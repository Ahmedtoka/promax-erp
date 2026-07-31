<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;

/**
 * بيقارن مفاتيح الترجمة بين العربي والإنجليزي، وبيدوّر على
 * نصوص عربية متسيبة في الفيوهات من غير ما تتحوّل لمفاتيح.
 *
 * الاستخدام: php artisan promax:i18n-check
 * القاعدة دي مفروضة في سكيل promax-i18n.
 */
class I18nCheck extends Command
{
    protected $signature = 'promax:i18n-check {--views : افحص الفيوهات كمان}';

    protected $description = 'يتأكد إن مفاتيح الترجمة متطابقة بين العربي والإنجليزي';

    public function handle(): int
    {
        $problems = $this->compareKeys();

        if ($this->option('views')) {
            $problems += $this->scanViews();
        }

        if ($problems === 0) {
            $this->info('✅ الترجمة سليمة — كل المفاتيح موجودة في اللغتين');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error("❌ فيه $problems مشكلة — راجع سكيل promax-i18n");

        return self::FAILURE;
    }

    private function compareKeys(): int
    {
        $arPath = lang_path('ar');
        $enPath = lang_path('en');

        $arFiles = collect(File::files($arPath))->map->getFilenameWithoutExtension();
        $enFiles = collect(File::files($enPath))->map->getFilenameWithoutExtension();

        $problems = 0;

        foreach ($arFiles->diff($enFiles) as $missing) {
            $this->warn("ملف ناقص في en: $missing.php");
            $problems++;
        }
        foreach ($enFiles->diff($arFiles) as $missing) {
            $this->warn("ملف ناقص في ar: $missing.php");
            $problems++;
        }

        foreach ($arFiles->intersect($enFiles) as $group) {
            $ar = Arr::dot(require "$arPath/$group.php");
            $en = Arr::dot(require "$enPath/$group.php");

            foreach (array_diff_key($ar, $en) as $key => $value) {
                $this->warn("مفتاح ناقص في en: $group.$key");
                $problems++;
            }
            foreach (array_diff_key($en, $ar) as $key => $value) {
                $this->warn("مفتاح ناقص في ar: $group.$key");
                $problems++;
            }

            // الـ placeholders لازم تكون هي هي في اللغتين
            foreach (array_intersect_key($ar, $en) as $key => $value) {
                if ($this->placeholders($value) !== $this->placeholders($en[$key])) {
                    $this->warn("الـ placeholders مختلفة في: $group.$key");
                    $problems++;
                }
            }
        }

        return $problems;
    }

    /** @return array<int, string> */
    private function placeholders(string $text): array
    {
        preg_match_all('/:(\w+)/', $text, $m);
        $found = $m[1];
        sort($found);

        return $found;
    }

    /** بيدوّر على نص عربي متسيب جوه الفيوهات */
    private function scanViews(): int
    {
        $problems = 0;
        $views = File::allFiles(resource_path('views'));

        foreach ($views as $view) {
            // الفيوهات القديمة مش جزء من السيستم الحالي
            if (str_contains($view->getPathname(), 'dashboard')
                || str_contains($view->getPathname(), 'appcycle')) {
                continue;
            }

            foreach (file($view->getPathname()) as $i => $line) {
                // بنتخطى الكومنتات وملفات اللغة
                if (preg_match('/^\s*(\/\/|\*|\{\{--|#)/', $line)) {
                    continue;
                }
                if (preg_match('/[\x{0600}-\x{06FF}]/u', $line)) {
                    $this->line(sprintf(
                        '  <fg=yellow>%s:%d</> %s',
                        str_replace(resource_path('views').DIRECTORY_SEPARATOR, '', $view->getPathname()),
                        $i + 1,
                        trim(mb_substr($line, 0, 70)),
                    ));
                    $problems++;
                }
            }
        }

        if ($problems) {
            $this->newLine();
            $this->warn("فيه $problems سطر فيه نص عربي مباشر في الفيوهات");
        }

        return $problems;
    }
}
