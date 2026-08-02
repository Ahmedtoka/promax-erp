<?php

namespace App\Console\Commands;

use App\Models\Zone;
use App\Support\Governorates;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * مراجعة المناطق — ضد مرجع جغرافي مصري صحيح
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **المناطق جت من 24 شيت مكتوبين بإيد ناس مختلفة** — فيها:
 * «Noarth Coast» و«North Coast» منطقتين، والإسكندرية بأربع أسماء
 * (Alexandria / Alex / اسكندرية / الاسكندرية)، و«6 أكتوبر» متسجلة
 * قاهرة، والسخنة قاهرة، والعبور قاهرة. المرجع اللي تحت هو الجغرافيا
 * الصح، والأمر بيقارن ويقول — ومايصلحش غير بأمرك.
 *
 * التشغيل:
 *   promax:zones            تقرير بس — مفيش أي كتابة
 *   promax:zones --fix      تصحيح المحافظات والأسماء (عربي + إنجليزي)
 *   promax:zones --merge    دمج المكرر: العملاء والمناديب بيتنقلوا
 *                           للمنطقة الأساسية والمكررة بتتمسح
 */
class AuditZones extends Command
{
    protected $signature = 'promax:zones {--fix} {--merge}';

    protected $description = 'مراجعة المناطق ضد المرجع الجغرافي: محافظات غلط، أسماء مكررة، لغة مخلوطة';

    /**
     * المرجع: المفتاح المطبّع ⇒ [الاسم العربي، الإنجليزي، المحافظة].
     *
     * ⚠️ **المفتاح الأول في كل مجموعة أسماء هو الأساسي** — الباقي
     * أسماء بديلة بتتحوّل ليه. المحافظات من الجغرافيا الفعلية مش من
     * الشيتات: أكتوبر والشيخ زايد والدقي جيزة، العبور قليوبية،
     * السخنة سويس، العاشر شرقية، الساحل مطروح.
     */
    private const REFERENCE = [
        // القاهرة
        'cairo_city' => ['القاهرة', 'Cairo', 'cairo', ['القاهرة', 'cairo']],
        'heliopolis' => ['مصر الجديدة', 'Heliopolis', 'cairo', ['heliopolis', 'مصر الجديدة']],
        'nasr_city' => ['مدينة نصر', 'Nasr City', 'cairo', ['nasr city', 'مدينة نصر']],
        'new_cairo' => ['القاهرة الجديدة', 'New Cairo', 'cairo',
            ['new cairo', 'tagamou3', 'tagamou', 'التجمع', 'التجمع الخامس', 'القاهرة الجديدة']],
        'rehab' => ['الرحاب', 'Rehab', 'cairo', ['rehab', 'الرحاب', 'الرحاب والتجمع الأول']],
        'madinaty' => ['مدينتي', 'Madinaty', 'cairo', ['madinaty', 'مدينتي']],
        'shorouk' => ['الشروق', 'Shorouk', 'cairo', ['shorouk', 'الشروق', 'الشروق والمستقبل']],
        'new_capital' => ['العاصمة الإدارية', 'New Capital', 'cairo', ['new capital', 'العاصمة الإدارية']],
        'maadi' => ['المعادي', 'Maadi', 'cairo', ['maadi', 'المعادي']],
        'katameya' => ['القطامية', 'Katameya', 'cairo', ['elkatamya', 'katameya', 'القطامية']],
        'mokattam' => ['المقطم', 'Mokattam', 'cairo', ['mokkatam', 'mokattam', 'المقطم']],
        'helwan' => ['حلوان', 'Helwan', 'cairo', ['helwan', 'حلوان']],
        'zamalek' => ['الزمالك', 'Zamalek', 'cairo', ['zamalek', 'الزمالك']],
        'downtown' => ['وسط البلد', 'Downtown', 'cairo', ['downtown', 'وسط البلد']],
        'abbasia' => ['العباسية', 'Abbasia', 'cairo', ['abbasia', 'العباسية']],
        'ring_road' => ['الطريق الدائري', 'Ring Road', 'cairo', ['ring road', 'الدائري']],
        'autostrad' => ['الأوتوستراد', 'Autostrad', 'cairo', ['autostourad', 'autostrad', 'الأوتوستراد']],
        'mehwar_shahed' => ['محور الشهيد', 'Mehwar El Shahed', 'cairo', ['mehwar elshahed', 'محور الشهيد']],
        'mehwar_moshir' => ['محور المشير', 'Mehwar El Moshir', 'cairo', ['mehwar elmoshier', 'محور المشير']],

        // الجيزة
        'giza_city' => ['الجيزة', 'Giza', 'giza', ['الجيزة', 'giza']],
        'october' => ['السادس من أكتوبر', '6th of October', 'giza',
            ['6th of october city', '6th of october', 'october', 'أكتوبر', 'السادس من أكتوبر']],
        'zayed' => ['الشيخ زايد', 'Sheikh Zayed', 'giza', ['zayed', 'sheikh zayed', 'الشيخ زايد']],
        'dokki' => ['الدقي', 'Dokki', 'giza', ['dokki', 'الدقي']],
        'fayoum_road' => ['طريق الفيوم', 'Fayoum Road', 'giza', ['fayioum road', 'fayoum road', 'طريق الفيوم']],

        // القليوبية والشرقية
        'obour' => ['العبور', 'Obour', 'qalyubia', ['obour city', 'obour', 'العبور']],
        'tenth_ramadan' => ['العاشر من رمضان', '10th of Ramadan', 'sharqia',
            ['10th of ramadan', 'العاشر من رمضان', 'العاشر']],
        'zagazig' => ['الزقازيق', 'Zagazig', 'sharqia', ['zagazig', 'الزقازيق']],
        'sharqia_gov' => ['الشرقية', 'Sharqia', 'sharqia', ['sharqia', 'الشرقية']],

        // الإسكندرية والساحل ومطروح
        'alexandria' => ['الإسكندرية', 'Alexandria', 'alexandria',
            ['alexandria', 'alex', 'اسكندرية', 'الاسكندرية', 'الإسكندرية']],
        'north_coast' => ['الساحل الشمالي', 'North Coast', 'matrouh',
            ['north coast', 'noarth coast', 'الساحل الشمالي', 'الساحل']],
        'matrouh_city' => ['مطروح', 'Matrouh', 'matrouh', ['matrouh', 'مطروح']],

        // الدلتا
        'mansoura' => ['المنصورة', 'Mansoura', 'dakahlia', ['mansoura', 'المنصورة']],
        'dakahlia_gov' => ['الدقهلية', 'Dakahlia', 'dakahlia', ['الدقهلية', 'dakahlia']],
        'tanta' => ['طنطا', 'Tanta', 'gharbia', ['tanta', 'طنطا']],
        'mahalla' => ['المحلة الكبرى', 'El Mahalla', 'gharbia', ['el mahala', 'el mahalla', 'المحلة', 'المحلة الكبرى']],
        'kafr_sheikh' => ['كفر الشيخ', 'Kafr El Sheikh', 'kafr_el_sheikh', ['kafr elsheikh', 'kafr el sheikh', 'كفر الشيخ']],
        'damietta_city' => ['دمياط', 'Damietta', 'damietta', ['damietta', 'دمياط']],

        // القناة وسيناء
        'ismailia_city' => ['الإسماعيلية', 'Ismailia', 'ismailia',
            ['ismailia', 'ismallia', 'الإسماعيلية', 'الاسماعيلية']],
        'port_said_city' => ['بورسعيد', 'Port Said', 'port_said', ['port said', 'بورسعيد']],
        'sokhna' => ['العين السخنة', 'Ain Sokhna', 'suez', ['sokhna', 'ain sokhna', 'السخنة', 'العين السخنة']],
        'sharm' => ['شرم الشيخ', 'Sharm El Sheikh', 'south_sinai',
            ['sharm elsheikh', 'sharm el sheikh', 'شرم الشيخ']],
        'south_sinai_gov' => ['جنوب سيناء', 'South Sinai', 'south_sinai', ['جنوب سيناء', 'south sinai']],

        // اللي ظهروا في أول تشغيل على اللايف
        'monufia_gov' => ['المنوفية', 'Monufia', 'monufia', ['المنوفية', 'monufia', 'منوفيه']],
        'mostakbal' => ['مدينة المستقبل', 'Mostakbal City', 'cairo',
            ['المستقبل', 'mostakbal', 'mostakbal city', 'مدينة المستقبل']],

        // البحر الأحمر والصعيد
        'gouna' => ['الجونة', 'El Gouna', 'red_sea', ['gouna', 'el gouna', 'الجونة']],
        'red_sea_gov' => ['البحر الأحمر', 'Red Sea', 'red_sea', ['البحر الاحمر', 'البحر الأحمر', 'red sea']],
        'asyut_city' => ['أسيوط', 'Asyut', 'asyut', ['assuit', 'asyut', 'أسيوط', 'اسيوط']],
    ];

    public function handle(): int
    {
        $zones = Zone::withCount('clients')->get();

        if ($zones->isEmpty()) {
            $this->warn('  مفيش مناطق.');

            return self::SUCCESS;
        }

        // مفتاح مرجعي لكل منطقة (لو اتعرفت)
        $matched = [];   // zone_id => ref_key

        foreach ($zones as $z) {
            foreach ([$z->name, $z->name_en] as $candidate) {
                if ($key = $this->refKey((string) $candidate)) {
                    $matched[$z->id] = $key;

                    break;
                }
            }
        }

        $this->reportUnknown($zones, $matched);
        $wrongGov = $this->reportGovernorates($zones, $matched);
        $langIssues = $this->reportLanguage($zones, $matched);
        $groups = $this->reportDuplicates($zones, $matched);

        if ($this->option('fix')) {
            $this->fix($zones, $matched);
        }

        if ($this->option('merge')) {
            $this->merge($zones, $matched, $groups);
        }

        if (! $this->option('fix') && ! $this->option('merge')) {
            $this->newLine();
            $this->line('  💡 ده تقرير بس. التصحيح: <fg=yellow>--fix</> · الدمج: <fg=yellow>--merge</> (اعمل fix الأول)');
        }

        return self::SUCCESS;
    }

    // ═══════════════════════════════════════════════════════════
    //  التطبيع والمطابقة
    // ═══════════════════════════════════════════════════════════

    /** تطبيع اسم للمقارنة: صغير، من غير ال التعريف، همزات موحدة */
    private function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = str_replace(['أ', 'إ', 'آ'], 'ا', $s);
        $s = str_replace('ة', 'ه', $s);
        $s = preg_replace('/^(ال)/u', '', $s) ?? $s;
        $s = preg_replace('/[\s\-_.]+/u', ' ', $s) ?? $s;

        return trim($s);
    }

    /** المنطقة دي مين في المرجع؟ */
    private function refKey(string $name): ?string
    {
        $n = $this->norm($name);

        if ($n === '') {
            return null;
        }

        foreach (self::REFERENCE as $key => [$ar, $en, $gov, $aliases]) {
            foreach ($aliases as $alias) {
                if ($this->norm($alias) === $n) {
                    return $key;
                }
            }
        }

        return null;
    }

    // ═══════════════════════════════════════════════════════════
    //  التقارير
    // ═══════════════════════════════════════════════════════════

    private function reportUnknown($zones, array $matched): void
    {
        $unknown = $zones->filter(fn ($z) => ! isset($matched[$z->id]));

        if ($unknown->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->warn('  ❓ مش في المرجع ('.$unknown->count().') — راجعهم بإيدك أو زوّدهم في REFERENCE:');

        foreach ($unknown as $z) {
            $this->line("     · {$z->code}: {$z->name} / {$z->name_en} ({$z->clients_count} عميل)");
        }
    }

    private function reportGovernorates($zones, array $matched): int
    {
        $this->newLine();
        $this->line('  ── المحافظات ──');
        $n = 0;

        foreach ($zones as $z) {
            $key = $matched[$z->id] ?? null;

            if ($key === null) {
                continue;
            }

            $correct = self::REFERENCE[$key][2];

            if ($z->governorate !== $correct) {
                $from = $z->governorate ? Governorates::label($z->governorate) : '—';
                $this->line("     ✗ {$z->name} ({$z->code}): {$from} ← المفروض ".Governorates::label($correct));
                $n++;
            }
        }

        $this->line($n === 0 ? '     ✓ كلها صح' : "     {$n} محافظة غلط");

        return $n;
    }

    private function reportLanguage($zones, array $matched): int
    {
        $this->newLine();
        $this->line('  ── اللغة ──');
        $n = 0;

        foreach ($zones as $z) {
            $issues = [];
            $nameHasLatin = (bool) preg_match('/[a-z]/i', (string) $z->name);
            $enHasArabic = (bool) preg_match('/[\x{0600}-\x{06FF}]/u', (string) $z->name_en);

            if ($nameHasLatin) {
                $issues[] = 'الاسم العربي فيه إنجليزي';
            }
            if ($enHasArabic) {
                $issues[] = 'الاسم الإنجليزي فيه عربي';
            }
            if (! $z->name_en) {
                $issues[] = 'من غير إنجليزي';
            }

            if ($issues !== []) {
                $this->line("     ✗ {$z->code}: «{$z->name}» / «{$z->name_en}» — ".implode('، ', $issues));
                $n++;
            }
        }

        $this->line($n === 0 ? '     ✓ اللغة نضيفة' : "     {$n} منطقة لغتها مخلوطة");

        return $n;
    }

    /** @return array<string, \Illuminate\Support\Collection> مجموعات المكرر */
    private function reportDuplicates($zones, array $matched): array
    {
        $this->newLine();
        $this->line('  ── المكرر ──');

        $groups = collect($matched)
            ->map(fn ($key, $zoneId) => ['key' => $key, 'zone' => $zones->firstWhere('id', $zoneId)])
            ->groupBy('key')
            ->filter(fn ($g) => $g->count() > 1)
            ->map(fn ($g) => $g->pluck('zone'));

        if ($groups->isEmpty()) {
            $this->line('     ✓ مفيش تكرار');

            return [];
        }

        foreach ($groups as $key => $group) {
            [$ar, $en] = self::REFERENCE[$key];
            $names = $group->map(fn ($z) => "{$z->name} ({$z->clients_count})")->join(' + ');
            $this->line("     ✗ {$ar} / {$en}: {$names}");
        }

        $this->line('     '.$groups->count().' منطقة متسجلة بأكتر من اسم');

        return $groups->all();
    }

    // ═══════════════════════════════════════════════════════════
    //  التصحيح
    // ═══════════════════════════════════════════════════════════

    private function fix($zones, array $matched): void
    {
        $this->newLine();
        $this->line('  ── التصحيح ──');
        $n = 0;

        DB::transaction(function () use ($zones, $matched, &$n) {
            foreach ($zones as $z) {
                $key = $matched[$z->id] ?? null;

                if ($key === null) {
                    continue;
                }

                [$ar, $en, $gov] = self::REFERENCE[$key];

                // ⚠️ الاسم العربي في `name` والإنجليزي في `name_en` —
                // من المرجع مباشرة، مش تنضيف نص الشيت.
                $dirty = [];

                if ($z->name !== $ar) {
                    $dirty['name'] = $ar;
                }
                if ($z->name_en !== $en) {
                    $dirty['name_en'] = $en;
                }
                if ($z->governorate !== $gov) {
                    $dirty['governorate'] = $gov;
                }

                if ($dirty !== []) {
                    $z->update($dirty);
                    $n++;
                }
            }
        });

        $this->info("     ✓ اتصلح: {$n} منطقة");
    }

    /**
     * دمج المكرر — العيال بتتنقل للأساسي والمكرر بيتمسح.
     *
     * ⚠️ **الأساسي = اللي عليه عملاء أكتر.** وكل حاجة بتشاور على
     * المكرر بتتنقل: العملاء، المناديب (العمود والجدول الوسيط)،
     * الليدز، وطلبات العملاء. المسح من غير النقل كان بيسيب
     * `nullOnDelete` يفضّي مناطق كل دول في صمت.
     */
    private function merge($zones, array $matched, array $groups): void
    {
        $this->newLine();
        $this->line('  ── الدمج ──');

        if ($groups === []) {
            $this->line('     مفيش حاجة تتدمج.');

            return;
        }

        DB::transaction(function () use ($groups) {
            foreach ($groups as $key => $group) {
                $survivor = $group->sortByDesc('clients_count')->first();
                $losers = $group->where('id', '!=', $survivor->id);

                foreach ($losers as $loser) {
                    DB::table('clients')->where('zone_id', $loser->id)->update(['zone_id' => $survivor->id]);
                    DB::table('users')->where('zone_id', $loser->id)->update(['zone_id' => $survivor->id]);
                    DB::table('leads')->where('zone_id', $loser->id)->update(['zone_id' => $survivor->id]);
                    DB::table('client_requests')->where('zone_id', $loser->id)->update(['zone_id' => $survivor->id]);

                    // الجدول الوسيط: انقل اللي مش موجود وامسح الباقي —
                    // النقل الأعمى بيكسر الـUNIQUE لو المندوب على الاتنين
                    $repIds = DB::table('zone_user')->where('zone_id', $loser->id)->pluck('user_id');

                    foreach ($repIds as $repId) {
                        DB::table('zone_user')->updateOrInsert(
                            ['zone_id' => $survivor->id, 'user_id' => $repId],
                        );
                    }

                    DB::table('zone_user')->where('zone_id', $loser->id)->delete();

                    $loser->delete();
                    $this->line("     ✓ «{$loser->name}» ({$loser->code}) اندمجت في «{$survivor->name}» ({$survivor->code})");
                }
            }
        });

        $this->info('     ✓ الدمج خلص — الأرقام كلها اتنقلت للمناطق الأساسية');
    }
}
