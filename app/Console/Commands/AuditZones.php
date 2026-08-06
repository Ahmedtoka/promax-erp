<?php

namespace App\Console\Commands;

use App\Models\Zone;
use App\Support\Governorates;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * مراجعة المناطق — ضد المرجع الجغرافي الرسمي (geo.json)
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **المناطق بتتلوث من مصدرين:** الشيتات القديمة المكتوبة بإيد ناس
 * مختلفة («Noarth Coast»، الإسكندرية بأربع أسماء)، واستيراد العملاء
 * اللي بيعمل منطقة جديدة بأي اسم مش لاقيه («ستون بارك - القاهرة
 * الجديدة»، «الشروق (يحتاج تأكيد)»). المرجع الوحيد للحقيقة هو
 * `database/data/geo.json` — الـ362 منطقة بأكوادها وأسماءها الرسمية.
 *
 * المطابقة بالترتيب (إعادة كتابة 2026-08-06):
 *  1. تطابق تام مع اسم رسمي (عربي أو إنجليزي، بعد التطبيع).
 *  2. الأسماء البديلة المعروفة (ALIASES) — أخطاء الشيتات التاريخية.
 *  3. الاحتواء: الاسم الرسمي جوه اسم المنطقة («التجمع الخامس -
 *     التسعين الشمالي» → «التجمع الخامس») — بس لو مرشح واحد محدد،
 *     لو أكتر من مرشح بنبلّغ ومابنلمسش.
 *
 * التنفيذ لكل منطقة متطابقة:
 *  · نفس الصف هو حامل الكود الرسمي  ← تصحيح الاسمين والمحافظة.
 *  · فيه صف تاني بالكود الرسمي      ← دمج فيه (كل المراجع بتتنقل).
 *  · مفيش حامل للكود الرسمي         ← الصف ده «يتبنّى» الكود والأسماء
 *    الرسمية — علشان promax:geo بعد كده يلاقيه بالكود ومايعملش نسخة
 *    تانية بنفس الاسم.
 *
 * التشغيل:
 *   promax:zones            تقرير بس — مفيش أي كتابة
 *   promax:zones --fix      إعادة التسمية والتبنّي (من غير دمج)
 *   promax:zones --merge    الدمج كمان: المراجع بتتنقل والمكرر بيتمسح
 */
class AuditZones extends Command
{
    protected $signature = 'promax:zones {--fix} {--merge}';

    protected $description = 'مراجعة المناطق ضد المرجع الجغرافي: تسمية غلط، مكرر، لغة مخلوطة — وإصلاحها';

    /** الجداول اللي فيها zone_id — بتتلم كلها عند الدمج (زي promax:geo) */
    private const ZONE_REF_TABLES = ['clients', 'users', 'client_requests', 'leads', 'journey_plans'];

    /**
     * أخطاء الشيتات التاريخية: البديل المطبّع ⇒ الاسم العربي الرسمي.
     * الاسم الرسمي لازم يبقى موجود في geo.json — لو مش موجود البديل
     * بيتساب والمنطقة بتتبلّغ «مش في المرجع».
     */
    private const ALIASES = [
        'noarth coast' => 'الساحل الشمالي',
        'north coast' => 'الساحل الشمالي',
        'ساحل' => 'الساحل الشمالي',
        'alex' => 'الإسكندرية',
        'اسكندريه' => 'الإسكندرية',
        'tagamou3' => 'التجمع الخامس',
        'tagamou' => 'التجمع الخامس',
        'تجمع' => 'التجمع الخامس',
        'اكتوبر' => 'السادس من أكتوبر',
        'october' => 'السادس من أكتوبر',
        '6th of october' => 'السادس من أكتوبر',
        '6th of october city' => 'السادس من أكتوبر',
        'zayed' => 'الشيخ زايد',
        'سخنه' => 'العين السخنة',
        'sokhna' => 'العين السخنة',
        'عاشر' => 'العاشر من رمضان',
        '10th of ramadan' => 'العاشر من رمضان',
        'obour city' => 'العبور',
        'mokkatam' => 'المقطم',
        'elkatamya' => 'القطامية',
        'ismallia' => 'الإسماعيلية',
        'el mahala' => 'المحلة الكبرى',
        'محله' => 'المحلة الكبرى',
        'assuit' => 'أسيوط',
        'fayioum road' => 'طريق الفيوم',
        'autostourad' => 'الأوتوستراد',
        'mehwar elshahed' => 'محور الشهيد',
        'mehwar elmoshier' => 'محور المشير',
        'sharm elsheikh' => 'شرم الشيخ',
        'kafr elsheikh' => 'كفر الشيخ',
        'mostakbal' => 'مدينة المستقبل',
        'مستقبل' => 'مدينة المستقبل',
        'شروق والمستقبل' => 'الشروق',
        'رحاب والتجمع الاول' => 'الرحاب',
        'gouna' => 'الجونة',
        'منوفيه' => 'المنوفية',
        // فروع كيو ماركت (2026-08-06) — حي البساتين هو Z668 في المرجع
        'بساتين الشرقيه' => 'البساتين - عزبة جبريل',
        'عزبه فهمي قسم البساتين' => 'البساتين - عزبة جبريل',
    ];

    /** @var array<string, array{code: string, name: string, name_en: ?string, gov: string, type: ?string, lat: ?float, lng: ?float}> بالكود */
    private array $reference = [];

    /** @var array<string, string> الاسم المطبّع (عربي/إنجليزي) ⇒ الكود */
    private array $byName = [];

    /**
     * @var array<string, string> نفس byName لكن لمطابقة الاحتواء بس —
     * من غير زونات «محافظة/عام». «البساتين الشرقية» كانت بتندمج في
     * «الشرقية» (المحافظة!) لمجرد إن الكلمة جوه الاسم (باج 2026-08-06).
     * زون المحافظة بيتطابق بالتطابق التام والأسماء البديلة بس.
     */
    private array $containable = [];

    public function handle(): int
    {
        if (! $this->loadReference()) {
            return self::FAILURE;
        }

        $zones = Zone::withCount('clients')->get();

        if ($zones->isEmpty()) {
            $this->warn('  مفيش مناطق.');

            return self::SUCCESS;
        }

        // كل منطقة بتتحكم عليها: كود رسمي أو null (مش في المرجع)
        $matched = [];        // zone_id => canonical code
        $how = [];            // zone_id => طريقة المطابقة (للتقرير)

        foreach ($zones as $z) {
            [$code, $method] = $this->match($z);

            if ($code !== null) {
                $matched[$z->id] = $code;
                $how[$z->id] = $method;
            }
        }

        // التجميع بالكود الرسمي — بيحدد مين يتسمى ومين يندمج في مين
        $plan = $this->plan($zones, $matched);

        $this->reportUnknown($zones, $matched);
        $this->reportPlan($plan, $how);
        $this->reportLanguage($zones);

        if ($this->option('fix') || $this->option('merge')) {
            $this->apply($plan, applyMerges: (bool) $this->option('merge'));
        } else {
            $this->newLine();
            $this->line('  💡 ده تقرير بس. التصحيح والتبنّي: <fg=yellow>--fix</> · مع الدمج: <fg=yellow>--merge</>');
        }

        return self::SUCCESS;
    }

    // ═══════════════════════════════════════════════════════════
    //  المرجع
    // ═══════════════════════════════════════════════════════════

    private function loadReference(): bool
    {
        $path = database_path('data/geo.json');

        if (! is_file($path)) {
            $this->error("مفيش ملف $path — المرجع الجغرافي مش موجود.");

            return false;
        }

        $geo = json_decode((string) file_get_contents($path), true);

        if (! is_array($geo) || empty($geo['zones'])) {
            $this->error('ملف geo.json بايظ أو من غير مناطق.');

            return false;
        }

        foreach ($geo['zones'] as $z) {
            $this->reference[$z['code']] = $z;
            $this->byName[$this->norm($z['name'])] = $z['code'];

            if (! empty($z['name_en'])) {
                // الأولوية للعربي — الإنجليزي مايكسبش لو اتسجل قبله عربي
                $en = $this->norm($z['name_en']);
                $this->byName[$en] ??= $z['code'];
            }

            if (($z['type'] ?? null) !== 'محافظة/عام') {
                $this->containable[$this->norm($z['name'])] = $z['code'];

                if (! empty($z['name_en'])) {
                    $this->containable[$this->norm($z['name_en'])] ??= $z['code'];
                }
            }
        }

        $this->line('  المرجع: '.count($this->reference).' منطقة رسمية من geo.json');

        return true;
    }

    /** تطبيع اسم للمقارنة: صغير، من غير ال التعريف، همزات موحدة */
    private function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = str_replace(['أ', 'إ', 'آ'], 'ا', $s);
        $s = str_replace('ة', 'ه', $s);
        $s = str_replace('ى', 'ي', $s);
        $s = preg_replace('/[()\x{FF08}\x{FF09}]/u', ' ', $s) ?? $s;
        $s = preg_replace('/[\s\-_.،,\/]+/u', ' ', $s) ?? $s;
        $s = trim($s);
        $s = preg_replace('/^(ال)/u', '', $s) ?? $s;

        return trim($s);
    }

    /**
     * المنطقة دي مين في المرجع؟
     *
     * @return array{0: ?string, 1: string} [الكود، طريقة المطابقة]
     */
    private function match(Zone $z): array
    {
        $candidates = array_filter([(string) $z->name, (string) $z->name_en]);

        // الكود نفسه رسمي؟ — أوثق مطابقة على الإطلاق
        if (isset($this->reference[$z->code])) {
            return [$z->code, 'بالكود'];
        }

        // 1) تطابق تام
        foreach ($candidates as $c) {
            $n = $this->norm($c);

            if ($n !== '' && isset($this->byName[$n])) {
                return [$this->byName[$n], 'تطابق تام'];
            }
        }

        // 2) الأسماء البديلة المعروفة
        foreach ($candidates as $c) {
            $alias = self::ALIASES[$this->norm($c)] ?? null;

            if ($alias !== null && isset($this->byName[$this->norm($alias)])) {
                return [$this->byName[$this->norm($alias)], 'اسم بديل'];
            }
        }

        // 3) الاحتواء: اسم رسمي جوه اسم المنطقة — مرشح واحد محدد بس.
        //    ⚠️ أقل من 4 حروف بيلقط صدف («حي»، «مصر») — مستبعد.
        $hits = [];

        foreach ($candidates as $c) {
            $n = $this->norm($c);

            if (mb_strlen($n) < 4) {
                continue;
            }

            foreach ($this->containable as $refName => $code) {
                if (mb_strlen($refName) >= 4 && str_contains($n, $refName)) {
                    $hits[$code] = max($hits[$code] ?? 0, mb_strlen($refName));
                }
            }
        }

        if ($hits !== []) {
            arsort($hits);
            $codes = array_keys($hits);

            // مرشحين بنفس طول المطابقة = التباس — بلاش تخمين
            if (count($codes) === 1 || $hits[$codes[0]] > $hits[$codes[1]]) {
                return [$codes[0], 'احتواء'];
            }
        }

        return [null, ''];
    }

    // ═══════════════════════════════════════════════════════════
    //  الخطة: مين يتسمى، مين يتبنّى الكود، مين يندمج في مين
    // ═══════════════════════════════════════════════════════════

    /**
     * @return array{renames: list<array{zone: Zone, ref: array}>,
     *               adoptions: list<array{zone: Zone, ref: array}>,
     *               merges: list<array{loser: Zone, survivor: Zone}>}
     */
    private function plan($zones, array $matched): array
    {
        $renames = $adoptions = $merges = [];

        // ⚠️ preserveKeys — من غيرها groupBy بترمي مفاتيح المصفوفة
        // (أرقام الزونات) وبنجمع بـ[0,1,..] فالخطة بتطلع غلط في صمت
        foreach (collect($matched)->groupBy(fn ($code) => $code, preserveKeys: true) as $code => $group) {
            $ref = $this->reference[$code];
            $members = $zones->whereIn('id', array_keys($group->all()))->values();

            // الناجي: حامل الكود الرسمي لو موجود، وإلا صاحب أكبر عدد عملاء
            $survivor = $members->firstWhere('code', $code)
                ?? $members->sortByDesc('clients_count')->first();

            if ($survivor->code === $code) {
                // اسمه أو محافظته ممكن يكونوا محتاجين تصحيح
                if ($survivor->name !== $ref['name']
                    || $survivor->name_en !== ($ref['name_en'] ?? null)
                    || $survivor->governorate !== $ref['gov']) {
                    $renames[] = ['zone' => $survivor, 'ref' => $ref];
                }
            } else {
                $adoptions[] = ['zone' => $survivor, 'ref' => $ref];
            }

            foreach ($members as $m) {
                if ($m->id !== $survivor->id) {
                    $merges[] = ['loser' => $m, 'survivor' => $survivor];
                }
            }
        }

        return ['renames' => $renames, 'adoptions' => $adoptions, 'merges' => $merges];
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
        $this->warn('  ❓ مش في المرجع ('.$unknown->count().') — مش هتتلمس، راجعها بإيدك أو زوّد المرجع/الأسماء البديلة:');

        foreach ($unknown as $z) {
            $this->line("     · {$z->code}: {$z->name} / {$z->name_en} ({$z->clients_count} عميل)");
        }
    }

    private function reportPlan(array $plan, array $how): void
    {
        $this->newLine();
        $this->line('  ── التسمية والتصحيح ──');

        if ($plan['renames'] === [] && $plan['adoptions'] === []) {
            $this->line('     ✓ الأسماء كلها مطابقة للمرجع');
        }

        foreach ($plan['renames'] as $r) {
            $z = $r['zone'];
            $this->line("     ✎ {$z->code}: «{$z->name}» ← «{$r['ref']['name']}» / «{$r['ref']['name_en']}» (".($how[$z->id] ?? '')                .')');
        }

        foreach ($plan['adoptions'] as $a) {
            $z = $a['zone'];
            $this->line("     ⇢ «{$z->name}» ({$z->code}, {$z->clients_count} عميل) هيتبنّى الكود الرسمي {$a['ref']['code']} «{$a['ref']['name']}» (".($how[$z->id] ?? '').')');
        }

        $this->newLine();
        $this->line('  ── الدمج ──');

        if ($plan['merges'] === []) {
            $this->line('     ✓ مفيش تكرار');

            return;
        }

        foreach ($plan['merges'] as $m) {
            $this->line("     ⇒ «{$m['loser']->name}» ({$m['loser']->code}, {$m['loser']->clients_count} عميل) هتندمج في «{$m['survivor']->name}» ({$m['survivor']->code})");
        }
    }

    private function reportLanguage($zones): void
    {
        $this->newLine();
        $this->line('  ── اللغة ──');
        $n = 0;

        foreach ($zones as $z) {
            $issues = [];

            if (preg_match('/[a-z]/i', (string) $z->name)) {
                $issues[] = 'الاسم العربي فيه إنجليزي';
            }
            if (preg_match('/[\x{0600}-\x{06FF}]/u', (string) $z->name_en)) {
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

        $this->line($n === 0 ? '     ✓ اللغة نضيفة' : "     {$n} منطقة لغتها مخلوطة — المتطابقة بتتصلح مع --fix");
    }

    // ═══════════════════════════════════════════════════════════
    //  التنفيذ
    // ═══════════════════════════════════════════════════════════

    private function apply(array $plan, bool $applyMerges): void
    {
        $this->newLine();
        $this->line('  ── التنفيذ ──');

        DB::transaction(function () use ($plan, $applyMerges) {
            foreach ($plan['renames'] as $r) {
                $this->applyRef($r['zone'], $r['ref'], adoptCode: false);
                $this->line("     ✓ {$r['zone']->code}: اتسمت «{$r['ref']['name']}»");
            }

            foreach ($plan['adoptions'] as $a) {
                $this->applyRef($a['zone'], $a['ref'], adoptCode: true);
                $this->line("     ✓ «{$a['ref']['name']}» تبنّت الكود الرسمي {$a['ref']['code']}");
            }

            if (! $applyMerges) {
                return;
            }

            foreach ($plan['merges'] as $m) {
                $this->mergeInto($m['loser'], $m['survivor']);
                $this->line("     ✓ «{$m['loser']->name}» ({$m['loser']->code}) اندمجت في «{$m['survivor']->name}» ({$m['survivor']->code})");
            }
        });

        if (! $applyMerges && $plan['merges'] !== []) {
            $this->warn('     ⚠ فيه '.count($plan['merges']).' دمج مستني — شغّل --merge علشان ينفذ.');
        }

        $this->info('     ✓ خلص.');
    }

    /** كتابة بيانات المرجع على الصف — الإحداثيات بتتحدث لو المرجع أدق */
    private function applyRef(Zone $z, array $ref, bool $adoptCode): void
    {
        $z->update(array_filter([
            'code' => $adoptCode ? $ref['code'] : null,
            'name' => $ref['name'],
            'name_en' => $ref['name_en'] ?? null,
            'governorate' => $ref['gov'],
            'type' => $ref['type'] ?? null,
            'lat' => $ref['lat'] ?? null,
            'lng' => $ref['lng'] ?? null,
        ], fn ($v) => $v !== null));
    }

    /**
     * دمج منطقة في الرسمية — كل المراجع بتتنقل وبعدين المكررة بتتمسح.
     *
     * ⚠️ نفس جداول promax:geo بالظبط + `zone_user` بحماية الـUNIQUE —
     * المسح من غير النقل بيسيب nullOnDelete يفضّي مناطق العملاء في صمت.
     */
    private function mergeInto(Zone $loser, Zone $survivor): void
    {
        foreach (self::ZONE_REF_TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'zone_id')) {
                DB::table($table)->where('zone_id', $loser->id)->update(['zone_id' => $survivor->id]);
            }
        }

        if (Schema::hasTable('zone_user')) {
            $dupUsers = DB::table('zone_user')->where('zone_id', $survivor->id)->pluck('user_id');
            DB::table('zone_user')->where('zone_id', $loser->id)->whereIn('user_id', $dupUsers)->delete();
            DB::table('zone_user')->where('zone_id', $loser->id)->update(['zone_id' => $survivor->id]);
        }

        $loser->delete();
    }
}
