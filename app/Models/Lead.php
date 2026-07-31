<?php

namespace App\Models;

use App\Models\Concerns\HasBilingualName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * عميل محتمل.
 *
 * ⚠️ الليد **مش** عميل. مالوش رصيد ولا كشف حساب ولا بيتباع له. لما
 * يوافق بيتحوّل لعميل بـ `Leads::convert()` — المكان الوحيد، وجوه
 * ترانزاكشن، وبيتقفل بعدها بـ `client_id` عشان مايتحولش مرتين.
 */
class Lead extends Model
{
    use HasBilingualName;

    protected $fillable = [
        'number', 'name', 'name_en', 'phone', 'contact_name', 'address',
        'zone_id', 'channel_id', 'assigned_to', 'status', 'source', 'lost_reason',
        'lat', 'lng', 'expected_monthly', 'client_id', 'converted_at',
        'next_action_on', 'notes', 'created_by',
    ];

    public const STATUSES = ['new', 'contacted', 'visited', 'negotiating', 'won', 'lost'];

    /** الحالات اللي لسه شغالة — الباقي خلاص */
    public const OPEN_STATUSES = ['new', 'contacted', 'visited', 'negotiating'];

    public const STATUS_CLASS = [
        'new' => 'b-blue',
        'contacted' => 'b-gray',
        'visited' => 'b-purple',
        'negotiating' => 'b-orange',
        'won' => 'b-green',
        'lost' => 'b-red',
    ];

    public const SOURCES = ['sheet', 'field', 'referral', 'inbound', 'other'];

    protected function casts(): array
    {
        return [
            'expected_monthly' => 'decimal:2',
            'next_action_on' => 'date',
            'converted_at' => 'datetime',
        ];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isConverted(): bool
    {
        return $this->client_id !== null;
    }

    /** ⚠️ متأخر عن موعده — بيلوّن الصف أحمر في القايمة */
    public function isOverdue(): bool
    {
        return $this->isOpen()
            && $this->next_action_on !== null
            && $this->next_action_on->isPast();
    }

    public function statusLabel(): string
    {
        return __('lead.status_'.$this->status);
    }

    public function statusClass(): string
    {
        return self::STATUS_CLASS[$this->status] ?? 'b-gray';
    }

    public function sourceLabel(): ?string
    {
        return $this->source ? __('lead.source_'.$this->source) : null;
    }

    public static function nextNumber(): string
    {
        $last = static::query()->orderByDesc('id')->value('number');
        $n = $last ? ((int) preg_replace('/\D+/', '', $last)) + 1 : 1001;

        return 'LD-'.$n;
    }
}
