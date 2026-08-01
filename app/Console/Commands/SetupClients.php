<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\Contract;
use App\Models\Zone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * ═══════════════════════════════════════════════════════════════
 * promax:clients — السلاسل والمناطق والفروع من ملف الداتا
 * ═══════════════════════════════════════════════════════════════
 *
 * بيقرا `storage/app/data/clients_2026.json` (اللي بيطلّعه
 * `database/data/extract_clients.py` من شيتات العملاء) وبينزّل:
 *
 *   • 23 سلسلة تحت قناة الكي أكاونت
 *   • المناطق اللي في الشيتات، كل واحدة تحت محافظتها
 *   • 455 فرع كعملاء **كلهم موقوفين**
 *   • ملفات العقود متربطة بسلاسلها بالاسم
 *
 * ⚠️ **كل العملاء بيتعملوا `active = 0` عن قصد.** الداتا دي من
 * شيتات، ومحدش راجع كل صف فيها. عميل مفعّل معناه إنه بيبان للمندوب
 * في خط سيره وبيتباعله، فـ455 عميل مفعّل مرة واحدة معناه إن الفريق
 * هيلف على فروع مقفولة وعناوين غلط. التفعيل بيتم واحد واحد من شاشة
 * التفعيل بعد ما تتأكد من العميل.
 *
 * ⚠️ **الأمر ده مابيمسحش حاجة.** بيضيف ويحدّث بالكود. لو شغّلته
 * مرتين مش هيعمل نسخ تانية، وأي تعديل عملته من الشاشة على عميل
 * موجود **بيتساب زي ما هو** — بنملا الخانات الفاضية بس.
 */
class SetupClients extends Command
{
    protected $signature = 'promax:clients
        {--force : من غير تأكيد}
        {--contracts= : مسار مجلد ملفات العقود PDF}';

    protected $description = 'بينزّل السلاسل والمناطق وفروع العملاء من ملف الداتا';

    /** @var array<string, int> كود السلسلة ← id */
    private array $groups = [];

    /** @var array<string, int> اسم المنطقة ← id */
    private array $zones = [];

    private int $created = 0;
    private int $updated = 0;

    public function handle(): int
    {
        $this->newLine();
        $this->line('  ┌──────────────────────────────────────────┐');
        $this->line('  │  السلاسل والمناطق والعملاء               │');
        $this->line('  └──────────────────────────────────────────┘');
        $this->newLine();

        $data = $this->data();

        if ($data === null) {
            return self::FAILURE;
        }

        $chains = $data['chains'];
        $clients = $data['clients'];

        $existing = Client::whereIn('code', array_column($clients, 'code'))->count();

        $this->line('  السلاسل:        <fg=green>'.count($chains).'</>');
        $this->line('  الفروع:         <fg=green>'.count($clients).'</>');
        $this->line('  منهم موجود:     <fg=yellow>'.$existing.'</>  (هيتحدّث مش هيتكرر)');
        $this->line('  الحالة:         <fg=yellow>كلهم موقوفين</> — التفعيل من الشاشة');
        $this->newLine();

        if (! $this->option('force') && ! $this->confirm('نكمّل؟', false)) {
            $this->line('  اتلغى — مافيش حاجة اتغيّرت.');

            return self::SUCCESS;
        }

        // ⚠️ **ترانزاكشن واحدة.** لو وقع في نص الـ455، السيستم كان
        // هيفضل بنص السلاسل ونص المناطق وعملاء متربطين بمناطق
        // مالهاش سلاسل — وإعادة التشغيل مش بتنضّف ده.
        DB::transaction(function () use ($chains, $clients) {
            $this->buildGroups($chains);
            $this->buildZones($clients);
            $this->buildClients($clients);
        });

        if ($dir = $this->option('contracts')) {
            $this->newLine();
            $this->line('  ── ملفات العقود ──');
            $this->linkContracts($dir);
        }

        $this->report();

        return self::SUCCESS;
    }

    /** @return array{chains: array, clients: array}|null */
    private function data(): ?array
    {
        $path = storage_path('app/data/clients_2026.json');

        if (! is_file($path)) {
            $this->error('  ⛔ ملف الداتا مش موجود: storage/app/data/clients_2026.json');
            $this->line('     شغّل: python3 database/data/extract_clients.py <مجلد الشيتات>');

            return null;
        }

        $d = json_decode((string) file_get_contents($path), true);

        if (! is_array($d) || empty($d['clients']) || empty($d['chains'])) {
            $this->error('  ⛔ ملف الداتا فاضي أو شكله غلط.');

            return null;
        }

        // ⚠️ الكود المكرر بيخلّي `updateOrCreate` تدوس على الأول
        // بالتاني — فرع بيختفي في صمت والأمر بيقول «تمّ».
        $codes = array_column($d['clients'], 'code');

        if (count($codes) !== count(array_unique($codes))) {
            $this->error('  ⛔ فيه أكواد مكررة في ملف الداتا.');

            return null;
        }

        return $d;
    }

    // ═══════════════════════════════════════════════════════════
    //  السلاسل
    // ═══════════════════════════════════════════════════════════

    private function buildGroups(array $chains): void
    {
        // ⚠️ **السلسلة تجميعة بس.** مفيش خصم ولا مسؤول عليها — كل
        // فرع عميل مستقل بعقده وخصمه ومديره. (قرار 2026-08-01.)
        $channel = Channel::where('code', Channel::KEY_ACCOUNT)->value('id');

        foreach ($chains as $c) {
            $g = ClientGroup::firstOrNew(['code' => $c['code']]);

            // ⚠️ **الموجود مابيتدعسش.** لو حد عدّل اسم سلسلة من
            // الشاشة، إعادة تشغيل الأمر ماتردهوش للاسم الافتراضي.
            if (! $g->exists) {
                $g->fill([
                    'name' => $c['name_ar'],
                    'name_en' => $c['name_en'],
                    'channel_id' => $channel,
                    'sub_channel' => 'chain',
                    'active' => true,
                ])->save();
            }

            $this->groups[$c['code']] = $g->id;
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  المناطق
    // ═══════════════════════════════════════════════════════════

    private function buildZones(array $clients): void
    {
        // ⚠️ **المنطقة بتتعمل من الشيتات، ومابتتسكّنش لحد.** الفروع
        // منتشرة في مصر كلها والمناديب في القاهرة والجيزة بس؛
        // تسكين منطقة إسكندرية لمندوب مودرن تريد بيحطها في خط
        // سيره وهو مش رايح هناك أصلاً.
        $names = [];

        foreach ($clients as $c) {
            $area = trim((string) ($c['area'] ?? ''));

            if ($area === '' || mb_strlen($area) > 60) {
                continue;
            }

            $names[$this->zoneKey($area)] = ['name' => $area, 'gov' => $c['governorate'] ?? null];
        }

        // الموجود الأول — عشان مانعملش نسخة تانية لمنطقة متعرّفة
        foreach (Zone::all() as $z) {
            $this->zones[$this->zoneKey((string) $z->name)] = $z->id;
            if ($z->name_en) {
                $this->zones[$this->zoneKey((string) $z->name_en)] = $z->id;
            }
        }

        $n = Zone::count();

        foreach ($names as $key => $row) {
            if (isset($this->zones[$key])) {
                continue;
            }

            $n++;
            $z = Zone::create([
                'code' => 'KA-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT),
                'name' => $row['name'],
                'name_en' => $row['name'],
                'governorate' => $row['gov'],
                'active' => true,
            ]);

            $this->zones[$key] = $z->id;
        }
    }

    /** مفتاح مقارنة المناطق — بيشيل الهمزات والتاء المربوطة */
    private function zoneKey(string $s): string
    {
        $s = Str::lower(trim($s));
        $s = str_replace(['أ', 'إ', 'آ'], 'ا', $s);
        $s = str_replace(['ة', 'ى', 'ؤ', 'ئ'], ['ه', 'ي', 'و', 'ي'], $s);

        return preg_replace('/[^\p{L}\p{N}]/u', '', $s) ?? $s;
    }

    // ═══════════════════════════════════════════════════════════
    //  الفروع
    // ═══════════════════════════════════════════════════════════

    private function buildClients(array $clients): void
    {
        $channel = Channel::where('code', Channel::KEY_ACCOUNT)->value('id');

        foreach ($clients as $c) {
            $client = Client::firstOrNew(['code' => $c['code']]);
            $isNew = ! $client->exists;

            // ⚠️ **الاسم العربي هو اللي بيتعرض لو الإنجليزي فاضي.**
            // 108 فرع اسمهم عربي بس (السلاسل الصغيرة اللي شيتها
            // `Name | Address`). عميل من غير اسم في القايمة مالوش
            // معنى، فبنحط العربي في الاتنين لحد ما حد يكتب الإنجليزي.
            $ar = $c['name_ar'] ?: $c['name_en'];
            $en = $c['name_en'] ?: null;

            $payload = [
                'name' => $ar,
                'name_en' => $en,
                'channel_id' => $channel,
                'group_id' => $this->groups[$c['chain']] ?? null,
                'sub_channel' => 'chain',
                'zone_id' => $this->zones[$this->zoneKey((string) $c['area'])] ?? null,
                'governorate' => $c['governorate'] ?: null,
                // ⚠️ **`clients.address` عمود 255 حرف.** أطول عنوان في
                // الداتا دلوقتي 252 — يعني على بُعد 3 حروف من خطأ
                // «Data too long» اللي بيرمي الترانزاكشن كلها ويسيب
                // الاستيراد نص. القص هنا أأمن من الاعتماد على إن
                // الشيت مايتغيّرش.
                'address' => $c['address'] ? Str::limit($c['address'], 250) : null,
                'phone' => $c['phone'] ?: null,
                'location_url' => $c['location_url'] ?: null,
                // ⚠️ **موقوف.** ده مش تفصيلة — ده اللي بيمنع 455 فرع
                // مااتراجعش من الظهور في خطوط سير المناديب بكرة.
                'active' => false,
                'category' => 'idle',
            ];

            if ($isNew) {
                $client->fill($payload)->save();
                $this->created++;

                continue;
            }

            // ⚠️ **التحديث بيملا الفاضي بس.** لو حد ظبط عنوان أو
            // تليفون من الشاشة، إعادة تشغيل الأمر ماترجعوش لقيمة
            // الشيت. والحالة `active` مابتتلمسش خالص — عميل اتفعّل
            // مايترجعش موقوف لأن أمر استيراد اتشغّل تاني.
            $touched = false;

            foreach ($payload as $k => $v) {
                if ($k === 'active' || $k === 'category') {
                    continue;
                }

                if ($v !== null && blank($client->{$k})) {
                    $client->{$k} = $v;
                    $touched = true;
                }
            }

            if ($touched) {
                $client->save();
                $this->updated++;
            }
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  ملفات العقود
    // ═══════════════════════════════════════════════════════════

    private function linkContracts(string $dir): void
    {
        if (! is_dir($dir)) {
            $this->error("  ⛔ المجلد مش موجود: {$dir}");

            return;
        }

        $dest = storage_path('app/contracts');

        if (! is_dir($dest)) {
            mkdir($dest, 0775, true);
        }

        $matched = 0;
        $orphans = [];

        foreach (glob($dir.'/*.pdf') ?: [] as $pdf) {
            // ⚠️ **أسماء الملفات مكسّرة.** كلهم اتنزّلوا من المتصفح
            // فأسماؤهم `Circle K TMT.crdownload.pdf`، وخمسة اسمهم
            // `.crdownload.pdf` من غير أي اسم قبلها.
            $name = basename($pdf);
            $clean = trim(str_ireplace(['.crdownload', '.pdf'], '', $name));
            $clean = trim(preg_replace('/\(\d+\)/', '', $clean) ?? $clean);

            $group = $clean === '' ? null : $this->matchGroup($clean);

            if ($group === null) {
                $orphans[] = $name;

                continue;
            }

            $file = 'contracts/'.$group->code.'-'.date('YmdHis').'-'.substr(md5($name), 0, 6).'.pdf';

            if (! copy($pdf, storage_path('app/'.$file))) {
                $orphans[] = $name;

                continue;
            }

            // ⚠️ **عقد على السلسلة مش على فرع.** الملف واحد لكل
            // السلسلة، وربطه بفرع عشوائي كان هيخلّي باقي الفروع
            // تبان من غير عقد وهي متغطية.
            $contract = Contract::firstOrNew([
                'group_id' => $group->id,
                'client_id' => null,
            ]);

            if (! $contract->exists) {
                $contract->fill([
                    'number' => 'CTR-'.$group->code,
                    'chain' => $group->name,
                    'chain_en' => $group->name_en,
                    // ⚠️ مش مفعّل: البنود والنِسَب لسه مااتقريتش من
                    // الـPDF. عقد مفعّل بنِسَب فاضية بيدّي خصم صفر
                    // وكإنه متفق عليه.
                    'active' => false,
                ]);
            }

            $contract->file_path = $file;
            $contract->save();
            $matched++;
        }

        $this->line("     ✓ اتربط: {$matched}");

        if ($orphans !== []) {
            $this->newLine();
            $this->warn('     ⚠️ مااتربطش (اسم الملف مايدلش على سلسلة):');

            foreach ($orphans as $o) {
                $this->line("        · {$o}");
            }

            $this->line('        اربطهم بإيدك من كارت السلسلة.');
        }
    }

    /** أقرب سلسلة لاسم الملف */
    private function matchGroup(string $name): ?ClientGroup
    {
        $key = $this->zoneKey($name);

        foreach (ClientGroup::whereIn('id', array_values($this->groups))->get() as $g) {
            foreach ([$g->name_en, $g->name] as $cand) {
                $c = $this->zoneKey((string) $cand);

                if ($c !== '' && (str_contains($key, $c) || str_contains($c, $key))) {
                    return $g;
                }
            }
        }

        return null;
    }

    // ═══════════════════════════════════════════════════════════

    private function report(): void
    {
        $this->newLine();
        $this->line('  ── النتيجة ──');
        $this->line("     السلاسل:   ".ClientGroup::count());
        $this->line("     المناطق:   ".Zone::count());
        $this->line("     عملاء جدد: <fg=green>{$this->created}</>");

        if ($this->updated > 0) {
            $this->line("     اتحدّثوا:  <fg=yellow>{$this->updated}</>");
        }

        $off = Client::where('active', false)->count();
        $on = Client::where('active', true)->count();

        $this->newLine();
        $this->info("  ✅ {$on} عميل شغّال · {$off} مستني التفعيل");

        // ⚠️ تقرير المراجعة بيتقال هنا عشان محدش يفتكر إن الاستيراد
        // خلص وكل حاجة تمام — فيه 184 صف محتاج عين بشرية.
        $review = storage_path('app/data/clients_review.json');

        if (is_file($review)) {
            $n = count(json_decode((string) file_get_contents($review), true) ?: []);

            if ($n > 0) {
                $this->line("     ⚠️ {$n} صف محتاج مراجعة — storage/app/data/clients_review.json");
            }
        }

        $this->line('     فعّل العملاء من:  /erp/clients/activate');
        $this->newLine();
    }
}
