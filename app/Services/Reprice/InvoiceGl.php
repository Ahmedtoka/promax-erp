<?php

namespace App\Services\Reprice;

use App\Models\Invoice;
use App\Models\Transaction;

/**
 * ═══════════════════════════════════════════════════════════════
 * إعادة ترحيل قيود الفاتورة للشجرة — منقولة من OpsController (٢٤/١٠)
 * ═══════════════════════════════════════════════════════════════
 *
 * نفس جسم `repostInvoiceGl` القديم بالحرف — اتنقلت هنا عشان أداة
 * إعادة التسعير الجماعية (`PriceChange`) تنادي نفس المسار اللي
 * أدوات الفاتورة الإدارية بتناديه، مش نسخة تانية منه.
 *
 * الشجرة: القيود اتعدّلت بـwhereKey()->update() (أو مسح/إنشاء مباشر)
 * فالـobserver ماشافهاش (٩/٩) — أدوات الفاتورة الإدارية كلها بتنادي
 * دي بعد ما تخلّص تعديلها جوه نفس الـDB::transaction. محمي: مشكلة
 * في الدفتر لازم تتسجل وميوقفش/يرجّع تعديل الأدمن نفسه.
 */
class InvoiceGl
{
    public static function repost(Invoice $invoice, string $event): bool
    {
        try {
            $ok = true;
            $ledger = app(\App\Services\Gl\Ledger::class);
            foreach (Transaction::where('source_type', Invoice::class)->where('source_id', $invoice->id)->get() as $glTx) {
                // ⚠️ قيد كان موجود ورجع null = القاعدة اتوقفت أو مفتاحها
                // اتشال، والقيد اتمسح من غير بديل. مافيش استثناء بيترمي
                // في الحالة دي فالصمت كان بيبلع اختفاء قيد من الدفتر.
                $had = $ledger->autoEntryFor($glTx) !== null;
                if ($ledger->repost($glTx) === null && $had) {
                    $ok = false;
                }
            }

            return $ok;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('gl.repost failed', ['invoice' => $invoice->id, 'event' => $event, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
