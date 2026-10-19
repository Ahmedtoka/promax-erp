<?php

namespace App\Observers\Gl;

use App\Models\FixedAssetDepreciation;
use App\Services\Gl\Ledger;
use Illuminate\Support\Facades\Log;

/**
 * قسط الإهلاك الشهري = قيد آلي `asset.depreciation` (L4-D11).
 * نفس عقيدة `CashMovementObserver`: الاستثناء بيتبلع ويتسجل.
 */
class FixedAssetDepreciationObserver
{
    public function __construct(private Ledger $ledger)
    {
    }

    public function created(FixedAssetDepreciation $d): void
    {
        try {
            $this->ledger->post($d, $d->creator);
        } catch (\Throwable $e) {
            Log::error('gl.observer failed', ['fixed_asset_depreciation' => $d->id, 'event' => 'created', 'error' => $e->getMessage()]);
        }
    }

    public function updated(FixedAssetDepreciation $d): void
    {
        try {
            if ($d->wasChanged(['amount', 'date', 'period_key', 'fixed_asset_id'])) {
                $d->unsetRelation('asset');
                $this->ledger->repost($d);
            }
        } catch (\Throwable $e) {
            Log::error('gl.observer failed', ['fixed_asset_depreciation' => $d->id, 'event' => 'updated', 'error' => $e->getMessage()]);
        }
    }

    public function deleted(FixedAssetDepreciation $d): void
    {
        try {
            $this->ledger->unpost($d);
        } catch (\Throwable $e) {
            Log::error('gl.observer failed', ['fixed_asset_depreciation' => $d->id, 'event' => 'deleted', 'error' => $e->getMessage()]);
        }
    }
}
