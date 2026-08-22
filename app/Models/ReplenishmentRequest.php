<?php

namespace App\Models;

use App\Models\Concerns\HasDocumentNumber;

use App\Exceptions\Rejected;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * طلب ريفيل: البروموتر لقى الصنف ناقص من الرف والمخزن،
 * فبيطلب توريد للفرع — والمدير بينزّله على مندوب أو سواق.
 */
class ReplenishmentRequest extends Model
{
    use HasDocumentNumber, HasFactory;

    public const STATUSES = [
        // ⚠️ المسميات اتغيّرت مع فلو ١٥/٨ (مفيش أمر توريد): الطلب
        // بيتوافق عليه ← ينزل المخزن يتجهّز ← يدخل عهدة المندوب.
        'pending' => ['مستني موافقة المدير', 'b-orange'],
        'assigned' => ['تحت التجهيز في المخزن', 'b-blue'],
        'ready' => ['اتجهّز — مستني الاستلام', 'b-gold'],
        'delivered' => ['دخل عهدة المندوب', 'b-green'],
        'cancelled' => ['ملغي', 'b-red'],
    ];

    protected $fillable = [
        'number', 'client_id', 'merch_visit_id', 'visit_id', 'requested_by', 'status',
        'assigned_to', 'assigned_by', 'purchase_order_id', 'assigned_at', 'delivered_at', 'note',
    ];

    /**
     * مصدر الطلب — بروموتر من زيارة رف، ولا مندوب واقف عند العميل.
     *
     * ⚠️ **من المرساة مش من رول الطالب** — الرول ممكن يتغير بعدين،
     * والمرساة (زيارة رف / زيارة سيلز) بتفضل شاهدة على اللحظة.
     */
    public function origin(): string
    {
        return $this->visit_id !== null ? 'rep' : 'promoter';
    }

    public function originLabel(): string
    {
        return __('field.replenishment_origin_'.$this->origin());
    }

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function merchVisit(): BelongsTo
    {
        return $this->belongsTo(MerchVisit::class);
    }

    /** زيارة السيلز اللي الطلب اتعمل منها — للطلبات من عند العميل */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function promoter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * نفس `promoter()` بالظبط، بس بالاسم الصح.
     *
     * ⚠️ الطلب بقى **أي مندوب** يعمله من عند العميل مش البروموتر بس
     * (توحيد الريفيل). `promoter` اتساب زي ما هو عشان الكود القديم
     * مايتكسرش، والكود الجديد يستخدم ده.
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * المدير اللي وافق على الطلب ونزّله (سؤال المالك ١٥ أغسطس).
     * الموافقة قرار بيحرّك بضاعة ويولّد أمر توريد بقيمة مالية —
     * فليها صاحب موثّق زي موافقة الحسابات على أمر التوريد.
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReplenishmentItem::class);
    }

    public function statusLabel(): string
    {
        // المسمى بييجي من lang/{ar,en}/enums.php — والثابت القديم fallback
        $key = 'enums.replenishment_status.'.$this->status;

        return \Illuminate\Support\Facades\Lang::has($key)
            ? __($key)
            : (self::STATUSES[$this->status][0] ?? $this->status);
    }

    public function statusClass(): string
    {
        return self::STATUSES[$this->status][1] ?? 'b-gray';
    }

    public function qtyTotal(): int
    {
        return (int) $this->items->sum('qty');
    }

    /**
     * ⚠️ `ready` انضمت للحالات المفتوحة (فلو ١٥/٨): الطلب اتجهّز في
     * المخزن بس المندوب لسه مااستلمهوش — البضاعة لسه بره عهدته،
     * فالطلب لسه شغل مفتوح على حد ما يستلم.
     */
    public function isOpen(): bool
    {
        return in_array($this->status, ['pending', 'assigned', 'ready'], true);
    }

    /** أمر التجهيز اللي الطلب نزل بيه المخزن (فلو ١٥/٨) */
    public function pickOrder(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(PickOrder::class);
    }

    public static function nextNumber(): string
    {
        // ⚠️ أكبر رقم مش آخر صف — شوف `HasDocumentNumber`
        return static::nextDocumentNumber('RPL-', 5001);
    }

    /**
     * ═══════════════════════════════════════════════════════════
     * الموافقة على الطلب — أمر تجهيز للمخزن، **مش** أمر توريد
     * إعادة بناء ١٥ أغسطس ٢٠٢٦
     * ═══════════════════════════════════════════════════════════
     *
     * قرار المالك بالنص: «طلبات الريفيل دي تبقى ريبلانشمنت عادي
     * وماتاخدش PO ولا تتعامل المعاملة دي … المندوب بيطلب، ينزل
     * ريكويست، لما يتوافق عليه من التشانل مانجر ينزل في المخزن
     * علشان يتجهز، وبعد كده يظهر للمندوب إن طلب الريفيل بتاعه
     * اتجهز. لا يدخل في الـPO لا يدخل في الحسابات».
     * وعلى سؤال «الطلب بينتهي فين؟» رد: «في عربية المندوب بعد
     * تجهيز المخزن مش قبل».
     *
     * ═══ إيه اللي اتغيّر وليه ═══
     *
     * الفلو القديم كان بيعمل `PurchaseOrder` بأسعار وضريبة وخصم،
     * والتسليم بيكتب قيد `sale` على حساب الفرع. يعني **طلب تحميل
     * عربية كان بيتسجّل كبيعة للعميل** — ده كان بيلوّث:
     *   · كشف حساب الفرع بمديونية مالهاش أصل
     *   · تصفية المندوب («أوامر توريد اتسلّمت»)
     *   · الضريبة والفاتورة الإلكترونية
     *   · شاشة الأوامر بصفوف مش أوامر عملاء أصلاً
     *
     * دلوقتي الطلب بيفضل طلب بضاعة من أوله لآخره: الموافقة بترفع
     * **أمر تجهيز** (`PickOrder`) على المخزن، أمين المخزن بيجهّز،
     * المندوب بيستلم في **عهدته**، وخلاص. البيع للعميل بيحصل بعد
     * كده بفاتورة عادية زي أي بضاعة تانية في العربية.
     *
     * ⚠️ **مفيش `Transaction` ولا `PurchaseOrder` في المسار ده
     * خالص** — لا هنا ولا في أي مكان بيتنده منه.
     *
     * ⚠️ **بيرفع أمر تجهيز دايماً، مايبصّش على العربية الأول.**
     * `PickOrderController::fulfil` بتتخطى المخزن لو البضاعة معاه
     * أصلاً — وده صح لأمر توريد (البضاعة رايحة للعميل حالاً)، لكن
     * غلط هنا: الطلب ده **طلب تحميل** غرضه يزوّد رصيد العربية،
     * ولو اتخطى المخزن يبقى مااتنفّذش. المالك حدّد: «بعد تجهيز
     * المخزن مش قبل».
     *
     * ⚠️ `$priceMode` اتساب في التوقيع عن قصد: الأبلكيشن القديم
     * على تليفونات المناديب لسه بيبعته، وشيله كان هيرمي
     * `ArgumentCountError` على كل موافقة من نسخة قديمة. بقى
     * متجاهَل — مافيش سعر في الفلو ده أصلاً.
     *
     * @param  string  $priceMode  متجاهَل — باقي للتوافق مع الأبلكيشن القديم
     * @param  User|null  $actor  المدير اللي بيوافق — بيتفحص سكوبه وبيتسجّل
     */
    public function assignTo(User $assignee, string $priceMode = 'client', ?User $actor = null): PickOrder
    {
        // الرسايل دي بترجع للأبلكيشن كـ message في رد 422، فلازم تكون مترجمة
        if ($this->status !== 'pending') {
            throw new Rejected(__('api.request_already_assigned'));
        }

        // ⚠️ **`exists:users,id` في الكنترولرز مش كفاية** (تدقيق ٨/٨):
        // كان ينفع الطلب يتنزّل على محاسب مالوش عهدة أصلاً، أو على
        // حساب موقوف. الفحص هنا مش في الكنترولر عشان الويب
        // والأبلكيشن الاتنين يعدّوا عليه.
        if (! $assignee->active || ! in_array($assignee->role, User::FIELD_WORK_ROLES, true)) {
            throw new Rejected(__('field.not_a_field_role'));
        }

        // ⚠️ وسكوب الفاعل لو اتبعت — الراوت بتاع الويب كان بلا حارس
        // قناة خالص، والتوأم في الـAPI بيفحص الطلب مش المستلم.
        if ($actor !== null) {
            \App\Support\Scope::assertRep($actor, $assignee, $this->client);
        }

        $this->loadMissing(['client', 'items.product', 'promoter']);

        $qtyByProduct = [];

        foreach ($this->items as $item) {
            if ($item->product === null) {
                continue;   // صنف اتمسح من الكتالوج — بنتخطاه بدل ما نقع
            }

            $pid = (int) $item->product_id;
            $qtyByProduct[$pid] = ($qtyByProduct[$pid] ?? 0) + (int) $item->qty;
        }

        if ($qtyByProduct === []) {
            throw new Rejected(__('stock.pick_no_items'));
        }

        // ⚠️ مخزن العهدة المفتوحة، وإلا الفرع الافتراضي — نفس اختيار
        // `PickOrderController::fulfil` بالحرف عشان المسارين مايفترقوش.
        $warehouse = $assignee->currentCustody()?->warehouse ?? Warehouse::defaultBranch();

        if ($warehouse === null) {
            throw new Rejected(__('stock.no_warehouse'));
        }

        $pick = DB::transaction(function () use ($assignee, $actor, $qtyByProduct, $warehouse) {
            // ⚠️ **جوه الترانزاكشن**: لو المخزن مافيهوش رصيد، `raise`
            // بترجّع خطأ والموافقة كلها بترجع — فالمدير يشوف السبب
            // بدل ما الطلب يتقفل والبضاعة ماتخرجش.
            $raised = PickOrder::raise(
                $warehouse,
                $assignee,
                $qtyByProduct,
                PickOrder::PURPOSE_REPLENISHMENT,
                $actor,
                ['replenishment_request_id' => $this->id],
            );

            if ($raised['error'] !== null) {
                throw new Rejected($raised['error']);
            }

            $this->update([
                'status' => 'assigned',
                'assigned_to' => $assignee->id,
                // ⚠️ الموافق كان مابيتسجّلش خالص (سؤال المالك ١٥/٨).
                'assigned_by' => $actor?->id,
                'assigned_at' => now(),
            ]);

            return $raised['order'];
        });

        // ═══ إشعار المندوب: طلبك اتوافق عليه وتحت التجهيز ═══
        // ⚠️ اللينك على **أمر التجهيز** مش على أمر توريد — مافيش
        // أمر توريد في الفلو ده خالص.
        AppNotification::send(
            $assignee,
            fn () => __('field.notif_rpl_approved_title', ['number' => $this->number]),
            fn () => __('field.notif_rpl_approved_body', [
                'client' => $this->client->displayName(),
                'pick' => $pick->number,
            ]),
            good: true,
            link: AppNotification::pickLink($pick->id),
        );

        // ═══ وإشعار الطالب لو مش هو نفسه المستلم ═══
        if ((int) $this->requested_by !== (int) $assignee->id) {
            AppNotification::send(
                $this->promoter,
                fn () => __('field.notif_replenishment_assigned_title', [
                    'number' => $this->number,
                    'name' => $assignee->displayName(),
                ]),
                fn () => __('field.notif_replenishment_assigned_body', [
                    'client' => $this->client->displayName(),
                ]),
                good: true,
                link: AppNotification::replenishmentLink($this->id),
            );
        }

        return $pick;
    }

    /**
     * إلغاء/رفض الطلب + إشعار الطالب — المكان الوحيد للإلغاء.
     *
     * ⚠️ الويب كان بيلغي **من غير أي إشعار** والـAPI بيلغي وبيبلّغ —
     * فالطالب اللي طلبه اتلغى من الداش بورد كان بيفضل مستني للأبد.
     * الاتنين بقوا بينده هنا عشان الفلو مايتفرّعش لنسختين.
     */
    public function cancelAndNotify(?string $note = null): void
    {
        $this->update(['status' => 'cancelled']);

        // ⚠️ لينك الطالب حسب مصدره — طلب المندوب لينكه الرئيسية
        // (مالوش تاب ريفيل)، والبروموتر ليه تاب ريفيل فلينكه ليه.
        AppNotification::send(
            $this->promoter,
            fn () => __('field.notif_replenishment_rejected_title', [
                'number' => $this->number,
            ]),
            fn () => $note ?: __('field.notif_replenishment_rejected_body'),
            good: false,
            link: $this->origin() === 'rep'
                ? null
                : AppNotification::replenishmentLink($this->id),
        );
    }

    /** مديرو القناة اللي المفروض يشوفوا الطلب ده */
    public function managers()
    {
        // ═══ عقيدة النوتفيكيشن (بلاغ المالك ٢٢/٨) ═══
        // «صاحب الشغل ومديره فقط لا غير.» كانت بتبعت لكل مديري قناة
        // العميل — فمدير تاني بيشارك نفس القناة كان بيستلم طلبات
        // بضاعة فريق مش بتاعه. بقت: الأدمنز + **مدير صاحب الطلب
        // نفسه** (`requested_by → manager_id`).
        //
        // فولباك مقصود: طالب من غير مدير متسكّن → مديري قناة العميل
        // (زي الأول) — أحسن ما الطلب يضيع في الصمت ومحدش يوافق عليه.
        $mgrId = $this->requester?->manager_id;

        if ($mgrId !== null) {
            return User::query()
                ->where('active', true)
                ->where(fn ($q) => $q->where('role', 'admin')->orWhere('id', $mgrId))
                ->get();
        }

        $channelId = $this->client?->channel_id;

        return User::query()
            ->whereIn('role', ['admin', 'manager'])
            ->where('active', true)
            ->when($channelId, fn ($q) => $q->where(fn ($w) => $w
                ->where('role', 'admin')
                ->orWhereHas('channels', fn ($c) => $c->where('channels.id', $channelId))
            ))
            ->get();
    }
}
