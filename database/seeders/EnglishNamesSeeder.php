<?php

namespace Database\Seeders;

use App\Models\Channel;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\Product;
use App\Models\Zone;
use Illuminate\Database\Seeder;

/**
 * بيملا عمود name_en للداتا الموجودة عشان النسخة الإنجليزية
 * ماتبقاش فاضية من أول يوم.
 *
 * Fills name_en for existing records so the English UI has real
 * names from day one.
 *
 * المصطلحات مأخوذة من سكيل promax-i18n — لو ضفت منتج أو زون جديد
 * ضيف ترجمته هنا كمان.
 *
 * السيدر idempotent — بيعدّي على اللي متملي بالفعل ومش بيدوسه.
 */
class EnglishNamesSeeder extends Seeder
{
    /** القنوات الأربعة */
    private const CHANNELS = [
        Channel::KEY_ACCOUNT => 'Key Account',
        Channel::ONLINE => 'Online',
        Channel::CASH_VAN => 'Cash Van',
        Channel::WHOLESALE => 'Wholesale',
    ];

    /** الزونز — أسماء المناطق بالإنجليزي زي ما بتتكتب على الخرايط */
    private const ZONES = [
        'Z1' => 'Nasr City & New Cairo',
        'Z2' => 'Heliopolis & Madinaty',
        'Z3' => 'Maadi & Mokattam',
        'Z4' => 'Downtown & Zamalek',
    ];

    /** عائلات المنتج → البادئة الإنجليزية */
    private const FAMILY_PREFIX = [
        'promax_bar' => 'PROMAX Bar',
        'promax_cup' => 'PROMAX Cup',
        'spreads' => 'PRO Spread',
        'pmx_bar' => 'PMX Bar',
    ];

    /** النكهات — الكلمة العربية → الإنجليزي */
    private const FLAVOURS = [
        'الشيكولاتة' => 'Chocolate',
        'شيكولاتة' => 'Chocolate',
        'شكولاته' => 'Chocolate',
        'التوت الازرق' => 'Blueberry',
        'توت' => 'Berry',
        'القهوة' => 'Coffee',
        'قهوة' => 'Coffee',
        'بالقهوة' => 'Coffee',
        'جوز الهند' => 'Coconut',
        'فول السودانى' => 'Peanut',
        'فول السوداني' => 'Peanut',
        'كوكيز اند كريم' => 'Cookies & Cream',
        'كوكيز' => 'Cookies',
        'فستق' => 'Pistachio',
        'بستاشيو دبى' => 'Dubai Pistachio',
        'بستاشيو' => 'Pistachio',
        'تمر' => 'Dates',
        'موز' => 'Banana',
        'فانليا' => 'Vanilla',
        'بالعثل' => 'Honey',
        'بوينو' => 'Bueno',
        'بروتين' => 'Protein',
    ];

    /** الوحدات */
    private const UNITS = [
        'بار 70جم' => '70g Bar',
        'بار 60جم' => '60g Bar',
        'كوب' => 'Cup',
        'برطمان' => 'Jar',
        'كرتونة' => 'Case',
        'قطعة' => 'Piece',
    ];

    public function run(): void
    {
        $this->channels();
        $this->zones();
        $this->products();
        $this->groupsAndClients();

        $this->command->info('   ✅ الأسماء الإنجليزية اتملت');
    }

    private function channels(): void
    {
        $n = 0;
        foreach (self::CHANNELS as $code => $en) {
            $n += Channel::where('code', $code)
                ->whereNull('name_en')
                ->update(['name_en' => $en]);
        }
        $this->command->info("   • $n قناة");
    }

    private function zones(): void
    {
        $n = 0;
        foreach (self::ZONES as $code => $en) {
            $n += Zone::where('code', $code)
                ->whereNull('name_en')
                ->update(['name_en' => $en]);
        }
        $this->command->info("   • $n زون");
    }

    private function products(): void
    {
        $n = 0;

        // الـ orWhere لازم يتلف في مجموعة، وإلا شرط chunkById (id > X)
        // بيتلزق على الفرع التاني بس والـ chunk بيلف على نفسه
        Product::where(fn ($q) => $q->whereNull('name_en')->orWhereNull('unit_en'))
            ->chunkById(100, function ($products) use (&$n) {
                foreach ($products as $product) {
                    $changes = [];

                    if (blank($product->name_en)) {
                        $changes['name_en'] = $this->translateProduct($product);
                    }
                    if (blank($product->unit_en) && filled($product->unit)) {
                        $changes['unit_en'] = self::UNITS[trim($product->unit)]
                            ?? $this->translateUnit($product->unit);
                    }

                    if ($changes) {
                        $product->forceFill($changes)->saveQuietly();
                        $n++;
                    }
                }
            });

        $this->command->info("   • $n منتج");
    }

    /** اسم المنتج = عائلته + نكهته */
    private function translateProduct(Product $product): string
    {
        $prefix = self::FAMILY_PREFIX[$product->family] ?? 'PROMAX';
        $flavour = $this->matchFlavour($product->name);

        return $flavour === null ? $prefix : "$prefix — $flavour";
    }

    /** بندوّر على أطول نكهة مطابقة الأول عشان "كوكيز اند كريم" ماتبقاش "كوكيز" */
    private function matchFlavour(string $name): ?string
    {
        $found = null;
        $foundLength = 0;

        foreach (self::FLAVOURS as $ar => $en) {
            if (mb_strpos($name, $ar) !== false && mb_strlen($ar) > $foundLength) {
                $found = $en;
                $foundLength = mb_strlen($ar);
            }
        }

        // "بروتين" لوحدها مش نكهة — بس لو مفيش غيرها نسيبها
        return $found;
    }

    private function translateUnit(string $unit): string
    {
        foreach (self::UNITS as $ar => $en) {
            if (mb_strpos($unit, $ar) !== false) {
                return $en;
            }
        }

        // الوحدات فيها أوزان بالأرقام — بنحوّل "جم" لـ g
        return trim(str_replace(['جم', 'بار', 'مل'], ['g', 'Bar', 'ml'], $unit));
    }

    /**
     * أسماء السلاسل والعملاء أصلاً مكتوبة بالإنجليزي في الداتا الحقيقية
     * (Circle K، Gourrmet، Rabbit…) فبننسخها زي ما هي.
     * اللي اسمه عربي بيفضل name_en فاضي والـ fallback هيعرضه عربي.
     */
    private function groupsAndClients(): void
    {
        $groups = ClientGroup::whereNull('name_en')->get()
            ->filter(fn ($g) => $this->isLatin($g->name));

        foreach ($groups as $group) {
            $group->forceFill(['name_en' => $group->name])->saveQuietly();
        }

        $clients = 0;
        Client::whereNull('name_en')->chunkById(200, function ($rows) use (&$clients) {
            foreach ($rows as $client) {
                if (! $this->isLatin($client->name)) {
                    continue;
                }
                $client->forceFill(['name_en' => $client->name])->saveQuietly();
                $clients++;
            }
        });

        $this->command->info("   • {$groups->count()} سلسلة و $clients عميل");
    }

    /** الاسم مكتوب بحروف لاتينية؟ */
    private function isLatin(string $name): bool
    {
        return preg_match('/[A-Za-z]/', $name) === 1
            && preg_match('/[\x{0600}-\x{06FF}]/u', $name) === 0;
    }
}
