<?php

namespace App\Services;

use App\Exceptions\Rejected;
use App\Models\Client;
use App\Models\ClientReturn;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * سداد المستندات — مصدر واحد (١٣/١١/٢٠٢٦ — «عاوزين حل للتكرار مرة واحدة»)
 * ═══════════════════════════════════════════════════════════════
 *
 * الدفتر «رصيد متراكم»: التحصيل الميداني/المكتبي على العميل كله مش على
 * فاتورة، و«الكاش مع الفاتورة» قيد تحصيل مربوط بالمستند نفسه. باج INV-1017
 * (ورقي 560) كان إن فاتورة اتدفعت بتحصيل ميداني اتعملها قيد كاش مربوط تاني.
 *
 * القواعد (كل المسارات بتعدّي من هنا):
 *   ١. **حالة السداد محسوبة من الدفتر نفسه** (`coverage`) — مش عمود ممكن
 *      يتلخبط: الكاش المربوط بيروح لمستنده الأول، وبعدين باقي الدائن (تحصيل،
 *      مرتجع، خصم ضريبة…) بيتوزّع على المديونية الأقدم فالأحدث (FIFO).
 *   ٢. **قيد كاش مربوط واحد بالكتير لكل مستند** — قفل على صف المستند هنا،
 *      وقيد فريد في الداتابيز (`transactions.doc_link`) تحت منه.
 *   ٣. **المربوط = إجمالي المستند بالظبط** — مايعدّيهوش أبداً.
 *   ٤. **الكاش بأثر رجعي** (تحويل آجل ← كاش بعد البيع) بيترفض لو المستند
 *      اتسدّد (كله أو جزء) بفلوس تانية — دي كانت الدوبلة.
 *   ٥. المرتجع: قيد دائن واحد ورد كاش واحد بالكتير (نفس القيد الفريد).
 */
class Settlement
{
    /** الأنواع المربوطة بالمستند — صف واحد بس لكل (نوع، مستند) — `doc_link` */
    public const LINKED_KINDS = ['sale', 'consignment', 'collection', 'return', 'refund'];

    public const LINKED_SOURCES = [Invoice::class, PurchaseOrder::class, ClientReturn::class];

    private const EPS = 1.0;

    /**
     * قيد «تحصيل نقدي مع المستند» — المسار الوحيد اللي بيكتبه.
     *
     * @param  bool  $retro  بعد البيع (تحويل لكاش): لازم المستند يكون لسه مفتوح بالكامل
     */
    public static function linkCash(Invoice|PurchaseOrder $doc, ?Carbon $date = null, ?string $memo = null, bool $retro = false, ?float $amount = null, ?float $tax = null): ?Transaction
    {
        return DB::transaction(function () use ($doc, $date, $memo, $retro, $amount, $tax) {
            // قفل صف المستند: نداءين في نفس اللحظة مايعدّوش الاتنين
            $doc->newQuery()->whereKey($doc->getKey())->lockForUpdate()->first();

            $existing = self::linked($doc, 'collection');
            if ($existing !== null) {
                return $existing;   // موجود — مفيش تاني (الدوسة المكررة بترجع نفس القيد)
            }
            if (self::linked($doc, 'consignment') !== null) {
                throw new Rejected(__('ops.pay_consignment_blocked'));
            }

            $grand = round((float) $doc->grand_total, 2);
            $amount = round($amount ?? $grand, 2);
            if ($amount <= 0) {
                return null;
            }
            if ($amount - $grand > 0.005) {
                throw new Rejected(__('ops.settle_over_total', ['number' => $doc->number, 'amount' => number_format($amount, 2), 'grand' => number_format($grand, 2)]));
            }
            if ($retro && ($why = self::retroBlocker($doc)) !== null) {
                throw new Rejected($why);
            }

            return Transaction::create([
                'client_id' => $doc->client_id,
                'date' => ($date ?? ($doc instanceof Invoice ? $doc->created_at : now()))->toDateString(),
                'memo' => $memo ?? __('flash.memo_cash_with_invoice', ['number' => $doc->number]),
                'debit' => 0,
                'credit' => $amount,
                'tax' => $tax ?? (float) $doc->tax_total,
                'kind' => 'collection',
                'method' => Transaction::METHOD_CASH,
                'source_type' => $doc::class,
                'source_id' => $doc->getKey(),
            ]);
        });
    }

    /** شيل الكاش المربوط (آجل) — صف صف عشان أوبزرفر الدفتر */
    public static function unlinkCash(Invoice|PurchaseOrder $doc): int
    {
        $rows = Transaction::where('source_type', $doc::class)->where('source_id', $doc->getKey())->where('kind', 'collection')->get();
        $rows->each->delete();

        return $rows->count();
    }

    /**
     * ليه الكاش بأثر رجعي ممنوع (null = مسموح): المستند اتسدّد (كله أو جزء) بفلوس
     * تانية حسب `coverage` — قيد كاش جديد = نفس الفلوس متحصّلة مرتين.
     */
    public static function retroBlocker(Invoice|PurchaseOrder $doc): ?string
    {
        $c = self::coverage((int) $doc->client_id)[$doc::class.'#'.$doc->getKey()] ?? null;
        if ($c === null) {
            return null;   // مالوش قيد بيع (أمر لسه ماتسلّمش) — مفيش مديونية تتحصّل
        }
        if ($c['open'] < (float) $doc->grand_total - self::EPS) {
            return __('ops.pay_already_collected', [
                'number' => $doc->number,
                'balance' => number_format($c['paid'], 2),
                'after' => number_format($c['open'], 2),
            ]);
        }

        return null;
    }

    /**
     * حالة سداد كل مستند للعميل — من الدفتر بس. المفتاح «Class#id».
     *
     * @return array<string, array{debit: float, paid: float, open: float, state: string}>
     */
    public static function coverage(int $clientId): array
    {
        $rows = Transaction::where('client_id', $clientId)->orderBy('date')->orderBy('id')
            ->get(['id', 'date', 'debit', 'credit', 'kind', 'source_type', 'source_id']);

        // المديونية: كل صف مدين (بيع/رد كاش/افتتاحي…) — المربوط بمستند بمفتاحه
        $debts = [];
        foreach ($rows as $r) {
            if ((float) $r->debit > 0) {
                $k = in_array($r->source_type, self::LINKED_SOURCES, true) ? $r->source_type.'#'.$r->source_id : 'tx#'.$r->id;
                $debts[$k] ??= ['debit' => 0.0, 'paid' => 0.0];
                $debts[$k]['debit'] += (float) $r->debit;
            }
        }

        // ١. الكاش المربوط لمستنده الأول
        $pool = [];
        foreach ($rows as $r) {
            if ((float) $r->credit <= 0) {
                continue;
            }
            $k = $r->source_type.'#'.$r->source_id;
            $credit = (float) $r->credit;
            if ($r->kind === 'collection' && isset($debts[$k])) {
                $take = min($credit, $debts[$k]['debit'] - $debts[$k]['paid']);
                $debts[$k]['paid'] += $take;
                $credit -= $take;
            }
            if ($credit > 0.004) {
                $pool[] = $credit;
            }
        }

        // ٢. باقي الدائن على الأقدم فالأحدث
        $keys = array_keys($debts);
        $i = 0;
        foreach ($pool as $credit) {
            while ($credit > 0.004 && $i < count($keys)) {
                $d = &$debts[$keys[$i]];
                $take = min($credit, $d['debit'] - $d['paid']);
                $d['paid'] += $take;
                $credit -= $take;
                if ($d['debit'] - $d['paid'] <= 0.004) {
                    $i++;
                }
                unset($d);
            }
        }

        $out = [];
        foreach ($debts as $k => $d) {
            $open = round(max(0, $d['debit'] - $d['paid']), 2);
            $out[$k] = ['debit' => round($d['debit'], 2), 'paid' => round($d['paid'], 2), 'open' => $open,
                'state' => $open <= 0.005 ? 'paid' : ($d['paid'] > 0.005 ? 'partial' : 'open')];
        }

        return $out;
    }

    /** حالة سداد مستند واحد: paid / partial / open (null = مالوش مديونية) */
    public static function state(Invoice|PurchaseOrder $doc): ?array
    {
        return self::coverage((int) $doc->client_id)[$doc::class.'#'.$doc->getKey()] ?? null;
    }

    /**
     * حارس التحصيل المكتبي (ERP): نفس التحصيل اتسجّل قبل كده؟ أو أكبر من المفتوح؟
     * الاتنين بيترفضوا إلا لو المحاسب أكّد صراحةً (تحصيل تاني فعلاً / دفعة مقدمة).
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public static function assertClientCollection(Client $client, float $amount, Carbon $date, bool $allowDuplicate = false, bool $allowAdvance = false): void
    {
        $amount = round($amount, 2);
        if (! $allowDuplicate) {
            $dup = Transaction::where('client_id', $client->id)->where('kind', 'collection')
                ->whereDate('date', $date->toDateString())->whereBetween('credit', [$amount - 0.005, $amount + 0.005])
                ->orderByDesc('id')->first();
            if ($dup !== null) {
                throw \Illuminate\Validation\ValidationException::withMessages(['amount' => __('ops.collect_dup', [
                    'amount' => number_format($amount, 2), 'date' => $date->toDateString(), 'memo' => (string) $dup->memo,
                ])]);
            }
        }
        if (! $allowAdvance) {
            $open = (float) Transaction::where('client_id', $client->id)->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS b')->value('b');
            if ($amount - $open > self::EPS) {
                throw \Illuminate\Validation\ValidationException::withMessages(['amount' => __('ops.collect_over', [
                    'amount' => number_format($amount, 2), 'open' => number_format(max(0, $open), 2),
                ])]);
            }
        }
    }

    private static function linked(Invoice|PurchaseOrder $doc, string $kind): ?Transaction
    {
        return Transaction::where('source_type', $doc::class)->where('source_id', $doc->getKey())->where('kind', $kind)->first();
    }
}
