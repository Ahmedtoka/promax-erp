<?php

namespace App\Console\Commands;

use App\Models\Gl\GlAccount;
use App\Models\User;
use Illuminate\Console\Command;

/** حساب «نقدية مع المندوب» لكل مستخدم ميداني نشط — آمن يتعاد */
class GlSyncRepAccounts extends Command
{
    protected $signature = 'promax:gl-sync-reps';

    protected $description = 'إنشاء حساب نقدية في الشجرة لكل مندوب/سواق/بروموتر/مدير ميداني نشط';

    public function handle(): int
    {
        if (! GlAccount::where('system_key', 'rep_cash')->exists()) {
            $this->error('الشجرة مش متولدة — شغّل GlSeeder الأول');

            return self::FAILURE;
        }
        $n = 0;
        foreach (User::whereIn('role', User::FIELD_WORK_ROLES)->where('active', true)->get() as $u) {
            $had = GlAccount::where('user_id', $u->id)->exists();
            GlAccount::repCash($u);
            $n += $had ? 0 : 1;
        }
        $this->info("تم — حسابات جديدة: {$n}");

        return self::SUCCESS;
    }
}
