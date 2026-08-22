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
 * أمر تحويل — مستند واحد بتلات اتجاهات.
 * Stock transfer — ONE document, three directions.
 *
 * ═══ الاتجاهات (`kind`) ═══
 *   wh_wh    🏭→🏭  مخزن لمخزن — المصنع بيبعت للفرع.
 *                   الفلو: بعت (sent) → الفرع يستلم ويأكد (received)،
 *                   والاستلام بيولّد GRN وباتشات في مخزن الفرع.
 *   rep_wh   🚐→🏭  مندوب بيرجّع بضاعة من عربيته للمخزن (١٤/٨).
 *   rep_rep  🚐→🚐  بضاعة بتتنقل من عربية لعربية (١٤/٨).
 *
 * ⚠️ **مستند واحد مش تلاتة.** نفس الترقيم ونفس الشاشة ونفس الورقة
 * ونفس الصلاحيات — جدول موازي كان معناه إن أي تعديل على الورقة أو
 * على الحارس يتعمل مرتين، والمرة اللي بتتنسى بتسيب باب مفتوح.
 *
 * ⚠️ **التحويلات الميدانية بتتنفّذ في خطوة واحدة** (`received`) —
 * مش `sent` بعدين `received`. السبب: البضاعة بتتسلّم إيد بإيد لحظة
 * عمل المستند (المندوب واقف عند شباك المخزن أو قدام زميله)، وحالة
 * «في الطريق» كانت هتخلق بضاعة مالهاش مالك: خرجت من العهدة وماوصلتش
 * الرف. الورقة المطبوعة فيها إمضاء الطرفين.
 *
 * ⚠️ **السبب (`reason`) إجباري في كل تحويل جديد**، أياً كان الاتجاه —
 * `nullable` في السكيما للشحنات القديمة بس.
 */
class StockTransfer extends Model
{
    use HasDocumentNumber, HasFactory;

    public const STATUSES = [
        'sent' => 'b-orange',
        'received' => 'b-green',
        'cancelled' => 'b-red',
    ];

    /** الاتجاه => [شارة الاتجاه، كلاس البادج] */
    public const KINDS = [
        'wh_wh' => ['🏭→🏭', 'b-gray'],
        'rep_wh' => ['🚐→🏭', 'b-blue'],
        'rep_rep' => ['🚐→🚐', 'b-purple'],
    ];

    protected $fillable = [
        'number', 'kind', 'from_warehouse_id', 'from_user_id',
        'to_warehouse_id', 'to_user_id', 'status',
        'sent_on', 'received_on', 'sent_by', 'received_by', 'created_by', 'carrier_name',
        'goods_receipt_id', 'notes', 'reason',
    ];

    protected function casts(): array
    {
        return ['sent_on' => 'date', 'received_on' => 'date'];
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** المندوب اللي البضاعة طلعت من عربيته */
    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    /** المندوب اللي البضاعة نزلت عربيته */
    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function statusLabel(): string
    {
        return __('stock.transfer_status_'.$this->status);
    }

    public function statusClass(): string
    {
        return self::STATUSES[$this->status] ?? 'b-gray';
    }

    // ==================== الاتجاه ====================

    public function kindKey(): string
    {
        $kind = (string) ($this->kind ?: 'wh_wh');

        return array_key_exists($kind, self::KINDS) ? $kind : 'wh_wh';
    }

    public function kindArrow(): string
    {
        return self::KINDS[$this->kindKey()][0];
    }

    public function kindClass(): string
    {
        return self::KINDS[$this->kindKey()][1];
    }

    public function kindLabel(): string
    {
        return __('stock.kind_'.$this->kindKey());
    }

    /** تحويل ميداني (طرفه الأول عربية مندوب) */
    public function isVan(): bool
    {
        return in_array($this->kindKey(), ['rep_wh', 'rep_rep'], true);
    }

    /** اسم الطرف المرسل — مخزن أو مندوب */
    public function fromLabel(): string
    {
        return $this->isVan()
            ? ($this->fromUser?->displayName() ?? '—')
            : ($this->fromWarehouse?->displayName() ?? '—');
    }

    /** اسم الطرف المستقبِل — مندوب لو rep_rep، وإلا مخزن */
    public function toLabel(): string
    {
        return $this->kindKey() === 'rep_rep'
            ? ($this->toUser?->displayName() ?? '—')
            : ($this->toWarehouse?->displayName() ?? '—');
    }

    /**
     * البضاعة اللي طلعت من مخزن ولسه مستلمتش — «في الطريق».
     *
     * ⚠️ **محسوبة مش متخزنة.** عمود `in_transit_qty` على `stocks` كان
     * هيبقى رقم رابع لازم يتزامن مع التلاتة اللي موجودين، وأول تحويل
     * يتلغى أو يتعدّل من غير ما حد يفتكر العمود بيخلّيه يكدب للأبد.
     * الحساب من التحويلات المفتوحة نفسها مايقدرش يختلف معاها.
     *
     * @param  int|null  $warehouseId  مخزن واحد، أو null لكل المخازن
     * @return array<int, int> [warehouse_id => qty] لو مافيش مخزن محدد،
     *                         و[product_id => qty] لو فيه
     */
    public static function inTransit(?int $warehouseId = null): array
    {
        $key = $warehouseId === null ? 'stock_transfers.from_warehouse_id' : 'i.product_id';

        // ⚠️ `selectRaw` + `pluck` على اسم مستعار — `pluck(DB::raw(...))`
        // بترمي الاسم الخام في `SELECT` وبتحاول تقراه كعمود بنفس النص،
        // فبترجّع مصفوفة فاضية في صمت.
        return static::query()
            ->join('stock_transfer_items as i', 'i.stock_transfer_id', '=', 'stock_transfers.id')
            ->where('stock_transfers.status', 'sent')
            // ⚠️ **مخزن لمخزن بس** (١٤/٨). التحويلات الميدانية بتتنفّذ
            // في خطوة واحدة فمالهاش حالة «في الطريق» أصلاً — ولو حصل
            // ودخلت هنا كانت هتقول إن بضاعة طالعة من رفوف مخزن وهي
            // أصلاً في عربية مندوب من إمبارح.
            ->where('stock_transfers.kind', 'wh_wh')
            ->when($warehouseId !== null,
                fn ($q) => $q->where('stock_transfers.from_warehouse_id', $warehouseId))
            ->groupBy($key)
            ->selectRaw("$key as k, SUM(i.qty_sent) as n")
            ->pluck('n', 'k')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    public function qtySent(): int
    {
        return (int) $this->items->sum('qty_sent');
    }

    public function qtyShort(): int
    {
        return (int) $this->items->sum('qty_short');
    }

    public function qtyReceived(): int
    {
        return (int) $this->items->sum('qty_received');
    }

    public function hasVariance(): bool
    {
        return $this->status === 'received' && $this->qtySent() !== $this->qtyReceived();
    }

    public function isOpen(): bool
    {
        return $this->status === 'sent';
    }

    /**
     * إرسال شحنة — بيخصم من باتشات المخزن المرسل فعلاً.
     *
     * ⚠️ **ده اللي ماكانش موجود خالص.** التحويل كان بيسجّل صفوف وبس؛
     * الخصم مااتعملش لا هنا ولا عند الاستلام، والاستلام بيزوّد باتشات
     * في المخزن التاني. يعني كل تحويل كان بيزوّد إجمالي بضاعة الشركة
     * بالكمية المحوّلة، والعاشر بيفضل بايع نفس البضاعة اللي هو باعتها.
     *
     * ⚠️ **الباتش بيتقفل قبل الخصم.** تحويلين على نفس الباتش في نفس
     * اللحظة كانوا هيقروا نفس `qty_remaining` ويخصموا الاتنين، فالباتش
     * يطلع بالسالب.
     *
     * @param  array<int, array{product_id:int, source_batch_id:int, qty:int}>  $lines
     * @return array{transfer: ?self, error: ?string}
     */
    public static function send(
        User $user,
        int $fromWarehouseId,
        int $toWarehouseId,
        string $sentOn,
        array $lines,
        ?string $carrier = null,
        ?string $notes = null,
        ?string $reason = null,
    ): array {
        // ⚠️ **`Rejected` مش `RuntimeException` ومش `rescue`.**
        // `QueryException` بترث من `RuntimeException`، فلقف العام كان
        // بيبلع الديد لوك وكسر الـFK وانقطاع الاتصال ويقول للمستخدم
        // «الشحنة مااتبعتتش» — وأخطر حالة: الخطأ بيحصل وقت الـcommit
        // بعد ما MySQL كتبت فعلاً، فالمستخدم يبعت تاني والباتش يتخصم
        // مرتين لشحنة واحدة. ولا حاجة من ده كانت هتتسجّل في اللوج.
        // القاعدة دي مكتوبة صراحةً في `App\Exceptions\Rejected`.
        try {
            $transfer = DB::transaction(function () use (
                $user, $fromWarehouseId, $toWarehouseId, $sentOn, $lines, $carrier, $notes, $reason
            ) {
                $transfer = static::create([
                    'number' => static::nextNumber(),
                    'kind' => 'wh_wh',
                    'from_warehouse_id' => $fromWarehouseId,
                    'to_warehouse_id' => $toWarehouseId,
                    'status' => 'sent',
                    'sent_on' => $sentOn,
                    'sent_by' => $user->id,
                    'created_by' => $user->id,
                    'carrier_name' => $carrier,
                    'notes' => $notes,
                    'reason' => $reason,
                ]);

                $touched = [];

                foreach ($lines as $line) {
                    $qty = (int) $line['qty'];

                    $batch = Batch::whereKey($line['source_batch_id'])->lockForUpdate()->first();

                    // ⚠️ **الباتش لازم يكون في المخزن المرسل نفسه.** من غير
                    // الفحص ده، حد يقدر يبعت `source_batch_id` بتاع باتش في
                    // مخزن تاني ويخصم منه — والبضاعة تظهر في مخزن ثالث.
                    if (! $batch || (int) $batch->warehouse_id !== $fromWarehouseId) {
                        throw new Rejected(__('stock.batch_not_in_warehouse'));
                    }

                    if ((int) $batch->product_id !== (int) $line['product_id']) {
                        throw new Rejected(__('stock.batch_product_mismatch'));
                    }

                    // ⚠️ **الرف الأول، وبعدين الباتش.** `batches.qty_remaining`
                    // المفروض يساوي مجموع `batch_locations.qty` — وده المكتوب
                    // في الدوكترين. لو خصمنا من الباتش بس، الرف بيفضل يقول إن
                    // البضاعة عليه: `Warehouse::availableFor()` و`PickOrder`
                    // بيقروا من الأرفف، فأمر تجهيز بياخد نفس الكراتين اللي
                    // مشيت، و`PickOrderItem::pull()` بتخصم من الباتش من غير
                    // حارس فيطلع بالسالب — والبضاعة تتباع مرتين.
                    if ($message = self::takeFromShelves($batch, $qty)) {
                        throw new Rejected($message);
                    }

                    if ($message = $batch->issue($qty)) {
                        throw new Rejected($message);
                    }

                    StockTransferItem::create([
                        'stock_transfer_id' => $transfer->id,
                        'product_id' => $batch->product_id,
                        'source_batch_id' => $batch->id,
                        'batch_no' => $batch->batch_no,
                        'produced_on' => $batch->produced_on,
                        'expires_on' => $batch->expires_on,
                        'qty_sent' => $qty,
                        // التكلفة بتتنقل من الباتش زي ما هي — البضاعة هي
                        // هي، والتكلفة صفة فيها مش رقم بيتكتب كل شحنة.
                        'cost' => $batch->cost,
                    ]);

                    $touched[(int) $batch->product_id] = true;
                }

                foreach (array_keys($touched) as $productId) {
                    \App\Services\StockCounting::resync((int) $productId, $fromWarehouseId);
                }

                return $transfer;
            });
        } catch (Rejected $e) {
            // رفض متوقّع — الترانزاكشن رجعت، ومافيش حاجة اتغيّرت.
            return ['transfer' => null, 'error' => $e->getMessage()];
        }

        // ⚠️ بره الترانزاكشن: الإشعار مش جزء من صحة الحركة.
        $transfer->notifyDestination();

        return ['transfer' => $transfer, 'error' => null];
    }

    /**
     * خصم الكمية من أرفف الباتش بترتيب أقل رصيد أولاً.
     *
     * ⚠️ **الرفوف بتتقفل كلها قبل ما نخصم من أي واحد.** من غير القفل،
     * تحويلين على نفس الباتش بيقروا نفس أرصدة الأرفف ويخصموا الاتنين،
     * فالرف يطلع بالسالب.
     *
     * ⚠️ **بضاعة مستلمة ولسه مترصّفتش مش على أي رف.** الباتش اللي لسه
     * على أرض المخزن (`unshelvedQty`) بيتخصم من الباتش مباشرةً — و
     * `putAway` بتحسب المتاح للترصيف من `qty_remaining` ناقص المرصّف،
     * فالحساب بيفضل مظبوط.
     */
    private static function takeFromShelves(Batch $batch, int $qty): ?string
    {
        $rows = $batch->locations()->where('qty', '>', 0)
            ->orderBy('qty')->lockForUpdate()->get();

        $shelved = (int) $rows->sum('qty');

        // اللي لسه مااترصّفش بيتخصم من الباتش من غير رف
        $left = max($qty - max((int) $batch->qty_remaining - $shelved, 0), 0);

        if ($left > $shelved) {
            return __('stock.shelf_short', ['available' => $shelved + max((int) $batch->qty_remaining - $shelved, 0)]);
        }

        foreach ($rows as $row) {
            if ($left <= 0) {
                break;
            }

            $take = min($left, (int) $row->qty);

            if ($error = $row->take($take)) {
                return $error;
            }

            $left -= $take;
        }

        return null;
    }

    /**
     * إخطار المخزن المستقبِل إن فيه شحنة في الطريق.
     *
     * ⚠️ **بره الترانزاكشن عن قصد.** الإشعار مش جزء من صحة الحركة —
     * لو جدول الإشعارات وقع، مايصحّش الشحنة كلها ترجع والبضاعة تفضل
     * في العاشر بعد ما العربية مشيت.
     *
     * ⚠️ وبيروح لأمين المخزن **وللمسؤول عنه** — أمين المخزن ممكن
     * يكون في أجازة، والشحنة اللي محدش يعرف إنها جاية بتقف على
     * الرصيف.
     */
    public function notifyDestination(): void
    {
        $this->loadMissing(['toWarehouse', 'fromWarehouse']);

        // ⚠️ `orWhere('id', ...)` مش `orWhereKey` (إصلاح ٢١/٨) —
        // النسخة دي من لارافيل مافيهاش `orWhereKey` أصلاً، وكانت
        // بترمي 500 أول ما الإشعار يتنادى. والشرط بيتضاف بس لما
        // يكون فيه مسؤول فعلاً — `orWhere('id', null)` مايجيبش حد.
        //
        // ⚠️ **أمناء المخازن بس مش أي حد على المخزن** (بلاغ المالك
        // ٢٢/٨): المناديب كلهم `warehouse_id` بتاعهم = مخزن تموينهم،
        // فكل تحويل وارد كان بيرن عند كل المناديب — «بيجيلنا رسايل
        // رجوع بضاعة من مندوب تاني». الإشعار ده شغل المخزن: أمينه
        // ومسؤوله وبس.
        $mgrId = $this->toWarehouse?->manager_id;

        $targets = User::query()
            ->where('active', true)
            ->where(function ($q) use ($mgrId) {
                $q->where(fn ($w) => $w->where('warehouse_id', $this->to_warehouse_id)
                    ->where('role', 'warehouse_keeper'));

                if ($mgrId !== null) {
                    $q->orWhere('id', $mgrId);
                }
            })
            ->get();

        foreach ($targets as $user) {
            AppNotification::send(
                $user,
                fn () => __('stock.notif_transfer_in_title', ['number' => $this->number]),
                fn () => __('stock.notif_transfer_in_body', [
                    'from' => $this->fromWarehouse?->displayName() ?? '—',
                    'qty' => $this->qtySent(),
                ]),
            );
        }
    }

    /**
     * ═══════════════════════════════════════════════════════════
     * تحويل من عربية مندوب — للمخزن أو لمندوب تاني (١٤ أغسطس ٢٠٢٦)
     * ═══════════════════════════════════════════════════════════
     *
     * طلب المالك: «عملت تسليم عهدة ومندوب خرج بيها ولقينا غلط في
     * التحضير — عاوز أحوّل بضاعة **موجودة فعلاً** معاه للمخزن أو
     * لمندوب تاني، بسبب مكتوب، وأعرف البضاعة دي مصدرها إيه».
     *
     * ⚠️ **مفيش مسار مخزون موازي:**
     *   - `rep_wh`  البضاعة بترجع الرف بـ`Custody::restockFromItem` —
     *     نفس الحركة بالحرف بتاعة تصحيح العهدة (١٢/٨) وفرق الاستلام.
     *   - `rep_rep` **مافيش حركة مخزن خالص** — البضاعة أصلاً `qty_issued`
     *     على الباتش من ساعة التحميل، وهي بتنتقل من عربية لعربية.
     *     أي كتابة على الباتش هنا كانت هتخلق بضاعة من العدم.
     *
     * ⚠️ **المعادلة بتفضل مقفولة:**
     *   - `rep_wh`  → `custody_items.returned` (خانة «مرجع للمخزن»
     *     الموجودة أصلاً في معادلة التصفية، وماكانش فيه مسار بيكتبها).
     *   - `rep_rep` → `custody_items.transferred_out` (حدّ جديد صريح
     *     في المعادلة) عند المرسِل، و`assigned` عند المستقبِل.
     *
     * ⚠️ **بضاعة موجودة فعلاً بس:** كل سطر بند عهدة حقيقي، متقفول
     * بـ`lockForUpdate`، والكمية ≤ `remaining()` — الفحص جوه الترانزاكشن
     * لأن الفحص اللي قبلها بيسقط أول ما المندوب يبيع في نفس اللحظة.
     *
     * @param  array<int, array{custody_item_id:int, qty:int}>  $lines
     * @return array{transfer: ?self, error: ?string}
     */
    public static function sendFromCustody(
        User $actor,
        User $fromRep,
        string $kind,
        ?Warehouse $toWarehouse,
        ?User $toRep,
        array $lines,
        string $reason,
        ?string $notes = null,
    ): array {
        if (! in_array($kind, ['rep_wh', 'rep_rep'], true)) {
            return ['transfer' => null, 'error' => __('stock.transfer_kind_bad')];
        }

        if ($kind === 'rep_wh' && $toWarehouse === null) {
            return ['transfer' => null, 'error' => __('stock.no_warehouse')];
        }

        if ($kind === 'rep_rep' && ($toRep === null || $toRep->id === $fromRep->id)) {
            return ['transfer' => null, 'error' => __('stock.transfer_same_rep')];
        }

        $custody = $fromRep->currentCustody();

        if ($custody === null || $custody->status === 'closed') {
            return ['transfer' => null, 'error' => __('field.custody_adjust_none')];
        }

        try {
            $transfer = DB::transaction(function () use (
                $actor, $fromRep, $kind, $toWarehouse, $toRep, $lines, $reason, $notes, $custody
            ) {
                // ⚠️ إعادة قراية بقفل — العهدة ممكن تكون اتقفلت من
                // شاشة تانية بين فتح الفورم والحفظ
                $source = Custody::whereKey($custody->id)->lockForUpdate()->first();

                if (! $source || $source->status === 'closed') {
                    throw new Rejected(__('field.custody_closed'));
                }

                // ═══ عهدة المستقبِل — نفس منطق `PickOrder::handOver` ═══
                // عهدة **واحدة مفتوحة** لكل مندوب، والقفل بينهيها مش
                // منتصف الليل (عقيدة ١٠/٨).
                $target = null;

                if ($kind === 'rep_rep') {
                    $target = Custody::where('user_id', $toRep->id)
                        ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '<>', 'closed'))
                        ->orderByDesc('date')
                        ->lockForUpdate()
                        ->first();

                    if ($target === null) {
                        $closedToday = Custody::where('user_id', $toRep->id)
                            ->whereDate('date', today())->first();

                        if ($closedToday !== null) {
                            throw new Rejected(__('field.custody_closed'));
                        }

                        $target = Custody::create([
                            'user_id' => $toRep->id,
                            'date' => today(),
                            'warehouse_id' => $toRep->warehouse_id ?? $source->warehouse_id,
                            'status' => 'open',
                        ]);
                    }
                }

                // ⚠️ **مرساة المخزن** — `from_warehouse_id` و
                // `to_warehouse_id` أعمدة NOT NULL في السكيما، وتغيير
                // nullability لعمود عليه FK على سيرفر لايف مش ريبو جيت
                // مخاطرة مالهاش لزوم. بنرسي على مخزن العهدة (المخزن
                // اللي البضاعة اتحمّلت منه) — وده رقم حقيقي مفيد.
                // الفولباك بالترتيب: مخزن عهدة المستقبِل ← باتش أول بند
                // ← مخزن الوجهة.
                $fromWarehouseId = (int) (
                    $source->warehouse_id
                    ?: ($target?->warehouse_id
                        ?: (Batch::whereIn('id', CustodyItem::where('custody_id', $source->id)
                            ->whereNotNull('batch_id')->pluck('batch_id'))
                            ->value('warehouse_id')
                            ?: ($toWarehouse?->id ?? 0)))
                );
                $toWarehouseId = (int) ($kind === 'rep_wh'
                    ? $toWarehouse->id
                    : ($target?->warehouse_id ?: $fromWarehouseId));

                if ($fromWarehouseId <= 0 || $toWarehouseId <= 0) {
                    throw new Rejected(__('stock.no_warehouse'));
                }

                $transfer = static::create([
                    'number' => static::nextNumber(),
                    'kind' => $kind,
                    'from_warehouse_id' => $fromWarehouseId,
                    'from_user_id' => $fromRep->id,
                    'to_warehouse_id' => $toWarehouseId,
                    'to_user_id' => $kind === 'rep_rep' ? $toRep->id : null,
                    // ⚠️ **خطوة واحدة** — البضاعة بتتسلّم إيد بإيد لحظة
                    // المستند. شوف الشرح في رأس الكلاس.
                    'status' => 'received',
                    'sent_on' => today(),
                    'received_on' => today(),
                    'sent_by' => $actor->id,
                    'received_by' => $actor->id,
                    'created_by' => $actor->id,
                    'notes' => $notes,
                    'reason' => $reason,
                ]);

                $touched = [];

                // خريطة مصادر عهدة المرسِل — كويريز ثابتة مرة واحدة
                // قبل اللوب، مش لكل بند (شوف `CustodySource`).
                $srcMap = \App\Support\CustodySource::forCustody(
                    $source,
                    CustodyItem::where('custody_id', $source->id)->get(),
                );

                foreach ($lines as $line) {
                    $qty = (int) ($line['qty'] ?? 0);

                    if ($qty <= 0) {
                        continue;
                    }

                    /** @var CustodyItem|null $item */
                    $item = CustodyItem::whereKey($line['custody_item_id'])
                        ->where('custody_id', $source->id)
                        ->lockForUpdate()
                        ->first();

                    if ($item === null) {
                        throw new Rejected(__('stock.transfer_line_gone'));
                    }

                    $item->loadMissing(['product', 'batch']);

                    if ($qty > $item->remaining()) {
                        throw new Rejected(__('field.custody_not_enough', [
                            'product' => $item->product?->displayName() ?? '#'.$item->product_id,
                            'short' => $qty - $item->remaining(),
                        ]));
                    }

                    // ⚠️ **البضاعة بترجع لمخزن باتشها، مش لأي مخزن.**
                    // `restockFromItem` بترجّع للرف اللي طلعت منه —
                    // ولو الوجهة مخزن تاني كانت البضاعة هتتحط على رف
                    // مخزن ثالث والرقمين يفترقوا. الشاشة بتفلتر البنود
                    // بمخزن الوجهة، والحارس ده هو اللي بيمنع POST مباشر.
                    if ($kind === 'rep_wh' && $item->batch !== null
                        && (int) $item->batch->warehouse_id !== (int) $toWarehouse->id) {
                        throw new Rejected(__('stock.transfer_batch_other_wh', [
                            'product' => $item->product?->displayName() ?? '#'.$item->product_id,
                            'warehouse' => $item->batch->warehouse?->displayName() ?? '—',
                        ]));
                    }

                    // ═══ ١. خانة العهدة عند المرسِل ═══
                    if ($kind === 'rep_wh') {
                        $item->increment('returned', $qty);
                    } else {
                        $item->increment('transferred_out', $qty);
                    }

                    // ═══ ٢. بند المستند — بمصدر البضاعة مجمّد ═══
                    // ⚠️ **المصدر المشتَق مش الخام** (١٥ أغسطس): البند
                    // الأقدم من عمود `source` بيقول `legacy`، ولو جمّدناه
                    // كده على مستند التحويل نكون طبعنا «مصدر غير محدد»
                    // على ورقة موقّعة — والحقيقة إن إذن تسليمه معروف.
                    // `CustodySource` بترقّيه لـ`custody` لو لقيت إذنه.
                    StockTransferItem::create([
                        'stock_transfer_id' => $transfer->id,
                        'product_id' => $item->product_id,
                        'source_batch_id' => $item->batch_id,
                        'custody_item_id' => $item->id,
                        'source' => $srcMap->keyFor($item),
                        'source_ref_id' => (int) $item->source_ref_id,
                        'batch_no' => $item->batch?->batch_no ?? '—',
                        'produced_on' => $item->batch?->produced_on,
                        'expires_on' => $item->batch?->expires_on,
                        'qty_sent' => $qty,
                        'qty_received' => $qty,
                        'qty_short' => 0,
                        'cost' => $item->batch?->cost ?? $item->product?->cost,
                    ]);

                    // ═══ ٣. الطرف التاني ═══
                    if ($kind === 'rep_wh') {
                        // نفس مسار الإرجاع للرف بتاع تصحيح العهدة
                        $source->restockFromItem($item, $qty, $toWarehouse);
                        $touched[(int) $item->product_id] = true;

                        continue;
                    }

                    // مندوب لمندوب: البضاعة مابتلمسش المخزن — بند
                    // جديد بمصدر `transfer` ومرجعه رقم المستند ده،
                    // فالمندوب التاني يعرف الكراتين دي جت منين.
                    $dest = $target->items()->firstOrNew([
                        'product_id' => $item->product_id,
                        'batch_id' => $item->batch_id,
                        'source' => 'transfer',
                        'source_ref_id' => $transfer->id,
                    ]);

                    $dest->assigned = (int) $dest->assigned + $qty;
                    $dest->save();
                }

                if ($transfer->items()->count() === 0) {
                    throw new Rejected(__('stock.pick_no_items'));
                }

                // ⚠️ `stocks` صورة من الباتشات — مصالحة ختامية للمخزن
                // اللي البضاعة رجعت له (تحويل مندوب لمندوب مالوش مخزن)
                foreach (array_keys($touched) as $productId) {
                    \App\Services\StockCounting::resync((int) $productId, $toWarehouseId);
                }

                return $transfer;
            });
        } catch (Rejected $e) {
            return ['transfer' => null, 'error' => $e->getMessage()];
        }

        // ⚠️ بره الترانزاكشن — الإشعار مش جزء من صحة الحركة
        $transfer->notifyVanTransfer();

        return ['transfer' => $transfer->fresh(['items']), 'error' => null];
    }

    /**
     * إشعارات التحويل الميداني — المندوب اللي اتسحب منه أولاً.
     *
     * ⚠️ **المندوب لازم يعرف قبل ما يقف قدام العميل.** بضاعة اختفت
     * من عربيته من غير إشعار = هو يقول «معايا» والسيستم يقول «مفيش».
     */
    public function notifyVanTransfer(): void
    {
        $this->loadMissing(['items', 'fromUser', 'toUser', 'toWarehouse']);

        $items = $this->items->count();
        $qty = $this->qtySent();

        AppNotification::send(
            $this->fromUser,
            fn () => __('stock.notif_van_out_title'),
            fn () => __('stock.notif_van_out_body', [
                'items' => $items,
                'qty' => $qty,
                'to' => $this->toLabel(),
                'reason' => (string) $this->reason,
            ]),
            good: false,
        );

        if ($this->kindKey() === 'rep_rep') {
            AppNotification::send(
                $this->toUser,
                fn () => __('stock.notif_van_in_title'),
                fn () => __('stock.notif_van_in_body', [
                    'items' => $items,
                    'qty' => $qty,
                    'from' => $this->fromLabel(),
                    'reason' => (string) $this->reason,
                ]),
            );

            return;
        }

        // أمين المخزن المستقبِل والمسؤول عنه — نفس قاعدة `notifyDestination`
        // ⚠️ نفس إصلاح `orWhereKey` (٢١/٨) — الميثود مش موجودة في
        // النسخة دي وكانت بترمي 500 عند أول تحويل ميداني للمخزن.
        //
        // ⚠️ **أمناء المخازن بس** (بلاغ المالك ٢٢/٨) — نفس تصليح
        // `notifyDestination` بالحرف: المناديب على نفس المخزن كانوا
        // بياخدوا إشعار رجوع بضاعة أي زميل.
        $mgrId = $this->toWarehouse?->manager_id;

        $targets = User::query()
            ->where('active', true)
            ->where(function ($q) use ($mgrId) {
                $q->where(fn ($w) => $w->where('warehouse_id', $this->to_warehouse_id)
                    ->where('role', 'warehouse_keeper'));

                if ($mgrId !== null) {
                    $q->orWhere('id', $mgrId);
                }
            })
            ->get();

        foreach ($targets as $user) {
            AppNotification::send(
                $user,
                fn () => __('stock.notif_van_wh_title', ['number' => $this->number]),
                fn () => __('stock.notif_van_wh_body', [
                    'rep' => $this->fromLabel(),
                    'qty' => $qty,
                    'reason' => (string) $this->reason,
                ]),
            );
        }
    }

    public static function nextNumber(): string
    {
        // ⚠️ أكبر رقم مش آخر صف — شوف `HasDocumentNumber`
        return static::nextDocumentNumber('TRF-', 1001);
    }

    /**
     * استلام التحويل في المخزن المستقبِل.
     * بيولّد إذن استلام وباتشات، والبضاعة تبقى «مستلمة ولسه مترصّفتش».
     *
     * ⚠️ **العجز بيتسجّل على المخزن المرسل.** البضاعة خرجت من باتشه
     * وقت الإرسال (`issue`)، فالناقص مش «مااتصرفش» — ده صرف ضاع.
     * بننقله من `qty_issued` لـ`qty_damaged` على نفس الباتش، فيفضل
     * `qty_received = qty_remaining + qty_issued + qty_damaged` صحيح
     * والفرق مفسَّر. لو سبناه في `qty_issued` كان هيبان كأنه وصل
     * لحد ما، ولو رجّعناه لـ`qty_remaining` كان المخزن هيقول إنه
     * موجود على الرف وهو مش موجود.
     *
     * @param  array<int, int>|null  $receivedByItem  [stock_transfer_item_id => qty]
     * @param  array<int, string|null>|null  $producedByItem  [stock_transfer_item_id => date]
     */
    public function receive(
        User $user,
        ?array $receivedByItem = null,
        ?string $notes = null,
        ?array $producedByItem = null,
    ): array {
        if ($this->status !== 'sent') {
            return ['receipt' => null, 'error' => __('stock.transfer_not_open')];
        }

        $this->load(['items.product', 'items.sourceBatch', 'toWarehouse', 'fromWarehouse']);

        // ⚠️ **الاستلام مايزيدش عن المبعوت.** المخزن المستقبِل
        // مايقدرش يخلق بضاعة — لو وصله أكتر، ده جرد مش استلام.
        foreach ($this->items as $item) {
            // ⚠️ **بند من غير باتش مصدر معناه إن المخزن المرسل مااتخصمش.**
            // استلامه بيزوّد المخزن المستقبِل من غير ما ينقّص المرسل،
            // فإجمالي بضاعة الشركة بيزيد من العدم. المايجريشن بتلغي
            // الشحنات القديمة، والفحص ده حزام أمان لو فضلت واحدة.
            if ($item->source_batch_id === null) {
                return ['receipt' => null, 'error' => __('stock.transfer_legacy')];
            }

            $q = $receivedByItem === null ? null : ($receivedByItem[$item->id] ?? null);

            if ($q !== null && (int) $q > (int) $item->qty_sent) {
                return ['receipt' => null, 'error' => __('stock.received_over_sent', [
                    'product' => $item->product?->displayName() ?? '—',
                    'sent' => (int) $item->qty_sent,
                ])];
            }
        }

        $receipt = DB::transaction(function () use ($user, $receivedByItem, $producedByItem, $notes) {
            // ⚠️ **الفحص لازم يتكرر جوه الترانزاكشن وبقفل.** الفحص اللي
            // فوق بره الترانزاكشن، فضغطتين على «استلام» في نفس اللحظة
            // (أو دبل كليك على نت بطيء — العملية بتاخد أجزاء من الثانية)
            // كانوا بيعدّوا الاتنين، و`increment` بتراكم: المخزن المستقبِل
            // بياخد الكمية **مرتين**، وبيتعمل إذنين استلام واحد منهم يتيم.
            $fresh = static::whereKey($this->id)->lockForUpdate()->first();

            if (! $fresh || $fresh->status !== 'sent') {
                throw new Rejected(__('stock.transfer_not_open'));
            }

            $receipt = GoodsReceipt::create([
                'number' => GoodsReceipt::nextNumber(),
                'warehouse_id' => $this->to_warehouse_id,
                'source_warehouse_id' => $this->from_warehouse_id,
                'received_on' => today(),
                'status' => 'posted',
                'supplier' => $this->fromWarehouse?->displayName(),
                'reference' => $this->number,
                'created_by' => $user->id,
                'notes' => $notes,
            ]);

            $touched = [];

            foreach ($this->items as $item) {
                $qty = $receivedByItem === null
                    ? (int) $item->qty_sent
                    : max((int) ($receivedByItem[$item->id] ?? $item->qty_sent), 0);

                $short = max((int) $item->qty_sent - $qty, 0);

                // ⚠️ **تاريخ الإنتاج بيتعدّل هنا لو المستلم صحّحه.**
                // الورقة اللي على الكرتونة هي الحقيقة، واللي بعت ممكن
                // يكون كتبه غلط — والتاريخ الغلط معناه صلاحية غلط
                // وترتيب FEFO غلط لكل ما الباتش ده يخرج بعد كده.
                $typed = $producedByItem[$item->id] ?? null;
                $produced = $typed ?: $item->produced_on;

                $product = $item->product;

                // ⚠️ **الصلاحية بتتحسب من تاني بس لو المستلم غيّر تاريخ
                // الإنتاج فعلاً.** كانت بتتحسب في كل استلام، فالباتش اللي
                // أمين المخزن كتب صلاحيته بإيده وقت الاستلام (`storeReceipt`
                // بتسمح بده) كان بيوصل المخزن التاني بصلاحية محسوبة مختلفة —
                // نفس الكرتونة بتنتهي في تاريخين. وكانت بتتكتب كمان على
                // بند التحويل، يعني الورقة الممضية لو اتطبعت تاني بتطلع
                // بتواريخ غير اللي في الدرج.
                $changed = $typed !== null
                    && $item->produced_on?->toDateString() !== \Illuminate\Support\Carbon::parse($typed)->toDateString();

                $expires = $changed && $product
                    ? $product->expiryFrom($produced)->toDateString()
                    : $item->expires_on;

                $item->update([
                    'qty_received' => $qty,
                    'qty_short' => $short,
                    'produced_on' => $produced,
                    'expires_on' => $expires,
                ]);

                // ⚠️ العجز بيترد على باتش المخزن المرسل: بينتقل من
                // «اتصرف» لـ«توالف» عشان الفرق يفضل مفسَّر ومايتحسبش
                // مرتين. الباتش بيتقفل عشان تحويلين على نفس الباتش
                // في نفس اللحظة مايدوسوش على بعض.
                if ($short > 0 && $item->source_batch_id) {
                    $source = Batch::whereKey($item->source_batch_id)->lockForUpdate()->first();

                    if ($source) {
                        // ⚠️ **نفس الرقم في الطرفين.** كان الخصم محدود
                        // بـ`qty_issued` والزيادة مش محدودة، فأول ما
                        // الحد يشتغل تنكسر معادلة الباتش
                        // (`received = remaining + issued + damaged`)
                        // للأبد ومن غير أي أثر. المحاسبة الناقصة أهون
                        // من رقم بيكدب على نفسه.
                        $move = min($short, (int) $source->qty_issued);

                        if ($move > 0) {
                            $source->decrement('qty_issued', $move);
                            $source->increment('qty_damaged', $move);
                        }

                        $touched[$this->from_warehouse_id][$item->product_id] = true;
                    }
                }

                if ($qty <= 0) {
                    continue;
                }

                // نفس رقم الباتش في مخزن مختلف = صف مستقل
                //
                // ⚠️ **`firstOrCreate` مش `updateOrCreate`.** التواريخ
                // والتكلفة بتتكتب وقت الإنشاء بس. `updateOrCreate` كانت
                // بتدوس بيهم على الصف الموجود — يعني شحنة جديدة من نفس
                // رقم الباتش بتغيّر تاريخ الصلاحية والتكلفة لكل الكمية
                // القديمة اللي على الرف، فترتيب FEFO وتقرير الصلاحية
                // وهامش الفاتورة كلهم بيتحركوا لبضاعة محدش لمسها.
                $batch = Batch::firstOrCreate(
                    [
                        'product_id' => $item->product_id,
                        'batch_no' => $item->batch_no,
                        'warehouse_id' => $this->to_warehouse_id,
                    ],
                    [
                        'goods_receipt_id' => $receipt->id,
                        'produced_on' => $produced,
                        'expires_on' => $expires,
                        // ⚠️ **التكلفة من الباتش المصدر مش من الشحنة.**
                        // كانت خانة في فورم التحويل، يعني نفس الصنف كان
                        // بياخد تكلفة مختلفة كل شحنة على مزاج اللي بيكتب —
                        // وربحية الفاتورة بتطلع من تكلفة الباتش.
                        'cost' => $item->cost ?? $product?->cost ?? 0,
                    ],
                );

                // الكمية بتتزوّد — ممكن نفس الباتش يوصل على شحنتين
                $batch->increment('qty_received', $qty);
                $batch->increment('qty_remaining', $qty);
                $touched[$this->to_warehouse_id][$item->product_id] = true;
            }

            $this->update([
                'status' => 'received',
                'received_on' => today(),
                'received_by' => $user->id,
                'goods_receipt_id' => $receipt->id,
            ]);

            // ⚠️ `stocks` تجميعة من الباتشات — لازم تتعاد للمخزنين،
            // وإلا شاشة المخزون بترقّم غير الباتشات ومحدش يعرف مين الصح.
            foreach ($touched as $warehouseId => $products) {
                foreach (array_keys($products) as $productId) {
                    \App\Services\StockCounting::resync((int) $productId, (int) $warehouseId);
                }
            }

            return $receipt;
        });

        if ($this->fresh()->hasVariance()) {
            AppNotification::send(
                $this->sender,
                fn () => __('stock.notif_transfer_variance_title', ['number' => $this->number]),
                fn () => __('stock.notif_transfer_variance_body', [
                    'sent' => $this->qtySent(),
                    'received' => $this->fresh()->qtyReceived(),
                ]),
                good: false,
            );
        }

        return ['receipt' => $receipt, 'error' => null];
    }
}
