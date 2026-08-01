<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Channel;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Warehouse;
use App\Models\Zone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * ═══════════════════════════════════════════════════════════════
 * الفريق الحقيقي + فرع المعادي + عربيات مودرن تريد
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **آمن يتشغّل أكتر من مرة.** كله `updateOrCreate` بمفتاح ثابت
 * (كود اليوزر، كود الزون، رقم اللوحة). السيدر اللي بيعمل صفوف جديدة
 * في كل تشغيلة بيملى الداتابيز نسخ ومحدش يعرف مين الصح.
 *
 * ⚠️ **الباسورد مابيتغيّرش لليوزر الموجود.** لو حد غيّر باسورده،
 * إعادة تشغيل السيدر مالازمش ترجّعه للمبدئي وتقفل عليه.
 *
 * كلمة السر بتتولّد عشوائي وبتتطبع في آخر التشغيل
 */
class ModernTradeSeeder extends Seeder
{
    // ⚠️ **الباسورد بقى عشوائي لكل تشغيلة.** كان `promax123` ثابت
    // ومكتوب في README المرفوع على الجت.
    private static ?string $pw = null;

    /**
     * عربيات مودرن تريد — 3 عربيات، كل واحدة بمناطقها.
     *
     * ⚠️ سامح **سائق ومندوب** في نفس الوقت (عربية واحدة براجل واحد)،
     * والتانيتين مندوب + سواق. عشان كده `driver` بيساوي `rep` عنده.
     */
    private const VANS = [
        [
            'plate' => 'رج ا 9161',
            'kind' => 'GMC ربع نقل ثلاجة',
            'kind_en' => 'GMC quarter-ton refrigerated',
            'fridge' => true,
            'rep' => ['سامح عبدالله', 'Sameh Abdallah', 'sameh', 'SLS-101'],
            // نفس الراجل بيسوق — مفيش سواق منفصل
            'driver' => null,
            'zones' => [
                ['MT-01', 'مصر الجديدة', 'Heliopolis'],
                ['MT-02', 'مدينة نصر', 'Nasr City'],
                ['MT-03', 'شبرا ووسط البلد', 'Shubra & Downtown'],
                ['MT-04', 'المهندسين', 'Mohandessin'],
                ['MT-05', 'الدقي', 'Dokki'],
                ['MT-06', 'الزمالك', 'Zamalek'],
            ],
        ],
        [
            'plate' => 'رج ا 9159',
            'kind' => 'GMC ربع نقل ثلاجة',
            'kind_en' => 'GMC quarter-ton refrigerated',
            'fridge' => true,
            'rep' => ['مريم', 'Mariam', 'mariam.mt', 'SLS-102'],
            'driver' => ['محمد سويلم', 'Mohamed Soliman', 'soliman', 'DRV-101'],
            'zones' => [
                ['MT-07', 'التجمع الخامس', 'Fifth Settlement'],
                ['MT-08', 'الرحاب والتجمع الأول', 'Rehab & First Settlement'],
                ['MT-09', 'مدينتي', 'Madinaty'],
                ['MT-10', 'الشروق والمستقبل', 'Shorouk & Mostakbal'],
                ['MT-11', 'العاشر من رمضان', 'Tenth of Ramadan'],
                ['MT-12', 'العبور', 'Obour'],
            ],
        ],
        [
            'plate' => 'رط د 8582',
            'kind' => 'شيفروليه ربع نقل صندوق',
            'kind_en' => 'Chevrolet quarter-ton box',
            'fridge' => false,
            'rep' => ['محمد خطاب', 'Mohamed Khattab', 'khattab', 'SLS-103'],
            'driver' => ['صبحي محمد', 'Sobhy Mohamed', 'sobhy', 'DRV-102'],
            'zones' => [
                ['MT-13', 'أكتوبر', 'Sixth of October'],
                ['MT-14', 'الشيخ زايد', 'Sheikh Zayed'],
                ['MT-15', 'حدائق الأهرام', 'Haram Gardens'],
                ['MT-16', 'المقطم', 'Mokattam'],
                ['MT-17', 'المعادي', 'Maadi'],
                ['MT-18', 'الهرم وفيصل', 'Haram & Faisal'],
            ],
        ],
    ];

    public function run(): void
    {
        $this->blockOnProduction();

        $branch = $this->maadiBranch();
        $channel = $this->modernTradeChannel();

        $this->headOffice($channel);
        $this->branchManager($branch);

        $zoneCount = 0;
        foreach (self::VANS as $van) {
            $zoneCount += $this->van($van, $branch, $channel);
        }

        $this->promoter($branch, $channel);

        $this->command->info('✅ الفريق اتعمل:');
        $this->command->info('   • '.User::count().' يوزر');
        $this->command->info('   • '.Vehicle::count().' عربية');
        $this->command->info("   • {$zoneCount} منطقة مودرن تريد");
        $this->command->info('   • الباسورد للكل: '.self::seedPassword());
    }

    // ═══════════════════════ الفرع ═══════════════════════

    private function maadiBranch(): Branch
    {
        $branch = Branch::updateOrCreate(
            ['code' => 'MAADI'],
            [
                'name' => 'فرع المعادي',
                'name_en' => 'Maadi Branch',
                'address' => 'المعادي، القاهرة',
                'active' => true,
            ],
        );

        // ⚠️ مخزن المعادي **موجود خلاص** بكود `MAADI` من
        // `WarehouseSeeder` (وعليه 30 رف وباتشات مرصّفة). بنربطه
        // بالفرع مش بنعمل مخزن موازي — التاني هيبقى فاضي والبضاعة
        // هتفضل في الأصلي والشاشتين هيرقموا مختلف.
        // ⚠️ **الربط بس** — من غير `name`. الكتابة فوق الاسم بتغيّره
        // في كل تشغيلة، والمخزن ده عليه 30 رف وباتشات مرصّفة واسمه
        // معروف للناس.
        $warehouse = Warehouse::firstOrNew(['code' => 'MAADI']);

        $warehouse->fill([
            'branch_id' => $branch->id,
            'type' => Warehouse::TYPE_BRANCH,
            'active' => true,
        ] + ($warehouse->exists ? [] : [
            'name' => 'مخزن فرع المعادي',
            'name_en' => 'Maadi branch warehouse',
        ]))->save();

        $this->command->info("   • {$branch->name} + {$warehouse->name}");

        return $branch;
    }

    /**
     * القنوات الأربعة — كي أكاونت، أونلاين، كاش فان، جملة.
     *
     * ⚠️ **الأربعة كلهم بيتعملوا مش الكي أكاونت بس.** لما كان بيعمل
     * واحدة، فورم العميل كان بيفتح بقايمة قنوات فيها اختيار واحد،
     * والمستخدم مش لاقي «كاش فان» فبيسيب الخانة فاضية — والعميل
     * بيتحفظ من غير قناة ومن غير خصم.
     *
     * ⚠️ **الأسماء مابتتكتبش فوق الموجود.** القنوات دي بتتعدّل من
     * `/erp/channels`، وإعادة تشغيل السيدر مالازمش ترجّع اسم اتغيّر.
     *
     * ⚠️ القناة مالهاش نسبة خصم — النسبة لكل عميل على حدة.
     */
    private function modernTradeChannel(): Channel
    {
        foreach (Channel::DEFAULTS as $code => [$name, $nameEn, $color]) {
            $channel = Channel::firstOrNew(['code' => $code]);

            $channel->fill([
                'color' => $color,
                'active' => true,
            ] + ($channel->exists ? [] : [
                'name' => $name,
                'name_en' => $nameEn,
            ]))->save();

            // الاسم الإنجليزي بيتملّى للقنوات القديمة اللي مالهاش واحد
            if (blank($channel->name_en)) {
                $channel->update(['name_en' => $nameEn]);
            }
        }

        $this->command->info('   • '.count(Channel::DEFAULTS).' قنوات');

        // مودرن تريد = الكي أكاونت في تسمية السيستم
        return Channel::where('code', Channel::KEY_ACCOUNT)->firstOrFail();
    }

    // ═══════════════════════ اليوزرات ═══════════════════════

    /**
     * إنشاء أو تحديث يوزر.
     *
     * ⚠️ الباسورد بيتحط **للجديد بس**. تحديث باسورد يوزر موجود في كل
     * تشغيلة بيرجّعه للمبدئي وبيقفل على اللي غيّره.
     */
    private function user(string $code, array $attrs): User
    {
        // ⚠️ البحث بالكود **أو الإيميل**. سيدر تاني ممكن يكون عمل
        // نفس الشخص بكود مختلف، والبحث بالكود لوحده بيوصل لـ
        // `User::create` وبيقع على «Duplicate entry» في الإيميل
        // ويوقّف السيدر كله في النص.
        $existing = User::where('code', $code)
            ->orWhere('email', $attrs['email'] ?? '__none__')
            ->first();

        if ($existing) {
            // ⚠️ **ممنوع نلمس الإيميل والحالة والرول.** الإيميل هو
            // اسم الدخول — الكتابة فوقه بتقفل على صاحبه من غير أي
            // رسالة، و`active` بترجّع موظف موقوف للخدمة، و`role`
            // بتلغي أي ترقية اتعملت من الشاشة.
            $safe = $attrs;
            unset($safe['email'], $safe['active'], $safe['role']);

            $existing->update($safe);

            return $existing;
        }

        return User::create($attrs + [
            'code' => $code,
            'password' => Hash::make(self::seedPassword()),
        ]);
    }

    private function headOffice(Channel $channel): void
    {
        // الأدمن — كل حاجة
        $this->user('ADM-001', [
            'name' => 'أدمن السيستم',
            'name_en' => 'System Admin',
            'email' => 'admin@promax.local',
            'role' => 'admin',
            'active' => true,
            'locale' => 'en',
            // ⚠️ من غير فرع = مركزي = بيشوف كل الفروع
            'branch_id' => null,
        ]);

        // مدير القنوات — بيشوف الشركة كلها
        $manager = $this->user('CHM-001', [
            'name' => 'مدير القنوات',
            'name_en' => 'Channel Manager',
            'email' => 'manager@promax.local',
            'role' => 'manager',
            'active' => true,
            'locale' => 'en',
            'branch_id' => null,
        ]);

        // ⚠️ `sync` مش `attach` — إعادة التشغيل بـ attach بتكرّر الصف
        $manager->channels()->sync(Channel::pluck('id')->all());
    }

    private function branchManager(Branch $branch): void
    {
        $user = $this->user('BRM-001', [
            'name' => 'مدير فرع المعادي',
            'name_en' => 'Maadi Branch Manager',
            'email' => 'maadi@promax.local',
            'role' => 'branch_manager',
            'branch_id' => $branch->id,
            'active' => true,
            'locale' => 'en',
        ]);

        $branch->update(['manager_id' => $user->id]);
    }

    // ═══════════════════════ العربيات ═══════════════════════

    /** @return int عدد المناطق اللي اتعملت */
    private function van(array $van, Branch $branch, Channel $channel): int
    {
        [$repName, $repEn, $repMail, $repCode] = $van['rep'];

        $rep = $this->user($repCode, [
            'name' => $repName,
            'name_en' => $repEn,
            'email' => $repMail.'@promax.local',
            'role' => 'sales_agent',
            'channel_id' => $channel->id,
            'branch_id' => $branch->id,
            'active' => true,
            'locale' => 'en',
        ]);

        $driver = $rep;   // الافتراضي: المندوب بيسوق

        if ($van['driver'] !== null) {
            [$dName, $dEn, $dMail, $dCode] = $van['driver'];

            $driver = $this->user($dCode, [
                'name' => $dName,
                'name_en' => $dEn,
                'email' => $dMail.'@promax.local',
                'role' => 'driver',
                'channel_id' => $channel->id,
                'branch_id' => $branch->id,
                'active' => true,
                'locale' => 'en',
            ]);
        }

        Vehicle::updateOrCreate(
            ['plate' => $van['plate']],
            [
                'kind' => $van['kind'],
                'kind_en' => $van['kind_en'],
                'is_fridge' => $van['fridge'],
                'branch_id' => $branch->id,
                'rep_id' => $rep->id,
                'driver_id' => $driver->id,
                'active' => true,
            ],
        );

        // ═══ مناطق العربية ═══
        $zoneIds = [];

        foreach ($van['zones'] as $i => [$code, $name, $nameEn]) {
            // ⚠️ المحافظة بتتحط للمنطقة اللي **مالهاش واحدة** بس. لو حد
            // ظبّطها بإيده من الشاشة، إعادة تشغيل السيدر مالازمش ترجّعها
            // للتخمين وتدوس على تصحيحه.
            $existingGov = Zone::where('code', $code)->value('governorate');

            $zone = Zone::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'name_en' => $nameEn,
                    'branch_id' => $branch->id,
                    'governorate' => $existingGov
                        ?: \App\Support\Governorates::guessFromZone($name, $nameEn),
                    'active' => true,
                ],
            );

            $zoneIds[] = $zone->id;

            // أول منطقة = زون المندوب الافتراضي — **لو مالوش واحد**.
            // الكتابة فوقه بترجّع المندوب لمنطقته الأولى كل تشغيلة.
            if ($i === 0) {
                if ($rep->zone_id === null) {
                    $rep->update(['zone_id' => $zone->id]);
                }

                if ($driver->id !== $rep->id && $driver->zone_id === null) {
                    $driver->update(['zone_id' => $zone->id]);
                }
            }
        }

        // ⚠️ `syncWithoutDetaching` مش `sync` — المدير ممكن يكون
        // زوّد للمندوب منطقة من `/ops/assignments`، وإعادة تشغيل
        // السيدر بـ `sync` بتلغيها في صمت.
        $rep->zones()->syncWithoutDetaching($zoneIds);

        if ($driver->id !== $rep->id) {
            $driver->zones()->syncWithoutDetaching($zoneIds);
        }

        return count($zoneIds);
    }

    // ═══════════════════════ البروموتر ═══════════════════════

    /**
     * المنسق = البروموتر.
     *
     * بيروح فروع الكي أكاونت، يعد الرف والمخزن، يصوّر قبل وبعد،
     * يقفل الزيارة، ويطلب توريد للناقص.
     */
    private function promoter(Branch $branch, Channel $channel): void
    {
        $this->user('PRM-001', [
            'name' => 'منسق الرفوف',
            'name_en' => 'Shelf Merchandiser',
            'email' => 'promoter@promax.local',
            'role' => 'promoter',
            'channel_id' => $channel->id,
            'branch_id' => $branch->id,
            'active' => true,
            'locale' => 'en',
        ]);
    }

    /**
     * ⚠️ **الحارس ده هو الفرق بين تيست وكارثة.**
     * السيدرز دي بتعمل `admin@promax.local` بباسورد معروف ومكتوب في
     * README المرفوع على الجت. `php artisan db:seed --force` على
     * اللايف — سطر واحد بيتكتب بالغلط أو بيتنسخ من دليل قديم —
     * بيفتح باب خلفي على السيستم الشغّال.
     *
     * التشغيل على production لازم يبقى قرار صريح:
     *     PROMAX_ALLOW_SEED=1 php artisan db:seed --force
     */
    private function blockOnProduction(): void
    {
        if (! app()->environment('production') || env('PROMAX_ALLOW_SEED') === '1') {
            return;
        }

        throw new \RuntimeException(
            'السيدر ده بيعمل حسابات ديمو بباسورد معروف، وممنوع يشتغل على production. '
            .'الفريق الحقيقي بيتعمل بـ`php artisan promax:team:setup`. '
            .'لو متأكد إنك عايزه: PROMAX_ALLOW_SEED=1 php artisan db:seed --force'
        );
    }

    /**
     * باسورد عشوائي واحد للتشغيلة دي، بيتطبع في الترمينال.
     *
     * ⚠️ ثابت جوه التشغيلة الواحدة (`static`) عشان كل الحسابات تاخد
     * نفس الباسورد وتقدر تدخل بيه، ومختلف كل مرة عشان مايتكتبش في
     * أي ملف ولا يتحفظ في أي دليل.
     */
    protected static function seedPassword(): string
    {
        if (static::$pw !== null) {
            return static::$pw;
        }

        $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';

        for ($i = 0; $i < 10; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return static::$pw = $out;
    }
}
