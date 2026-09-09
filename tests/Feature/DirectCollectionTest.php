<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * التحصيل المباشر من العميل — بلا مندوب، بإثبات وضريبة مخصومة (٩/٩/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * العميل حوّل على البنك أو بعت شيك من غير أي مندوب. المستند اليدوي بقى
 * فيه خيار «بدون مندوب» بيسجّل قيد `collection` بتاريخ التحصيل وصورة
 * الإثبات، ولو العميل خصم ضرايب تحت الحساب بيتسجّل لها قيد `taxded`
 * منفصل — فالرصيد ينقص بالاتنين والمحاسب يلاقي المخصوم لوحده.
 *
 * نفس القلب (`ManualCollection::record`) بيخدم كارت العميل كمان.
 */
class DirectCollectionTest extends TestCase
{
    use RefreshDatabase;

    private function debtor(float $sale = 10000): array
    {
        $admin = $this->makeAdmin();
        $client = $this->makeClient();
        Transaction::create(['client_id' => $client->id, 'date' => today()->subDays(10), 'memo' => 'فاتورة', 'debit' => $sale, 'credit' => 0, 'kind' => 'sale']);
        $client->recalculate();

        return [$admin, $client->fresh()];
    }

    public function test_a_direct_transfer_posts_the_collection_and_the_withheld_tax_and_settles_the_balance(): void
    {
        Storage::fake('public');
        [$admin, $client] = $this->debtor(10000);

        $this->actingAs($admin)
            ->post(route('ops.manual.collection'), [
                'direct' => 1,
                'client_id' => $client->id,
                'doc_date' => today()->subDay()->toDateString(),
                'amount' => 9700,
                'method' => 'transfer',
                'reference' => 'TRX-100ACH',
                'tax_withheld' => 300,
                'proof' => UploadedFile::fake()->image('transfer.jpg'),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $client->refresh();
        $collection = Transaction::where('client_id', $client->id)->where('kind', 'collection')->first();
        $tax = Transaction::where('client_id', $client->id)->where('kind', 'taxded')->first();

        $this->assertNotNull($collection);
        $this->assertSame(9700.0, (float) $collection->credit);
        $this->assertSame('transfer', $collection->method);
        $this->assertSame('TRX-100ACH', $collection->reference);
        $this->assertNull($collection->source_type, 'المباشر بلا مندوب = إدخال مكتبي بلا مصدر');
        $this->assertNotNull($collection->proof_path);
        Storage::disk('public')->assertExists($collection->proof_path);
        $this->assertSame(today()->subDay()->toDateString(), $collection->date->toDateString());

        $this->assertNotNull($tax, 'الضريبة المخصومة قيد منفصل');
        $this->assertSame(300.0, (float) $tax->credit);
        $this->assertSame($collection->proof_path, $tax->proof_path);

        // 10000 − 9700 − 300 = 0: العميل خلّص المبلغ كله (جزء لنا وجزء للمصلحة باسمنا)
        $this->assertSame(0.0, (float) $client->balance);
        $this->assertSame(9700.0, (float) $client->collections, 'المحصَّل فعلاً هو اللي وصل — من غير الضريبة');
    }

    public function test_a_direct_non_cash_collection_without_proof_is_refused(): void
    {
        [$admin, $client] = $this->debtor();

        $this->actingAs($admin)
            ->post(route('ops.manual.collection'), [
                'direct' => 1,
                'client_id' => $client->id,
                'doc_date' => today()->toDateString(),
                'amount' => 500,
                'method' => 'cheque',
                'reference' => 'CHQ-1',
                'cheque_bank' => 'CIB',
                'cheque_due' => today()->addDays(10)->toDateString(),
            ])
            ->assertSessionHasErrors('proof');

        $this->assertSame(0, Transaction::where('client_id', $client->id)->where('kind', 'collection')->count());
    }

    public function test_a_direct_cash_collection_needs_no_proof_and_no_rep(): void
    {
        [$admin, $client] = $this->debtor(1000);

        $this->actingAs($admin)
            ->post(route('ops.manual.collection'), [
                'direct' => 1,
                'client_id' => $client->id,
                'doc_date' => today()->toDateString(),
                'amount' => 1000,
                'method' => 'cash',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(0.0, (float) $client->fresh()->balance);
    }

    /** المسار القديم باسم المندوب لسه بيطلب المندوب ولسه بيشتغل */
    public function test_a_rep_collection_still_requires_the_rep(): void
    {
        [$admin, $client] = $this->debtor(1000);
        $rep = $this->makeRep();

        $this->actingAs($admin)
            ->post(route('ops.manual.collection'), [
                'client_id' => $client->id,
                'doc_date' => today()->toDateString(),
                'amount' => 400,
                'method' => 'cash',
            ])
            ->assertSessionHasErrors('user_id');

        $this->actingAs($admin)
            ->post(route('ops.manual.collection'), [
                'user_id' => $rep->id,
                'client_id' => $client->id,
                'doc_date' => today()->toDateString(),
                'amount' => 400,
                'method' => 'cash',
            ])
            ->assertSessionHasNoErrors();

        $tx = Transaction::where('client_id', $client->id)->where('kind', 'collection')->first();
        $this->assertSame(User::class, $tx->source_type);
        $this->assertSame($rep->id, (int) $tx->source_id);
    }

    /** كارت العميل بيمشي على نفس القلب: إثبات + ضريبة مخصومة */
    public function test_the_client_card_collection_accepts_proof_and_withheld_tax(): void
    {
        Storage::fake('public');
        [$admin, $client] = $this->debtor(5000);

        $this->actingAs($admin)
            ->post(route('erp.clients.collect', $client), [
                'amount' => 4900,
                'date' => today()->toDateString(),
                'method' => 'transfer',
                'reference' => 'BANK-77',
                'tax_withheld' => 100,
                'proof' => UploadedFile::fake()->image('slip.png'),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $client->refresh();
        $this->assertSame(0.0, (float) $client->balance);
        $this->assertSame(1, Transaction::where('client_id', $client->id)->where('kind', 'taxded')->count());
        $this->assertNotNull(Transaction::where('client_id', $client->id)->where('kind', 'collection')->value('proof_path'));
    }

    /** الشاشة: خيار «بدون مندوب» وخانات الإثبات والضريبة موجودين */
    public function test_the_manual_document_offers_the_direct_option(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->get(route('ops.manual'))
            ->assertOk()
            ->assertSee('value="direct"', false)
            ->assertSee('name="proof"', false)
            ->assertSee('name="tax_withheld"', false)
            ->assertSee('enctype="multipart/form-data"', false);
    }
}
