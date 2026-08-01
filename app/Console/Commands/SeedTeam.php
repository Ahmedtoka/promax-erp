<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\ModernTradeSeeder;
use Illuminate\Console\Command;

/**
 * ═══════════════════════════════════════════════════════════════
 * promax:team — الفريق والفروع والعربيات بس
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **مش `db:seed`.** السيدر الكامل بيرحّل الداتا التاريخية كلها
 * (103 عميل و~2000 حركة وعقود وفواتير). بعد `promax:reset` والرفع
 * بالشيتات، تشغيله معناه إن الداتا القديمة بترجع فوق الجديدة
 * وتختلط بيها ومحدش يعرف يفصلهم.
 *
 * الأمر ده بيعمل **اليوزرات والفروع والعربيات والمناطق بس**.
 */
class SeedTeam extends Command
{
    protected $signature = 'promax:team {--force : من غير تأكيد}';

    protected $description = 'إنشاء فريق العمل والفروع والعربيات (آمن يتكرر)';

    public function handle(ModernTradeSeeder $seeder): int
    {
        // ⚠️ **الأمر ده بيحطّ فريق الديمو بباسورد معروف.**
        // اسمه شبه `promax:team:setup` (اللي بيحطّ الفريق الحقيقي)
        // لدرجة إن `promax:password` نفسه كان بينصح بيه بالغلط.
        if (app()->environment('production')) {
            $this->error('  ⛔ الأمر ده بيحطّ فريق ديمو بباسورد معروف — ممنوع على production.');
            $this->line('     الفريق الحقيقي: php artisan promax:team:setup');

            return self::FAILURE;
        }

        $existing = User::count();

        $this->newLine();
        $this->line('  ┌─────────────────────────────────────────┐');
        $this->line('  │  فريق العمل والفروع والعربيات          │');
        $this->line('  └─────────────────────────────────────────┘');
        $this->newLine();

        if ($existing > 0) {
            $this->warn("  في {$existing} يوزر موجود.");
            $this->line('  الأمر ده **مابيمسحش** — بيحدّث الموجود ويضيف الناقص،');
            $this->line('  والباسوردات الحالية بتفضل زي ما هي.');
            $this->newLine();
        }

        if (! $this->option('force') && ! $this->confirm('نكمّل؟', true)) {
            $this->line('  اتلغى.');

            return self::SUCCESS;
        }

        // ⚠️ السيدر محتاج `$this->command` عشان يطبع — من غيرها
        // بيرمي «Call to a member function info() on null».
        $seeder->setCommand($this);
        $seeder->run();

        $this->newLine();
        $this->line('  ── الحسابات ──');
        $this->newLine();

        $rows = User::with('branch')
            ->orderByRaw("FIELD(role, 'admin', 'manager', 'branch_manager', 'sales_agent', 'driver', 'promoter')")
            ->get()
            ->map(fn (User $u) => [
                $u->code,
                $u->name,
                $u->roleLabel(),
                $u->email,
                $u->branch?->name ?? '—',
            ])
            ->all();

        $this->table(['الكود', 'الاسم', 'الوظيفة', 'الإيميل', 'الفرع'], $rows);

        $this->newLine();
        $this->line('  العربيات:');
        foreach (Vehicle::with(['rep', 'driver'])->get() as $v) {
            $this->line("    • {$v->plate} — {$v->kind} — {$v->crewLabel()}");
        }

        $this->newLine();
        $this->info('  ✅ خلاص. ادخل بأي إيميل فوق — الباسورد في السيدر');
        $this->line('     أو بكود الموظف بدل الإيميل.');
        $this->newLine();

        if (Branch::count() > 0) {
            $this->line('  ⚠️ الداتا اللي مش متخصصة لفرع بتبان لكل الفروع.');
            $this->line('     من /erp/branches تقدر تشوف كام حاجة مركزية.');
            $this->newLine();
        }

        return self::SUCCESS;
    }
}
