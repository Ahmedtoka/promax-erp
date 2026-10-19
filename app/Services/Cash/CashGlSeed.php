<?php

namespace App\Services\Cash;

use App\Models\ExpenseCategory;
use App\Models\Gl\GlAccount;
use App\Models\Gl\GlPostingRule;
use Illuminate\Support\Facades\DB;

/**
 * حسابات النظام وقواعد الترحيل وبنود المصروفات بتاعة Lane 4 (L4-D13).
 *
 * ⚠️ **بالمفتاح مش بالكود.** الحساب بيتدوّر عليه بـ`system_key` الأول؛ لو
 * مش موجود والكود المقترح فاضي بيتعمل بيه، ولو الكود متاخد بحساب تاني
 * (شجرة المحاسب المستوردة) بيتعمل بكود `<code>.L4` وبتتكتب ملحوظة. أي حساب
 * موجود **مابيتعدّلش أبداً**. القواعد والبنود `firstOrCreate` — تعديلات
 * المالك بتعيش، والتشغيل التاني بيرجّع أصفار.
 *
 *   php artisan promax:cash-setup --dry-run
 */
class CashGlSeed
{
    /** مجموعة الأصول الثابتة — بتتعمل تحت الجذر 1 لو الكود 14 فاضي (13 = المخزون من الخط ٣) */
    private const GROUP = ['14', 'الأصول الثابتة', 'Fixed assets'];

    /** [system_key, code, name, name_en, parent code, type, normal_side] */
    private const ACCOUNTS = [
        ['fixed_assets', '1401', 'الأصول الثابتة', 'Fixed assets', '14', 'asset', 'debit'],
        // مجمع الإهلاك: أصل مقابل (contra) — طبيعته دائنة
        ['accum_depreciation', '1409', 'مجمع الإهلاك', 'Accumulated depreciation', '14', 'asset', 'credit'],
        ['expense_depreciation', '5108', 'إهلاك الأصول الثابتة', 'Depreciation', '5', 'expense', 'debit'],
        ['cash_over_short', '5120', 'عجز وزيادة الخزنة', 'Cash over / short', '5', 'expense', 'debit'],
        ['expense_factory', '5130', 'مصاريف المصنع', 'Factory overheads', '5', 'expense', 'debit'],
    ];

    /** [key, label, debit_key, credit_key] */
    private const RULES = [
        ['cash.safe_transfer', 'تحويل خزنة → خزنة', 'cash_main', 'cash_main'],
        ['cash.bank_transfer', 'تحويل بنك → بنك', 'bank', 'bank'],
        ['cash.count', 'عجز جرد خزنة', 'cash_over_short', 'cash_main'],
        ['asset.depreciation', 'إهلاك شهري', 'expense_depreciation', 'accum_depreciation'],
    ];

    /** [code, name, name_en, type, account system_key, life_months, links_vehicle] */
    private const CATEGORIES = [
        ['BUFFET', 'بوفيه', 'Buffet', 'operating', 'expense_other', null, false],
        ['FUEL', 'بنزين', 'Fuel', 'operating', 'expense_fuel', null, true],
        ['PETTY', 'نثريات', 'Petty expenses', 'operating', 'expense_other', null, false],
        ['MAINT_VEH', 'صيانة عربيات', 'Vehicle maintenance', 'operating', 'expense_maintenance', null, false],
        ['SALARIES', 'مرتبات', 'Salaries', 'payroll', 'expense_salaries', null, false],
        ['LAPTOP', 'لابتوبات', 'Laptops', 'asset', 'fixed_assets', 36, false],
        ['CAMERA', 'كاميرات', 'Cameras', 'asset', 'fixed_assets', 36, false],
        ['FURNITURE', 'مكاتب وأثاث', 'Desks & furniture', 'asset', 'fixed_assets', 60, false],
        ['FITOUT', 'تجهيزات', 'Fit-out', 'asset', 'fixed_assets', 60, false],
        ['ELECTRICITY', 'كهربا', 'Electricity', 'factory', 'expense_factory', null, false],
        ['GAS', 'غاز', 'Gas', 'factory', 'expense_factory', null, false],
        ['WATER', 'مياه', 'Water', 'factory', 'expense_factory', null, false],
        ['FACT_MAINT', 'صيانة المصنع', 'Factory maintenance', 'factory', 'expense_factory', null, false],
    ];

    /**
     * @return array{accounts_created:int, rules_created:int, categories_created:int, notes:list<string>}
     */
    public static function run(bool $dryRun = false): array
    {
        $out = ['accounts_created' => 0, 'rules_created' => 0, 'categories_created' => 0, 'notes' => []];

        DB::beginTransaction();
        try {
            foreach (self::ACCOUNTS as $row) {
                self::account($out, ...$row);
            }

            foreach (self::RULES as [$key, $label, $dr, $cr]) {
                $rule = GlPostingRule::firstOrCreate(['key' => $key], [
                    'label' => $label, 'debit_key' => $dr, 'credit_key' => $cr, 'tax_key' => null, 'active' => true,
                ]);
                $out['rules_created'] += $rule->wasRecentlyCreated ? 1 : 0;
            }

            foreach (self::CATEGORIES as [$code, $name, $en, $type, $accKey, $life, $vehicle]) {
                $cat = ExpenseCategory::firstOrCreate(['code' => $code], [
                    'name' => $name, 'name_en' => $en, 'type' => $type,
                    'account_id' => GlAccount::where('system_key', $accKey)->value('id'),
                    'life_months' => $life, 'links_vehicle' => $vehicle, 'active' => true,
                ]);
                $out['categories_created'] += $cat->wasRecentlyCreated ? 1 : 0;
            }

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            GlPostingRule::flush();
            throw $e;
        }
        GlPostingRule::flush();

        return $out;
    }

    private static function account(array &$out, string $key, string $code, string $name, string $en, string $parentCode, string $type, string $side): void
    {
        if (GlAccount::where('system_key', $key)->exists()) {
            return;
        }

        $parent = $parentCode === self::GROUP[0] ? self::group($out) : self::root($out, $parentCode);

        $finalCode = $code;
        if (GlAccount::where('code', $code)->exists()) {
            $finalCode = $code.'.L4';
            if (GlAccount::where('code', $finalCode)->exists()) {
                $out['notes'][] = "{$key}: codes {$code} and {$finalCode} are both taken - not created, add it from the chart and set its key";

                return;
            }
            $out['notes'][] = "{$key}: code {$code} is taken by another account - created as {$finalCode}";
        }

        GlAccount::create([
            'code' => $finalCode, 'name' => $name, 'name_en' => $en, 'parent_id' => $parent?->id,
            'type' => $type, 'normal_side' => $side, 'is_system' => true, 'system_key' => $key,
            'is_postable' => true, 'active' => true,
        ]);
        $out['accounts_created']++;
    }

    /** مجموعة 14 — بالكود؛ بتتعمل بس لو الكود فاضي */
    private static function group(array &$out): ?GlAccount
    {
        [$code, $name, $en] = self::GROUP;
        $existing = GlAccount::where('code', $code)->first();
        if ($existing) {
            if ($existing->type !== 'asset' || $existing->is_postable) {
                $out['notes'][] = "code {$code} is not a fixed-assets group (type {$existing->type}) - new accounts go under root 1";

                return self::root($out, '1');
            }

            return $existing;
        }

        $root = self::root($out, '1');
        $group = GlAccount::create([
            'code' => $code, 'name' => $name, 'name_en' => $en, 'parent_id' => $root?->id,
            'type' => 'asset', 'normal_side' => 'debit', 'is_system' => true, 'system_key' => null,
            'is_postable' => false, 'active' => true,
        ]);
        $out['accounts_created']++;

        return $group;
    }

    private static function root(array &$out, string $code): ?GlAccount
    {
        $root = GlAccount::where('code', $code)->whereNull('parent_id')->first()
            ?? GlAccount::where('code', $code)->first();
        if (! $root) {
            $note = "root {$code} missing - run GlSeeder; accounts created without a parent";
            if (! in_array($note, $out['notes'], true)) {
                $out['notes'][] = $note;
            }
        }

        return $root;
    }
}
