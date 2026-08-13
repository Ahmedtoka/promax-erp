<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * نقاء اللغة — الحارس اللي بيمنع رجوع الخلط
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ التيست ده **مايحتاجش داتابيز** عن قصد — بيقرا الملفات مباشرة.
 * كده بيشتغل بسرعة وبيقدر يجري قبل أي مايجريشن.
 *
 * القواعد اللي بيحرسها اتكسرت فعلاً قبل كده:
 *   - مفتاح موجود في `ar` وناقص في `en` → النص بيطلع بالمفتاح الخام
 *   - أبوستروف في `lang/en` → **كل صفحة في السيستم 500**
 *   - نص عربي جوه `lang/en` → واجهة مخلّطة
 */
class LanguageTest extends TestCase
{
    private function base(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return array<string, mixed> */
    private function load(string $locale): array
    {
        $out = [];

        foreach (glob($this->base()."/lang/{$locale}/*.php") as $file) {
            $out[basename($file, '.php')] = require $file;
        }

        return $out;
    }

    /** يفرد المصفوفة المتداخلة لمفاتيح بنقط */
    private function flatten(array $data, string $prefix = ''): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            $full = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $out += $this->flatten($value, $full);

                continue;
            }

            $out[$full] = $value;
        }

        return $out;
    }

    public function test_both_languages_have_exactly_the_same_keys(): void
    {
        $ar = $this->flatten($this->load('ar'));
        $en = $this->flatten($this->load('en'));

        $onlyAr = array_diff(array_keys($ar), array_keys($en));
        $onlyEn = array_diff(array_keys($en), array_keys($ar));

        $this->assertSame([], array_values($onlyAr),
            'مفاتيح في العربي وناقصة في الإنجليزي: '.implode(', ', $onlyAr));
        $this->assertSame([], array_values($onlyEn),
            'مفاتيح في الإنجليزي وناقصة في العربي: '.implode(', ', $onlyEn));
    }

    public function test_no_language_file_is_empty(): void
    {
        foreach (['ar', 'en'] as $locale) {
            foreach ($this->load($locale) as $name => $data) {
                $this->assertNotEmpty($data, "ملف {$locale}/{$name}.php فاضي");
            }
        }
    }

    public function test_english_file_has_no_arabic_text(): void
    {
        $bad = [];

        foreach ($this->flatten($this->load('en')) as $key => $value) {
            if (is_string($value) && preg_match('/[\x{0600}-\x{06FF}]/u', $value)) {
                // استثناء واحد مقصود: اسم اللغة العربية نفسه
                if ($key === 'common.arabic' || str_ends_with($key, '.lang_ar')) {
                    continue;
                }

                $bad[] = $key.' => '.$value;
            }
        }

        $this->assertSame([], $bad,
            'نص عربي في ملفات الإنجليزي: '.implode(' | ', $bad));
    }

    public function test_arabic_file_has_no_english_only_sentences(): void
    {
        $bad = [];

        foreach ($this->flatten($this->load('ar')) as $key => $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }

            // ⚠️ الأسماء والاختصارات مسموحة (PROMAX, PDF, GS1, EGP).
            // اللي ممنوع هو **جملة** إنجليزي كاملة من غير حرف عربي.
            $hasArabic = preg_match('/[\x{0600}-\x{06FF}]/u', $value) === 1;
            $words = preg_match_all('/[A-Za-z]{3,}/', $value);

            if (! $hasArabic && $words >= 3) {
                $bad[] = $key.' => '.$value;
            }
        }

        $this->assertSame([], $bad,
            'جملة إنجليزية في ملفات العربي: '.implode(' | ', $bad));
    }

    public function test_placeholders_match_between_languages(): void
    {
        // ⚠️ `:count` في العربي و `:total` في الإنجليزي معناه إن نص
        // من اللغتين هيطلع بالبديل الخام ظاهر للمستخدم.
        $ar = $this->flatten($this->load('ar'));
        $en = $this->flatten($this->load('en'));

        $bad = [];

        foreach ($ar as $key => $value) {
            if (! is_string($value) || ! isset($en[$key]) || ! is_string($en[$key])) {
                continue;
            }

            preg_match_all('/:([a-z_]+)/', $value, $a);
            preg_match_all('/:([a-z_]+)/', $en[$key], $e);

            sort($a[1]);
            sort($e[1]);

            if ($a[1] !== $e[1]) {
                $bad[] = $key.' (ar: '.implode(',', $a[1]).' | en: '.implode(',', $e[1]).')';
            }
        }

        $this->assertSame([], $bad, 'بدائل مختلفة بين اللغتين: '.implode(' | ', $bad));
    }

    public function test_english_files_parse_without_apostrophe_errors(): void
    {
        // ⚠️ الإنجليزي هو الافتراضي، وخطأ التجميع فيه **مايتلقفش** —
        // كل صفحة في السيستم بترجع 500. حصل فعلاً مع "Client's".
        //
        // ⚠️ **الكاشف بقى بيفهم الهروب** (إصلاح ١٣/٨/٢٠٢٦). النمط
        // القديم `'[^']*'[A-Za-z]` كان بيعتبر `\'` نهاية السترنج،
        // فأربع سطور **مهرّبة صح** (`client\'s` في `api.php` و
        // `journey.php` و`settle.php` و`supplier.php`) كانت بتفشّل
        // التيست وهي PHP سليمة تماماً — كاشف بيصرخ على الصح بيتشال
        // بعد أسبوع، وساعتها الأبوستروف الحقيقي بيعدّي.
        // `(?:[^'\\]|\\.)*` بتاكل `\'` كوحدة واحدة، فاللي بيتمسك هو
        // الأبوستروف **غير المهرّب** بس — وهو اللي بيكسّر البارسر.
        foreach (glob($this->base().'/lang/en/*.php') as $file) {
            $source = file_get_contents($file);

            $this->assertSame(
                0,
                preg_match("/=>\s*'(?:[^'\\\\]|\\\\.)*'[A-Za-z]/", $source),
                'أبوستروف غير مهرّب في '.basename($file),
            );
        }
    }

    public function test_flutter_app_has_both_languages_in_sync(): void
    {
        $path = dirname($this->base()).'/Promax-app/lib/l10n.dart';

        if (! is_file($path)) {
            $this->markTestSkipped('مشروع الأبلكيشن مش موجود جنب الـ ERP');
        }

        $source = file_get_contents($path);

        $arStart = strpos($source, '_ar = {');
        $enStart = strpos($source, '_en = {');

        $this->assertNotFalse($arStart);
        $this->assertNotFalse($enStart);

        preg_match_all("/^\s*'(\w+)':/m", substr($source, $arStart, $enStart - $arStart), $a);
        preg_match_all("/^\s*'(\w+)':/m", substr($source, $enStart), $e);

        $onlyAr = array_diff($a[1], $e[1]);
        $onlyEn = array_diff($e[1], $a[1]);

        $this->assertSame([], array_values($onlyAr),
            'مفاتيح أبلكيشن في العربي بس: '.implode(', ', $onlyAr));
        $this->assertSame([], array_values($onlyEn),
            'مفاتيح أبلكيشن في الإنجليزي بس: '.implode(', ', $onlyEn));
    }

    public function test_flutter_app_has_no_hardcoded_arabic(): void
    {
        $dir = dirname($this->base()).'/Promax-app/lib';

        if (! is_dir($dir)) {
            $this->markTestSkipped('مشروع الأبلكيشن مش موجود جنب الـ ERP');
        }

        $bad = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'dart' || $file->getFilename() === 'l10n.dart') {
                continue;
            }

            foreach (file($file->getPathname()) as $no => $line) {
                $trimmed = ltrim($line);

                // التعليقات مسموح فيها عربي — الشرح بالعربي مقصود
                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')) {
                    continue;
                }

                $code = explode('//', $line)[0];

                if (preg_match("/'[^']*[\x{0600}-\x{06FF}][^']*'/u", $code)) {
                    $bad[] = $file->getFilename().':'.($no + 1);
                }
            }
        }

        $this->assertSame([], $bad,
            'نص عربي متبتّت في الأبلكيشن: '.implode(', ', array_slice($bad, 0, 15)));
    }
}
