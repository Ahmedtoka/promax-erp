<?php

namespace App\Console\Commands;

use App\Models\PickOrder;
use App\Models\PurchaseOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * إصلاح أوامر التوريد اللي إعادة التسعير رجّعتها للحسابات (٢٤/٨)
 * ═══════════════════════════════════════════════════════════════
 *
 * اللي حصل: أول نسخة من «إعادة التسعير» كانت بتعامل الأمر المعتمد
 * زي التعديل — بترجّعه لطابور الحسابات وبتلغي أمر تجهيزه. الفحص
 * الجماعي طبّق ده على أوامر قديمة شغّالة: المناديب مابقوش شايفين
 * أوامرهم، والموافقة تاني كانت هتفتح أوامر تجهيز جديدة فوق القديمة.
 *
 * الأمر ده بيرجّع كل أمر متضرر **لآخر حالته بأسعاره الجديدة**:
 *
 *   • المميِّز القاطع: أمر pending معدّل وله أمر تجهيز قديم —
 *     التجهيز مابيتعملش غير بالموافقة، فوجوده = كان معتمد أكيد.
 *     الأوامر اللي لسه ماتوافقش عليها أصلاً مالهاش تجهيز فمش بتتلمس.
 *   • تجهيزه لسه عايش (متسلم/جاهز/مطلوب): بيرجع معتمد ويتربط
 *     بتجهيزه القديم زي ما كان — من غير أي تجهيز جديد.
 *   • تجهيزه اتلغى بالغلط: بيرجع معتمد وبيترفع تجهيز جديد بنفس
 *     مخزنه ومندوبه وكمياته (بأسعار البنود الجديدة) — نفس نداء
 *     الموافقة بالحرف، بس من غير ما الحسابات تتعب تاني.
 *   • تاريخ الموافقة بيرجع من تاريخ إنشاء التجهيز القديم — دي لحظة
 *     الموافقة الفعلية.
 *
 * معاينة افتراضية، و`--apply` للتنفيذ. آمن يتكرر: بعد الإصلاح
 * الأمر بيبقى approved فبيخرج من الشرط لوحده.
 */
class RestoreRepricedPos extends Command
{
    protected $signature = 'promax:restore-repriced
                            {--apply : التنفيذ الفعلي — من غيره معاينة بس}';

    protected $description = 'رجوع الأوامر اللي إعادة التسعير رجّعتها للحسابات — لآخر حالتها بأسعارها الجديدة';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $this->info($apply ? '🚀 تنفيذ فعلي' : '👀 معاينة بس — من غير --apply مفيش أي تعديل');
        $this->newLine();

        $candidates = PurchaseOrder::with(['items', 'warehouse', 'courier'])
            ->where('approval_status', 'pending')
            ->where('was_edited', true)
            ->whereNotIn('status', ['delivered', 'cancelled'])
            ->whereIn('id', PickOrder::whereNotNull('purchase_order_id')->pluck('purchase_order_id'))
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('مفيش أوامر متضررة — كله في حالته الصح. ✅');

            return self::SUCCESS;
        }

        $fixed = 0;
        $failed = 0;

        foreach ($candidates as $po) {
            $pick = PickOrder::where('purchase_order_id', $po->id)->latest('id')->first();

            $alive = $pick !== null && $pick->status !== 'cancelled';

            $plan = $alive
                ? "ربط بتجهيزه القديم #{$pick->id} ({$pick->status})"
                : 'تجهيز جديد بدل الملغي';

            $this->line(sprintf('  %s %-12s %-30s %s',
                $apply ? '✔' : '·', $po->number,
                mb_substr((string) $po->client?->fullName(), 0, 28), $plan));

            if (! $apply) {
                $fixed++;

                continue;
            }

            try {
                DB::transaction(function () use ($po, $pick, $alive) {
                    if (! $alive) {
                        // نفس نداء الموافقة بالحرف — طلب تجهيز جديد
                        // بكميات البنود الحالية (الأسعار الجديدة)
                        // ⚠️ CLI مالوش auth() — الفاعل هو اللي عمل إعادة
                        // التسعير (edited_by)، وفولباك لأول أدمن نشط
                        $actor = \App\Models\User::find($po->edited_by)
                            ?? \App\Models\User::where('role', 'admin')->where('active', true)->first();

                        $result = PickOrder::raise(
                            $po->warehouse,
                            $po->courier,
                            $po->items->pluck('qty', 'product_id')->all(),
                            PickOrder::PURPOSE_CUSTOMER_PO,
                            $actor,
                            [
                                'purchase_order_id' => $po->id,
                                'pickup_at' => $po->pickup_at,
                            ],
                        );

                        if ($result['error']) {
                            throw new \App\Exceptions\Rejected($result['error']);
                        }

                        $pickId = $result['order']->id;
                    } else {
                        $pickId = $pick->id;
                    }

                    $po->update([
                        'approval_status' => 'approved',
                        // لحظة الموافقة الفعلية = لحظة إنشاء التجهيز الأصلي
                        'approved_at' => $pick?->created_at ?? now(),
                        'approved_by' => $pick?->created_by ?? null,
                        'pick_order_id' => $pickId,
                        'prep_started_at' => $po->prep_started_at ?? $pick?->created_at ?? now(),
                    ]);
                });

                $fixed++;
            } catch (\Throwable $e) {
                $failed++;
                $this->error("    ✖ {$po->number}: ".$e->getMessage());
            }
        }

        $this->newLine();
        $this->info($apply
            ? "✅ اترجّع {$fixed} أمر لحالته — والمناديب هيشوفوهم تاني.".($failed ? " ({$failed} فشل — شوف فوق)" : '')
            : "المعاينة: {$fixed} أمر هيترجّع. شغّل بـ --apply للتنفيذ.");

        return self::SUCCESS;
    }
}
