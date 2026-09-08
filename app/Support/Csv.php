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
    public static function download(string $name, array $columns, iterable $rows, ?array $totals = null): StreamedResponse
    {
        return response()->streamDownload(function () use ($columns, $rows, $totals) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $columns);

            foreach ($rows as $row) {
                fputcsv($out, $row);
            }

            if ($totals !== null) {
                fputcsv($out, $totals);
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** رقم مالي للخلية — منزلتين، من غير فاصلة آلاف */
    public static function money(float|int|string|null $n): string
    {
        return number_format((float) $n, 2, '.', '');
    }
}
