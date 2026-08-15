<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * تصحيح فواتير وأوامر مندوب على عملاء محدّدين  ·  ١٥ أغسطس ٢٠٢٦
 * ═══════════════════════════════════════════════════════════════
 *
 * طلب المالك: «كل الفواتير بتاعت المندوب ده للعملاء دي كلها آجل،
 * وتتشال من الـPO».
 *
 * ═══ الحالة اللي بنصحّحها ═══
 *
 * نفس البضاعة اتسجّلت مرتين: فاتورة (بخصم العميل) **و** أمر توريد
 * (بسعر القايمة). الدليل الرقمي: PO-2025 = ١٠ × ١٣٥ = ١٣٥٠،
 * وINV-1002 = ١٠١٢٫٥٠ = ١٣٥٠ × ٠٫٧٥ — نفس الكمية بخصم ٢٥٪.
 *
 * والفواتير اتسجّلت **كاش** والبيع كان **آجل**، فقيد التحصيل كتب
 * فلوس ماتقبضتش.
 *
 * ═══ الوضع المستهدف ═══
 *
 *   · كل فاتورة → `credit`، وقيد التحصيل المربوط بيها يتشال.
 *   · كل أمر توريد → `cancelled`، و**أثره على الليدجر يبقى صفر**.
 *   · البضاعة اللي الأمر خصمها من العهدة ترجع (البضاعة خرجت مرة
 *     واحدة، والفاتورة هي اللي خصمتها صح).
 *   · `recalculate()` لكل عميل.
 *
 * ═══ ليه الأمر ده **قابل لإعادة التشغيل** ═══
 *
 * ⚠️ PO-2026 اتعكس قبل كده بأمر تاني، فقيوده صافيها صفر بالفعل.
 * لو الأمر ده عكسه تاني كان هيقلب الرصيد للناحية التانية. عشان
 * كده بيحسب **الصافي الحالي** لكل أمر من الليدجر، ومابيعملش قيد
 * إلا لو الصافي ≠ صفر. تشغيله مرتين = نفس النتيجة.
 *
 * ⚠️ **رجوع العهدة مربوط بحالة الأمر** مش بالليدجر: الأمر اللي
 * حالته `cancelled` بالفعل بضاعته رجعت قبل كده، فمابترجعش تاني.
 *
 * ⚠️ **بيطبع كل عميل بالـid والاسم** — لو طلع عميلين لنفس المحل
 * (Hammam Gym / همام جيم) ده تكرار عملاء لازم يتدمج، والأمر ده
 * مابيدمجش: بيوريك بس.
 *
 *   php artisan promax:rep-ledger --rep=17 --clients=686,473
 *   php artisan promax:rep-ledger --rep=17 --clients=686,473 --fix
 */
class RepClientLedgerFix extends Command
{
    protected $signature = 'promax:rep-ledger
        {--rep= : رقم المندوب}
        {--clients= : أرقام العملاء بالفاصلة}
        {--terms=both : شروط الدفع الجديدة للعملاء — both|credit|cash|skip}
        {--fix : نفّذ — من غيرها معاينة بس}';

    protected $description = 'فواتير المندوب على عملاء محدّدين تبقى آجل، وأوامر التوريد المكرّرة تتشال';

    public function handle(): int
    {
        $repId = (int) $this->option('rep');
        $ids = array_filter(array_map('intval', explode(',', (string) $this->option('clients'))));
        $terms = (string) $this->option('terms');
        $fix = (bool) $this->option('fix');

        if ($repId <= 0 || $ids === []) {
            $this->error('حدد --rep=17 --clients=686,473');

            return self::FAILURE;
        }

        $rep = User::find($repId);

        if ($rep === null) {
            $this->error("مفيش مستخدم رقم {$repId}");

            return self::FAILURE;
        }

        $clients = Client::whereIn('id', $ids)->get();

        if ($clients->count() !== count($ids)) {
            $this->warn('⚠ بعض أرقام العملاء مالهاش سجل: '
                .implode(',', array_diff($ids, $clients->pluck('id')->all())));
        }

        $this->line('');
        $this->line('  المندوب: '.$rep->displayName()." (#{$rep->id})");

        foreach ($clients as $c) {
            $this->line("  العميل #{$c->id} · {$c->code} · {$c->displayName()}"
                .' · شروط: '.$c->paymentTerms()
                .' · الرصيد: '.number_format((float) $c->balance, 2));
        }

        // ⚠️ تحذير تكرار العملاء — نفس المحل باسمين
        $names = $clients->map(fn ($c) => preg_replace('/\s+/', '',
            mb_strtolower($c->name_en ?: $c->name)))->all();

        if (count($names) !== count(array_unique($names))) {
            $this->warn('  ⚠ فيه عميلين بنفس الاسم تقريباً — راجع تكرار العملاء.');
        }

        $totalInv = 0;
        $totalPo = 0;

        foreach ($clients as $client) {
            $this->line('');
            $this->line('  ══════ '.$client->displayName()." (#{$client->id}) ══════");

            // ═══ الفواتير ═══
            $invoices = Invoice::where('client_id', $client->id)
                ->where('user_id', $rep->id)
                ->orderBy('id')->get();

            $this->line('  الفواتير ('.$invoices->count().'):');

            foreach ($invoices as $inv) {
                $coll = Transaction::where('source_type', Invoice::class)
                    ->where('source_id', $inv->id)
                    ->where('kind', 'collection')->get();

                $flag = $inv->payment === 'cash' || $coll->isNotEmpty() ? '→ آجل' : 'آجل بالفعل';

                $this->line(sprintf('    %-12s %s  %11s  %-7s قيود تحصيل: %d   %s',
                    $inv->number, $inv->created_at->format('m-d H:i'),
                    number_format((float) $inv->grand_total, 2),
                    $inv->payment, $coll->count(), $flag));

                if ($inv->payment === 'cash' || $coll->isNotEmpty()) {
                    $totalInv++;
                }
            }

            // ═══ أوامر التوريد ═══
            $pos = PurchaseOrder::with('items')
                ->where('client_id', $client->id)
                ->where('assigned_to', $rep->id)
                ->orderBy('id')->get();

            $this->line('  أوامر التوريد ('.$pos->count().'):');

            foreach ($pos as $po) {
                $net = $this->netOf($po);

                $this->line(sprintf('    %-12s %-10s %11s  صافي أثره على الليدجر: %s',
                    $po->number, $po->status,
                    number_format((float) $po->grand_total, 2),
                    number_format($net, 2)));

                if ($po->status !== 'cancelled' || abs($net) > 0.009) {
                    $totalPo++;
                }
            }
        }

        $this->line('');
        $this->line("  هيتغيّر: {$totalInv} فاتورة · {$totalPo} أمر توريد");

        if (! $fix) {
            $this->comment('  (معاينة — ضيف --fix للتنفيذ)');

            return self::SUCCESS;
        }

        foreach ($clients as $client) {
            DB::transaction(function () use ($client, $rep, $terms) {
                // ═══ ١. الفواتير تبقى آجل ═══
                $invoices = Invoice::where('client_id', $client->id)
                    ->where('user_id', $rep->id)->get();

                foreach ($invoices as $inv) {
                    Transaction::where('source_type', Invoice::class)
                        ->where('source_id', $inv->id)
                        ->where('kind', 'collection')
                        ->delete();

                    if ($inv->payment !== 'credit') {
                        $inv->update(['payment' => 'credit']);
                    }
                }

                // ═══ ٢. أوامر التوريد ═══
                $pos = PurchaseOrder::with('items')
                    ->where('client_id', $client->id)
                    ->where('assigned_to', $rep->id)->get();

                foreach ($pos as $po) {
                    $net = $this->netOf($po);

                    // ⚠️ قيد تسوية بالصافي بس — الأمر اللي اتعكس قبل
                    // كده صافيه صفر فمابياخدش قيد جديد. ده اللي بيخلّي
                    // الأمر قابل لإعادة التشغيل من غير ما يقلب الرصيد.
                    if (abs($net) > 0.009) {
                        Transaction::create([
                            'client_id' => $client->id,
                            'date' => today(),
                            'memo' => __('flash.memo_po_dupe_reversed', [
                                'number' => $po->number,
                                'kind' => 'net',
                            ]),
                            'debit' => $net < 0 ? abs($net) : 0,
                            'credit' => $net > 0 ? $net : 0,
                            'tax' => 0,
                            'kind' => 'settlement',
                            'source_type' => PurchaseOrder::class,
                            'source_id' => $po->id,
                        ]);
                    }

                    // ⚠️ البضاعة ترجع **مرة واحدة بس** — الأمر الملغي
                    // بضاعته رجعت في تشغيلة سابقة.
                    if ($po->status !== 'cancelled') {
                        $this->returnGoods($po);

                        $po->update([
                            'status' => 'cancelled',
                            'abort_reason' => 'مكرر مع فاتورة لنفس البضاعة — تصحيح ١٥ أغسطس ٢٠٢٦',
                        ]);
                    }

                    $po->replenishmentRequest?->update([
                        'purchase_order_id' => null,
                        'status' => 'delivered',
                    ]);
                }

                // ═══ ٣. شروط الدفع ═══
                if ($terms !== 'skip' && in_array($terms, Client::PAY_TERMS, true)) {
                    $client->update(['payment_terms' => $terms]);
                }

                // ═══ ٤. إعادة الحساب — أشهر باج في الرَنبوك ═══
                $client->recalculate();
            });

            $client->refresh();
            $this->info('  ✓ '.$client->displayName().' — الرصيد بقى '
                .number_format((float) $client->balance, 2));
        }

        $this->line('');
        $this->comment('  راجع كشف حساب كل عميل: الرصيد لازم = Σ مدين − Σ دائن.');

        return self::SUCCESS;
    }

    /** صافي أثر الأمر على الليدجر دلوقتي (مدين − دائن) */
    private function netOf(PurchaseOrder $po): float
    {
        $rows = Transaction::where('source_type', PurchaseOrder::class)
            ->where('source_id', $po->id)->get();

        return round($rows->sum(fn ($t) => (float) $t->debit - (float) $t->credit), 2);
    }

    /** رجوع بضاعة الأمر للعهدة — `sold` بيقلّ من غير ما يبقى سالب */
    private function returnGoods(PurchaseOrder $po): void
    {
        $custody = $po->courier?->currentCustody();

        if ($custody === null) {
            $this->warn('    ⚠ '.$po->number.': المندوب مالوش عهدة مفتوحة — البضاعة مارجعتش.');

            return;
        }

        foreach ($po->items as $it) {
            $back = (int) $it->delivered_qty;

            if ($back <= 0) {
                continue;
            }

            foreach ($custody->items()->where('product_id', $it->product_id)
                ->orderByDesc('sold')->get() as $ci) {
                if ($back <= 0) {
                    break;
                }

                $take = min($back, (int) $ci->sold);

                if ($take > 0) {
                    $ci->decrement('sold', $take);
                    $back -= $take;
                }
            }

            if ($back > 0) {
                $this->warn('    ⚠ '.$po->number.': فاضل '.$back.' من '
                    .($it->product?->displayName() ?? '#'.$it->product_id).' ماقدرناش نرجّعه.');
            }
        }
    }
}
