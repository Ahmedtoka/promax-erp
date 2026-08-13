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

    /**
     * ⚠️ **ممنوع `resource_path()` هنا** (إصلاح ١٢/٨): الـdata provider
     * بيشتغل **قبل** ما الأبلكيشن يقوم — الهيلبر بينده على كونتينر
     * فاضي ويرمي «Call to undefined method Container::resourcePath()».
     */
    public static function screens(): array
    {
        $out = [];
        $dir = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'views';

        foreach (glob($dir.DIRECTORY_SEPARATOR.'**'.DIRECTORY_SEPARATOR.'*.blade.php') as $path) {
            $out[str_replace($dir.DIRECTORY_SEPARATOR, '', $path)] = [$path];
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

        // ⚠️ **`window.X = ...` تعريف كامل** (إصلاح الكاشف ١٣/٨/٢٠٢٦).
        // الشاشات اللي بتتحمّل جوه بلوك `IIFE` أو `DOMContentLoaded`
        // بتعرّض الهاندلرز بتاعتها على `window` عن قصد — دي الطريقة
        // الوحيدة اللي خاصية `onclick=` في الـHTML بتشوف بيها الدالة.
        // الكاشف القديم ماكانش بيعرف الشكل ده، فكان بيقول إن
        // `qcTogglePin` و`rbSubmit` و`covToggleEmpty` وتسع دوال في
        // `geo_planner` «مش معرّفة» وهي معرّفة سطر واحد فوق النداء.
        // التلات أشكال المستعملة فعلاً:
        //   window.X = function (…) {…}   ·   window.X = (…) => {…}
        //   window.X = localFn;           ← تعريض دالة محلية باسم عام
        preg_match_all(
            '/\b(?:window|globalThis)\.([A-Za-z_$][\w$]*)\s*=\s*(?:async\s*)?(?:function\b|\(|[A-Za-z_$][\w$]*)/',
            $js, $exposed);

        // وإسناد عام من غير `const/let/var` — `X = function (…) {…}`
        preg_match_all(
            '/(?:^|[;{}\s])([A-Za-z_$][\w$]*)\s*=\s*(?:async\s*)?(?:function\b|\([^()]*\)\s*=>)/m',
            $js, $bare);

        $defined = array_merge(
            $defs[1], $assigned[1], $exposed[1], $bare[1], self::GLOBALS,
        );

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
