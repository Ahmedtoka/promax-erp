<?php

namespace App\Models;

use App\Exceptions\Rejected;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * طلب ريفيل: البروموتر لقى الصنف ناقص من الرف والمخزن،
 * فبيطلب توريد للفرع — والمدير بينزّله على مندوب أو سواق.
 */
class ReplenishmentRequest extends Model
{
    use HasFactory;

    public const STATUSES = [
        'pending' => ['مستني التوزيع', 'b-orange'],
        'assigned' => ['اتنزّل على مندوب', 'b-blue'],
        'delivered' => ['اتورد', 'b-green'],
        'cancelled' => ['ملغي', 'b-red'],
    ];

    protected $fillable = [
        'number', 'client_id', 'merch_visit_id', 'requested_by', 'status',
        'assigned_to', 'purchase_order_id', 'assigned_at', 'delivered_at', 'note',
    ];

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

    public function promoter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
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

    public function isOpen(): bool
    {
        return in_array($this->status, ['pending', 'assigned'], true);
    }

    public static function nextNumber(): string
    {
        $last = static::query()->orderByDesc('id')->value('number');
        $n = $last ? ((int) preg_replace('/\D+/', '', $last)) + 1 : 5001;

        return 'RPL-'.$n;
    }

    /**
     * تحويل الطلب لأمر توريد وتنزيله على مندوب/سواق.
     * ده المكان الوحيد اللي بيعمل التحويل — الويب والأبلكيشن الاتنين بينادوا عليه،
     * عشان الفلو ما يتفرّعش لنسختين بيختلفوا مع الوقت.
     *
     * @param  string  $priceMode  channel | old | new
     * @param  User|null  $actor  اللي بينزّل الطلب — لو اتبعت بيتفحص سكوبه
     */
    public function assignTo(User $assignee, string $priceMode = 'channel', ?User $actor = null): PurchaseOrder
    {
        // الرسايل دي بترجع للأبلكيشن كـ message في رد 422، فلازم تكون مترجمة
        if ($this->status !== 'pending') {
            throw new Rejected(__('api.request_already_assigned'));
        }

        // ⚠️ **`exists:users,id` في الكنترولرز مش كفاية** (تدقيق ٨/٨):
        // كان ينفع PO يتنزّل على محاسب مالوش عهدة أصلاً، أو على حساب
        // موقوف. الفحص هنا مش في الكنترولر عشان الويب والأبلكيشن
        // الاتنين يعدّوا عليه — المسار ده هو المكان الوحيد للتحويل.
        if (! $assignee->active || ! in_array($assignee->role, User::FIELD_ROLES, true)) {
            throw new Rejected(__('field.not_a_field_role'));
        }

        // ⚠️ وسكوب الفاعل لو اتبعت — الراوت بتاع الويب كان بلا حارس
        // قناة خالص، والتوأم في الـAPI بيفحص الطلب مش المستلم.
        if ($actor !== null) {
            \App\Support\Scope::assertRep($actor, $assignee, $this->client);
        }
        // ⚠️ `channel` اتغيّرت لـ`client`. القناة مابقاش لها سعر ولا
        // خصم — التسعير بيتحدد من قائمة العميل وخصمه (عقد/خاص/سلسلة).
        // القيمة القديمة لسه مقبولة عشان أي طلب معلّق في الأبلكيشن
        // اتبعت قبل التحديث مايترفضش.
        if (! in_array($priceMode, ['client', 'channel', 'old', 'new'], true)) {
            throw new Rejected(__('api.unknown_price_mode'));
        }

        $this->loadMissing(['client', 'items.product', 'promoter']);

        $po = \Illuminate\Support\Facades\DB::transaction(function () use ($assignee, $priceMode) {
            $client = $this->client;

            $po = PurchaseOrder::create([
                'number' => PurchaseOrder::nextNumber(),
                'client_id' => $client->id,
                'source' => PurchaseOrder::SOURCE_REPLENISHMENT,
                'address' => $client->address,
                'assigned_to' => $assignee->id,
                'status' => 'pending',
                // ⚠️ تسعيرة العميل بتتحسب على السطور وبتتخزن فيها،
                // فالـPO بيتسجل بقائمة ثابتة عشان مايعيدش الحساب عند
                // التسليم ويطلع رقم تاني.
                'price_mode' => in_array($priceMode, ['client', 'channel'], true) ? 'new' : $priceMode,
                'total' => 0,
            ]);

            $rows = [];
            foreach ($this->items as $item) {
                $product = $item->product;
                if ($product === null) {
                    continue; // صنف اتمسح من الكتالوج — بنتخطاه بدل ما نقع
                }
                // ⚠️ `priceFor($client)` بيمشي على الدوكترين: قائمة
                // العميل ← خصمه (عقد/خاص/سلسلة). القناة مالهاش دخل.
                $price = in_array($priceMode, ['client', 'channel'], true)
                    ? $client->priceFor($product)
                    : $product->priceFor($priceMode);

                // ⚠️ **سعر صفر = أمر مرفوض** (تدقيق ٨/٨/٢٠٢٦). نفس
                // الحارس اللي في `OpsController::fillPoItems` واتنسي
                // هنا — والصنف اللي مش متسعّر في قايمة العميل كان
                // بيعدّي بسطر 0.00 من غير أي رسالة. السواق بيوصّل
                // بضاعة ببلاش والرقم مابيبانش غير في مراجعة آخر الشهر.
                if ((float) $price <= 0) {
                    throw new Rejected(__('api.product_not_priced', [
                        'product' => $product->displayName(),
                    ]));
                }

                $lineTotal = round($item->qty * $price, 2);

                // ⚠️ الضريبة هنا كمان، مش في `OpsController` بس. ده تاني
                // مسار بيولّد أمر توريد، ولو ساب الضريبة صفر فـ `payable()`
                // بترجع الصافي في صمت، والسواق بيحصّل ناقص قيمة الضريبة
                // اللي بروماكس بتدفعها للمصلحة برضو.
                $taxRate = \App\Services\Tax::rate($client, $product);
                $lineTax = \App\Services\Tax::on($lineTotal, $client, $product);

                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'product_id' => $product->id,
                    'qty' => $item->qty,
                    'price' => $price,
                    'total' => $lineTotal,
                    'tax_rate' => $taxRate,
                    'tax' => $lineTax,
                ]);

                $rows[] = ['total' => $lineTotal, 'tax' => $lineTax];
            }

            $sums = \App\Services\Tax::totals($rows);
            $total = $sums['net'];

            $po->update([
                'total' => $sums['net'],
                'tax_total' => $sums['tax'],
                'grand_total' => $sums['grand'],
            ]);

            $this->update([
                'status' => 'assigned',
                'assigned_to' => $assignee->id,
                'purchase_order_id' => $po->id,
                'assigned_at' => now(),
            ]);

            return $po;
        });

        AppNotification::send(
            $assignee,
            fn () => __('field.notif_replenishment_new_title', ['number' => $po->number]),
            fn () => __('field.notif_replenishment_new_body', [
                'client' => $this->client->displayName(),
                'amount' => number_format((float) $po->total),
            ]),
            link: \App\Models\AppNotification::poLink($po->id),
        );

        AppNotification::send(
            $this->promoter,
            fn () => __('field.notif_replenishment_assigned_title', [
                'number' => $this->number,
                'name' => $assignee->name,
            ]),
            fn () => __('field.notif_replenishment_assigned_body', [
                'client' => $this->client->displayName(),
            ]),
            good: true,
            link: \App\Models\AppNotification::replenishmentLink($this->id),
        );

        return $po;
    }

    /** مديرو القناة اللي المفروض يشوفوا الطلب ده */
    public function managers()
    {
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
