<?php

namespace Database\Seeders;

use App\Models\Gl\GlAccount;
use App\Models\Gl\GlPostingRule;
use Illuminate\Database\Seeder;

/**
 * الشجرة الأساسية وقواعد الترحيل — آمن يتعاد: بيعمل updateOrCreate بالكود
 * وبالمفتاح، ومابيلمسش الحسابات الحرة اللي المالك ضافها.
 */
class GlSeeder extends Seeder
{
    /** [code, name, name_en, parentCode, system_key, postable] */
    private const ACCOUNTS = [
        ['1', 'الأصول', 'Assets', null, null, false],
        ['11', 'النقدية وما في حكمها', 'Cash & equivalents', '1', null, false],
        ['1101', 'الخزنة الرئيسية', 'Main safe', '11', 'cash_main', true],
        ['1102', 'البنك', 'Bank', '11', 'bank', true],
        ['1110', 'نقدية مع المناديب', 'Cash with reps', '11', 'rep_cash', false],
        ['12', 'المدينون', 'Receivables', '1', null, false],
        ['1201', 'العملاء', 'Clients', '12', 'receivables', true],
        ['1202', 'ضرايب مخصومة تحت الحساب', 'Withholding tax receivable', '12', 'withheld_tax', true],
        ['1901', 'حساب معلّق', 'Suspense', '1', 'suspense', true],
        ['2', 'الخصوم', 'Liabilities', null, null, false],
        ['21', 'الدائنون', 'Payables', '2', null, false],
        ['2101', 'الموردون', 'Suppliers', '21', 'payables', true],
        ['22', 'الضرائب', 'Taxes', '2', null, false],
        ['2201', 'ضريبة القيمة المضافة — مخرجات', 'VAT output', '22', 'vat_output', true],
        ['3', 'حقوق الملكية', 'Equity', null, null, false],
        ['3101', 'رأس المال والأرصدة الافتتاحية', 'Capital & opening balances', '3', 'opening_equity', true],
        ['4', 'الإيرادات', 'Revenue', null, null, false],
        ['4101', 'المبيعات', 'Sales', '4', 'sales', true],
        ['4102', 'مرتجعات المبيعات', 'Sales returns', '4', 'sales_returns', true],
        ['4103', 'خصومات مسموح بها', 'Discounts allowed', '4', 'discounts_allowed', true],
        ['5', 'المصروفات', 'Expenses', null, null, false],
        ['5101', 'مشتريات بضاعة', 'Purchases', '5', 'expense_purchases', true],
        ['5102', 'بنزين ووقود', 'Fuel', '5', 'expense_fuel', true],
        ['5103', 'صيانة عربيات', 'Vehicle maintenance', '5', 'expense_maintenance', true],
        ['5104', 'رواتب وأجور', 'Salaries & wages', '5', 'expense_salaries', true],
        ['5105', 'عمولات وحوافز', 'Commissions & incentives', '5', 'expense_commissions', true],
        ['5106', 'إيجار', 'Rent', '5', 'expense_rent', true],
        ['5107', 'هدايا وعينات', 'Gifts & samples', '5', 'expense_gifts', true],
        ['5199', 'مصروفات أخرى', 'Other expenses', '5', 'expense_other', true],
    ];

    /** [key, label, debit_key, credit_key, tax_key] */
    private const RULES = [
        ['tx.sale', 'فاتورة / مبيعات', 'receivables', 'sales', 'vat_output'],
        ['tx.collection.rep_cash', 'تحصيل كاش عن طريق مندوب', 'rep_cash', 'receivables', null],
        ['tx.collection.bank', 'تحصيل تحويل / شيك / كارت', 'bank', 'receivables', null],
        ['tx.collection.office_cash', 'تحصيل كاش مكتبي', 'cash_main', 'receivables', null],
        ['tx.collection.auto', 'تحصيل مقابل فاتورة/أمر كاش', 'rep_cash', 'receivables', null],
        ['tx.return', 'مرتجع', 'sales_returns', 'receivables', 'vat_output'],
        ['tx.refund', 'رد فلوس مرتجع كاش', 'receivables', 'rep_cash', null],
        ['tx.rebate', 'خصم تجاري', 'discounts_allowed', 'receivables', null],
        ['tx.settlement', 'تسوية / مقاصة', 'discounts_allowed', 'receivables', null],
        ['tx.transfer', 'قيد تحويل', 'receivables', 'suspense', null],
        ['tx.taxded', 'ضرايب مخصومة تحت الحساب', 'withheld_tax', 'receivables', null],
        ['tx.opening', 'رصيد افتتاحي عميل', 'receivables', 'opening_equity', null],
        ['settle.received', 'تصفية مندوب — المستلم', 'cash_main', 'rep_cash', null],
        ['sup.invoice', 'فاتورة مورد', 'expense_purchases', 'payables', null],
        ['sup.payment.cash', 'دفعة مورد كاش', 'payables', 'cash_main', null],
        ['sup.payment.bank', 'دفعة مورد تحويل / شيك', 'payables', 'bank', null],
        ['sup.opening', 'رصيد افتتاحي مورد', 'opening_equity', 'payables', null],
        ['sup.adjust', 'تسوية مورد', 'payables', 'suspense', null],
        ['expense.cash_main', 'مصروف من الخزنة', null, 'cash_main', null],
        ['expense.bank', 'مصروف من البنك', null, 'bank', null],
        ['expense.rep_cash', 'مصروف من نقدية مندوب', null, 'rep_cash', null],
        ['cash.deposit', 'إيداع خزنة → بنك', 'bank', 'cash_main', null],
        ['cash.withdraw', 'سحب بنك → خزنة', 'cash_main', 'bank', null],
        ['cash.rep_advance', 'عهدة نقدية خزنة → مندوب', 'rep_cash', 'cash_main', null],
        ['cash.rep_return', 'رد عهدة مندوب → خزنة', 'cash_main', 'rep_cash', null],
    ];

    public function run(): void
    {
        foreach (self::ACCOUNTS as [$code, $name, $en, $parentCode, $key, $postable]) {
            $parent = $parentCode ? GlAccount::where('code', $parentCode)->first() : null;
            $type = GlAccount::ROOT_TYPES[$code[0]];
            $acc = GlAccount::where('code', $code)->first()
                ?? ($key ? GlAccount::where('system_key', $key)->first() : null);
            $attrs = [
                'parent_id' => $parent?->id, 'type' => $type,
                'normal_side' => GlAccount::normalSideFor($type),
                'is_system' => true, 'system_key' => $key, 'is_postable' => $postable,
            ];
            if ($acc) {
                // الاسم اللي المالك غيّره مايتداسش
                $acc->fill($attrs)->save();
            } else {
                GlAccount::create($attrs + ['code' => $code, 'name' => $name, 'name_en' => $en, 'active' => true]);
            }
        }

        foreach (self::RULES as [$key, $label, $dr, $cr, $tax]) {
            GlPostingRule::firstOrCreate(['key' => $key], [
                'label' => $label, 'debit_key' => $dr, 'credit_key' => $cr, 'tax_key' => $tax, 'active' => true,
            ]);
        }
        GlPostingRule::flush();
    }
}
