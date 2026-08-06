<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * أليرت عميل محتمل لمندوب (2026-08-06) —
 * shown = ظهر له · accepted = قبل وراح له · rejected = رفض.
 */
class LeadPing extends Model
{
    protected $fillable = ['lead_id', 'user_id', 'action'];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
