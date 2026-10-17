<?php

namespace App\Console\Commands;

use App\Models\OnlineOrder;
use App\Models\OnlinePickup;
use App\Models\OnlineReturn;
use App\Models\PickOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * أوامر تجهيز الأونلاين «الجاهزة» القديمة (١٧/١٠/٢٠٢٦ — الخط ٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * أمر تجهيز الأونلاين مالوش handOver — البضاعة بتخرج من الرف وقت
 * «تم التجهيز» (markReady) والشحن بيتم من موديول الأونلاين. فالأمر
 * بيفضل `ready` للأبد، وبيتعد «مفتوح» في المخزن وفي فحص «جاهز أكتر
 * من ٧ أيام». الأمر ده بيصنّفهم:
 *
 *   • orphan_reship — أمر قديم لأوردر **اتعاد شحنه**: الأوردر اتنقل على
 *     أمر جديد والقديم فضل `ready` من غير أوردر (§9 في promax-system).
 *   • orphan_other  — `ready` من غير أوردر ومن غير سجل إعادة شحن (يدوي).
 *   • done          — أوردره اتشحن/اتحصّل/رجع: البضاعة خرجت مع المندوب.
 *   • stuck         — أوردره لسه «جاهز» ومااتشحنش: **محتاج حد يتصرف**،
 *                     الأمر ده مابيلمسوش أبداً.
 *
 * التنفيذ = `status = handed` + `handed_at` = وقت خروج الشحنة. **مفيش
 * أي حركة مخزون** — البضاعة خرجت وقت التجهيز، والمرتجع (لو حصل) رجّعها
 * بـ`restock()` اللي مابتبصش على حالة الأمر.
 *
 * التشغيل:
 *   php artisan promax:online-close-picks                    (معاينة)
 *   php artisan promax:online-close-picks --apply            (اليتامى بس)
 *   php artisan promax:online-close-picks --apply --include-done
 *
 * ⚠️ على قاعدة `promax` (اللايف) التنفيذ محتاج `--live` + تأكيد.
 */
class OnlineClosePicks extends Command
{
    protected $signature = 'promax:online-close-picks
                            {--days=7 : الأوامر اللي بقالها جاهزة أكتر من كذا يوم}
                            {--apply : التنفيذ الفعلي — من غيره معاينة بس}
                            {--include-done : كمان أوامر الأوردرات اللي اتشحنت/اتحصلت/رجعت}
                            {--live : مطلوب للتنفيذ على قاعدة promax}';

    protected $description = 'تصنيف أوامر تجهيز الأونلاين الجاهزة القديمة وقفل اليتيمة منها (معاينة افتراضياً)';

    public function handle(): int
    {
        $days = max(0, (int) $this->option('days'));
        $apply = (bool) $this->option('apply');
        $includeDone = (bool) $this->option('include-done');
        $db = (string) DB::connection()->getDatabaseName();

        if ($apply && $db === 'promax') {
            if (! $this->option('live')) {
                $this->error('❌ القاعدة promax = داتا اللايف. التنفيذ عليها محتاج --live صريح.');

                return self::FAILURE;
            }

            if (! $this->confirm("تنفيذ فعلي على {$db}؟", false)) {
                $this->warn('اتلغى.');

                return self::FAILURE;
            }
        }

        $rows = self::classify($days);

        $this->info(($apply ? '🚀 تنفيذ فعلي' : '👀 معاينة بس — من غير --apply مفيش أي تعديل')
            ." · {$db} · جاهز قبل ".now()->subDays($days)->toDateString());
        $this->newLine();

        $this->table(['#', 'pick', 'ready_at', 'class', 'order', 'order status', 'handed_at →'],
            array_map(fn ($r) => [
                $r['pick']->id, $r['pick']->number, (string) $r['pick']->ready_at, $r['class'],
                $r['order']?->number ?? '—', $r['order']?->status ?? '—', (string) ($r['handed_at'] ?? '—'),
            ], $rows));

        $counts = array_count_values(array_column($rows, 'class'));
        ksort($counts);
        $this->line('  الإجمالي '.count($rows).' · '.collect($counts)->map(fn ($n, $k) => "{$k}={$n}")->implode(' · '));

        $targets = array_values(array_filter($rows, fn ($r) => str_starts_with($r['class'], 'orphan')
            || ($includeDone && $r['class'] === 'done')));

        $this->line('  هيتقفل: '.count($targets).($includeDone ? '' : ' (اليتامى بس — --include-done للباقي)')
            .' · stuck مابيتلمسش أبداً');

        if (! $apply || $targets === []) {
            return self::SUCCESS;
        }

        $closed = DB::transaction(function () use ($targets) {
            $n = 0;

            foreach ($targets as $r) {
                // ⚠️ مشروط على الحالة — لو حد اتصرف في الأمر بين المعاينة والتنفيذ مايتلمسش
                $n += PickOrder::whereKey($r['pick']->id)
                    ->where('purpose', PickOrder::PURPOSE_ONLINE)
                    ->where('status', 'ready')
                    ->update(['status' => 'handed', 'handed_at' => $r['handed_at'] ?? $r['pick']->ready_at]);
            }

            return $n;
        });

        $this->info("✅ اتقفل {$closed} أمر.");

        return self::SUCCESS;
    }

    /**
     * @return list<array{pick: PickOrder, class: string, order: ?OnlineOrder, handed_at: ?Carbon}>
     */
    public static function classify(int $days = 7): array
    {
        $picks = PickOrder::where('purpose', PickOrder::PURPOSE_ONLINE)
            ->where('status', 'ready')
            ->where('ready_at', '<', now()->subDays($days))
            ->orderBy('id')->get();

        $linked = OnlineOrder::whereIn('pick_order_id', $picks->pluck('id'))->get()->keyBy('pick_order_id');
        $pickups = OnlinePickup::whereIn('id', $linked->pluck('pickup_id')->filter())->get()->keyBy('id');

        $out = [];

        foreach ($picks as $pick) {
            $order = $linked[$pick->id] ?? null;

            if ($order !== null) {
                $done = in_array($order->status, ['shipped', 'completed', 'returned'], true);
                $out[] = [
                    'pick' => $pick,
                    'class' => $done ? 'done' : 'stuck',
                    'order' => $order,
                    'handed_at' => $done
                        ? ($order->shipped_at ?? $pickups->get((int) $order->pickup_id)?->created_at ?? $pick->ready_at)
                        : null,
                ];

                continue;
            }

            // اليتيم: رقمه ON-{رقم الأوردر} وممكن بعده R (تكرار)
            $number = preg_replace('/R+$/', '', substr((string) $pick->number, 3));
            $owner = str_starts_with((string) $pick->number, 'ON-')
                ? OnlineOrder::where('number', $number)->first()
                : null;

            $reship = $owner === null ? null : OnlineReturn::with('pickup')
                ->where('online_order_id', $owner->id)
                ->whereNotNull('reshipped_at')
                ->where(fn ($q) => $q->whereNull('reship_pick_order_id')->orWhere('reship_pick_order_id', '!=', $pick->id))
                ->orderBy('id')->first();

            $out[] = [
                'pick' => $pick,
                'class' => $reship !== null ? 'orphan_reship' : 'orphan_other',
                'order' => $owner,
                // الشحنة الأصلية خرجت على شيت المرتجع
                'handed_at' => $reship?->pickup?->created_at ?? $pick->ready_at,
            ];
        }

        return $out;
    }
}
