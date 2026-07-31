<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\Warehouse;
use App\Models\Zone;
use App\Support\Roster;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * ═══════════════════════════════════════════════════════════════
 * promax:team:setup — يحطّ الفريق الحقيقي ويشيل اللي قبله
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **حذف يوزر بيمسح شغله معاه.** الـFK دي كلها `cascadeOnDelete`
 * على `users`:
 *
 *     invoices · custodies · visits · merch_visits · journey_plans
 *     client_requests · pick_orders · replenishment_requests · track_events
 *
 * يعني `User::truncate()` بيمسح **الفواتير** — والقيود في `transactions`
 * بتفضل مكانها لأن `transactions` مش فيها FK على `users`. النتيجة:
 * أرصدة العملاء فيها مبيعات مالهاش فواتير، ومحدش يعرف السبب.
 *
 * فالأمر ده **بيعدّ الأول**: اليوزر اللي عليه شغل بيتوقّف (`active=0`)
 * ومابيتمسحش، واللي مالوش شغل بيتمسح فعلاً. والفرق بيتقال بالبنط
 * العريض قبل ما حاجة تحصل.
 */
class SetupTeam extends Command
{
    protected $signature = 'promax:team:setup
        {--force : من غير تأكيد}
        {--password=promax123 : باسورد الحسابات الجديدة}
        {--purge : امسح اللي عليه شغل كمان — بيمسح فواتير وعُهد وزيارات}';

    protected $description = 'يحطّ فريق PROMAX الحقيقي ويشيل حسابات التجربة';

    /**
     * الجداول اللي بتتمسح مع اليوزر.
     *
     * ⚠️ القايمة دي **مستخرجة من المايجريشنز**، مش مكتوبة من الذاكرة.
     * أي `cascadeOnDelete` جديدة على `users` لازم تتزوّد هنا، وإلا
     * الأمر هيقول «اليوزر ده مالوش شغل» وهو بيمسح جدول مش في الحسبان.
     *
     * @var array<string, string>
     */
    private const CASCADES = [
        'invoices' => 'user_id',
        'custodies' => 'user_id',
        'visits' => 'user_id',
        'merch_visits' => 'user_id',
        'journey_plans' => 'user_id',
        'client_requests' => 'created_by',
        'pick_orders' => 'assigned_to',
        'replenishment_requests' => 'requested_by',
        'track_events' => 'user_id',
        // ⚠️ **دي كانت ناقصة والجدول ده اتعمل في نفس المايجريشن.**
        // سواق كل أثره تسكين عربيات كان بيتحسب «مالوش شغل»، يتمسح،
        // وتاريخ تسكينه يروح معاه — وده بالظبط السجل اللي الموديول
        // اتعمل عشان يحفظه.
        'vehicle_assignments' => 'user_id',
        'app_notifications' => 'user_id',
        'zone_user' => 'user_id',
        'channel_user' => 'user_id',
    ];

    /**
     * الجداول اللي **مابتتمسحش** بس بتتفضّى (`nullOnDelete`).
     *
     * ⚠️ دي مش أقل خطورة من الـcascade — أهدى وبس. حذف مندوب «نضيف»
     * بيفضّي `rep_id` من كل عميل كان مسؤول عنه، والعملاء دول بيبقوا
     * من غير مسؤول ومحدش واخد باله. لازم العدد يتقال قبل الحذف.
     *
     * @var array<string, list<string>>
     */
    private const REFERENCES = [
        'clients' => ['rep_id', 'manager_id', 'created_by'],
        'purchase_orders' => ['assigned_to'],
        'vehicles' => ['driver_id', 'rep_id'],
        'branches' => ['manager_id'],
        'warehouses' => ['manager_id'],
        'leads' => ['assigned_to', 'created_by'],
        'replenishment_requests' => ['assigned_to'],
        'stock_counts' => ['started_by', 'approved_by'],
    ];

    public function handle(): int
    {
        $this->newLine();
        $this->line('  ┌──────────────────────────────────────────┐');
        $this->line('  │  فريق PROMAX — التحديث للفريق الحقيقي   │');
        $this->line('  └──────────────────────────────────────────┘');
        $this->newLine();

        $keepEmails = Roster::emails();

        // ⚠️ المقارنة **بالإيميل الصغير**. `Jad@promax.com` و
        // `jad@promax.com` نفس الحساب في MySQL بالـcollation الافتراضي،
        // بس `in_array` في PHP بيقول إنهم مختلفين — وكان هيمسح الحساب
        // ويعمله تاني، ويضيّع أي شغل عليه.
        $keepLower = array_map('strtolower', $keepEmails);

        // ⚠️ `whereNotNull` لازم: اليوزر اللي إيميله NULL بيخلّي
        // `LOWER(email) NOT IN (...)` ترجّع NULL — مش TRUE ولا FALSE —
        // فمابيتحسبش لا في القديم ولا في الجديد وبيفضل في السيستم
        // للأبد من غير ما حد يشوفه.
        $doomed = User::whereNotNull('email')
            ->whereNotIn(DB::raw('LOWER(email)'), $keepLower)
            ->get();

        // ═══════════ 0. فحص تصادم أكواد الموظفين ═══════════
        // ⚠️ **ده كان بيسيب السيستم من غير أي حساب يدخل.** عمود `code`
        // فيه `unique`، والفريق القديم فيه `ADM-001` و`CHM-001` —
        // نفس أكواد جاد وعمرو في القايمة الجديدة. الحساب القديم اللي
        // عليه شغل بيتوقّف ومابيتمسحش، يعني كوده بيفضل محجوز، وبعدين
        // `updateOrCreate` بترمي Duplicate entry — **بعد** ما الحذف
        // اتنفّذ وخلص. النتيجة: كل الحسابات القديمة راحت، ولا حساب
        // جديد اتعمل، ومحدش يقدر يدخل لا على الويب ولا على الأبلكيشن.
        $blockers = $this->codeCollisions($keepLower);

        if ($blockers !== []) {
            $this->newLine();
            $this->error('  ⛔ أكواد موظفين متصادمة — الأمر وقف قبل ما يلمس حاجة:');
            $this->newLine();

            foreach ($blockers as [$code, $oldEmail, $newEmail]) {
                $this->line("     • <fg=yellow>{$code}</> عند {$oldEmail} ومطلوب لـ {$newEmail}");
            }

            $this->newLine();
            $this->line('  غيّر الكود في App\\Support\\Roster، أو فضّي الكود القديم:');
            $this->line("     php artisan tinker --execute=\"App\\Models\\User::where('email','<الإيميل>')->update(['code'=>null]);\"");
            $this->newLine();

            return self::FAILURE;
        }

        // ═══════════ 1. العدّ قبل أي حاجة ═══════════
        $withWork = [];
        $clean = [];

        foreach ($doomed as $user) {
            $counts = $this->workOf($user);

            if (array_sum($counts) > 0) {
                $withWork[] = [$user, $counts];
            } else {
                $clean[] = $user;
            }
        }

        $this->line('  الحسابات القديمة: <fg=yellow>'.$doomed->count().'</>');
        $this->line('  الفريق الجديد:    <fg=green>'.count($keepEmails).'</>');
        $this->newLine();

        if ($clean !== []) {
            $this->line('  🗑  هتتمسح (مالهاش أي شغل مسجّل):');

            foreach ($clean as $u) {
                // ⚠️ **«مالوش شغل» مش معناها «مالوش أثر».** حذفه بيفضّي
                // `rep_id` من كل عميل كان مسؤول عنه — والعملاء دول
                // بيبقوا من غير مسؤول ومحدش واخد باله. لازم يتقال.
                $orphans = $this->orphansOf($u);

                $tail = $orphans === [] ? '' : '  <fg=yellow>↳ هيتفضّى: '
                    .collect($orphans)->map(fn ($n, $t) => "{$t}: {$n}")->implode('، ').'</>';

                $this->line("     • {$u->email} — {$u->roleLabel()}{$tail}");
            }

            $this->newLine();
        }

        if ($withWork !== []) {
            // ⚠️ **دي أهم شاشة في الأمر كله.** لو عدّيناها في صمت،
            // حد هيدوس «نعم» ويمسح فواتير من غير ما يعرف.
            $this->warn('  ⚠️  الحسابات دي عليها شغل مسجّل:');
            $this->newLine();

            foreach ($withWork as [$u, $counts]) {
                $detail = collect($counts)
                    ->filter()
                    ->map(fn ($n, $t) => "{$t}: {$n}")
                    ->implode('، ');

                $this->line("     • <fg=yellow>{$u->email}</> — {$detail}");
            }

            $this->newLine();

            if ($this->option('purge')) {
                $this->error('  🔴 مع --purge الشغل ده كله هيتمسح — الفواتير كمان.');
                $this->line('     القيود في كشوف الحساب مش هتتمسح معاها، فالأرصدة');
                $this->line('     هتفضل فيها مبيعات مالهاش فواتير.');
            } else {
                $this->line('  الحسابات دي <fg=green>هتتوقّف</> ومش هتتمسح —');
                $this->line('  شغلها بيفضل مربوط بيها والتقارير القديمة بتفضل مظبوطة.');
                $this->line('  لو متأكد إنك عايز تمسحها بشغلها: <fg=yellow>--purge</>');
            }

            $this->newLine();
        }

        if (! $this->option('force') && ! $this->confirm('نكمّل؟', false)) {
            $this->line('  اتلغى — مافيش حاجة اتغيّرت.');

            return self::SUCCESS;
        }

        // ═══════════ 2. التنفيذ ═══════════
        // ⚠️ **الهدم والبناء في ترانزاكشن واحدة.** لما كانوا اتنين،
        // أي خطأ في البناء كان بيسيب السيستم بعد الهدم وخلاص: الحسابات
        // القديمة اتمسحت، الجديدة ماتعملتش، ومحدش يدخل. الترانزاكشن
        // الواحدة معناها إما الاتنين أو ولا واحد.
        $made = 0;
        $fleet = 0;

        $rosterCodes = array_column(Roster::TEAM, 'code');

        DB::transaction(function () use ($clean, $withWork, $rosterCodes, &$made, &$fleet) {
            foreach ($clean as $u) {
                // ⚠️ `forceDelete` صراحةً: `User` مافيهاش `SoftDeletes`
                // دلوقتي، وأول ما حد يضيفها الأمر ده بيتحوّل لـno-op
                // صامت — الصف بيفضل بكوده المحجوز والبناء بيرمي
                // Duplicate entry على عمود `code`.
                $u->forceDelete();
            }

            foreach ($withWork as [$u, $counts]) {
                if ($this->option('purge')) {
                    $u->forceDelete();

                    continue;
                }

                // ⚠️ التوقيف بيشيل التوكنز كمان. اليوزر الموقّف اللي
                // توكنه لسه شغّال بيفضل داخل على الأبلكيشن عادي —
                // الـmiddleware بيفحص التوكن مش `active` على كل نداء.
                $u->tokens()->delete();

                // ⚠️ **الكود بيتفكّ من الحساب الموقّف.** `code` عمود
                // `unique`، وسيدر مودرن تريد بيستخدم `ADM-001` و
                // `CHM-001` و`PRM-001` — نفس أكواد الفريق الجديد.
                // الحساب اللي عليه شغل بيتوقّف ومابيتمسحش، فكوده كان
                // بيفضل محجوز والبناء بيرمي Duplicate entry.
                //
                // بنضيف `-OLD` بدل ما نفضّيه: الكود بيفضل مقروء في أي
                // تقرير قديم، والخانة بتتفك للحساب الجديد. والشغل
                // نفسه مربوط بـ`user_id` مش بالكود، فمفيش حاجة بتنقطع.
                $freed = $u->code !== null && in_array($u->code, $rosterCodes, true)
                    ? $this->freeCode((string) $u->code)
                    : $u->code;

                $u->update(['active' => false, 'code' => $freed]);
            }

            $made = $this->buildTeam();
            $fleet = $this->buildFleet();
        });

        // ═══════════ 3. النتيجة ═══════════
        $this->newLine();
        $this->line('  ── الفريق دلوقتي ──');
        $this->newLine();

        $rows = User::where('active', true)
            ->orderByRaw("FIELD(role, 'admin', 'manager', 'branch_manager', 'accountant', "
                ."'warehouse_keeper', 'sales_agent', 'driver', 'promoter')")
            ->orderBy('code')
            ->get()
            ->map(fn (User $u) => [
                $u->code,
                $u->name,
                $u->roleLabel(),
                $u->email,
                $u->warehouse?->code ?? $u->channel?->code ?? '—',
            ])
            ->all();

        $this->table(['الكود', 'الاسم', 'الوظيفة', 'الإيميل', 'المخزن/القناة'], $rows);

        if ($fleet > 0) {
            $this->line("  🚚 العربيات: {$fleet}");
        } elseif (Vehicle::count() === 0) {
            $this->newLine();
            $this->warn('  ⚠️ مافيش عربيات. أضفها من /erp/vehicles أو حطّها في');
            $this->line('     App\\Support\\Roster::FLEET وشغّل الأمر تاني.');
        }

        $noZones = User::where('active', true)
            ->whereIn('role', ['sales_agent', 'promoter'])
            ->whereDoesntHave('zones')
            ->count();

        if ($noZones > 0) {
            $this->newLine();
            $this->warn("  ⚠️ فيه {$noZones} مندوب/بروموتر من غير مناطق.");
            $this->line('     خط سيرهم هيطلع فاضي. سكّنهم من /ops/assignments.');
        }

        $this->newLine();
        $this->info("  ✅ {$made} حساب جاهز. الباسورد: ".$this->option('password'));
        $this->line('     الدخول بالإيميل أو بكود الموظف.');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * كود بديل مافيش حد واخده.
     *
     * ⚠️ بيلف على `-OLD` و`-OLD2`… لأن الأمر ممكن يتشغّل أكتر من مرة
     * وكل مرة بتوقّف حساب تاني على نفس الكود الأصلي.
     */
    private function freeCode(string $code): string
    {
        $base = $code.'-OLD';

        if (! User::where('code', $base)->exists()) {
            return $base;
        }

        for ($i = 2; $i < 100; $i++) {
            if (! User::where('code', $base.$i)->exists()) {
                return $base.$i;
            }
        }

        // ⚠️ 99 حساب موقّف على نفس الكود؟ حاجة غلط — بنفضّيه بدل ما
        // نرجّع كود مكرر ونقع على Duplicate entry.
        return '';
    }

    /**
     * أكواد موظفين محجوزة عند حساب قديم ومطلوبة لحساب جديد.
     *
     * ⚠️ بيفحص **قبل** أي حذف. الحساب القديم اللي عليه شغل بيتوقّف
     * ومابيتمسحش، فكوده بيفضل محجوز — و`code` عمود `unique`.
     *
     * @param  list<string>  $keepLower  إيميلات الفريق الجديد (صغيرة)
     * @return list<array{0:string,1:string,2:string}>
     */
    private function codeCollisions(array $keepLower): array
    {
        $out = [];

        foreach (Roster::TEAM as $row) {
            $holder = User::where('code', $row['code'])->first();

            if ($holder === null) {
                continue;
            }

            // نفس الحساب؟ يبقى تحديث عادي مش تصادم
            if (strtolower((string) $holder->email) === strtolower($row['email'])) {
                continue;
            }

            // ⚠️ الحساب القديم (مش في الفريق الجديد) بيتصرّف فيه
            // تلقائياً: يا بيتمسح لو مالوش شغل، يا بيتوقّف وكوده
            // بيتفكّ لـ`-OLD`. الحالتين بيفضّوا الخانة، فمش تصادم.
            //
            // ⚠️ **`email !== null` لازم.** اليوزر بإيميل فاضي مستثنى
            // من `$doomed` أصلاً (`whereNotNull`)، يعني مش هيتمسح ولا
            // هيتوقّف — وكوده هيفضل محجوز. لازم يتبلّغ.
            $isOldAccount = $holder->email !== null
                && ! in_array(strtolower($holder->email), $keepLower, true);

            if ($isOldAccount) {
                continue;
            }

            // ⚠️ اللي بيوصل هنا: صاحب الكود **في الفريق الجديد** بس
            // بصف تاني — يعني اتنين في القايمة بيتبادلوا الأكواد.
            // ده غلط في `Roster` نفسه ولازم إنسان يصلّحه.
            $out[] = [$row['code'], (string) ($holder->email ?? '—'), $row['email']];
        }

        return $out;
    }

    /**
     * الصفوف اللي بتتفضّى لو مسحنا اليوزر (`nullOnDelete`).
     *
     * @return array<string, int>
     */
    private function orphansOf(User $user): array
    {
        $out = [];

        foreach (self::REFERENCES as $table => $columns) {
            if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
                continue;
            }

            $q = DB::table($table);

            foreach ($columns as $i => $col) {
                if (! \Illuminate\Support\Facades\Schema::hasColumn($table, $col)) {
                    continue;
                }

                $i === 0 ? $q->where($col, $user->id) : $q->orWhere($col, $user->id);
            }

            $n = $q->count();

            if ($n > 0) {
                $out[$table] = $n;
            }
        }

        return $out;
    }

    /**
     * الشغل المربوط باليوزر — اللي هيتمسح لو مسحناه.
     *
     * @return array<string, int>
     */
    private function workOf(User $user): array
    {
        $out = [];

        foreach (self::CASCADES as $table => $column) {
            // ⚠️ `hasTable` لازم: الأمر بيتشغّل على داتابيز لسه
            // ماخدتش كل المايجريشنز، والكويري على جدول مش موجود
            // بترمي وتوقّف الأمر قبل ما يقول أي حاجة مفيدة.
            if (! \Illuminate\Support\Facades\Schema::hasTable($table)) {
                continue;
            }

            $n = DB::table($table)->where($column, $user->id)->count();

            if ($n > 0) {
                $out[$table] = $n;
            }
        }

        return $out;
    }

    /** إنشاء أو تحديث الفريق من `Roster` */
    private function buildTeam(): int
    {
        $password = (string) $this->option('password');
        $made = 0;

        foreach (Roster::TEAM as $row) {
            $channel = isset($row['channel'])
                ? Channel::where('code', $row['channel'])->first()
                : null;

            $warehouse = isset($row['warehouse'])
                ? Warehouse::where('code', $row['warehouse'])->first()
                : null;

            if (isset($row['warehouse']) && $warehouse === null) {
                // ⚠️ **ده خطأ مش تحذير.** أمين مخزن بـ`warehouse_id`
                // فاضي بيعدّي من `guardWarehouse` من غير ما تمنعه —
                // يعني الحساب اللي المفروض مقفول على مخزن واحد بيفتح
                // كل المخازن: يستلم شحناتها، يرصّف على أرففها، ويشوف
                // إذون استلامها. التحذير كان بيعدّي في الترمينال ومحدش
                // ياخد باله، والصلاحية تفضل مفتوحة على السيستم اللايف.
                throw new \RuntimeException(
                    "المخزن «{$row['warehouse']}» مش موجود — لازم يتعمل قبل ما {$row['email']} يتسكّن عليه. "
                    .'شغّل `php artisan promax:catalogue` الأول.'
                );
            }

            // ⚠️ الحساب الموجود بإيميل مطابق بيتاخد بالرول الجديد.
            // لو ده رول مختلف، لازم يتقال — تحويل مندوب لأدمن في صمت
            // مش حاجة حد يكتشفها بالصدفة.
            $before = User::where('email', $row['email'])->first();

            if ($before !== null && $before->role !== $row['role']) {
                $this->warn("  ⚠️ {$row['email']}: الرول بيتغيّر من «{$before->role}» لـ«{$row['role']}».");
            }

            // ⚠️ **`firstOrNew` مش `updateOrCreate`.**
            //
            // `updateOrCreate` بتعمل الـINSERT بالحقول اللي في المصفوفة
            // بس، و`password` مش فيها — وعمود `password` مالوش ديفولت
            // في MySQL. النتيجة كانت:
            //
            //     SQLSTATE[HY000] 1364: Field 'password' doesn't have
            //     a default value
            //
            // كنت بحط الباسورد **بعد** الإنشاء، بس الإنشاء نفسه هو اللي
            // بيقع. و`firstOrNew` بتخلّي الصف يتبني في الذاكرة الأول،
            // فنقدر نحط الباسورد قبل ما يوصل للداتابيز — ونفضل نحطه
            // للجديد بس زي ما كان مطلوب.
            $user = User::firstOrNew(['email' => $row['email']]);

            // ⚠️ **الباسورد للجديد بس.** الأمر بيتشغّل تاني وتالت مع كل
            // تعديل في القايمة، ولو كان بيدوس على الباسورد كل مرة كان
            // كل واحد غيّر باسورده يلاقيه رجع للافتراضي من غير ما حد
            // يقوله.
            if (! $user->exists || blank($user->password)) {
                $user->password = Hash::make($password);
            }

            $user->fill([
                'name' => $row['name'],
                'name_en' => $row['name_en'] ?? null,
                'role' => $row['role'],
                'code' => $row['code'],
                'channel_id' => $channel?->id,
                'warehouse_id' => $warehouse?->id,
                'active' => true,
            ])->save();

            // القنوات اللي بيديرها
            if (($row['manages'] ?? null) === 'all') {
                $user->channels()->sync(Channel::pluck('id'));
            } elseif (is_array($row['manages'] ?? null)) {
                $user->channels()->sync(Channel::whereIn('code', $row['manages'])->pluck('id'));
            }

            // المناطق
            if (isset($row['zones']) && $row['zones'] !== []) {
                $ids = [];

                foreach ($row['zones'] as $zoneName) {
                    $zone = Zone::where('name', $zoneName)
                        ->orWhere('name_en', $zoneName)
                        ->orWhere('code', $zoneName)
                        ->first();

                    if ($zone === null) {
                        $this->warn("  ⚠️ المنطقة «{$zoneName}» مش موجودة — اتخطّت لـ{$row['email']}.");

                        continue;
                    }

                    $ids[] = $zone->id;
                }

                if ($ids !== []) {
                    $user->zones()->sync($ids);
                    // ⚠️ `zone_id` القديم لازم يتحدّث كمان: شاشات ولوجيك
                    // كتير لسه بتقرا منه، وسيبه فاضي بيخلّي المندوب
                    // يبان من غير منطقة في نص الشاشات.
                    $user->update(['zone_id' => $ids[0]]);
                }
            }

            if ($user->tokens()->count() === 0) {
                $user->issueToken('mobile');
            }

            $made++;
        }

        // ⚠️ أمين المخزن بيتحط مدير على مخزنه كمان. من غير ده شاشة
        // المخزن بتقول «مافيش مسؤول» وهو قاعد عليها.
        // ⚠️ `active` لازم. اليوزرات القديمة اتوقّفت في نفس الترانزاكشن
        // وصفوفها لسه موجودة — من غير الفلتر، أمين مخزن موقّف ممكن يكسب
        // سباق `whereNull('manager_id')` وتفضل شاشة المخزن بتقول إن
        // المسؤول عنه حساب مقفول.
        foreach (User::where('role', 'warehouse_keeper')
            ->where('active', true)
            ->whereNotNull('warehouse_id')->get() as $keeper) {
            Warehouse::where('id', $keeper->warehouse_id)
                ->whereNull('manager_id')
                ->update(['manager_id' => $keeper->id]);
        }

        return $made;
    }

    /** العربيات من `Roster::FLEET` */
    private function buildFleet(): int
    {
        $made = 0;

        foreach (Roster::FLEET as $row) {
            $driver = isset($row['driver'])
                ? User::where('email', $row['driver'])->first()
                : null;

            if (isset($row['driver']) && $driver === null) {
                $this->warn("  ⚠️ السواق {$row['driver']} مش موجود — العربية {$row['plate']} من غير سواق.");
            }

            // ⚠️ **المندوب غير السواق.** العربية بتشيل الاتنين:
            // `driver_id` اللي بيسوق و`rep_id` اللي بيبيع منها. في
            // مودرن تريد سامح هو الاتنين، والباقي مندوب مع سواق.
            $rep = isset($row['rep'])
                ? User::where('email', $row['rep'])->first()
                : null;

            if (isset($row['rep']) && $rep === null) {
                $this->warn("  ⚠️ المندوب {$row['rep']} مش موجود — العربية {$row['plate']} من غير مندوب.");
            }

            $vehicle = Vehicle::updateOrCreate(
                ['plate' => $row['plate']],
                [
                    'kind' => $row['kind'] ?? '—',
                    'kind_en' => $row['kind_en'] ?? null,
                    'model_year' => $row['model_year'] ?? null,
                    'is_fridge' => (bool) ($row['is_fridge'] ?? false),
                    'rep_id' => $rep?->id,
                    'active' => true,
                ],
            );

            // ⚠️ العداد بيتحط **قبل** التسكين. `VehicleAssignment::assign()`
            // بترفض عداد أقل من الحالي، والعربية الجديدة عدادها صفر —
            // فلو سكّنّا الأول، الرقم الحقيقي كان هيعدّي، بس لو الأمر
            // اتشغّل تاني بعداد أقل كان هيترفض ويوقف من غير سبب واضح.
            if (($row['odometer'] ?? 0) > (int) $vehicle->odometer) {
                $vehicle->update(['odometer' => (int) $row['odometer'], 'odometer_at' => now()]);
            }

            if ($driver !== null) {
                // ⚠️ `null` مش `0`. الصفر الصريح مابيشغّلش الافتراضي
                // جوه `assign()`، فالعربية اللي عدادها وصل 52000 كانت
                // بترفض التسكين في التشغيلة التانية لأن 0 أقل منه.
                if ($err = VehicleAssignment::assign($vehicle, $driver, $row['odometer'] ?? null)) {
                    $this->warn("  ⚠️ {$row['plate']}: {$err}");
                }
            }

            $made++;
        }

        return $made;
    }
}
