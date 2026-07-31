<?php

namespace Database\Seeders;

use App\Models\Channel;
use App\Models\Client;
use App\Models\ClientGroup;
use Illuminate\Database\Seeder;

/**
 * تجميع الفروع في سلاسل — Circle K وجورميه وبونجور... إلخ
 * القاعدة: أي كلمة مفتاحية بتظهر في اسم أكتر من عميل = سلسلة
 */
class ClientGroupSeeder extends Seeder
{
    /** [اسم السلسلة، الكلمة المفتاحية، القناة، القسم] */
    private const GROUPS = [
        ['Circle K', 'Circle K', Channel::KEY_ACCOUNT, 'convenience'],
        ['Gourrmet Egypt', 'Gourrmet', Channel::KEY_ACCOUNT, 'chain'],
        ['Bounjour Market', 'Bounjour', Channel::KEY_ACCOUNT, 'chain'],
        ['On The Run', 'On The Run', Channel::KEY_ACCOUNT, 'convenience'],
        ['Rabbit', 'Rabbit', Channel::ONLINE, null],
        ['Aloush Group', 'Aloush', Channel::WHOLESALE, null],
    ];

    /** إحداثيات تقريبية لمناطق القاهرة — للخريطة */
    private const AREA_COORDS = [
        'مدينة نصر' => [30.0566, 31.3450],
        'التجمع' => [30.0080, 31.4300],
        'القاهرة الجديدة' => [30.0080, 31.4300],
        'مصر الجديدة' => [30.0880, 31.3280],
        'هليوبوليس' => [30.0900, 31.3250],
        'مدينتي' => [30.1050, 31.6200],
        'المعادي' => [29.9600, 31.2580],
        'المقطم' => [30.0100, 31.3050],
        'الزمالك' => [30.0620, 31.2200],
        'وسط البلد' => [30.0450, 31.2380],
        'جاردن سيتي' => [30.0350, 31.2300],
    ];

    public function run(): void
    {
        $made = 0;
        $linked = 0;

        foreach (self::GROUPS as [$name, $keyword, $channelCode, $sub]) {
            $matches = Client::where('name', 'like', "%$keyword%")->get();

            // سلسلة = فرعين فأكتر
            if ($matches->count() < 2) {
                continue;
            }

            $channel = Channel::where('code', $channelCode)->first();

            $group = ClientGroup::updateOrCreate(
                ['code' => ClientGroup::nextCode($name)],
                [
                    'name' => $name,
                    'channel_id' => $channel?->id,
                    'sub_channel' => $sub,
                    'discount' => 0,
                    'uses_group_discount' => false,
                    'active' => true,
                ],
            );
            $made++;

            foreach ($matches as $client) {
                $client->group_id = $group->id;
                $client->saveQuietly();
                $linked++;
            }
        }

        $this->geocodeClients();

        $this->command->info("   • $made سلسلة، $linked فرع مربوط");
    }

    /** إحداثيات تقريبية من العنوان — عشان الخريطة تشتغل */
    private function geocodeClients(): void
    {
        $n = 0;

        Client::whereNull('lat')->chunkById(100, function ($clients) use (&$n) {
            foreach ($clients as $client) {
                $coords = $this->guessCoords($client);
                if ($coords === null) {
                    continue;
                }

                // نبعثر الفروع شوية عشان مايبقوش فوق بعض على الخريطة
                $jitter = (($client->id % 20) - 10) / 1000;

                $client->lat = $coords[0] + $jitter;
                $client->lng = $coords[1] + $jitter * 1.4;
                $client->saveQuietly();
                $n++;
            }
        });

        $this->command->info("   • $n عميل اتحطلهم إحداثيات تقريبية");
    }

    private function guessCoords(Client $client): ?array
    {
        foreach (self::AREA_COORDS as $area => $coords) {
            if (str_contains((string) $client->address, $area)) {
                return $coords;
            }
        }

        // الافتراضي وسط القاهرة
        return [30.0444, 31.2357];
    }
}
