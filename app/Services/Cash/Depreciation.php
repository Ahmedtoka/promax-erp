<?php

namespace App\Services\Cash;

use App\Exceptions\Rejected;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Models\Gl\GlAccount;
use App\Models\Gl\GlPeriod;
use App\Models\Gl\GlPostingRule;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * تشغيل الإهلاك الشهري (L4-D11) — صف واحد لكل أصل لكل `YYYY-MM`
 * بتاريخ آخر يوم في الشهر، و`FixedAssetDepreciationObserver` بيرحّله
 * `asset.depreciation`. التشغيل مرتين لنفس الشهر = مفيش حاجة جديدة
 * (فحص الوجود جوه قفل الأصل + الـunique index).
 *
 * المرفوض كله قبل أي كتابة:
 *   • الشهر المقفول — مفيش قسط يدخل ميزان اتعتمد.
 *   • الشهر الحالي أو اللي جاي — الشهر لسه ماخلصش.
 *   • قاعدة `asset.depreciation` واقفة أو حساباتها ناقصة — من غيرها
 *     الصف كان هيتسجل من غير قيد، والتشغيل التاني بيتخطاه فمايتصلّحش.
 */
class Depreciation
{
    /** @return array{created:int, skipped:int, amount:float} */
    public function run(string $periodKey, ?User $by = null, bool $dryRun = false): array
    {
        $monthEnd = FixedAsset::periodStart($periodKey)->endOfMonth()->startOfDay();
        if (GlPeriod::isClosed($monthEnd)) {
            throw new Rejected(__('gl.period_closed', ['period' => $periodKey]));
        }
        if ($periodKey >= now('Africa/Cairo')->format('Y-m')) {
            throw new Rejected(__('cash.depr_month_open', ['month' => $periodKey]));
        }
        if (! $dryRun) {
            $rule = GlPostingRule::forKey('asset.depreciation');
            if (! $rule || ! $rule->active || ! GlAccount::where('system_key', $rule->debit_key)->exists() || ! GlAccount::where('system_key', $rule->credit_key)->exists()) {
                throw new Rejected(__('cash.depr_rule_missing'));
            }
        }

        $out = ['created' => 0, 'skipped' => 0, 'amount' => 0.0];
        FixedAsset::query()
            ->whereIn('status', FixedAsset::STATUSES)
            ->whereDate('start_date', '<=', $monthEnd->toDateString())
            ->orderBy('id')
            ->chunkById(200, function ($assets) use ($periodKey, $monthEnd, $by, $dryRun, &$out) {
                foreach ($assets as $a) {
                    if ($dryRun) {
                        $amount = FixedAssetDepreciation::where('fixed_asset_id', $a->id)->where('period_key', $periodKey)->exists()
                            ? 0.0 : $a->amountFor($periodKey);
                    } else {
                        try {
                            // ⚠️ قفل الأصل: إلغاء سنده في نفس اللحظة أو تشغيل تاني
                            // مايحسبوش الباقي على نفس الصفوف
                            $amount = DB::transaction(function () use ($a, $periodKey, $monthEnd, $by) {
                                $row = FixedAsset::whereKey($a->id)->lockForUpdate()->first();
                                if ($row === null || FixedAssetDepreciation::where('fixed_asset_id', $row->id)->where('period_key', $periodKey)->exists()) {
                                    return 0.0;
                                }
                                $amount = $row->amountFor($periodKey);
                                if ($amount > 0) {
                                    FixedAssetDepreciation::create([
                                        'fixed_asset_id' => $row->id, 'period_key' => $periodKey,
                                        'date' => $monthEnd->toDateString(), 'amount' => $amount, 'created_by' => $by?->id,
                                    ]);
                                }

                                return $amount;
                            });
                        } catch (UniqueConstraintViolationException) {
                            $amount = 0.0;
                        }
                    }
                    if ($amount <= 0) {
                        $out['skipped']++;

                        continue;
                    }
                    $out['created']++;
                    $out['amount'] = round($out['amount'] + $amount, 2);
                }
            });

        return $out;
    }
}
