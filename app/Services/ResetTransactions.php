<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * مسح الترانزاكشنز — الماستر داتا بتفضل (قرار المالك 2026-08-04)
 * ═══════════════════════════════════════════════════════════════
 *
 * بيمسح **الحركة** بس: بيع، مرتجع، تحويل، عهدة، أذون استلام، باتشات،
 * أوامر توريد، تجهيز، جرد، ريفيل، قيود، إشعارات، زيارات، تراكينج،
 * حركة الموردين، المستحقات، وطلبات العملاء الجدد.
 *
 * وبيسيب: اليوزرات، العملاء، السلاسل، المنتجات، الأسعار وقوايمها،
 * العقود وبنودها، المخازن والأرفف، المناطق، القنوات، الفروع،
 * العربيات، خطط السير، العملاء المحتملين، وتوكينات الأبلكيشن
 * (المناديب بيفضلوا داخلين).
 *
 * وبعد المسح: `stocks` بتتصفّر (الصفوف بتفضل)، ورصيد كل عميل
 * ومحجوزه بيرجعوا صفر — رصيد أول المدة بييجي بعدها بالاستيراد.
 *
 * ⚠️ مالوش رجعة — التأكيد مسؤولية اللي بينده (كتابة WIPE في الشاشة
 * أو --force في الأمر).
 */
class ResetTransactions
{
    /**
     * ترتيب المسح: التابع قبل المتبوع — نفس قاعدة promax:reset.
     * ⚠️ أي جدول حركة جديد بمفتاح أجنبي لازم يدخل هنا في مكانه.
     */
    private const ORDER = [
        // الحركات اليومية
        'track_events', 'app_notifications', 'shelf_refills',
        'replenishment_items', 'replenishment_requests', 'merch_visits',
        'invoice_items', 'invoices',
        'gift_handouts',
        // ⚠️ الأوامر قبل التجهيز — `purchase_orders.pick_order_id`
        'purchase_order_items', 'purchase_orders',
        'custody_items', 'custodies', 'visits', 'transactions',
        'client_requests',

        // الجرد — بيشير للباتشات
        'stock_count_items', 'stock_counts',

        // حركة المخزن
        'pick_order_items', 'pick_orders',
        'stock_transfer_items', 'stock_transfers',
        'batch_locations', 'batches',

        // حركة الموردين — المورد نفسه (الماستر) بيفضل
        'supplier_transactions', 'supplier_payments', 'supplier_invoices',
        'supplier_order_items', 'supplier_orders',

        'goods_receipts',

        // المستحقات بتتولد تاني من العقود بـ promax:dues
        'contract_dues',

        // سجل ملفات الاستيراد القديمة
        'imports',
    ];

    /** @return array<string,int> اللي اتمسح فعلاً: جدول => عدد صفوف */
    public static function run(): array
    {
        $wiped = [];

        DB::transaction(function () use (&$wiped) {
            Schema::disableForeignKeyConstraints();

            try {
                foreach (self::ORDER as $table) {
                    if (! Schema::hasTable($table)) {
                        continue;   // موديول لسه ماتركبش على البيئة دي
                    }

                    $n = DB::table($table)->count();

                    if ($n > 0) {
                        DB::table($table)->delete();
                        $wiped[$table] = $n;
                    }
                }
            } finally {
                Schema::enableForeignKeyConstraints();
            }

            // ⚠️ الصفوف بتفضل والأرقام بتتصفّر — الصنف لازم يبان بصفر
            // في كل مخزن، مش يختفي من الشاشات
            DB::table('stocks')->update(['qty' => 0, 'hold_qty' => 0, 'good_qty' => 0, 'counted_at' => null]);

            // ⚠️ **كل المجاميع الدفترية على العميل، مش الرصيد بس.**
            // `recalculate()` بيخزّن مشتريات/تحصيلات/مرتجعات/خصومات/
            // تسويات كأعمدة على `clients` — والنظرة العامة بتقرا منهم
            // مباشرة. تصفير الرصيد لوحده ساب «مشتريات 210 وتحصيل
            // 5,000» طالعين في الداش بورد بعد المسح (اتشاف فعلاً
            // 2026-08-04). مفيش قيود = كل المجاميع صفر — نفس نتيجة
            // recalculate() على دفتر فاضي بس دفعة واحدة.
            DB::table('clients')->update([
                'balance' => 0, 'withheld' => 0,
                'purchases' => 0, 'collections' => 0, 'returns' => 0,
                'rebates' => 0, 'settlements' => 0,
                'first_activity_at' => null, 'last_activity_at' => null,
                'last_payment_at' => null,
            ]);

            // ونفس القاعدة للموردين — رصيدهم تجميعة من دفترهم اللي اتمسح
            if (Schema::hasTable('suppliers')) {
                DB::table('suppliers')->update(['balance' => 0]);
            }
        });

        return $wiped;
    }
}
