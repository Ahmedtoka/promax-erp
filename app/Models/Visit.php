<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Visit extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'client_id', 'checked_in_at', 'checked_out_at', 'lat', 'lng', 'note',
        'journey_plan_id',
    ];

    protected function casts(): array
    {
        return [
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function isOpen(): bool
    {
        return $this->checked_out_at === null;
    }

    public function minutes(): ?int
    {
        return $this->checked_out_at
            ? (int) round(abs($this->checked_in_at->diffInMinutes($this->checked_out_at)))
            : null;
    }
}
