<?php

namespace App\Services;

/**
 * ═══════════════════════════════════════════════════════════════
 * قارئ شيتات بـ PHP الخام — xlsx و csv، من غير مكتبات خارجية
 * ═══════════════════════════════════════════════════════════════
 *
 * ليه مكتوب بالإيد؟ السيرفر مالوش نت للـ composer، و PhpSpreadsheet
 * مش متثبّتة. ملف xlsx في الآخر مجرد ZIP جواه XML، و ZipArchive و
 * SimpleXML الاتنين في لب PHP.
 *
 * بنقرا:
 *   xl/workbook.xml         أسماء الأوراق وترتيبها
 *   xl/sharedStrings.xml    جدول النصوص المشتركة
 *   xl/worksheets/sheetN.xml  الخلايا
 *
 * ⚠️ الفخاخ اللي اتعاملنا معاها:
 *   • النصوص مخزّنة في جدول مشترك بالفهرس (t="s") مش في الخلية
 *   • التواريخ أرقام من 1900-01-01، وفيه غلطة 29 فبراير 1900 التاريخية
 *   • الخلايا الفاضية **مش موجودة** في الـ XML خالص — مش فاضية، غايبة
 *   • مرجع الخلية حروف (A, B, ... Z, AA) لازم يتحول لرقم عمود
 *   • النص ممكن يبقى inline (t="inlineStr") بدل المشترك
 */
class Sheet
{
    /**
     * قراءة أول ورقة (أو ورقة بالاسم) كصفوف.
     *
     * @return array<int, array<int, string|null>> صفوف، كل صف مصفوفة قيم بالترتيب
     */
    public static function rows(string $path, ?string $sheet = null, int $limit = 0): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $ext === 'csv' || $ext === 'txt'
            ? self::csv($path, $limit)
            : self::xlsx($path, $sheet, $limit);
    }

    /** أسماء الأوراق في الملف */
    public static function sheets(string $path): array
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'xlsx') {
            return [];
        }

        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            return [];
        }

        $xml = $zip->getFromName('xl/workbook.xml');
        $zip->close();

        if ($xml === false) {
            return [];
        }

        $out = [];
        foreach (self::xml($xml)->sheets->sheet ?? [] as $s) {
            $out[] = (string) $s['name'];
        }

        return $out;
    }

    // ==================== CSV ====================

    private static function csv(string $path, int $limit): array
    {
        $rows = [];
        $h = fopen($path, 'r');

        if ($h === false) {
            return [];
        }

        // ⚠️ إكسل على ويندوز عربي بيكتب CSV بفاصلة منقوطة مش فاصلة.
        // من غير الاستنتاج ده الملف بيتقرا عمود واحد عملاق ومطابقة
        // العناوين بتفشل من غير ما اليوزر يفهم ليه.
        $probe = (string) fgets($h);
        rewind($h);

        $sep = ',';
        foreach ([';' => substr_count($probe, ';'), "\t" => substr_count($probe, "\t"),
                  ',' => substr_count($probe, ',')] as $char => $n) {
            if ($n > substr_count($probe, $sep)) {
                $sep = $char;
            }
        }

        // ⚠️ الوسايط الأربعة كلها: PHP 8.4 بيحذّر من حذف الأخير
        $first = true;

        while (($row = fgetcsv($h, 0, $sep, '"', '\\')) !== false) {
            if ($first && isset($row[0])) {
                $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]);
                $first = false;
            }

            $rows[] = array_map(fn ($v) => $v === '' ? null : trim((string) $v), $row);

            if ($limit > 0 && count($rows) >= $limit) {
                break;
            }
        }

        fclose($h);

        return $rows;
    }

    // ==================== XLSX ====================

    private static function xlsx(string $path, ?string $sheet, int $limit): array
    {
        $zip = new \ZipArchive;

        if ($zip->open($path) !== true) {
            return [];
        }

        try {
            $strings = self::sharedStrings($zip);
            $target = self::sheetPath($zip, $sheet);

            if ($target === null) {
                return [];
            }

            $xml = $zip->getFromName($target);
            if ($xml === false) {
                return [];
            }

            return self::parseSheet($xml, $strings, $limit);
        } finally {
            $zip->close();
        }
    }

    /** @return array<int, string> */
    private static function sharedStrings(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            return [];
        }

        $out = [];
        foreach (self::xml($xml)->si ?? [] as $si) {
            // النص ممكن يكون قطعة واحدة <t> أو مقسّم على <r><t>
            if (isset($si->t)) {
                $out[] = (string) $si->t;

                continue;
            }

            $buf = '';
            foreach ($si->r ?? [] as $r) {
                $buf .= (string) $r->t;
            }
            $out[] = $buf;
        }

        return $out;
    }

    /**
     * مسار ملف الورقة داخل الـ ZIP.
     *
     * ⚠️ ترتيب الورقة في workbook.xml **مش** بالضرورة رقم الملف.
     * الربط الصح عن طريق r:id في workbook.xml → الهدف في ملف العلاقات.
     * الافتراض إن الورقة التانية اسمها sheet2.xml بيفشل مع ملفات
     * اتعملت ببرامج تانية أو اتمسح منها ورقة.
     */
    private static function sheetPath(\ZipArchive $zip, ?string $name): ?string
    {
        $wb = $zip->getFromName('xl/workbook.xml');
        $relsRaw = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($wb !== false && $relsRaw !== false) {
            // r:id → المسار
            $rels = [];
            foreach (self::xml($relsRaw)->Relationship ?? [] as $r) {
                $target = (string) $r['Target'];
                $rels[(string) $r['Id']] = str_starts_with($target, '/')
                    ? ltrim($target, '/')
                    : 'xl/'.ltrim($target, './');
            }

            $ns = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

            foreach (self::xml($wb)->sheets->sheet ?? [] as $sheet) {
                if ($name !== null && (string) $sheet['name'] !== $name) {
                    continue;
                }

                $rid = (string) ($sheet->attributes($ns)['id'] ?? '');
                $path = $rels[$rid] ?? null;

                if ($path !== null && $zip->locateName($path) !== false) {
                    return $path;
                }

                // الاسم اتلاقى بس العلاقة بايظة — مانكملش دوران
                if ($name !== null) {
                    break;
                }
            }
        }

        // خطة بديلة: أول ورقة موجودة فعلاً في الـ ZIP
        for ($i = 1; $i <= 50; $i++) {
            if ($zip->locateName("xl/worksheets/sheet$i.xml") !== false) {
                return "xl/worksheets/sheet$i.xml";
            }
        }

        return null;
    }

    /** @return array<int, array<int, string|null>> */
    private static function parseSheet(string $xml, array $strings, int $limit): array
    {
        $rows = [];

        foreach (self::xml($xml)->sheetData->row ?? [] as $row) {
            $line = [];
            $maxCol = 0;

            foreach ($row->c ?? [] as $c) {
                $col = self::colIndex((string) $c['r']);
                $line[$col] = self::cellValue($c, $strings);
                $maxCol = max($maxCol, $col);
            }

            // ⚠️ الخلايا الفاضية غايبة من الـ XML أصلاً. لازم نملّي
            // الفجوات بـ null وإلا الأعمدة بتتزحزح والداتا تروح لعمود غلط.
            $full = [];
            for ($i = 0; $i <= $maxCol; $i++) {
                $full[] = $line[$i] ?? null;
            }

            $rows[] = $full;

            if ($limit > 0 && count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    private static function cellValue(\SimpleXMLElement $c, array $strings): ?string
    {
        $type = (string) $c['t'];

        // نص من الجدول المشترك
        if ($type === 's') {
            $i = (int) $c->v;

            return $strings[$i] ?? null;
        }

        // نص مكتوب جوه الخلية
        if ($type === 'inlineStr') {
            $buf = (string) ($c->is->t ?? '');
            foreach ($c->is->r ?? [] as $r) {
                $buf .= (string) $r->t;
            }

            return $buf !== '' ? $buf : null;
        }

        // صيغة: بناخد النتيجة المحفوظة مش الصيغة نفسها
        if (! isset($c->v)) {
            return null;
        }

        $v = trim((string) $c->v);

        return $v === '' ? null : $v;
    }

    /** A → 0، B → 1، AA → 26 */
    private static function colIndex(string $ref): int
    {
        $letters = preg_replace('/\d+/', '', $ref);
        $n = 0;

        for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
            $n = $n * 26 + (ord(strtoupper($letters[$i])) - 64);
        }

        return max($n - 1, 0);
    }

    // ==================== XML ====================

    /**
     * قراءة XML مع تحييد الـ namespace الافتراضي.
     *
     * ⚠️ ملفات xlsx بتحط namespace افتراضي على كل عنصر، فـ SimpleXML
     * بترجّع عناصر فاضية لو ناديت `$xml->sheetData` مباشرة. بنشيل
     * إعلان الـ namespace الافتراضي بس — الإعلانات اللي ليها بادئة
     * (زي xmlns:r) بتفضل، لأن فيه خصائص بتستخدمها وشيلها بيكسّر الملف.
     */
    private static function xml(string $raw): \SimpleXMLElement
    {
        $clean = preg_replace('/\sxmlns="[^"]*"/', '', $raw, 1);

        $prev = libxml_use_internal_errors(true);

        try {
            $el = simplexml_load_string($clean ?? $raw);

            return $el === false ? new \SimpleXMLElement('<empty/>') : $el;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    // ==================== مساعدات التحويل ====================

    /**
     * تاريخ من خلية إكسل — رقم تسلسلي أو نص.
     *
     * ⚠️ إكسل بيعدّ الأيام من 1900-01-01 = 1، وبيعتبر 1900 سنة كبيسة
     * بالغلط (خطأ تاريخي متعمّد للتوافق مع لوتس). عشان كده بنطرح 2
     * مش 1 لأي رقم بعد 59.
     *
     * ⚠️ والنص بيبقى d/m/Y في مصر — بس إكسل بيقلبه لـ m/d لو اليوم ≤ 12.
     * لو الخلية اتحولت لرقم تسلسلي، القلب حصل خلاص ومفيش رجعة منه من هنا.
     */
    public static function date(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        $s = trim((string) $value);

        // رقم تسلسلي
        if (is_numeric($s)) {
            $serial = (float) $s;

            if ($serial < 1) {
                return null;
            }

            $days = (int) $serial - ($serial > 59 ? 2 : 1);

            return (new \DateTimeImmutable('1900-01-01'))->modify("+$days days");
        }

        // ISO
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) {
            try {
                return new \DateTimeImmutable(substr($s, 0, 10));
            } catch (\Throwable) {
                return null;
            }
        }

        // d/m/Y أو d-m-Y — التفسير المصري
        if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{2,4})$#', $s, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];

            if ($y < 100) {
                $y += 2000;
            }

            return checkdate($mo, $d, $y)
                ? new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $mo, $d))
                : null;
        }

        return null;
    }

    /** رقم من خلية — بيشيل الفواصل ورموز العملة */
    public static function number(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $s = trim((string) $value);

        // ⚠️ \d في PCRE بيطابق أرقام لاتينية بس حتى مع /u. الشيتات
        // المصرية بتيجي بأرقام هندية، فلازم نحوّلها بإيدنا وإلا كل
        // كمية وسعر بيترفضوا كـ "مش رقم".
        $s = strtr($s, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٫' => '.',
        ]);

        $s = str_replace([',', '٬', ' ', "\u{00A0}"], '', $s);
        $s = preg_replace('/[^\d.\-]/u', '', $s) ?? '';

        // ⚠️ is_numeric مش مجرد "مش فاضي": "1.2.3" و "1-2" كانوا بيعدّوا
        // ويرجّعوا رقم مقطوع في صمت.
        return is_numeric($s) ? (float) $s : null;
    }

    /** نص نضيف — بيوحّد المسافات ويرجّع null للفاضي */
    public static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $s = preg_replace('/\s+/u', ' ', trim((string) $value));

        return $s === '' ? null : $s;
    }

    /** نعم/لا بأي صيغة */
    public static function bool(mixed $value): bool
    {
        $s = strtolower(trim((string) $value));

        return in_array($s, ['1', 'true', 'yes', 'y', 'نعم', 'ايوه', 'أيوه', 'صح'], true);
    }
}
