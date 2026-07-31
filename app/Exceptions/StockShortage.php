<?php

namespace App\Exceptions;

/**
 * نقص في البضاعة — عهدة عربية أو رصيد رف مش كافي.
 * حالة خاصة من Rejected، فأي كود بيلقف Rejected بيلقفها كمان.
 */
class StockShortage extends Rejected
{
}
