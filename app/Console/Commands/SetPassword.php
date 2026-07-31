<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * ═══════════════════════════════════════════════════════════════
 * promax:password — ظبط باسورد أي حساب
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ موجود عشان حالة حقيقية حصلت: `promax:reset` بيعمل الأدمن
 * بباسورد بيتحدد وقت التفضية، والسيدر **مابيغيّرش** باسورد حساب
 * موجود (عن قصد، عشان مايقفلش على اللي غيّر باسورده). النتيجة إن
 * الحساب موجود والباسورد اللي في التعليمات مش بتاعه، ومفيش طريقة
 * تعرف الصح غير إنك تعيد ضبطه.
 *
 * أمثلة:
 *   php artisan promax:password
 *   php artisan promax:password admin@promax.local
 *   php artisan promax:password ADM-001 --password=1234
 *   php artisan promax:password --all --password=promax123
 */
class SetPassword extends Command
{
    protected $signature = 'promax:password
        {user? : الإيميل أو كود الموظف}
        {--password= : الباسورد الجديد}
        {--all : كل الحسابات}';

    protected $description = 'ظبط باسورد حساب (أو كل الحسابات)';

    public function handle(): int
    {
        if (User::count() === 0) {
            $this->error('  مفيش حسابات أصلاً. شغّل: php artisan promax:team');

            return self::FAILURE;
        }

        $password = $this->option('password')
            ?: ($this->secret('الباسورد الجديد (فاضي = promax123)') ?: 'promax123');

        if (strlen($password) < 4) {
            $this->error('  الباسورد قصير أوي.');

            return self::FAILURE;
        }

        // ═══════════ كل الحسابات ═══════════
        if ($this->option('all')) {
            // ⚠️ تأكيد إجباري — الأمر ده بيغيّر باسورد كل الموظفين
            if (! $this->option('no-interaction')
                && ! $this->confirm('هتغيّر باسورد **كل** الحسابات ('.User::count().'). متأكد؟', false)) {
                $this->line('  اتلغى.');

                return self::SUCCESS;
            }

            $n = 0;
            // ⚠️ `chunkById` مش `get()` — لو الفريق كبر، تحميل الجدول
            // كله في الذاكرة عشان نعمل hash لكل صف بيفجّر الميموري.
            User::query()->chunkById(100, function ($users) use ($password, &$n) {
                foreach ($users as $user) {
                    $user->forceFill(['password' => Hash::make($password)])->save();
                    $n++;
                }
            });

            $this->newLine();
            $this->info("  ✅ {$n} حساب باسوردهم بقى: {$password}");
            $this->newLine();

            return self::SUCCESS;
        }

        // ═══════════ حساب واحد ═══════════
        $key = $this->argument('user') ?: $this->askForUser();

        if ($key === null) {
            return self::FAILURE;
        }

        $user = User::where('email', $key)->orWhere('code', $key)->first();

        if ($user === null) {
            $this->error("  مفيش حساب بالإيميل أو الكود: {$key}");
            $this->line('  الحسابات الموجودة:');
            foreach (User::orderBy('role')->get() as $u) {
                $this->line("    • {$u->code}  —  {$u->email}");
            }

            return self::FAILURE;
        }

        // ⚠️ `forceFill` مش `update` — `password` مش في `$fillable`
        // (وده مقصود)، و`update` بتتجاهله في صمت وبتقول تمام.
        $user->forceFill(['password' => Hash::make($password)])->save();

        // الحساب الموقوف باسورده بيتغيّر بس بيفضل مقفول — لازم نقول
        if (! $user->active) {
            $this->warn('  ⚠️ الحساب ده **موقوف** — الباسورد اتغيّر بس الدخول هيفضل مرفوض.');
        }

        $this->newLine();
        $this->info("  ✅ {$user->name} ({$user->email})");
        $this->line("     الباسورد بقى: {$password}");
        $this->newLine();

        return self::SUCCESS;
    }

    private function askForUser(): ?string
    {
        $users = User::orderByRaw("FIELD(role, 'admin', 'manager', 'branch_manager')")
            ->orderBy('name')
            ->get();

        $choices = $users
            ->mapWithKeys(fn (User $u) => [$u->email => "{$u->name} — {$u->roleLabel()} — {$u->email}"])
            ->all();

        return $this->choice('الحساب', $choices, array_key_first($choices));
    }
}
