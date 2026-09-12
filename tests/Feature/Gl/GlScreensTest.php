<?php

namespace Tests\Feature\Gl;

use App\Models\Gl\GlAccount;
use App\Models\Gl\GlEntry;
use App\Models\Gl\GlPeriod;
use App\Models\Gl\GlPostingRule;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\GlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * شاشات دفتر الأستاذ — الشجرة واليومية والكشف والتقارير والإعدادات
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ الشاشات دي هي الواجهة الوحيدة اللي المحاسب بيشوف بيها الدفتر —
 * لو شاشة وقعت أو زرار رفض، الدفتر «مش موجود» بالنسبة له حتى لو
 * القيود كلها متظبطة في الداتابيز.
 */
class GlScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $acc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GlSeeder::class);
        Setting::write('gl_enabled', '1');
        Setting::flushCache();
        $this->acc = $this->makeAdmin(['role' => 'accountant', 'email' => 'acc.screens@test.local']);
    }

    public function test_every_gl_screen_renders_for_the_accountant_with_export(): void
    {
        $client = $this->makeClient();
        Transaction::create(['client_id' => $client->id, 'date' => today(), 'memo' => 'فاتورة تيست', 'debit' => 1140, 'credit' => 0, 'tax' => 140, 'kind' => 'sale']);

        foreach (['gl.accounts', 'gl.entries', 'gl.trial_balance', 'gl.income', 'gl.balance_sheet', 'gl.settings'] as $r) {
            $this->actingAs($this->acc)->get(route($r))->assertOk();
        }
        $this->actingAs($this->acc)->get(route('gl.entries'))->assertSee('فاتورة تيست')->assertSee('JV-1001');
        $res = $this->actingAs($this->acc)->get(route('gl.trial_balance', ['export' => 1]));
        $res->assertOk();
        $this->assertStringContainsString('text/csv', (string) $res->headers->get('content-type'));
        $this->actingAs($this->acc)->get(route('gl.accounts.show', GlAccount::findKey('receivables')))->assertOk()->assertSee('1,140.00');
    }

    public function test_the_accountant_can_add_a_free_account_and_the_admin_edits_a_system_name_only(): void
    {
        $this->actingAs($this->acc)->post(route('gl.accounts.store'), [
            'parent_id' => GlAccount::where('code', '5')->first()->id, 'code' => '5108', 'name' => 'اتصالات', 'name_en' => 'Telecom',
        ])->assertSessionHasNoErrors();
        $acc = GlAccount::where('code', '5108')->first();
        $this->assertSame('expense', $acc->type);
        $this->assertFalse($acc->is_system);

        $sys = GlAccount::findKey('bank');
        $this->actingAs($this->makeAdmin())->post(route('gl.accounts.update', $sys), ['name' => 'بنك CIB', 'name_en' => 'CIB', 'code' => '9999'])->assertSessionHasNoErrors();
        $sys->refresh();
        $this->assertSame('بنك CIB', $sys->name);
        $this->assertSame('1102', $sys->code, 'system code never changes');
    }

    /**
     * ⚠️ نوع الحساب بيتحدد من أول رقم في كوده — نقله لجذر تاني بيحوّل
     * كل قيوده القديمة من قائمة الدخل للميزانية (أو العكس) في لحظة
     * ومن غير أي قيد تصحيح. والأب اللي جوه شجرة الحساب نفسه بيعمل
     * حلقة بتعلّق `subtreeIds()`.
     */
    public function test_a_free_account_cannot_be_re_rooted_or_parented_into_its_own_subtree(): void
    {
        $free = $this->makeFreeAccount('5108', 'اتصالات');

        // نقل تحت مجموعة أصول
        $this->actingAs($this->acc)->post(route('gl.accounts.update', $free), [
            'name' => 'اتصالات', 'code' => '5108', 'parent_id' => GlAccount::where('code', '11')->first()->id,
        ])->assertSessionHasErrors('parent_id');

        // إعادة ترقيم لجذر تاني
        $this->actingAs($this->acc)->post(route('gl.accounts.update', $free), [
            'name' => 'اتصالات', 'code' => '1108',
        ])->assertSessionHasErrors('code');

        // أب فرعي (مش مجموعة)
        $this->actingAs($this->acc)->post(route('gl.accounts.update', $free), [
            'name' => 'اتصالات', 'code' => '5108', 'parent_id' => GlAccount::findKey('expense_rent')->id,
        ])->assertSessionHasErrors('parent_id');

        // أب جوه شجرة الحساب نفسه (الحساب نفسه هنا — أبسط حالة حلقة)
        $this->actingAs($this->acc)->post(route('gl.accounts.update', $free), [
            'name' => 'اتصالات', 'code' => '5108', 'parent_id' => $free->id,
        ])->assertSessionHasErrors('parent_id');

        $free->refresh();
        $this->assertSame('5108', $free->code);
        $this->assertSame('expense', $free->type);
        $this->assertSame(GlAccount::where('code', '5')->first()->id, $free->parent_id);

        // نقل صحيح: مجموعة مصروفات جديدة تحت نفس الجذر
        $group = GlAccount::create([
            'code' => '51', 'name' => 'مصروفات تشغيل', 'name_en' => 'Operating', 'parent_id' => GlAccount::where('code', '5')->first()->id,
            'type' => 'expense', 'normal_side' => 'debit', 'is_system' => false, 'is_postable' => false, 'active' => true,
        ]);
        $this->actingAs($this->acc)->post(route('gl.accounts.update', $free), [
            'name' => 'اتصالات', 'code' => '5108', 'parent_id' => $group->id, 'active' => 1,
        ])->assertSessionHasNoErrors();

        $free->refresh();
        $this->assertSame($group->id, $free->parent_id);
        $this->assertSame('expense', $free->type);
        $this->assertSame('debit', $free->normal_side);
    }

    /** جدول واحد من الصفحة بالـid بتاعه — عشان رسالة الفشل تفضل مقروءة */
    private function sliceTable(string $html, string $id): string
    {
        $start = strpos($html, 'id="'.$id.'"');
        $this->assertNotFalse($start, "الجدول [{$id}] مش موجود في الصفحة");
        $end = strpos($html, '</table>', $start);

        return substr($html, $start, ($end === false ? $start + 4000 : $end) - $start);
    }

    /** حساب حر تحت جذر المصروفات — نفس مسار الشاشة */
    private function makeFreeAccount(string $code, string $name): GlAccount
    {
        $this->actingAs($this->acc)->post(route('gl.accounts.store'), [
            'parent_id' => GlAccount::where('code', $code[0])->first()->id,
            'code' => $code, 'name' => $name, 'name_en' => $name,
        ])->assertSessionHasNoErrors();

        return GlAccount::where('code', $code)->firstOrFail();
    }

    public function test_a_manual_entry_from_the_screen_and_an_unbalanced_one_is_refused(): void
    {
        $lines = [
            ['account_id' => GlAccount::findKey('expense_rent')->id, 'debit' => 1000, 'credit' => 0],
            ['account_id' => GlAccount::findKey('cash_main')->id, 'debit' => 0, 'credit' => 1000],
        ];
        $this->actingAs($this->acc)->post(route('gl.entries.store'), ['date' => today()->toDateString(), 'memo' => 'إيجار', 'lines' => $lines])->assertSessionHasNoErrors();
        $this->assertSame(1, GlEntry::where('origin', 'manual')->count());

        $lines[1]['credit'] = 999;
        $this->actingAs($this->acc)->post(route('gl.entries.store'), ['date' => today()->toDateString(), 'memo' => 'x', 'lines' => $lines])->assertSessionHasErrors('lines');

        // سطر بمدين ودائن مع بعض بيتوازن مع نفسه ويعدّي من حارس الميزان —
        // لكنه قيد مالوش معنى محاسبي
        $both = [
            ['account_id' => GlAccount::findKey('expense_rent')->id, 'debit' => 50, 'credit' => 50],
            ['account_id' => GlAccount::findKey('cash_main')->id, 'debit' => 0, 'credit' => 0],
        ];
        $this->actingAs($this->acc)->post(route('gl.entries.store'), ['date' => today()->toDateString(), 'memo' => 'الاتنين', 'lines' => $both])
            ->assertSessionHasErrors('lines');

        // ومبلغ أكبر من سعة العمود مرفوض بالفاليديشن مش بالقص الصامت
        $big = [
            ['account_id' => GlAccount::findKey('expense_rent')->id, 'debit' => 100000000, 'credit' => 0],
            ['account_id' => GlAccount::findKey('cash_main')->id, 'debit' => 0, 'credit' => 100000000],
        ];
        $this->actingAs($this->acc)->post(route('gl.entries.store'), ['date' => today()->toDateString(), 'memo' => 'كبير', 'lines' => $big])
            ->assertSessionHasErrors('lines.0.debit');

        $this->assertSame(1, GlEntry::where('origin', 'manual')->count(), 'القيد السليم بس هو اللي اتسجّل');
    }

    /**
     * ⚠️ القيد اللي اترفض كان بيرجع بديالوج فاضي — عشر سطور تتكتب
     * تاني من الأول عشان رسالة واحدة. السطور لازم ترجع زي ما هي
     * والديالوج يفتح لوحده.
     */
    public function test_a_refused_manual_entry_comes_back_with_its_lines_and_reopens_the_dialog(): void
    {
        $rent = GlAccount::findKey('expense_rent');
        $fuel = GlAccount::findKey('expense_fuel');
        $cash = GlAccount::findKey('cash_main');

        $lines = [
            ['account_id' => $rent->id, 'debit' => 700, 'credit' => 0],
            ['account_id' => $fuel->id, 'debit' => 300, 'credit' => 0],
            ['account_id' => $cash->id, 'debit' => 0, 'credit' => 999],
        ];

        $this->actingAs($this->acc)->from(route('gl.entries'))->post(route('gl.entries.store'), [
            'date' => today()->toDateString(), 'memo' => 'مصروفات اليوم', 'lines' => $lines,
        ])->assertRedirect(route('gl.entries'))->assertSessionHasErrors('lines');

        $page = $this->actingAs($this->acc)->get(route('gl.entries'));
        $page->assertOk();

        // الحسابات التلاتة مختارة والمبالغ راجعة في خاناتها
        foreach ([$rent, $fuel, $cash] as $i => $account) {
            $page->assertSee('name="lines['.$i.'][account_id]"', false);
            $page->assertSee('<option value="'.$account->id.'" selected>', false);
        }
        $page->assertSee('name="lines[2][credit]" step="0.01" min="0" dir="ltr" value="999"', false);
        $page->assertSee('value="700"', false);
        $page->assertSee('value="300"', false);
        $page->assertSee('مصروفات اليوم');

        // والديالوج بيفتح لوحده على الرسالة
        $page->assertSee("openDlg('dlgEntry')", false);
    }

    public function test_a_manual_entry_on_a_control_account_is_refused(): void
    {
        // حساب العملاء بيتحرّك من مستنداته بس — الشاشة لازم ترجّع
        // الرسالة على خانة السطور مش 500
        $lines = [
            ['account_id' => GlAccount::findKey('receivables')->id, 'debit' => 50, 'credit' => 0],
            ['account_id' => GlAccount::findKey('cash_main')->id, 'debit' => 0, 'credit' => 50],
        ];
        $this->actingAs($this->acc)->post(route('gl.entries.store'), [
            'date' => today()->toDateString(), 'memo' => 'تحصيل يدوي', 'lines' => $lines,
        ])->assertSessionHasErrors('lines');
        $this->assertSame(0, GlEntry::where('origin', 'manual')->count());
    }

    public function test_the_override_button_changes_the_account_and_the_rebuild_preview_shows_a_rule_change(): void
    {
        $admin = $this->makeAdmin();
        $client = $this->makeClient();
        $cash = GlAccount::findKey('cash_main');
        $bank = GlAccount::findKey('bank');

        // تحصيل أول — سطر الخزنة بيتحوّل يدوي للبنك
        Transaction::create(['client_id' => $client->id, 'date' => today(), 'memo' => 'تحصيل ١', 'debit' => 0, 'credit' => 300, 'kind' => 'collection', 'method' => 'cash']);
        $line = GlEntry::first()->lines->firstWhere('account_id', $cash->id);

        $this->actingAs($this->acc)->post(route('gl.entries.override', $line), ['account_id' => $bank->id, 'note' => 'تحويل'])->assertSessionHasNoErrors();
        $this->assertSame($bank->id, $line->fresh()->account_id);

        // ⚠️ تحصيل تاني **من غير** تحويل يدوي — هو اللي القاعدة الجديدة
        // بتحرّكه فعلاً. من غيره فرق الأرصدة بيطلع فاضي (التحويل اليدوي
        // والقاعدة بيوّدوا نفس الحساب) والتيست بيعدّي على لا شيء.
        Transaction::create(['client_id' => $client->id, 'date' => today(), 'memo' => 'تحصيل ٢', 'debit' => 0, 'credit' => 600, 'kind' => 'collection', 'method' => 'cash']);
        $this->assertSame(600.0, $cash->balanceBetween(null, null));
        $this->assertSame(300.0, $bank->balanceBetween(null, null));

        // ⚠️ القاعدة بتتغيّر **من الشاشة** مش بـ`update()` على الكويري —
        // الكويري بيلدر مابيرفعش إيفنتات الموديل، يعني كاش
        // `GlPostingRule::forKey()` بيفضل شايل القاعدة القديمة وإعادة
        // البناء بتعيد توليد نفس القيود بالظبط (والتيست يعدّي على لا شيء)
        $this->actingAs($admin)->post(route('gl.settings.rules'), [
            'rules' => ['tx.collection.office_cash' => ['debit_key' => 'bank', 'credit_key' => 'receivables', 'active' => 1]],
        ])->assertSessionHasNoErrors();
        $this->assertSame('bank', GlPostingRule::where('key', 'tx.collection.office_cash')->value('debit_key'));

        $res = $this->actingAs($admin)->post(route('gl.rebuild.preview'), ['keep_overrides' => 1]);
        $res->assertOk();

        // جدول الفروق: الخزنة ٦٠٠ ← صفر، والبنك ٣٠٠ ← ٩٠٠
        // ⚠️ بنقص جدول الفروق لوحده من الصفحة — `assertSee` على الصفحة
        // كلها بتخلي PHPUnit يحاول يعمل diff على ١٠٠ كيلو HTML لما
        // تفشل، وده بياخد دقايق قبل ما يطلّع الرسالة
        $diff = $this->sliceTable((string) $res->getContent(), 'glRebuildDiff');
        $this->assertStringContainsString('1101', $diff);
        $this->assertStringContainsString('1102', $diff);
        $this->assertStringContainsString('600.00', $diff);
        $this->assertStringContainsString('900.00', $diff);
        $this->assertStringContainsString('300.00', $diff);
        $this->assertStringContainsString('-600.00', $diff);

        // والمعاينة ما كتبتش حاجة — القيدين زي ما هما بأرصدتهم
        $this->assertSame(2, GlEntry::count(), 'preview writes nothing');
        $this->assertSame(600.0, $cash->balanceBetween(null, null));
        $this->assertSame(300.0, $bank->balanceBetween(null, null));

        $this->actingAs($this->acc)->post(route('gl.rebuild.preview'))->assertForbidden();
    }

    /**
     * ⚠️ رصيد المجموعة = مجموع أحفادها **موقّع بطبيعتها هي** مش بطبيعة
     * الابن — «مرتجعات المبيعات» بتتقيّد مدين تحت جذر الإيرادات الدائن،
     * فلازم تنزّل الإيراد مش تزوّده.
     */
    public function test_the_tree_balances_roll_up_the_subtree_and_sign_by_the_parent(): void
    {
        $admin = $this->makeAdmin();
        $rep = $this->makeRep();
        $repAcc = GlAccount::repCash($rep);          // 1110.X تحت 1110
        $ledger = app(\App\Services\Gl\Ledger::class);

        $ledger->manual(today(), 'بيع مندوب', [
            ['account_id' => $repAcc->id, 'debit' => 500, 'credit' => 0],
            ['account_id' => GlAccount::findKey('sales')->id, 'debit' => 0, 'credit' => 500],
        ], $admin);
        $ledger->manual(today(), 'بيع مكتبي', [
            ['account_id' => GlAccount::findKey('cash_main')->id, 'debit' => 300, 'credit' => 0],
            ['account_id' => GlAccount::findKey('sales')->id, 'debit' => 0, 'credit' => 300],
        ], $admin);
        $ledger->manual(today(), 'مرتجع', [
            ['account_id' => GlAccount::findKey('sales_returns')->id, 'debit' => 200, 'credit' => 0],
            ['account_id' => GlAccount::findKey('opening_equity')->id, 'debit' => 0, 'credit' => 200],
        ], $admin);

        $b = app(\App\Services\Gl\Reports::class)->balancesByAccount(null, null);
        $id = fn (string $code) => GlAccount::where('code', $code)->first()->id;

        // الأصول: الورقة، والأب، وجد الأب، والجذر
        $this->assertSame(500.0, $b[$repAcc->id]);
        $this->assertSame(500.0, $b[$id('1110')], 'نقدية المناديب = مجموع أولادها');
        $this->assertSame(300.0, $b[$id('1101')]);
        $this->assertSame(800.0, $b[$id('11')], 'النقدية وما في حكمها = ٣٠٠ خزنة + ٥٠٠ مندوب');
        $this->assertSame(800.0, $b[$id('1')], 'جذر الأصول — مافيش حركة على العملاء هنا');

        // الإيرادات: المرتجع مدين فرصيده سالب، والجذر بيجمعهم بطبيعته الدائنة
        $this->assertSame(800.0, $b[$id('4101')]);
        $this->assertSame(-200.0, $b[$id('4102')], 'مرتجع مدين تحت حساب دائن');
        $this->assertSame(600.0, $b[$id('4')], '٨٠٠ مبيعات ناقص ٢٠٠ مرتجع');

        // حقوق الملكية طرف المرتجع
        $this->assertSame(200.0, $b[$id('3')]);
    }

    public function test_every_report_and_the_statement_export_csv(): void
    {
        $client = $this->makeClient();
        Transaction::create(['client_id' => $client->id, 'date' => today(), 'memo' => 'فاتورة', 'debit' => 1140, 'credit' => 0, 'tax' => 140, 'kind' => 'sale']);

        $targets = [
            ['gl.accounts.show', ['account' => GlAccount::findKey('receivables')->id, 'export' => 1]],
            ['gl.trial_balance', ['export' => 1]],
            ['gl.income', ['export' => 1]],
            ['gl.balance_sheet', ['export' => 1]],
        ];

        foreach ($targets as [$name, $params]) {
            $res = $this->actingAs($this->acc)->get(route($name, $params));
            $res->assertOk();
            $this->assertStringContainsString('text/csv', (string) $res->headers->get('content-type'), $name);
        }
    }

    public function test_closing_a_period_blocks_manual_entries_inside_it(): void
    {
        $this->actingAs($this->acc)->post(route('gl.periods.close', '2026-07'))->assertRedirect();
        $this->assertTrue(GlPeriod::isClosed(\Illuminate\Support\Carbon::parse('2026-07-15')));

        $this->actingAs($this->acc)->post(route('gl.entries.store'), ['date' => '2026-07-15', 'memo' => 'x', 'lines' => [
            ['account_id' => GlAccount::findKey('expense_rent')->id, 'debit' => 1, 'credit' => 0],
            ['account_id' => GlAccount::findKey('cash_main')->id, 'debit' => 0, 'credit' => 1],
        ]])->assertSessionHasErrors('lines');
    }

    /**
     * ⚠️ **قاعدة نص ماتتحفظش.** القاعدة من غير طرفها بتخلي الترحيل
     * يرمي/يروح حساب معلّق، وإعادة البناء بعدها بترفض — والمحاسب
     * مابيكتشفش ده غير لما فاتورة تعدي من غير قيد. الحارس هنا.
     */
    public function test_a_rule_cannot_be_saved_without_the_side_it_needs(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('gl.settings.rules'), [
            'rules' => ['tx.sale' => ['debit_key' => '', 'credit_key' => 'sales', 'tax_key' => 'vat_output', 'active' => 1]],
        ])->assertSessionHasErrors('rules.tx.sale.debit_key');

        $rule = GlPostingRule::where('key', 'tx.sale')->first();
        $this->assertSame('receivables', $rule->debit_key, 'الرفض معناه إن القاعدة مالمستهاش');
    }

    /**
     * `rep_cash` مفتاح مجموعة بيتحل لحساب المندوب — والريزولفر بتاع
     * «تصفية مندوب» بيستنتج المندوب للطرف الدائن بس. حطّه على المدين
     * كان معناه سطر على حساب مجموعة (رصيد بيتعدّ مرتين في الشجرة).
     */
    public function test_the_rep_cash_group_key_is_only_allowed_where_a_resolver_understands_it(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('gl.settings.rules'), [
            'rules' => ['settle.received' => ['debit_key' => 'rep_cash', 'credit_key' => 'rep_cash', 'active' => 1]],
        ])->assertSessionHasErrors('rules.settle.received.debit_key');
        $this->assertSame('cash_main', GlPostingRule::where('key', 'settle.received')->value('debit_key'));

        // نفس المفتاح على القاعدة اللي ريزولفرها بيفهمه — يعدّي عادي
        $this->actingAs($admin)->post(route('gl.settings.rules'), [
            'rules' => ['tx.collection.rep_cash' => ['debit_key' => 'rep_cash', 'credit_key' => 'receivables', 'active' => 1]],
        ])->assertSessionHasNoErrors();
        $this->assertSame('rep_cash', GlPostingRule::where('key', 'tx.collection.rep_cash')->value('debit_key'));

        // وحساب مجموعة عادي (مش بيقبل ترحيل) مرفوض على أي طرف
        $this->actingAs($admin)->post(route('gl.settings.rules'), [
            'rules' => ['tx.rebate' => ['debit_key' => 'discounts_allowed', 'credit_key' => 'rep_cash', 'active' => 1]],
        ])->assertSessionHasErrors('rules.tx.rebate.credit_key');
    }

    public function test_the_settings_screen_warns_about_a_rule_that_would_stop_posting(): void
    {
        $this->actingAs($this->acc)->get(route('gl.settings'))
            ->assertOk()->assertDontSee(__('gl.rules_broken_warn'));

        $rule = GlPostingRule::where('key', 'tx.sale')->first();
        $rule->update(['active' => false]);

        $page = $this->actingAs($this->acc)->get(route('gl.settings'));
        $page->assertOk()
            ->assertSee(__('gl.rules_broken_warn'))
            ->assertSee(__('gl.rule_inactive'));
    }

    public function test_the_accountant_cannot_touch_the_admin_only_settings(): void
    {
        // الإعدادات نفسها شاشة محاسب — الكتابة فيها (القواعد، السويتش،
        // فتح فترة مقفولة، إعادة البناء) قرار أدمن
        $this->actingAs($this->acc)->post(route('gl.settings.general'), ['gl_enabled' => '0'])->assertForbidden();
        $this->actingAs($this->acc)->post(route('gl.periods.reopen', '2026-07'))->assertForbidden();
        $this->assertSame('1', Setting::read('gl_enabled'));
    }
}
