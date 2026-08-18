<?php

namespace App\Models;

use App\Models\Concerns\HasBilingualName;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Client extends Model
{
    use HasBilingualName, HasFactory;

    /** التصنيفات التجارية: [التسمية، كلاس الشارة] */
    /**
     * الاسم الكامل: السلسلة الأول وبعدين الفرع.
     *
     * ⚠️ «Katameya Heights» لوحدها ماتقولش إنه فرع جورميه — واللي
     * بيقرا الداشبورد بيفتكره عميل مستقل. أول ما العميل ليه سلسلة،
     * اسمها بييجي الأول: «Gourmet — Katameya Heights».
     */
    public function fullName(): string
    {
        $chain = $this->group?->displayName();

        return $chain && $chain !== $this->displayName()
            ? $chain.' — '.$this->displayName()
            : $this->displayName();
    }

    public const CATEGORIES = [
        'danger' => ['🔴 تحصيل فوري', 'b-red'],
        'watch' => ['🟠 تابع عن قرب', 'b-orange'],
        'grow' => ['🟢 كبّر التعامل', 'b-green'],
        'ok' => ['✅ منتظم', 'b-blue'],
        'idle' => ['⚪ خامل', 'b-gray'],
        'internal' => ['🚚 قناة داخلية', 'b-purple'],
        'credit' => ['🔵 رصيد دائن', 'b-blue'],
    ];

    /**
     * ═══════════════════════════════════════════════════════════
     * شروط الدفع
     * ═══════════════════════════════════════════════════════════
     *
     * ⚠️ **`both` مش «مش محدد».** «مش محدد» هي `null` في العمود ومعناها
     * «امشي على القناة»؛ و`both` قرار صريح إن العميل ده بيتعامل
     * بالطريقتين والمندوب هو اللي بيحدد في كل فاتورة. الخلط بينهم كان
     * هيخلّي كل عميل مالوش إعداد يوري المندوب سويتش مالوش لازمة.
     *
     * ⚠️ ومترتّبين من الأضيق للأوسع — أي فحص `in_array` بيمشي عليهم
     * لازم يقرا القيمة الصريحة الأول قبل ما يرجع لافتراضي القناة.
     */
    public const PAY_CASH = 'cash';
    public const PAY_CREDIT = 'credit';
    public const PAY_BOTH = 'both';
    public const PAY_TERMS = [self::PAY_CASH, self::PAY_CREDIT, self::PAY_BOTH];

    /**
     * ═══════════════════════════════════════════════════════════
     * سياسة المرتجع (قرار المالك ٨ أغسطس ٢٠٢٦)
     * ═══════════════════════════════════════════════════════════
     *
     * ⚠️ **بتتعرّف على العميل، مش على المندوب ولا على الحركة.**
     * العميل ممكن يكون مسموح له بأكتر من طريقة، والمندوب بيشوف
     * المسموح بيه **بس** ويختار قبل ما يعمل المرتجع. سيبها للمندوب
     * كان معناه إن كل واحد يتصرف حسب علاقته بالعميل، والشركة
     * تكتشف الفرق في المطابقة.
     *
     * ⚠️ **الافتراضي بيتبع شروط الدفع**: عميل الكاش بياخد «كاش
     * فوري» (مالوش حساب أصلاً يتخصم منه)، وعميل الآجل بياخد «خصم
     * من الحساب». الافتراض ده بيتطبق للعملاء اللي لسه مااتظبطش
     * لهم سياسة — مش بيتكتب في الداتابيز، عشان لما الإدارة تحدد
     * يبقى قرارها هو اللي شغّال.
     */
    public const RETURN_CASH = 'cash';
    public const RETURN_ACCOUNT = 'account';
    public const RETURN_EXCHANGE = 'exchange';
    public const RETURN_CREDIT_NEXT = 'credit_next';

    public const RETURN_POLICIES = [
        self::RETURN_CASH,
        self::RETURN_ACCOUNT,
        self::RETURN_EXCHANGE,
        self::RETURN_CREDIT_NEXT,
    ];

    /**
     * دورة الإقرار الضريبي للعميل.
     *
     * ⚠️ دي **مش** بتغيّر حساب الضريبة على الفاتورة — الضريبة بتتحسب
     * لكل فاتورة على حدة في `Services\Tax`. الدورة دي بتحدد إمتى
     * بنجمّع فواتيره ونرفعها للبورتال، ومتى نطالبه بالخصم الخاص.
     */
    public const TAX_CYCLES = ['monthly', 'quarterly', 'annual'];

    /**
     * ═══════════════════════════════════════════════════════════
     * مصدر نقطة العميل — `clients.location_source`
     * ═══════════════════════════════════════════════════════════
     *
     * ⚠️ **العمود ده كان نصوص حرة متناثرة في الكود** ('visit' ·
     * 'manual' · 'map' مكتوبين بالنص في الكنترولر والفاليديشن).
     * أول ما اتضاف مصدر رابع (الأبلكيشن، ١٤ أغسطس ٢٠٢٦) الحاجة بقت
     * محتاجة اسم واحد: غلطة حرف في مكان واحد كانت هتخلّي صف
     * `rep-app` مايتعدّش مع `rep_app` وعدّاد الشاشة يكدب.
     *
     * ⚠️ **`rep_app` أقوى مصدر عندنا.** المندوب سحب النقطة بإيده وهو
     * **واقف قدام المحل** — عكس `visit` اللي نقطة تشيك إن ممكن تكون
     * من العربية في الطريق (بلاغ المالك ١٤/٨). الأدمن لسه بيقدر
     * يصحّحها من شاشة تأكيد اللوكيشن، وساعتها بتبقى `manual`.
     */
    public const LOC_SRC_VISIT = 'visit';
    public const LOC_SRC_MANUAL = 'manual';
    public const LOC_SRC_MAP = 'map';
    public const LOC_SRC_APP = 'rep_app';

    public const LOC_SOURCES = [
        self::LOC_SRC_VISIT,
        self::LOC_SRC_MANUAL,
        self::LOC_SRC_MAP,
        self::LOC_SRC_APP,
    ];

    protected $fillable = [
        'code', 'name', 'name_en', 'phone', 'address', 'zone_id', 'rep_id', 'manager_id',
        'contacts', 'category', 'payment_terms', 'payment_days', 'payment_days_from', 'status',
        'channel_id', 'group_id', 'branch_id', 'sub_channel', 'division', 'fulfillment_mode', 'parent_id', 'uses_channel_discount',
        'price_list', 'price_list_id', 'taxable', 'tax_rate', 'tax_id', 'eta_type', 'tax_cycle',
        'governorate', 'location_url', 'lat', 'lng', 'address_ar',
        'location_confirmed_at', 'location_confirmed_by', 'location_source',
        // ⚠️ **الإرسال غير التأكيد** (١٧/٨) — المندوب بيبعت طلب،
        // والأدمن بيأكّد. خلطهم كان بيخلّي المندوب يأكّد لنفسه.
        'location_submitted_at', 'location_submitted_by',
        // ⚠️ **لازم يكون fillable** — من غيره `update()` بيتجاهله في
        // صمت وشاشة العميل بتحفظ من غير ما تحفظ.
        'return_policies',
        'discount', 'is_new', 'photo_path', 'docs_path', 'docs_type', 'has_docs',
        'purchases', 'collections', 'returns', 'rebates', 'settlements', 'balance', 'withheld',
        'first_activity_at', 'last_activity_at', 'last_payment_at',
        'created_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'discount' => 'decimal:4',
            'contacts' => 'array',
            // ⚠️ **مهجور.** كان معناه «ارجع لخصم القناة»، والقناة
            // مابقاش لها نسبة. العمود سايب عشان الداتا القديمة، بس
            // `effectiveDiscount()` مابتقراهوش.
            'uses_channel_discount' => 'boolean',
            'taxable' => 'boolean',
            'tax_rate' => 'decimal:4',
            'is_new' => 'boolean',
            'has_docs' => 'boolean',
            'purchases' => 'decimal:2',
            'collections' => 'decimal:2',
            'returns' => 'decimal:2',
            'rebates' => 'decimal:2',
            'settlements' => 'decimal:2',
            'balance' => 'decimal:2',
            'withheld' => 'decimal:2',
            'return_policies' => 'array',
            'first_activity_at' => 'date',
            'last_activity_at' => 'date',
            'last_payment_at' => 'date',
            'location_confirmed_at' => 'datetime',
            'location_submitted_at' => 'datetime',
        ];
    }

    /**
     * ═══════════════════════════════════════════════════════════
     * القسم للكي أكاونت وبس
     * ═══════════════════════════════════════════════════════════
     *
     * ⚠️ **على الموديل مش على الفورم عن قصد.** العميل بيتعمل من 5
     * مسارات: شاشة الـERP، تحويل عميل محتمل، موافقة طلب من الأبلكيشن،
     * استيراد شيت، وسيدر. لو الحارس اتحط في الفورم بس، الأربعة التانيين
     * بيعدّوا بقسم على قناة مالهاش أقسام.
     *
     * ⚠️ الحالة اللي بتحصل فعلاً: عميل كان كي أكاونت/سلاسل واتنقل
     * كاش فان. القناة بتتغيّر والقسم بيفضل، والفلتر في شاشة العملاء
     * بيطلّعه في نتيجة «سلاسل هايبر» وهو عربية بتلف الشارع.
     */
    protected static function booted(): void
    {
        static::saving(function (Client $client) {
            if ($client->sub_channel === null) {
                return;
            }

            // ⚠️ العلاقة المحمّلة بتتستخدم **لو القناة ماتغيّرتش**. لو
            // اتغيّرت، العلاقة في الذاكرة لسه شايلة القناة القديمة —
            // وده بالظبط السيناريو اللي الحارس ده موجود عشانه، فكنا
            // هنقرا كود القناة القديمة ونسيب القسم.
            $fresh = $client->isDirty('channel_id') || ! $client->relationLoaded('channel');

            $code = $client->channel_id === null
                ? null
                : ($fresh
                    ? Channel::whereKey($client->channel_id)->value('code')
                    : $client->channel?->code);

            if (! Channel::codeHasSubChannels($code)) {
                $client->sub_channel = null;
            }
        });

        // ═══════════════════════════════════════════════════════
        // مفاتيح كشف التكرار — مشتقة، بتتكتب هنا وبس (١٥/٨/٢٠٢٦)
        // ═══════════════════════════════════════════════════════
        //
        // ⚠️ **على الموديل مش على الفورم.** العميل بيتعمل من خمس
        // مسارات (شاشة الـERP، الاستنساخ، طلب الأبلكيشن، الاعتماد،
        // الاستيراد) — لو المفتاح اتكتب في واحد منهم بس، الأربعة
        // التانيين بيولّدوا صفوف الحارس مش شايفها.
        //
        // ⚠️ **محروس بـ`hasKeyColumns`** — السيرفر اللايف مش ريبو
        // جيت والمالك بيرفع الملفات بإيده، فممكن الكود يوصل قبل
        // المايجريشن. من غير الحارس ده أول حفظ عميل بيرمي
        // «Unknown column 'dupe_key'».
        static::saving(function (Client $client) {
            if (! \App\Support\Dupes::hasKeyColumns()) {
                return;
            }

            $client->dupe_key = \App\Support\Dupes::nameKey($client->name);
            $client->dupe_phone = \App\Support\Dupes::phoneKey($client->phone) ?: null;
        });
    }

    // ---------- Relations ----------

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

        /**
     * قائمة السعر المعتمدة — الصف مش النص.
     *
     * ⚠️ **اسمها مش `priceList`.** فيه عمود اسمه `price_list` (نص
     * `old`/`new`)، ولو العلاقة اتسمّت `priceList` كان Eloquent
     * هيلغبط الاتنين: `$model->price_list` بترجّع العلاقة بدل النص
     * وكل كود قديم بيقارن بالنص بيقع.
     */
    public function priceListRow(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PriceList::class, 'price_list_id');
    }

public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** المندوب المسئول عن العميل ده — تخصيص حصري */
    public function rep(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rep_id');
    }

    /**
     * مدير القناة المسؤول تجارياً عن الحساب.
     *
     * ⚠️ **غير المندوب.** المندوب بيتغيّر مع خط السير كل شهر، والمدير
     * هو اللي متفاوض على العقد وبيتحاسب على أرقام العميل.
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /**
     * جهات التواصل عند العميل — أسماء وتليفونات، مرجع بشري بس.
     *
     * ⚠️ **عمود واحد بالإنجليزي مش عمودين.** الاسم والصفة نص حر
     * بيتكتب مرة لحالة واحدة — مالوش «نسخة تانية» زي اسم القناة أو
     * المحافظة اللي بتتعرّف مرة وبتتعرض في كل شاشة. عمودين هنا
     * معناهم إن اللي بيدخل الداتا بيكتب نفس الاسم مرتين على 300
     * عميل، وفي الآخر نص الخانات بتفضل فاضية.
     *
     * ⚠️ الصفوف الفاضية بتتشال هنا مش في الشاشة. صف مالوش اسم ولا
     * تليفون بيبان في الكارت كسطر فاضي ومحدش يعرف يمسحه.
     *
     * @return array<int, array{name: string, role: ?string, phone: ?string}>
     */
    public function contactList(): array
    {
        return collect($this->contacts ?? [])
            ->map(fn ($c) => [
                'name' => trim((string) ($c['name'] ?? '')),
                'role' => trim((string) ($c['role'] ?? '')) ?: null,
                'phone' => trim((string) ($c['phone'] ?? '')) ?: null,
            ])
            ->filter(fn ($c) => $c['name'] !== '' || $c['phone'] !== null)
            ->values()
            ->all();
    }

    /**
     * الموظف اللي ضبط/أكّد لوكيشن العميل — بصمة على النقطة نفسها.
     *
     * ⚠️ **غير `rep` وغير `manager`.** ممكن يكون مندوب سحب النقطة من
     * الأبلكيشن، وممكن يكون أدمن أكّدها من الداشبورد — والفرق بين
     * الاتنين في `location_source`. شاشة تأكيد اللوكيشن بتعرض
     * الاسمين مع بعض عشان اللي بيراجع يعرف يسأل مين.
     */
    public function locationConfirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'location_confirmed_by');
    }

    /**
     * المندوب اللي **بعت** النقطة من الأبلكيشن — غير اللي أكّدها.
     *
     * ⚠️ الاتنين كانوا نفس العمود قبل ١٧/٨، فالمندوب كان بيظهر
     * كإنه هو اللي راجع وأكّد. الفصل ده بيخلّي سؤال «مين بعتها؟»
     * و«مين وافق؟» ليهم إجابتين مختلفتين.
     */
    public function locationSubmitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'location_submitted_by');
    }

    /** السلسلة اللي الفرع تابع لها */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ClientGroup::class, 'group_id');
    }

    /** السلسلة الأم (لو العميل ده فرع) */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'parent_id');
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Client::class, 'parent_id');
    }

    public function merchVisits(): HasMany
    {
        return $this->hasMany(MerchVisit::class)->latest();
    }

    public function replenishmentRequests(): HasMany
    {
        return $this->hasMany(ReplenishmentRequest::class)->latest();
    }

    /**
     * عقد خاص بالعميل ده لوحده.
     *
     * ⚠️ latestOfMany: لو لأي سبب بقى فيه أكتر من عقد للعميل، hasOne العادية
     * بترجّع الأقدم (ترتيب الإندكس) — يعني عقد قديم يغلب عقد جديد ويطبّق خصم
     * غلط في صمت. الأحدث دايماً هو الصح.
     */
    public function contract(): HasOne
    {
        return $this->hasOne(Contract::class)->latestOfMany();
    }

    /**
     * العقد الفعّال: بتاع العميل لو موجود وسارٍ، وإلا بتاع سلسلته.
     *
     * ⚠️ ده المصدر الوحيد للعقد في كل السيستم. سلسلة زي Circle K
     * ليها عقد واحد و 40 فرع — ممنوع نكرر العقد على كل فرع، والفرع
     * لازم يشوف نفس النِسَب بالظبط.
     */
    public function liveContract(): ?Contract
    {
        return $this->ownLiveContract() ?? $this->chainLiveContract();
    }

    /** عقده الشخصي السارٍ — من غير عقد السلسلة */
    public function ownLiveContract(): ?Contract
    {
        $this->loadMissing('contract');

        return self::contractIsLive($this->contract) ? $this->contract : null;
    }

    /** عقد سلسلته السارٍ — من غير عقده الشخصي */
    public function chainLiveContract(): ?Contract
    {
        $this->loadMissing('group.contract');

        $ct = $this->group?->contract;

        return self::contractIsLive($ct) ? $ct : null;
    }

    private static function contractIsLive(?Contract $c): bool
    {
        return $c !== null && $c->active && ! $c->isExpired();
    }

    /**
     * أي عقد مسجّل للعميل ولو مش سارٍ — بتاعه هو الأول وإلا بتاع سلسلته.
     *
     * ⚠️ **غير `liveContract()` تماماً.** دي بترجّع الصف الموجود
     * مهما كانت حالته؛ دي اللي بتخلّي الشاشة تفرّق بين «العقد خلص»
     * و«مفيش عقد أصلاً». ممنوع تستخدمها في أي حساب سعر أو خصم —
     * التسعير `liveContract()` وبس.
     */
    public function anyContract(): ?Contract
    {
        $this->loadMissing(['contract', 'group.contract']);

        return $this->contract ?? $this->group?->contract;
    }

    /**
     * حالة التعاقد للعرض والفلترة.
     *
     * ⚠️ **«منتهي» ≠ «بدون عقد»** (بلاغ المالك ١٥/٨). الشاشة كانت
     * بتقرا `liveContract()` بس، وده بيرجّع `null` للاتنين — فالعميل
     * اللي عقده خلص إمبارح كان بيبان زي عميل عمره ما تعاقد، والفرصة
     * الوحيدة إن حد ياخد باله من التجديد بتضيع.
     *
     * @return 'live'|'expired'|'inactive'|'none'
     */
    public function contractState(): string
    {
        if ($this->liveContract() !== null) {
            return 'live';
        }

        $any = $this->anyContract();

        if ($any === null) {
            return 'none';
        }

        return $any->isExpired() ? 'expired' : 'inactive';
    }

    /**
     * سكوب التشانل مانجر (قرار المالك 2026-08-05): المدير بيشوف
     * **عملاءه المسكّنين له بس** — `manager_id` اللي بيتظبط من شاشة
     * «عملاء المديرين» أو من فورم العميل. أي شاشة فيها عملاء أو
     * أرقام مبنية عليهم لازم تعدي من هنا.
     *
     * ⚠️ نفس نمط `Branch::scope` — بيرجع الكويري زي ما هو لغير المدير.
     */
    /**
     * البحث الموحّد عن عميل (طلب المالك ١١/٨): «رابت» ماكانش بيطلّع
     * «رابت — فرع مدينة نصر» لأن «رابت» اسم **السلسلة** في جدول تاني
     * والبحث كان في اسم الفرع بس. السكوب ده بيغطي: اسم الفرع
     * عربي/إنجليزي + اسم السلسلة عربي/إنجليزي + التليفون + الكود —
     * فتكتب بأي لغة وبأول كام حرف من أي جزء وتلاقيه.
     * أي خانة بحث عملاء جديدة لازم تستخدمه بدل ما تكتب like بإيدها.
     */
    public static function search($query, string $s)
    {
        return $query->where(fn ($w) => $w->where('name', 'like', "%$s%")
            ->orWhere('name_en', 'like', "%$s%")
            ->orWhere('phone', 'like', "%$s%")
            ->orWhere('code', 'like', "%$s%")
            ->orWhereHas('group', fn ($g) => $g->where('name', 'like', "%$s%")
                ->orWhere('name_en', 'like', "%$s%")));
    }

    public static function visibleTo($query, ?User $user = null)
    {
        $user = $user ?? auth()->user();

        if ($user !== null && $user->role === 'manager') {
            $query->where('manager_id', $user->id);
        }

        return $query;
    }

    /** المدير ده يقدر يشوف العميل ده؟ — لحراسة صفحات العميل الواحد */
    public function visibleBy(?User $user): bool
    {
        return $user === null
            || $user->role !== 'manager'
            || (int) $this->manager_id === (int) $user->id;
    }

    /**
     * ═══════════════════════════════════════════════════════════
     * بول الفريق (قرار المالك ١١ أغسطس ٢٠٢٦ مساءً) — «سكّن كل عملاء
     * مدير التشانل بكل مناديبه»
     * ═══════════════════════════════════════════════════════════
     *
     * الفصل الأساسي بقى على مستوى **مدير القناة**: عملاؤه بول مشترك
     * لكل فريقه. مندوب «ب» بيغطي منطقة مندوب «أ» الغايب من غير ما حد
     * ينقل عملاء بإيده — الاتنين تحت نفس المدير فالاتنين شايفين نفس
     * البول. `rep_id` فضل زي ما هو = **المسؤول الأساسي** (التارجت،
     * الوراثة، عزو العميل الجديد).
     *
     * قاعدة مش داتا: مفيش جدول pivot ولا سكريبت تسكين — أي عميل جديد
     * تحت المدير بيبان لكل مناديبه فوراً وللأبد.
     *
     * ⚠️ **الحدود زي ما هي**: مندوب مدير «س» عمره ما يشوف عملاء مدير
     * «ص» (الفحص على `manager_id` بتاع المندوب نفسه). والعميل اللي
     * `manager_id` بتاعه فاضي (يتيم/إدارة) بيفضل لمندوبه المسجّل بس —
     * زي الأول بالظبط.
     */
    public static function poolWhere($query, User $rep)
    {
        return $query->where(function ($w) use ($rep) {
            $w->where('rep_id', $rep->id);

            // مندوب من غير مدير = السلوك القديم بالحرف (rep_id بس)
            if ($rep->manager_id !== null) {
                $w->orWhere('manager_id', $rep->manager_id);
            }
        });
    }

    /** العميل ده جوه بول فريق المندوب ده؟ — نسخة الصف الواحد من `poolWhere` */
    public function inPoolOf(User $rep): bool
    {
        if ((int) $this->rep_id === (int) $rep->id) {
            return true;
        }

        return $rep->manager_id !== null
            && (int) $this->manager_id === (int) $rep->manager_id;
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class)->orderBy('date');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->latest();
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class)->latest();
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class)->latest();
    }

    // ---------- Helpers ----------

    /**
     * كود عميل جديد مش متكرر.
     *
     * ⚠️⚠️ **انفجر على اللايف ١٧/٨** («Duplicate CL-4» وقت اعتماد
     * طلب عميل). النسخة القديمة كانت بتقرا **آخر صف بالـid** وتطلّع
     * أرقامه — وبعد استيراد سلاسل بأكواد بادئات مختلفة (BSM-28،
     * FLM-03، BAP-02…) آخر عميل بقى كوده «FLM-03» فطلع الرقم 3+1=4
     * واصطدم بـCL-4 الموجود من زمان.
     *
     * نفس فخ «آخر صف مش أكبر رقم» اللي اتصلح في 15 موديل
     * بـ`HasDocumentNumber` — بس هنا البادئات متعددة، فبنقرا أكبر
     * رقم من أكواد **CL- بس**، وبنلف على أي فجوة محجوزة.
     */
    public static function nextCode(): string
    {
        $n = (int) static::query()
            ->where('code', 'like', 'CL-%')
            ->selectRaw("MAX(CAST(SUBSTRING_INDEX(code, '-', -1) AS UNSIGNED)) as n")
            ->value('n');

        $n = $n > 0 ? $n : 1000;

        do {
            $code = 'CL-'.(++$n);
        } while (static::where('code', $code)->exists());

        return $code;
    }

    public function governorateLabel(): string
    {
        return \App\Support\Governorates::label($this->governorate);
    }

    public function taxCycleLabel(): string
    {
        return in_array($this->tax_cycle, self::TAX_CYCLES, true)
            ? __('client.tax_cycle_'.$this->tax_cycle)
            : '—';
    }

    /**
     * لينك اللوكيشن للعرض — أو لينك مولّد من الإحداثيات.
     *
     * ⚠️ بيرجّع `null` بدل لينك مكسور. زرار "افتح على الخريطة" اللي
     * بيروح لصفحة فاضية أسوأ من زرار مش موجود، لأن المندوب بيقف
     * قدام العميل ومش لاقي العنوان.
     */
    public function mapUrl(): ?string
    {
        $url = trim((string) $this->location_url);

        if ($url !== '' && preg_match('#^https?://#i', $url)) {
            return $url;
        }

        if ($this->lat !== null && $this->lng !== null) {
            return 'https://www.google.com/maps?q='.$this->lat.','.$this->lng;
        }

        return null;
    }

    public function categoryLabel(): string
    {
        // المسمى بييجي من lang/{ar,en}/enums.php — والثابت القديم fallback
        $key = 'enums.category.'.$this->category;

        return \Illuminate\Support\Facades\Lang::has($key)
            ? __($key)
            : (self::CATEGORIES[$this->category][0] ?? $this->category);
    }

    public function categoryClass(): string
    {
        return self::CATEGORIES[$this->category][1] ?? 'b-gray';
    }

    /** فيه عقد سارٍ فعلاً؟ (مش مجرد خصم على العميل) */
    public function hasContract(): bool
    {
        return $this->hasLiveContract();
    }

    /**
     * العميل بيتحاسب بالمبيع؟
     *
     * ⚠️ في عقود الأمانة (Healthy، Kwake 24، Max Muscle) البضاعة بتروح
     * الفرع وتفضل ملك بروماكس لحد ما تتباع. فالتوريد **مش** مديونية،
     * والمديونية بتتولد من تقرير المبيعات الشهري بتاع الفرع.
     * لو حسبناه مديونية وقت التوريد، رصيد العميل بيطلع أعلى من الحقيقة
     * وأعمار الديون بتبان متأخرة وهي مش متأخرة.
     */
    public function isConsignment(): bool
    {
        return $this->liveContract()?->isConsignment() ?? false;
    }

    /**
     * المبلغ المحجوز كضمان — من العمود المخزّن اللي recalculate() بيحدّثه.
     * ⚠️ ده جزء من balance مش زيادة عليه: الرصيد المتاح للتحصيل =
     * balance − withheld.
     */
    public function withheldAmount(): float
    {
        return (float) $this->withheld;
    }

    /** الرصيد اللي نقدر نحصّله فعلاً */
    public function collectableBalance(): float
    {
        return round((float) $this->balance - (float) $this->withheld, 2);
    }

    /** استحقاقات مستنية ترحيل */
    public function dues(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ContractDue::class);
    }

    /** فيه عقد سارٍ — خاص بالعميل أو موروث من سلسلته */
    public function hasLiveContract(): bool
    {
        return $this->liveContract() !== null;
    }

    /**
     * ═══════════════════════════════════════════════════════════
     * نسبة الخصم المعتمدة — الترتيب المقدّس
     * ═══════════════════════════════════════════════════════════
     *
     *   1. العقد السارٍ (بتاع العميل أو الموروث من سلسلته)
     *   2. خصم خاص متسجّل على العميل
     *   3. خصم السلسلة
     *   4. صفر
     *
     * ⚠️ **القناة مالهاش نسبة خالص.** قرار 2026-07-31: القناة بقت
     * بُعد تجميع وتقرير (كام عميل، كام بضاعة، كام مبيعات) — مش مصدر
     * تسعير. النسبة بتتحدد لكل عميل على حدة، وعميلين في نفس القناة
     * ممكن يكونوا على 40% و55%.
     *
     * ⚠️ لما كانت القناة بتدي نسبة، عميل جديد اتحط في «كي أكاونت»
     * كان بياخد 50% أوتوماتيك من غير ما حد يتفاوض عليها — وأول
     * فاتورة بتطلع بخصم محدش قرره.
     */
    public function effectiveDiscount(): float
    {
        // ═══ الترتيب الجديد (قرار المالك ١٨ أغسطس ٢٠٢٦) ═══
        //
        // «شاشة الإعداد تسمع في كل السيستم» — الترتيب القديم كان
        // العقد (بتاعه أو بتاع سلسلته) يغلب خصم العميل، فالمالك
        // بيكتب نسبة لفرع واحد من شاشة الإعداد وعقد السلسلة بيدهسها
        // في صمت. الترتيب بقى:
        //
        //   1. عقد الفرع **الشخصي** السارٍ — اتفاق مكتوب باسمه بيغلب
        //   2. خصم العميل نفسه (اللي شاشة الإعداد بتكتبه)
        //   3. عقد **السلسلة** السارٍ — الافتراضي للفروع اللي مالهاش رقم خاص
        //   4. صفر — سعر القائمة كامل
        //
        // ⚠️ «عقد الفرع يكسب» (قرار ١٧/٨) لسه محفوظ — اللي اتغير إن
        // خصم العميل بقى **بين** عقده الشخصي وعقد السلسلة بدل ما كان
        // تحت الاتنين. وشاشة الإعداد بتختم العقود الشخصية والسلسلة
        // معاً وقت الحفظ، فبعد أي حفظة الطبقات كلها متطابقة أصلاً.
        //
        // ⚠️ خصم الفاتورة بس — الخصومات الدورية بتتسوّى بعدين
        // ومالهاش دعوة بالفاتورة.
        $own = $this->ownLiveContract();
        if ($own && (float) $own->discount > 0) {
            return (float) $own->discount;
        }

        if ((float) $this->discount > 0) {
            return (float) $this->discount;
        }

        $chain = $this->chainLiveContract();
        if ($chain && (float) $chain->discount > 0) {
            return (float) $chain->discount;
        }

        return 0.0;
    }

    public function hasLocation(): bool
    {
        return $this->lat !== null && $this->lng !== null;
    }

    /** اسم القسم التجاري — «—» للغير مسكَّن */
    public function divisionLabel(): string
    {
        return \App\Support\Divisions::label($this->division);
    }

    /**
     * طريقة التعامل — مشتقة من القسم، مش عمود.
     *
     * ⚠️ cashvan = عهدة + خط سير · delivery = PO · online = كوريير.
     * تخزينها كعمود تاني كان معناه إن القسم يتغيّر والطريقة تفضل
     * قديمة — والاشتقاق بيخلّيهم مستحيل يفترقوا.
     */
    public function fulfillment(): ?string
    {
        // ⚠️ **التجاوز يغلب** (١٧/٨) — المالك ممكن يحدد لسلسلة
        // كونفينيانس إنها تتعامل ديلفري. الفاضي = افتراضي القسم.
        return $this->fulfillment_mode
            ?: \App\Support\Divisions::fulfillmentOf($this->division);
    }

    public function fulfillmentLabel(): string
    {
        $f = $this->fulfillment();

        return $f === null ? '—' : __('client.ff_'.$f);
    }

    /**
     * سعر بيع الصنف للعميل ده — قائمة سعره بعد خصمه.
     * الحساب كله في Pricing عشان يبقى في مكان واحد.
     */
    public function priceFor(Product $product): float
    {
        return \App\Services\Pricing::unitPrice($this, $product);
    }

    /** قائمة السعر المعتمدة — old أو new */
    public function priceList(): string
    {
        return \App\Services\Pricing::listFor($this);
    }

    public function priceListLabel(): string
    {
        return \App\Services\Pricing::listLabel($this->priceList());
    }

    /** تسعيرة كاملة بالتكلفة والهامش */
    public function quoteFor(Product $product, ?Batch $batch = null, int $qty = 1): array
    {
        return \App\Services\Pricing::quote($this, $product, $batch, $qty);
    }

    /** مصدر الخصم — للعرض في الشاشات (بيتترجم من lang/{ar,en}/client.php) */
    public function discountSource(): string
    {
        return __('client.'.$this->discountSourceKey());
    }

    /** المفتاح الخام — للمقارنات في الكود بدل ما نقارن نص مترجم */
    public function discountSourceKey(): string
    {
        // ⚠️ **نفس ترتيب `effectiveDiscount()` بالحرف** (١٨/٨/٢٠٢٦):
        // عقده الشخصي ← خصمه الخاص ← عقد السلسلة. لو اختلفوا، الشاشة
        // بتقول «عقد» والرقم جاي من الخصم الخاص.
        $own = $this->ownLiveContract();
        if ($own && (float) $own->discount > 0) {
            return 'contract';
        }
        if ((float) $this->discount > 0) {
            return 'custom_discount';
        }
        $chain = $this->chainLiveContract();
        if ($chain && (float) $chain->discount > 0) {
            return 'contract';
        }

        // ⚠️ **مفيش `chain_discount` ولا `channel_discount`.** الاتنين
        // مابقوش مصادر خصم — السلسلة تجميعة والقناة بُعد تقرير.
        return 'no_discount';
    }

    public function subChannelLabel(): ?string
    {
        return Channel::subChannelLabel($this->sub_channel);
    }

    /** فرع كي أكاونت — البروموتر بيزوره */
    public function isKeyAccount(): bool
    {
        return $this->channel?->code === Channel::KEY_ACCOUNT;
    }

    /**
     * طريقة الدفع: كاش ولا آجل — **قرار إدارة مش قرار مندوب**
     * (قرار المالك 2026-08-03). الأبلكيشن مابيسألش؛ بياخدها من هنا.
     *
     * الترتيب:
     *   1. التصنيف `danger` ← كاش إجباري مهما كانت الخانة
     *   2. الخانة المتظبطة بالإيد من الأدمن (`payment_terms`)
     *   3. حسب القناة: كاش فان وجملة كاش، كي أكاونت وأونلاين آجل
     */
    public function paymentTerms(): string
    {
        if ($this->category === 'danger') {
            return 'cash';
        }

        if (in_array($this->payment_terms, self::PAY_TERMS, true)) {
            return $this->payment_terms;
        }

        // ⚠️ العميل من غير قناة → **آجل** مش كاش. العميل الجديد بيتعمل
        // من غير قناة أحياناً، وقفل الآجل عليه من يومه الأول حكم على
        // سلوك لسه مافيش منه حاجة (نفس منطق التصنيف).
        return in_array($this->channel?->code, [Channel::CASH_VAN, Channel::WHOLESALE], true)
            ? 'cash'
            : 'credit';
    }

    public function paymentTermsLabel(): string
    {
        return __('client.terms_'.$this->paymentTerms());
    }

    /**
     * العميل اللي بيشتري كاش بس — من `paymentTerms()` مش من التصنيف لوحده.
     *
     * ⚠️ **`both` مش `cashOnly`.** العميل المختلط مسموح له الآجل،
     * فالحارس ده لازم يفضل `false` ليه وإلا الأبلكيشن هيقفل عليه
     * الآجل اللي المدير سمح بيه بإيده.
     */
    public function cashOnly(): bool
    {
        return $this->paymentTerms() === self::PAY_CASH;
    }

    /**
     * ═══════════════════════════════════════════════════════════
     * شروط الدفع ومواعيد السداد (2026-08-08)
     * ═══════════════════════════════════════════════════════════
     */

    /**
     * ⚠️ **`both` معناها المندوب بيختار وقت البيع** — وده الاستثناء
     * الوحيد لقاعدة «كاش/آجل قرار إدارة». الفرق إن الإدارة هي اللي
     * سمحت بالاختيار أصلاً وهي بتعرّف العميل؛ اللي مش `both` المندوب
     * مايشوفش السويتش خالص فمفيش فرصة يغلط.
     */
    public function paymentIsChoice(): bool
    {
        return $this->paymentTerms() === self::PAY_BOTH;
    }

    /** الآجل مسموح؟ — `credit` أو `both` */
    public function allowsCredit(): bool
    {
        return in_array($this->paymentTerms(), [self::PAY_CREDIT, self::PAY_BOTH], true);
    }

    /**
     * أيام السداد — **العقد الساري يغلب العميل**.
     *
     * ⚠️ الترتيب ده مش تفصيلة: العقد ورقة موقّعة، والخانة على العميل
     * إعداد داخلي. لو عكسناهم، تعديل بسيط على كارت العميل بيغيّر مدة
     * سداد متفق عليها في عقد — من غير ما حد يفتح العقد.
     *
     * ⚠️ ولازم `hasLiveContract()` مش `->contract` — العقد المنتهي
     * شروطه ماتتطبقش، والعميل بيرجع لإعداده الخاص.
     */
    public function paymentDays(): ?int
    {
        $ct = $this->liveContract();

        if ($ct !== null && $ct->paymentDays() !== null) {
            return $ct->paymentDays();
        }

        return $this->payment_days === null ? null : (int) $this->payment_days;
    }

    /** أساس العد — نفس مفردات العقد بالظبط */
    public function paymentBasis(): string
    {
        $ct = $this->liveContract();

        if ($ct !== null && $ct->paymentDays() !== null) {
            return $ct->paymentBasis();
        }

        // ⚠️ **نفس افتراضي العقد** (`first_supply`) مش `invoice`
        // (إصلاح 2026-08-08). لما الاتنين كانوا مختلفين، نفس الداتا
        // (أيام من غير أساس) كان معناها تاريخ استحقاق مختلف حسب إن
        // كان العميل ليه عقد ولا لأ — وده بالظبط اللي المايجريشن
        // بتوعد بعكسه: «نفس مفردات العقد بالظبط».
        return in_array($this->payment_days_from, Contract::DAYS_FROM, true)
            ? $this->payment_days_from
            : Contract::DAYS_FROM_FIRST_SUPPLY;
    }

    public function paymentBasisLabel(): string
    {
        return __('client.days_from_'.$this->paymentBasis());
    }

    /** مصدر الشروط — عشان الشاشة تقول للمستخدم الرقم جه منين */
    public function paymentSourceKey(): string
    {
        $ct = $this->liveContract();

        return $ct !== null && $ct->paymentDays() !== null
            ? 'client.pay_from_contract'
            : 'client.pay_from_client';
    }

    /**
     * ميعاد استحقاق فاتورة.
     *
     * ⚠️ **بيعدّي على العقد لو سارٍ** عشان يفضل حساب واحد في السيستم
     * كله — `Contract::dueDateFor()` هي اللي بتعرف تفرق بين العد من
     * أول توريد والعد من الفاتورة، ونسخ منطقها هنا كان هيخلي شاشتين
     * يقولوا تاريخين لنفس الفاتورة.
     */
    public function dueDateFor($invoiceDate = null): ?\Illuminate\Support\Carbon
    {
        $ct = $this->liveContract();

        if ($ct !== null && $ct->paymentDays() !== null) {
            return $ct->dueDateFor($this, $invoiceDate);
        }

        $days = $this->paymentDays();

        if ($days === null) {
            return null;
        }

        if ($this->paymentBasis() === Contract::DAYS_FROM_INVOICE) {
            return $invoiceDate
                ? \Illuminate\Support\Carbon::parse($invoiceDate)->copy()->addDays($days)
                : null;
        }

        // ⚠️ `null` لو لسه مفيش أول توريد — الافتراض إن أول توريد هو
        // النهارده كان بيدي ميعاد استحقاق بيتحرك كل يوم (نفس فخ العقد)
        //
        // ⚠️ **دورة متكررة زي `Contract::dueDateFor`** (تدقيق ٨/٨) —
        // الحسبة مكتوبة مرة هنا لأن العميل ممكن مايكونش عليه عقد
        // خالص. لو غيّرت واحدة، غيّر التانية.
        $first = $this->first_activity_at;

        if ($first === null) {
            return null;
        }

        $anchor = $first->copy()->startOfDay();
        $ref = ($invoiceDate ? \Illuminate\Support\Carbon::parse($invoiceDate) : today())->copy()->startOfDay();

        // ⚠️ صفر يوم = مستحق فوراً — نفس حارس `Contract::dueDateFor`
        if ($days <= 0) {
            return $ref;
        }

        $elapsed = (int) round($anchor->diffInDays($ref, false));

        return $anchor->copy()->addDays($days * max(1, (int) ceil($elapsed / $days)));
    }

    /**
     * ═══════════════════════════════════════════════════════════
     * اللوكيشن والعنوان (2026-08-08)
     * ═══════════════════════════════════════════════════════════
     */

    /**
     * العنوان بلغة الواجهة.
     *
     * ⚠️ **`address` إنجليزي و`address_ar` عربي** — مش `address` +
     * `address_en` زي الأسماء. السبب في المايجريشن: العمود القديم
     * إنجليزي أصلاً وعليه داتا حية، وإعادة تسميته كانت مخاطرة
     * مالهاش مكسب. الدالة دي هي المكان الوحيد اللي بيعرف الفرق ده.
     */
    public function displayAddress(): string
    {
        $ar = trim((string) $this->address_ar);
        $en = trim((string) $this->address);

        return app()->getLocale() === 'ar'
            ? ($ar !== '' ? $ar : $en)
            : ($en !== '' ? $en : $ar);
    }

    /** «7 شارع 9 — المعادي — القاهرة» — الأجزاء الفاضية بتتشال */
    public function fullAddress(): string
    {
        return implode(' — ', array_filter([
            $this->displayAddress(),
            $this->zone?->displayName(),
            $this->governorateLabel(),
        ], fn ($p) => trim((string) $p) !== ''));
    }

    /**
     * ⚠️ **موثوق ≠ موجود.** الإحداثيات ممكن تكون جاية من استيراد أو
     * من جيوكودينج تقريبي على نص عنوان — والاتنين تخمين. الموثوق هو
     * اللي بني آدم أكّده من زيارة فعلية، وده اللي الفيريفاي هيتبني
     * عليه بعدين.
     */
    public function locationTrusted(): bool
    {
        return $this->hasLocation() && $this->location_confirmed_at !== null;
    }

    /** النقطة جاية من الأبلكيشن؟ — المندوب سحبها وهو قدام المحل */
    public function locationFromApp(): bool
    {
        return $this->location_source === self::LOC_SRC_APP;
    }

    /**
     * طلب تعديل لوكيشن مستنّي مراجعة؟
     *
     * ⚠️ **الشرطين مع بعض.** `submitted_at` لوحدها بتفضل مكتوبة بعد
     * التأكيد (بصمة تاريخية)، فلو الطابور اتبنى عليها لوحدها كان
     * هيفضل يعرض عملاء اتراجعوا خلاص.
     */
    public function locationPending(): bool
    {
        return $this->location_submitted_at !== null
            && $this->location_confirmed_at === null;
    }

    /**
     * مسمى مصدر النقطة — «من الأبلكيشن» / «من زيارة» / «يدوي» / «من لينك».
     *
     * ⚠️ المصدر الغير معروف بيرجّع `null` مش نص خام: صف قديم فيه قيمة
     * مالهاش مفتاح لغة كان هيطبع `geo.src.xxx` في وش المستخدم.
     */
    public function locationSourceLabel(): ?string
    {
        $src = (string) $this->location_source;

        return in_array($src, self::LOC_SOURCES, true) ? __('geo.src.'.$src) : null;
    }

    public function collectionRate(): float
    {
        return (float) $this->purchases > 0
            ? (float) $this->collections / (float) $this->purchases
            : 0;
    }

    /**
     * السياسات المسموح بيها للعميل ده — دايماً واحدة على الأقل.
     *
     * ⚠️ **مابترجعش مصفوفة فاضية أبداً.** لو رجّعت فاضية، المندوب
     * بيوصل لشاشة المرتجع ومايلاقيش أي اختيار — يعني مرتجع مستحيل
     * من غير رسالة تقول ليه. الافتراضي بيتبع شروط الدفع.
     *
     * @return array<int, string>
     */
    public function returnPolicies(): array
    {
        $raw = $this->return_policies;

        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        $set = collect(is_array($raw) ? $raw : [])
            ->map(fn ($p) => (string) $p)
            ->filter(fn ($p) => in_array($p, self::RETURN_POLICIES, true))
            ->unique()
            ->values()
            ->all();

        if ($set !== []) {
            return $set;
        }

        // ⚠️ عميل الكاش مالوش حساب يتخصم منه — «خصم من الحساب»
        // عليه بيسيب رصيد دائن وهمي بيفضل في الدفتر للأبد.
        return $this->paymentTerms() === self::PAY_CASH
            ? [self::RETURN_CASH, self::RETURN_EXCHANGE]
            : [self::RETURN_ACCOUNT, self::RETURN_EXCHANGE, self::RETURN_CREDIT_NEXT];
    }

    /** أعمار المديونية تقديري FIFO من كشف الحساب */
    public function aging(): array
    {
        $buckets = ['a30' => 0.0, 'a60' => 0.0, 'a90' => 0.0, 'a180' => 0.0, 'a180p' => 0.0];
        $balance = (float) $this->balance;
        if ($balance <= 0) {
            return $buckets;
        }

        $today = now();
        // نمشي من الأحدث للأقدم ونوزّع الرصيد على الفواتير غير المسددة
        // (لو الحركات محمّلة مسبقاً بنستخدمها من غير كويري جديد)
        $sales = $this->relationLoaded('transactions')
            ? $this->transactions->whereIn('kind', Transaction::DEBT_KINDS)->sortByDesc('date')
            : $this->transactions()->whereIn('kind', Transaction::DEBT_KINDS)->orderByDesc('date')->get();

        foreach ($sales as $t) {
            if ($balance <= 0) {
                break;
            }
            $take = min($balance, (float) $t->debit);
            $days = abs((int) $today->diffInDays($t->date));
            $key = match (true) {
                $days <= 30 => 'a30',
                $days <= 60 => 'a60',
                $days <= 90 => 'a90',
                $days <= 180 => 'a180',
                default => 'a180p',
            };
            $buckets[$key] += $take;
            $balance -= $take;
        }

        return $buckets;
    }

    /**
     * ═══════════════════════════════════════════════════════════
     * الرصيد المتأخر عن ميعاد سداده
     * ═══════════════════════════════════════════════════════════
     *
     * ⚠️ **غير `aging()`.** الأعمار بتقول الفلوس دي بقالها كام يوم.
     * دي بتقول كام منها **عدّى ميعاده**. عميل بشروط 60 يوم وعليه
     * فاتورة عمرها 45 يوم بيبان في خانة «31-60» كأنه متأخر، وهو لسه
     * في مدته. الفرق ده هو اللي بيخلّي المتابعة تلاحق عميل ملتزم
     * وتسيب اللي فعلاً متأخر.
     *
     * ⚠️ **من غير شروط سداد مفيش تأخير.** لو العقد مامعاهوش أيام
     * سداد، بنرجّع `has_terms = false` بدل ما نفترض صفر — الافتراض
     * ده بيخلّي كل عميل آجل متأخر من أول يوم وكل الشاشة حمرا.
     *
     * ⚠️ التوزيع FIFO على نفس أساس `aging()` — نفس الرصيد بيتوزّع على
     * نفس الحركات بنفس الترتيب. لو الاتنين اختلفوا في الطريقة، شاشتين
     * بيوصفوا نفس الفلوس برقمين.
     *
     * @return array{amount: float, days: ?int, due_on: ?\Illuminate\Support\Carbon, has_terms: bool}
     */
    public function overdue(): array
    {
        $out = ['amount' => 0.0, 'days' => null, 'due_on' => null, 'has_terms' => false];

        // ⚠️ **من `paymentDays()` مش من العقد مباشرة** (2026-08-08).
        // كانت بتقرا `liveContract()->paymentDays()` بس — يعني العميل
        // الآجل اللي مالوش عقد (كل الكاش فان والجملة تقريباً) كان
        // `has_terms = false` والشاشة تقول «مفيش أيام سداد»، مهما كان
        // عليه فلوس بقالها شهور. `paymentDays()` بتقرا العقد الأول
        // وترجع لخانة العميل — فالتأخير بقى بيتحسب للكل.
        $days = $this->paymentDays();

        // ⚠️ **`allowsCredit()` كمان** (2026-08-08). `togglePayDays()`
        // في الفورم بيخبّي الخانتين ومابيمسحش قيمتهم — فعميل اتحوّل
        // كاش (أو اتصنّف `danger`) بيفضل شايل `payment_days` قديمة.
        // من غير الحارس ده، نفس الكارت كان بيقول «كاش» في الشارة
        // و«متأخر من 40 يوم» في الـKPI جنبها.
        // ⚠️ **`$days <= 0` كمان** — صفر يوم معناه «مستحق فوراً»،
        // والقسمة عليه في `$dueOf` تحت كانت هترمي 500.
        if ($days === null || ! $this->allowsCredit()) {
            return $out;
        }

        $out['has_terms'] = true;
        $balance = (float) $this->balance;

        if ($balance <= 0) {
            return $out;
        }

        // ═══════════════════════════════════════════════════════
        // ميعاد الاستحقاق لكل حركة — حسب الأساس
        // ═══════════════════════════════════════════════════════
        //
        // ⚠️ **الأساس `first_supply` كان بيدي ميعاد واحد للحساب كله
        // مدى الحياة** (تدقيق ٨/٨/٢٠٢٦): `first_activity_at + days`.
        // أول ما التاريخ ده يعدّي — وهو بيعدّي بعد شهر من أول توريد
        // ويفضل عادي للأبد — **الرصيد كله بيبان متأخر**، بما فيه
        // فاتورة اتكتبت النهارده. العميل الملتزم بيبقى أحمر في
        // الشاشة والمتابعة بتلاحقه بدل المتأخر الحقيقي.
        //
        // ⚠️ الصح إن «من أول توريد» **دورة بتتكرر** مش تاريخ واحد:
        // العميل بيسدّد كل `days` يوم، والعدّاد بيبدأ من أول توريد.
        // فالحركة بتستحق في **حد الدورة اللي بعدها**:
        //
        //     due(T) = أول_توريد + days × ceil((T − أول_توريد) / days)
        //
        // كده فاتورة النهارده بتستحق في حد الدورة الجاية (مش متأخرة)،
        // وفاتورة بقالها دورتين بتبان متأخرة بدورة كاملة. والفرق بين
        // الأساسين بقى **في الميعاد بس** — التوزيع FIFO واحد للاتنين
        // زي `aging()` بالظبط.
        $byInvoice = $this->paymentBasis() === Contract::DAYS_FROM_INVOICE;
        $anchor = $byInvoice ? null : $this->first_activity_at?->copy()->startOfDay();

        // ⚠️ مفيش أول توريد لسه = مفيش ميعاد. الافتراض إن النهارده هو
        // أول توريد كان بيدي ميعاد بيتحرك كل يوم.
        if (! $byInvoice && $anchor === null) {
            return $out;
        }

        $dueOf = function (Transaction $t) use ($byInvoice, $anchor, $days) {
            // صفر يوم = مستحق يوم الحركة نفسها، في الأساسين
            if ($days <= 0) {
                return $t->date->copy();
            }

            if ($byInvoice) {
                return $t->date->copy()->addDays($days);
            }

            // ⚠️ `max(1, ...)` — حركة في نفس يوم أول توريد (أو قبله في
            // الداتا المستوردة) لازم تاخد دورة كاملة، مش تستحق فوراً.
            $elapsed = (int) round($anchor->diffInDays($t->date->copy()->startOfDay(), false));
            $cycles = max(1, (int) ceil($elapsed / $days));

            return $anchor->copy()->addDays($days * $cycles);
        };

        // ═══ التوزيع FIFO — نفس ترتيب `aging()` بالظبط ═══
        $sales = $this->relationLoaded('transactions')
            ? $this->transactions->whereIn('kind', Transaction::DEBT_KINDS)->sortByDesc('date')
            : $this->transactions()->whereIn('kind', Transaction::DEBT_KINDS)->orderByDesc('date')->get();

        $oldest = null;

        foreach ($sales as $t) {
            if ($balance <= 0) {
                break;
            }

            $take = min($balance, (float) $t->debit);
            $balance -= $take;

            $due = $dueOf($t);

            if (! $due->isPast()) {
                continue;
            }

            $out['amount'] += $take;

            if ($oldest === null || $due->lessThan($oldest)) {
                $oldest = $due;
            }
        }

        $out['amount'] = round($out['amount'], 2);
        $out['due_on'] = $oldest;
        $out['days'] = $oldest === null ? null : max(0, (int) round($oldest->diffInDays(today())));

        return $out;
    }

    /**
     * الرصيد الافتتاحي — قيد واحد بس لكل عميل.
     *
     * ⚠️ **بيستبدل القيد القديم مش بيزوّده.** لو اتكتب مرتين والقيد
     * القديم فاضل، رصيد أول المدة بيتحسب مرتين ورصيد العميل يطلع
     * ضعف الحقيقة من غير ما حد يعرف من فين.
     *
     * ⚠️ المبلغ السالب = رصيد **دائن** (العميل دافع مقدماً) — بيتقيّد
     * في خانة الدائن. لو اتحط مدين بالسالب، كل جمع في كشف الحساب
     * بيطلع غلط لأن الأعمدة مفروض موجبة دايماً.
     */
    public function setOpeningBalance(float $amount, ?string $date = null, ?string $memo = null): ?Transaction
    {
        // ⚠️ **المسح والكتابة جوّه ترانزاكشن واحدة** (تدقيق ٨/٨/٢٠٢٦).
        // القديم كان بيتمسح **قبل** ما الجديد يتكتب وبرّه أي حماية —
        // لو الكتابة وقعت (خطأ فاليديشن على مستوى الداتابيز، انقطاع،
        // ديد لوك) العميل بيفضل **بلا رصيد أول مدة خالص** والقديم راح.
        // أسوأ حاجة في الباج ده إنه مابيرميش خطأ: الرصيد بيقل في صمت.
        return \Illuminate\Support\Facades\DB::transaction(function () use ($amount, $date, $memo) {
            $this->transactions()->where('kind', 'opening')->delete();

            if (abs($amount) < 0.01) {
                $this->recalculate();

                return null;
            }

            $txn = Transaction::create([
                'client_id' => $this->id,
                'date' => $date ?: today()->toDateString(),
                'memo' => $memo ?: __('flash.memo_opening'),
                'debit' => $amount > 0 ? round($amount, 2) : 0,
                'credit' => $amount < 0 ? round(abs($amount), 2) : 0,
                'kind' => 'opening',
            ]);

            $this->recalculate();

            return $txn;
        });
    }

    /** مبيعاته موزّعة على عائلات المنتجات */
    public function familySplit(): array
    {
        $rows = InvoiceItem::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->join('products', 'products.id', '=', 'invoice_items.product_id')
            ->where('invoices.client_id', $this->id)
            ->selectRaw('products.family, SUM(invoice_items.total) as amt, SUM(invoice_items.qty) as units')
            ->groupBy('products.family')
            ->get();

        return $rows->map(fn ($r) => [
            'family' => $r->family,
            'label' => (new Product(['family' => $r->family]))->familyLabel(),
            'amt' => (float) $r->amt,
            'units' => (int) $r->units,
        ])->all();
    }

    /** إعادة حساب الأرصدة من كشف الحساب */
    public function recalculate(): void
    {
        $rows = $this->transactions()->get();

        $this->purchases = $rows->where('kind', 'sale')->sum('debit');
        $this->collections = $rows->where('kind', 'collection')->sum('credit');
        $this->returns = $rows->where('kind', 'return')->sum('credit');
        $this->rebates = $rows->where('kind', 'rebate')->sum('credit');
        $this->settlements = $rows->where('kind', 'settlement')->sum('credit');
        $this->balance = $rows->sum('debit') - $rows->sum('credit');

        // ⚠️ حجز الضمان: نسبة من مستحقاتنا العميل بيمسكها كضمان لسحب
        // المرتجعات (Circle K 25%). هي **جزء من الرصيد** مش زيادة عليه،
        // بس مش متاحة للتحصيل — فبنخزنها عشان تبان في الشاشات ونعرف
        // كام فلوسنا محجوزة فعلاً بدل ما نفتكرها مديونية عادية.
        $pct = (float) ($this->liveContract()?->withholding_pct ?? 0);
        $this->withheld = $pct > 0 ? round(max((float) $this->balance, 0) * $pct, 2) : 0;

        $this->first_activity_at = $rows->min('date');
        $this->last_activity_at = $rows->max('date');
        $this->last_payment_at = $rows->where('kind', 'collection')->max('date');

        $this->save();
    }
}
