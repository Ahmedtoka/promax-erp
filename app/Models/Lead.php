<?php

namespace App\Models;

use App\Models\Concerns\HasDocumentNumber;

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
    use HasBilingualName, HasDocumentNumber;

    protected $fillable = [
        'number', 'name', 'name_en', 'phone', 'contact_name', 'address',
        'zone_id', 'channel_id', 'assigned_to', 'status', 'source', 'lost_reason',
        'lat', 'lng', 'expected_monthly', 'client_id', 'converted_at',
        'next_action_on', 'notes', 'created_by',
        // ⚠️ أعمدة السورسنج (2026-08-13) — من غيرها في `$fillable`
        // المستورد بيكتبها في صمت من غير ما تتحفظ، والاستيراد بيقول
        // «تم» والليدز بتنزل بسكور صفر وبلا مرجع خارجي.
        'external_id', 'website', 'rating', 'reviews_count', 'category_raw',
        'score', 'governorate', 'sub_channel',
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

    /**
     * ⚠️ `gmaps` و`facebook` بيتحطوا من `LeadImporter` أوتوماتيك حسب
     * شكل المرجع الخارجي — مش من دروب داون. سيبهم في القايمة عشان
     * الفلتر في الشاشة والفاليديشن في الكنترولر يعرفوهم.
     */
    public const SOURCES = ['sheet', 'gmaps', 'facebook', 'field', 'referral', 'inbound', 'other'];

    protected function casts(): array
    {
        return [
            'expected_monthly' => 'decimal:2',
            'next_action_on' => 'date',
            'converted_at' => 'datetime',
            'rating' => 'decimal:2',
            'reviews_count' => 'integer',
            'score' => 'integer',
        ];
    }

    /** لون شارة السكور — من نفس المحرك اللي حسبه */
    public function scoreClass(): string
    {
        return \App\Support\LeadScore::badgeClass((int) $this->score);
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
        // ⚠️ أكبر رقم مش آخر صف — شوف `HasDocumentNumber`
        return static::nextDocumentNumber('LD-', 1001);
    }
}
