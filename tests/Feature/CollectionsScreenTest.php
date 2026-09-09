<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * شاشة التحصيلات — المصدر والتحصيلات المباشرة والتصدير (٩/٩/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * نفس الفيو لشاشتين: «تحصيلات الميدان» بفلتر المصدر (زيارة / مكتبي
 * باسم مندوب / مباشر)، و«التحصيلات المباشرة» مقفولة على المباشر
 * ومعاها عمود الضرايب المخصومة تحت الحساب (قيد `taxded` المطابق
 * بالعميل والتاريخ والمرجع). التصدير من نفس الكويري المفلترة.
 */
class CollectionsScreenTest extends TestCase
{
    use RefreshDatabase;

    private function world(): array
    {
        $admin = $this->makeAdmin();
        $rep = $this->makeRep();
        $client = $this->makeClient(['code' => 'CL-501', 'name' => 'عميل التحويلات']);
        $today = today()->toDateString();

        $tx = fn (array $a) => Transaction::create(array_merge([
            'client_id' => $client->id, 'date' => $today, 'debit' => 0, 'kind' => 'collection',
        ], $a));

        // ميدان: مصدره زيارة
        $tx(['memo' => 'تحصيل زيارة', 'credit' => 100, 'method' => 'cash', 'source_type' => Visit::class, 'source_id' => 999]);
        // مكتبي باسم مندوب: مصدره المندوب
        $tx(['memo' => 'مستند يدوي', 'credit' => 200, 'method' => 'transfer', 'reference' => 'REP-REF-1', 'source_type' => User::class, 'source_id' => $rep->id]);
        // مباشر من العميل: بلا مصدر + ضريبة مخصومة منفصلة بنفس المرجع
        $tx(['memo' => 'تحويل مباشر', 'credit' => 9700, 'method' => 'transfer', 'reference' => 'TRX-DIRECT-1']);
        $tx(['memo' => 'ضرايب مخصومة', 'credit' => 300, 'kind' => 'taxded', 'reference' => 'TRX-DIRECT-1']);
        // أوتوماتيك (مقابل فاتورة كاش) — بلا طريقة، مايظهرش أبداً
        $tx(['memo' => 'مقابل فاتورة كاش', 'credit' => 50]);

        return [$admin, $rep, $client];
    }

    public function test_the_direct_screen_shows_only_direct_collections_with_their_withheld_tax(): void
    {
        [$admin] = $this->world();

        $this->actingAs($admin)->get(route('erp.collections.direct'))
            ->assertOk()
            ->assertSee(__('nav.collections_direct'))
            ->assertSee('TRX-DIRECT-1')
            ->assertSee('9,700.00')
            ->assertSee('300.00')
            ->assertDontSee('REP-REF-1')
            ->assertDontSee('تحصيل زيارة')
            ->assertDontSee('مقابل فاتورة كاش');
    }

    public function test_the_field_screen_filters_by_source(): void
    {
        [$admin] = $this->world();

        $this->actingAs($admin)->get(route('erp.collections', ['source' => 'rep']))
            ->assertOk()
            ->assertSee('REP-REF-1')
            ->assertDontSee('TRX-DIRECT-1')
            ->assertDontSee('تحصيل زيارة');

        $this->actingAs($admin)->get(route('erp.collections', ['source' => 'field']))
            ->assertOk()
            ->assertSee('تحصيل زيارة')
            ->assertDontSee('REP-REF-1');

        // بلا فلتر: كل المصادر التلاتة، والأوتوماتيك لا
        $this->actingAs($admin)->get(route('erp.collections'))
            ->assertOk()
            ->assertSee('REP-REF-1')
            ->assertSee('TRX-DIRECT-1')
            ->assertSee('تحصيل زيارة')
            ->assertDontSee('مقابل فاتورة كاش')
            ->assertSee(route('erp.collections.direct'), false);
    }

    public function test_the_direct_export_is_a_csv_of_the_same_filter_with_the_tax_column(): void
    {
        [$admin] = $this->world();

        $res = $this->actingAs($admin)->get(route('erp.collections.direct', ['export' => 1]));
        $res->assertOk();
        $this->assertStringContainsString('text/csv', (string) $res->headers->get('content-type'));
        $this->assertStringContainsString('direct-collections-', (string) $res->headers->get('content-disposition'));

        $body = $res->streamedContent();
        $this->assertStringContainsString('TRX-DIRECT-1', $body);
        $this->assertStringContainsString('300.00', $body);
        $this->assertStringContainsString('9700.00', $body);
        $this->assertStringContainsString(__('ops.tax_withheld_col'), $body);
        $this->assertStringNotContainsString('REP-REF-1', $body);
    }

    public function test_the_field_export_has_no_tax_column_and_follows_the_filter(): void
    {
        [$admin] = $this->world();

        $body = $this->actingAs($admin)
            ->get(route('erp.collections', ['export' => 1, 'source' => 'field']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('تحصيل زيارة', $body);
        $this->assertStringNotContainsString('TRX-DIRECT-1', $body);
        $this->assertStringNotContainsString(__('ops.tax_withheld_col'), $body);
    }
}
