<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * كل دالة بتتنادى من الشاشة موجودة فعلاً
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **الملف ده اتكتب بعد ما 4 دوال اختفت من فورم العميل** في تعديل
 * على بلوك السكربت: `syncSubChannel` و`openZoneBox` و`closeZoneBox`
 * و`saveZone`.
 *
 * النتيجة كانت **صامتة تماماً**: الصفحة بتفتح عادي، والزراير موجودة،
 * والضغط عليها مابيعملش حاجة. الخطأ بيتكتب في كونسول المتصفح اللي
 * محدش بيفتحه — وخانة «قسم الكي أكاونت» فضلت ظاهرة على كل القنوات،
 * وزرار إضافة المنطقة بقى ديكور.
 *
 * ⚠️ الفحص نصّي عن قصد. رندر الصفحة بيحتاج داتا وبيخبّي الحقول اللي
 * جوه شرط — والحقل المخبّي هو بالظبط اللي بيتنسى.
 */
class ScreenScriptsTest extends TestCase
{
    /** دوال معرّفة في الليّاوت مش في الشاشة نفسها */
    private const GLOBALS = [
        'openDlg', 'closeDlg', 'confirm', 'alert', 'print',
    ];

    public static function screens(): array
    {
        $out = [];

        foreach (glob(resource_path('views/**/*.blade.php')) as $path) {
            $out[str_replace(resource_path('views/'), '', $path)] = [$path];
        }

        return $out;
    }

    /**
     * @dataProvider screens
     */
    public function test_every_handler_on_the_screen_is_defined(string $path): void
    {
        $html = (string) file_get_contents($path);

        // الدوال المعرّفة جوه وسوم السكربت
        preg_match_all('/<script\b[^>]*>(.*?)<\/script>/s', $html, $blocks);
        $js = implode("\n", $blocks[1]);

        preg_match_all('/(?:^|\s)(?:async\s+)?function\s+([A-Za-z_$][\w$]*)\s*\(/m', $js, $defs);
        preg_match_all('/(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=\s*(?:async\s*)?(?:function|\()/', $js, $assigned);

        $defined = array_merge($defs[1], $assigned[1], self::GLOBALS);

        // الدوال اللي الشاشة بتناديها من خصائص الـHTML
        $called = [];

        foreach (['onclick', 'onchange', 'oninput', 'onsubmit', 'onblur', 'onfocus'] as $attr) {
            preg_match_all('/'.$attr.'="([A-Za-z_$][\w$]*)\(/', $html, $m);
            $called = array_merge($called, $m[1]);
        }

        // ⚠️ والنداءات جوه `DOMContentLoaded` كمان — دي بتتنفّذ مرة
        // واحدة عند الفتح، وغيابها بيسيب الشاشة في حالة أولية غلط
        // (خانة مفروض تكون مخفية بتفضل ظاهرة) من غير أي عرض للخطأ.
        preg_match_all('/^\s{4}([A-Za-z_$][\w$]*)\(\);\s*$/m', $js, $boot);
        $called = array_merge($called, $boot[1]);

        $missing = array_values(array_unique(array_diff($called, $defined)));
        sort($missing);

        $this->assertSame([], $missing,
            basename($path).' — دوال بتتنادى ومش معرّفة: الزرار بيبقى ديكور والخطأ في الكونسول بس');
    }
}
