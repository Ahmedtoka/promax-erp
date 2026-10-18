<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * تعليم استلام شاشة — مين وإمتى وبملاحظة.
 *
 * ⚠️ **مابيتمسحش.** الإلغاء بيملا `unmarked_at` و`unmarked_by`، والصف
 * فاضل في السجل (spec §4.1: «التعليم ينفع يتلغي (بسجل)»).
 */
class HandoverMark extends Model
{
    public $timestamps = false;

    protected $fillable = ['screen_id', 'user_id', 'marked_at', 'unmarked_at', 'unmarked_by', 'note'];

    protected function casts(): array
    {
        return [
            'marked_at' => 'datetime',
            'unmarked_at' => 'datetime',
        ];
    }

    public function screen(): BelongsTo
    {
        return $this->belongsTo(HandoverScreen::class, 'screen_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function unmarker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unmarked_by');
    }
}
