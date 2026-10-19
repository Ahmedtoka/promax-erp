<?php

namespace App\Models;

use App\Models\Concerns\HasDocumentNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * حركة نقدية بين الخزنة والبنك ونقدية المناديب.
 * deposit: خزنة→بنك · withdraw: بنك→خزنة · rep_advance: خزنة→مندوب · rep_return: مندوب→خزنة
 * safe_transfer: خزنة→خزنة · bank_transfer: بنك→بنك (Lane 4)
 */
class CashMovement extends Model
{
    use HasDocumentNumber;

    public const KINDS = ['deposit', 'withdraw', 'rep_advance', 'rep_return', 'safe_transfer', 'bank_transfer'];

    /** الأنواع اللي بتقبل خزن (L4-D3): [نوع خزنة المصدر، نوع خزنة الوجهة] */
    public const SAFE_KINDS = [
        'deposit' => ['cash', 'bank'],
        'withdraw' => ['bank', 'cash'],
        'safe_transfer' => ['cash', 'cash'],
        'bank_transfer' => ['bank', 'bank'],
    ];

    /** الأنواع اللي ليها مندوب — خانة `user_id` إجبارية فيها بس */
    public const REP_KINDS = ['rep_advance', 'rep_return'];

    protected $fillable = [
        'number', 'date', 'kind', 'amount', 'user_id', 'reference', 'note',
        'attachment_path', 'status', 'voided_at', 'voided_by', 'created_by',
        'from_safe_id', 'to_safe_id', 'cost_center_id',
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

    public function fromSafe(): BelongsTo
    {
        return $this->belongsTo(Safe::class, 'from_safe_id');
    }

    public function toSafe(): BelongsTo
    {
        return $this->belongsTo(Safe::class, 'to_safe_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }
}
