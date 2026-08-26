<?php

namespace App\Console\Commands;

use App\Models\PickOrder;
use App\Models\PurchaseOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * تصليح أمر توريد اتوافق عليه يدوي وسط حادثة إعادة التسعير (٢٤/٨)
 * ═══════════════════════════════════════════════════════════════
 *
 * الحالة: أمر رجع للحسابات بالغلط، والمالك وافق عليه يدوي قبل أمر
 * الإصلاح — فاتفتح تجهيز جديد فوق القديم. الأمر ده بيبص على كل
 * تجهيزات الأمر وبيقرر:
 *
 *   • لو فيه تجهيز **أقدم عايش** (متسلم/جاهز/شغال): الأمر بيترجع
 *     يتربط بيه، وتاريخ الموافقة بيرجع لتاريخه، والتجهيز الجديد
 *     بيتلغى — والإلغاء بيرجّع أي كميات اتلمّت للرف، فتجهيز لسه
 *     «مطلوب» بصفر مجهّز = تغيير حالة بس، **مفيش أي حركة ستوك**.
 *   • لو مفيش قديم عايش (كان اتلغى قبل ما يتجهز): التجهيز الجديد
 *     هو الصح أصلاً — بنظبط تاريخ الموافقة بس ونسيب كل حاجة.
 *
 * التشغيل: php artisan promax:fix-po-pick PO-2013
 *          php artisan promax:fix-po-pick PO-2013 --apply
 */
class FixPoPick extends Command
{
    protected $signature = 'promax:fix-po-pick
                            {number : رقم أمر التوريد (مثال PO-2013)}
                            {--apply : التنفيذ الفعلي — من غيره معاينة بس}';

    protected $description = 'رجوع أمر توريد اتوافق عليه يدوي لتجهيزه القديم — وإلغاء الجديد الفاضي من غير أي حركة ستوك';

    public function handle(): int
    {
        $number = trim((string) $this->argument('number'));
        $apply = (bool) $this->option('apply');

        $po = PurchaseOrder::with('client')->where('number', $number)->first();

        if ($po === null) {
            $this->error("❌ مفيش أمر بالرقم {$number}");

            return self::FAILURE;
        }

        $picks = PickOrder::where('purchase_order_id', $po->id)->orderBy('id')->get();

        $this->info(($apply ? '🚀 تنفيذ فعلي' : '👀 معاينة بس — من غير --apply مفيش أي تعديل')
            ." · {$po->number} — ".($po->client?->fullName() ?? '—'));
        $this->newLine();

        $this->line('  الحالة: '.$po->status.' · الموافقة: '.($po->approval_status ?? '—')
            .' · التجهيز المربوط: '.($po->pick_order_id ?? '—'));

        foreach ($picks as $p) {
            $this->line(sprintf('  تجهيز #%-5d %-10s اتعمل %s · مجهّز %s',
                $p->id, $p->status, $p->created_at, collect($p->items ?? [])->count() ? '?' : '-'));
        }

        if ($picks->isEmpty()) {
            $this->error('❌ الأمر ده مالوش أي تجهيزات — مش الحالة اللي الأمر ده معمول ليها.');

            return self::FAILURE;
        }

        $newest = $picks->last();
        $priorAlive = $picks->filter(fn ($p) => $p->id !== $newest->id
            && $p->status !== 'cancelled')->last();

        $this->newLine();

        if ($priorAlive === null) {
            // مفيش قديم عايش — الجديد هو الصح، تاريخ الموافقة بس
            $oldest = $picks->first();
            $this->line("الخطة: مفيش تجهيز قديم عايش — التجهيز #{$newest->id} هو الساري ويفضل زي ما هو.");
            $this->line("       تاريخ الموافقة هيرجع لـ{$oldest->created_at} (لحظة الموافقة الأصلية).");

            if ($apply) {
                $po->update(['approved_at' => $oldest->created_at]);
                $this->info('✅ اتظبط.');
            }

            return self::SUCCESS;
        }

        $this->line("الخطة: الأمر يرجع يتربط بتجهيزه القديم #{$priorAlive->id} ({$priorAlive->status})");
        $this->line("       والتجهيز الجديد #{$newest->id} ({$newest->status}) يتلغى — أي كميات اتلمّت بترجع للرف؛");
        $this->line('       تجهيز «مطلوب» بصفر مجهّز = تغيير حالة بس، مفيش حركة ستوك.');
        $this->line("       وتاريخ الموافقة يرجع لـ{$priorAlive->created_at}.");

        if (! $apply) {
            $this->newLine();
            $this->info('شغّل بـ --apply للتنفيذ.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($po, $priorAlive, $newest) {
            if ($newest->status !== 'cancelled' && $newest->id !== $priorAlive->id) {
                if ($err = $newest->cancel()) {
                    throw new \App\Exceptions\Rejected($err);
                }
            }

            $po->update([
                'pick_order_id' => $priorAlive->id,
                'approved_at' => $priorAlive->created_at,
                'prep_started_at' => $po->prep_started_at ?? $priorAlive->created_at,
            ]);
        });

        $this->info("✅ {$po->number} رجع لتجهيزه القديم #{$priorAlive->id} — والجديد اتلغى من غير أي حركة ستوك.");

        return self::SUCCESS;
    }
}
