<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * تشخيص التايم زون — قراءة بس، مفيش أي تعديل (2026-08-23)
 * ═══════════════════════════════════════════════════════════════
 *
 * بيطبع كل اللي محتاجينه عشان نفهم حادثة التايم زون من المصدر بدل
 * التخمين من السكرينشوتس:
 *
 *   ١) ساعة PHP (now + التايم زون من الكونفيج) وساعة MySQL جنب بعض
 *      — لو مختلفين يبقى فيه طبقة لسه على UTC.
 *   ٢) أي عمود timestamp عليه `ON UPDATE CURRENT_TIMESTAMP` —
 *      الفخ الضمني بتاع MySQL القديم: أي UPDATE للصف بيدوس على
 *      العمود بوقت التعديل. لو `attendance_punches.at` طلع فيهم،
 *      يبقى أوامر الزحزحة نفسها هي اللي كتبت 10:47 في البانشات.
 *   ٣) بانشات النهارده كلها خام: النوع والوقت وcreated/updated —
 *      عشان نشوف مين اتلمس ومين لأ.
 *   ٤) صفوف attendance_days بتاعة النهارده بالخام برضو.
 *
 * التشغيل: php artisan promax:tz-doctor
 * وابعت الخرج زي ما هو.
 */
class TzDoctor extends Command
{
    protected $signature = 'promax:tz-doctor {--date= : اليوم (الافتراضي النهارده) YYYY-MM-DD}';

    protected $description = 'تشخيص التايم زون: ساعات PHP وMySQL + أعمدة ON UPDATE + بانشات اليوم خام';

    public function handle(): int
    {
        $date = (string) ($this->option('date') ?: today()->toDateString());

        // ═══ ١) الساعتين ═══
        $my = DB::selectOne('SELECT NOW() AS n, @@session.time_zone AS s, @@global.time_zone AS g,
                                    @@explicit_defaults_for_timestamp AS ex');

        $this->info('═══ الساعات ═══');
        $this->line('PHP  config(app.timezone) = '.config('app.timezone'));
        $this->line('PHP  now()                = '.now()->format('Y-m-d H:i:s P'));
        $this->line('MySQL NOW()               = '.$my->n.'  (session tz: '.$my->s.' · global: '.$my->g.')');
        $this->line('MySQL explicit_defaults_for_timestamp = '.$my->ex.'  (0 = الفخ الضمني شغال)');
        $this->newLine();

        // ═══ ٢) أعمدة ON UPDATE CURRENT_TIMESTAMP ═══
        $this->info('═══ أعمدة عليها ON UPDATE CURRENT_TIMESTAMP (الفخ) ═══');

        $traps = DB::select(
            'SELECT TABLE_NAME AS t, COLUMN_NAME AS c, COLUMN_TYPE AS ty, EXTRA AS e, COLUMN_DEFAULT AS d
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND EXTRA LIKE "%on update%"
             ORDER BY TABLE_NAME, ORDINAL_POSITION',
            [DB::getDatabaseName()]
        );

        if ($traps === []) {
            $this->line('  (مفيش — الفخ ده مش موجود)');
        }

        foreach ($traps as $r) {
            $this->line(sprintf('  ⚠ %-28s %-20s %-12s default=%s', $r->t, $r->c, $r->ty, $r->d ?? 'NULL'));
        }
        $this->newLine();

        // ═══ ٣) بانشات اليوم خام ═══
        $this->info("═══ بانشات {$date} خام ═══");

        $punches = DB::select(
            'SELECT p.id, p.user_id, u.name, p.type, p.at, p.auto, p.created_at, p.updated_at
             FROM attendance_punches p
             LEFT JOIN users u ON u.id = p.user_id
             WHERE DATE(p.created_at) = ? OR DATE(p.at) = ?
             ORDER BY p.user_id, p.id',
            [$date, $date]
        );

        foreach ($punches as $p) {
            $this->line(sprintf('  #%-4d %-18s %-6s at=%s  created=%s  updated=%s%s',
                $p->id, mb_substr((string) $p->name, 0, 16), $p->type,
                $p->at, $p->created_at, $p->updated_at, $p->auto ? '  (auto)' : ''));
        }
        $this->newLine();

        // ═══ ٤) أيام الحضور خام ═══
        $this->info("═══ attendance_days بتاعة {$date} خام ═══");

        $days = DB::select(
            'SELECT d.id, d.user_id, u.name, d.first_in_at, d.last_out_at,
                    d.worked_minutes, d.break_minutes, d.sessions, d.status
             FROM attendance_days d
             LEFT JOIN users u ON u.id = d.user_id
             WHERE d.date = ?
             ORDER BY d.user_id',
            [$date]
        );

        foreach ($days as $d) {
            $this->line(sprintf('  #%-4d %-18s in=%s  out=%s  worked=%d  breaks=%d  sess=%d  %s',
                $d->id, mb_substr((string) $d->name, 0, 16),
                $d->first_in_at ?? '—', $d->last_out_at ?? '—',
                $d->worked_minutes, $d->break_minutes, $d->sessions, $d->status));
        }

        return self::SUCCESS;
    }
}
