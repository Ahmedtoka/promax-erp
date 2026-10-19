<?php

namespace App\Models\Gl;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * قبول تحذير أصفر في فحص الشجرة — محفوظ لكل (فحص × صف مسودة)، فالمحاسب
 * مابيقبلش نفس التحذير مرتين. الأحمر مالوش قبول.
 */
class CoaCheckAccept extends Model
{
    protected $table = 'coa_check_accepts';

    public $timestamps = false;

    protected $fillable = ['check', 'draft_account_id', 'accepted_by', 'accepted_at'];

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime'];
    }

    public function draftAccount(): BelongsTo
    {
        return $this->belongsTo(CoaDraftAccount::class, 'draft_account_id');
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }
}
