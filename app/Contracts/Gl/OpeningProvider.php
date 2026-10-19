<?php

namespace App\Contracts\Gl;

use Illuminate\Support\Carbon;

/**
 * مصدر سطور لقيد الافتتاح الواحد (`golive.opening`) — العملاء، الموردين،
 * المخزون، الأصول الثابتة، السلف... `OpeningBuilder` بيجمعهم ويحط الفرق
 * على `opening_difference`. المصدر بيرجّع الطرف اللي يعرفه بس (مثلاً مدين
 * المخزون) — الطرف التاني بيتقفل بالفرق أو بسطور حقوق الملكية اليدوية.
 */
interface OpeningProvider
{
    public function key(): string;

    public function label(): string;

    /** @return list<array{account_id:int, cost_center_id?:?int, debit:float, credit:float, memo?:string}> */
    public function lines(Carbon $asOf): array;
}
