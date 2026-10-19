<?php

namespace App\Services\Costing;

use App\Models\Gl\GlAccount;
use App\Models\Gl\GlPostingRule;
use Illuminate\Support\Facades\DB;

/**
 * حسابات وقواعد التكاليف بتاعة الخط ٩ (D1–D3) — على نمط `CashGlSeed`.
 *
 * ⚠️ **بالمفتاح مش بالكود.** الحساب بيتدوّر عليه بـ`system_key`؛ لو الكود
 * متاخد بحساب تاني بيتعمل بكود `<code>.L9` وبتتكتب ملحوظة. أي حساب موجود
 * **مابيتعدّلش أبداً**. القواعد `firstOrCreate` والتشغيل التاني بيرجّع أصفار.
 * `GlSeeder` **مابيشغّلهاش** (D1): من غير الـseed ده المخزون الدائم مقفول.
 *
 *   php artisan promax:costing-gl-seed --dry-run
 */
class CostingGlSeed
{
    /** [system_key, code, name, name_en, root code, type, normal_side] */
    private const ACCOUNTS = [
        ['cogs', '5150', 'تكلفة المبيعات', 'Cost of sales', '5', 'expense', 'debit'],
        ['internal_cogs', '5151', 'تكلفة المبيعات الداخلية', 'Internal cost of sales', '5', 'expense', 'debit'],
        ['internal_elim', '5152', 'استبعاد هامش التحويل الداخلي', 'Internal transfer margin elimination', '5', 'expense', 'debit'],
        // تكاليف محمّلة على الإنتاج: حساب مصروف طبيعته دائنة (contra)
        ['overhead_applied', '5153', 'تكاليف تشغيل محمّلة على الإنتاج', 'Overhead applied to production', '5', 'expense', 'credit'],
        ['production_variance', '5154', 'انحرافات تكاليف الإنتاج', 'Production cost variances', '5', 'expense', 'debit'],
        ['internal_sales', '4110', 'مبيعات داخلية للفروع', 'Internal sales to branches', '4', 'revenue', 'credit'],
    ];

    /** [key, label, debit_key, credit_key] */
    private const RULES = [
        ['cost.sale', 'تكلفة بيع', 'cogs', 'inventory'],
        ['cost.markup', 'هامش تحويل داخلي على بيع الفرع', 'cogs', 'internal_elim'],
        ['cost.transfer', 'تحويل داخلي مصنع → فرع (التكلفة)', 'internal_cogs', 'internal_sales'],
        ['cost.transfer_markup', 'تحويل داخلي (هامش سعر التحويل)', 'internal_elim', 'internal_sales'],
        ['cost.production', 'إنتاج تام (مواد)', 'inventory', 'inventory'],
        ['cost.overhead', 'تحميل تكاليف تشغيل على الإنتاج', 'inventory', 'overhead_applied'],
        ['cost.variance', 'انحراف تكاليف تشغيل الشهر', 'production_variance', 'overhead_applied'],
        ['sup.invoice.stock', 'فاتورة مورد (مخزون دائم)', 'inventory', 'payables'],
    ];

    /** @return list<string> مفاتيح الحسابات الستة */
    public static function keys(): array
    {
        return array_column(self::ACCOUNTS, 0);
    }

    /** @return list<string> مفاتيح القواعد الثمانية */
    public static function ruleKeys(): array
    {
        return array_column(self::RULES, 0);
    }

    /** كل مفتاح D2 موجود وكل قاعدة D3 موجودة ونشطة */
    public static function ready(): bool
    {
        $have = GlAccount::whereIn('system_key', self::keys())->distinct()->count('system_key');
        if ($have !== count(self::keys())) {
            return false;
        }
        $rules = GlPostingRule::whereIn('key', self::ruleKeys())->where('active', true)->count();

        return $rules === count(self::ruleKeys());
    }

    /**
     * سبب رفض التشغيل الحقيقي، أو null: الشجرة فاضية، أو جذر ٤/٥ مفقود، أو
     * مفتاح `inventory` / `payables` (هدف القواعد) مش موجود.
     */
    public static function blocker(): ?string
    {
        if (! GlAccount::query()->exists()) {
            return 'The chart of accounts is empty - set up the GL tree first (GL wizard or GlSeeder), then run this again. Nothing written.';
        }
        foreach (['4', '5'] as $code) {
            if (! GlAccount::where('code', $code)->exists()) {
                return "Root {$code} is missing from the chart of accounts - set up the GL tree first. Nothing written.";
            }
        }
        foreach (['inventory', 'payables'] as $key) {
            if (! GlAccount::where('system_key', $key)->exists()) {
                return "The system account [{$key}] is missing - set up the GL tree first. Nothing written.";
            }
        }

        return null;
    }

    /**
     * @return array{accounts_created:int, rules_created:int, notes:list<string>}
     */
    public static function run(bool $dryRun = false): array
    {
        $out = ['accounts_created' => 0, 'rules_created' => 0, 'notes' => []];

        if (! $dryRun && ($why = self::blocker()) !== null) {
            throw new \RuntimeException($why);
        }

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

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            GlPostingRule::flush();
            throw $e;
        }
        GlPostingRule::flush();

        return $out;
    }

    private static function account(array &$out, string $key, string $code, string $name, string $en, string $rootCode, string $type, string $side): void
    {
        if (GlAccount::where('system_key', $key)->exists()) {
            return;
        }

        $parent = GlAccount::where('code', $rootCode)->whereNull('parent_id')->first()
            ?? GlAccount::where('code', $rootCode)->first();
        if (! $parent) {
            $note = "root {$rootCode} missing - accounts created without a parent";
            if (! in_array($note, $out['notes'], true)) {
                $out['notes'][] = $note;
            }
        }

        $finalCode = $code;
        if (GlAccount::where('code', $code)->exists()) {
            $finalCode = $code.'.L9';
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
}
