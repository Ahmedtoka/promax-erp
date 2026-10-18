<?php

namespace App\Services;

use App\Models\HandoverModule;
use App\Models\HandoverScreen;

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
