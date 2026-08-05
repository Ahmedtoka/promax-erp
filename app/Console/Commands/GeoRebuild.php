<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Governorate;
use App\Models\Zone;
use App\Support\Governorates;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تثبيت المرجع الجغرافي من `database/data/geo.json` (شيت Governate.xlsx):
 *
 *   php artisan promax:geo            # ينفّذ فعلاً
 *   php artisan promax:geo --dry-run  # يعرض اللي هيحصل من غير ما يلمس حاجة
 *
 * بيعمل بالترتيب:
 *  1. أبسيرت الـ27 محافظة بالمفتاح الثابت (أسماء + ISO + عاصمة + إقليم + إحداثيات).
 *  2. أي محافظة دخيلة (زي «6 أكتوبر» اللي اتضافت غلط) — عملاؤها ومناطقها
 *     بيتنقلوا للجيزة والصف بيتمسح. غير كده بيتبلّغ عنها من غير لمس.
 *  3. دمج الزونات المكررة (Z65-2→KA-034، Z670→KA-029): كل المراجع بتتنقل
 *     والزون المكرر بيتمسح.
 *  4. أبسيرت الـ362 منطقة بالكود (اسمين + محافظة + نوع + إحداثيات).
 *  5. إعادة محاذاة العملاء: `clients.governorate` من محافظة الزون بتاعهم.
 *
 * ⚠️ الأمر آمن يتعاد أي وقت — أبسيرت مش إنشاء، وجوه ترانزاكشن واحدة.
 */
class GeoRebuild extends Command
{
    protected $signature = 'promax:geo {--dry-run : عرض من غير تنفيذ}';

    protected $description = 'تثبيت المحافظات والمناطق والإحداثيات من المرجع الجغرافي';

    /** الجداول اللي فيها zone_id — بتتلم كلها عند دمج زون في زون */
    private const ZONE_REF_TABLES = ['clients', 'users', 'client_requests', 'leads', 'journey_plans'];

    public function handle(): int
    {
        $path = database_path('data/geo.json');

        if (! is_file($path)) {
            $this->error("مفيش ملف $path");

            return self::FAILURE;
        }

        $geo = json_decode((string) file_get_contents($path), true);

        if (! is_array($geo) || empty($geo['governorates']) || empty($geo['zones'])) {
            $this->error('ملف geo.json بايظ أو ناقص');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');

        try {
            DB::transaction(function () use ($geo, $dry) {
                $this->syncGovernorates($geo['governorates']);
                $this->retireRogueGovernorates($geo['governorates']);
                $this->mergeZones($geo['merges'] ?? []);
                $this->syncZones($geo['zones']);
                $this->realignClients();

                if ($dry) {
                    // الاستثناء بيفرقع الترانزاكشن كلها — التقرير اتطبع
                    // والداتا رجعت زي ما كانت
                    throw new \RuntimeException('__dry_run__');
                }
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() !== '__dry_run__') {
                throw $e;
            }

            $this->warn('دراي رن — مفيش حاجة اتحفظت.');

            return self::SUCCESS;
        }

        Governorates::flush();
        $this->info('تمام — المرجع الجغرافي اتثبت.');

        return self::SUCCESS;
    }

    private function syncGovernorates(array $rows): void
    {
        $created = $updated = 0;

        foreach ($rows as $g) {
            $gov = Governorate::firstOrNew(['key' => $g['key']]);
            $gov->exists ? $updated++ : $created++;
            $gov->fill([
                'name' => $g['name'],
                'name_en' => $g['name_en'],
                'iso_code' => $g['iso'],
                'capital' => $g['capital'],
                'capital_en' => $g['capital_en'],
                'region' => $g['region'],
                'region_en' => $g['region_en'],
                'lat' => $g['lat'],
                'lng' => $g['lng'],
                'sort' => $g['sort'],
                'active' => true,
            ])->save();
        }

        $this->info("محافظات: $created جديدة، $updated اتحدثت.");
    }

    /**
     * محافظات موجودة في الداتابيز ومش في المرجع الرسمي — «6 أكتوبر»
     * (اتضافت غلط كمحافظة وهي مدينة تحت الجيزة) بتتشال وعملاؤها
     * ومناطقها بيتنقلوا للجيزة. أي دخيلة تانية بنبلّغ عنها بس.
     */
    private function retireRogueGovernorates(array $official): void
    {
        $keys = array_column($official, 'key');

        foreach (Governorate::whereNotIn('key', $keys)->get() as $rogue) {
            $isOctober = str_contains($rogue->name, 'كتوبر')
                || str_contains(mb_strtolower($rogue->name_en ?? ''), 'october')
                || str_contains($rogue->key, 'october');

            if (! $isOctober) {
                $this->warn("محافظة دخيلة سيبتها زي ما هي: {$rogue->name} ({$rogue->key}) — راجعها بإيدك.");

                continue;
            }

            $clients = Client::where('governorate', $rogue->key)->update(['governorate' => 'giza']);
            $zones = Zone::where('governorate', $rogue->key)->update(['governorate' => 'giza']);
            $rogue->delete();
            $this->info("«{$rogue->name}» اتشالت كمحافظة — $clients عميل و $zones منطقة اتنقلوا للجيزة.");
        }
    }

    /** دمج زون مكرر في الأصلي: كل المراجع بتتنقل وبعدين المكرر بيتمسح */
    private function mergeZones(array $merges): void
    {
        foreach ($merges as $m) {
            $from = Zone::where('code', $m['from'])->first();
            $into = Zone::where('code', $m['into'])->first();

            if (! $from) {
                continue; // اتدمج قبل كده — الأمر بيتعاد بأمان
            }

            if (! $into) {
                $this->warn("دمج {$m['from']} اتلغى — {$m['into']} مش موجود.");

                continue;
            }

            $moved = 0;

            foreach (self::ZONE_REF_TABLES as $table) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, 'zone_id')) {
                    $moved += DB::table($table)->where('zone_id', $from->id)->update(['zone_id' => $into->id]);
                }
            }

            if (Schema::hasTable('zone_user')) {
                // المندوب اللي متسكن على الزونين — صف المكرر بيتمسح مش بيتنقل
                // عشان unique(zone_id, user_id) مايضربش
                $dupUsers = DB::table('zone_user')->where('zone_id', $into->id)->pluck('user_id');
                DB::table('zone_user')->where('zone_id', $from->id)->whereIn('user_id', $dupUsers)->delete();
                $moved += DB::table('zone_user')->where('zone_id', $from->id)->update(['zone_id' => $into->id]);
            }

            $from->delete();
            $this->info("«{$m['from']}» اندمج في «{$m['into']}» — $moved مرجع اتنقل.");
        }
    }

    private function syncZones(array $rows): void
    {
        $created = $updated = 0;

        foreach ($rows as $z) {
            $zone = Zone::firstOrNew(['code' => $z['code']]);
            $zone->exists ? $updated++ : $created++;
            $zone->fill([
                'name' => $z['name'],
                'name_en' => $z['name_en'],
                'governorate' => $z['gov'],
                'type' => $z['type'],
                'lat' => $z['lat'],
                'lng' => $z['lng'],
                'active' => true,
            ])->save();
        }

        $this->info("مناطق: $created جديدة، $updated اتحدثت.");

        $codes = array_column($rows, 'code');
        $orphans = Zone::whereNotIn('code', $codes)->withCount('clients')->get();

        foreach ($orphans as $o) {
            $this->warn("منطقة مش في المرجع: {$o->code} «{$o->name}» ({$o->clients_count} عميل) — سيبتها، راجعها بإيدك.");
        }
    }

    /** محافظة العميل لازم تبقى محافظة الزون بتاعه — مصدر واحد للحقيقة */
    private function realignClients(): void
    {
        $fixed = 0;

        Client::whereNotNull('zone_id')->with('zone:id,governorate')->chunkById(500, function ($chunk) use (&$fixed) {
            foreach ($chunk as $c) {
                $gov = $c->zone?->governorate;

                if ($gov && $c->governorate !== $gov) {
                    $c->updateQuietly(['governorate' => $gov]);
                    $fixed++;
                }
            }
        });

        $this->info("عملاء اتظبطت محافظتهم من الزون: $fixed.");
    }
}
