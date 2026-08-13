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
        // مرساة التاريخ والوقت (١٣ أغسطس ٢٠٢٦) — شوف `dueOn`
        'starts_on', 'visit_at',
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
            // ⚠️ `starts_on` تاريخ بس (`date` مش `datetime`) — المقارنة
            // في `dueOn` بتحصل على مستوى اليوم، و`datetime` كانت
            // هتخلّي خطة اتحفظت الساعة ٤ العصر «مش مستحقة» في يومها.
            // ⚠️ **`visit_at` من غير كاست عن قصد** — MySQL بيرجّعه
            // `HH:MM:SS` نص، وأي كاست تاريخ كان هيلزقه بيوم وهمي
            // ويطلع في الفورمات غلط.
            'starts_on' => 'date',
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
     * وقت الزيارة للعرض — `h:i A` زي كل الأوقات في السيستم.
     *
     * ⚠️ بترجّع نص فاضي مش «—» عن قصد: اللي بينده عليها بيقرر يعرض
     * إيه في حالة الفراغ (بادج ولا لا شيء).
     */
    public function visitTimeLabel(): string
    {
        $raw = (string) ($this->visit_at ?? '');

        if ($raw === '') {
            return '';
        }

        try {
            return \Illuminate\Support\Carbon::parse($raw)->format('h:i A');
        } catch (\Throwable) {
            return '';
        }
    }

    /** نفس الوقت بصيغة خانة `<input type="time">` — `H:i` */
    public function visitTimeValue(): string
    {
        $raw = (string) ($this->visit_at ?? '');

        if ($raw === '') {
            return '';
        }

        try {
            return \Illuminate\Support\Carbon::parse($raw)->format('H:i');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * هل الخطة دي مستحقة في التاريخ ده؟
     *
     * ⚠️ التردد بيتحسب من **رقم أسبوع السنة**، مش من آخر زيارة.
     * الاعتماد على آخر زيارة بيخلّي المندوب اللي غاب أسبوع يفضل
     * متأخر للأبد، والخطة تزحف من يوم ليوم.
     *
     * ═══ مرساة التاريخ (١٣ أغسطس ٢٠٢٦) ═══
     * المالك بيختار «تاريخ أول زيارة» من الشاشة الجغرافية، فالخطة
     * بقى ليها `starts_on` اختياري بمعنيين:
     *
     * 1. **مفيش استحقاق قبل التاريخ ده** — خطة اتعملت النهارده لأول
     *    زيارة الشهر الجاي مالهاش تطلع في خطة بكرة.
     * 2. **مرساة التردد بتبقى أسبوعه هو** مش `epoch()` — من غير كده
     *    خطة «كل أسبوعين» بتاريخ بداية في أسبوع فردي كانت هتزحف
     *    أسبوع كامل عن اليوم اللي المالك اختاره بالظبط، وهو شايف
     *    التاريخ قدامه في الشاشة.
     *
     * ⚠️ **الخطط القديمة (`starts_on = null`) مالهاش أي تغيير**:
     * الفحص بيتخطى والمرساة بتفضل `epoch()` الثابتة.
     */
    public function dueOn(\Illuminate\Support\Carbon $date): bool
    {
        if (! $this->active || $date->dayOfWeek !== $this->weekday) {
            return false;
        }

        $start = $this->startsOnDate();

        if ($start !== null && $date->copy()->startOfDay()->lt($start)) {
            return false;
        }

        if ($this->every_weeks <= 1) {
            return true;
        }

        // ⚠️ **مش `weekOfYear`.** السنة ممكن تبقى 53 أسبوع، فخطة كل
        // أسبوعين مستحقة في الأسبوع 52 بتستحق تاني في أسبوع 2 من
        // السنة اللي بعدها — 3 أسابيع فجوة والمحل بيفضل من غير زيارة.
        // العد من نقطة ثابتة بيدي إيقاع منتظم للأبد.
        $anchor = $start !== null ? $start->copy()->startOfWeek() : self::epoch();

        $weeks = (int) floor($date->copy()->startOfWeek()->diffInWeeks($anchor));

        return abs($weeks) % $this->every_weeks === 0;
    }

    /**
     * `starts_on` كتاريخ بداية اليوم — أو null.
     *
     * ⚠️ الميثود دي موجودة عشان `nextDue()` بتبني نسخة بـ`new self([...])`
     * من غير ما تعدّي على الداتابيز، والكاست بيشتغل في الحالتين —
     * بس القيمة ممكن تكون نص خام لو حد ملا الخاصية بإيده.
     */
    private function startsOnDate(): ?\Illuminate\Support\Carbon
    {
        $raw = $this->starts_on;

        if ($raw === null || $raw === '') {
            return null;
        }

        if ($raw instanceof \DateTimeInterface) {
            return \Illuminate\Support\Carbon::instance($raw)->startOfDay();
        }

        try {
            return \Illuminate\Support\Carbon::parse((string) $raw)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
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
