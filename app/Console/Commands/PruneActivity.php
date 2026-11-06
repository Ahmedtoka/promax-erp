<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use Illuminate\Console\Command;

/**
 * تنضيف سجل الحركة — بيمسح الأقدم من فترة الاحتفاظ.
 *
 * ⚠️ **فتح الصفحات بيتمسح أسرع من التعديلات.** الزيارات ضوضاء بعد
 * أسبوعين، لكن «مين غيّر سعر المنتج ده» لازم يفضل شهور. الفصل ده
 * هو اللي بيخلي الجدول ينفع يفضل شغال من غير ما يوصل ملايين الصفوف.
 *
 *   php artisan promax:prune-activity              # الافتراضي
 *   php artisan promax:prune-activity --views=7 --edits=120
 */
class PruneActivity extends Command
{
    protected $signature = 'promax:prune-activity {--views=21 : أيام الاحتفاظ بفتح الصفحات}
                                                  {--edits=180 : أيام الاحتفاظ بالتعديلات والدخول}
                                                  {--pings=90 : أيام الاحتفاظ بنبضات وقت الشغل}';

    protected $description = 'تنضيف سجل حركة اليوزرات حسب فترة الاحتفاظ';

    public function handle(): int
    {
        $views = ActivityLog::where('event', 'viewed')
            ->where('created_at', '<', now()->subDays((int) $this->option('views')))
            ->delete();

        $rest = ActivityLog::where('event', '!=', 'viewed')
            ->where('created_at', '<', now()->subDays((int) $this->option('edits')))
            ->delete();

        // نبضات وقت الشغل (٥/١١) — صف لكل تاب في الدقيقة
        $pings = \Illuminate\Support\Facades\Schema::hasTable('presence_pings')
            ? \Illuminate\Support\Facades\DB::table('presence_pings')
                ->where('at', '<', now()->subDays((int) $this->option('pings')))->delete()
            : 0;

        $this->info("اتمسح: $views زيارة و $rest حركة و $pings نبضة. الباقي: ".ActivityLog::count());

        return self::SUCCESS;
    }
}
