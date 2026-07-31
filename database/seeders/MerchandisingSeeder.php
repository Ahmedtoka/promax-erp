<?php

namespace Database\Seeders;

use App\Models\Channel;
use App\Models\Client;
use App\Models\Custody;
use App\Models\MerchVisit;
use App\Models\Product;
use App\Models\ReplenishmentItem;
use App\Models\ReplenishmentRequest;
use App\Models\ShelfRefill;
use App\Models\TrackEvent;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * يوم شغل البروموتر: زيارة فرع كي أكاونت، ريفيل للرف، وطلب توريد للناقص
 */
class MerchandisingSeeder extends Seeder
{
    public function run(): void
    {
        $promoter = User::where('role', 'promoter')->first();
        if (! $promoter) {
            $this->command->warn('   ! مفيش بروموتر — شغّل TeamSeeder الأول');

            return;
        }

        if (MerchVisit::exists()) {
            return;
        }

        // فروع كي أكاونت للزيارة
        $branches = Client::whereHas('channel', fn ($q) => $q->where('code', Channel::KEY_ACCOUNT))
            ->orderByDesc('purchases')->take(3)->get();

        if ($branches->isEmpty()) {
            $this->command->warn('   ! مفيش عملاء كي أكاونت');

            return;
        }

        // عهدة البروموتر (شوية بضاعة معاه للطوارئ)
        $custody = Custody::firstOrCreate(
            ['user_id' => $promoter->id, 'date' => today()],
            ['status' => 'open'],
        );
        foreach (['1005' => 24, '1007' => 24, '1017' => 36, '1019' => 36] as $code => $qty) {
            $product = Product::where('code', (string) $code)->first();
            if ($product) {
                $custody->items()->updateOrCreate(
                    ['product_id' => $product->id],
                    ['assigned' => $qty, 'sold' => 0, 'returned' => 0],
                );
            }
        }

        TrackEvent::firstOrCreate(
            ['user_id' => $promoter->id, 'type' => 'start', 'title' => 'بداية اليوم'],
            [
                'subtitle' => 'خط زيارات الكي أكاونت',
                'lat' => 30.0450, 'lng' => 31.2300,
                'happened_at' => today()->setTime(8, 30),
            ],
        );

        // ===== زيارة مكتملة =====
        $b1 = $branches[0];
        $v1 = MerchVisit::create([
            'user_id' => $promoter->id,
            'client_id' => $b1->id,
            'checked_in_at' => today()->setTime(9, 20),
            'checked_out_at' => today()->setTime(9, 55),
            'photo_before' => 'demo/shelf-before.jpg',
            'photo_after' => 'demo/shelf-after.jpg',
            'lat' => 30.0510, 'lng' => 31.3410,
            'note' => 'الرف كان فاضي من بارات PMX',
        ]);

        $lines = [
            ['1017', 6, 24, 18, false],   // كود، على الرف قبل، في المخزن، اتنقل، ناقص
            ['1019', 4, 30, 26, false],
            ['1005', 12, 12, 12, false],
            ['1002', 0, 0, 0, true],      // ناقص خالص
            ['1011', 2, 0, 0, true],      // ناقص من المخزن
        ];

        foreach ($lines as [$code, $shelf, $store, $moved, $oos]) {
            $product = Product::where('code', $code)->first();
            if (! $product) {
                continue;
            }
            ShelfRefill::create([
                'merch_visit_id' => $v1->id,
                'product_id' => $product->id,
                'shelf_before' => $shelf,
                'store_qty' => $store,
                'moved_qty' => $moved,
                'out_of_stock' => $oos,
            ]);
        }

        TrackEvent::create([
            'user_id' => $promoter->id, 'type' => 'check_in',
            'title' => 'زيارة رف - '.$b1->name, 'subtitle' => $b1->address,
            'lat' => 30.0510, 'lng' => 31.3410,
            'happened_at' => $v1->checked_in_at,
        ]);
        TrackEvent::create([
            'user_id' => $promoter->id, 'type' => 'refill',
            'title' => 'ريفيل - '.$b1->name,
            'subtitle' => '56 قطعة اتنقلت للرف • صنفين ناقصين',
            'lat' => 30.0510, 'lng' => 31.3410,
            'happened_at' => today()->setTime(9, 45),
        ]);
        TrackEvent::create([
            'user_id' => $promoter->id, 'type' => 'check_out',
            'title' => 'خروج - '.$b1->name, 'subtitle' => 'مدة الزيارة 35 دقيقة',
            'lat' => 30.0510, 'lng' => 31.3410,
            'happened_at' => $v1->checked_out_at,
        ]);

        // ===== طلب ريفيل للناقص =====
        $req = ReplenishmentRequest::create([
            'number' => ReplenishmentRequest::nextNumber(),
            'client_id' => $b1->id,
            'merch_visit_id' => $v1->id,
            'requested_by' => $promoter->id,
            'status' => 'pending',
            'note' => 'الرف فاضي من الاسبريد والبروكب — محتاج توريد عاجل',
        ]);

        foreach (['1002' => 12, '1011' => 24] as $code => $qty) {
            $product = Product::where('code', (string) $code)->first();
            if ($product) {
                ReplenishmentItem::create([
                    'replenishment_request_id' => $req->id,
                    'product_id' => $product->id,
                    'qty' => $qty,
                ]);
            }
        }

        TrackEvent::create([
            'user_id' => $promoter->id, 'type' => 'request',
            'title' => 'طلب ريفيل '.$req->number.' - '.$b1->name,
            'subtitle' => '36 قطعة مطلوبة',
            'lat' => 30.0510, 'lng' => 31.3410,
            'happened_at' => today()->setTime(9, 50),
        ]);

        $this->command->info('   • زيارة بروموتر + طلب ريفيل واحد');
    }
}
