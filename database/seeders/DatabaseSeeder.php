<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->blockOnProduction();

        $this->call([
            PromaxImportSeeder::class,  // الداتا الحقيقية: زونز، منتجات، مخزون، عملاء، عقود، كشوف حساب
            ChannelSeeder::class,       // القنوات الأربعة + تصنيف العملاء عليها
            ClientGroupSeeder::class,   // السلاسل (Circle K...) + إحداثيات العملاء
            TeamSeeder::class,          // فريق العمل بالرولز والقنوات
            FieldDaySeeder::class,      // يوم شغل: عهدة، زيارات، فواتير، أوامر توريد
            MerchandisingSeeder::class, // زيارة بروموتر + طلب ريفيل
            Gs1CatalogueSeeder::class,  // باركود GS1 + الأسماء الرسمية + مدة الصلاحية
            BatchSeeder::class,         // باتشات افتتاحية بتواريخ صلاحية من المخزون الحالي
            WarehouseSeeder::class,     // المصنع + فرع المعادي + الأرفف + ترصيف الباتشات
            ContractsSeeder::class,     // العقود الموقّعة وبنودها المصنّفة
            ModernTradeSeeder::class,   // الفروع + العربيات + فريق مودرن تريد الحقيقي
            EnglishNamesSeeder::class,  // أسماء إنجليزية للباقي (لازم يبقى آخر واحد)
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
}
