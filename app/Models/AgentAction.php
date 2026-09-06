<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * أكشن مقترح من مساعد بروماكس — بيستنى تأكيد صاحبه قبل التنفيذ.
 *
 * ⚠️ التنفيذ بيحصل وقت التأكيد بس، وبنفس مسار كود الشاشة الأصلية
 * (مثلاً التحصيل بيمر بـ`App\Services\ManualCollection` — نفس
 * سيرفس المستند اليدوي بالحرف).
 */
class AgentAction extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    public const TYPE_COLLECTION = 'collection';

    protected $fillable = [
        'user_id', 'type', 'payload', 'status', 'result', 'error', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'confirmed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
