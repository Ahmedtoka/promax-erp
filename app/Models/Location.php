<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * رف في المخزن. الكود A03 = ستاند A، الرف الثالث.
 * Shelf location. Code A03 = stand A, level 3.
 */
class Location extends Model
{
    use HasFactory;

    protected $fillable = [
        'warehouse_id', 'code', 'stand', 'level', 'is_pick_face', 'capacity', 'notes', 'active',
    ];

    protected function casts(): array
    {
        return ['is_pick_face' => 'boolean', 'active' => 'boolean'];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function batchLocations(): HasMany
    {
        return $this->hasMany(BatchLocation::class);
    }

    /** إجمالي اللي على الرف ده */
    public function qty(): int
    {
        return (int) $this->batchLocations()->sum('qty');
    }

    public function isEmpty(): bool
    {
        return $this->qty() === 0;
    }

    public function freeCapacity(): ?int
    {
        return $this->capacity === null ? null : max($this->capacity - $this->qty(), 0);
    }

    /**
     * أسوأ حالة صلاحية على الرف — ده اللي بيقول لأمين المخزن
     * إن الرف ده فيه حاجة قربت تخلص ولازم تتحرك لفوق.
     */
    public function worstExpiryState(): string
    {
        $batch = $this->batchLocations()
            ->where('qty', '>', 0)
            ->with('batch')
            ->get()
            ->pluck('batch')
            ->filter()
            ->sortBy('expires_on')
            ->first();

        return $batch?->expiryState() ?? 'ok';
    }

    /**
     * تكوين كود الرف من الستاند والدور، ودايماً برقمين: A3 → A03.
     * بنستخدمها في الإنشاء عشان الأكواد تبقى متسقة.
     */
    public static function buildCode(string $stand, int $level): string
    {
        return strtoupper(trim($stand)).str_pad((string) $level, 2, '0', STR_PAD_LEFT);
    }

    /** بيفك A03 → ['A', 3] — بيرجع null لو الكود مش مفهوم */
    public static function parseCode(string $code): ?array
    {
        if (! preg_match('/^([A-Za-z]+)\s*0*(\d+)$/', trim($code), $m)) {
            return null;
        }

        return [strtoupper($m[1]), (int) $m[2]];
    }
}
