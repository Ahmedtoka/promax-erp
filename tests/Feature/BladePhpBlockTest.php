<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * بلوكات `@php` فيها PHP سليم — مش بليد
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **الملف ده اتكتب بعد ما 13 شاشة وقعت مرة واحدة.**
 *
 *     ParseError: syntax error, unexpected token "**"
 *     resources/views/erp/contract.blade.php:9
 *
 * السبب: تعليق بليد `{{-- ... --}}` مكتوب **جوه** بلوك `@php`.
 * البليد بيحوّل `@php ... @endphp` لـPHP خام زي ما هو — مابيلمسش
 * اللي جواه. فالتعليق بيوصل لمترجم PHP كـ`{{--` والنجوم اللي في
 * النص العربي (`**مدير الفرع**`) بتبقى عامل قوّة، والصفحة بتقع
 * قبل ما ترسم أي حاجة.
 *
 * الغلط ده **صامت وقت الكتابة**: الشكل صح، والمحرر مش بيشتكي،
 * والصفحة بتقع أول ما حد يفتحها بس. و13 شاشة وقعوا مع بعض لأن
 * التعديل كان واحد اتكرر عليهم كلهم.
 *
 * ⚠️ الفحص نصّي عن قصد. رندر الصفحة بيحتاج داتا وصلاحيات، والشاشة
 * اللي مالهاش داتا في التيست مش هتترسم أصلاً — يعني الغلط ده كان
 * هيعدّي من تيستات الرندر.
 */
class BladePhpBlockTest extends TestCase
{
    public static function views(): array
    {
        $out = [];

        foreach (glob(resource_path('views/**/*.blade.php')) as $path) {
            $out[str_replace(resource_path('views/'), '', $path)] = [$path];
        }

        return $out;
    }

    /**
     * @dataProvider views
     */
    public function test_php_blocks_contain_only_php(string $path): void
    {
        $src = (string) file_get_contents($path);
        $file = basename($path);

        // ⚠️ `@php(...)` شكل سطر واحد — مش بلوك، وملهوش `@endphp`.
        preg_match_all('/@php\b(?!\s*\()(.*?)@endphp/s', $src, $blocks, PREG_OFFSET_CAPTURE);

        foreach ($blocks[1] as [$body, $offset]) {
            $line = substr_count(substr($src, 0, $offset), "\n") + 1;

            $this->assertStringNotContainsString('{{--', $body,
                "{$file}:{$line} — تعليق بليد جوه @php. البليد مابيلمسش اللي جوه "
                .'البلوك، فالتعليق بيوصل لمترجم PHP وبيوقع الصفحة. استخدم // بدلها.');

            // ⚠️ و`{{ $x }}` كمان: جوه PHP دي بتبقى مصفوفة جوه مصفوفة.
            $this->assertDoesNotMatchRegularExpression('/\{\{(?!--)/', $body,
                "{$file}:{$line} — {{ }} جوه @php. جوه بلوك PHP اكتب المتغير مباشرةً.");
        }
    }

    /**
     * الأقواس متوازنة جوه كل بلوك.
     *
     * ⚠️ البلوك اللي أقواسه مش متوازنة بيبلع اللي بعده: البليد
     * بيقفل عند `@endphp` والـPHP بيفضل مفتوح، فالخطأ بيطلع في سطر
     * بعيد خالص عن مكان الغلط الحقيقي.
     */
    public function test_php_blocks_have_balanced_brackets(): void
    {
        $broken = [];

        foreach (glob(resource_path('views/**/*.blade.php')) as $path) {
            $src = (string) file_get_contents($path);

            preg_match_all('/@php\b(?!\s*\()(.*?)@endphp/s', $src, $blocks, PREG_OFFSET_CAPTURE);

            foreach ($blocks[1] as [$body, $offset]) {
                if (! $this->balanced($body)) {
                    $line = substr_count(substr($src, 0, $offset), "\n") + 1;
                    $broken[] = basename($path).':'.$line;
                }
            }
        }

        $this->assertSame([], $broken,
            'بلوكات @php أقواسها مش متوازنة: '.implode(', ', $broken));
    }

    /** عدّاد أقواس واعي بالنصوص والتعليقات */
    private function balanced(string $code): bool
    {
        $pairs = ['}' => '{', ')' => '(', ']' => '['];
        $stack = [];
        $len = strlen($code);

        for ($i = 0; $i < $len; $i++) {
            $c = $code[$i];

            if ($c === '"' || $c === "'") {
                $quote = $c;
                $i++;

                while ($i < $len) {
                    if ($code[$i] === '\\') {
                        $i += 2;

                        continue;
                    }

                    if ($code[$i] === $quote) {
                        break;
                    }

                    $i++;
                }

                continue;
            }

            if (substr($code, $i, 2) === '//' || $c === '#') {
                $j = strpos($code, "\n", $i);
                $i = $j === false ? $len : $j;

                continue;
            }

            if (substr($code, $i, 2) === '/*') {
                $j = strpos($code, '*/', $i + 2);
                $i = $j === false ? $len : $j + 1;

                continue;
            }

            if (in_array($c, ['{', '(', '['], true)) {
                $stack[] = $c;
            } elseif (isset($pairs[$c])) {
                if ($stack === [] || array_pop($stack) !== $pairs[$c]) {
                    return false;
                }
            }
        }

        return $stack === [];
    }
}
