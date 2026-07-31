<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\Custody;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\TrackEvent;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Database\Seeder;

/**
 * يوم شغل كامل: عهدة العربيات، زيارات وفواتير، أوامر توريد، وطلب عميل جديد
 * — عشان السيستم والأبلكيشن يفتحوا وفيهم حركة حقيقية
 */
class FieldDaySeeder extends Seeder
{
    public function run(): void
    {
        $ahmed = User::where('code', 'SLS-014')->first();
        $mariam = User::where('code', 'SLS-021')->first();
        $courier = User::where('code', 'DRV-007')->first();

        if (! $ahmed || ! $mariam || ! $courier) {
            $this->command->warn('   ! المستخدمين مش موجودين — شغّل TeamSeeder الأول');

            return;
        }

        $this->loadVan($ahmed, [
            '1001' => 24, '1002' => 20, '1005' => 120, '1007' => 150,
            '1011' => 48, '1013' => 36, '1017' => 150, '1019' => 150, '1021' => 120,
        ]);
        $this->loadVan($mariam, [
            '1003' => 15, '1004' => 10, '1006' => 120, '1010' => 100,
            '1012' => 36, '1016' => 36, '1018' => 150, '1020' => 120, '1022' => 150,
        ]);
        $this->loadVan($courier, [
            '1002' => 16, '1005' => 60, '1007' => 72, '1011' => 30,
            '1017' => 60, '1019' => 80, '1021' => 40, '1001' => 15, '1004' => 8,
        ]);

        $this->seedVisitsAndInvoices($ahmed);
        $this->seedPurchaseOrders($courier);
        $this->seedClientRequest($ahmed);

        $this->command->info('   • عهدة 3 عربيات + زيارات وفواتير + 5 أوامر توريد');
    }

    /** تحميل عربية بعهدة النهارده */
    private function loadVan(User $user, array $codeQty): Custody
    {
        $custody = Custody::updateOrCreate(
            ['user_id' => $user->id, 'date' => today()],
            ['status' => 'open'],
        );

        foreach ($codeQty as $code => $qty) {
            $product = Product::where('code', (string) $code)->first();
            if ($product) {
                $custody->items()->updateOrCreate(
                    ['product_id' => $product->id],
                    ['assigned' => $qty, 'sold' => 0, 'returned' => 0],
                );
            }
        }

        TrackEvent::firstOrCreate(
            ['user_id' => $user->id, 'type' => 'start', 'title' => 'بداية اليوم'],
            [
                'subtitle' => 'استلام العهدة وتحميل العربية',
                'lat' => 30.0450, 'lng' => 31.2300,
                'happened_at' => today()->setTime(8, 0),
            ],
        );

        return $custody;
    }

    /** زيارتين خلصوا بفواتير حقيقية لأحمد */
    private function seedVisitsAndInvoices(User $rep): void
    {
        if (Invoice::where('user_id', $rep->id)->whereDate('created_at', today())->exists()) {
            return;
        }

        $custody = $rep->todayCustody();
        $zoneClients = Client::where('zone_id', $rep->zone_id)
            ->where('category', '!=', 'internal')
            ->orderByDesc('purchases')
            ->take(2)
            ->get();

        $plan = [
            [['1007' => 12, '1001' => 3, '1002' => 2], 'cash', 9, 15, 9, 32, 9, 40],
            [['1019' => 24, '1011' => 6, '1017' => 12], 'cash', 10, 5, 10, 20, 10, 28],
        ];

        foreach ($zoneClients as $i => $client) {
            if (! isset($plan[$i])) {
                break;
            }
            [$lines, $payment, $inH, $inM, $sH, $sM, $outH, $outM] = $plan[$i];

            $visit = Visit::create([
                'user_id' => $rep->id,
                'client_id' => $client->id,
                'checked_in_at' => today()->setTime($inH, $inM),
                'checked_out_at' => today()->setTime($outH, $outM),
                'lat' => 30.0510 + $i * 0.007,
                'lng' => 31.3410 + $i * 0.006,
            ]);

            TrackEvent::create([
                'user_id' => $rep->id, 'type' => 'check_in',
                'title' => 'تشيك إن - '.$client->name, 'subtitle' => $client->address,
                'lat' => $visit->lat, 'lng' => $visit->lng,
                'happened_at' => $visit->checked_in_at,
            ]);

            $subtotal = 0;   // بسعر القائمة قبل الخصم
            $net = 0;        // بعد الخصم — ده إجمالي الفاتورة
            $costTotal = 0;
            $rows = [];
            foreach ($lines as $code => $qty) {
                $product = Product::where('code', (string) $code)->first();
                if (! $product) {
                    continue;
                }
                // ⚠️ نفس مصدر التسعير اللي الـ API بيستخدمه (Pricing)، عشان
                // الداتا المزروعة تبقى مطابقة للحقيقية: مجموع سطور الفاتورة
                // لازم يساوي invoices.total بالظبط.
                $quote = \App\Services\Pricing::quote($client, $product, null, $qty);

                $rows[] = [
                    'product' => $product,
                    'qty' => $qty,
                    'list_price' => $quote['list_price'],
                    'price' => $quote['unit_price'],
                    'unit_cost' => $quote['unit_cost'],
                    'total' => $quote['line_total'],
                ];

                $subtotal += round($quote['list_price'] * $qty, 2);
                $net += $quote['line_total'];
                $costTotal += $quote['line_cost'];
            }

            $discPct = $client->effectiveDiscount();
            $discount = round($subtotal - $net, 2);
            $total = $net;

            $invoice = Invoice::create([
                'number' => Invoice::nextNumber(),
                'client_id' => $client->id,
                'user_id' => $rep->id,
                'visit_id' => $visit->id,
                'payment' => $client->cashOnly() ? 'cash' : $payment,
                'price_list' => $client->priceList(),
                'subtotal' => $subtotal,
                'discount_pct' => $discPct,
                'discount_source' => $client->discountSourceKey(),
                'discount' => $discount,
                'total' => $total,
                'cost_total' => round($costTotal, 2),
                'lat' => $visit->lat, 'lng' => $visit->lng,
            ]);

            // وقت الفاتورة زي ما هو مخطط (created_at مش fillable)
            $invoice->forceFill([
                'created_at' => today()->setTime($sH, $sM),
                'updated_at' => today()->setTime($sH, $sM),
            ])->save();

            $deduct = [];
            foreach ($rows as $r) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'product_id' => $r['product']->id,
                    'qty' => $r['qty'],
                    'list_price' => $r['list_price'],
                    'price' => $r['price'],
                    'unit_cost' => $r['unit_cost'],
                    'total' => $r['total'],
                ]);
                $deduct[$r['product']->id] = $r['qty'];
            }
            // ⚠️ لو النقص اتبلع، بتتزرع فاتورة من غير حركة عهدة والأرقام
            // مابتتطابقش. أحسن السيدر يقع بصوت عالي.
            if ($err = $custody?->deduct($deduct)) {
                throw new \RuntimeException($err);
            }

            Transaction::create([
                'client_id' => $client->id,
                'date' => today(),
                'memo' => 'فاتورة '.$invoice->number.' — كاش فان '.$rep->name,
                'debit' => $total,
                'credit' => 0,
                'kind' => 'sale',
                'source_type' => Invoice::class,
                'source_id' => $invoice->id,
            ]);

            if ($invoice->payment === 'cash') {
                Transaction::create([
                    'client_id' => $client->id,
                    'date' => today(),
                    'memo' => 'تحصيل نقدي مع فاتورة '.$invoice->number,
                    'debit' => 0,
                    'credit' => $total,
                    'kind' => 'collection',
                    'source_type' => Invoice::class,
                    'source_id' => $invoice->id,
                ]);
            }

            TrackEvent::create([
                'user_id' => $rep->id, 'type' => 'sale',
                'title' => 'فاتورة '.$invoice->number.' - '.$client->name,
                'subtitle' => number_format($total).' ج',
                'lat' => $visit->lat, 'lng' => $visit->lng,
                'happened_at' => $invoice->created_at,
            ]);

            TrackEvent::create([
                'user_id' => $rep->id, 'type' => 'check_out',
                'title' => 'تشيك أوت - '.$client->name,
                'subtitle' => 'مدة الزيارة '.$visit->minutes().' دقيقة',
                'lat' => $visit->lat, 'lng' => $visit->lng,
                'happened_at' => $visit->checked_out_at,
            ]);

            $client->recalculate();
        }
    }

    /** أوامر التوريد للكورير — Gourrmet Egypt و Rabbit */
    private function seedPurchaseOrders(User $courier): void
    {
        if (PurchaseOrder::whereDate('created_at', today())->exists()) {
            return;
        }

        $gourmet = Client::where('name', 'Gourrmet Egypt')->first();
        $rabbit = Client::where('name', 'Rabbit')->first();
        if (! $gourmet || ! $rabbit) {
            return;
        }

        $custody = $courier->todayCustody();

        $orders = [
            [$gourmet, 'جورميه', 'التجمع الخامس - القاهرة الجديدة', ['1002' => 12, '1005' => 48, '1011' => 24], 'delivered', 9, 10, 9, 35],
            [$rabbit, 'رابيت', 'مخزن مصر الجديدة - ش الحجاز', ['1007' => 60, '1019' => 72], 'delivered', 10, 20, 10, 45],
            [$gourmet, 'جورميه', 'فرع مدينة نصر - ش عباس العقاد', ['1001' => 12, '1004' => 6, '1017' => 24], 'arrived', 11, 50, null, null],
            [$rabbit, 'رابيت', 'مخزن المعادي - ش 9', ['1017' => 36, '1021' => 36], 'pending', null, null, null, null],
            [$gourmet, 'جورميه', 'فرع الزمالك - ش 26 يوليو', ['1002' => 4, '1005' => 12, '1007' => 12], 'pending', null, null, null, null],
        ];

        foreach ($orders as [$client, $source, $address, $lines, $status, $aH, $aM, $dH, $dM]) {
            $po = PurchaseOrder::create([
                'number' => PurchaseOrder::nextNumber(),
                'client_id' => $client->id,
                'source' => $source,
                'address' => $address,
                'assigned_to' => $courier->id,
                'status' => $status,
                'price_mode' => 'old',
                'total' => 0,
                'arrived_at' => $aH !== null ? today()->setTime($aH, $aM) : null,
                'delivered_at' => $dH !== null ? today()->setTime($dH, $dM) : null,
            ]);

            $total = 0;
            $deduct = [];
            foreach ($lines as $code => $qty) {
                $product = Product::where('code', (string) $code)->first();
                if (! $product) {
                    continue;
                }
                $price = $product->priceFor('old');
                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'product_id' => $product->id,
                    'qty' => $qty,
                    'delivered_qty' => $status === 'delivered' ? $qty : 0,
                    'price' => $price,
                    'total' => $qty * $price,
                ]);
                $total += $qty * $price;
                $deduct[$product->id] = $qty;
            }
            $po->update(['total' => $total]);

            if ($status === 'delivered') {
                // ⚠️ لو النقص اتبلع، بتتزرع فاتورة من غير حركة عهدة والأرقام
            // مابتتطابقش. أحسن السيدر يقع بصوت عالي.
            if ($err = $custody?->deduct($deduct)) {
                throw new \RuntimeException($err);
            }

                Transaction::create([
                    'client_id' => $client->id,
                    'date' => today(),
                    'memo' => 'تسليم أمر توريد '.$po->number,
                    'debit' => $total,
                    'credit' => 0,
                    'kind' => 'sale',
                    'source_type' => PurchaseOrder::class,
                    'source_id' => $po->id,
                ]);

                TrackEvent::create([
                    'user_id' => $courier->id, 'type' => 'deliver',
                    'title' => 'تسليم '.$po->number.' - '.$client->name,
                    'subtitle' => $po->qtyTotal().' وحدة • '.number_format($total).' ج',
                    'lat' => 30.0100, 'lng' => 31.4300,
                    'happened_at' => $po->delivered_at,
                ]);

                $client->recalculate();
            } elseif ($status === 'arrived') {
                TrackEvent::create([
                    'user_id' => $courier->id, 'type' => 'check_in',
                    'title' => 'وصول - '.$client->name,
                    'subtitle' => $address,
                    'lat' => 30.0510, 'lng' => 31.3410,
                    'happened_at' => $po->arrived_at,
                ]);
            }
        }
    }

    /** طلب عميل جديد مستني موافقة المدير */
    private function seedClientRequest(User $rep): void
    {
        if (ClientRequest::exists()) {
            return;
        }

        ClientRequest::create([
            'number' => ClientRequest::nextNumber(),
            'name' => 'جيم فيتنس بلس',
            'phone' => '01099887766',
            'address' => 'ش مصطفى النحاس - مدينة نصر',
            'zone_id' => $rep->zone_id,
            'has_docs' => true,
            'photo_path' => 'demo/gym.jpg',
            'status' => 'pending',
            'created_by' => $rep->id,
        ]);

        TrackEvent::create([
            'user_id' => $rep->id, 'type' => 'request',
            'title' => 'طلب عميل جديد - جيم فيتنس بلس',
            'subtitle' => 'مستني موافقة المدير',
            'lat' => 30.0585, 'lng' => 31.3480,
            'happened_at' => today()->setTime(11, 30),
        ]);
    }
}
