<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * تفضية السيستم — مسح كل الداتا والبدء من الصفر
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ الأمر ده **مالوش رجعة**. بيمسح كل صف داتا في السيستم ويسيب
 * السكيما زي ما هي (مش migrate:fresh — المايجريشنز مابتتشغلش تاني).
 *
 * ليه مش migrate:fresh؟ لأن fresh بيدروب الجداول ويعيد بناءها، وده
 * بيحتاج قفل ميتاداتا على الداتابيز كلها وبيفشل لو فيه اتصال مفتوح
 * (مشكلة اتكررت في المشروع ده). التفريغ أسرع وأأمن.
 *
 * ⚠️ اليوزرز بيتمسحوا كمان — وده معناه إنك مش هتعرف تدخل. عشان كده
 * الأمر **بيعمل أدمن واحد إجباري** بعد المسح. من غيره السيستم بيقفل
 * على نفسه ومفيش طريقة تفتحه غير من الداتابيز مباشرة.
 */
class ResetSystem extends Command
{
    protected $signature = 'promax:reset
        {--admin-email= : إيميل الأدمن الجديد}
        {--admin-password= : باسورد الأدمن الجديد}
        {--admin-name= : اسم الأدمن الجديد}
        {--force : تنفيذ من غير سؤال تأكيد}';

    protected $description = 'مسح كل داتا السيستم والبدء من الصفر بأدمن واحد';

    /**
     * ترتيب المسح: التابع قبل المتبوع.
     * ⚠️ الترتيب ده مش عشوائي — كل جدول هنا بيعتمد على اللي بعده.
     * لو غيّرته، المسح هيفشل على قيد مفتاح أجنبي.
     */
    private const ORDER = [
        // ⚠️ **ترتيب المسح: الأولاد قبل الآباء.** أي جدول جديد
        // بمفتاح أجنبي لازم يتحط في مكانه هنا، وإلا التفضية بتقع
        // على «Cannot delete or update a parent row» وسط الترانزاكشن
        // والسيستم بيفضل نص مفضّي.

        // ⚠️ **الـFK بتتقفل أثناء المسح** (`disableForeignKeyConstraints`)
        // — يعني `cascadeOnDelete` **مابيشتغلش**. أي جدول مش مذكور
        // هنا بيفضل بصفوفه شايلة `user_id`/`client_id` لكيانات
        // اتمسحت، والسيستم بيبدأ «من الصفر» وهو شايل خردة.
        // (17 جدول كانوا ناقصين — اتضافوا 2026-08-07.)

        // الحركات اليومية
        'track_events', 'app_notifications', 'shelf_refills',
        'replenishment_items', 'replenishment_requests', 'merch_visits',
        'invoice_items', 'invoices', 'purchase_order_items', 'purchase_orders',
        'gift_handouts',
        'rep_settlements',
        'custody_items', 'custodies', 'visits', 'transactions',
        'client_requests', 'api_tokens', 'device_tokens',

        // الحوافز والليدز والعداد وقفل اليوم
        'day_closes', 'rep_points', 'rep_targets', 'commission_tiers',
        'lead_pings', 'odometer_readings', 'vehicle_assignments',

        // سجل حركة اليوزرات + صلاحياتهم
        'activity_logs', 'user_permissions',

        // الجرد — بيشير للباتشات والمخازن واليوزرز
        'stock_count_items', 'stock_counts',

        // خطط السير والعملاء المحتملين — بيشيروا لليوزرز والعملاء
        'journey_plans', 'leads',

        // المخزن
        'pick_order_items', 'pick_orders',
        'stock_transfer_items', 'stock_transfers',
        'batch_locations', 'batches', 'supplier_transactions', 'supplier_payments', 'supplier_invoices',
            'supplier_order_items', 'supplier_orders', 'suppliers',
            'goods_receipts',
        'locations', 'warehouses', 'stocks',

        // العقود
        'contract_dues', 'contract_clauses', 'contracts',

        // سجل الاستيراد — بيشير لليوزرز، ولازم يتمسح قبلهم
        'imports',

        // العربيات — بتشير لليوزرز والفروع
        'vehicles',

        // التأسيس
        'zone_user', 'channel_user',
        'price_list_items', 'price_lists',
        'clients', 'client_groups',
        'products', 'product_families',
        'zones', 'governorates', 'channels',

        // ⚠️ الإعدادات بترجع للافتراضي في `Setting::DEFAULTS` —
        // مش بتضيع. مسحها جزء من «من الصفر» عن قصد.
        'settings',

        // المستخدمين
        'users',

        // ⚠️ الفروع **آخر حاجة**: كل حاجة بتشير ليها، وهي بتشير
        // لليوزر (مدير الفرع). مسحها بدري بيخلّي الـ FK يرفض.
        'branches',
    ];

    /** جداول البنية التحتية اللي مالهاش دعوة بالداتا */
    private const KEEP = [
        'migrations', 'cache', 'cache_locks', 'jobs', 'job_batches',
        'failed_jobs', 'sessions', 'password_reset_tokens',
    ];

    public function handle(): int
    {
        // ⚠️ **`--force` على production كانت بتفضّي الداتابيز فوراً**
        // وتسيب `admin@promax.local` بباسورد افتراضي معروف. الأمر ده
        // موجود عشان التجارب، و`--force` بتتكتب بالعادة — سطر متنسّي
        // في سكربت نشر بيمسح شغل شهور.
        if (app()->environment('production') && env('PROMAX_ALLOW_RESET') !== '1') {
            $this->error('  ⛔ ممنوع على production.');
            $this->line('     لو متأكد: PROMAX_ALLOW_RESET=1 php artisan promax:reset --force');

            return self::FAILURE;
        }

        $this->warn('⚠️  الأمر ده هيمسح **كل** داتا السيستم ومالوش رجعة.');
        $this->newLine();

        $counts = $this->counts();
        $total = array_sum($counts);

        if ($total === 0) {
            $this->info('السيستم فاضي أصلاً.');
        } else {
            $this->line('اللي هيتمسح:');
            foreach ($counts as $table => $n) {
                if ($n > 0) {
                    $this->line(sprintf('   %-24s %s', $table, number_format($n)));
                }
            }
            $this->newLine();
            $this->warn('   الإجمالي: '.number_format($total).' صف');
            $this->newLine();
        }

        if (! $this->option('force') && ! $this->confirm('متأكد؟')) {
            $this->info('اتلغى. مفيش حاجة اتمسحت.');

            return self::SUCCESS;
        }

        // الأدمن بيتاخد **قبل** المسح — عشان لو اليوزر لغى نبقى ماكسرناش حاجة
        [$name, $email, $password] = $this->adminDetails();

        $this->newLine();
        $this->info('بنمسح…');

        DB::transaction(function () {
            // ⚠️ تعطيل فحص المفاتيح مؤقتاً: الترتيب فوق مظبوط، بس فيه
            // مراجع دائرية (contracts.client_id ↔ clients.group_id) مستحيل
            // ترتيبها. التعطيل جوه ترانزاكشن فأي فشل بيرجّع كل حاجة.
            Schema::disableForeignKeyConstraints();

            try {
                foreach (self::ORDER as $table) {
                    if (Schema::hasTable($table)) {
                        DB::table($table)->delete();
                        $this->line('   • '.$table);
                    }
                }
            } finally {
                Schema::enableForeignKeyConstraints();
            }
        });

        // العدّادات ترجع لأول رقم — عشان الأكواد تبدأ من CL-1001 تاني
        $this->resetAutoIncrements();

        $admin = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'admin',
            'code' => 'ADM-001',
            'active' => true,
        ]);

        // ⚠️ **القنوات بترجع فوراً بعد المسح.** دي مش داتا — دي ثابت في
        // السيستم: كل عميل لازم يقع في واحدة منهم والتسعير كله معلّق
        // عليهم. لما التفضية كانت بتمسحهم من غير رجوع، فورم العميل كان
        // بيفتح بقايمة قنوات فاضية، والمستخدم مش لاقي «كاش فان» فبيسيب
        // الخانة والعميل يتحفظ من غير قناة ومن غير خصم.
        $this->seedChannels();

        cache()->flush();

        // ⚠️ الجلسات لازم تتمسح. العدّاد بيرجع لواحد، فأي متصفح لسه فاتح
        // جلسة على اليوزر رقم 1 القديم بيبقى **هو الأدمن الجديد** من غير
        // ما يدخل باسورد.
        $sessions = storage_path('framework/sessions');
        if (is_dir($sessions)) {
            foreach (glob($sessions.'/*') ?: [] as $f) {
                if (is_file($f) && basename($f) !== '.gitignore') {
                    @unlink($f);
                }
            }
        }
        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->delete();
        }

        $this->newLine();
        $this->info('✅ السيستم اتفضّى.');
        $this->newLine();
        $this->line('   الأدمن: '.$admin->email);
        $this->line('   الباسورد: '.$password);
        $this->newLine();
        $this->comment('ارفع الداتا من شاشة الاستيراد: /erp/import');
        $this->comment('الترتيب المفروض: المنتجات ← العملاء ← الفريق والمناطق ← المخزون');

        return self::SUCCESS;
    }

    /**
     * القنوات الأربعة — ثابت في السيستم مش داتا.
     *
     * ⚠️ نفس منطق مايجريشن `seed_four_channels`. الاتنين موجودين عن
     * قصد: المايجريشن للتنصيب الجديد، ودي للتفضية — والسيستم ممنوع
     * يقعد ولو لحظة من غير قنوات.
     */
    private function seedChannels(): void
    {
        foreach (Channel::DEFAULTS as $code => [$chName, $chNameEn, $color]) {
            Channel::updateOrCreate(['code' => $code], [
                'name' => $chName,
                'name_en' => $chNameEn,
                'color' => $color,
                'active' => true,
            ]);
        }

        $this->line('   • '.count(Channel::DEFAULTS).' قنوات اترجّعت');
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $out = [];
        foreach (self::ORDER as $table) {
            if (Schema::hasTable($table)) {
                $out[$table] = DB::table($table)->count();
            }
        }

        return $out;
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function adminDetails(): array
    {
        $name = $this->option('admin-name')
            ?: ($this->option('force') ? 'Admin' : $this->ask('اسم الأدمن', 'Admin'));

        $email = $this->option('admin-email')
            ?: ($this->option('force') ? 'admin@promax.local' : $this->ask('إيميل الأدمن', 'admin@promax.local'));

        // ⚠️ الأقواس الداخلية إجبارية: `a ? b : c ?: d` من غير أقواس
        // خطأ **تجميع** في PHP 8 — وأي خطأ تجميع في مجلد الأوامر بيوقّف
        // كل أوامر artisan مش الأمر ده بس.
        $password = $this->option('admin-password');

        if ($password === null) {
            $password = $this->option('force')
// ⚠️ **مافيش باسورد افتراضي معروف.** كان `promax2026` — يعني
                // `promax:reset --force` بتسيب أدمن على السيستم بباسورد
                // مكتوب في الكود وأي حد شاف الريبو يعرفه.
                ? \App\Console\Commands\SetupTeam::newPassword()
                : ((string) $this->secret('باسورد الأدمن') ?: \App\Console\Commands\SetupTeam::newPassword());
        }

        return [$name, $email, $password];
    }

    /**
     * ترجيع العدّادات لواحد.
     * ⚠️ DELETE مابيرجّعش الـ AUTO_INCREMENT (بعكس TRUNCATE)، ومن غير
     * كده أول عميل جديد هياخد id 104 وكوده CL-1104 — مش CL-1001.
     */
    private function resetAutoIncrements(): void
    {
        foreach (self::ORDER as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'id')) {
                continue;
            }

            try {
                DB::statement("ALTER TABLE `$table` AUTO_INCREMENT = 1");
            } catch (\Throwable) {
                // جدول من غير عمود ترقيم تلقائي — عادي
            }
        }
    }
}
