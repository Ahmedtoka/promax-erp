<?php

namespace App\Models;

use App\Models\Concerns\HasDocumentNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * حركة نقدية بين الخزنة والبنك ونقدية المناديب.
 * deposit: خزنة→بنك · withdraw: بنك→خزنة · rep_advance: خزنة→مندوب · rep_return: مندوب→خزنة
 */
class CashMovement extends Model
{
    use HasDocumentNumber;

    public const KINDS = ['deposit', 'withdraw', 'rep_advance', 'rep_return'];

    /** الأنواع اللي ليها مندوب — خانة `user_id` إجبارية فيها بس */
    public const REP_KINDS = ['rep_advance', 'rep_return'];

    protected $fillable = [
        'number', 'date', 'kind', 'amount', 'user_id', 'reference', 'note',
        'attachment_path', 'status', 'voided_at', 'voided_by', 'created_by',
    ];

    protected function casts(): array
    {
        return ['date' => 'date', 'voided_at' => 'datetime'];
    }

    public static function nextNumber(): string
    {
        return static::nextDocumentNumber('CM-', 1001);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
