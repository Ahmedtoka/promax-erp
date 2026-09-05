<?php

namespace App\Services;

/**
 * ═══════════════════════════════════════════════════════════════
 * كاتب xlsx بـ PHP الخام — من غير مكتبات خارجية (٢٨/٨/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * التوأم الكاتب لـ`App\Services\Sheet` (القارئ). نفس السبب: السيرفر
 * مالوش نت للـcomposer و PhpSpreadsheet مش متثبّتة — وملف xlsx في
 * الآخر ZIP جواه XML، و`ZipArchive` في لب PHP.
 *
 * بيكتب الحد الأدنى اللي إكسيل بيقبله:
 *   [Content_Types].xml · _rels/.rels · xl/workbook.xml
 *   xl/_rels/workbook.xml.rels · xl/styles.xml · xl/worksheets/sheet1.xml
 * (النصوص inline — مفيش `sharedStrings` عشان نقلل الاحتمالات.)
 *
 * ⚠️ الفخاخ اللي اتعاملنا معاها وإحنا بنكتبه:
 *   • **الترتيب في `styles.xml` إجباري**: numFmts→fonts→fills→borders
 *     →cellStyleXfs→cellXfs. أي قلب = «الملف تالف» من غير سبب واضح.
 *   • **أول اتنين fill محجوزين** (none وgray125) — لو حطيت لونك مكانهم
 *     إكسيل بيتجاهله.
 *   • **النص لازم يتهرب** (`&`, `<`, `>`) وإلا الـXML يقع — وأسماء
 *     المنتجات عندنا فيها `&` فعلاً.
 *   • **`t="inlineStr"` مع `<is><t>`** — من غير `t` إكسيل بيحاول يقرا
 *     النص كرقم ويرمي صفر.
 *   • **الأرقام تتكتب خام بالإنجليزي** (`0.00` مش `١٬٢٣٤`) — التنسيق
 *     شغل `numFmt`، والفورمات المصري بيخلي الخلية نص مايتحسبش.
 *   • **`rightToLeft` على `sheetView`** — الشيت العربي لازم يفتح من
 *     اليمين وإلا الأعمدة بتبان مقلوبة على المستخدم.
 *   • **`ZipArchive::CREATE | OVERWRITE`** على ملف مؤقت — من غير
 *     OVERWRITE تصدير تاني بنفس الاسم بيتلزق على الأول.
 */
class SheetWriter
{
    /** الصفوف: كل صف مصفوفة خلايا. الخلية قيمة، أو مصفوفة بمفاتيح */
    private array $rows = [];

    /** عرض الأعمدة بالحروف: [الفهرس => العرض] */
    private array $widths = [];

    /** الدمج: ["A1:F1", ...] */
    private array $merges = [];

    private string $title;

    /**
     * الشيتات اللي اتقفلت قبل الحالية — دعم الملف متعدد الشيتات (٥/٩).
     * كل عنصر: ['title', 'rows', 'widths', 'merges'].
     * الـAPI القديم زي ما هو: ملف بشيت واحد مش محتاج يعرف عن دي حاجة.
     */
    private array $done = [];

    public function __construct(string $title = 'Sheet1')
    {
        $this->title = self::sheetName($title);
    }

    /**
     * قفل الشيت الحالي وابدأ شيت جديد — كل row/width/merge بعدها
     * بتكتب في الشيت الجديد.
     *
     * ⚠️ أسماء الشيتات لازم تكون فريدة — الفحص بيحصل وقت البناء
     * (بيتزوّد رقم للاسم المكرر) عشان النداءات هنا تفضل بسيطة.
     */
    public function addSheet(string $title): static
    {
        $this->done[] = [
            'title' => $this->title,
            'rows' => $this->rows,
            'widths' => $this->widths,
            'merges' => $this->merges,
        ];

        $this->title = self::sheetName($title);
        $this->rows = [];
        $this->widths = [];
        $this->merges = [];

        return $this;
    }

    /** ⚠️ اسم الورقة ممنوع فيه : \ / ? * [ ] وأقصاه 31 حرف */
    private static function sheetName(string $title): string
    {
        $t = mb_substr(str_replace([':', '\\', '/', '?', '*', '[', ']'], ' ', $title), 0, 31);

        return trim($t) === '' ? 'Sheet' : $t;
    }

    /**
     * إضافة صف.
     *
     * كل خلية إما قيمة عادية (نص/رقم/null) أو مصفوفة:
     *   ['v' => القيمة, 'style' => مفتاح ستايل, 'num' => true لو رقم]
     *
     * مفاتيح الستايلات المتاحة: title · header · label · value ·
     * money · money_bold · center · muted · total
     */
    public function row(array $cells): static
    {
        $this->rows[] = $cells;

        return $this;
    }

    /** صف فاضي — مسافة بصرية */
    public function blank(): static
    {
        $this->rows[] = [];

        return $this;
    }

    /** عرض عمود بالحروف (تقريباً عدد المحارف) */
    public function width(int $col, float $w): static
    {
        $this->widths[$col] = $w;

        return $this;
    }

    /** دمج خلايا الصف الحالي — بيتنادى بعد `row()` */
    public function merge(int $fromCol, int $toCol, ?int $row = null): static
    {
        $r = $row ?? count($this->rows);
        $this->merges[] = self::colName($fromCol).$r.':'.self::colName($toCol).$r;

        return $this;
    }

    /** ينزّل الملف مباشرة للمتصفح */
    public function download(string $filename)
    {
        $path = $this->build();

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /** بيبني الملف في temp وبيرجّع مساره */
    public function build(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pmx').'.xlsx';

        $zip = new \ZipArchive;
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('cannot create xlsx');
        }

        $sheets = $this->allSheets();

        $zip->addFromString('[Content_Types].xml', $this->contentTypes(count($sheets)));
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('xl/workbook.xml', $this->workbook($sheets));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels(count($sheets)));
        $zip->addFromString('xl/styles.xml', $this->styles());

        foreach ($sheets as $i => $s) {
            $zip->addFromString(
                'xl/worksheets/sheet'.($i + 1).'.xml',
                $this->sheetXml($s['rows'], $s['widths'], $s['merges']),
            );
        }

        $zip->close();

        return $path;
    }

    /**
     * كل الشيتات بالترتيب (المقفولة + الحالية) بأسماء **فريدة** —
     * إكسيل بيرفض الملف كله لو اسمين اتكرروا، فالمكرر بياخد لاحقة رقم.
     */
    private function allSheets(): array
    {
        $sheets = array_merge($this->done, [[
            'title' => $this->title,
            'rows' => $this->rows,
            'widths' => $this->widths,
            'merges' => $this->merges,
        ]]);

        $used = [];
        foreach ($sheets as &$s) {
            $name = $s['title'];
            $n = 2;
            while (isset($used[mb_strtolower($name)])) {
                $suffix = ' '.$n++;
                $name = mb_substr($s['title'], 0, 31 - mb_strlen($suffix)).$suffix;
            }
            $used[mb_strtolower($name)] = true;
            $s['title'] = $name;
        }
        unset($s);

        return $sheets;
    }

    // ═══════════════ أجزاء الملف ═══════════════

    private function contentTypes(int $sheetCount): string
    {
        $overrides = '';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .$overrides
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function workbook(array $sheets): string
    {
        $entries = '';
        foreach ($sheets as $i => $s) {
            $n = $i + 1;
            $entries .= '<sheet name="'.self::esc($s['title']).'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$entries.'</sheets>'
            .'</workbook>';
    }

    private function workbookRels(int $sheetCount): string
    {
        $rels = '';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $rels .= '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$rels
            .'</Relationships>';
    }

    /**
     * الستايلات — الترتيب هنا هو ترتيب `cellXfs` وهو اللي مفاتيح
     * `STYLES` بتشاور عليه بالفهرس. **ممنوع تزحزح صف من غير ما تظبط
     * الخريطة**، وإلا الألوان بتلبس خلايا تانية.
     */
    private const STYLES = [
        'default' => 0,
        'title' => 1,      // عنوان كبير أزرق
        'header' => 2,     // رأس جدول أبيض على أزرق
        'label' => 3,      // اسم حقل رمادي
        'value' => 4,      // قيمة عادية بولد
        'money' => 5,      // رقم بفاصلة عشرية
        'money_bold' => 6,
        'center' => 7,
        'muted' => 8,      // نص صغير رمادي
        'total' => 9,      // صف الإجمالي — خلفية فاتحة وبولد
    ];

    private function styles(): string
    {
        // ⚠️ الترتيب الإجباري: numFmts → fonts → fills → borders
        // → cellStyleXfs → cellXfs
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'

            .'<numFmts count="1">'
            .'<numFmt numFmtId="164" formatCode="#,##0.00"/>'
            .'</numFmts>'

            .'<fonts count="6">'
            .'<font><sz val="11"/><name val="Calibri"/></font>'                                   // 0 عادي
            .'<font><b/><sz val="18"/><color rgb="FF12399B"/><name val="Calibri"/></font>'        // 1 عنوان
            .'<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'        // 2 رأس جدول
            .'<font><sz val="10"/><color rgb="FF6B7280"/><name val="Calibri"/></font>'            // 3 رمادي
            .'<font><b/><sz val="11"/><name val="Calibri"/></font>'                               // 4 بولد
            .'<font><b/><sz val="11"/><color rgb="FF12399B"/><name val="Calibri"/></font>'        // 5 بولد أزرق
            .'</fonts>'

            // ⚠️ أول اتنين محجوزين لإكسيل — ألواننا بتبدأ من الفهرس 2
            .'<fills count="4">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF12399B"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFEEF2FB"/><bgColor indexed="64"/></patternFill></fill>'
            .'</fills>'

            .'<borders count="2">'
            .'<border><left/><right/><top/><bottom/><diagonal/></border>'
            .'<border>'
            .'<left style="thin"><color rgb="FFD1D5DB"/></left>'
            .'<right style="thin"><color rgb="FFD1D5DB"/></right>'
            .'<top style="thin"><color rgb="FFD1D5DB"/></top>'
            .'<bottom style="thin"><color rgb="FFD1D5DB"/></bottom>'
            .'<diagonal/></border>'
            .'</borders>'

            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'

            .'<cellXfs count="10">'
            // 0 default
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            // 1 title
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            // 2 header
            .'<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            // 3 label
            .'<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            // 4 value
            .'<xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            // 5 money
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            // 6 money_bold
            .'<xf numFmtId="164" fontId="5" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            // 7 center
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            // 8 muted
            .'<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            // 9 total
            .'<xf numFmtId="164" fontId="4" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            .'</cellXfs>'

            // ⚠️ **`cellStyles` بعد `cellXfs`** — من غيره الملف بيفتح
            // بس بتحذير «مفيش ستايل افتراضي»، والقارئات الصارمة بتشتكي
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'

            .'</styleSheet>';
    }

    private function sheetXml(array $rows, array $widths, array $merges): string
    {
        $cols = '';
        if ($widths !== []) {
            $cols = '<cols>';
            foreach ($widths as $i => $w) {
                $n = $i + 1;
                $cols .= '<col min="'.$n.'" max="'.$n.'" width="'.$w.'" customWidth="1"/>';
            }
            $cols .= '</cols>';
        }

        $data = '';
        foreach ($rows as $r => $cells) {
            $rowNum = $r + 1;
            $body = '';

            foreach (array_values($cells) as $c => $cell) {
                $ref = self::colName($c).$rowNum;
                [$value, $style, $isNum] = self::normalize($cell);

                if ($value === null || $value === '') {
                    // خلية فاضية بستايل (عشان البوردر يكمّل الجدول)
                    $body .= $style === 0 ? '' : '<c r="'.$ref.'" s="'.$style.'"/>';

                    continue;
                }

                $body .= $isNum
                    ? '<c r="'.$ref.'" s="'.$style.'"><v>'.$value.'</v></c>'
                    : '<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'
                        .self::esc((string) $value).'</t></is></c>';
            }

            $data .= '<row r="'.$rowNum.'">'.$body.'</row>';
        }

        $mergeXml = '';
        if ($merges !== []) {
            $mergeXml = '<mergeCells count="'.count($merges).'">';
            foreach ($merges as $m) {
                $mergeXml .= '<mergeCell ref="'.$m.'"/>';
            }
            $mergeXml .= '</mergeCells>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            // ⚠️ الشيت العربي لازم يفتح من اليمين
            .'<sheetViews><sheetView rightToLeft="1" workbookViewId="0"/></sheetViews>'
            .$cols
            .'<sheetData>'.$data.'</sheetData>'
            .$mergeXml
            .'</worksheet>';
    }

    // ═══════════════ مساعدات ═══════════════

    /** @return array{0: mixed, 1: int, 2: bool} */
    private static function normalize(mixed $cell): array
    {
        if (is_array($cell)) {
            $v = $cell['v'] ?? null;
            $style = self::STYLES[$cell['style'] ?? 'default'] ?? 0;
            $isNum = ($cell['num'] ?? false) === true && is_numeric($v);

            // ⚠️ الرقم بيتكتب خام بالإنجليزي — التنسيق شغل numFmt
            return [$isNum ? (0 + $v) : $v, $style, $isNum];
        }

        return [$cell, 0, false];
    }

    /** 0 → A، 25 → Z، 26 → AA */
    private static function colName(int $i): string
    {
        $s = '';
        $n = $i + 1;

        while ($n > 0) {
            $r = ($n - 1) % 26;
            $s = chr(65 + $r).$s;
            $n = intdiv($n - 1, 26);
        }

        return $s;
    }

    /**
     * تهريب النص للـXML.
     *
     * 🔴 **محارف التحكّم لازم تتشال قبل التهريب** (باج ٢٨/٨): أسماء
     * المنتجات المستوردة من شيتات إكسيل بتحمل محارف مخفية
     * (\x0B \x1F \x00…) — `htmlspecialchars` **مابيلمسهاش**، وXML 1.0
     * بيحرّمها، فإكسيل بيرمي **الخلية دي بالذات** في صمت وهو بيفتح.
     * النتيجة كانت: عمود «الصنف» فاضي والباقي سليم — أصعب شكل عطل
     * لأن الملف بيفتح عادي من غير أي رسالة.
     *
     * ⚠️ و`ENT_SUBSTITUTE` مش رفاهية: من غيره أي بايت UTF-8 مكسور
     * بيخلي `htmlspecialchars` يرجّع **نص فاضي بالكامل**.
     */
    private static function esc(string $s): string
    {
        // \t \n \r مسموحين — أي حاجة تحت 0x20 غيرهم بتتشال
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);

        // الريجيكس بيرجّع null لو النص UTF-8 مكسور — نكمّل بالخام
        // وسيب ENT_SUBSTITUTE يتصرف
        return htmlspecialchars($clean ?? $s, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }
}
