<?php

namespace App\Services;

use App\Models\HandoverModule;
use App\Models\HandoverScreen;
use App\Support\Access;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * أداة تسليم النظام — نسبة الاستلام (spec §3.8)
 * ═══════════════════════════════════════════════════════════════
 *
 * نسبة الموديول = الشاشات المعلّمة ÷ كل شاشاته (نص الشاشات = 50%)،
 * والنسبة الكلية بنفس الطريقة على كل الشاشات.
 *
 * ⚠️ `pct` = `intdiv(marked*100, total)` — بتقرّب لتحت عن قصد: 99.5%
 * مش 100%. الـ100% معناها كل شاشة اتعلّمت فعلاً. وموديول من غير شاشات
 * = 0 مش قسمة على صفر.
 * ⚠️ الشاشة «اتعدلت بعد الاستلام» لسه معلّمة وبتتحسب — العلامة تنبيه
 * بس (spec §4.1).
 */
class Handover
{
    /**
     * مجموعات منيو مش موديولز تسليم — `seedFromNav` بيعدّيها.
     * 📖 الدليل (٢١/١٠) مرجع التسليم نفسه، ومستلم النظام مالوش وصول له أصلاً.
     */
    public const NOT_HANDOVER = ['nav.group_docs'];

    /** @return array{marked:int,total:int,pct:int} */
    public static function moduleProgress(HandoverModule $m): array
    {
        $total = HandoverScreen::query()->where('module_id', $m->id)->count();
        $marked = HandoverScreen::query()->where('module_id', $m->id)->marked()->count();

        return self::pack($marked, $total);
    }

    /** @return array{marked:int,total:int,pct:int} */
    public static function overall(): array
    {
        return self::pack(
            HandoverScreen::query()->marked()->count(),
            HandoverScreen::query()->count(),
        );
    }

    /**
     * يولّد الموديولز من مجموعات `Access::NAV` والشاشات من بنودها.
     *
     * ⚠️ **إضافة بس** — مابيمسحش ولا بيغيّر اسم: المالك بيعدّل الأسامي
     * والحالات بعد أول توليد، وإعادة التشغيل (بعد ما شاشة جديدة اتضافت
     * للسايدبار) بتزوّد الناقص وتسيب تعديلاته زي ما هي.
     *
     * @return array{modules_added:int,screens_added:int}
     */
    public static function seedFromNav(bool $dryRun = false): array
    {
        $modulesAdded = 0;
        $screensAdded = 0;

        $work = function () use ($dryRun, &$modulesAdded, &$screensAdded) {
            $groupSort = 0;

            foreach (Access::NAV as $groupKey => $links) {
                $groupSort += 10;

                // 📖 الدليل مش شاشة بتتسلّم — ده مرجع التسليم نفسه
                if (in_array($groupKey, self::NOT_HANDOVER, true)) {
                    continue;
                }
                $module = HandoverModule::query()->where('key', $groupKey)->first();

                if ($module === null) {
                    $modulesAdded++;

                    if (! $dryRun) {
                        $module = HandoverModule::create([
                            'key' => $groupKey,
                            'nav_group_key' => $groupKey,
                            'name_ar' => __($groupKey, [], 'ar'),
                            'name_en' => __($groupKey, [], 'en'),
                            'sort' => $groupSort,
                        ]);
                    }
                }

                $existing = $module
                    ? HandoverScreen::query()->where('module_id', $module->id)->pluck('route')->all()
                    : [];
                $itemSort = 0;

                foreach ($links as $link) {
                    [$route, , $label] = $link;
                    $itemSort += 10;

                    if (in_array($route, $existing, true)) {
                        continue;
                    }

                    $existing[] = $route;   // نفس الراوت مرتين في نفس المجموعة = شاشة واحدة
                    $screensAdded++;

                    if (! $dryRun) {
                        HandoverScreen::create([
                            'module_id' => $module->id,
                            'route' => $route,
                            'title_ar' => __($label, [], 'ar'),
                            'title_en' => __($label, [], 'en'),
                            'sort' => $itemSort,
                        ]);
                    }
                }
            }
        };

        $dryRun ? $work() : DB::transaction($work);

        return ['modules_added' => $modulesAdded, 'screens_added' => $screensAdded];
    }

    /**
     * «الشاشة اتغيّرت» — `touched_at = now()` على الشاشات اللي راوتها في القايمة.
     * التعليم نفسه فاضل، والشاشة بتاخد «اتعدلت بعد الاستلام».
     *
     * @param  list<string>  $routes
     */
    public static function touch(array $routes): int
    {
        if ($routes === []) {
            return 0;
        }

        return HandoverScreen::query()->whereIn('route', $routes)->update(['touched_at' => now()]);
    }

    /** @return array{marked:int,total:int,pct:int} */
    public static function pack(int $marked, int $total): array
    {
        return [
            'marked' => $marked,
            'total' => $total,
            'pct' => $total === 0 ? 0 : intdiv($marked * 100, $total),
        ];
    }
}
