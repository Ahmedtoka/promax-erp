<?php

namespace App\Services\Gl\Opening;

use App\Contracts\Gl\AdvancesRegister;
use Illuminate\Support\Carbon;

/** لحد ما الخط ٧ يربط سجل السلف — مفيش سطور */
class NullAdvancesRegister implements AdvancesRegister
{
    public function key(): string
    {
        return 'advances';
    }

    public function label(): string
    {
        return __('golive.opening_src_advances');
    }

    public function lines(Carbon $asOf): array
    {
        return [];
    }
}
