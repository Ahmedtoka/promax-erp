<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
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
}
