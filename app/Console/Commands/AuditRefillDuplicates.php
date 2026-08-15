<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * ازدواج الريفيل: نفس البضاعة اتسجّلت فاتورة **و** أمر توريد
 * ١٥ أغسطس ٢٠٢٦
 * ═══════════════════════════════════════════════════════════════
 *
 * بلاغ المالك: «اكتشفت إن المندوب باعهم للعميل آجل، وهما نازلين
 * عندي مرة بفاتورة ومرة بـPO».
 *
 * ═══ إيه اللي حصل بالظبط ═══
 *
 * البضاعة خرجت من العربية **مرة واحدة** فيزيكال، لكن اتسجّلت مرتين:
 *
 *   ١. المندوب عمل فاتورة → `storeInvoice` كتب قيد `sale`
 *      وخصم من العهدة.
 *   ٢. وأمر التوريد المتولّد من طلب الريفيل اتسلّم كمان →
 *      `deliver` كتب قيد `sale` تاني وخصم من العهدة تاني.
 *
 * فالنتيجة **تلات أضرار مع بعض**:
 *   · حساب العميل عليه مديونية مضاعفة (قيدين لنفس البضاعة)
 *   · عهدة المندوب اتخصمت مرتين ← **ده سبب «الرصيد بايظ»**
 *   · تصفية المندوب بتعدّ المبلغ مرتين (فاتورة + أمر)
 *
 * ═══ ليه أمر (`--fix`) مش مايجريشن ═══
 *
 * ⚠️ **ماينفعش أخمّن أنهي فاتورة تقابل أنهي أمر.** المبالغ مش
 * متطابقة بالضرورة (كميات جزئية، أسعار مختلفة، خصم)، والقرار ده
 * بيحرّك أرصدة عملاء حقيقية. فالأمر ده بيشتغل **تقرير بس** ما لم
 * تكتب `--fix` مع أرقام الأوامر بالاسم. مافيش أوتوماتيك.
 *
 * ═══ العكس بيعمل إيه (عقيدة `promax-numbers`) ═══
 *
 *   ١. **قيود عكسية** مؤرخة النهارده بنوع `settlement` — مش مسح.
 *      «مصدر الحقيقة هو `transactions`»، وسجل التدقيق لازم يفضل
 *      يقول إيه اللي حصل وليه.
 *   ٢. **البضاعة ترجع للعهدة** — `sold` بيقلّ بالكمية المسلَّمة،
 *      لأن البضاعة خرجت مرة واحدة بس فعلاً.
 *   ٣. أمر التوريد → `cancelled` بسبب مكتوب.
 *   ٤. طلب الريفيل يتفك من الأمر ويترسي على الفلو الجديد.
 *   ٥. `$client->recalculate()` — أشهر باج في الرَنبوك هو نسيانها.
 *
 * الفاتورة **مابتتلمسش**: هي التسجيل الصح للبيعة.
 *
 *   php artisan promax:refill-dupes
 *   php artisan promax:refill-dupes --fix --po=PO-2026,PO-2025
 */
class AuditRefillDuplicates extends Command
{
    protected $signature = 'promax:refill-dupes
        {--po= : أرقام أوامر التوريد بالفاصلة — إجباري مع --fix}
        {--days=7 : نافذة البحث عن فواتير مقابلة (يوم قبل/بعد التسليم)}
        {--fix : نفّذ العكس فعلاً — من غيرها تقرير بس}';

    protected $description = 'أوامر توريد من الريفيل اتسجّلت كمان بفاتورة — تقرير وعكس';

    public function handle(): int
    {
        $fix = (bool) $this->option('fix');
        $days = max((int) $this->option('days'), 0);
        $only = array_filter(array_map('trim', explode(',', (string) $this->option('po'))));

        if ($fix && $only === []) {
            $this->error('لازم تحدد --po=PO-2026,PO-2025 مع --fix. مفيش عكس أوتوماتيك.');

            return self::FAILURE;
        }

        $q = PurchaseOrder::with(['client', 'items.product', 'courier'])
            ->where('source', PurchaseOrder::SOURCE_REPLENISHMENT)
            ->where('status', 'delivered');

        if ($only !== []) {
            $q->whereIn('number', $only);
        }

        $orders = $q->orderBy('id')->get();

        if ($orders->isEmpty()) {
            $this->info('مفيش أوامر توريد متسلّمة مصدرها ريفيل.');

            return self::SUCCESS;
        }

        foreach ($orders as $po) {
            $this->line('');
            $this->line('════════════════════════════════════════════');
            $this->line("  {$po->number}  ·  ".($po->client?->displayName() ?? '—')
                ." ·  {$po->grand_total}  ·  ".($po->delivered_at?->format('Y-m-d H:i') ?? '—'));
            $this->line('════════════════════════════════════════════');

            $pids = $po->items->pluck('product_id')->all();

            // ⚠️ الفواتير المرشّحة: نفس العميل، نفس الأصناف، في نافذة
            // حوالين التسليم. **مرشّحة مش مؤكّدة** — العين البشرية هي
            // اللي تقرر، عشان كده الأمر بيطبعها بدل ما يتصرف لوحده.
            $from = ($po->delivered_at ?? $po->created_at)->copy()->subDays($days);
            $to = ($po->delivered_at ?? $po->created_at)->copy()->addDays($days);

            $invoices = Invoice::with('items.product')
                ->where('client_id', $po->client_id)
                ->whereBetween('created_at', [$from, $to])
                ->whereHas('items', fn ($i) => $i->whereIn('product_id', $pids))
                ->get();

            $this->line('  بنود الأمر:');
            foreach ($po->items as $it) {
                $this->line(sprintf('    %-42s %6d  ×%9s',
                    mb_substr($it->product?->displayName() ?? '#'.$it->product_id, 0, 42),
                    (int) $it->delivered_qty, number_format((float) $it->price, 2)));
            }

            if ($invoices->isEmpty()) {
                $this->warn('  ⚠ مفيش فاتورة مقابلة في النافذة — الأمر ده غالباً مش مكرر. سيبه.');

                continue;
            }

            $this->line('  فواتير مرشّحة لنفس العميل والأصناف:');
            foreach ($invoices as $inv) {
                $shared = $inv->items->whereIn('product_id', $pids);
                $this->line("    {$inv->number} · {$inv->created_at->format('Y-m-d H:i')}"
                    ." · {$inv->payment} · ".number_format((float) $inv->grand_total, 2));
                foreach ($shared as $li) {
                    $this->line(sprintf('        %-38s %6d',
                        mb_substr($li->product?->displayName() ?? '#'.$li->product_id, 0, 38),
                        (int) $li->qty));
                }
            }

            // ═══════════════════════════════════════════════════
            // ⚠️ **حارس التطابق الكامل** — أُضيف بعد غلطة حقيقية
            // ═══════════════════════════════════════════════════
            //
            // النسخة الأولى كانت بتعكس الأمر **كله** لمجرد إن فيه
            // فاتورة فيها نفس الصنف. ده عكس PO-2026 (١٤٤ قطعة ×٦٠)
            // على أساس INV-1003 (١٢ قطعة ×٤٥) — كمية مختلفة وسعر
            // مختلف، يعني بيعة منفصلة مش نسخة. النتيجة: بضاعة
            // راحت للعميل واتشال تسجيلها.
            //
            // القاعدة دلوقتي: الازدواج الحقيقي معناه الفاتورة
            // بتغطي **كل** كمية الأمر لكل صنف. أي حاجة أقل من كده
            // بتتعرض كتحذير والأمر **مابيتلمسش**.
            $need = [];

            foreach ($po->items as $it) {
                $need[(int) $it->product_id] = (int) $it->delivered_qty;
            }

            $covered = [];

            foreach ($invoices as $inv) {
                foreach ($inv->items as $li) {
                    $pid = (int) $li->product_id;

                    if (isset($need[$pid])) {
                        $covered[$pid] = ($covered[$pid] ?? 0) + (int) $li->qty;
                    }
                }
            }

            $partial = [];

            foreach ($need as $pid => $n) {
                $got = $covered[$pid] ?? 0;

                if ($got < $n) {
                    $partial[] = ['pid' => $pid, 'need' => $n, 'got' => $got];
                }
            }

            if ($partial !== []) {
                $this->warn('  ⚠ تطابق **جزئي** — الفاتورة ماتغطّيش كل كمية الأمر:');

                foreach ($partial as $p) {
                    $name = $po->items->firstWhere('product_id', $p['pid'])?->product?->displayName()
                        ?? '#'.$p['pid'];
                    $this->warn(sprintf('      %-38s الأمر %d · الفاتورة %d',
                        mb_substr($name, 0, 38), $p['need'], $p['got']));
                }

                $this->warn('  ⚠ الأمر ده **مش هيتعكس**. الازدواج بيتأكد لما الفاتورة');
                $this->warn('    تغطّي كل كمية الأمر بنفس الأصناف. راجعه يدوي.');

                continue;
            }

            if (! $fix) {
                $this->comment('  ✓ تطابق كامل — للعكس: --fix --po='.$po->number);

                continue;
            }

            $this->reverse($po);
        }

        if (! $fix) {
            $this->line('');
            $this->comment('راجع المطابقة فوق بعينك. العكس بيتنفّذ بأرقام محددة بس:');
            $this->comment('  php artisan promax:refill-dupes --fix --po=PO-XXXX');
        }

        return self::SUCCESS;
    }

    /** عكس أمر توريد مكرر — قيود عكسية + رجوع العهدة + إلغاء */
    private function reverse(PurchaseOrder $po): void
    {
        DB::transaction(function () use ($po) {
            /** @var Client $client */
            $client = $po->client;

            // ═══ ١. القيود العكسية ═══
            // ⚠️ لكل قيد اتكتب على الأمر بيتعمل قيد مضاد بنفس القيمة
            // ومقلوب الاتجاه. `settlement` هو نوع التسوية المعتمد.
            $entries = Transaction::where('source_type', PurchaseOrder::class)
                ->where('source_id', $po->id)
                ->get();

            foreach ($entries as $t) {
                Transaction::create([
                    'client_id' => $t->client_id,
                    'date' => today(),
                    'memo' => __('flash.memo_po_dupe_reversed', [
                        'number' => $po->number,
                        'kind' => $t->kind,
                    ]),
                    // مقلوب: المدين يبقى دائن والعكس
                    'debit' => (float) $t->credit,
                    'credit' => (float) $t->debit,
                    'tax' => -1 * (float) ($t->tax ?? 0),
                    'kind' => 'settlement',
                    'source_type' => PurchaseOrder::class,
                    'source_id' => $po->id,
                ]);

                $this->line("    ↩ عكس قيد {$t->kind}: مدين {$t->debit} / دائن {$t->credit}");
            }

            // ═══ ٢. البضاعة ترجع للعهدة ═══
            // ⚠️ البضاعة خرجت **مرة واحدة** فيزيكال، والفاتورة خصمتها.
            // خصم الأمر كان زيادة، فبيترجع. الرجوع على نفس العهدة اللي
            // الأمر خصم منها (`pick_orders.custody_id` مش موجود هنا،
            // فبنمشي على عهدة المندوب المفتوحة وقتها = الأحدث المفتوحة).
            $custody = $po->courier?->currentCustody();

            if ($custody !== null) {
                foreach ($po->items as $it) {
                    $back = (int) $it->delivered_qty;

                    if ($back <= 0) {
                        continue;
                    }

                    // ⚠️ الرجوع بيتوزع على بنود الصنف، وكل بند
                    // مايرجعش أكتر من اللي اتباع منه — عشان
                    // `sold` مايبقاش بالسالب (الرَنبوك: العهدة
                    // السالبة معناها خصم عدّى من غير `deduct`).
                    $items = $custody->items()
                        ->where('product_id', $it->product_id)
                        ->orderByDesc('sold')
                        ->get();

                    foreach ($items as $ci) {
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
                        $this->warn("    ⚠ {$it->product?->displayName()}: فاضل {$back} "
                            .'ماقدرناش نرجّعه — العهدة الحالية مافيهاش خصم كفاية.');
                    }
                }

                $this->line('    ↩ البضاعة رجعت لعهدة '.($po->courier?->displayName() ?? '—'));
            } else {
                $this->warn('    ⚠ المندوب مالوش عهدة مفتوحة — البضاعة مارجعتش. صحّحها يدوي من «تعديل العهدة».');
            }

            // ═══ ٣. الأمر يتلغي ═══
            $po->update([
                'status' => 'cancelled',
                'abort_reason' => 'مكرر — البضاعة اتسجّلت بفاتورة كمان (تصحيح ١٥ أغسطس ٢٠٢٦)',
            ]);

            // ═══ ٤. طلب الريفيل يتفك ═══
            $po->replenishmentRequest?->update([
                'purchase_order_id' => null,
                'status' => 'delivered',
            ]);

            // ═══ ٥. إعادة حساب رصيد العميل — أشهر باج في الرَنبوك ═══
            $client?->recalculate();

            $this->info("    ✓ {$po->number} اتعكس. رصيد ".($client?->displayName() ?? '—')
                .' بقى '.number_format((float) $client?->fresh()->balance, 2));
        });
    }
}
