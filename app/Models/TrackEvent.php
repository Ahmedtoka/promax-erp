<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrackEvent extends Model
{
    use HasFactory;

    /**
     * أنواع الأحداث — [المسمى الافتراضي، اللون، الأيقونة].
     *
     * ⚠️ **أي نوع بيتسجّل في الكود لازم يبقى هنا.** `return` و`open`
     * كانوا بيتسجّلوا من شهور وهما مش في القايمة دي — فكانوا بيطلعوا
     * في غرفة التحكم بلون رمادي واسم إنجليزي خام (`return`) وشكلهم
     * زي حدث بايظ. `color()` و`icon()` بيرجّعوا الافتراضي بصمت، فالغلط
     * ده مابيعملش أي خطأ — بس بيخفي حركة المندوب.
     *
     * الأيقونة إيموچي عن قصد: بتترسم في HTML وفي الأبلكيشن من غير
     * أي أصول ولا تعريفات ألوان.
     */
    public const TYPES = [
        'start' => ['استلم عهدة', '#7C3AED', '📦'],
        'open' => ['فتح الأبلكيشن', '#6366F1', '📱'],
        'check_in' => ['دخل عند عميل', '#2563EB', '📍'],
        'check_out' => ['خرج من عميل', '#DC2626', '🚪'],
        'sale' => ['باع', '#16A34A', '💰'],
        'return' => ['مرتجع', '#B45309', '↩️'],
        'gift' => ['هدية', '#DB2777', '🎁'],
        'deliver' => ['سلّم أمر توريد', '#0F766E', '🚚'],
        'request' => ['طلب عميل جديد', '#EA8C1C', '🆕'],
        'refill' => ['ريفيل رف', '#7C3AED', '🧃'],

        // ═══ أوبشنات الزيارة التلاتة (2026-08-09) ═══
        // ⚠️ نفس الفخ الموثّق فوق اتكرر تاني: `collect` و`shelf`
        // اتسجّلوا في FieldApiController من غير ما يدخلوا هنا —
        // فكانوا بيطلعوا رمادي بأيقونة نقطة. أي نوع جديد ييجي هنا فوراً.
        'collect' => ['حصّل فلوس', '#0F766E', '🧾'],
        'shelf' => ['ترتيب رف', '#DB2777', '📷'],

        // إلغاء التسليم بسبب (١١ أغسطس ٢٠٢٦) — المندوب وصل وماعرفش
        // يسلّم؛ السبب في الـsubtitle
        'po_abort' => ['رجّع أمر من غير تسليم', '#B00020', '⛔'],

        // تصحيح إداري للعهدة (١٢ أغسطس ٢٠٢٦) — الأدمن ظبّط تحميل
        // اتسجّل غلط؛ السبب والتغييرات «قديم ← جديد» في الـsubtitle
        'custody_adjust' => ['تعديل إداري على العهدة', '#B45309', '🛠️'],

        // تحويل بضاعة من العربية (١٤ أغسطس ٢٠٢٦) — للمخزن أو لمندوب
        // تاني. الرقم والوجهة والسبب في الـsubtitle.
        'custody_transfer' => ['تحويل بضاعة من العربية', '#0F766E', '🔄'],

        // المندوب ضبط لوكيشن العميل من الأبلكيشن (١٤ أغسطس ٢٠٢٦).
        // ⚠️ الحدث ده **أدق نقطة عندنا** عن مكان المحل: المندوب سحبها
        // بقصد وهو واقف قدامه، مش نقطة تشيك إن من العربية في الطريق.
        'set_location' => ['ضبط لوكيشن عميل', '#0891B2', '🗺️'],

        // ═══ الحضور والانصراف — HR (2026-08-08) ═══
        // ⚠️ أسماء مختلفة عن `check_in/check_out` عن قصد: دول
        // بداية ونهاية **يوم الشغل**، والتانيين دخول وخروج من
        // **محل عميل**. الخلط بينهم بيبوّظ أي تقرير.
        'shift_in' => ['بدأ شغل', '#059669', '🟢'],
        'shift_break' => ['بريك', '#B86E00', '⏸️'],
        'shift_back' => ['رجع من البريك', '#2563EB', '▶️'],
        'shift_out' => ['خلّص شغل', '#7C2D12', '🔴'],

        // ═══ زيارات المخزن (2026-08-08) ═══
        // ⚠️ نوع تالت غير `check_in` و`shift_in`: ده دخول **مكان**
        // شغل مش محل عميل ولا بداية يوم. تقرير «قعد قد إيه في
        // المخزن» بيتبني عليه لوحده.
        'wh_in' => ['دخل مخزن', '#0369A1', '🏭'],
        'wh_out' => ['خرج من مخزن', '#94A3B8', '🚶'],
    ];

    protected $fillable = [
        'user_id', 'type', 'title', 'subtitle', 'lat', 'lng', 'happened_at',
    ];

    protected function casts(): array
    {
        return ['happened_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function typeLabel(): string
    {
        // المسمى بييجي من lang/{ar,en}/enums.php — والثابت القديم fallback
        $key = 'enums.track.'.$this->type;

        return \Illuminate\Support\Facades\Lang::has($key)
            ? __($key)
            : (self::TYPES[$this->type][0] ?? $this->type);
    }

    public function color(): string
    {
        return self::TYPES[$this->type][1] ?? '#6B6B66';
    }

    /** أيقونة النوع — بتترسم في غرفة التحكم وشاشة التتبع */
    public function icon(): string
    {
        return self::TYPES[$this->type][2] ?? '•';
    }

    public static function log(
        User $user,
        string $type,
        string $title,
        ?string $subtitle = null,
        ?float $lat = null,
        ?float $lng = null,
    ): self {
        // ⚠️ **القص إجباري** (بلاغ ٢٢/٨): تعديل عهدة بأصناف كتير ركّب
        // subtitle أطول من عمود VARCHAR(255) فرمى 1406 على اللايف —
        // والأمَرّ إن التعديل نفسه كان لسه مكمّل، فالمستخدم شاف 500
        // وهو فاكر إن حاجة ماتحفظتش. الحدث وصف مش مستند — قصّه أهون
        // ألف مرة من ما يرمي الشاشة كلها. القص هنا مركزي عشان يغطي
        // كل نداءات log الحالية والجاية مرة واحدة.
        return static::create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => \Illuminate\Support\Str::limit($title, 190, '…'),
            'subtitle' => $subtitle === null
                ? null
                : \Illuminate\Support\Str::limit($subtitle, 250, '…'),
            'lat' => $lat,
            'lng' => $lng,
            'happened_at' => now(),
        ]);
    }
}
