<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * إكسيل الفاتورة الواحدة (٩/٩/٢٠٢٦) — xlsx بـ`SheetWriter` من صفحة
 * الفاتورة، بنفس حراس الصفحة (الفرع + سكوب المدير).
 */
class InvoiceExportTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(): Invoice
    {
        $rep = $this->makeRep();
        $client = $this->makeClient();
        $bar = $this->makeProduct(['code' => 'BAR-9', 'name' => 'بروتين بار', 'name_en' => 'Protein bar']);

        $invoice = Invoice::create([
            'number' => 'INV-77001', 'client_id' => $client->id, 'user_id' => $rep->id,
            'payment' => 'cash', 'subtotal' => 660, 'discount' => 0, 'total' => 660, 'tax_total' => 0, 'grand_total' => 660,
        ]);
        InvoiceItem::create(['invoice_id' => $invoice->id, 'product_id' => $bar->id, 'qty' => 10, 'list_price' => 66, 'price' => 66, 'unit_cost' => 30, 'total' => 660, 'tax_rate' => 0, 'tax' => 0]);

        return $invoice;
    }

    public function test_the_invoice_downloads_as_xlsx(): void
    {
        $invoice = $this->invoice();

        $res = $this->actingAs($this->makeAdmin())->get(route('ops.invoices.export', $invoice));

        $res->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $res->headers->get('content-type'));
        $this->assertStringContainsString('invoice-INV-77001.xlsx', (string) $res->headers->get('content-disposition'));
    }

    public function test_the_invoice_page_offers_excel_and_pdf(): void
    {
        $invoice = $this->invoice();

        $this->actingAs($this->makeAdmin())->get(route('ops.invoice', $invoice))
            ->assertOk()
            ->assertSee(route('ops.invoices.export', $invoice), false)
            ->assertSee(__('ops.save_pdf'));
    }
}
