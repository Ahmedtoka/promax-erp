<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Custody;
use App\Models\Invoice;
use App\Models\PickOrder;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * السيدرز بتتشغّل — وبتسيب الدفاتر قافلة
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **الملف ده اتكتب بعد ٨ سبتمبر ٢٠٢٦:** ٣ من ٤ مشاكل اليوم كانت في
 * سيدرز (أعمدة اتشالت والسيدر لسه بيكتبها، و`grand_total` صفر) —
 * والسويت كلها بتعمل `migrate` بس فالـCI ماكانش هيمسك ولا واحدة.
 * `migrate:fresh --seed` هو اللي بيبني بيئة التطوير والديمو، ولو وقع
 * محدش يعرف غير وقت ما يحتاجه.
 *
 * التيست بيشغّل `DatabaseSeeder` كامل مرة (ومرة تانية عشان يتأكد إن
 * السيدرز idempotent زي ما الدوكترين بتقول)، وبيفحص الثوابت اللي
 * لازم تصح بعد أي سيد مهما تغيّرت الداتا نفسها.
 *
 * ⚠️ بطيء عن قصد (السيد بيرحّل مئات العملاء وآلاف الحركات) — تيست
 * واحد بس عشان الـmigrate والسيد يحصلوا مرة.
 */
class SeedersRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_full_seed_runs_twice_and_leaves_the_books_balanced(): void
    {
        $this->seed();

        $clients = Client::count();
        $products = Product::count();
        $users = User::count();

        $this->assertGreaterThan(0, $clients, 'السيد ما زرعش عملاء');
        $this->assertGreaterThan(0, $products, 'السيد ما زرعش منتجات');
        $this->assertGreaterThan(0, Transaction::count(), 'السيد ما زرعش كشوف حساب');

        // ═══ 1. الأرصدة المجمّعة = كشف الحساب (عقيدة transactions مصدر الحقيقة) ═══
        $ledger = Transaction::query()
            ->selectRaw('client_id, SUM(debit) - SUM(credit) AS bal')
            ->groupBy('client_id')->pluck('bal', 'client_id');

        $off = [];

        foreach (Client::query()->get(['id', 'code', 'balance']) as $c) {
            $expected = (float) ($ledger[$c->id] ?? 0);

            if (abs((float) $c->balance - $expected) > 0.01) {
                $off[] = "{$c->code}: balance={$c->balance} ledger={$expected}";
            }
        }

        $this->assertSame([], $off, "أرصدة مش مطابقة لكشوفها بعد السيد — `recalculate()` اتنسي:\n  ".implode("\n  ", $off));

        // ═══ 2. كل مستخدم برول معروف، وكل ميداني أكتيف عنده كود ═══
        $this->assertSame([], User::whereNotIn('role', array_keys(User::ROLES))->pluck('email')->all(),
            'مستخدمين برولز مش في User::ROLES');
        $this->assertSame([], User::whereIn('role', User::FIELD_ROLES)->whereNull('code')->pluck('email')->all(),
            'ميدانيين من غير كود موظف — الأبلكيشن بتدخل بالكود');

        // ═══ 3. عقيدة الأرقام التلاتة على الفواتير: Σ البنود = total، وgrand_total مش صفر ═══
        $broken = DB::table('invoices as i')
            ->leftJoin('invoice_items as it', 'it.invoice_id', '=', 'i.id')
            ->groupBy('i.id', 'i.number', 'i.total', 'i.grand_total')
            ->havingRaw('ABS(COALESCE(SUM(it.total), 0) - i.total) > 0.01 OR i.grand_total < i.total')
            ->pluck('i.number')->all();

        $this->assertSame([], $broken,
            'فواتير مجموع بنودها ≠ total أو grand_total أقل من total: '.implode(', ', $broken));

        // ═══ 4. مفيش عهدة مفتوحة بكمية سالبة ═══
        $negative = DB::table('custody_items')->where('assigned', '<', 0)->count();
        $this->assertSame(0, $negative, 'بنود عهدة بكمية سالبة');

        // ═══ 5. العربيات اتحمّلت بأوامر تجهيز من رف حقيقي (٨/٩) — مش من العدم ═══
        $handed = PickOrder::where('purpose', PickOrder::PURPOSE_VAN_LOAD)->where('status', 'handed')->count();
        $this->assertGreaterThanOrEqual(3, $handed, 'عهد العربيات التلاتة لازم تكون من أوامر تجهيز مسلَّمة');
        $this->assertSame(0, DB::table('custody_items')->whereNull('batch_id')->where('source', 'custody')->count(),
            'بنود عهدة من غير باتش — اتكتبت مباشرة من غير تجهيز');
        $this->assertGreaterThan(0, Invoice::whereDate('created_at', today())->count(), 'يوم الشغل ما زرعش فواتير');

        // ═══ 5. الإعادة مابتكسرش ومابتكرّرش (idempotent) ═══
        $this->seed();

        $this->assertSame($clients, Client::count(), 'إعادة السيد ضاعفت العملاء');
        $this->assertSame($products, Product::count(), 'إعادة السيد ضاعفت المنتجات');
        $this->assertSame($users, User::count(), 'إعادة السيد ضاعفت المستخدمين');
        $this->assertGreaterThan(0, Custody::count());
    }
}
