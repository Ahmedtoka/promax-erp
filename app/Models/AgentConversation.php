<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * محادثة مع مساعد بروماكس — سلسلة أسئلة وأجوبة ليوزر واحد.
 * كل سؤال جواها = صف في `agent_runs`.
 */
class AgentConversation extends Model
{
    protected $fillable = ['user_id', 'title'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AgentRun::class, 'conversation_id');
    }
}
