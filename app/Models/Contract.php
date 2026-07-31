<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contract extends Model
{
    use HasFactory;

    /**
     * أنواع العقود — مفاتيح ثابتة بتتترجم وقت العرض.
     * ⚠️ ممنوع نخزّن نص النوع، لأنه بيتخزن بلغة الواجهة وقت الإنشاء
     * فيظهر إنجليزي في الشاشة العربية والعكس.
     */
    /**
     * ═══ الأنواع اللي بتتعرض في شاشة عقد جديد ═══
     *
     * الترتيب مقصود: من الأقوى التزاماً للأضعف — عقد توريد فوق،
     * واتفاق بدون عقد تحت.
     */
    public const TYPE_CHOICES = [
        'supply',                   // عقد توريد
        'consignment',              // عقد بيع بالأمانة
        'authorised_distributor',   // عقد توزيع معتمد
        'exclusive_distribution',   // عقد توزيع حصري
        'commercial_agency',        // عقد وكالة تجارية
        'contract_manufacturing',   // عقد تصنيع للغير
        'agreement',                // اتفاق تجاري بدون عقد
    ];

    /**
     * أنواع قديمة مالهاش مكان في القايمة الجديدة — **بس لسه صالحة**.
     *
     * ⚠️ **ممنوع تتشال.** الـ22 عقد الحقيقيين اتقروا من الـPDF وفيهم
     * `supply_agreement` و`business_development` و`annual` و
     * `supplier_form`. لو شلناها من `TYPE_KEYS`:
     *
     *   • `typeLabel()` هترجّع المفتاح الخام في شاشة العقد
     *   • قاعدة `in:` هترفض حفظ العميل — يعني حتى تصليح تليفونه
     *     بيترفض لأن نوع عقده «مش من القايمة»
     *
     * مش معروضة في الدروب داون للعقد الجديد، بس بتفضل في القايمة لو
     * العقد اللي بنعدّله نوعه واحد منها.
     */
    public const TYPE_LEGACY = [
        'supply_agreement', 'business_development', 'annual', 'supplier_form',
    ];

    /** كل الأنواع الصالحة — للتحقق والعرض */
    public const TYPE_KEYS = [
        'supply' => true, 'consignment' => true, 'authorised_distributor' => true,
        'exclusive_distribution' => true, 'commercial_agency' => true,
        'contract_manufacturing' => true, 'agreement' => true,
        // قديمة — شوف TYPE_LEGACY فوق
        'supply_agreement' => true, 'business_development' => true,
        'annual' => true, 'supplier_form' => true,
    ];

    public const TYPE_DEFAULT = 'agreement';

    /**
     * ═══ مدة التعاقد ═══
     *
     * ⚠️ **المدة مش زينة — هي اللي بتحدد الفاليديشن على التواريخ.**
     * عقد سنة بتاريخ نهاية بعد شهرين معناه إما غلطة كتابة أو مدة
     * اتغيّرت ومحدش عدّل التاريخ. الاتنين بيخلّوا تنبيه التجديد يطلع
     * في الوقت الغلط، والخصومات تفضل شغالة بعد ما العقد خلص.
     *
     * `months` بتتحسب منها نهاية العقد تلقائياً من تاريخ البداية.
     * `min`/`max` مدى مقبول بالأيام — واسع عشان الشهور مش متساوية
     * (فبراير 28 ويناير 31) وحد ممكن يكتب «لحد آخر الشهر».
     *
     * `null` في `months` معناها **مفيش نهاية محسوبة**.
     */
    public const DURATIONS = [
        'month' => ['months' => 1, 'min' => 27, 'max' => 32],
        'quarter' => ['months' => 3, 'min' => 88, 'max' => 93],
        'half_year' => ['months' => 6, 'min' => 179, 'max' => 185],
        'year' => ['months' => 12, 'min' => 362, 'max' => 368],
        // ⚠️ أكتر من سنة مالهاش سقف — فيه عقود 3 و5 سنين
        'multi_year' => ['months' => 24, 'min' => 369, 'max' => null],
        // ⚠️ **مدة مخصصة — من غير أي فحص على الأيام.**
        //
        // المدد اللي فوق بتغطي فترات قياسية بس، والواقع مليان عقود
        // بتنتهي آخر السنة الميلادية مهما بدأت إمتى: عقد بدأ مارس
        // وبينتهي 31 ديسمبر = 278 يوم. مش شهر ولا 3 ولا 6 ولا سنة —
        // مايقعش في أي نافذة، وكان بيبقى **مستحيل يتحفظ**: كل مدة
        // ممكن يختارها بترفضه، وتواريخه جاية من عقد موقّع.
        // اتنين من الـ22 عقد الحقيقيين بالشكل ده.
        'custom' => ['months' => null, 'min' => null, 'max' => null],
        // ⚠️ مفتوح المدة: **بداية بس**. تاريخ نهاية على عقد مفتوح
        // تناقض — يا مفتوح يا ليه نهاية.
        'open' => ['months' => null, 'min' => null, 'max' => null],
        // ⚠️ تعامل بالطلب: **مفيش تواريخ خالص**. مفيش عقد أصلاً،
        // فيه تعامل بيحصل لما يحصل.
        'per_order' => ['months' => null, 'min' => null, 'max' => null],
    ];

    /**
     * المدد اللي **مالهاش تاريخ نهاية** أصلاً.
     *
     * ⚠️ **مش نفس «نهايتها مابتتحسبش».** `custom` نهايتها بتتكتب
     * بالإيد ومابتتحسبش من المدة — بس هي موجودة. لما كان الفرق ده
     * مبني على `months !== null` لوحده، العقد المخصص كانت نهايته
     * بتتصفّى عند الحفظ في صمت.
     */
    public const NO_END = ['open', 'per_order'];

    /** المدة دي ليها تاريخ نهاية؟ */
    public static function durationHasEnd(?string $duration): bool
    {
        return $duration !== null
            && isset(self::DURATIONS[$duration])
            && ! in_array($duration, self::NO_END, true);
    }

    /** نهاية المدة دي بتتحسب تلقائياً من البداية؟ */
    public static function durationComputesEnd(?string $duration): bool
    {
        return $duration !== null
            && (self::DURATIONS[$duration]['months'] ?? null) !== null;
    }

    /** المدة دي ليها تواريخ أصلاً؟ */
    public static function durationHasDates(?string $duration): bool
    {
        return $duration !== null && $duration !== 'per_order';
    }

    /**
     * تاريخ النهاية المحسوب من البداية والمدة.
     *
     * ⚠️ `subDay()` مقصود: عقد سنة بيبدأ 1 يناير 2026 بينتهي
     * **31 ديسمبر 2026** مش 1 يناير 2027 — وإلا بيبقى 366 يوم
     * والعقدين المتتاليين بيتداخلوا في يوم.
     */
    public static function computeEnd(?string $startsAt, ?string $duration): ?string
    {
        $months = self::DURATIONS[$duration]['months'] ?? null;

        if ($startsAt === null || $startsAt === '' || $months === null) {
            return null;
        }

        // ⚠️ **`addMonthsNoOverflow` مش `addMonths`.** Carbon 3 بيسمح
        // بالتجاوز افتراضياً: 31 يناير + شهر = 3 مارس (لأن فبراير 28).
        // الجافاسكربت في الشاشة بيقفل على آخر يوم في الشهر المستهدف،
        // فالاتنين كانوا بيدوا تاريخين مختلفين — الشاشة بتملا تاريخ
        // والسيرفر بيرفضه.
        return \Illuminate\Support\Carbon::parse($startsAt)
            ->addMonthsNoOverflow($months)->subDay()->toDateString();
    }

    /**
     * المدة متطابقة مع التواريخ؟ بترجّع رسالة الخطأ أو `null`.
     */
    public static function checkDuration(?string $duration, ?string $from, ?string $to): ?string
    {
        if ($duration === null || ! isset(self::DURATIONS[$duration])) {
            return null;
        }

        $spec = self::DURATIONS[$duration];

        // تعامل بالطلب — مفيش تواريخ
        if (! self::durationHasDates($duration)) {
            return ($from || $to) ? __('client.duration_no_dates') : null;
        }

        // مفتوح المدة — بداية بس
        if (! self::durationHasEnd($duration)) {
            return $to ? __('client.duration_open_has_end') : null;
        }

        // ⚠️ المدة المخصصة مالهاش نافذة أيام — التواريخ زي ما هي.
        if ($spec['min'] === null && $spec['max'] === null) {
            return null;
        }

        if (! $from || ! $to) {
            return null;   // الفاليديشن العادية بتمسك الناقص
        }

        // ⚠️ `round` **قبل** `(int)`، مش `(int)` لوحده.
        //
        // `diffInDays` في Carbon 3 بترجّع float بإشارة (منها `abs`).
        // والمنطقة الزمنية `Africa/Cairo` فيها توقيت صيفي، فالفرق
        // اللي بيعدّي على تغيير الساعة بيطلع 87.958 مش 88 — والقص
        // بـ`(int)` كان بيخليه 87. النتيجة: عقد ربع سنة حقيقي 88 يوم
        // بيترفض بحجة «أقل من 88».
        $days = abs((int) round(\Illuminate\Support\Carbon::parse($from)
            ->diffInDays(\Illuminate\Support\Carbon::parse($to)))) + 1;

        if ($spec['min'] !== null && $days < $spec['min']) {
            return __('client.duration_too_short', [
                'label' => __('client.duration_'.$duration),
                'days' => $days,
                'min' => $spec['min'],
            ]);
        }

        if ($spec['max'] !== null && $days > $spec['max']) {
            return __('client.duration_too_long', [
                'label' => __('client.duration_'.$duration),
                'days' => $days,
                'max' => $spec['max'],
            ]);
        }

        return null;
    }

    public function durationLabel(): string
    {
        return $this->duration
            ? __('client.duration_'.$this->duration)
            : '—';
    }

    /** نمط التسوية: مديونية وقت التوريد، أو بيع بالمبيع */
    public const MODE_INVOICE = 'invoice';
    public const MODE_CONSIGNMENT = 'consignment';

    /** أيام السداد بتتحسب من إمتى */
    public const DAYS_FROM_FIRST_SUPPLY = 'first_supply';
    public const DAYS_FROM_INVOICE = 'invoice';
    public const DAYS_FROM = [self::DAYS_FROM_FIRST_SUPPLY, self::DAYS_FROM_INVOICE];

    /**
     * ═══════════════════════════════════════════════════════════
     * بنود الخصم الجاهزة — اللي بتتعلّم بتشيك بوكس في فورم العقد
     * ═══════════════════════════════════════════════════════════
     *
     * كل بند بيتحوّل لصف في `contract_clauses`، و`recalcFromClauses()`
     * بتجمّعهم في النِسَب الثلاثة اللي السيستم بيشتغل بيها.
     *
     * ⚠️ **`invoice_discount` هو الوحيد اللي بيوصل للفاتورة.** الباقي
     * خصومات دورية بتتسوّى في وقت تاني. لو حد جمّعهم على خصم الفاتورة،
     * العميل بياخد خصمه مرتين والربح بيطلع بالسالب.
     *
     * ⚠️ `mode` بتقول الخانة اللي بتفتح: نسبة ولا مبلغ. البند اللي
     * أصله مبلغ ثابت (إيجار رف، مجلة) لو اتخزن كنسبة بيدخل في
     * `total_deduction_pct` ويحرق حساب الربحية كله.
     *
     * @var array<string, array{kind: string, basis: string, mode: string}>
     */
    public const CLAUSE_PRESETS = [
        // نسب
        'invoice_discount' => ['kind' => 'invoice_discount', 'basis' => 'per_invoice', 'mode' => 'pct'],
        'quarterly_rebate' => ['kind' => 'rebate', 'basis' => 'quarterly', 'mode' => 'pct'],
        'annual_rebate' => ['kind' => 'rebate', 'basis' => 'annual', 'mode' => 'pct'],
        'collection_fee' => ['kind' => 'collection', 'basis' => 'per_invoice', 'mode' => 'pct'],
        'withholding' => ['kind' => 'withholding', 'basis' => 'per_invoice', 'mode' => 'pct'],

        // مبالغ ثابتة
        'shelf_rent' => ['kind' => 'rent', 'basis' => 'annual', 'mode' => 'amount'],
        'magazine' => ['kind' => 'marketing', 'basis' => 'annual', 'mode' => 'amount'],
        'listing_fee' => ['kind' => 'listing_fee', 'basis' => 'one_off', 'mode' => 'amount'],
        'opening_fee' => ['kind' => 'opening_fee', 'basis' => 'one_off', 'mode' => 'amount'],
    ];

    public static function presetIsPct(string $preset): bool
    {
        return (self::CLAUSE_PRESETS[$preset]['mode'] ?? 'pct') === 'pct';
    }

    protected $fillable = [
        'client_id', 'group_id', 'number', 'chain', 'chain_en', 'type', 'type_key', 'duration',
        'discount', 'price_list',
        'withholding_pct', 'total_deduction_pct', 'settlement_mode',
        'terms', 'payment_days', 'payment_days_from', 'starts_at', 'ends_at', 'auto_renew', 'notice_days',
        'signed_ok', 'note', 'termination', 'renewal_note', 'file_path', 'clauses', 'active',
    ];

    protected function casts(): array
    {
        return [
            'discount' => 'decimal:4',
            'withholding_pct' => 'decimal:4',
            'total_deduction_pct' => 'decimal:4',
            'starts_at' => 'date',
            'ends_at' => 'date',
            'auto_renew' => 'boolean',
            'signed_ok' => 'boolean',
            'clauses' => 'array',
            'active' => 'boolean',
        ];
    }

    /**
     * ⚠️ حجز الضمان محسوب على العميل من نسبة العقد. لو النسبة اتغيرت
     * أو العقد اتوقف، عمود clients.withheld بيفضل على القيمة القديمة
     * لحد أول حركة فلوس — والشاشة بتعرضه كأنه حقيقة.
     */
    protected static function booted(): void
    {
        static::saved(function (Contract $contract) {
            // ⚠️ عند الإنشاء wasChanged بترجّع true لكل حاجة، فالسيدر كان
            // هيعيد حساب 44 فرع Circle K وهو أصلاً بيعمل كده في الآخر.
            if ($contract->wasRecentlyCreated) {
                return;
            }
            if (! $contract->wasChanged(['withholding_pct', 'active', 'ends_at'])) {
                return;
            }

            $clients = $contract->group_id
                ? Client::where('group_id', $contract->group_id)->get()
                : collect(array_filter([$contract->client]));

            foreach ($clients as $client) {
                $client->recalculate();
            }
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** العقد ممكن يكون للسلسلة كلها بدل عميل واحد */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ClientGroup::class, 'group_id');
    }

    public function dues(): HasMany
    {
        return $this->hasMany(ContractDue::class);
    }

    public function contractClauses(): HasMany
    {
        return $this->hasMany(ContractClause::class)->orderBy('sort')->orderBy('id');
    }

    // ==================== النِسَب ====================

    /**
     * ⚠️ الفرق الجوهري:
     *   discount             = خصم الفاتورة — ده **الوحيد** اللي Pricing بيطبقه
     *   total_deduction_pct  = كل النسب مجمّعة — للربحية الحقيقية، مش للفاتورة
     *
     * لو حد جمعهم على بعض في الفاتورة، العميل بياخد الخصومات الدورية مرتين.
     */
    public function invoiceDiscount(): float
    {
        return (float) $this->discount;
    }

    public function totalDeduction(): float
    {
        $stored = (float) $this->total_deduction_pct;

        return $stored > 0 ? $stored : $this->invoiceDiscount();
    }

    /** الفرق بين اللي بيتشال على الفاتورة واللي بيتشال فعلاً */
    public function hiddenDeduction(): float
    {
        return round(max($this->totalDeduction() - $this->invoiceDiscount(), 0), 4);
    }

    /** إعادة حساب الإجماليات من البنود — المصدر الوحيد للحق */
    public function recalcFromClauses(): void
    {
        $clauses = $this->contractClauses()->get()->filter(fn ($c) => $c->counts());

        $sum = fn (string ...$kinds) => round(
            $clauses->whereIn('kind', $kinds)->sum(fn ($c) => (float) $c->pct), 4
        );

        $this->forceFill([
            'discount' => $sum('invoice_discount'),
            'total_deduction_pct' => $sum(...ContractClause::DEDUCTION_KINDS),
            'withholding_pct' => $sum('withholding'),
        ])->save();
    }

    /** إجمالي الرسوم الثابتة السنوية — تكلفة العقد بره الخصم */
    public function annualFees(): float
    {
        return round($this->contractClauses
            ->filter(fn ($c) => $c->counts()
                && in_array($c->kind, ContractClause::FEE_KINDS, true)
                && in_array($c->basis, ['annual', 'one_off'], true))
            ->sum(fn ($c) => (float) $c->amount), 2);
    }

    public function monthlyFees(): float
    {
        return round($this->contractClauses
            ->filter(fn ($c) => $c->counts()
                && in_array($c->kind, ContractClause::FEE_KINDS, true)
                && $c->basis === 'monthly')
            ->sum(fn ($c) => (float) $c->amount), 2);
    }

    /** التزامات العقد السنوية = رسوم سنوية + شهرية × 12 */
    public function annualCommitment(): float
    {
        return round($this->annualFees() + $this->monthlyFees() * 12, 2);
    }

    public function isConsignment(): bool
    {
        return $this->settlement_mode === self::MODE_CONSIGNMENT;
    }

    public function isExpiring(int $days = 90): bool
    {
        return $this->ends_at !== null
            && $this->ends_at->lessThanOrEqualTo(now()->addDays($days));
    }

    public function isExpired(): bool
    {
        return $this->ends_at !== null && $this->ends_at->isPast();
    }

    /** أيام السداد — الرقم لو متسجل، وإلا نستخرجه من نص terms */
    public function paymentDays(): ?int
    {
        if ($this->payment_days) {
            return (int) $this->payment_days;
        }

        return preg_match('/(\\d+)/', (string) $this->terms, $m) ? (int) $m[1] : null;
    }

    public function paymentBasis(): string
    {
        return in_array($this->payment_days_from, self::DAYS_FROM, true)
            ? $this->payment_days_from
            : self::DAYS_FROM_FIRST_SUPPLY;
    }

    public function paymentBasisLabel(): string
    {
        return __('client.days_from_'.$this->paymentBasis());
    }

    /**
     * ميعاد استحقاق السداد.
     *
     * ⚠️ الرقم لوحده مالوش معنى من غير نقطة بداية. الاتفاق مع
     * الكي أكاونت إن العد بيبدأ من **أول توريد للعميل** مش من تاريخ
     * كل فاتورة — يعني ميعاد واحد لكل الحساب. اللي بيتعامل بالفاتورة
     * بيتحط له `payment_days_from = invoice` وساعتها بنعد من تاريخها.
     *
     * ⚠️ بيرجّع `null` لو مفيش أيام سداد **أو** لسه مفيش أول توريد.
     * الافتراض إن أول توريد هو النهارده كان بيدي ميعاد بيتحرك كل يوم.
     */
    public function dueDateFor(?Client $client = null, $invoiceDate = null): ?\Illuminate\Support\Carbon
    {
        $days = $this->paymentDays();

        if ($days === null) {
            return null;
        }

        if ($this->paymentBasis() === self::DAYS_FROM_INVOICE) {
            $from = $invoiceDate ? \Illuminate\Support\Carbon::parse($invoiceDate) : null;

            return $from?->copy()->addDays($days);
        }

        $client ??= $this->client;
        $first = $client?->first_activity_at;

        return $first ? $first->copy()->addDays($days) : null;
    }

    /**
     * بنود العقد الحرة كمصفوفة نضيفة.
     *
     * ⚠️ **لغة واحدة.** دي نصوص حرة بتتكتب مرة لعقد واحد — مش داتا
     * أساسية بتتكرر. القاعدة: النص الحر بيتكتب إنجليزي والخانة
     * بتقول كده.
     */
    public function clauseList(): array
    {
        return collect($this->clauses ?? [])
            ->map(fn ($c) => trim((string) $c))
            ->filter()
            ->values()
            ->all();
    }

    public function statusClass(): string
    {
        if (! $this->active) {
            return 'b-gray';
        }

        return match (true) {
            $this->isExpired() => 'b-red',
            $this->isExpiring(60) => 'b-orange',
            default => 'b-green',
        };
    }

    public function statusLabel(): string
    {
        if (! $this->active) {
            return __('client.contract_inactive');
        }

        return match (true) {
            $this->isExpired() => __('client.contract_expired'),
            $this->isExpiring(60) => __('client.contract_expiring'),
            default => __('client.contract_active'),
        };
    }

    /**
     * ميعاد آخر يوم نقدر نخطر فيه بعدم التجديد.
     * لو فات، العقد هيتجدد تلقائياً وإحنا مش واخدين بالنا.
     */
    public function noticeDeadline(): ?\Illuminate\Support\Carbon
    {
        if (! $this->ends_at || ! $this->notice_days) {
            return null;
        }

        return $this->ends_at->copy()->subDays((int) $this->notice_days);
    }

    public function noticeMissed(): bool
    {
        $deadline = $this->noticeDeadline();

        return $this->auto_renew && $deadline !== null && $deadline->isPast();
    }

    public function noticeDaysLeft(): ?int
    {
        $deadline = $this->noticeDeadline();

        return $deadline === null ? null : (int) round(today()->diffInDays($deadline, false));
    }

    public function daysLeft(): ?int
    {
        return $this->ends_at === null
            ? null
            : (int) round(today()->diffInDays($this->ends_at, false));
    }

    /**
     * اسم السلسلة بلغة الواجهة.
     *
     * ⚠️ في الإنجليزي بنرجّع chain_en أو **فاضي** — ممنوع الرجوع للعربي.
     * كان بيرجع chain العربي لما chain_en فاضي، فأي عقد اتعمل من الشاشة
     * (ومحدش بيملا chain_en) كان بيسرّب اسم عربي للواجهة الإنجليزية.
     */
    public function displayChain(): string
    {
        return app()->getLocale() === 'en'
            ? (string) ($this->chain_en ?? '')
            : (string) ($this->chain ?? '');
    }

    /**
     * نوع العقد مترجم.
     * ⚠️ type فيه النص العربي الأصلي، وtype_key هو المفتاح الثابت.
     * في الإنجليزي بنستخدم المفتاح — ممنوع نعرض النص العربي.
     */
    public function typeLabel(): string
    {
        if ($this->type_key) {
            return __('client.contract_type_'.$this->type_key);
        }

        // ⚠️ عقود قديمة اتخزنت بنص حر: بنعرضه في العربي بس (النص عربي)،
        // وفي الإنجليزي بنرجّع اللابل العام بدل ما نسرّب عربي.
        return app()->getLocale() === 'ar' ? (string) ($this->type ?? '') : __('client.contract');
    }

    public function settlementLabel(): string
    {
        return __('client.settlement_'.$this->settlement_mode);
    }

    public static function nextNumber(): string
    {
        $last = static::query()->orderByDesc('id')->value('number');
        $n = $last ? ((int) preg_replace('/\\D+/', '', $last)) + 1 : 1001;

        return 'CNT-'.$n;
    }
}
