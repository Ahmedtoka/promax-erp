<?php

namespace Tests\Feature;

use App\Models\OnlineOrder;
use App\Models\OnlinePickup;
use App\Models\OnlineReturn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * التصدير السيرفري لـ online.returns و online.accounts (١٧/١٠/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * `?export=1` = الملف الكامل من **نفس الكويري المفلترة** قبل الصفحات
 * (promax-system §7) — والزرار في الشاشة بيشيل زرار «إكسيل الشاشة».
 */
class OnlineExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeAdmin();
    }

    private function order(string $number, array $extra = []): OnlineOrder
    {
        return OnlineOrder::create($extra + [
            'shopify_id' => 950000 + (int) $number, 'number' => $number, 'customer_name' => 'عميل '.$number,
            'area' => 'Nasr City - Cairo', 'items_count' => 2, 'subtotal' => 200, 'shipping' => 50, 'total' => 250,
            'status' => 'shipped',
        ]);
    }

    private function csv($response): string
    {
        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));

        return $response->streamedContent();
    }

    public function test_returns_export_follows_filters_and_all_pages(): void
    {
        $pu = OnlinePickup::create(['number' => 'PU-X1', 'date' => today(), 'created_by' => $this->admin->id]);

        // 55 مرتجع (أكتر من صفحة 50) في الفترة + واحد قديم بره الفترة
        for ($i = 0; $i < 55; $i++) {
            $o = $this->order((string) (3000 + $i), ['status' => 'returned']);
            OnlineReturn::create(['online_order_id' => $o->id, 'pickup_id' => $pu->id, 'kind' => 'full', 'pieces' => 2,
                'value' => 200, 'lines' => [['title' => 'Bar', 'qty' => 2]], 'created_by' => $this->admin->id]);
        }
        $old = $this->order('3999', ['status' => 'returned']);
        OnlineReturn::create(['online_order_id' => $old->id, 'kind' => 'partial', 'pieces' => 1, 'value' => 100,
            'lines' => [['title' => 'Old', 'qty' => 1]], 'created_by' => $this->admin->id])
            ->forceFill(['created_at' => now()->subMonths(2)])->save();

        $from = today()->subWeek()->toDateString();
        $page = $this->actingAs($this->admin)->get(route('online.returns', ['from' => $from]));
        $page->assertOk()->assertSee('export=1', false);

        $body = $this->csv($this->actingAs($this->admin)->get(route('online.returns', ['from' => $from, 'export' => 1])));

        $this->assertStringContainsString('#3054', $body);           // آخر صف (الصفحة التانية)
        $this->assertStringNotContainsString('#3999', $body);        // بره الفترة
        $this->assertStringContainsString('2 × Bar', $body);
        $this->assertStringContainsString('11000.00', $body);        // 55 × 200
        $this->assertStringContainsString(__('online.returns_title'), $body);

        // فلتر الحالة بيمشي على الملف كمان
        $partial = $this->csv($this->actingAs($this->admin)->get(route('online.returns', ['state' => 'partial', 'export' => 1])));
        $this->assertStringContainsString('#3999', $partial);
        $this->assertStringNotContainsString('#3000', $partial);
    }

    public function test_accounts_export_has_summary_status_and_open_pickups(): void
    {
        $open = OnlinePickup::create(['number' => 'PU-OPEN', 'date' => today(), 'created_by' => $this->admin->id]);
        $oldOpen = OnlinePickup::create(['number' => 'PU-OLD', 'date' => today()->subMonths(2), 'created_by' => $this->admin->id]);
        $this->order('4001', ['pickup_id' => $open->id, 'collected_total' => 50]);
        $this->order('4002', ['pickup_id' => $oldOpen->id]);
        $this->order('4003', ['status' => 'completed', 'collected_total' => 200, 'cost_total' => 80]);

        $page = $this->actingAs($this->admin)->get(route('online.accounts'));
        $page->assertOk()->assertSee('export=1', false);

        $body = $this->csv($this->actingAs($this->admin)->get(route('online.accounts', ['export' => 1])));
        $this->assertStringContainsString(__('online.k_outstanding'), $body);
        $this->assertStringContainsString('350.00', $body);           // بره: 150 + 200
        $this->assertStringContainsString(__('online.status_completed'), $body);
        $this->assertStringContainsString('PU-OPEN', $body);
        $this->assertStringContainsString('PU-OLD', $body);

        // الفترة على تاريخ البيك اب — للجدول بس
        $ranged = $this->csv($this->actingAs($this->admin)->get(route('online.accounts', ['from' => today()->subWeek()->toDateString(), 'export' => 1])));
        $this->assertStringContainsString('PU-OPEN', $ranged);
        $this->assertStringNotContainsString('PU-OLD', $ranged);
        $this->assertStringContainsString('350.00', $ranged);         // السامري رصيد حي مش فترة
    }

    public function test_accountant_can_export_returns(): void
    {
        $acc = $this->makeAdmin(['role' => 'accountant']);

        $this->actingAs($acc)->get(route('online.returns', ['export' => 1]))->assertOk();
    }
}
