<?php

namespace App\Services\Importers;

use App\Services\Sheet;

/**
 * ═══════════════════════════════════════════════════════════════
 * أساس كل المستوردات — نفس الدورة لكل نوع داتا
 * ═══════════════════════════════════════════════════════════════
 *
 * الدورة: قراءة → مطابقة أعمدة → تحقق → معاينة → تنفيذ.
 *
 * ⚠️ التحقق بيتم على **كل** الصفوف قبل ما يتكتب أي حاجة. الاستيراد
 * النصّي (نص الصفوف نجح والباقي لأ) أسوأ من الفشل الكامل: بيسيب داتا
 * ناقصة محدش عارف فين، والإعادة بتكرّر اللي نجح.
 *
 * ⚠️ مطابقة الأعمدة **بالاسم مش بالترتيب**. الشيتات اللي بتيجي من
 * الواقع بتبقى أعمدتها مترتبة بأي شكل وفيها أعمدة زيادة، والاعتماد
 * على الترتيب بيحط الأسعار في خانة الكميات في صمت.
 */
abstract class Importer
{
    /** مفتاح النوع — بيتستخدم في الراوت وملفات اللغة */
    abstract public function kind(): string;

    /**
     * الأعمدة: مفتاح داخلي => [الأسماء اللي نقبلها في الشيت]
     * أول اسم في القايمة هو اللي بيتكتب في القالب الفاضي.
     *
     * @return array<string, array<int, string>>
     */
    abstract public function columns(): array;

    /** الأعمدة اللي من غيرها الشيت مايتقبلش */
    abstract public function required(): array;

    /**
     * تحقق صف واحد. بيرجّع رسائل الأخطاء — فاضية يعني الصف سليم.
     *
     * @param  array<string, string|null>  $row
     * @return array<int, string>
     */
    abstract public function validateRow(array $row, int $line): array;

    /**
     * تنفيذ الصفوف السليمة. بيترجع ملخص لعرضه.
     *
     * @param  array<int, array<string, string|null>>  $rows
     * @return array<string, int|string>
     */
    abstract public function apply(array $rows): array;

    // ==================== المشترك ====================

    /**
     * قراءة الملف وتحويله لصفوف بمفاتيح داخلية.
     *
     * @return array{
     *   rows: array<int, array<string, string|null>>,
     *   headers: array<int, string>,
     *   mapped: array<string, string>,
     *   missing: array<int, string>,
     *   errors: array<int, string>
     * }
     */
    public function read(string $path, int $limit = 0, ?string $sheet = null): array
    {
        // ⚠️ الحد بيتزوّد شوية: سطر العناوين ممكن مايكونش أول سطر
        $raw = Sheet::rows($path, $sheet, $limit > 0 ? $limit + 12 : 0);

        if (count($raw) < 2) {
            return [
                'rows' => [], 'headers' => [], 'mapped' => [], 'sheets' => Sheet::sheets($path),
                'missing' => $this->required(),
                'errors' => [__('import.empty_sheet')],
            ];
        }

        // ⚠️ سطر العناوين مش دايماً أول سطر. الشيتات اللي بتيجي من الواقع
        // بيبقى فوقها عنوان وشعار وسطور فاضية — شيت باتشات المصنع عناوينه
        // في السطر الرابع. بندوّر على أول سطر بيطابق عمودين معروفين على
        // الأقل بدل ما نفترض إنه الأول ونرفض الملف كله.
        [$headerAt, $headers, $map] = $this->findHeader($raw);

        if ($headerAt === null) {
            return [
                'rows' => [], 'headers' => [], 'mapped' => [], 'sheets' => Sheet::sheets($path),
                'missing' => $this->required(),
                'errors' => [__('import.no_header_row')],
            ];
        }

        // نشيل كل اللي فوق العناوين ومعاها
        $raw = array_slice($raw, $headerAt + 1);

        $missing = array_values(array_diff($this->required(), array_keys($map)));

        $rows = [];
        foreach ($raw as $line) {
            $row = [];
            foreach ($map as $key => $i) {
                $row[$key] = Sheet::text($line[$i] ?? null);
            }

            // صف فاضي تماماً = نهاية الداتا، مش خطأ
            if (count(array_filter($row, fn ($v) => $v !== null && $v !== '')) === 0) {
                continue;
            }

            $rows[] = $row;
        }

        return [
            'rows' => $rows,
            'headers' => $headers,
            'mapped' => array_map(fn ($i) => $headers[$i] ?? '', $map),
            'missing' => $missing,
            'sheets' => Sheet::sheets($path),
            'header_row' => $headerAt + 1,
            'errors' => [],
        ];
    }

    /**
     * تحديد سطر العناوين ومطابقة الأعمدة.
     *
     * @return array{0: ?int, 1: array<int, string>, 2: array<string, int>}
     */
    private function findHeader(array $raw): array
    {
        $best = [null, [], []];
        $bestScore = 0;

        // أول 12 سطر بس — لو العناوين أبعد من كده يبقى الملف مش شيت داتا
        foreach (array_slice($raw, 0, 12, true) as $i => $line) {
            $headers = array_map(fn ($h) => $this->normalise((string) $h), $line);

            $map = [];
            foreach ($this->columns() as $key => $names) {
                foreach ($names as $name) {
                    $at = array_search($this->normalise($name), $headers, true);
                    if ($at !== false) {
                        $map[$key] = $at;
                        break;
                    }
                }
            }

            // بنفضّل السطر اللي طابق أكتر عمود، وبنقف أول ما نلاقي
            // سطر مطابق لكل الأعمدة الإجبارية.
            $score = count($map);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [$i, $headers, $map];

                if (! array_diff($this->required(), array_keys($map))) {
                    break;
                }
            }
        }

        // عمود واحد متطابق مش كفاية — ده صدفة مش سطر عناوين
        return $bestScore >= 2 || ($bestScore >= 1 && count($this->required()) === 1)
            ? $best
            : [null, [], []];
    }

    /**
     * تحقق كل الصفوف.
     *
     * @return array{ok: array<int, array>, errors: array<int, string>}
     */
    public function validateAll(array $rows): array
    {
        $ok = [];
        $errors = [];

        foreach ($rows as $i => $row) {
            // +2: سطر العناوين + الترقيم بيبدأ من واحد
            $line = $i + 2;
            $problems = $this->validateRow($row, $line);

            if ($problems) {
                foreach ($problems as $p) {
                    $errors[] = __('import.line_error', ['line' => $line, 'error' => $p]);
                }

                continue;
            }

            $ok[] = $row;
        }

        return ['ok' => $ok, 'errors' => $errors];
    }

    /** توحيد اسم العمود عشان المطابقة تنجح رغم فروق الكتابة */
    protected function normalise(string $s): string
    {
        // ⚠️ الشيتات الحقيقية بتيجي بمسافات زيادة، حروف كبيرة وصغيرة،
        // شرطة سفلية بدل مسافة، وألف بأشكالها. من غير التوحيد ده
        // "Product Name" و "product_name" و "اسم المنتج " بيبقوا تلاتة مختلفين.
        $s = trim(mb_strtolower($s, 'UTF-8'));
        $s = str_replace(['_', '-', '.', '/'], ' ', $s);
        $s = str_replace(['أ', 'إ', 'آ'], 'ا', $s);
        $s = str_replace('ة', 'ه', $s);
        $s = str_replace('ى', 'ي', $s);
        $s = preg_replace('/[\x{064B}-\x{0652}]/u', '', $s) ?? $s;  // تشكيل
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

        return trim($s);
    }

    /**
     * تحويل قيمة الشيت لمفتاح داخلي.
     *
     * ⚠️ اليوزر بيملا القالب العربي فبيكتب «بروماكس بار» مش `promax_bar`.
     * رفض ده معناه إن القالب اللي إحنا مدّيناه له بيرفض نفسه. بنقبل
     * المفتاح، واللابل العربي، واللابل الإنجليزي، وكلهم بيوصلوا لنفس
     * المفتاح.
     *
     * @param  array<string, mixed>  $labels  مفتاح => لابل (أو [لابل, كلاس])
     */
    protected function toKey(?string $value, array $labels, array $extra = []): ?string
    {
        if ($value === null) {
            return null;
        }

        $want = $this->normalise($value);

        // المفتاح نفسه
        foreach (array_keys($labels) as $key) {
            if ($this->normalise((string) $key) === $want) {
                return (string) $key;
            }
        }

        // اللابل زي ما هو مخزّن في الموديل
        foreach ($labels as $key => $label) {
            $text = is_array($label) ? ($label[0] ?? '') : $label;
            if ($text !== '' && $this->normalise((string) $text) === $want) {
                return (string) $key;
            }
        }

        // مرادفات إضافية: لابل => مفتاح
        foreach ($extra as $label => $key) {
            if ($this->normalise((string) $label) === $want) {
                return (string) $key;
            }
        }

        return null;
    }

    /** القالب الفاضي — الصف الأول بس */
    public function template(): string
    {
        $headers = [];
        foreach ($this->columns() as $key => $names) {
            $headers[] = $names[0];
        }

        // ⚠️ BOM ضروري: إكسل بيقرا CSV بترميز الويندوز افتراضياً
        // فالعربي بيطلع رموز من غيره.
        return "\u{FEFF}".implode(',', $headers)."\n";
    }
}
