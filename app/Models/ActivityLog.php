<?php

namespace App\Models;

use App\Support\Access;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * سجل حركة اليوزرات — مين عمل إيه وإمتى.
 *
 * ⚠️ **الكتابة هنا ممنوع تفشّل العملية الأصلية.** لو التسجيل رمى
 * استثناء (الجدول لسه مااتعملش، عمود ناقص، الديسك اتملى) العميل
 * اللي المستخدم بيحفظه كان هيضيع. كل النداءات ملفوفة `rescue`.
 */
class ActivityLog extends Model
{
    protected $fillable = [
        'user_id', 'user_name', 'role', 'event', 'subject_type', 'subject_id',
        'title', 'changes', 'url', 'method', 'route', 'status', 'ip', 'agent',
    ];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    /** الأحداث اللي بتتسجل — للفلاتر والترجمة */
    public const EVENTS = ['created', 'updated', 'deleted', 'login', 'logout', 'viewed', 'action'];

    /** الأحداث اللي معناها «شغل فعلي» مش مجرد فتح صفحات */
    public const WORK = ['created', 'updated', 'deleted', 'action'];

    /**
     * حدود الحالة بالدقايق (مركز النشاط ٢٢/٩): آخر حركة من أقل من 5 دقايق
     * = شغال دلوقتي، لحد 30 = فاتح وساكت، وبعدها بره. ونفس الـ30 هي اللي
     * بتقفل «الجلسة»: فجوة أطول منها بين حركتين = قام ورجع.
     */
    public const ACTIVE_MIN = 5;

    public const IDLE_MIN = 30;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** عمودين ٢٢/٩ موجودين؟ الملفات ممكن تترفع قبل `migrate` — مرة واحدة لكل طلب */
    public static function routeReady(): bool
    {
        static $ready = null;

        return $ready ??= (bool) rescue(fn () => Schema::hasColumn('activity_logs', 'route'), false, false);
    }

    /**
     * تسجيل حدث — الطريقة الوحيدة للكتابة.
     *
     * @param  array<string, mixed>  $extra
     */
    public static function record(string $event, array $extra = []): void
    {
        rescue(function () use ($event, $extra) {
            $user = Auth::user();
            $request = request();

            $row = [
                'user_id' => $user?->id,
                'user_name' => $user?->name,
                'role' => $user?->role,
                'event' => $event,
                'url' => $request?->path() ? mb_substr((string) $request->path(), 0, 300) : null,
                'method' => $request?->method(),
                'ip' => $request?->ip(),
                'agent' => mb_substr((string) $request?->userAgent(), 0, 200),
            ];

            if (self::routeReady()) {
                $row['route'] = mb_substr((string) $request?->route()?->getName(), 0, 120) ?: null;
            } else {
                unset($extra['route'], $extra['status']);
            }

            static::create($extra + $row);
        }, null, false);
    }

    /** وصف مقروء للحدث — بيستخدم في الشاشة والتصدير */
    public function label(): string
    {
        $subject = $this->subject_type
            ? __('audit.model_'.$this->subject_type, [], null) : null;

        return trim(($subject ?: $this->subject_type ?: '').' '.($this->title ?: ''));
    }

    /** كام حقل اتغير فعلاً */
    public function changedCount(): int
    {
        return is_array($this->changes) ? count($this->changes) : 0;
    }

    // ═══════════════ القسم والشاشة (مركز النشاط ٢٢/٩) ═══════════════

    /**
     * اسم راوت الحركة. الصفوف القديمة (قبل عمود `route`) بنستنتجه: صف
     * «فتح صفحة» اسمه متخزن في `title`، والباقي بنطابق الـURL على الراوتر.
     */
    public function routeName(): ?string
    {
        if (! empty($this->attributes['route'])) {
            return $this->attributes['route'];
        }

        if ($this->event === 'viewed' && $this->title && ! str_contains($this->title, '/')) {
            return $this->title;
        }

        return self::routeOfUrl((string) $this->url, (string) ($this->method ?: 'GET'));
    }

    /** @var array<string, ?string> */
    private static array $urlCache = [];

    public static function routeOfUrl(string $url, string $method = 'GET'): ?string
    {
        if ($url === '') {
            return null;
        }

        $key = $method.' '.$url;

        if (array_key_exists($key, self::$urlCache)) {
            return self::$urlCache[$key];
        }

        $name = rescue(fn () => Route::getRoutes()
            ->match(Request::create('/'.ltrim($url, '/'), $method))->getName(), null, false);

        return self::$urlCache[$key] = $name ?: null;
    }

    /** مفتاح قسم المنيو (`nav.group_*`)، أو `app` لحركات الأبلكيشن، أو null */
    public function sectionKey(): ?string
    {
        return self::sectionOf($this->routeName(), (string) $this->url);
    }

    public static function sectionOf(?string $route, string $url = ''): ?string
    {
        if (str_starts_with($url, 'api/')) {
            return 'app';
        }

        return $route ? (self::navOf($route)[0] ?? null) : null;
    }

    /** @var array<string, ?array{0:string,1:string}> */
    private static array $navCache = [];

    /**
     * [القسم، مفتاح اسم الشاشة] لراوت — بأطول «نمط تفعيل» في المنيو بيطابقه.
     *
     * ⚠️ مش `Access::groupOf`: دي بتطابق على اسم راوت اللينك نفسه، فـ`erp.reports.show`
     * (تحت لينك `erp.reports.hub`) كانت بتطلع من غير قسم. نمط التفعيل (`erp.reports*`)
     * هو اللي بيقول السايدبار ينوّر أنهي لينك — فهو تعريف «الشاشة دي تبع مين».
     *
     * @return ?array{0:string,1:string}
     */
    public static function navOf(string $route): ?array
    {
        if (array_key_exists($route, self::$navCache)) {
            return self::$navCache[$route];
        }

        $best = null;
        $len = -1;

        foreach (Access::NAV as $group => $links) {
            foreach ($links as $link) {
                foreach ([$link[3], $link[0], $link[0].'.*'] as $pattern) {
                    if (strlen($pattern) > $len && \Illuminate\Support\Str::is($pattern, $route)) {
                        $best = [$group, $link[2]];
                        $len = strlen($pattern);
                    }
                }
            }
        }

        return self::$navCache[$route] = $best;
    }

    public static function sectionLabel(?string $key): string
    {
        return match (true) {
            $key === null => __('activity.sec_other'),
            $key === 'app' => __('activity.sec_app'),
            default => __($key),
        };
    }

    /** اسم الشاشة زي ما هو مكتوب في المنيو — وإلا اسم الراوت نفسه */
    public function screenLabel(): string
    {
        $route = $this->routeName();

        if ($route === null) {
            return (string) $this->url;
        }

        $nav = self::navOf($route);

        return $nav ? __($nav[1]) : $route;
    }

    /** الحالة من آخر حركة: active | idle | away */
    public static function stateOf(?\DateTimeInterface $last): string
    {
        if ($last === null) {
            return 'away';
        }

        $min = now()->diffInMinutes($last, true);

        return $min < self::ACTIVE_MIN ? 'active' : ($min < self::IDLE_MIN ? 'idle' : 'away');
    }
}
