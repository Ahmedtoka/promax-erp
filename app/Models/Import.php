<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** سجل استيراد — شيت اترفع، وإيه اللي حصل له */
class Import extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_FAILED = 'failed';

    public const STATUS_CLASS = [
        self::STATUS_PENDING => 'b-orange',
        self::STATUS_APPLIED => 'b-green',
        self::STATUS_FAILED => 'b-red',
    ];

    protected $fillable = [
        'kind', 'file_name', 'file_path', 'sheet', 'status',
        'rows_total', 'rows_ok', 'rows_failed',
        'errors', 'summary', 'user_id', 'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'errors' => 'array',
            'summary' => 'array',
            'applied_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function statusLabel(): string
    {
        return __('import.status_'.$this->status);
    }

    public function statusClass(): string
    {
        return self::STATUS_CLASS[$this->status] ?? 'b-gray';
    }

    public function kindLabel(): string
    {
        return __('import.kind_'.$this->kind);
    }

    /** ملخص النتيجة كنص قصير — بلغة الواجهة */
    public function resultLine(): ?string
    {
        $r = $this->summary['result'] ?? null;

        if (! is_array($r) || ! $r) {
            return null;
        }

        $parts = [];
        foreach ($r as $key => $value) {
            if ((int) $value > 0) {
                $parts[] = __('import.result_'.$key).': '.number_format((float) $value);
            }
        }

        return $parts ? implode(' · ', $parts) : null;
    }
}
