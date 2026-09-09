<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\GoodsReceipt;
use App\Models\PickOrder;
use App\Models\StockCount;
use App\Models\StockTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * فلتر «من — إلى» على شاشات المخازن (٩/٩/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * لكل شاشة: صفّين بتاريخين — واحد جوه النطاق وواحد بره — والفلتر
 * لازم يعرض الأول ويخبّي التاني. وكل شاشة لازم ترجّع 200 على
 * `?from=garbage` — `DateRange::day` بتعامل النص العبيط كفاضي بدل 500.
 *
 * ⚠️ الفلتر على **عمود العمل** لكل شاشة (received_on / sent_on /
 * expires_on / count_date / pickup_at / handed_at) مش `created_at` —
 * فالصفوف هنا كلها بتتعمل النهارده وبتتفرّق بعمود العمل بس.
 */
class DateFilterWarehouseTest extends TestCase
{
    use RefreshDatabase;

    private const FROM = '2026-03-01';

    private const TO = '2026-03-31';

    private const INSIDE = '2026-03-15';

    private const OUTSIDE = '2026-06-20';

    // ═══════════════ 1. الاستلام — received_on ═══════════════

    public function test_receipts_filter_on_received_on(): void
    {
        $admin = $this->makeAdmin();
        $wh = $this->makeWarehouse();

        foreach ([['GRN-IN-7101', self::INSIDE], ['GRN-OUT-7202', self::OUTSIDE]] as [$number, $on]) {
            GoodsReceipt::create([
                'number' => $number, 'warehouse_id' => $wh->id, 'received_on' => $on,
                'status' => 'posted', 'created_by' => $admin->id,
            ]);
        }

        $params = ['warehouse' => $wh->id, 'from' => self::FROM, 'to' => self::TO];

        $this->actingAs($admin)->get(route('wh.receipts', $params))
            ->assertOk()
            ->assertSee('GRN-IN-7101')
            ->assertDontSee('GRN-OUT-7202');

        $this->actingAs($admin)->get(route('wh.receipts', ['warehouse' => $wh->id, 'from' => 'garbage']))
            ->assertOk()
            ->assertSee('GRN-OUT-7202');
    }

    // ═══════════════ 2. التحويلات — sent_on ═══════════════

    public function test_transfers_filter_on_sent_on(): void
    {
        $admin = $this->makeAdmin();
        $from = $this->makeWarehouse();
        $to = $this->makeWarehouse();

        foreach ([['TRF-IN-7301', self::INSIDE], ['TRF-OUT-7402', self::OUTSIDE]] as [$number, $on]) {
            StockTransfer::create([
                'number' => $number, 'kind' => 'wh_wh',
                'from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id,
                'status' => 'sent', 'sent_on' => $on, 'created_by' => $admin->id,
            ]);
        }

        $this->actingAs($admin)->get(route('wh.transfers', ['from' => self::FROM, 'to' => self::TO]))
            ->assertOk()
            ->assertSee('TRF-IN-7301')
            ->assertDontSee('TRF-OUT-7402');

        $this->actingAs($admin)->get(route('wh.transfers', ['from' => 'garbage']))
            ->assertOk()
            ->assertSee('TRF-OUT-7402');
    }

    // ═══════════════ 3. الصلاحية — expires_on ═══════════════

    public function test_expiry_report_windows_on_batch_expires_on(): void
    {
        $admin = $this->makeAdmin();
        $wh = $this->makeWarehouse();
        $product = $this->makeProduct();

        // ⚠️ النافذة في المستقبل عشان الباتشين يبقوا سليمين (مش «منتهي»)
        // — الفلتر بيتفحص لوحده بعيد عن تصنيف الكروت
        $inside = today()->addMonths(6)->toDateString();
        $outside = today()->addMonths(12)->toDateString();

        foreach ([['EXP-IN-7511', $inside], ['EXP-OUT-7622', $outside]] as [$no, $on]) {
            Batch::create([
                'product_id' => $product->id, 'warehouse_id' => $wh->id, 'batch_no' => $no,
                'produced_on' => today(), 'expires_on' => $on,
                'qty_received' => 50, 'qty_remaining' => 50, 'cost' => 10,
            ]);
        }

        $this->actingAs($admin)->get(route('wh.expiry', [
            'warehouse' => $wh->id,
            'from' => today()->addMonths(5)->toDateString(),
            'to' => today()->addMonths(7)->toDateString(),
        ]))
            ->assertOk()
            ->assertSee('EXP-IN-7511')
            ->assertDontSee('EXP-OUT-7622');

        $this->actingAs($admin)->get(route('wh.expiry', ['warehouse' => $wh->id, 'from' => 'garbage']))
            ->assertOk()
            ->assertSee('EXP-OUT-7622');
    }

    // ═══════════════ 4. الجرد — count_date ═══════════════

    public function test_counts_filter_on_count_date(): void
    {
        $admin = $this->makeAdmin();
        $wh = $this->makeWarehouse();

        foreach ([['CNT-IN-7701', self::INSIDE], ['CNT-OUT-7802', self::OUTSIDE]] as [$number, $on]) {
            StockCount::create([
                'number' => $number, 'warehouse_id' => $wh->id, 'status' => 'approved',
                'started_by' => $admin->id, 'count_date' => $on,
            ]);
        }

        $this->actingAs($admin)->get(route('wh.counts', ['from' => self::FROM, 'to' => self::TO]))
            ->assertOk()
            ->assertSee('CNT-IN-7701')
            ->assertDontSee('CNT-OUT-7802');

        $this->actingAs($admin)->get(route('wh.counts', ['from' => 'garbage']))
            ->assertOk()
            ->assertSee('CNT-OUT-7802');
    }

    // ═══════════════ 5. أوامر التجهيز — pickup_at ═══════════════

    public function test_picks_filter_on_pickup_at(): void
    {
        $admin = $this->makeAdmin();
        $wh = $this->makeWarehouse();
        $rep = $this->makeRep();

        foreach ([['PCK-IN-7901', self::INSIDE], ['PCK-OUT-7002', self::OUTSIDE]] as [$number, $on]) {
            PickOrder::create([
                'number' => $number, 'warehouse_id' => $wh->id, 'assigned_to' => $rep->id,
                'requested_by' => $admin->id, 'purpose' => PickOrder::PURPOSE_VAN_LOAD,
                'status' => 'requested', 'pickup_at' => $on.' 09:00:00',
            ]);
        }

        $this->actingAs($admin)->get(route('wh.picks', ['from' => self::FROM, 'to' => self::TO]))
            ->assertOk()
            ->assertSee('PCK-IN-7901')
            ->assertDontSee('PCK-OUT-7002');

        $this->actingAs($admin)->get(route('wh.picks', ['from' => 'garbage']))
            ->assertOk()
            ->assertSee('PCK-OUT-7002');
    }

    // ═══════════════ 6. تسليم العهدة (الهيستوري) — handed_at ═══════════════

    public function test_handout_history_filters_on_handed_at(): void
    {
        $admin = $this->makeAdmin();
        $wh = $this->makeWarehouse();
        $rep = $this->makeRep();

        foreach ([['HND-IN-8101', self::INSIDE], ['HND-OUT-8202', self::OUTSIDE]] as [$number, $on]) {
            PickOrder::create([
                'number' => $number, 'warehouse_id' => $wh->id, 'assigned_to' => $rep->id,
                'requested_by' => $admin->id, 'purpose' => PickOrder::PURPOSE_VAN_LOAD,
                'status' => 'handed', 'handed_at' => $on.' 10:30:00',
            ]);
        }

        $params = ['warehouse' => $wh->id, 'from' => self::FROM, 'to' => self::TO];

        $this->actingAs($admin)->get(route('ops.handout', $params))
            ->assertOk()
            ->assertSee('HND-IN-8101')
            ->assertDontSee('HND-OUT-8202');

        $this->actingAs($admin)->get(route('ops.handout', ['warehouse' => $wh->id, 'from' => 'garbage']))
            ->assertOk()
            ->assertSee('HND-OUT-8202');
    }

    // ═══════════════ 7. تقرير الباتشات (من ملف) — expires_on ═══════════════

    /**
     * ⚠️ التقرير بيقرا من `storage/app/data/batch_report.json` مش من
     * الداتابيز. عشان مانلمسش الملف الحقيقي، التيست بيحوّل مسار
     * التخزين لمجلد مؤقت فيه شيت صغير بصنفين، ويرجّعه في الآخر.
     */
    public function test_batch_report_windows_on_batch_expires_on(): void
    {
        $admin = $this->makeAdmin();
        $original = $this->app->storagePath();
        $tmp = sys_get_temp_dir().'/promax_batches_'.uniqid();

        foreach (['app/data', 'framework/views', 'framework/cache', 'framework/sessions', 'logs'] as $dir) {
            mkdir($tmp.'/'.$dir, 0777, true);
        }

        file_put_contents($tmp.'/app/data/batch_report.json', json_encode([
            'generated_on' => '2026-07-30',
            'source' => ['test.xlsx'],
            'price_basis' => 'new',
            'warn_days' => 90,
            'danger_days' => 30,
            'items' => [
                $this->reportItem('9101', 'صنف جوه النطاق INSIDE-9101', '2027-03-15', 200),
                // ⚠️ الباتش البرّاني «هولد» عن قصد: جدول «أقرب الباتشات» فوق
                // بيتحسب على الكتالوج **كله** (قاعدة الـKPIs) وبيستبعد الهولد —
                // فالاسم ده مايظهرش غير في قايمة الأصناف، وهي اللي بتتفلتر.
                $this->reportItem('9202', 'صنف بره النطاق OUTSIDE-9202', '2027-09-20', 400, hold: true),
            ],
        ], JSON_UNESCAPED_UNICODE));

        $this->app->useStoragePath($tmp);

        try {
            $this->actingAs($admin)->get(route('erp.batches', ['from' => '2027-03-01', 'to' => '2027-03-31']))
                ->assertOk()
                ->assertSee('INSIDE-9101')
                ->assertDontSee('OUTSIDE-9202');

            $this->actingAs($admin)->get(route('erp.batches', ['from' => 'garbage']))
                ->assertOk()
                ->assertSee('OUTSIDE-9202');
        } finally {
            $this->app->useStoragePath($original);
            $this->rmdirRecursive($tmp);
        }
    }

    /** صنف بشكل الشيت بالظبط — باتش واحد بتاريخ انتهاء معيّن */
    private function reportItem(string $code, string $name, string $expiresOn, int $daysLeft, bool $hold = false): array
    {
        return [
            'code' => $code, 'barcode' => '622400385'.$code, 'name' => $name, 'name_en' => 'Item '.$code,
            'name_gs1_ar' => $name, 'flavour_en' => null, 'flavour_ar' => null, 'brand' => 'ProMax',
            'net_content' => 300.0, 'uom_en' => 'Gram', 'uom_ar' => 'غرام', 'tax_shared' => true,
            'family' => 'spreads', 'family_en' => 'PRO Spreads', 'family_ar' => 'سبريدز',
            'unit' => 'برطمان', 'shelf_life_months' => 18, 'price' => 65.0, 'image_url' => null,
            'qty' => 100, 'qty_live' => $hold ? 0 : 100, 'qty_hold' => $hold ? 100 : 0,
            'value' => 6500.0, 'value_live' => $hold ? 0.0 : 6500.0, 'value_hold' => $hold ? 6500.0 : 0.0,
            'soonest' => $daysLeft, 'soonest_on' => $expiresOn, 'batch_count' => 1,
            'batches' => [[
                'produced_on' => '2025-09-15', 'expires_on' => $expiresOn, 'days_left' => $daysLeft,
                'qty' => 100, 'value' => 6500.0, 'hold' => $hold, 'note' => $hold ? 'hold' : null,
            ]],
        ];
    }

    private function rmdirRecursive(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->rmdirRecursive($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
