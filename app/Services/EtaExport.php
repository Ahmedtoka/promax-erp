<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Setting;
use Illuminate\Support\Collection;

/**
 * ═══════════════════════════════════════════════════════════════
 * تصدير الفواتير لمنظومة الفاتورة الإلكترونية
 * ═══════════════════════════════════════════════════════════════
 *
 * بيحوّل فواتير السيستم لصيغة مستندات المنظومة (JSON) عشان ترفعها
 * من بورتال المصلحة.
 *
 * ⚠️ **الملف ده مش موقّع.** المنظومة بتطلب توقيع إلكتروني (CAdES)
 * بتوكن الشركة، والتوقيع ده بيتعمل ببرنامج المصلحة على جهاز فيه
 * التوكن — مش من سيرفر. اللي إحنا بنعمله هو تجهيز البيانات صح
 * وكاملة، وخطوة التوقيع والرفع بتتم بره. أي كلام غير كده بيوعد
 * اليوزر بحاجة مش موجودة وبيخلّيه يكتشف ده يوم التقديم.
 *
 * ⚠️ الأرقام هنا **بتتقرا من الفاتورة زي ما هي**، مابتتحسبش من جديد.
 * إعادة الحساب وقت التصدير معناها إن الملف المرفوع ممكن يخالف
 * الفاتورة المطبوعة اللي العميل واخدها لو نسبة اتغيرت في الوسط.
 */
class EtaExport
{
    /**
     * أسباب رفض الفاتورة قبل ما نبعتها.
     *
     * بنفحص محلياً بدل ما نرفع ونستنى رفض من المنظومة برسالة مبهمة.
     *
     * @return array<int, string> فاضية = الفاتورة سليمة
     */
    public static function problems(Invoice $invoice): array
    {
        $out = [];

        // ⚠️ الحقول دي كلها إجبارية عند المنظومة وكلها اختيارية في
        // شاشة الإعدادات. لو واحد ناقص، الملف بيطلع سليم الشكل
        // والمصلحة بترفض **الحزمة كلها** — وإحنا نكون علّمنا الفواتير
        // «اتصدّرت» فاختفت من قايمة الجاهز ومحدش عارف فين المشكلة.
        $required = [
            'company_tax_id' => 'tax.missing_tax_id',
            'company_activity_code' => 'tax.missing_activity_code',
            'company_governorate' => 'tax.missing_address',
            'company_city' => 'tax.missing_address',
            'company_street' => 'tax.missing_address',
            'company_building' => 'tax.missing_address',
        ];

        foreach ($required as $key => $message) {
            if (Setting::read($key) === null) {
                $text = __($message);
                if (! in_array($text, $out, true)) {
                    $out[] = $text;
                }
            }
        }

        // العميل شخص اعتباري لازم له رقم ضريبي
        if (($invoice->client->eta_type ?? 'B') === 'B' && ! $invoice->client->tax_id) {
            $out[] = __('tax.client_no_tax_id');
        }

        // ⚠️ الكود اللي هنبعته لازم يبقى GS1 حقيقي أو كود مصلحة.
        // بعت كودنا الداخلي متعلّم إنه GTIN بيخلّي السطر مرفوض.
        $invoice->loadMissing('items.product');

        foreach ($invoice->items as $item) {
            $p = $item->product;

            if ($p !== null && ! $p->barcode && ! $p->getAttribute('eta_code')) {
                $out[] = __('tax.product_no_code', ['product' => $p->displayName()]);
                break;
            }
        }

        return $out;
    }

    /**
     * مستند واحد بصيغة المنظومة.
     *
     * @return array<string, mixed>
     */
    public static function document(Invoice $invoice): array
    {
        $invoice->loadMissing(['client', 'items.product']);

        $lines = [];

        foreach ($invoice->items as $item) {
            $qty = (int) $item->qty;
            $unit = round((float) $item->list_price, 5);
            $sales = round($unit * $qty, 5);          // قبل الخصم
            $net = round((float) $item->total, 5);    // بعد الخصم
            $discount = round($sales - $net, 5);
            $tax = round((float) $item->tax, 5);

            $lines[] = [
                'description' => $item->product->displayName(),
                // ⚠️ النوع بيتبع الكود الفعلي. `GS1` مع كود داخلي
                // معناه إحنا بنعلن رقم مش GTIN على إنه GTIN.
                'itemType' => $item->product->barcode ? 'GS1' : 'EGS',
                'itemCode' => $item->product->barcode
                    ?: ($item->product->getAttribute('eta_code') ?: $item->product->code),
                'unitType' => 'EA',
                'quantity' => $qty,
                'internalCode' => $item->product->code,
                'salesTotal' => $sales,
                'total' => round($net + $tax, 5),
                'valueDifference' => 0,
                'totalTaxableFees' => 0,
                'netTotal' => $net,
                'itemsDiscount' => 0,
                'unitValue' => [
                    'currencySold' => 'EGP',
                    'amountEGP' => $unit,
                ],
                'discount' => [
                    'rate' => $sales > 0 ? round($discount / $sales * 100, 5) : 0,
                    'amount' => $discount,
                ],
                'taxableItems' => $tax > 0 ? [[
                    'taxType' => 'T1',                     // ضريبة القيمة المضافة
                    'amount' => $tax,
                    'subType' => 'V009',                   // النسبة العامة
                    'rate' => round((float) $item->tax_rate * 100, 5),
                ]] : [],
            ];
        }

        $totalSales = round(array_sum(array_column($lines, 'salesTotal')), 5);
        $totalDiscount = round(array_sum(array_map(fn ($l) => $l['discount']['amount'], $lines)), 5);

        return [
            'issuer' => self::issuer(),
            'receiver' => self::receiver($invoice),
            'documentType' => 'I',                         // فاتورة
            'documentTypeVersion' => '1.0',
            // ⚠️ التوقيت لازم UTC بصيغة Z — المنظومة بترفض التوقيت المحلي
            'dateTimeIssued' => $invoice->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'taxpayerActivityCode' => (string) Setting::read('company_activity_code', ''),
            'internalID' => $invoice->number,
            'totalSalesAmount' => $totalSales,
            'totalDiscountAmount' => $totalDiscount,
            'netAmount' => round((float) $invoice->total, 5),
            'taxTotals' => (float) $invoice->tax_total > 0 ? [[
                'taxType' => 'T1',
                'amount' => round((float) $invoice->tax_total, 5),
            ]] : [],
            'totalAmount' => round($invoice->payable(), 5),
            'extraDiscountAmount' => 0,
            'totalItemsDiscountAmount' => 0,
            'invoiceLines' => $lines,
        ];
    }

    /**
     * ملف كامل بمجموعة فواتير.
     *
     * @param  Collection<int, Invoice>  $invoices
     * @return array<string, mixed>
     */
    public static function batch(Collection $invoices): array
    {
        return [
            'documents' => $invoices->map(fn (Invoice $i) => self::document($i))->values()->all(),
        ];
    }

    /** بيانات البائع من إعدادات الشركة */
    private static function issuer(): array
    {
        return [
            'address' => [
                'branchID' => (string) Setting::read('company_branch_code', '0'),
                'country' => 'EG',
                'governate' => (string) Setting::read('company_governorate', ''),
                'regionCity' => (string) Setting::read('company_city', ''),
                'street' => (string) Setting::read('company_street', ''),
                'buildingNumber' => (string) Setting::read('company_building', ''),
            ],
            'type' => 'B',
            'id' => (string) Setting::read('company_tax_id', ''),
            'name' => (string) Setting::read('company_name', 'PROMAX'),
        ];
    }

    /** بيانات المشتري من كارت العميل */
    private static function receiver(Invoice $invoice): array
    {
        $client = $invoice->client;
        $type = $client->eta_type ?: 'B';

        $receiver = [
            'type' => $type,
            'name' => $client->displayName(),
        ];

        // ⚠️ الشخص الطبيعي ممكن يبقى من غير رقم ضريبي، والاعتباري لأ.
        // بعت مفتاح `id` فاضي بيرفض المستند، فبنشيله خالص.
        if ($client->tax_id) {
            $receiver['id'] = $client->tax_id;
        }

        // ⚠️ **محافظة العميل هي الأصل، ومحافظة الشركة آخر حل.**
        // قبل كده كل فاتورة إلكترونية كانت بتطلع بمحافظة الشركة على
        // كل العملاء — يعني عميل في أسيوط بيتسجّل في القاهرة عند
        // المصلحة. المنظومة بترفض الحقل الفاضي، فالرجوع لمحافظة
        // الشركة فاضل موجود للعميل اللي لسه ماتحدّدتش محافظته.
        // ⚠️ الاسم بيتبعت **بالعربي دايماً** — المنظومة عربية، ولو
        // اتبعت بلغة الواجهة، تصدير اتعمل من شاشة إنجليزية بيوصل
        // بأسماء محافظات إنجليزية والمستند بيترفض.
        $governate = $client->governorate
            ? __('geo.gov.'.$client->governorate, [], 'ar')
            : (string) Setting::read('company_governorate', '');

        $receiver['address'] = [
            'country' => 'EG',
            'governate' => $governate,
            'regionCity' => (string) Setting::read('company_city', ''),
            'street' => $client->address ?: $client->displayName(),
            'buildingNumber' => '—',
        ];

        return $receiver;
    }

    /** اسم الملف: promax-eta-2026-07-01_2026-07-31.json */
    public static function filename(string $from, string $to): string
    {
        return 'promax-eta-'.$from.'_'.$to.'.json';
    }
}
