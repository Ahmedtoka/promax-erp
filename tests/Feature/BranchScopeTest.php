<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Client;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\ModernTradeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * سكوب الفرع
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ التيستات دي بتحرس قاعدة **أمنية**: مدير فرع المعادي ممنوع
 * يشوف أرقام فرع تاني. الفلترة في القوايم مش كفاية — الحارس على
 * الصفحة اللي بتفتح سجل واحد بالـ id هو اللي بيمنع الوصول فعلاً.
 */
class BranchScopeTest extends TestCase
{
    use RefreshDatabase;

    private function branch(string $code): Branch
    {
        return Branch::create([
            'code' => $code,
            'name' => 'فرع '.$code,
            'name_en' => $code.' branch',
            'active' => true,
        ]);
    }

    // ═══════════════════════ القاعدة الأساسية ═══════════════════════

    public function test_null_branch_means_visible_to_everyone_not_forbidden(): void
    {
        $maadi = $this->branch('MAADI');

        $manager = $this->makeAdmin(['role' => 'branch_manager', 'branch_id' => $maadi->id]);

        $central = $this->makeClient(['name' => 'عميل مركزي', 'branch_id' => null]);
        $mine = $this->makeClient(['name' => 'عميل المعادي', 'branch_id' => $maadi->id]);

        $seen = Branch::scope(Client::query(), $manager)->pluck('id')->all();

        $this->assertContains($central->id, $seen, 'الداتا المركزية لازم تبان للكل');
        $this->assertContains($mine->id, $seen);
    }

    public function test_a_branch_manager_never_sees_another_branch(): void
    {
        $maadi = $this->branch('MAADI');
        $giza = $this->branch('GIZA');

        $manager = $this->makeAdmin(['role' => 'branch_manager', 'branch_id' => $maadi->id]);

        $mine = $this->makeClient(['name' => 'عميل المعادي', 'branch_id' => $maadi->id]);
        $theirs = $this->makeClient(['name' => 'عميل الجيزة', 'branch_id' => $giza->id]);

        $seen = Branch::scope(Client::query(), $manager)->pluck('id')->all();

        $this->assertContains($mine->id, $seen);
        $this->assertNotContains($theirs->id, $seen, 'ممنوع يشوف عميل فرع تاني');
    }

    public function test_admin_and_channel_manager_see_everything(): void
    {
        $maadi = $this->branch('MAADI');
        $giza = $this->branch('GIZA');

        $this->makeClient(['branch_id' => $maadi->id]);
        $this->makeClient(['branch_id' => $giza->id]);
        $this->makeClient(['branch_id' => null]);

        foreach (['admin', 'manager'] as $role) {
            $user = $this->makeAdmin(['role' => $role, 'branch_id' => $maadi->id]);

            $this->assertTrue($user->seesAllBranches(), "الرول {$role} لازم يشوف كل الفروع");
            $this->assertSame(3, Branch::scope(Client::query(), $user)->count());
        }
    }

    public function test_a_branch_manager_without_a_branch_still_does_not_see_everything(): void
    {
        // ⚠️ الحالة دي كانت ثغرة: مدير فرع من غير `branch_id` كان
        // بيعدّي على شرط «موظف مركزي» وبيبقى قارئ للشركة كلها.
        $manager = $this->makeAdmin(['role' => 'branch_manager', 'branch_id' => null]);

        $this->assertFalse($manager->seesAllBranches());
    }

    public function test_scope_leaves_other_conditions_intact(): void
    {
        // ⚠️ لو الـ `orWhereNull` مش جوه أقواس، بيلغي الشروط اللي
        // بعده وبيرجّع صفوف مش المفروض تبان.
        $maadi = $this->branch('MAADI');
        $manager = $this->makeAdmin(['role' => 'branch_manager', 'branch_id' => $maadi->id]);

        // ⚠️ `clients.status` عمود `enum('active','pending','rejected')`.
        // «موقوف» في السيستم = `pending` (`ClientActivationController::
        // deactivate`) — و`inactive` اللي كان مكتوب هنا مش قيمة موجودة
        // في أي مكان في الكود، فكان بيرمي «Data truncated for column».
        $this->makeClient(['name' => 'نشط', 'branch_id' => null, 'status' => 'active']);
        $this->makeClient(['name' => 'موقوف', 'branch_id' => null, 'status' => 'pending']);

        $count = Branch::scope(Client::query(), $manager)->where('status', 'active')->count();

        $this->assertSame(1, $count, 'شرط الحالة لازم يفضل شغّال مع السكوب');
    }

    // ═══════════════════════ الحارس على السجل الواحد ═══════════════════════

    public function test_opening_another_branch_client_card_is_forbidden(): void
    {
        $maadi = $this->branch('MAADI');
        $giza = $this->branch('GIZA');

        $manager = $this->makeAdmin([
            'role' => 'branch_manager',
            'branch_id' => $maadi->id,
            'password' => bcrypt('secret'),
        ]);

        $theirs = $this->makeClient(['branch_id' => $giza->id]);
        $mine = $this->makeClient(['branch_id' => $maadi->id]);

        // ⚠️ الفلترة بتخبّي الصف عن العين — الحارس بيمنع الراوت
        $this->actingAs($manager)->get('/erp/clients/'.$theirs->id)->assertForbidden();
        $this->actingAs($manager)->get('/erp/clients/'.$mine->id)->assertOk();
    }

    public function test_can_see_branch_helper(): void
    {
        $maadi = $this->branch('MAADI');
        $giza = $this->branch('GIZA');

        $manager = $this->makeAdmin(['role' => 'branch_manager', 'branch_id' => $maadi->id]);
        $admin = $this->makeAdmin(['role' => 'admin']);

        $this->assertTrue($manager->canSeeBranch(null), 'المركزي مسموح');
        $this->assertTrue($manager->canSeeBranch($maadi->id));
        $this->assertFalse($manager->canSeeBranch($giza->id));

        $this->assertTrue($admin->canSeeBranch($giza->id), 'الأدمن بيشوف كل حاجة');
    }

    // ═══════════════════════ السيدر ═══════════════════════

    public function test_seeder_is_safe_to_run_twice(): void
    {
        $this->seed(ModernTradeSeeder::class);

        $users = User::count();
        $vehicles = Vehicle::count();
        $branches = Branch::count();

        $this->assertGreaterThan(0, $users);
        $this->assertSame(3, $vehicles, '3 عربيات مودرن تريد');

        // ⚠️ التشغيلة التانية مالازمش تعمل نسخ
        $this->seed(ModernTradeSeeder::class);

        $this->assertSame($users, User::count(), 'مفيش يوزرات مكررة');
        $this->assertSame($vehicles, Vehicle::count(), 'مفيش عربيات مكررة');
        $this->assertSame($branches, Branch::count(), 'مفيش فروع مكررة');
    }

    public function test_seeder_does_not_reset_an_existing_password_or_email(): void
    {
        $this->seed(ModernTradeSeeder::class);

        $admin = User::where('code', 'ADM-001')->firstOrFail();

        // اليوزر غيّر إيميله وباسورده من الشاشة
        $admin->update(['email' => 'boss@promax.local', 'password' => bcrypt('mine')]);

        $this->seed(ModernTradeSeeder::class);

        $admin->refresh();

        $this->assertSame('boss@promax.local', $admin->email,
            'الإيميل هو اسم الدخول — الكتابة فوقه بتقفل على صاحبه');
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('mine', $admin->password),
            'الباسورد المتغيّر مالازمش يرجع للمبدئي');
    }

    public function test_seeder_creates_one_van_where_the_rep_drives(): void
    {
        $this->seed(ModernTradeSeeder::class);

        $solo = Vehicle::whereColumn('rep_id', 'driver_id')->get();

        $this->assertCount(1, $solo, 'عربية واحدة المندوب فيها بيسوق بنفسه');
        $this->assertSame('رج ا 9161', $solo->first()->plate);
    }

    public function test_every_seeded_zone_belongs_to_the_maadi_branch(): void
    {
        $this->seed(ModernTradeSeeder::class);

        $maadi = Branch::where('code', 'MAADI')->firstOrFail();

        $this->assertSame(18, $maadi->zones()->count(), '18 منطقة مودرن تريد');
    }
}
