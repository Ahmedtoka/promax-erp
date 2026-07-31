<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * قراية عداد — يوم واحد
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ جدول منفصل عن `vehicle_assignments` عن قصد. القراية بتتاخد
 * يومياً (بداية اليوم ونهايته) والتسكين بيفضل شهور — لو دمجناهم
 * مانقدرش نحسب كيلومترات يوم واحد ولا نعرف اليوم اللي العربية
 * مشيت فيه ضعف المعتاد.
 */
class OdometerReading extends Model
{
    public const KINDS = ['start', 'end', 'manual'];

    protected $fillable = [
        'vehicle_id', 'user_id', 'custody_id', 'read_on', 'km', 'kind', 'note',
    ];

    protected function casts(): array
    {
        return [
            'read_on' => 'date',
            'km' => 'integer',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function kindLabel(): string
    {
        return __('fleet.reading_'.$this->kind);
    }

    /**
     * سجّل قراية.
     *
     * ⚠️ **بتحدّث عداد العربية كمان.** القراية اللي بتتخزن ومابتحرّكش
     * `vehicles.odometer` بتخلّي الشاشة تقول رقم والتقرير يقول رقم
     * تاني — وده بالظبط اللي بيخلّي حد يقول «الأرقام مش مظبوطة».
     *
     * @return string|null رسالة الخطأ، أو `null` لو تمّ
     */
    public static function record(
        Vehicle $vehicle,
        int $km,
        string $kind = 'manual',
        ?User $by = null,
        ?string $date = null,
        ?string $note = null,
    ): ?string {
        if (! in_array($kind, self::KINDS, true)) {
            return __('fleet.bad_reading_kind');
        }

        // ⚠️ العداد مابيرجعش لورا — إلا لو اتغيّر، وده قرار إنسان.
        // الرقم الأقل بيتقفل هنا عشان مايخربش كل الحسابات اللي بعده.
        if ($km < (int) $vehicle->odometer) {
            return __('fleet.odometer_went_back', [
                'now' => number_format((float) $vehicle->odometer),
                'new' => number_format((float) $km),
            ]);
        }

        // ⚠️ سقف عقلاني: قفزة أكتر من 2000 كيلو في قراية واحدة يعني
        // غلطة كتابة (صفر زيادة). عربية توزيع داخل القاهرة بتعمل
        // 150 كيلو في اليوم على الأكتر.
        if ((int) $vehicle->odometer > 0 && ($km - (int) $vehicle->odometer) > 2000) {
            return __('fleet.odometer_jump', [
                'diff' => number_format((float) ($km - (int) $vehicle->odometer)),
            ]);
        }

        DB::transaction(function () use ($vehicle, $km, $kind, $by, $date, $note) {
            static::updateOrCreate(
                [
                    'vehicle_id' => $vehicle->id,
                    'read_on' => $date ?: now()->toDateString(),
                    'kind' => $kind,
                ],
                [
                    'km' => $km,
                    'user_id' => $by?->id ?? $vehicle->driver_id,
                    'note' => $note,
                ],
            );

            $vehicle->update(['odometer' => $km, 'odometer_at' => now()]);
        });

        return null;
    }
}
