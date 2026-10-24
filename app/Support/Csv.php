<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ═══════════════════════════════════════════════════════════════
 * تصدير CSV بيفتح في إكسيل عربي سليم
 * ═══════════════════════════════════════════════════════════════
 *
 * نفس مسار مركز التقارير (`ReportController::csv`): BOM في الأول عشان
 * إكسيل يقرا العربي، وهيدر، وصفوف، وصف إجماليات اختياري.
 *
 * ⚠️ **مفيش باكدج إكسيل في المشروع عن قصد** (قرار المالك — الـPDF من
 * طباعة المتصفح والإكسيل من CSV). الأرقام بتتكتب `1250.00` من غير
 * فاصلة الآلاف عشان إكسيل يعاملها كأرقام ويجمعها، مش كنص.
 */
class Csv
{
    /**
     * @param  list<string>  $columns
     * @param  iterable<list<mixed>>  $rows
     * @param  list<mixed>|null  $totals
     */
    public static function download(string $name, array $columns, iterable $rows, ?array $totals = null, array $meta = []): StreamedResponse
    {
        return response()->streamDownload(function () use ($columns, $rows, $totals, $meta) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            // سطور التعريف (٢١/٩ — «كل ما أسحب تقرير مش بلاقي تاريخ»): الملف
            // لوحده لازم يقول هو إيه، لأنهي فترة، واتسحب إمتى.
            foreach ($meta as $m) {
                fputcsv($out, self::safeRow($m));
            }
            if ($meta !== []) {
                fputcsv($out, []);
            }

            fputcsv($out, self::safeRow($columns));

            foreach ($rows as $row) {
                fputcsv($out, self::safeRow($row));
            }

            if ($totals !== null) {
                fputcsv($out, self::safeRow($totals));
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * سطور التعريف الموحّدة لأي ملف: العنوان · الفترة · وقت السحب · مين سحبه.
     * `$from`/`$to` نصوص `Y-m-d` أو null (= كل الفترات).
     */
    public static function meta(string $title, ?string $from = null, ?string $to = null): array
    {
        $period = ($from === null && $to === null)
            ? __('common.exp_all_time')
            : ($from ?? '…').' → '.($to ?? now()->toDateString());

        return [
            [$title],
            [__('common.exp_period'), $period],
            [__('common.exp_generated'), now()->format('Y-m-d h:i A'), auth()->user()?->displayName() ?? ''],
        ];
    }

    /**
     * ⚠️ **حارس حقن المعادلات (CSV injection).** خلية نصية أولها `=` أو
     * `+` أو `@` أو تاب أو `\r` — أو `-` وبعدها حاجة — إكسيل بيشغّلها
     * كمعادلة: اسم عميل زي `=HYPERLINK(...)` بيبقى لينك أو أمر بيشتغل
     * عند اللي فتح الملف. بنحط `'` قبلها فتتعرض نص زي ما هي.
     * الأرقام (حتى السالبة `-12.50`) مابتتلمسش عشان إكسيل يجمعها.
     */
    public static function safeCell(mixed $v): mixed
    {
        if (! is_string($v) || $v === '' || is_numeric($v)) {
            return $v;
        }

        $first = $v[0];

        if (in_array($first, ['=', '+', '@', "\t", "\r"], true) || ($first === '-' && strlen($v) > 1)) {
            return "'".$v;
        }

        return $v;
    }

    /** @param  iterable<mixed>  $row */
    private static function safeRow(iterable $row): array
    {
        $out = [];
        foreach ($row as $k => $v) {
            $out[$k] = self::safeCell($v);
        }

        return $out;
    }

    /** رقم مالي للخلية — منزلتين، من غير فاصلة آلاف */
    public static function money(float|int|string|null $n): string
    {
        return number_format((float) $n, 2, '.', '');
    }
}
