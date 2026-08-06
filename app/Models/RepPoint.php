<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * نقطة يدوية لمندوب — منح أو خصم بسبب (2026-08-06).
 * النقاط الأوتوماتيك مش هنا — بتتحسب مشتقة من النشاط في RepKpis.
 */
class RepPoint extends Model
{
    protected $fillable = ['user_id', 'date', 'points', 'reason', 'created_by'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
