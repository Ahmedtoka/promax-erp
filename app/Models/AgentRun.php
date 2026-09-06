<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * تشغيلة واحدة لمساعد بروماكس: سؤال ← أدوات ← رد.
 * بنسجل التوكنز والوقت والحالة عشان المراجعة والتكلفة.
 */
class AgentRun extends Model
{
    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REFUSED = 'refused';

    protected $fillable = [
        'conversation_id', 'user_message', 'agent_name', 'tools_called',
        'response', 'tokens_in', 'tokens_out', 'duration_ms', 'status', 'error',
    ];

    protected function casts(): array
    {
        return [
            'tools_called' => 'array',
            'response' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AgentConversation::class, 'conversation_id');
    }
}
