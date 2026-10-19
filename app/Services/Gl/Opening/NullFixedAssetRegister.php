<?php

namespace App\Services\Gl\Opening;

use App\Contracts\Gl\FixedAssetRegister;
use Illuminate\Support\Carbon;

/** لحد ما الخط ٤ يربط سجل الأصول — مفيش سطور */
class NullFixedAssetRegister implements FixedAssetRegister
{
    public function key(): string
    {
        return 'fixed_assets';
    }

    public function label(): string
    {
        return __('golive.opening_src_fixed_assets');
    }

    public function lines(Carbon $asOf): array
    {
        return [];
    }
}
