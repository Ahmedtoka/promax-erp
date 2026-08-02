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
     * دورة الإقرار الضريبي للعميل.
     *
     * ⚠️ دي **مش** بتغيّر حساب الضريبة على الفاتورة — الضريبة بتتحسب
     * لكل فاتورة على حدة في `Services\Tax`. الدورة دي بتحدد إمتى
     * بنجمّع فواتيره ونرفعها للبورتال، ومتى نطالبه بالخصم الخاص.
     */
    public const TAX_CYCLES = ['monthly', 'quarterly', 'annual'];

    protected $fillable = [
        'code', 'name', 'name_en', 'phone', 'address', 'zone_id', 'rep_id', 'manager_id',
        'contacts', 'category', 'status',
        'channel_id', 'group_id', 'branch_id', 'sub_channel', 'parent_id', 'uses_channel_discount',
        'price_list', 'price_list_id', 'taxable', 'tax_rate', 'tax_id', 'eta_type', 'tax_cycle',
        'governorate', 'location_url', 'lat', 'lng',
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
            'first_activity_at' => 'date',
            'last_activity_at' => 'date',
            'last_payment_at' => 'date',
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
        $live = fn (?Contract $c) => $c !== null && $c->active && ! $c->isExpired();

        $this->loadMissing(['contract', 'group.contract']);

        if ($live($this->contract)) {
            return $this->contract;
        }

        $fromGroup = $this->group?->contract;

        return $live($fromGroup) ? $fromGroup : null;
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

    /** كود عميل جديد مش متكرر */
    public static function nextCode(): string
    {
        $last = static::query()->orderByDesc('id')->value('code');
        $n = $last ? ((int) preg_replace('/\D+/', '', $last)) + 1 : 1001;

        return 'CL-'.$n;
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
        // 1. العقد — اتفاق مكتوب فبيغلب على أي حاجة، بشرط يكون سارٍ ومش منتهي.
        // ⚠️ خصم الفاتورة بس. الخصومات الدورية بتتسوّى بعدين ومالهاش دعوة بالفاتورة.
        $contract = $this->liveContract();
        if ($contract && (float) $contract->discount > 0) {
            return (float) $contract->discount;
        }

        // 2. خصم خاص متحدد على العميل نفسه
        if ((float) $this->discount > 0) {
            return (float) $this->discount;
        }

        // ⚠️ **مفيش خطوة للسلسلة.** قرار 2026-08-01: السلسلة مكان
        // بنجمع فيه الفروع تحت اسم واحد عشان نشوف إجمالياتها — مش
        // كيان تجاري ليه شروطه. كل فرع بيتفاوض لوحده وليه عقده وخصمه،
        // وخصم على مستوى السلسلة كان بيتجاهل اتفاق الفرع.
        //
        // خصومات السلاسل القديمة اتنقلت على فروعها في مايجريشن
        // `000028_drop_group_discount` قبل ما العمود يتشال.

        // 3. مفيش خصم — سعر القائمة كامل
        return 0.0;
    }

    public function hasLocation(): bool
    {
        return $this->lat !== null && $this->lng !== null;
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
        $contract = $this->liveContract();
        if ($contract && (float) $contract->discount > 0) {
            return 'contract';
        }
        if ((float) $this->discount > 0) {
            return 'custom_discount';
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

    /** العميل الخطر لازم يشتري كاش */
    public function cashOnly(): bool
    {
        return $this->category === 'danger';
    }

    public function collectionRate(): float
    {
        return (float) $this->purchases > 0
            ? (float) $this->collections / (float) $this->purchases
            : 0;
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
            ? $this->transactions->where('debit', '>', 0)->sortByDesc('date')
            : $this->transactions()->where('debit', '>', 0)->orderByDesc('date')->get();

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

        $contract = $this->liveContract();
        $days = $contract?->paymentDays();

        if ($days === null) {
            return $out;
        }

        $out['has_terms'] = true;
        $balance = (float) $this->balance;

        if ($balance <= 0) {
            return $out;
        }

        // ═══ الأساس «أول توريد» = ميعاد واحد للحساب كله ═══
        if ($contract->paymentBasis() !== Contract::DAYS_FROM_INVOICE) {
            $due = $contract->dueDateFor($this);
            $out['due_on'] = $due;

            // ⚠️ مفيش أول توريد لسه = مفيش ميعاد. الافتراض إن النهارده
            // هو أول توريد كان بيدي ميعاد بيتحرك كل يوم.
            if ($due === null || ! $due->isPast()) {
                return $out;
            }

            $out['amount'] = round($balance, 2);
            $out['days'] = max(0, (int) round($due->diffInDays(today())));

            return $out;
        }

        // ═══ الأساس «تاريخ كل فاتورة» = FIFO زي `aging()` ═══
        $sales = $this->relationLoaded('transactions')
            ? $this->transactions->where('debit', '>', 0)->sortByDesc('date')
            : $this->transactions()->where('debit', '>', 0)->orderByDesc('date')->get();

        $oldest = null;

        foreach ($sales as $t) {
            if ($balance <= 0) {
                break;
            }

            $take = min($balance, (float) $t->debit);
            $balance -= $take;

            $due = $t->date->copy()->addDays($days);

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
