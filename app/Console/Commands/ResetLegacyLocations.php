<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Support\Governorates;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * تصفير التأكيدات القديمة  ·  ١٧ أغسطس ٢٠٢٦
 * ═══════════════════════════════════════════════════════════════
 *
 * طلب المالك: «فيه عملاء اتأكّدوا قبل ما نعمل الأوبشن بتاع تعديل
 * لوكيشن العميل — عاوز أشيلهم، وتبقى الداتا في الشاشة كلها بتاعت
 * التعديل الجديد، عشان أحصر كام لوكيشن مؤكَّد وفاضلي كام، وفي
 * مناطق ومحافظات إيه».
 *
 * ═══ إيه اللي بيتشال بالظبط ═══
 *
 * ⚠️⚠️ **الفارق هو `location_submitted_at`.** الصف اللي عدّى على
 * فلو المندوب الجديد عنده بصمة إرسال؛ اللي اتأكّد قبل الفلو ده
 * مالوش. فالشرط:
 *
 *   `location_confirmed_at IS NOT NULL` **و** `location_submitted_at IS NULL`
 *
 * ⚠️ **الإحداثيات والعنوان مابيتشالوش.** التصفير على **حالة
 * المراجعة** بس. النقطة القديمة تخمين، بس تخمين أحسن من فراغ:
 * المودال بيفتح عليها والمراجع بيصحّحها بدل ما يبدأ من الصفر،
 * والمندوب لسه بيلاقي «الاتجاهات» شغّال وهو رايح.
 *
 * ⚠️ **الاستعلام مالهوش علاقة بـ`location_source`.** الصف القديم
 * ممكن يكون `visit` أو `manual` أو `map` أو حتى فاضي — كلهم قبل
 * الفلو. المصدر بيتساب زي ما هو كأثر تاريخي.
 *
 *   php artisan promax:reset-locations
 *   php artisan promax:reset-locations --fix
 */
class ResetLegacyLocations extends Command
{
    protected $signature = 'promax:reset-locations {--fix : نفّذ — من غيرها تقرير بس}';

    protected $description = 'تصفير تأكيدات اللوكيشن اللي قبل فلو المندوب + تقرير بالمحافظات والمناطق';

    public function handle(): int
    {
        $fix = (bool) $this->option('fix');

        $legacy = Client::where('status', '!=', 'rejected')
            ->whereNotNull('location_confirmed_at')
            ->whereNull('location_submitted_at');

        $count = (clone $legacy)->count();

        $this->line('');
        $this->line('  تأكيدات قديمة (قبل فلو المندوب): '.$count);

        if ($count > 0) {
            // توزيع اللي هيتصفّر — عشان المالك يشوف الأثر قبل التنفيذ
            $byGov = (clone $legacy)->selectRaw('governorate, COUNT(*) n')
                ->groupBy('governorate')->orderByDesc('n')->get();

            foreach ($byGov as $g) {
                $this->line(sprintf('      %-22s %s',
                    Governorates::label($g->governorate), $g->n));
            }
        }

        if ($fix) {
            // ⚠️ **بصمة مين صفّر وإمتى مش موجودة عن قصد** — الصف
            // بيرجع لحالة «مش متأكّد» وخلاص، وهي الحالة الطبيعية
            // لأي عميل جديد. عمود جديد عشان عملية بتتعمل مرة واحدة
            // مايستاهلش.
            DB::transaction(function () use ($legacy) {
                (clone $legacy)->update([
                    'location_confirmed_at' => null,
                    'location_confirmed_by' => null,
                ]);
            });

            $this->info('  ✓ اتصفّر '.$count.' عميل.');
        } elseif ($count > 0) {
            $this->comment('  (تقرير بس — ضيف --fix للتصفير)');
        }

        $this->line('');
        $this->line('  ══════════ الحصر بعد التصفير ══════════');
        $this->breakdown($fix);

        return self::SUCCESS;
    }

    /**
     * كام مؤكَّد وكام فاضل — بالمحافظة وبالمنطقة.
     *
     * ⚠️ **بيتحسب بعد التصفير في نفس التشغيلة.** لو حسبناه قبله
     * كان هيقول أرقام قديمة والمالك يفتكرها الجديدة.
     */
    private function breakdown(bool $applied): void
    {
        $base = fn () => Client::where('status', '!=', 'rejected');

        // ⚠️ لو لسه ماتصفّرش، بنستثني القديم من العدّ عشان التقرير
        // يوري **النتيجة المتوقعة** مش الحالة الحالية.
        $confirmed = $base()->whereNotNull('location_confirmed_at')
            ->when(! $applied, fn ($q) => $q->whereNotNull('location_submitted_at'));

        $total = $base()->count();
        $ok = (clone $confirmed)->count();

        $this->line('  العملاء: '.$total
            .'  ·  مؤكَّد: '.$ok
            .'  ·  فاضل: '.($total - $ok));
        $this->line('');

        // ═══ بالمحافظة ═══
        $rows = $base()
            ->selectRaw('governorate,
                         COUNT(*) as total,
                         SUM(location_confirmed_at IS NOT NULL'
                         .($applied ? '' : ' AND location_submitted_at IS NOT NULL')
                         .') as ok')
            ->groupBy('governorate')->get()
            ->sortByDesc(fn ($r) => $r->total - $r->ok);

        $this->line('  ── المحافظات (مرتّبة بالناقص) ──');

        foreach ($rows as $r) {
            $left = (int) $r->total - (int) $r->ok;

            $this->line(sprintf('      %-22s ناقص %-5s من %-5s %s',
                Governorates::label($r->governorate) ?: '—',
                $left, $r->total,
                $left === 0 ? '✅' : ''));
        }

        // ═══ أسوأ ١٥ منطقة ═══
        $zones = $base()->with('zone')
            ->selectRaw('zone_id,
                         COUNT(*) as total,
                         SUM(location_confirmed_at IS NOT NULL'
                         .($applied ? '' : ' AND location_submitted_at IS NOT NULL')
                         .') as ok')
            ->groupBy('zone_id')->get()
            ->map(fn ($r) => [
                'name' => $r->zone?->displayName() ?? '—',
                'left' => (int) $r->total - (int) $r->ok,
                'total' => (int) $r->total,
            ])
            ->filter(fn ($r) => $r['left'] > 0)
            ->sortByDesc('left')->take(15);

        $this->line('');
        $this->line('  ── أكتر ١٥ منطقة ناقصة ──');

        foreach ($zones as $z) {
            $this->line(sprintf('      %-28s ناقص %-5s من %s',
                mb_substr($z['name'], 0, 28), $z['left'], $z['total']));
        }

        $this->line('');
        $this->comment('  الشاشة: /erp/client-locations  ·  الرصيد: /erp/client-locations/credits');
    }
}
