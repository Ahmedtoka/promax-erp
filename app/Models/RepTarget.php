<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** تارجت شهري لمندوب — فلوس / عملاء جداد / زيارات / قطع (2026-08-06). */
class RepTarget extends Model
{
    protected $fillable = [
        'user_id', 'month', 'money_target', 'new_clients_target',
        'visits_target', 'pieces_target',
    ];

    protected function casts(): array
    {
        return ['month' => 'date', 'money_target' => 'decimal:2'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** تارجت شهر معيّن لمندوب — أو null لو ماتحددش */
    public static function for(int $userId, \DateTimeInterface $month): ?self
    {
        return static::where('user_id', $userId)
            ->whereDate('month', \Carbon\Carbon::parse($month)->startOfMonth()->toDateString())
            ->first();
    }
}
