<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * تسكين عربية على سواق — بالتاريخ
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **`vehicles.driver_id` عمود «دلوقتي» مش تاريخ.** كل نقل بيدوس
 * على اللي قبله. الجدول ده بيخلّي السؤال «العربية دي كانت مع مين
 * الشهر اللي فات ومشيت كام؟» ليه إجابة.
 *
 * `to_date = null` معناها التسكين الجاري. والقاعدة اللي بتحكم كل
 * حاجة: **عربية واحدة = سواق واحد في أي لحظة**.
 */
class VehicleAssignment extends Model
{
    protected $fillable = [
        'vehicle_id', 'user_id', 'from_date', 'to_date',
        'odometer_start', 'odometer_end', 'note',
    ];

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
            'odometer_start' => 'integer',
            'odometer_end' => 'integer',
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

    /** لسه شغّال؟ */
    public function isOpen(): bool
    {
        return $this->to_date === null;
    }

    /**
     * كيلومترات الفترة.
     *
     * ⚠️ بترجع `null` مش صفر لو لسه مفتوح أو العداد ماتقراش. الصفر
     * معناه «مشيش»، و`null` معناها «مانعرفش» — والاتنين بيتعرضوا
     * مختلف. لو رجّعنا صفر، تقرير الكيلومترات بيقول إن العربية واقفة
     * وهي شغالة والقراية بس ناقصة.
     */
    public function distance(): ?int
    {
        $end = $this->odometer_end ?? ($this->isOpen() ? $this->vehicle?->odometer : null);

        if ($end === null) {
            return null;
        }

        // ⚠️ العداد مابيرجعش لورا. الفرق السالب معناه قراية غلط
        // (حد كتب 12000 مكان 21000) — بنرجّع null بدل رقم بالسالب
        // يتجمع في التقرير ويقلّل إجمالي الأسطول.
        $diff = (int) $end - (int) $this->odometer_start;

        return $diff >= 0 ? $diff : null;
    }

    /**
     * سكّن عربية على سواق — وبيقفل التسكين اللي قبله.
     *
     * ⚠️ **جوه ترانزاكشن.** القفل والفتح لازم يحصلوا مع بعض: لو
     * القفل نجح والفتح فشل، العربية بتفضل من غير سواق ومحدش واخد
     * باله. ولو الفتح نجح والقفل فشل، بيبقى سواقين على نفس العربية
     * في نفس اليوم — وعهدة اليوم بتتحسب مرتين.
     *
     * @return string|null رسالة الخطأ، أو `null` لو تمّ
     */
    public static function assign(
        Vehicle $vehicle,
        ?User $driver,
        ?int $odometer = null,
        ?string $note = null,
    ): ?string {
        $km = $odometer ?? (int) $vehicle->odometer;

        // ⚠️ العداد مايقلّش عن آخر قراية. لو قلّ، يبقى إما غلطة كتابة
        // أو عربية اتغير عدادها — والاتنين محتاجين قرار إنسان مش
        // تسجيل صامت بيخرّب كل الكيلومترات اللي بعده.
        if ($km < (int) $vehicle->odometer) {
            return __('fleet.odometer_went_back', [
                'now' => number_format((float) $vehicle->odometer),
                'new' => number_format((float) $km),
            ]);
        }

        // ⚠️ **نفس حارس القفزة اللي في `OdometerReading::record()`.**
        // من غيره، صفر زيادة واحد هنا (250,000 → 2,500,000) بيقفل
        // العربية للأبد: كل قراية حقيقية بعد كده بتبقى «أقل من الحالي»
        // وبترفض، ومفيش طريقة ترجّع الرقم غير الداتابيز.
        if ((int) $vehicle->odometer > 0 && ($km - (int) $vehicle->odometer) > 2000) {
            return __('fleet.odometer_jump', [
                'diff' => number_format((float) ($km - (int) $vehicle->odometer)),
            ]);
        }

        if ($driver !== null && ! $driver->isDriver() && ! $driver->isSalesAgent()) {
            return __('fleet.not_a_driver');
        }

        DB::transaction(function () use ($vehicle, $driver, $km, $note) {
            // ⚠️ `lockForUpdate` عشان ريكوستين مايسكّنوش سواقين على
            // نفس العربية في نفس اللحظة.
            $open = static::where('vehicle_id', $vehicle->id)
                ->whereNull('to_date')
                ->lockForUpdate()
                ->get();

            foreach ($open as $row) {
                // ⚠️ نفس السواق؟ الصف بيفضل مفتوح زي ما هو — مابنقفلوش
                // ومابنعملش صف جديد. لو قفلناه وفتحنا واحد، تاريخ
                // تسكينه كان هيتقطّع لصفوف بيوم واحد كل ما الأمر يتشغّل.
                if ($driver !== null && (int) $row->user_id === (int) $driver->id) {
                    continue;
                }

                $row->update([
                    'to_date' => now()->toDateString(),
                    'odometer_end' => $km,
                ]);
            }

            $stillOpen = $driver !== null && $open->contains(
                fn (self $r) => (int) $r->user_id === (int) $driver->id
            );

            if ($driver !== null && ! $stillOpen) {
                static::create([
                    'vehicle_id' => $vehicle->id,
                    'user_id' => $driver->id,
                    'from_date' => now()->toDateString(),
                    'odometer_start' => $km,
                    'note' => $note,
                ]);
            }

            // ⚠️ العمود القديم بيتحدّث برضه. كل الشاشات والكويريات
            // القديمة لسه بتقرا منه، وسيبه قديم معناه شاشتين بيقولوا
            // اسمين مختلفين لنفس العربية.
            $vehicle->update([
                'driver_id' => $driver?->id,
                'odometer' => $km,
                'odometer_at' => now(),
            ]);
        });

        return null;
    }
}
