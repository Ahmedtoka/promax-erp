<?php

namespace App\Services\Gl;

use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Gl\GlAccount;
use App\Models\Safe;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * زرع الأساس (خطة القفل ١٩/١٠): مراكز `CO` / `FAC` / `MAADI` والخزنتين
 * `MAADI-MAIN` / `BANK-1`، وربط المخازن بمراكزها.
 *
 * ⚠️ **إضافة بس.** أي صف موجود مابيتعدّلش، وأي عمود متملي مايتلمسش —
 * الفاضي بس بيتملى. فتعديلات المالك (الاسم، ربط مخزن بخط) بتعيش، والتشغيل
 * التاني بيرجّع قوايم فاضية.
 *
 * ⚠️ **المعادي مابيتخمّنش.** مخزن كوده `MAADI` أو `--maadi-warehouse=CODE`،
 * وفرع كوده `MAADI` أو فرع مخزن المعادي. لو مفيش، المركز بيتعمل من غير
 * ربط والتقرير بيقول كده.
 */
class FoundationSeed
{
    /**
     * @param  list<string>|null  $factoryCodes  أكواد مخازن المصنع (الافتراضي: كل `factory` ما عدا المعادي)
     * @return array{created: list<string>, linked: list<string>, unlinked: list<string>}
     *
     * @throws \InvalidArgumentException كود مخزن مش موجود — مفيش حاجة بتتكتب
     */
    public static function run(bool $dryRun = false, ?string $maadiWarehouse = null, ?array $factoryCodes = null): array
    {
        $maadiWh = self::resolveMaadiWarehouse($maadiWarehouse);
        $factories = self::resolveFactories($factoryCodes, $maadiWh);

        $out = ['created' => [], 'linked' => [], 'unlinked' => []];

        DB::beginTransaction();
        try {
            $co = self::center($out, 'CO', 'company', null, 'الشركة', 'Company');
            $fac = self::center($out, 'FAC', 'factory', $co, 'المصنع', 'Factory');
            $maadi = self::center($out, 'MAADI', 'branch', $co, 'المعادي', 'Maadi');

            $branch = Branch::where('code', 'MAADI')->first()
                ?? ($maadiWh?->branch_id ? Branch::find($maadiWh->branch_id) : null);

            if ($maadi->branch_id === null) {
                if ($branch) {
                    $maadi->update(['branch_id' => $branch->id]);
                    $out['linked'][] = "branch {$branch->code} -> MAADI";
                } else {
                    $out['unlinked'][] = 'MAADI: no branch with code MAADI';
                }
            }

            if ($maadiWh) {
                if ($maadi->warehouse_id === null) {
                    $maadi->update(['warehouse_id' => $maadiWh->id]);
                    $out['linked'][] = "MAADI.warehouse = {$maadiWh->code}";
                }
                self::linkWarehouse($out, $maadiWh, $maadi);
            } elseif ($maadi->warehouse_id === null) {
                $out['unlinked'][] = 'MAADI: no Maadi warehouse (code MAADI) - pass --maadi-warehouse=CODE';
            }

            foreach ($factories as $w) {
                self::linkWarehouse($out, $w, $fac);
            }
            if ($factories === []) {
                $out['unlinked'][] = 'FAC: no factory warehouse';
            }

            self::safe($out, 'MAADI-MAIN', 'cash', 'الخزنة الرئيسية - المعادي', 'Maadi main safe', 'cash_main', $maadi, $branch);
            self::safe($out, 'BANK-1', 'bank', 'حساب البنك الأول', 'Bank account 1', 'bank', $co, null);

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $out;
    }

    private static function resolveMaadiWarehouse(?string $code): ?Warehouse
    {
        if ($code !== null && $code !== '') {
            return Warehouse::where('code', $code)->first()
                ?? throw new \InvalidArgumentException("Warehouse {$code} not found");
        }

        return Warehouse::where('code', 'MAADI')->first();
    }

    /** @return list<Warehouse> */
    private static function resolveFactories(?array $codes, ?Warehouse $maadi): array
    {
        if ($codes !== null) {
            $found = Warehouse::whereIn('code', $codes)->orderBy('id')->get();
            if ($missing = array_diff($codes, $found->pluck('code')->all())) {
                throw new \InvalidArgumentException('Warehouse '.implode(', ', $missing).' not found');
            }

            return $found->reject(fn (Warehouse $w) => $w->id === $maadi?->id)->values()->all();
        }

        return Warehouse::where('type', Warehouse::TYPE_FACTORY)
            ->when($maadi, fn ($q) => $q->whereKeyNot($maadi->id))
            ->orderBy('id')->get()->all();
    }

    private static function center(array &$out, string $code, string $kind, ?CostCenter $parent, string $name, string $nameEn): CostCenter
    {
        $c = CostCenter::firstOrCreate(['code' => $code], [
            'name' => $name, 'name_en' => $nameEn, 'kind' => $kind, 'parent_id' => $parent?->id, 'active' => true,
        ]);
        if ($c->wasRecentlyCreated) {
            $out['created'][] = $code;
        }

        return $c;
    }

    private static function linkWarehouse(array &$out, Warehouse $w, CostCenter $c): void
    {
        if ($w->cost_center_id === null) {
            $w->update(['cost_center_id' => $c->id]);
            $out['linked'][] = "warehouse {$w->code} -> {$c->code}";
        }
    }

    private static function safe(array &$out, string $code, string $kind, string $name, string $nameEn, string $glKey, CostCenter $center, ?Branch $branch): void
    {
        $glId = GlAccount::where('system_key', $glKey)->value('id');

        $safe = Safe::firstOrCreate(['code' => $code], [
            'name' => $name, 'name_en' => $nameEn, 'kind' => $kind, 'currency' => 'EGP', 'active' => true,
            'gl_account_id' => $glId, 'cost_center_id' => $center->id, 'branch_id' => $branch?->id,
        ]);
        if ($safe->wasRecentlyCreated) {
            $out['created'][] = $code;

            return;
        }

        // الفاضي بس بيتملى — اللي المالك كتبه مايتلمسش
        $fill = array_filter([
            'gl_account_id' => $safe->gl_account_id === null ? $glId : null,
            'cost_center_id' => $safe->cost_center_id === null ? $center->id : null,
            'branch_id' => $safe->branch_id === null ? $branch?->id : null,
        ], fn ($v) => $v !== null);
        if ($fill !== []) {
            $safe->update($fill);
            $out['linked'][] = "safe {$code}: ".implode(', ', array_keys($fill));
        }
    }
}
