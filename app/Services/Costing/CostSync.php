<?php

namespace App\Services\Costing;

use App\Models\ClientReturn;
use App\Models\CostCenter;
use App\Models\CostEntry;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Transaction;
use App\Services\Gl\CostCenterResolver;
use App\Services\Gl\Ledger;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ═══ مزامنة طبقة التكلفة (الخط ٩ — D5/D6/D12) ═══
 *
 * قيد تكلفة واحد لكل أصل، بمفتاح `(kind, origin_type, origin_id)`
 * وبيتحدّث في مكانه — **الـid عمره ما بيتغير** (إعادة البناء بتقارن
 * القيود بمفتاح المصدر). السطور بتتبدّل لما الأرقام تتغير بس.
 *
 * الأصول في المهمة دي = صفوف `transactions`:
 *   • `sale` مصدره فاتورة ← `invoice_items` (R الباتش = `unit_cost` المخزّن)
 *   • `sale` مصدره أمر توريد ← البنود بـ`COALESCE(delivered_qty, qty)` و
 *     R = `products.cost` (مفيش باتش على بند الأمر — نفس `SalesSource::lines`)
 *   • `return` مصدره مرتجع ← البنود السليمة بس (`condition = good`)،
 *     R = `unit_cost` بند الفاتورة الأصلي وإلا تكلفة الصنف. التالف مالوش
 *     سطر (التكلفة بتفضل في تكلفة المبيعات — D6)، وكله تالف = مفيش قيد.
 * أي حاجة تانية (افتتاحي، يدوي، أمانة، تحصيل) مش بتتكلّف.
 *
 * ⚠️ **المزامنة مش بترحّل.** `forTransaction()` بيكتب الطبقة بس؛ اللي
 * بينادي (الأوبزرفر / `Ledger::repost` / `run($post=true)`) هو اللي بيرحّل.
 * جوه إعادة البناء `run($from, false)` والاستثناء بيطلع (الأحسن إعادة بناء
 * تفشل بصوت عالي من إنها تسقط تكلفة المبيعات في صمت).
 */
final class CostSync
{
    public const TX_KINDS = ['sale', 'return'];

    private static ?bool $ready = null;

    /** الكود ممكن يترفع قبل المايجريشن — الإيجابي بس بيتكاش */
    public static function ready(): bool
    {
        return self::$ready === true || (self::$ready = Schema::hasTable('cost_entries'));
    }

    /** القيد ده بيتكلّف؟ (نوعه + مصدره) */
    public static function costable(Transaction $tx): bool
    {
        if (! $tx->source_id || ! $tx->source_type) {
            return false;
        }
        $class = Relation::getMorphedModel($tx->source_type) ?? $tx->source_type;

        return match ($tx->kind) {
            'sale' => in_array($class, [Invoice::class, PurchaseOrder::class], true),
            'return' => $class === ClientReturn::class,
            default => false,
        };
    }

    public static function forTransaction(Transaction $tx): ?CostEntry
    {
        if (! self::ready()) {
            return null;
        }
        Valuation::flush();

        return self::sync($tx)['entry'];
    }

    /** القيد اتمسح: قيد التكلفة بتاعه بيتشال من الدفتر ومن الطبقة */
    public static function forgetTransaction(Transaction $tx): void
    {
        if (! self::ready()) {
            return;
        }
        DB::transaction(function () use ($tx) {
            CostEntry::where('origin_type', $tx->getMorphClass())->where('origin_id', $tx->id)
                ->whereIn('kind', self::TX_KINDS)->get()
                ->each(fn (CostEntry $e) => self::drop($e));
        });
    }

    /**
     * كل أصل قابل للتكلفة بتاريخ ≥ `$from` + مسح القيود اللي أصلها اختفى.
     * `$post`: القيد اللي اتغير بيترحّل تاني (repost)، واللي ماتغيرش بيتأكد
     * إن له قيد (post مابيعملش حاجة لو موجود). جوه إعادة البناء = false.
     *
     * @return array{synced:int, deleted:int}
     */
    public static function run(CarbonInterface $from, bool $post = false): array
    {
        $out = ['synced' => 0, 'deleted' => 0];
        if (! self::ready()) {
            return $out;
        }
        Valuation::flush();
        $d = $from->toDateString();
        $ledger = app(Ledger::class);
        $kept = [];

        $txs = Transaction::whereIn('kind', self::TX_KINDS)
            ->whereIn('source_type', [Invoice::class, PurchaseOrder::class, ClientReturn::class])
            ->whereDate('date', '>=', $d);
        foreach ($txs->lazyById(500) as $tx) {
            $r = self::sync($tx);
            $out['deleted'] += $r['deleted'];
            if ($r['entry'] === null) {
                continue;
            }
            $kept[$r['entry']->id] = true;
            $out['synced']++;
            if ($post) {
                $r['changed'] ? $ledger->repost($r['entry']) : $ledger->post($r['entry']);
            }
        }

        // قيود أصلها اختفى (أو بقى مش قابل للتكلفة) في نفس النافذة
        $morph = (new Transaction)->getMorphClass();
        $orphans = CostEntry::whereIn('kind', self::TX_KINDS)->where('origin_type', $morph)
            ->whereDate('date', '>=', $d)->pluck('id')->reject(fn ($id) => isset($kept[$id]));
        foreach ($orphans as $id) {
            $e = CostEntry::find($id);
            $tx = $e ? Transaction::find($e->origin_id) : null;
            if ($e === null) {
                continue;
            }
            // الأصل لسه موجود بس تاريخه اتنقل قبل النافذة — يتزامن مش يتمسح
            if ($tx !== null && self::costable($tx)) {
                $r = self::sync($tx);
                $out['deleted'] += $r['deleted'];
                if ($r['entry'] !== null && $post) {
                    $r['changed'] ? $ledger->repost($r['entry']) : $ledger->post($r['entry']);
                }

                continue;
            }
            DB::transaction(fn () => self::drop($e, $post));
            $out['deleted']++;
        }

        return $out;
    }

    /**
     * @return array{entry:?CostEntry, changed:bool, deleted:int}
     */
    private static function sync(Transaction $tx): array
    {
        $kind = self::costable($tx) ? $tx->kind : null;
        $date = Carbon::parse($tx->date)->startOfDay();
        $lines = $kind === null ? [] : self::linesFor($tx, $kind, $date);
        $morph = $tx->getMorphClass();

        return DB::transaction(function () use ($tx, $kind, $lines, $date, $morph) {
            // قيد بنوع تاني لنفس الأصل (أو الأصل بقى مش قابل للتكلفة) بيتشال
            $stale = CostEntry::where('origin_type', $morph)->where('origin_id', $tx->id)
                ->whereIn('kind', self::TX_KINDS)
                ->when($lines !== [], fn ($q) => $q->where('kind', '!=', $kind))->get();
            foreach ($stale as $s) {
                self::drop($s);
            }
            if ($lines === []) {
                return ['entry' => null, 'changed' => $stale->isNotEmpty(), 'deleted' => $stale->count()];
            }

            $attrs = self::entryAttrs($tx, $kind, $lines, $date);
            $entry = CostEntry::where('kind', $kind)->where('origin_type', $morph)->where('origin_id', $tx->id)
                ->lockForUpdate()->first();
            if ($entry !== null && self::signature($entry->only(array_keys($attrs)), $entry->lines()->orderBy('id')->get()->map->only(array_keys($lines[0]))->all())
                === self::signature($attrs, $lines)) {
                return ['entry' => $entry, 'changed' => false, 'deleted' => $stale->count()];
            }

            $entry ??= new CostEntry(['kind' => $kind, 'origin_type' => $morph, 'origin_id' => $tx->id]);
            $entry->fill($attrs)->save();
            $entry->lines()->delete();
            $entry->lines()->createMany($lines);
            $entry->unsetRelation('lines');

            return ['entry' => $entry, 'changed' => true, 'deleted' => $stale->count()];
        });
    }

    /** شيل القيد من الدفتر ومن الطبقة (السطور بالكاسكيد) */
    private static function drop(CostEntry $e, bool $unpost = true): void
    {
        if ($unpost) {
            app(Ledger::class)->unpost($e);
        }
        $e->delete();
    }

    /**
     * سطور التكلفة بالطريقتين + سعر التحويل. المرتجع: الكميات والمبالغ
     * موجبة والإيراد سالب (اتجاه الدفتر بيتقلب في `CostGl`).
     *
     * @return list<array<string, mixed>>
     */
    private static function linesFor(Transaction $tx, string $kind, Carbon $date): array
    {
        $raw = match (true) {
            $kind === 'return' => self::returnRows((int) $tx->source_id),
            (Relation::getMorphedModel($tx->source_type) ?? $tx->source_type) === PurchaseOrder::class => self::poRows((int) $tx->source_id),
            default => self::invoiceRows((int) $tx->source_id),
        };
        $raw = array_values(array_filter($raw, fn ($r) => (float) $r['qty'] > 0));
        if ($raw === []) {
            return [];
        }

        $products = Product::whereIn('id', array_unique(array_column($raw, 'product_id')))->get()->keyBy('id');
        $out = [];
        foreach ($raw as $r) {
            $p = $products->get($r['product_id']);
            if ($p === null) {
                continue;
            }
            $qty = round((float) $r['qty'], 3);
            $unitBatch = round((float) ($r['unit'] ?? $p->cost), 4);
            $unitWavg = Valuation::wavg($p, $date);
            $tp = ($p->kind ?? 'finished') === 'finished' ? TransferPrice::effective((int) $p->id, $date) : null;
            $unitTp = $tp === null ? null : round((float) $tp->price, 4);
            $sign = $kind === 'return' ? -1 : 1;

            $out[] = [
                'product_id' => (int) $p->id,
                'batch_id' => $r['batch_id'] ?? null,
                'qty' => $qty,
                'revenue' => round($sign * (float) $r['revenue'], 2),
                'unit_batch' => $unitBatch,
                'unit_wavg' => $unitWavg,
                'unit_transfer' => $unitTp,
                'amount_batch' => round($qty * $unitBatch, 2),
                'amount_wavg' => round($qty * $unitWavg, 2),
                'amount_transfer' => $unitTp === null ? null : round($qty * $unitTp, 2),
                'zero_cost' => $unitBatch <= 0 || $unitWavg <= 0,
            ];
        }

        return $out;
    }

    /** @return list<array{product_id:int, batch_id:?int, qty:float, revenue:float, unit:?float}> */
    private static function invoiceRows(int $invoiceId): array
    {
        return DB::table('invoice_items')->where('invoice_id', $invoiceId)->orderBy('id')
            ->get(['product_id', 'batch_id', 'qty', 'total', 'unit_cost'])
            ->map(fn ($r) => ['product_id' => (int) $r->product_id, 'batch_id' => $r->batch_id ? (int) $r->batch_id : null,
                'qty' => (float) $r->qty, 'revenue' => (float) $r->total, 'unit' => $r->unit_cost === null ? null : (float) $r->unit_cost])
            ->all();
    }

    /** بند الأمر بقيمة المسلَّم — نفس قاعدة `SalesSource::lines` بالحرف */
    private static function poRows(int $poId): array
    {
        return DB::table('purchase_order_items')->where('purchase_order_id', $poId)->orderBy('id')
            ->get(['product_id', 'qty', 'delivered_qty', 'price', 'total'])
            ->map(function ($r) {
                $full = $r->delivered_qty === null || (float) $r->delivered_qty === (float) $r->qty;

                return ['product_id' => (int) $r->product_id, 'batch_id' => null,
                    'qty' => (float) ($r->delivered_qty ?? $r->qty),
                    'revenue' => $full ? (float) $r->total : round((float) $r->delivered_qty * (float) $r->price, 2),
                    'unit' => null];
            })->all();
    }

    /** البنود السليمة بس — R من بند الفاتورة الأصلي وإلا تكلفة الصنف (`unit` = null) */
    private static function returnRows(int $returnId): array
    {
        return DB::table('return_items as ri')
            ->leftJoin('invoice_items as ii', 'ii.id', '=', 'ri.invoice_item_id')
            ->where('ri.return_id', $returnId)
            ->where('ri.condition', ClientReturn::CONDITION_GOOD)
            ->orderBy('ri.id')
            ->get(['ri.product_id', 'ii.batch_id', 'ri.qty', 'ri.total', 'ii.id as ii_id', 'ii.unit_cost'])
            ->map(fn ($r) => ['product_id' => (int) $r->product_id, 'batch_id' => $r->batch_id ? (int) $r->batch_id : null,
                'qty' => (float) $r->qty, 'revenue' => (float) $r->total,
                'unit' => $r->ii_id !== null && $r->unit_cost !== null ? (float) $r->unit_cost : null])
            ->all();
    }

    /** @param list<array<string, mixed>> $lines */
    private static function entryAttrs(Transaction $tx, string $kind, array $lines, Carbon $date): array
    {
        $withTp = array_filter($lines, fn ($l) => $l['unit_transfer'] !== null);
        $center = CostCenterResolver::for($tx) ?? CostCenter::maadi()->id;

        return [
            'date' => $date->toDateString(),
            'cost_center_id' => $center,
            'other_center_id' => null,
            'qty' => round(array_sum(array_column($lines, 'qty')), 3),
            'revenue' => round(array_sum(array_column($lines, 'revenue')), 2),
            'amount_batch' => round(array_sum(array_column($lines, 'amount_batch')), 2),
            'amount_wavg' => round(array_sum(array_column($lines, 'amount_wavg')), 2),
            'amount_transfer' => $withTp === [] ? null : round(array_sum(array_column($withTp, 'amount_transfer')), 2),
            'materials' => 0,
            'overhead' => 0,
            // `no_transfer_price` مابيتحطش على البيع أبداً — من غير سعر تحويل
            // تكلفة الفرع هي R (D4/D10)
            'flags' => collect($lines)->contains(fn ($l) => $l['zero_cost']) ? 'zero_cost' : null,
        ];
    }

    /**
     * توقيع ثابت للمقارنة (القيم من الداتابيز بتيجي نصوص بكسور — بتتوحّد).
     *
     * @param  array<string, mixed>  $attrs
     * @param  list<array<string, mixed>>  $lines
     */
    private static function signature(array $attrs, array $lines): string
    {
        $norm = function ($v) {
            if ($v instanceof \DateTimeInterface) {
                return $v->format('Y-m-d');
            }
            if (is_bool($v)) {
                return $v ? '1' : '0';
            }
            if (is_numeric($v)) {
                return number_format((float) $v, 4, '.', '');
            }

            return $v === null ? '' : (string) $v;
        };
        ksort($attrs);
        $a = array_map($norm, $attrs);
        $l = array_map(function ($line) use ($norm) {
            ksort($line);

            return array_map($norm, $line);
        }, $lines);

        return json_encode([$a, $l]);
    }
}
