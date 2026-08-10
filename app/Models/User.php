<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    // ⚠️ أسماء الفريق بتظهر في كل شاشة فيها «المندوب» — من غير
    // الترايت دي بتفضل عربي جوه الواجهة الإنجليزية.
    use \App\Models\Concerns\HasBilingualName;

    use HasFactory, Notifiable;

    public const ROLES = [
        'admin' => 'أدمن',
        'manager' => 'Channel Manager',
        'branch_manager' => 'مدير فرع',
        'accountant' => 'محاسب',
        'warehouse_keeper' => 'أمين مخزن',
        'sales_agent' => 'سيلز إيجينت',
        'driver' => 'سواق توزيع',
        'promoter' => 'بروموتر',
    ];

    /**
     * الرولز اللي بتدير — بتشوف أرقام وبتقرر.
     *
     * ⚠️ `branch_manager` هنا، بس صلاحيته **مقيّدة بفرعه**. الفرق
     * بينه وبين `manager`: الأخير بيشوف الشركة كلها والأول فرعه بس.
     */
    public const MANAGER_ROLES = ['admin', 'manager', 'branch_manager'];

    /**
     * الرولز اللي بتظهر في دروب داونز التوزيع والتسكين.
     *
     * ⚠️ **من غير الأدمن** (قرار المالك 2026-08-05): «شيل الأدمنز من
     * الدروب داون — خليهم بس الموظفين في كل السيستم». الأدمن بيدير
     * مش بيتوزّع عليه شغل. (كان قبل كده بيظهر عشان أكبر العملاء كانوا
     * على الأدمن نفسه — القرار اتغيّر مع دخول التشانل مانجرز.)
     */
    public const ASSIGNABLE_MANAGER_ROLES = ['manager', 'branch_manager'];

    /**
     * رولز المكتب — بتدخل الويب وماتنزلش الشارع.
     *
     * ⚠️ **المحاسب وأمين المخزن مش `MANAGER_ROLES`.** لو حطّيناهم هناك،
     * `isManager()` بترجع true وبيفتحوا كل شاشة بتفحص بيها — وده أوسع
     * بكتير من شغلهم. صلاحيتهم بتتحدد من `App\Support\Access` وبس.
     */
    public const OFFICE_ROLES = ['accountant', 'warehouse_keeper'];

    /** الرولز اللي بتنزل الشارع */
    public const FIELD_ROLES = ['sales_agent', 'driver', 'promoter'];

    protected $fillable = [
        'name', 'name_en', 'email', 'password', 'role', 'code', 'phone',
        'zone_id', 'channel_id', 'branch_id', 'warehouse_id', 'active', 'locale',
        'manager_id', 'avatar_path',
    ];

    /** اللغات المدعومة — أي قيمة تانية بترجع للافتراضي */
    public const LOCALES = ['en' => 'English', 'ar' => 'العربية'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
        ];
    }

    // ---------- Relations ----------

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    /** قناة المندوب */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** القنوات اللي المدير مسئول عنها */
    /**
     * المناطق اللي اليوزر مسؤول عنها — مدير القنوات بياخد أكتر من زون.
     * ⚠️ ده غير zone() المفردة اللي هي زون المندوب النهارده.
     */
    public function zones(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        // ⚠️ **اسم الجدول لازم يتكتب صريح.** لارافيل بيخمّنه بترتيب
        // أبجدي (`user_zone`)، والجدول الحقيقي اسمه `zone_user`
        // (مايجريشن 000010). من غير التصريح، أي استخدام للعلاقة
        // بيرمي «Table 'user_zone' doesn't exist» — ومابيبانش غير
        // وقت التشغيل لأن مفيش حاجة بتفحص أسماء الجداول.
        // `Zone::reps()` مصرّحة بيه أصلاً، فالاتجاهين لازم يتطابقوا.
        return $this->belongsToMany(Zone::class, 'zone_user')
            ->withPivot('visit_day')
            ->withTimestamps();
    }

    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class)->withTimestamps();
    }

    public function merchVisits(): HasMany
    {
        return $this->hasMany(MerchVisit::class)->latest();
    }

    public function replenishmentRequests(): HasMany
    {
        return $this->hasMany(ReplenishmentRequest::class, 'requested_by')->latest();
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(ApiToken::class);
    }

    public function custodies(): HasMany
    {
        return $this->hasMany(Custody::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'assigned_to');
    }

    public function trackEvents(): HasMany
    {
        return $this->hasMany(TrackEvent::class)->orderByDesc('happened_at');
    }

    public function appNotifications(): HasMany
    {
        return $this->hasMany(AppNotification::class)->latest();
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(UserPermission::class);
    }

    /** التشانل مانجر اللي الموظف الميداني متسكّن له (2026-08-05) */
    public function teamManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function teamMembers(): HasMany
    {
        return $this->hasMany(User::class, 'manager_id');
    }

    /**
     * سكوب فريق التشانل مانجر — زي `Client::visibleTo` بالظبط:
     * المدير بيشوف المناديب والسواقين المتسكّنين له بس، وغيره
     * بيعدّي من غير فلترة. تتحط على أي كويري بيجيب فريق الميدان.
     */
    public static function fieldVisibleTo($query, ?User $viewer = null)
    {
        $viewer = $viewer ?? auth()->user();

        if ($viewer !== null && $viewer->role === 'manager') {
            $query->where('manager_id', $viewer->id);
        }

        return $query;
    }

    /**
     * خريطة استثناءات الصلاحيات — perm => allow.
     *
     * ⚠️ **ميمو للريكوست الواحد.** السايدبار بينادي allows() لكل لينك
     * (40+ مرة في الصفحة) — من غير الكاش دي 40 كويري على نفس الجدول.
     *
     * @return array<string, bool>
     */
    public function permMap(): array
    {
        return $this->permMapCache ??= $this->permissions()
            ->pluck('allow', 'perm')->map(fn ($v) => (bool) $v)->all();
    }

    private ?array $permMapCache = null;

    // ---------- Helpers ----------

    /**
     * صورة الموظف (٩ أغسطس ٢٠٢٦) — null لو لسه مارفعش، والواجهات
     * بتقع على دايرة بحروف اسمه (`initials()`). التراكينج وكروت
     * الحضور والأبلكيشن كلهم بيقروا من هنا.
     */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path
            ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->avatar_path)
            : null;
    }

    /** حروف الاسم للدايرة البديلة — أول حرف من أول كلمتين */
    public function initials(): string
    {
        $words = preg_split('/\s+/', trim($this->displayName())) ?: [];
        $take = array_slice(array_filter($words), 0, 2);

        return implode('', array_map(
            fn ($w) => mb_substr($w, 0, 1),
            $take,
        )) ?: '؟';
    }

    public function roleLabel(): string
    {
        // المسمى بييجي من lang/{ar,en}/enums.php — والثابت القديم fallback
        $key = 'enums.role.'.$this->role;

        return \Illuminate\Support\Facades\Lang::has($key)
            ? __($key)
            : (self::ROLES[$this->role] ?? $this->role);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isManager(): bool
    {
        return in_array($this->role, self::MANAGER_ROLES, true);
    }

    /** مدير فرع — بيدير، بس في فرعه بس */
    public function isBranchManager(): bool
    {
        return $this->role === 'branch_manager';
    }

    /**
     * هل بيشوف كل الفروع؟
     *
     * ⚠️ الأدمن ومدير القنوات بيشوفوا الشركة كلها. مدير الفرع
     * وأي موظف متخصص لفرع بيشوفوا فرعهم + المركزي بس.
     */
    public function seesAllBranches(): bool
    {
        if ($this->isAdmin() || $this->role === 'manager') {
            return true;
        }

        // ⚠️ **مدير الفرع مايشوفش الكل أبداً**، حتى لو فرعه فاضي.
        // من غير السطر ده، مدير فرع اتعمل من غير `branch_id` بيبقى
        // قارئ للشركة كلها في صمت — وده بالظبط اللي الرول اتعمل
        // عشان يمنعه.
        if ($this->isBranchManager()) {
            return false;
        }

        // موظف من غير فرع = مركزي = بيشوف الكل
        return $this->branch_id === null;
    }

    /**
     * هل اليوزر مسموح له يشوف الصف ده؟
     *
     * ⚠️ للشاشات اللي بتفتح سجل واحد بالـ id (كارت عميل، كارت
     * مندوب، فاتورة). فلترة القايمة بتخبّي الصف عن العين، مش عن
     * الراوت — أي حد بيعرف الـ id بيوصله.
     */
    public function canSeeBranch(?int $branchId): bool
    {
        return $this->seesAllBranches()
            || $branchId === null
            || $branchId === $this->branch_id;
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** العربية المخصصة له مندوباً أو سواقاً */
    public function vehicle(): ?Vehicle
    {
        return Vehicle::where('active', true)
            ->where(fn ($q) => $q->where('rep_id', $this->id)->orWhere('driver_id', $this->id))
            ->first();
    }

    /** سيلز إيجينت — بيفتح أكاونتات وبيبيع */
    /** محاسب — بيقفل حسابات ومابيحركش بضاعة */
    public function isAccountant(): bool
    {
        return $this->role === 'accountant';
    }

    /**
     * أمين مخزن — بيشتغل على **مخزنه هو** بس.
     *
     * ⚠️ `warehouse_id` مش مجرد بيان. أمين مخزن المعادي اللي بيفتح
     * مخزن المصنع ممكن يعمل جرد أو يصرف من رصيد مش بتاعه، والفرق
     * بيطلع بعد أسبوع في تسوية محدش عارف مصدرها.
     */
    public function isWarehouseKeeper(): bool
    {
        return $this->role === 'warehouse_keeper';
    }

    public function warehouse(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * ═══ بوابات الأزرار في الشاشات ═══
     *
     * ⚠️ **`isManager()` ماكانتش كفاية.** الشاشات كلها كانت بتقول
     * `$manager = auth()->user()->isManager()` وتخبّي كل أزرار
     * الكتابة وراها. النتيجة بعد الرولز الجديدة:
     *
     *   • أمين المخزن اتديله راوتس الاستلام والترصيف والتجهيز —
     *     ومشفش ولا زرار. مخزن للقراية بس.
     *   • المحاسب اتديله التحصيل والمستحقات — ومشفش ولا زرار.
     *   • مدير الفرع `isManager()` بترجّع له true، فشاف أزرار
     *     أوامر التوريد وقرارات الطلبات — وكلها `role:admin,manager`
     *     يعني 403 أول ما يدوس.
     *
     * الدوال دي بتخلّي الزرار يبان **بالظبط** لمين الراوت بيسمح له.
     */

    /** بيشتغل في المخزن: استلام، ترصيف، نقل، تجهيز */
    public function canWorkWarehouse(): bool
    {
        return in_array($this->role, ['admin', 'manager', 'warehouse_keeper'], true);
    }

    /** بيحرّك فلوس: تحصيل، رصيد افتتاحي، مستحقات */
    public function canWorkMoney(): bool
    {
        return in_array($this->role, ['admin', 'manager', 'accountant'], true);
    }

    /**
     * بيقرر في العمليات: ينزّل أمر توريد، يوافق على عميل، يحمّل عربية.
     *
     * ⚠️ **مدير الفرع مش هنا.** الراوتس دي `role:admin,manager` وبس،
     * فلو وّرناهوله الزرار بيدوس ويترمي على 403 بعد ما يملا الفورم.
     */
    public function canDecideOps(): bool
    {
        return in_array($this->role, ['admin', 'manager'], true);
    }

    public function isSalesAgent(): bool
    {
        return $this->role === 'sales_agent';
    }

    /** سواق — بيوصّل أوامر التوريد */
    public function isDriver(): bool
    {
        return $this->role === 'driver';
    }

    /** بروموتر — بيعمل ريفيل للرفوف */
    public function isPromoter(): bool
    {
        return $this->role === 'promoter';
    }

    public function isFieldUser(): bool
    {
        return in_array($this->role, self::FIELD_ROLES, true);
    }

    /** أكواد القنوات اللي المستخدم مسموحله يتحكم فيها */
    public function managedChannelIds(): array
    {
        if ($this->isAdmin()) {
            return Channel::pluck('id')->all();
        }

        $ids = $this->channels()->pluck('channels.id')->all();

        if (! $ids && $this->channel_id) {
            $ids = [$this->channel_id];
        }

        return $ids;
    }

    /** هل يقدر يتحكم في القناة دي؟ */
    public function managesChannel(?int $channelId): bool
    {
        if ($this->isAdmin()) {
            return true;
        }
        if ($channelId === null) {
            return false;
        }

        return in_array($channelId, $this->managedChannelIds(), true);
    }

    /** عهدة النهارده */
    public function todayCustody(): ?Custody
    {
        return $this->custodies()->whereDate('date', today())->first();
    }

    /**
     * ⚠️⚠️ **العهدة الحالية — مش عهدة النهارده** (إصلاح ١٠ أغسطس ٢٠٢٦).
     *
     * العهدة اللي اتحمّلت امبارح ولسه **مفتوحة** كانت بتختفي من كل
     * الشاشات الساعة ١٢ بالليل — المندوب يفتح الصبح يلاقي «مفيش عهدة»
     * وأوامر التوريد تقول «ناقص» والبضاعة **في عربيته فعلياً**.
     * التاريخ مش هو اللي بينهي العهدة — **القفل** هو اللي بينهيها
     * (`closeCustody` من شاشة المندوب).
     *
     * الترتيب: عهدة النهارده لو موجودة (حتى لو مقفولة — يومه اتقفل)،
     * وإلا آخر عهدة لسه مفتوحة من الأيام اللي فاتت.
     *
     * ⚠️ `status != 'closed'` في MySQL بتستبعد الـNULL — الصفوف
     * القديمة اللي من غير status لازم `whereNull` صريحة.
     *
     * **كل قراية إنتاجية تستخدم دي** — `todayCustody()` فضلت
     * للسيدرز ولأي حساب مقيد بيوم بعينه.
     */
    public function currentCustody(): ?Custody
    {
        return $this->todayCustody()
            ?? $this->custodies()
                ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '<>', 'closed'))
                ->orderByDesc('date')
                ->first();
    }

    /** الزيارة المفتوحة حالياً */
    public function openVisit(): ?Visit
    {
        return $this->visits()->whereNull('checked_out_at')->latest()->first();
    }

    public function issueToken(string $name = 'mobile'): ApiToken
    {
        return $this->tokens()->create([
            'name' => $name,
            'token' => Str::random(64),
        ]);
    }
}
