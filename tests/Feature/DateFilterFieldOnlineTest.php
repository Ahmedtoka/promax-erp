<?php

namespace Tests\Feature;

use App\Models\AccountAudit;
use App\Models\AttendanceDay;
use App\Models\ClientGroup;
use App\Models\ClientRequest;
use App\Models\Lead;
use App\Models\OnlineOrder;
use App\Models\OnlinePickup;
use App\Models\ReplenishmentRequest;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * فلتر «من — إلى» على شاشات الميدان والأونلاين (٩ سبتمبر ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * لكل شاشة: صف جوه الفترة وصف بره — الجوه يظهر والبره يختفي، و
 * `?from=garbage` مايرميش 500 (حارس `DateRange::day`).
 *
 * ⚠️ الفلتر على **عمود الشغل** مش `created_at` على العمياني — كل
 * تيست بيختم العمود اللي الكنترولر بيفلتر عليه فعلاً، فلو حد غيّر
 * العمود من غير ما يغيّر الشاشة التيست بيقع.
 */
class DateFilterFieldOnlineTest extends TestCase
{
    use RefreshDatabase;

    private const FROM = '2026-03-01';
    private const TO = '2026-03-31';
    private const IN = '2026-03-10 10:00:00';
    private const OUT = '2026-01-05 10:00:00';

    private function admin(): User
    {
        return $this->makeAdmin();
    }

    /** ختم عمود تاريخ من غير ما `$fillable` يمنعه (`created_at` مش fillable) */
    private function stamp(Model $m, string $column, string $value): Model
    {
        $m->forceFill([$column => Carbon::parse($value)])->saveQuietly();

        return $m->fresh();
    }

    private function range(array $extra = []): array
    {
        return ['from' => self::FROM, 'to' => self::TO] + $extra;
    }

    private function assertFiltered(User $admin, string $route, string $inside, string $outside, array $extra = []): void
    {
        $this->actingAs($admin)->get(route($route, $this->range($extra)))
            ->assertOk()->assertSee($inside)->assertDontSee($outside);

        // بلا فلتر — الاتنين ظاهرين (السلوك القديم زي ما هو)
        $this->actingAs($admin)->get(route($route, $extra))
            ->assertOk()->assertSee($inside)->assertSee($outside);

        $this->actingAs($admin)->get(route($route, ['from' => 'garbage'] + $extra))->assertOk();
    }

    // ═══════════════════ ١. طلبات العملاء الجدد ═══════════════════

    public function test_ops_requests_filters_on_submission_date(): void
    {
        $admin = $this->admin();
        $rep = $this->makeRep();
        $zone = $this->makeZone();

        $mk = fn (string $name, string $at) => $this->stamp(ClientRequest::create([
            'number' => 'REQ-'.strtoupper(uniqid()), 'name' => $name, 'status' => 'pending',
            'created_by' => $rep->id, 'zone_id' => $zone->id,
        ]), 'created_at', $at);

        $mk('REQIN-داخل-الفترة', self::IN);
        $mk('REQOUT-خارج-الفترة', self::OUT);

        $this->assertFiltered($admin, 'ops.requests', 'REQIN-داخل-الفترة', 'REQOUT-خارج-الفترة');
    }

    // ═══════════════════ ٢. طلبات الريفيل ═══════════════════

    public function test_ops_replenishments_filters_on_request_date(): void
    {
        $admin = $this->admin();
        $promoter = $this->makeRep(['role' => 'promoter']);
        $client = $this->makeClient();

        $mk = fn (string $number, string $at) => $this->stamp(ReplenishmentRequest::create([
            'number' => $number, 'client_id' => $client->id, 'requested_by' => $promoter->id, 'status' => 'pending',
        ]), 'created_at', $at);

        $mk('RPL-INSIDE-77', self::IN);
        $mk('RPL-OUTSIDE-88', self::OUT);

        $this->assertFiltered($admin, 'ops.replenishments', 'RPL-INSIDE-77', 'RPL-OUTSIDE-88');
    }

    // ═══════════════════ ٣. المهام — على الموعد ═══════════════════

    public function test_erp_tasks_filters_on_deadline(): void
    {
        $admin = $this->admin();
        $rep = $this->makeRep();

        $mk = fn (string $title, string $deadline) => Task::create([
            'title' => $title, 'assigned_to' => $rep->id, 'created_by' => $admin->id,
            'priority' => 'normal', 'status' => 'open', 'deadline' => Carbon::parse($deadline),
        ]);

        $mk('TASK-INSIDE-مهمة', self::IN);
        $mk('TASK-OUTSIDE-مهمة', self::OUT);

        $this->assertFiltered($admin, 'erp.tasks', 'TASK-INSIDE-مهمة', 'TASK-OUTSIDE-مهمة');
    }

    // ═══════════════════ ٤. الليدز — تاريخ دخول المحفظة ═══════════════════

    public function test_erp_leads_filters_on_intake_date(): void
    {
        $admin = $this->admin();
        $zone = $this->makeZone();
        $channel = $this->makeChannel();

        $mk = fn (string $name, string $at) => $this->stamp(Lead::create([
            'number' => Lead::nextNumber(), 'name' => $name, 'name_en' => $name, 'phone' => '0100'.random_int(1000000, 9999999),
            'status' => 'new', 'zone_id' => $zone->id, 'channel_id' => $channel->id, 'created_by' => $admin->id,
        ]), 'created_at', $at);

        $mk('LEADIN-ليد-داخل', self::IN);
        $mk('LEADOUT-ليد-خارج', self::OUT);

        $this->assertFiltered($admin, 'erp.leads', 'LEADIN-ليد-داخل', 'LEADOUT-ليد-خارج');
    }

    // ═══════════════════ ٥. مراجعة الحضور — على اليوم ═══════════════════

    public function test_attendance_review_filters_on_day(): void
    {
        $admin = $this->admin();

        $mk = function (string $name, string $day) {
            $u = $this->makeRep(['name' => $name, 'name_en' => $name]);

            return AttendanceDay::create([
                'user_id' => $u->id, 'date' => substr($day, 0, 10), 'status' => AttendanceDay::STATUS_AUTO,
                'worked_minutes' => 480, 'sessions' => 1,
            ]);
        };

        $mk('ATT-INSIDE-موظف', self::IN);
        $mk('ATT-OUTSIDE-موظف', self::OUT);

        $this->assertFiltered($admin, 'erp.attendance.review', 'ATT-INSIDE-موظف', 'ATT-OUTSIDE-موظف');
    }

    // ═══════════════════ ٦. مراجعة الحسابات — تاريخ المراجعة ═══════════════════

    public function test_audit_clients_filters_on_reviewed_at(): void
    {
        $admin = $this->admin();

        $mk = function (string $name, string $at) use ($admin) {
            $c = $this->makeClient(['name' => $name, 'name_en' => $name]);
            AccountAudit::create([
                'entity_type' => 'client', 'entity_id' => $c->id, 'has_account' => true,
                'reviewed_by' => $admin->id, 'reviewed_at' => Carbon::parse($at),
            ]);
        };

        $mk('AUDIN-عميل-داخل', self::IN);
        $mk('AUDOUT-عميل-خارج', self::OUT);

        $this->assertFiltered($admin, 'erp.audit.clients', 'AUDIN-عميل-داخل', 'AUDOUT-عميل-خارج');
    }

    public function test_audit_chains_filters_on_reviewed_at(): void
    {
        $admin = $this->admin();
        $channel = $this->makeChannel();

        $mk = function (string $name, string $at) use ($admin, $channel) {
            $g = ClientGroup::create([
                'code' => 'G-'.strtoupper(uniqid()), 'name' => $name, 'name_en' => $name,
                'channel_id' => $channel->id, 'active' => true,
            ]);
            AccountAudit::create([
                'entity_type' => 'group', 'entity_id' => $g->id, 'has_account' => true,
                'reviewed_by' => $admin->id, 'reviewed_at' => Carbon::parse($at),
            ]);
        };

        $mk('CHAININ-سلسلة-داخل', self::IN);
        $mk('CHAINOUT-سلسلة-خارج', self::OUT);

        $this->assertFiltered($admin, 'erp.audit.chains', 'CHAININ-سلسلة-داخل', 'CHAINOUT-سلسلة-خارج');
    }

    // ═══════════════════ ٧. الأونلاين ═══════════════════

    private function onlineOrder(array $attrs): OnlineOrder
    {
        static $n = 0;
        $n++;

        return OnlineOrder::create(array_merge([
            'shopify_id' => 900000 + $n, 'number' => 'ORD-'.$n, 'customer_name' => 'عميل أونلاين',
            'items_count' => 1, 'subtotal' => 100, 'shipping' => 0, 'total' => 100, 'status' => 'new',
        ], $attrs));
    }

    public function test_online_orders_filters_on_ordered_at(): void
    {
        $admin = $this->admin();

        $this->onlineOrder(['number' => 'ORDIN-5151', 'ordered_at' => Carbon::parse(self::IN)]);
        $this->onlineOrder(['number' => 'ORDOUT-6262', 'ordered_at' => Carbon::parse(self::OUT)]);

        $this->assertFiltered($admin, 'online.orders', 'ORDIN-5151', 'ORDOUT-6262');
    }

    public function test_online_collections_filters_on_shipped_at(): void
    {
        $admin = $this->admin();

        $this->onlineOrder(['number' => 'SHPIN-5151', 'status' => 'shipped', 'shipped_at' => Carbon::parse(self::IN)]);
        $this->onlineOrder(['number' => 'SHPOUT-6262', 'status' => 'shipped', 'shipped_at' => Carbon::parse(self::OUT)]);

        $this->assertFiltered($admin, 'online.collections', 'SHPIN-5151', 'SHPOUT-6262');
    }

    public function test_online_pickups_filters_on_pickup_date(): void
    {
        $admin = $this->admin();

        OnlinePickup::create(['number' => 'PUIN-5151', 'date' => substr(self::IN, 0, 10), 'created_by' => $admin->id]);
        OnlinePickup::create(['number' => 'PUOUT-6262', 'date' => substr(self::OUT, 0, 10), 'created_by' => $admin->id]);

        $this->assertFiltered($admin, 'online.pickups', 'PUIN-5151', 'PUOUT-6262');
    }

    public function test_online_accounts_filters_open_pickups_on_date(): void
    {
        $admin = $this->admin();

        // بيك اب «مفتوح» = عليه أوردر مشحون لسه ماتحصلش — غير كده
        // `isSettled()` بتشيله من الجدول قبل أي فلتر
        $mk = function (string $number, string $day) use ($admin) {
            $p = OnlinePickup::create(['number' => $number, 'date' => substr($day, 0, 10), 'created_by' => $admin->id]);
            $this->onlineOrder(['status' => 'shipped', 'pickup_id' => $p->id, 'shipped_at' => Carbon::parse($day)]);
        };

        $mk('ACCIN-5151', self::IN);
        $mk('ACCOUT-6262', self::OUT);

        $this->assertFiltered($admin, 'online.accounts', 'ACCIN-5151', 'ACCOUT-6262');
    }
}
