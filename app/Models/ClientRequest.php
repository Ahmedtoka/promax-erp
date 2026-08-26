<?php

namespace App\Models;

use App\Models\Concerns\HasDocumentNumber;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientRequest extends Model
{
    use HasDocumentNumber, HasFactory;

    public const STATUSES = [
        'pending' => ['مستني الموافقة', 'b-gray'],
        'review' => ['تحت المراجعة', 'b-orange'],
        'approved' => ['متوافق عليه', 'b-green'],
        'rejected' => ['مرفوض', 'b-red'],
    ];

    protected $fillable = [
        'number', 'name', 'phone', 'address', 'address_ar', 'zone_id', 'lat', 'lng', 'has_docs',
        'photo_path', 'docs_path', 'docs_type',
        'status', 'created_by', 'decided_by', 'decided_at', 'client_id', 'decision_note',
        // مرساة الليد (بايبلاين ٢٦/٨) — الاعتماد بيقفل الليد «كسبناه»
        'lead_id',
    ];

    protected function casts(): array
    {
        return ['has_docs' => 'boolean', 'decided_at' => 'datetime'];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function rep(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function statusLabel(): string
    {
        // المسمى بييجي من lang/{ar,en}/enums.php — والثابت القديم fallback
        $key = 'enums.request_status.'.$this->status;

        return \Illuminate\Support\Facades\Lang::has($key)
            ? __($key)
            : (self::STATUSES[$this->status][0] ?? $this->status);
    }

    public function statusClass(): string
    {
        return self::STATUSES[$this->status][1] ?? 'b-gray';
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['pending', 'review'], true);
    }

    public function hasPhoto(): bool
    {
        return ! empty($this->photo_path);
    }

    public function hasDocsFile(): bool
    {
        return ! empty($this->docs_path);
    }

    public function photoUrl(): ?string
    {
        return $this->photo_path ? asset('storage/'.$this->photo_path) : null;
    }

    public function docsUrl(): ?string
    {
        return $this->docs_path ? asset('storage/'.$this->docs_path) : null;
    }

    public function hasPoint(): bool
    {
        return $this->lat !== null && $this->lng !== null;
    }

    public function mapUrl(): ?string
    {
        return $this->hasPoint()
            ? 'https://www.google.com/maps?q='.$this->lat.','.$this->lng
            : null;
    }

    public static function nextNumber(): string
    {
        // ⚠️ أكبر رقم مش آخر صف — شوف `HasDocumentNumber`
        return static::nextDocumentNumber('REQ-', 301);
    }
}
