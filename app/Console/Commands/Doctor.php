<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Warehouse;
use App\Support\Roster;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * promax:doctor — بيقولك السيستم ناقصه إيه، مابيصلّحش
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **اتعمل عشان «اسم المستخدم غلط والباسورد غلط».** الرسالة دي
 * ليها ٦ أسباب مختلفة تماماً وكلهم بيطلّعوا نفس الجملة:
 *
 *   1. الحسابات مااتعملتش أصلاً (`promax:team:setup` مااتشغّلش)
 *   2. الحساب موجود بس `active = 0` — اللوجين بيرفضه
 *   3. الباسورد اتظبط على حساب تاني
 *   4. `.env` بيشاور على داتابيز فاضية غير اللي انت بتبص عليها
 *   5. `config:cache` اتعمل قبل ما `.env` يتظبط — لارافيل بتقرا
 *      الكاش القديم وبتوصل بداتابيز غلط من غير ما تقول
 *   6. `SESSION_SECURE_COOKIE=true` والموقع لسه HTTP — الدخول
 *      بينجح والكوكي مابيتخزنش، فبيرجّعك للوجين وكإنك غلطان
 *
 * ⚠️ **الأمر ده مابيغيّرش حاجة.** بيقرا ويقول بس — عشان تقدر
 * تشغّله على اللايف وانت مطمّن.
 *
 *   php artisan promax:doctor
 *   php artisan promax:doctor --check-password=promax123
 */
class Doctor extends Command
{
    protected $signature = 'promax:doctor
        {--check-password= : يجرّب الباسورد ده على كل الحسابات}';

    protected $description = 'بيفحص السيستم ويقول ناقصه إيه — مابيغيّرش حاجة';

    private int $problems = 0;

    public function handle(): int
    {
        $this->newLine();
        $this->line('  ┌──────────────────────────────────────────┐');
        $this->line('  │  فحص السيستم                             │');
        $this->line('  └──────────────────────────────────────────┘');

        $this->section('١. البيئة والاتصال');
        $this->env();

        $this->section('٢. الداتابيز');
        $this->database();

        $this->section('٣. الحسابات');
        $this->users();

        $this->section('٤. المخازن والمنتجات');
        $this->catalogue();

        $this->section('٥. الملفات والصلاحيات');
        $this->files();

        if ($pw = $this->option('check-password')) {
            $this->section('٦. تجربة الباسورد');
            $this->tryPassword($pw);
        }

        $this->newLine();

        if ($this->problems === 0) {
            $this->info('  ✅ مافيش مشاكل. لو لسه مش بتدخل، المشكلة في الكوكي أو الـSSL —');
            $this->line('     جرّب من نافذة خاصة، وشوف storage/logs/laravel-*.log');
        } else {
            $this->error("  ⛔ {$this->problems} مشكلة فوق — صلّحها بالترتيب.");
        }

        $this->newLine();

        return self::SUCCESS;
    }

    private function env(): void
    {
        $this->row('APP_ENV', config('app.env'), config('app.env') === 'production');
        $this->row('APP_DEBUG', config('app.debug') ? 'true ⚠️' : 'false', ! config('app.debug'),
            'خلّيه false على اللايف — بيعرض باسورد الداتابيز في أي خطأ.');
        $this->row('APP_URL', config('app.url'), str_starts_with((string) config('app.url'), 'http'));
        $this->row('اللغة الافتراضية', config('app.locale'), config('app.locale') === 'en');

        $key = (string) config('app.key');
        $this->row('APP_KEY', $key === '' ? 'فاضي!' : 'موجود', $key !== '',
            'شغّل php artisan key:generate');

        // ⚠️ الكاش القديم أخطر من عدم وجود كاش: بيخلّي `.env` كله
        // متجاهَل من غير أي علامة.
        $cached = file_exists(base_path('bootstrap/cache/config.php'));

        if ($cached) {
            $envDb = trim((string) (parse_ini_file(base_path('.env'))['DB_DATABASE'] ?? ''), '"\'');
            $liveDb = (string) config('database.connections.mysql.database');

            $this->row('كاش الإعدادات', $cached ? 'مفعّل' : 'مطفي',
                $envDb === '' || $envDb === $liveDb,
                $envDb !== $liveDb
                    ? "الكاش بيقول «{$liveDb}» و.env بيقول «{$envDb}» — شغّل php artisan config:cache"
                    : null);
        }

        $secure = (bool) config('session.secure');
        $https = str_starts_with((string) config('app.url'), 'https://');
        $this->row('كوكي HTTPS بس', $secure ? 'true' : 'false', ! ($secure && ! $https),
            'SESSION_SECURE_COOKIE=true والموقع HTTP — الدخول بينجح والكوكي بيضيع، فبيرجّعك للوجين.');
    }

    private function database(): void
    {
        try {
            DB::connection()->getPdo();
            $this->row('الاتصال', config('database.connections.mysql.database'), true);
        } catch (\Throwable $e) {
            $this->row('الاتصال', 'فشل', false, $e->getMessage());

            return;
        }

        $ran = Schema::hasTable('migrations') ? DB::table('migrations')->count() : 0;
        $files = count(glob(database_path('migrations/*.php')));

        $this->row('المايجريشنز', "{$ran} / {$files}", $ran >= $files,
            $ran < $files ? 'شغّل php artisan migrate --force' : null);

        foreach (['users', 'sessions', 'cache', 'jobs'] as $t) {
            if (! Schema::hasTable($t)) {
                $this->row("جدول {$t}", 'مش موجود', false, 'شغّل php artisan migrate --force');
            }
        }
    }

    private function users(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $total = User::count();
        $active = User::where('active', true)->count();

        $this->row('عدد الحسابات', (string) $total, $total > 0,
            $total === 0 ? 'شغّل php artisan promax:team:setup --force' : null);
        $this->row('المفعّلة', (string) $active, $active > 0,
            $active === 0 && $total > 0 ? 'كل الحسابات موقوفة — اللوجين بيرفضها كلها.' : null);

        // ⚠️ حساب من غير باسورد بيرمي «غلط» مهما كتبت.
        $blank = User::whereNull('password')->orWhere('password', '')->count();
        $this->row('من غير باسورد', (string) $blank, $blank === 0,
            $blank > 0 ? 'شغّل php artisan promax:password --all --password=…' : null);

        $missing = collect(Roster::emails())
            ->reject(fn ($e) => User::where('email', $e)->exists())
            ->values();

        $this->row('من قايمة الفريق', (14 - $missing->count()).' / 14', $missing->isEmpty(),
            $missing->isNotEmpty() ? 'ناقص: '.$missing->implode(', ') : null);

        // أمين مخزن من غير مخزن = بيفتح كل المخازن
        $loose = User::where('role', 'warehouse_keeper')->whereNull('warehouse_id')->pluck('email');

        if ($loose->isNotEmpty()) {
            $this->row('أمناء مخازن سايبين', $loose->implode(', '), false,
                'أمين مخزن من غير مخزن بيعدّي من الحارس — بيفتح كل المخازن.');
        }

        $this->newLine();
        $this->line('     ── الحسابات ──');

        foreach (User::orderBy('role')->orderBy('code')->get() as $u) {
            $flag = $u->active ? '<fg=green>●</>' : '<fg=red>○ موقوف</>';
            $this->line(sprintf('     %s %-9s %-30s %s', $flag, $u->code, $u->email, $u->role));
        }
    }

    private function catalogue(): void
    {
        if (! Schema::hasTable('warehouses')) {
            return;
        }

        $wh = Warehouse::pluck('code');
        $this->row('المخازن', $wh->isEmpty() ? 'مافيش' : $wh->implode(' · '), $wh->isNotEmpty(),
            $wh->isEmpty() ? 'شغّل php artisan promax:catalogue --force' : null);

        foreach (['TENTH', 'MAADI'] as $code) {
            if (! $wh->contains($code)) {
                $this->row("مخزن {$code}", 'مش موجود', false, 'شغّل php artisan promax:catalogue --force');
            }
        }

        $products = Schema::hasTable('products') ? DB::table('products')->count() : 0;
        $this->row('المنتجات', (string) $products, $products > 0,
            $products === 0 ? 'شغّل php artisan promax:catalogue --force' : null);
    }

    private function files(): void
    {
        foreach (['storage/logs', 'storage/framework/views', 'bootstrap/cache'] as $dir) {
            $path = base_path($dir);
            $ok = is_dir($path) && is_writable($path);
            $this->row($dir, $ok ? 'قابل للكتابة' : 'مش قابل للكتابة', $ok,
                $ok ? null : 'sudo chown -R www-data:www-data storage bootstrap/cache && sudo chmod -R 775 storage bootstrap/cache');
        }

        $link = public_path('storage');
        $this->row('لينك التخزين', is_link($link) || is_dir($link) ? 'موجود' : 'مش موجود',
            is_link($link) || is_dir($link), 'شغّل php artisan storage:link');
    }

    private function tryPassword(string $password): void
    {
        // ⚠️ **الفحص ده بيقول مين بيفتح بالباسورد ده — ومابيغيّرش
        // حاجة.** بيتشغّل بالإيد على اللايف عشان تعرف إذا كان
        // الباسورد اللي معاك صح ولا لأ، من غير ما تفضل تجرّب في
        // المتصفح وانت مش عارف المشكلة في الباسورد ولا في الكوكي.
        $ok = [];

        foreach (User::all() as $u) {
            if ($u->password && Hash::check($password, $u->password)) {
                $ok[] = $u->email.($u->active ? '' : ' <fg=red>(موقوف)</>');
            }
        }

        if ($ok === []) {
            $this->row('الباسورد ده', 'مافيش حساب بيفتح بيه', false,
                'ظبّطه: php artisan promax:password <الإيميل> --password='.$password);
        } else {
            $this->row('الباسورد ده', count($ok).' حساب', true);

            foreach ($ok as $e) {
                $this->line("       • {$e}");
            }
        }
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line("  <fg=cyan>── {$title} ──</>");
    }

    private function row(string $label, string $value, bool $ok, ?string $fix = null): void
    {
        $mark = $ok ? '<fg=green>✓</>' : '<fg=red>✗</>';
        $this->line(sprintf('     %s %-22s %s', $mark, $label, $value));

        if (! $ok) {
            $this->problems++;

            if ($fix) {
                $this->line("        <fg=yellow>← {$fix}</>");
            }
        }
    }
}
