<?php

namespace App\Console\Commands;

use App\Models\Channel;
use Illuminate\Console\Command;

/**
 * ═══════════════════════════════════════════════════════════════
 * promax:channels — رجّع القنوات الأربعة
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ موجود لحالة حصلت فعلاً: `promax:reset` مسح جدول القنوات، والسيدر
 * اللي بيرجّعهم كان بيعمل واحدة بس. النتيجة إن فورم العميل بيفتح
 * بقايمة قنوات فيها اختيار وحيد — والمستخدم مش لاقي «كاش فان» فبيسيب
 * الخانة، والعميل بيتحفظ من غير قناة ومن غير خصم.
 *
 * ⚠️ **مابيكتبش فوق اسم اتغيّر.** الأمر ده بيضيف الناقص بس.
 *
 * ⚠️ القناة مالهاش نسبة خصم — النسبة بتتحدد لكل عميل.
 *
 *   php artisan promax:channels
 *   php artisan promax:channels --reset   ← يرجّع النِسَب للافتراضي
 */
class SeedChannels extends Command
{
    protected $signature = 'promax:channels {--reset : رجّع الأسماء والألوان للافتراضي}';

    protected $description = 'التأكد إن القنوات الأربعة موجودة';

    public function handle(): int
    {
        $reset = (bool) $this->option('reset');
        $added = 0;
        $fixed = 0;

        foreach (Channel::DEFAULTS as $code => [$name, $nameEn, $color]) {
            $channel = Channel::firstOrNew(['code' => $code]);

            if (! $channel->exists) {
                $channel->fill([
                    'name' => $name,
                    'name_en' => $nameEn,
                    'color' => $color,
                    'active' => true,
                ])->save();

                $this->line("   + {$name}  ({$code})");
                $added++;

                continue;
            }

            $fill = [];

            if ($reset) {
                $fill = [
                    'name' => $name,
                    'name_en' => $nameEn,
                    'color' => $color,
                    'active' => true,
                ];
            } else {
                // الناقص بس — الاسم قرار تجاري
                if (blank($channel->name_en)) {
                    $fill['name_en'] = $nameEn;
                }
                if (blank($channel->color)) {
                    $fill['color'] = $color;
                }
            }

            if ($fill !== []) {
                $channel->fill($fill)->save();
                $this->line("   ~ {$channel->name}  ({$code})");
                $fixed++;
            }
        }

        $this->newLine();
        $this->info('  ✅ '.Channel::count().' قنوات في السيستم'
            .($added ? "  ·  {$added} جديدة" : '')
            .($fixed ? "  ·  {$fixed} اتظبطت" : ''));
        $this->newLine();

        return self::SUCCESS;
    }
}
