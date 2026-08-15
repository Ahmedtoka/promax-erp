<?php

namespace App\Support;

use App\Models\Custody;
use App\Models\CustodyItem;
use App\Models\PurchaseOrder;
use App\Models\StockTransfer;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * مصدر بضاعة العهدة — الرقم المرجعي الحقيقي  · ١٥ أغسطس ٢٠٢٦
 * ═══════════════════════════════════════════════════════════════
 *
 * بلاغ المالك: «مكتوب إن المصدر مش معروف، وده أصلاً استلامه
 * بريفرنس في العهد» — ومعاه إذن تسليم العهدة PCK-1021 اللي
 * البضاعة دي بالظبط جت عليه.
 *
 * ═══ السبب ═══
 *
 * عمودَي `custody_items.source` / `source_ref_id` اتضافوا في ١٤
 * أغسطس. المسار بيكتب فيهم صح **من ساعتها**، بمنطق:
 *
 *   `$source = $this->purchase_order_id ? 'purchase_order' : 'custody'`
 *   `$ref    = (int) ($this->purchase_order_id ?? 0)`
 *
 * فطلعت فجوتين:
 *
 *   ١. **العهدة العادية بتضيّع ورقتها.** لما مايكونش فيه أمر توريد،
 *      الـ`ref` بيتكتب صفر — فرقم إذن التسليم (PCK-) اللي طلّع
 *      البضاعة من الرف مابيتسجّلش على البند، مع إنه موجود ومطبوع
 *      وموقّع. كل اللي بيتقال «عهدة عادية» من غير ما يقول من أنهي إذن.
 *
 *   ٢. **الصفوف الأقدم من ١٤ أغسطس مكتوب عليها `legacy`** = «مصدر
 *      غير محدد — بضاعة قديمة». وده **مش صحيح**: الربط موجود فعلاً
 *      في `pick_orders.custody_id` (عمود من أول مايجريشن، ٢٩ يوليو)
 *      وبنوده فيها `product_id` و`batch_id` و`qty_received`. يعني
 *      إحنا كنا بنقول «مانعرفش» ورقم الإذن قدامنا في الداتابيز.
 *
 * ═══ الحل: اشتقاق وقت القراءة — مش باك-فيل ═══
 *
 * الكلاس ده **مابيكتبش أي حاجة**. بيقرا أذونات التسليم اللي حمّلت
 * العهدة دي (كويري واحدة للعهدة كلها، مش كويري للبند) ويبني خريطة
 * (منتج + باتش) → أرقام الأذونات، وبيستخدمها يجاوب على البنود اللي
 * مصدرها `custody` أو `legacy`.
 *
 * ⚠️ **ليه مش باك-فيل في مايجريشن؟** لأن كتابة `source_ref_id` على
 * صفوف قديمة معناها تغيير المفتاح الفريد (منتج+باتش+مصدر+مرجع)
 * على داتا حيّة: صف اتقسم بأثر رجعي = بيع بيخصم من نص البضاعة،
 * وتصفية مقفولة بتتحرّك أرقامها. الاشتقاق بيدي نفس المعلومة
 * للمستخدم وصفر خطر على الحسابات.
 *
 * ⚠️ **مابنخمّنش.** لو مافيش إذن تسليم في العهدة دي فيه الصنف
 * والباتش ده، البند بيفضل `legacy` زي ما هو. «مصدر غير محدد» أشرف
 * من مصدر مخترع.
 */
final class CustodySource
{
    /** "pid:bid" => [id => 'PCK-1021', …]  — بضاعة عهدة عادية */
    private array $picks = [];

    /** id => number  لأوامر التوريد المشار إليها */
    private array $poRefs = [];

    /** id => number  للتحويلات المشار إليها */
    private array $trRefs = [];

    private function __construct() {}

    /**
     * بناء الخريطة لعهدة واحدة — تلات كويريز ثابتة مهما كان عدد البنود.
     *
     * @param  iterable<CustodyItem>|null  $items  بنود محمّلة مسبقاً (اختياري،
     *                                             بيوفّر كويري لو الكولر قراها)
     */
    public static function forCustody(Custody|int|null $custody, ?iterable $items = null): self
    {
        $self = new self;
        $id = $custody instanceof Custody ? (int) $custody->id : (int) $custody;

        if ($id <= 0) {
            return $self;
        }

        // ═══ ١. أذونات التسليم اللي حمّلت العهدة دي ═══
        // ⚠️ `qty_received > 0` شرط أساسي: البند اللي المندوب مااستلمهوش
        // رجع الرف، فمايصحش يتقال إن بضاعة العربية جت منه.
        $rows = DB::table('pick_order_items as poi')
            ->join('pick_orders as po', 'po.id', '=', 'poi.pick_order_id')
            ->where('po.custody_id', $id)
            ->where('poi.qty_received', '>', 0)
            ->orderBy('po.handed_at')
            ->orderBy('po.id')
            ->get(['poi.product_id', 'poi.batch_id', 'po.id as pick_order_id', 'po.number']);

        foreach ($rows as $r) {
            $key = ((int) $r->product_id).':'.((int) $r->batch_id);
            $self->picks[$key][(int) $r->pick_order_id] = (string) $r->number;
        }

        // ═══ ٢. أرقام أوامر التوريد والتحويلات — دفعة واحدة ═══
        $items ??= ($custody instanceof Custody ? $custody->items : []);
        $bySrc = ['purchase_order' => [], 'transfer' => []];

        foreach ($items as $it) {
            $k = $it->sourceKey();
            $ref = (int) $it->source_ref_id;

            if ($ref > 0 && isset($bySrc[$k])) {
                $bySrc[$k][$ref] = true;
            }
        }

        if ($bySrc['purchase_order'] !== []) {
            $self->poRefs = PurchaseOrder::whereIn('id', array_keys($bySrc['purchase_order']))
                ->pluck('number', 'id')->all();
        }

        if ($bySrc['transfer'] !== []) {
            $self->trRefs = StockTransfer::whereIn('id', array_keys($bySrc['transfer']))
                ->pluck('number', 'id')->all();
        }

        return $self;
    }

    /** المفتاح بعد الاشتقاق — `legacy` بيترقّى لـ`custody` لو لقينا إذنه */
    public function keyFor(CustodyItem $item): string
    {
        $key = $item->sourceKey();

        if ($key === 'legacy' && $this->picksFor($item) !== []) {
            return 'custody';
        }

        return $key;
    }

    public function labelFor(CustodyItem $item): string
    {
        return __('stock.src_'.$this->keyFor($item));
    }

    public function classFor(CustodyItem $item): string
    {
        return CustodyItem::SOURCES[$this->keyFor($item)] ?? 'b-gray';
    }

    /**
     * رقم المستند: «PO-1042» لأمر التوريد، «TRF-1007» للتحويل،
     * «PCK-1021» (أو «PCK-1021 + PCK-1033») للعهدة العادية.
     */
    public function refFor(CustodyItem $item): ?string
    {
        $ref = (int) $item->source_ref_id;

        return match ($this->keyFor($item)) {
            'purchase_order' => $ref > 0 ? ($this->poRefs[$ref] ?? null) : null,
            'transfer' => $ref > 0 ? ($this->trRefs[$ref] ?? null) : null,
            'custody' => ($p = $this->picksFor($item)) === [] ? null : implode(' + ', array_values($p)),
            default => null,
        };
    }

    /** «عهدة عادية · PCK-1021» — سطر واحد جاهز للعرض */
    public function textFor(CustodyItem $item): string
    {
        $ref = $this->refFor($item);

        return $this->labelFor($item).($ref === null ? '' : ' · '.$ref);
    }

    /**
     * نفس المراجع بس كل واحد بلينك يفتح مستنده — المالك طلب إن كل
     * رقم في كارت المندوب يبقى كليك-إبل («مش لاقي باعهم فين»).
     *
     * @return array<int, array{text: string, url: ?string}>
     */
    public function linksFor(CustodyItem $item): array
    {
        $key = $this->keyFor($item);
        $ref = (int) $item->source_ref_id;

        if ($key === 'custody') {
            $out = [];

            foreach ($this->picksFor($item) as $id => $number) {
                $out[] = ['text' => $number, 'url' => self::route('wh.picks.show', $id)];
            }

            return $out;
        }

        if ($ref <= 0) {
            return [];
        }

        return match ($key) {
            'purchase_order' => isset($this->poRefs[$ref])
                ? [['text' => $this->poRefs[$ref], 'url' => self::route('ops.pos.show', $ref)]]
                : [],
            'transfer' => isset($this->trRefs[$ref])
                ? [['text' => $this->trRefs[$ref], 'url' => self::route('wh.transfers.show', $ref)]]
                : [],
            default => [],
        };
    }

    /**
     * ⚠️ اسم الراوت بيتفحص قبل التوليد. الكلاس ده بيتنده من شاشات
     * أدوار مختلفة، ولو اسم اتغيّر يوماً `route()` بترمي استثناء
     * وتكسّر كارت المندوب كله عشان لينك — الأمان إن اللينك يختفي.
     */
    private static function route(string $name, int $id): ?string
    {
        return \Illuminate\Support\Facades\Route::has($name) ? route($name, $id) : null;
    }

    /** @return array<int, string> [id => number] أذونات تسليم الصنف+الباتش ده */
    private function picksFor(CustodyItem $item): array
    {
        return $this->picks[((int) $item->product_id).':'.((int) $item->batch_id)] ?? [];
    }
}
