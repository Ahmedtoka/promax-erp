<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * خط سير: عميل × يوم × مندوب.
 *
 * ⚠️ الخطة **نمط أسبوعي**، مش تواريخ. صف واحد معناه «المندوب ده
 * بيزور العميل ده كل يوم اتنين». زيارات يوم معيّن بتتولّد من النمط
 * وقت ما تتطلب، ومابتتخزنش.
 */
class JourneyPlan extends Model
{
    protected $fillable = [
        'user_id', 'client_id', 'weekday', 'every_weeks', 'sort', 'active', 'notes',
    ];

    /**
     * ⚠️ الترقيم هنا **لازم** يطابق `Carbon::dayOfWeek`:
     * 0 = الأحد ... 6 = السبت. أي ترقيم تاني بيخلّي زيارات النهارده
     * تطلع بيوم غلط، وده بيتكشف بعد أسبوع مش في التيست.
     */
    public const WEEKDAYS = [0, 1, 2, 3, 4, 5, 6];

    /** كل أسبوع / أسبوع ورا أسبوع / مرة في الشهر */
    public const FREQUENCIES = [1, 2, 4];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'every_weeks' => 'integer',
            'sort' => 'integer',
            'active' => 'boolean',
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

    /** نقطة صفر ثابتة لحساب التردد — أول أسبوع في 2026 */
    private static function epoch(): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Carbon::create(2026, 1, 5)->startOfWeek();
    }

    public function weekdayLabel(): string
    {
        return __('journey.day_'.$this->weekday);
    }

    public function frequencyLabel(): string
    {
        return __('journey.freq_'.$this->every_weeks);
    }

    /**
     * هل الخطة دي مستحقة في التاريخ ده؟
     *
     * ⚠️ التردد بيتحسب من **رقم أسبوع السنة**، مش من آخر زيارة.
     * الاعتماد على آخر زيارة بيخلّي المندوب اللي غاب أسبوع يفضل
     * متأخر للأبد، والخطة تزحف من يوم ليوم.
     */
    public function dueOn(\Illuminate\Support\Carbon $date): bool
    {
        if (! $this->active || $date->dayOfWeek !== $this->weekday) {
            return false;
        }

        if ($this->every_weeks <= 1) {
            return true;
        }

        // ⚠️ **مش `weekOfYear`.** السنة ممكن تبقى 53 أسبوع، فخطة كل
        // أسبوعين مستحقة في الأسبوع 52 بتستحق تاني في أسبوع 2 من
        // السنة اللي بعدها — 3 أسابيع فجوة والمحل بيفضل من غير زيارة.
        // العد من نقطة ثابتة بيدي إيقاع منتظم للأبد.
        $weeks = (int) floor($date->copy()->startOfWeek()->diffInWeeks(self::epoch()));

        return abs($weeks) % $this->every_weeks === 0;
    }

    /**
     * أول تاريخ مستحق لنمط (يوم × تردد) — لمعاينة «أول زيارة».
     *
     * ⚠️ **مش تاريخ بيتخزن.** المالك بيقول «أختار تاريخ زيارة»،
     * والسيستم نمط أسبوعي — فبنعرض له التاريخ اللي النمط بتاعه
     * هيقع عليه فعلاً. اختراع جدول تواريخ لمرة واحدة كان هيعمل
     * مصدرين لخط السير، وأول ما الاتنين يختلفوا محدش يعرف مين الصح.
     *
     * ⚠️ الحساب من `dueOn` نفسها مش من معادلة جديدة — التردد له
     * نقطة صفر ثابتة (`epoch`)، وأي نسخة تانية من المنطق بتفترق
     * عنها أول ما تتعدّل.
     *
     * السقف 35 يوم: أطول تردد شهري (4 أسابيع = 28 يوم) + هامش يوم
     * الأسبوع، فأي نمط صالح لازم يقع جواها.
     */
    public static function nextDue(int $weekday, int $everyWeeks, ?\Illuminate\Support\Carbon $from = null): ?\Illuminate\Support\Carbon
    {
        $probe = new self([
            'weekday' => $weekday,
            'every_weeks' => $everyWeeks,
            'active' => true,
        ]);

        $date = ($from ? $from->copy() : today())->startOfDay();

        for ($i = 0; $i < 35; $i++) {
            if ($probe->dueOn($date)) {
                return $date;
            }

            $date->addDay();
        }

        return null;
    }
}
