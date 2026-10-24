<?php

namespace App\Services\Reprice;

use App\Models\Invoice;
use App\Models\Transaction;
use App\Services\Pricing;
use App\Services\Tax;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * ═══════════════════════════════════════════════════════════════
 * إعادة تسعير فاتورة واحدة بتسعيرة العميل الحالية — الجوهر
 * ═══════════════════════════════════════════════════════════════
 *
 * منقول من `OpsController::repriceInvoice` (٢٤/١٠) **من غير أي
 * تغيير في السلوك** — نفس الحسبة ونفس الكتابات ونفس الحراس. اتقسم
 * لتلات خطوات عشان التعديل الجماعي (`PriceChange`) يستخدم نفس الطريق:
 *
 *   • `blocker()` — الحراس التلاتة (ضرايب / أمانة / مرتجع) بنفس الترتيب.
 *     ⚠️ حارس الشهر المقفول **مش هنا** — الزرار الفردي بيناديه بين
 *     حارس الضرايب والباقي (نفس الترتيب القديم)، والجماعي بيفحصه بنفسه.
 *   • `plan()` — قراءة بس: الأسعار الجديدة من `Pricing::quote` والضريبة
 *     من `Tax`، من غير ولا كتابة ولا save على أي موديل.
 *   • `apply()` — الكتابة: البنود + الإجماليات التلاتة + قيد البيع
 *     (+ التحصيل لو اتطلب) + `recalculate()` + إعادة ترحيل الشجرة،
 *     كلها جوه `DB::transaction` واحدة.
 *
 * ⚠️ **التكلفة التاريخية ماتتلمسش** — `unit_cost` و`cost_total`
 * لقطة يوم البيع؛ إعادة التسعير بتغيّر البيع مش التكلفة.
 */
class InvoiceRepricer
{
    /** مفتاح الحارس ← رسالته (نفس رسايل الزرار الفردي) */
    public const BLOCKER_MESSAGES = [
        'eta_locked' => 'ops.reprice_eta_locked',
        'consignment' => 'ops.reprice_consignment_blocked',
        'has_return' => 'ops.reprice_has_return',
    ];

    /** فرق أقل من كده = نفس الرقم (قرشين مقرّبين) */
    private const EPS = 0.005;

    /**
     * أول حارس بيمنع إعادة التسعير — أو null.
     *
     * @return array{key: string, detail: null}|null
     */
    public static function blocker(Invoice $inv): ?array
    {
        // متصدّرة للضرائب → ممنوع — المستند الرسمي طلع بأرقامه
        if (in_array((string) $inv->eta_status, ['exported', 'submitted'], true)) {
            return ['key' => 'eta_locked', 'detail' => null];
        }

        // فاتورة أمانة → مالهاش مديونية ولا معنى لإعادة تسعيرها
        if (Transaction::where('source_type', Invoice::class)
            ->where('source_id', $inv->id)
            ->where('kind', 'consignment')->exists()) {
            return ['key' => 'consignment', 'detail' => null];
        }

        // عليها مرتجع مربوط → المرتجع اتحسب بالأسعار القديمة
        if (Schema::hasColumn('return_items', 'invoice_id')
            && DB::table('return_items')->where('invoice_id', $inv->id)->exists()) {
            return ['key' => 'has_return', 'detail' => null];
        }

        return null;
    }

    /**
     * الأرقام الجديدة لو الفاتورة اتسعّرت دلوقتي — **قراءة بس**.
     *
     * بيحمّل `items.product` و`items.batch` و`client` على الموديل
     * (تحميل علاقات في الذاكرة مش كتابة). لو صنف مش متسعّر في
     * القايمة الحالية: `not_priced` = اسمه، و`after`/`lines` فاضيين،
     * و`changed` = false — والمنادي هو اللي يرفض.
     *
     * @return array{
     *   not_priced: ?string, changed: bool,
     *   before: array{net: float, tax: float, grand: float},
     *   after: array<string, mixed>,
     *   lines: list<array<string, mixed>>
     * }
     */
    public static function plan(Invoice $inv): array
    {
        $inv->load(['items.product', 'items.batch', 'client']);
        $client = $inv->client;

        $before = [
            'net' => (float) $inv->total,
            'tax' => (float) $inv->tax_total,
            'grand' => (float) $inv->grand_total,
        ];

        $subtotal = 0;
        $rows = [];
        $lines = [];

        foreach ($inv->items as $item) {
            $quote = Pricing::quote($client, $item->product, $item->batch, (int) $item->qty);

            // صنف سعره صفر في القايمة الحالية → الفاتورة كلها مرفوضة
            if ($quote['list_price'] <= 0) {
                return [
                    'not_priced' => $item->product->displayName(),
                    'changed' => false,
                    'before' => $before,
                    'after' => [],
                    'lines' => [],
                ];
            }

            $taxRate = Tax::rate($client, $item->product);
            $lineTax = Tax::on($quote['line_total'], $client, $item->product);

            $lines[] = [
                'item_id' => $item->id,
                'product_id' => $item->product_id,
                'name' => $item->product->displayName(),
                'qty' => (int) $item->qty,
                'old_list' => (float) $item->list_price,
                'old_price' => (float) $item->price,
                'old_total' => (float) $item->total,
                'old_tax' => (float) $item->tax,
                'list_price' => $quote['list_price'],
                'price' => $quote['unit_price'],
                'total' => $quote['line_total'],
                'tax_rate' => $taxRate,
                'tax' => $lineTax,
            ];

            $rows[] = ['total' => $quote['line_total'], 'tax' => $lineTax];
            $subtotal += round($quote['list_price'] * $item->qty, 2);
        }

        // الإجماليات التلاتة من نفس مصدر الفاتورة الجديدة
        $sums = Tax::totals($rows);

        $after = [
            'subtotal' => $subtotal,
            'discount' => round($subtotal - $sums['net'], 2),
            'net' => $sums['net'],
            'tax' => $sums['tax'],
            'grand' => $sums['grand'],
            'discount_pct' => $client->effectiveDiscount(),
            'discount_source' => $client->discountSourceKey(),
            'price_list' => $client->priceList(),
        ];

        return [
            'not_priced' => null,
            'changed' => self::differs($inv, $before, $after, $lines),
            'before' => $before,
            'after' => $after,
            'lines' => $lines,
        ];
    }

    /**
     * كتابة الخطة — نفس كتابات الزرار الفردي بالحرف.
     *
     * `$adjustCollection`: قيد التحصيل المربوط (فاتورة كاش) يتحرك مع
     * البيع — الزرار الفردي = true، الجماعي = false (الفرق يفضل على
     * حساب العميل). `$recalc`: `recalculate()` للعميل هنا ولا المنادي
     * هيعملها مرة واحدة في الآخر — في الحالتين جوه نفس الترانزاكشن.
     *
     * ⚠️ بتفتح `DB::transaction` بتاعتها (التداخل سليم) — والمنادي
     * الجماعي لازم يناديها جوه ترانزاكشنه هو.
     */
    public static function apply(Invoice $inv, array $plan, bool $adjustCollection, bool $recalc): void
    {
        // شبكة أمان — المنادي مفروض رفض قبل كده
        if (($plan['not_priced'] ?? null) !== null) {
            throw ValidationException::withMessages([
                'reprice' => __('ops.reprice_not_priced', ['product' => $plan['not_priced']]),
            ]);
        }

        $inv->loadMissing(['items', 'client']);
        $client = $inv->client;
        $after = $plan['after'];

        DB::transaction(function () use ($inv, $client, $plan, $after, $adjustCollection, $recalc) {
            $items = $inv->items->keyBy('id');

            foreach ($plan['lines'] as $line) {
                // ⚠️ التكلفة زي ما هي — لقطة يوم البيع
                $items[$line['item_id']]->update([
                    'list_price' => $line['list_price'],
                    'price' => $line['price'],
                    'total' => $line['total'],
                    'tax_rate' => $line['tax_rate'],
                    'tax' => $line['tax'],
                ]);
            }

            $inv->update([
                'price_list' => $after['price_list'],
                'subtotal' => $after['subtotal'],
                'discount_pct' => $after['discount_pct'],
                'discount_source' => $after['discount_source'],
                'discount' => $after['discount'],
                'total' => $after['net'],
                'tax_total' => $after['tax'],
                'grand_total' => $after['grand'],
                // الحارس ضمن إنها مش متصدّرة — بنظبط الجاهزية بس
                'eta_status' => $after['tax'] > 0 ? 'ready' : 'none',
            ]);

            // قيد البيع (مدين بالشامل)
            Transaction::where('source_type', Invoice::class)
                ->where('source_id', $inv->id)
                ->where('kind', 'sale')
                ->update(['debit' => $after['grand'], 'tax' => $after['tax']]);

            // وقيد التحصيل المربوط لو كاش — بس لما المنادي يطلبه
            if ($adjustCollection) {
                Transaction::where('source_type', Invoice::class)
                    ->where('source_id', $inv->id)
                    ->where('kind', 'collection')
                    ->update(['credit' => $after['grand'], 'tax' => $after['tax']]);
            }

            if ($recalc) {
                $client->recalculate();
            }

            InvoiceGl::repost($inv, 'repriceInvoice');
        });
    }

    /**
     * فيه فرق فلوس؟ — كل سطر (سعر القايمة / السعر / الإجمالي / الضريبة)
     * ونسبة الخصم، وكمان الإجماليات المخزّنة على الفاتورة نفسها.
     */
    private static function differs(Invoice $inv, array $before, array $after, array $lines): bool
    {
        foreach ($lines as $l) {
            if (abs($l['old_list'] - $l['list_price']) >= self::EPS
                || abs($l['old_price'] - $l['price']) >= self::EPS
                || abs($l['old_total'] - $l['total']) >= self::EPS
                || abs($l['old_tax'] - $l['tax']) >= self::EPS) {
                return true;
            }
        }

        // العمود decimal:4 — المقارنة على نفس الدقة
        if (abs(round((float) $inv->discount_pct, 4) - round((float) $after['discount_pct'], 4)) >= 0.00005) {
            return true;
        }

        return abs($before['net'] - $after['net']) >= self::EPS
            || abs($before['tax'] - $after['tax']) >= self::EPS
            || abs($before['grand'] - $after['grand']) >= self::EPS;
    }
}
