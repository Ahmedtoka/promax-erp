<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * حدث واحد في مراجعة المستندات — ✓ / ✗ / فتح تاني / تعديل (١١/١١).
 *
 * ⚠️ **سجل مابيتعدّلش ولا بيتمسح.** ده الدليل اللي أحمد بيرجعله لما يسأل
 * «المحاسب راجع فعلاً؟» — فالموديل بيرفض أي update أو delete.
 */
class DocReviewEvent extends Model
{
    public const UPDATED_AT = null;

    public const TYPES = ['invoice', 'po', 'return'];

    /** الأحداث اللي بتتحسب «مراجعة» في التحليل (الوقت بين مستند والتاني) */
    public const REVIEW_ACTIONS = ['ok', 'issue'];

    /** (١٣/١١) المراجِع اتراجع عن علامته (نفس اليوم) — مش مراجعة ولا تعديل */
    public const UNDO = 'undo';

    public const EDIT_ACTIONS = ['serial', 'lines', 'rep', 'date', 'proof'];

    /** أقل من كده بين مراجعة والتانية = سريعة. ٥ ورا بعض = علم أحمر */
    public const FAST_SECONDS = 8;

    public const FAST_STREAK = 5;

    /** فجوة أكبر من كده = جلسة جديدة (مش «وقت على المستند») — زي ActivityStats */
    public const SESSION_GAP = 1800;

    protected $fillable = [
        'user_id', 'doc_type', 'doc_id', 'action', 'before', 'after',
        'net_delta', 'grand_delta', 'seconds_since_prev', 'open_ms', 'note',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'net_delta' => 'decimal:2',
            'grand_delta' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('doc_review_events is append-only'));
        static::deleting(fn () => throw new \LogicException('doc_review_events is append-only'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
