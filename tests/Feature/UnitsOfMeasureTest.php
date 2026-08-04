<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Custody;
use App\Models\CustodyItem;
use App\Models\Invoice;
use App\Models\PriceList;
use App\Models\PriceListItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * وحدات القياس: قطعة / علبة / كرتونة — قرار المالك 2026-08-04
 * ═══════════════════════════════════════════════════════════════
 *
 * القواعد اللي التيست ده بيحرسها:
 *
 *   1. المخزون بالقطعة دايماً — الوحدة مضاعِف إدخال **في السيرفر**.
 *   2. وحدة مش معرّفة للصنف = رفض، مش افتراض قطعة (2 ≠ 144).
 *   3. سعر الوحدة = سعر القطعة × المضاعِف — مفيش سعر خاص للكرتونة.
 *   4. سقف المرتجع بيتفحص **بعد** التحويل للقطع (9999 قطعة) —
 *      «9999 كرتونة» كانت بتعدّي الفاليديشن وتتحول 719,928 قطعة
 *      على إندبوينت بيكتب قيد دائن من غير حارس مخزون.
 *   5. أسعار القوايم من فورم الصنف: الصفر/الفاضي مايدوسش على سعر
 *      معتمد — نفس قاعدة Pricing::syncColumnsToLists.
 */
class UnitsOfMeasureTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: \App\Models\User, 1: \App\Models\Client, 2: \App\Models\Product} */
    private function scene(): array
    {
        $channel = $this->makeChannel(0.0);
        $zone = $this->makeZone();

        $rep = $this->makeRep(['zone_id' => $zone->id, 'channel_id' => $channel->id]);
        $client = $this->makeClient([
            'zone_id' => $zone->id,
            'channel_id' => $channel->id,
            'taxable' => false,
            'payment_terms' => 'credit',
        ]);

        // بروتين بار: علبة 12 قطعة، كرتونة 72
        $product = $this->makeProduct([
            'cost' => 10, 'price_new' => 20,
            'box_units' => 12, 'units_per_case' => 72,
        ]);

        $warehouse = $this->makeWarehouse();

        $batch = Batch::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'batch_no' => 'B-1',
            'produced_on' => today()->subMonth(),
            'expires_on' => today()->addMonths(6),
            'qty_received' => 500,
            'qty_remaining' => 500,
            'cost' => 10,
        ]);

        $custody = Custody::create([
            'user_id' => $rep->id,
            'warehouse_id' => $warehouse->id,
            'date' => today(),
            'status' => 'open',
        ]);

        CustodyItem::create([
            'custody_id' => $custody->id,
            'product_id' => $product->id,
            'batch_id' => $batch->id,
            'assigned' => 300,
            'sold' => 0,
        ]);

        return [$rep, $client, $product];
    }

    private function sell(array $scene, array $items)
    {
        [$rep, $client] = $scene;

        return $this->withHeaders($this->tokenFor($rep))
            ->postJson('/api/invoices', [
                'client_id' => $client->id,
                'items' => $items,
            ]);
    }

    // ═══ 1+3: البيع بالكرتونة بيتخزن قطع وبيتسعّر سعر قطعة × العدد ═══

    public function test_selling_two_boxes_stores_pieces_and_prices_per_piece(): void
    {
        $scene = $this->scene();
        [, , $product] = $scene;

        $res = $this->sell($scene, [
            ['product_id' => $product->id, 'qty' => 2, 'unit' => 'box'],
        ]);

        $res->assertStatus(201);

        $invoice = Invoice::latest('id')->first();

        // 2 علبة × 12 قطعة = 24 قطعة × 20 جنيه = 480
        $this->assertEqualsWithDelta(480.0, (float) $invoice->total, 0.01);
        $this->assertSame(24, (int) $invoice->items()->sum('qty'));

        // العهدة اتخصمت **بالقطع**
        $this->assertSame(24, (int) CustodyItem::where('product_id', $product->id)->sum('sold'));
    }

    // ═══ الفلو القديم من غير unit — ولا حاجة اتغيرت ═══

    public function test_items_without_unit_behave_exactly_as_before(): void
    {
        $scene = $this->scene();
        [, , $product] = $scene;

        $this->sell($scene, [
            ['product_id' => $product->id, 'qty' => 10],
        ])->assertStatus(201);

        $invoice = Invoice::latest('id')->first();

        $this->assertEqualsWithDelta(200.0, (float) $invoice->total, 0.01);
        $this->assertSame(10, (int) $invoice->items()->sum('qty'));
    }

    // ═══ 2: وحدة مش معرّفة = رفض مش افتراض قطعة ═══

    public function test_undefined_unit_is_rejected_not_assumed_piece(): void
    {
        $scene = $this->scene();
        [, , $product] = $scene;

        // صنف من غير علبة خالص
        $product->update(['box_units' => null]);

        $this->sell($scene, [
            ['product_id' => $product->id, 'qty' => 2, 'unit' => 'box'],
        ])->assertStatus(422);

        $this->assertNull(Invoice::latest('id')->first(), 'اتعملت فاتورة بوحدة مش معرّفة');
    }

    // ═══ 4: سقف المرتجع بعد التحويل للقطع ═══

    public function test_return_cap_is_checked_in_pieces_after_conversion(): void
    {
        $scene = $this->scene();
        [$rep, $client, $product] = $scene;

        // زيارة مفتوحة — شرط المرتجع
        $visit = \App\Models\Visit::create([
            'user_id' => $rep->id,
            'client_id' => $client->id,
            'checked_in_at' => now(),
        ]);

        // 9999 كرتونة تعدّي max:9999 بالوحدة — لازم تترفض بالقطع
        $this->withHeaders($this->tokenFor($rep))
            ->postJson('/api/returns', [
                'client_id' => $client->id,
                'visit_id' => $visit->id,
                'items' => [
                    ['product_id' => $product->id, 'qty' => 9999, 'unit' => 'case'],
                ],
            ])->assertStatus(422);

        $this->assertSame(
            0,
            (int) CustodyItem::where('product_id', $product->id)->sum('returned_in'),
            'مرتجع 719,928 قطعة اتسجّل رغم السقف'
        );
    }

    // ═══ 5: أسعار القوايم — الصفر مايدوسش على سعر معتمد ═══

    public function test_zero_or_empty_list_price_does_not_wipe_a_real_price(): void
    {
        $admin = $this->makeAdmin();
        $product = $this->makeProduct(['cost' => 10, 'price_new' => 20, 'price_old' => 18]);

        $list = PriceList::create([
            'code' => 'new', 'name' => 'الجديدة', 'active' => true, 'is_default' => true,
        ]);
        PriceListItem::create([
            'price_list_id' => $list->id, 'product_id' => $product->id, 'price' => 20,
        ]);

        $this->actingAs($admin)
            ->put(route('erp.products.update', $product), [
                'name' => $product->name,
                'name_en' => $product->name_en,
                'unit' => $product->unit ?: 'قطعة',
                'cost' => 10,
                'shelf_life_months' => 12,
                'active' => 1,
                // صفر صريح + إرسال القوايم — السعر المعتمد مايتلمسش
                'list_price' => [$list->id => 0],
            ]);

        $this->assertEqualsWithDelta(
            20.0,
            (float) PriceListItem::where('product_id', $product->id)->value('price'),
            0.01,
            'الصفر داس على سعر القايمة المعتمد'
        );
        $this->assertEqualsWithDelta(20.0, (float) $product->fresh()->price_new, 0.01);
    }

    // ═══ أسعار القوايم بتتكتب من الفورم وبتزامن العمود ═══

    public function test_list_price_writes_item_and_syncs_migrated_column(): void
    {
        $admin = $this->makeAdmin();
        $product = $this->makeProduct(['cost' => 10, 'price_new' => 20]);

        $list = PriceList::create([
            'code' => 'new', 'name' => 'الجديدة', 'active' => true, 'is_default' => true,
        ]);

        $this->actingAs($admin)
            ->put(route('erp.products.update', $product), [
                'name' => $product->name,
                'name_en' => $product->name_en,
                'unit' => $product->unit ?: 'قطعة',
                'cost' => 10,
                'shelf_life_months' => 12,
                'active' => 1,
                'list_price' => [$list->id => 25.5],
            ]);

        $this->assertEqualsWithDelta(
            25.5,
            (float) PriceListItem::where('product_id', $product->id)->value('price'),
            0.01
        );
        $this->assertEqualsWithDelta(25.5, (float) $product->fresh()->price_new, 0.01);
    }
}
