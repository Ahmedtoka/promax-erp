<?php

namespace App\Console\Commands;

use App\Models\PurchaseOrder;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * إرجاع أمر توريد اتعكس بالغلط + تصحيح طريقة الدفع
 * ١٥ أغسطس ٢٠٢٦
 * ═══════════════════════════════════════════════════════════════
 *
 * ═══ ليه الأمر ده موجود ═══
 *
 * `promax:refill-dupes --fix` عكست PO-2026 بالكامل على أساس تطابق
 * ضعيف: نفس العميل ونفس الصنف في نافذة ٧ أيام. لكن الأرقام كانت
 * بتقول العكس بوضوح — الأمر ١٤٤ قطعة بسعر ٦٠، والفاتورة ١٢ قطعة
 * بسعر ٤٥. كمية مختلفة **وسعر مختلف** = بيعة منفصلة مش نسخة.
 *
 * النتيجة: بضاعة راحت للعميل فعلاً واتشال تسجيلها، والكراتين رجعت
 * لعهدة مندوب هي مش موجودة عنده.
 *
 * ═══ الأمر ده بيرجّع الوضع ═══
 *
 *   ١. **بيمسح قيود التسوية اللي العكس عملها** — مش بيعكسها.
 *      القيود دي اتكتبت من دقايق بسبب باج، ماشافهاش محاسب ولا
 *      دخلت كشف حساب مطبوع. مسحها بيرجّع الحقيقة؛ عكسها كان
 *      هيسيب تلات طبقات قيود لحدث واحد مالوش أصل.
 *      ⚠️ الحارس: `kind='settlement'` + مربوط بالأمر ده + اتعمل
 *      النهارده. أي قيد تسوية حقيقي قديم مابيتلمسش.
 *
 *   ٢. **البضاعة تخرج من العهدة تاني** — بـ`deduct()` نفسها، مش
 *      بزيادة `sold` مباشرة. الرَنبوك واضح: أي كود بيزوّد `sold`
 *      من غير ما يعدّي على `deduct()` ده باج، لأنها هي اللي
 *      بتتحقق من الكفاية وبتوزّع FEFO.
 *
 *   ٣. **الأمر يرجع `delivered`**.
 *
 *   ٤. **`--as=credit`**: بيشيل قيد التحصيل عشان البيعة تبقى آجل.
 *      الفرع مسجّل `cash` في بياناته فالتسليم كتب `collection`
 *      أوتوماتيك ورصيد العميل رجع صفر — والفلوس ماتقبضتش فعلاً.
 *      شيل القيد ده = المديونية تبان صح.
 *      `--as=cash` بيسيب القيدين زي ما هما.
 *
 *   ٥. `recalculate()`.
 *
 *   php artisan promax:po-restore --po=PO-2026 --as=credit
 */
class RestorePurchaseOrder extends Command
{
    protected $signature = 'promax:po-restore
        {--po= : رقم أمر التوريد}
        {--as=keep : credit = شيل قيد التحصيل · cash = سيبه · keep = ماتلمسش}
        {--fix : نفّذ — من غيرها معاينة بس}';

    protected $description = 'إرجاع أمر توريد اتعكس بالغلط، مع تصحيح كاش/آجل';

    public function handle(): int
    {
        $number = trim((string) $this->option('po'));
        $as = (string) $this->option('as');
        $fix = (bool) $this->option('fix');

        if ($number === '') {
            $this->error('حدد --po=PO-XXXX');

            return self::FAILURE;
        }

        if (! in_array($as, ['credit', 'cash', 'keep'], true)) {
            $this->error('--as لازم تكون credit أو cash أو keep');

            return self::FAILURE;
        }

        /** @var PurchaseOrder|null $po */
        $po = PurchaseOrder::with(['client', 'items.product', 'courier'])
            ->where('number', $number)->first();

        if ($po === null) {
            $this->error("مفيش أمر بالرقم {$number}");

            return self::FAILURE;
        }

        $client = $po->client;

        $this->line('');
        $this->line("  {$po->number} · ".($client?->displayName() ?? '—')
            ." · الحالة: {$po->status} · ".number_format((float) $po->grand_total, 2));

        // ═══ قيود التسوية اللي العكس عملها ═══
        $bogus = Transaction::where('source_type', PurchaseOrder::class)
            ->where('source_id', $po->id)
            ->where('kind', 'settlement')
            ->whereDate('created_at', today())
            ->get();

        $this->line('  قيود التسوية اللي هتتشال: '.$bogus->count());

        foreach ($bogus as $t) {
            $this->line("    #{$t->id} مدين ".number_format((float) $t->debit, 2)
                .' / دائن '.number_format((float) $t->credit, 2));
        }

        // ═══ قيد التحصيل (كاش) ═══
        $collection = Transaction::where('source_type', PurchaseOrder::class)
            ->where('source_id', $po->id)
            ->where('kind', 'collection')
            ->first();

        if ($as === 'credit' && $collection !== null) {
            $this->line('  قيد التحصيل هيتشال (البيعة آجل): #'.$collection->id
                .' دائن '.number_format((float) $collection->credit, 2));
        }

        $qty = $po->items->pluck('delivered_qty', 'product_id')
            ->map(fn ($q) => (int) $q)->filter(fn ($q) => $q > 0)->all();

        $this->line('  البضاعة اللي هتخرج من العهدة تاني: '.array_sum($qty).' قطعة');

        if (! $fix) {
            $this->comment('  (معاينة — ضيف --fix للتنفيذ)');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($po, $client, $bogus, $collection, $as, $qty) {
            // ١. مسح قيود التسوية الغلط
            foreach ($bogus as $t) {
                $t->delete();
            }

            // ٢. البضاعة تخرج من العهدة تاني — بـ`deduct` مش بزيادة يدوية
            $custody = $po->courier?->currentCustody();

            if ($custody !== null && $qty !== []) {
                if ($err = $custody->deduct($qty)) {
                    // ⚠️ الترانزاكشن بترجع كلها — أحسن من أمر مرجّع
                    // وعهدة مش مخصومة (نفس عقيدة `storeInvoice`).
                    throw new \RuntimeException('العهدة مش كفاية للخصم تاني: '.$err);
                }

                $this->line('    ✓ البضاعة خرجت من عهدة '.($po->courier?->displayName() ?? '—'));
            } elseif ($qty !== []) {
                $this->warn('    ⚠ المندوب مالوش عهدة مفتوحة — الخصم مااتعملش. صحّحه من «تعديل العهدة».');
            }

            // ٣. الأمر يرجع متسلّم
            $po->update(['status' => 'delivered', 'abort_reason' => null]);

            // ٤. كاش ولا آجل
            if ($as === 'credit' && $collection !== null) {
                $collection->delete();
                $this->line('    ✓ قيد التحصيل اتشال — البيعة بقت آجل');
            }

            // ٥. إعادة الحساب
            $client?->recalculate();
        });

        $this->info('  ✓ '.$po->number.' رجع. رصيد '.($client?->displayName() ?? '—')
            .' بقى '.number_format((float) $client?->fresh()->balance, 2));

        return self::SUCCESS;
    }
}
