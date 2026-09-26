<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Sheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * تقرير «مسحوبات العميل بالصنف» (٢٦/٩/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * طلب المالك: «تقرير فيه العميل بإجمالي مسحوباته وتحته تفصيل
 * مسحوباته بالمنتج … وإكسيل مرتب بنفس الترتيب».
 *
 * ⚠️ الإجمالي من قيود البيع بتاريخ القيد (SalesSource) — مش من إجمالي
 * الفاتورة. التيست بيحط قيد من غير بنود عشان يتأكد إن سطر «قيود بلا
 * بنود» بيقفل العميل على إجماليه.
 */
class ClientDrawsReportTest extends TestCase
{
    use RefreshDatabase;

    private const RANGE = ['from' => '2026-08-01', 'to' => '2026-08-31'];

    private User $admin;
    private User $rep;
    private Product $choco;
    private Product $nuts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeAdmin();
        $this->rep = $this->makeRep();
        $this->choco = $this->makeProduct(['code' => 'CHOCO1', 'name_en' => 'Choco Bar']);
        $this->nuts = $this->makeProduct(['code' => 'NUTS1', 'name_en' => 'Nuts Bar']);
    }

    /** فاتورة بقيد بيع بتاريخها — البنود [منتج, كمية, صافي, ضريبة] */
    private function sale(Client $c, string $date, array $lines): void
    {
        $net = array_sum(array_column($lines, 2));
        $tax = array_sum(array_column($lines, 3));

        $inv = Invoice::create([
            'number' => 'INV-'.random_int(100000, 999999), 'client_id' => $c->id, 'user_id' => $this->rep->id,
            'payment' => 'credit', 'subtotal' => $net, 'discount' => 0, 'total' => $net,
            'tax_total' => $tax, 'grand_total' => $net + $tax,
        ]);

        foreach ($lines as [$p, $q, $n, $t]) {
            InvoiceItem::create([
                'invoice_id' => $inv->id, 'product_id' => $p->id, 'qty' => $q, 'list_price' => $n / $q,
                'price' => $n / $q, 'unit_cost' => 1, 'total' => $n, 'tax_rate' => $n > 0 ? $t / $n : 0, 'tax' => $t,
            ]);
        }

        Transaction::create([
            'client_id' => $c->id, 'date' => $date, 'memo' => $inv->number, 'kind' => 'sale',
            'debit' => $net + $tax, 'credit' => 0, 'tax' => $tax,
            'source_type' => Invoice::class, 'source_id' => $inv->id,
        ]);
    }

    private function seedDraws(): array
    {
        $big = $this->makeClient(['code' => 'CL-BIG', 'name_en' => 'Big Client', 'rep_id' => $this->rep->id]);
        $small = $this->makeClient(['code' => 'CL-SMALL', 'name_en' => 'Small Client', 'rep_id' => $this->rep->id]);

        $this->sale($big, '2026-08-05', [[$this->choco, 10, 1000, 140], [$this->nuts, 5, 200, 28]]);
        $this->sale($big, '2026-08-20', [[$this->choco, 2, 200, 28]]);
        $this->sale($small, '2026-08-10', [[$this->nuts, 3, 300, 0]]);

        // قيد بيع يدوي من غير مستند ولا بنود — لازم يظهر سطر «قيود بلا بنود»
        Transaction::create(['client_id' => $small->id, 'date' => '2026-08-12', 'memo' => 'opening import',
            'kind' => 'sale', 'debit' => 50, 'credit' => 0]);

        // مرتجع للكبير، وبيع بره الفترة مايتحسبش
        Transaction::create(['client_id' => $big->id, 'date' => '2026-08-25', 'memo' => 'ret', 'kind' => 'return',
            'debit' => 0, 'credit' => 100]);
        $this->sale($big, '2026-09-02', [[$this->choco, 99, 9900, 0]]);

        return [$big, $small];
    }

    public function test_each_client_line_carries_its_ledger_total_and_products_underneath(): void
    {
        [$big, $small] = $this->seedDraws();

        $res = $this->actingAs($this->admin)->get(route('erp.reports.show', ['key' => 'client_draws'] + self::RANGE))
            ->assertOk();

        // الكبير: فاتورة 1368 + فاتورة 228 = 1596 · الشوكولاتة 12 قطعة = 1368 · المرتجع 100 → صافي 1496
        $res->assertSeeInOrder(['Big Client', '1,596.00', '100.00', '1,496.00',
            'Choco Bar', '1,368.00', 'Nuts Bar', '228.00',
            'Small Client', '350.00', 'Nuts Bar', '300.00', __('rpt.cd_unlined'), '50.00'], false);
        $res->assertSee('1,946.00')->assertDontSee('9,900.00');
        $res->assertSee('class="rpt-grp"', false)->assertSee('class="rpt-sub"', false);
    }

    public function test_the_excel_file_keeps_the_same_grouped_order_with_real_numbers(): void
    {
        $this->seedDraws();

        $res = $this->actingAs($this->admin)
            ->get(route('erp.reports.show', ['key' => 'client_draws', 'export' => 1] + self::RANGE))
            ->assertOk();

        $this->assertStringContainsString('spreadsheetml', (string) $res->headers->get('Content-Type'));

        $rows = Sheet::rows($res->baseResponse->getFile()->getPathname());
        $flat = array_map(fn ($r) => array_values(array_map(fn ($v) => is_string($v) ? trim($v) : $v, $r)), $rows);

        $find = function (string $needle) use ($flat) {
            foreach ($flat as $i => $r) {
                if (in_array($needle, array_map('strval', $r), true)) {
                    return $i;
                }
            }

            return null;
        };

        $big = $find('Big Client');
        $choco = $find('↳ Choco Bar');
        $small = $find('Small Client');
        $unlined = $find('↳ '.__('rpt.cd_unlined'));

        $this->assertNotNull($big);
        $this->assertTrue($big < $choco && $choco < $small && $small < $unlined, 'client → its products → next client');

        // الأرقام أرقام في الإكسيل، مش نص بفاصلة
        $this->assertEquals(1596.0, Sheet::number($flat[$big][7]));
        $this->assertEquals(1368.0, Sheet::number($flat[$choco][7]));
        $this->assertEquals(50.0, Sheet::number($flat[$unlined][7]));
    }

    public function test_filters_and_scope(): void
    {
        [$big] = $this->seedDraws();

        $this->actingAs($this->admin)
            ->get(route('erp.reports.show', ['key' => 'client_draws', 'q' => 'small'] + self::RANGE))
            ->assertOk()->assertSee('Small Client')->assertDontSee('Big Client');

        // الفلتر بالمندوب على صاحب المستند — القيد اليدوي بيتبع مندوب العميل
        $other = $this->makeRep();
        $this->actingAs($this->admin)
            ->get(route('erp.reports.show', ['key' => 'client_draws', 'user_id' => $other->id] + self::RANGE))
            ->assertOk()->assertDontSee('Big Client');

        $this->actingAs($this->admin)->get(route('erp.reports.show', ['key' => 'client_draws', 'from' => 'garbage']))
            ->assertOk();
        $this->actingAs($this->admin)->get(route('erp.reports.hub'))->assertOk()->assertSee(__('rpt.client_draws'));
    }
}
