<?php

namespace Database\Seeders;

use App\Models\Channel;
use App\Models\Client;
use Illuminate\Database\Seeder;

/**
 * القنوات الأربعة + تصنيف العملاء عليها أوتوماتيك
 */
class ChannelSeeder extends Seeder
{
    /** كلمات بتدل على سلسلة هايبر/ماركت (كي أكاونت — chain) */
    private const CHAIN_KEYWORDS = [
        'Gourrmet', 'Seoudi', 'Metro', 'Kazyon', 'Spinneys', 'Carrefour',
        'Ragab', 'Hyper', 'Zahran', 'Kheir Zaman', 'Fresh Food', 'Oscar',
        'Bounjour', 'Flamingo', 'Exception Market', 'Grab and Go',
    ];

    /** كلمات بتدل على كونفينيانس/محطة بنزين (كي أكاونت — convenience) */
    private const CONVENIENCE_KEYWORDS = [
        'Circle K', 'On The Run', 'Speerr', 'KWAK', 'Traffic', 'Grease Car',
        'Master On The Go', 'Way to go', 'Pickup',
    ];

    /** قنوات أونلاين */
    private const ONLINE_NAMES = [
        'Rabbit', 'Amazon Agent', 'Talabat', 'Breadfast', 'Instashop',
    ];

    /** جملة / تريدنج */
    private const WHOLESALE_KEYWORDS = [
        'Gomla', 'Trading', 'Gen. Trading', 'Group', 'Best Way', 'Clean Source',
        'Al Massa', 'Aloush', 'Magdy', 'Farouk',
    ];

    public function run(): void
    {
        $channels = $this->seedChannels();
        $this->classifyClients($channels);
    }

    /** @return array<string, Channel> */
    private function seedChannels(): array
    {
        $out = [];
        foreach (Channel::DEFAULTS as $code => [$name, $nameEn, $color]) {
            $out[$code] = Channel::updateOrCreate(
                ['code' => $code],
                ['name' => $name, 'name_en' => $nameEn, 'color' => $color, 'active' => true],
            );
        }
        $this->command->info('   • '.count($out).' قنوات');

        return $out;
    }

    /** @param  array<string, Channel>  $channels */
    private function classifyClients(array $channels): void
    {
        $counts = [];

        Client::query()->chunkById(100, function ($clients) use ($channels, &$counts) {
            foreach ($clients as $client) {
                [$code, $sub] = $this->classify($client);

                $client->channel_id = $channels[$code]->id;
                $client->sub_channel = $sub;
                // ⚠️ `uses_channel_discount` مهجور — القناة مابقاش لها
                // نسبة. سايبينه على قيمته عشان الداتا القديمة.
                $client->saveQuietly();

                $key = $code.($sub ? "/$sub" : '');
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        });

        foreach ($counts as $key => $n) {
            $this->command->info("   • $key: $n عميل");
        }
    }

    /** @return array{0: string, 1: ?string} */
    private function classify(Client $client): array
    {
        $name = $client->name;

        // القنوات الداخلية (كاش فان الشركة نفسها)
        if ($client->category === 'internal' && ! $this->matchesAny($name, self::ONLINE_NAMES)) {
            if ($this->matchesAny($name, self::CONVENIENCE_KEYWORDS)) {
                return [Channel::KEY_ACCOUNT, 'convenience'];
            }

            return [Channel::CASH_VAN, null];
        }

        // أونلاين
        if ($this->matchesAny($name, self::ONLINE_NAMES)) {
            return [Channel::ONLINE, null];
        }

        // كونفينيانس ومحطات
        if ($this->matchesAny($name, self::CONVENIENCE_KEYWORDS)) {
            return [Channel::KEY_ACCOUNT, 'convenience'];
        }

        // سلاسل هايبر وماركت
        if ($this->matchesAny($name, self::CHAIN_KEYWORDS)) {
            return [Channel::KEY_ACCOUNT, 'chain'];
        }

        // جملة
        if ($this->matchesAny($name, self::WHOLESALE_KEYWORDS)) {
            return [Channel::WHOLESALE, null];
        }

        // الباقي كاش فان
        return [Channel::CASH_VAN, null];
    }

    private function matchesAny(string $name, array $keywords): bool
    {
        foreach ($keywords as $k) {
            if (stripos($name, $k) !== false) {
                return true;
            }
        }

        return false;
    }
}
