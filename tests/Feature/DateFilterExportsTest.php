<?php

namespace Tests\Feature;

use App\Models\AccountAudit;
use App\Models\ActivityLog;
use App\Models\Contract;
use App\Models\Gl\GlAccount;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * فلتر «من — إلى» + التصدير السيرفري — الخط ٥ (١٧ أكتوبر ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * لكل شاشة: صف جوه الفترة وصف بره على **العمود التجاري** اللي
 * الكنترولر بيفلتر عليه، والشاشة تعرض الجوه بس، و`?export=1` يطلّع
 * نفس الصفوف (من نفس الكويري المفلترة)، و`?from=garbage` مايرميش 500.
 */
class DateFilterExportsTest extends TestCase
{
    use RefreshDatabase;

    private const FROM = '2026-03-01';
    private const TO = '2026-03-31';
    private const IN = '2026-03-10 10:00:00';
    private const OUT = '2026-01-05 10:00:00';

    private function stamp(Model $m, string $column, string $value): Model
    {
        $m->forceFill([$column => Carbon::parse($value)])->saveQuietly();

        return $m->fresh();
    }

    private function range(array $extra = []): array
    {
        return ['from' => self::FROM, 'to' => self::TO] + $extra;
    }

    /** الشاشة بالفترة + التصدير بنفس الفترة + نص عبيط */
    private function assertScreenAndExport(User $user, string $route, string $in, string $out, array $extra = []): void
    {
        $this->actingAs($user)->get(route($route, $this->range($extra)))
            ->assertOk()->assertSee($in)->assertDontSee($out);

        $res = $this->actingAs($user)->get(route($route, $this->range($extra + ['export' => 1])));
        $res->assertOk();
        $csv = $res->streamedContent();
        $this->assertStringContainsString($in, $csv);
        $this->assertStringNotContainsString($out, $csv);

        $this->actingAs($user)->get(route($route, ['from' => 'garbage'] + $extra))->assertOk();
        $this->actingAs($user)->get(route($route, ['from' => 'garbage', 'export' => 1] + $extra))->assertOk();
    }

    // ═══════════════════ ١. عروض الأسعار — يوم الإصدار ═══════════════════

    public function test_quotations_filter_and_export_on_created_at(): void
    {
        $admin = $this->makeAdmin();

        $mk = fn (string $name, string $at) => $this->stamp(Quotation::create([
            'number' => 'QT-'.strtoupper(uniqid()), 'client_name' => $name, 'created_by' => $admin->id,
            'valid_until' => '2026-12-31', 'discount_pct' => 0, 'extra_pct' => 0, 'tax_pct' => 0,
            'tax_inclusive' => false, 'subtotal' => 100, 'discount' => 0, 'net' => 100, 'tax' => 0, 'grand' => 100,
        ]), 'created_at', $at);

        $mk('QTIN-عرض-داخل', self::IN);
        $mk('QTOUT-عرض-خارج', self::OUT);

        $this->assertScreenAndExport($admin, 'erp.reports.quotations', 'QTIN-عرض-داخل', 'QTOUT-عرض-خارج');
    }

    // ═══════════════════ ٢. مراجعة الحسابات — تصدير ═══════════════════

    public function test_audit_clients_export_follows_reviewed_at(): void
    {
        $admin = $this->makeAdmin();

        $mk = function (string $name, string $at) use ($admin) {
            $c = $this->makeClient(['name' => $name, 'name_en' => $name]);
            AccountAudit::create([
                'entity_type' => 'client', 'entity_id' => $c->id, 'has_account' => true,
                'reviewed_by' => $admin->id, 'reviewed_at' => Carbon::parse($at),
            ]);
        };

        $mk('AUDXIN-عميل', self::IN);
        $mk('AUDXOUT-عميل', self::OUT);

        $this->assertScreenAndExport($admin, 'erp.audit.clients', 'AUDXIN-عميل', 'AUDXOUT-عميل');
    }

    public function test_audit_chains_export_follows_reviewed_at(): void
    {
        $admin = $this->makeAdmin();
        $channel = $this->makeChannel();

        $mk = function (string $name, string $at) use ($admin, $channel) {
            $g = \App\Models\ClientGroup::create([
                'code' => 'G-'.strtoupper(uniqid()), 'name' => $name, 'name_en' => $name,
                'channel_id' => $channel->id, 'active' => true,
            ]);
            AccountAudit::create([
                'entity_type' => 'group', 'entity_id' => $g->id, 'has_account' => true,
                'reviewed_by' => $admin->id, 'reviewed_at' => Carbon::parse($at),
            ]);
        };

        $mk('CHXIN-سلسلة', self::IN);
        $mk('CHXOUT-سلسلة', self::OUT);

        $this->assertScreenAndExport($admin, 'erp.audit.chains', 'CHXIN-سلسلة', 'CHXOUT-سلسلة');
    }

    // ═══════════════════ ٣. مركز النشاط — تاب «دلوقتي» بالفترة ═══════════════════

    public function test_activity_now_tab_takes_a_range_and_exports(): void
    {
        $admin = $this->makeAdmin();

        $mk = function (string $name, string $at) {
            $u = $this->makeRep(['name' => $name, 'name_en' => $name]);
            $this->stamp(ActivityLog::create([
                'user_id' => $u->id, 'user_name' => $name, 'role' => $u->role, 'event' => 'viewed',
                'title' => 'erp.dashboard', 'url' => 'dashboard', 'method' => 'GET',
            ]), 'created_at', $at);
        };

        $mk('ACTIN-يوزر', self::IN);
        $mk('ACTOUT-يوزر', self::OUT);

        $this->assertScreenAndExport($admin, 'erp.activity', 'ACTIN-يوزر', 'ACTOUT-يوزر', ['tab' => 'now']);

        // من غير فترة = النهارده بس (السلوك القديم) — الاتنين مش ظاهرين
        $this->actingAs($admin)->get(route('erp.activity', ['tab' => 'now']))
            ->assertOk()->assertDontSee('ACTIN-يوزر')->assertDontSee('ACTOUT-يوزر');
    }

    public function test_activity_log_tab_garbage_date_is_not_500(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->get(route('erp.activity', ['tab' => 'log', 'from' => 'garbage', 'to' => 'x']))
            ->assertOk();
    }

    // ═══════════════════ ٤. الزيارات المفتوحة — يوم فتح الزيارة ═══════════════════

    public function test_open_visits_filter_and_export_on_checked_in_at(): void
    {
        $admin = $this->makeAdmin();
        $rep = $this->makeRep();

        $mk = function (string $name, string $at) use ($rep) {
            $c = $this->makeClient(['name' => $name, 'name_en' => $name]);
            Visit::create(['user_id' => $rep->id, 'client_id' => $c->id, 'checked_in_at' => Carbon::parse($at)]);
        };

        $mk('OVIN-عميل', self::IN);
        $mk('OVOUT-عميل', self::OUT);

        $this->assertScreenAndExport($admin, 'ops.open_visits', 'OVIN-عميل', 'OVOUT-عميل');

        // من غير فلتر — كل المفتوح أياً كان يومه
        $this->actingAs($admin)->get(route('ops.open_visits'))
            ->assertOk()->assertSee('OVIN-عميل')->assertSee('OVOUT-عميل');
    }

    // ═══════════════════ ٥. تسليم أمر التوريد — الأوامر اللي اتبعتت ═══════════════════

    public function test_po_handout_sent_table_filters_and_exports_on_created_at(): void
    {
        $admin = $this->makeAdmin();

        // ⚠️ بنقارن برقم الأمر مش اسم العميل — أسماء الفروع كلها في JSON الفورم فوق
        $mk = function (string $name, string $at) use ($admin) {
            $c = $this->makeClient(['name' => $name, 'name_en' => $name]);
            $po = PurchaseOrder::create([
                'number' => PurchaseOrder::nextNumber(), 'client_id' => $c->id, 'price_mode' => 'new',
                'approval_status' => 'pending', 'created_by' => $admin->id, 'status' => 'pending', 'total' => 0,
            ]);

            return $this->stamp($po, 'created_at', $at)->number;
        };

        $in = $mk('PHIN-فرع', self::IN);
        $out = $mk('PHOUT-فرع', self::OUT);

        $this->actingAs($admin)->get(route('ops.po.handout', $this->range()))
            ->assertOk()->assertSee('>'.$in.'<', false)->assertDontSee('>'.$out.'<', false);

        $csv = $this->actingAs($admin)->get(route('ops.po.handout', $this->range(['export' => 1])))
            ->assertOk()->streamedContent();
        $this->assertStringContainsString($in.',', $csv);
        $this->assertStringNotContainsString($out.',', $csv);

        $this->actingAs($admin)->get(route('ops.po.handout', ['from' => 'garbage']))->assertOk();
        $this->actingAs($admin)->get(route('ops.po.handout', ['from' => 'garbage', 'export' => 1]))->assertOk();

        // وضع التعديل بيرندر نفس الفيو من غير الجدول — مايقعش
        $po = PurchaseOrder::where('number', $in)->first();
        $this->actingAs($admin)->get(route('ops.po.edit', $po))
            ->assertOk()->assertDontSee(__('ops.ph_sent_title'));
    }

    // ═══════════════════ ٦. العقود — بداية العقد أو نهايته ═══════════════════

    public function test_contracts_filter_and_export_on_starts_at_or_ends_at(): void
    {
        $admin = $this->makeAdmin();

        // ⚠️ بنقارن برقم العقد — العقد المنتهي بيخلّي عميله يظهر في «من غير عقد» تحت
        $mk = function (string $number, string $starts, string $ends) {
            $c = $this->makeClient();
            Contract::create([
                'client_id' => $c->id, 'number' => $number, 'discount' => 0.05,
                'starts_at' => $starts, 'ends_at' => $ends, 'active' => true, 'signed_ok' => true,
            ]);
        };

        $mk('CTNIN-501', '2026-03-05', '2027-03-04');
        $mk('CTNOUT-502', '2025-06-01', '2026-03-20');

        $this->assertScreenAndExport($admin, 'erp.contracts', 'CTNIN-501', 'CTNOUT-502');

        // على النهاية: العقد اللي بيخلص في مارس هو اللي يظهر
        $this->assertScreenAndExport($admin, 'erp.contracts', 'CTNOUT-502', 'CTNIN-501', ['basis' => 'ends']);

        // من غير فلتر — الشاشة زي ما هي
        $this->actingAs($admin)->get(route('erp.contracts'))
            ->assertOk()->assertSee('CTNIN-501')->assertSee('CTNOUT-502');
    }

    // ═══════════════════ ٧. شجرة الحسابات — تصدير سيرفري ═══════════════════

    public function test_gl_accounts_export_has_the_tree(): void
    {
        $admin = $this->makeAdmin();

        $root = GlAccount::create([
            'code' => '9', 'name' => 'GLROOT-جذر', 'type' => 'asset', 'normal_side' => 'debit',
            'is_system' => false, 'is_postable' => false, 'active' => true,
        ]);
        GlAccount::create([
            'code' => '9101', 'name' => 'GLLEAF-ورقة', 'parent_id' => $root->id, 'type' => 'asset',
            'normal_side' => 'debit', 'is_system' => false, 'is_postable' => true, 'active' => true,
        ]);

        $this->actingAs($admin)->get(route('gl.accounts', $this->range()))
            ->assertOk()->assertSee('export=1', false);

        $res = $this->actingAs($admin)->get(route('gl.accounts', $this->range(['export' => 1])));
        $res->assertOk();
        $csv = $res->streamedContent();
        $this->assertStringContainsString('9101', $csv);
        $this->assertStringContainsString('GLLEAF-ورقة', $csv);
        $this->assertStringContainsString(self::FROM, $csv);

        $this->actingAs($admin)->get(route('gl.accounts', ['from' => 'garbage', 'export' => 1]))->assertOk();
    }
}
