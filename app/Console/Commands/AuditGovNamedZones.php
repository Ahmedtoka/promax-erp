<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Zone;
use App\Support\Governorates;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * مناطق اسمها اسم محافظة  ·  ١٥ أغسطس ٢٠٢٦
 * ═══════════════════════════════════════════════════════════════
 *
 * بلاغ المالك: «عندنا عملاء موجودين في القاهرة والمنطقة قاهرة برضو —
 * المفروض القاهرة تحتيها مناطق».
 *
 * ═══ المشكلة ═══
 *
 * فيه مناطق اسمها **اسم محافظة** («Cairo» · «Alexandria») وعليها
 * عملاء مباشرة. ده خلط مستويين في الداتا نفسها: المحافظة حاوية،
 * والمنطقة هي الوحدة اللي المندوب بيمشي بيها. عميل تحت «القاهرة»
 * معناه عملياً «مش عارفين هو فين» — والمندوب مايقدرش يخطط خط سير
 * على محافظة كاملة.
 *
 * ═══ الأداة دي بتعمل إيه ═══
 *
 *   ١. بتلاقي المناطق اللي اسمها بيطابق اسم محافظة.
 *   ٢. لكل عميل تحتها، بتقرا **عنوانه** وتدوّر على اسم منطقة حقيقية
 *      موجودة كـ**كلمة كاملة** في العنوان.
 *   ٣. بتصنّف الاقتراح: **مؤكد** · **محتاج مراجعة** · **يدوي**.
 *
 * ⚠️ **مابتعملش مناطق جديدة.** بتنقل لمنطقة **موجودة** بس — إنشاء
 * مناطق من نص عنوان بيولّد مناطق وهمية بأسماء متكررة ومكتوبة غلط،
 * وتنضيفها بعدين أصعب من المشكلة الأصلية.
 *
 * ⚠️ **العميل اللي عنوانه مايدلّش على منطقة بيتساب مكانه** ويتكتب في
 * قايمة «محتاج تحديد يدوي». نقله لأقرب تخمين أسوأ من سيبانه: الأول
 * غلط مخفي، والتاني مشكلة ظاهرة.
 *
 *   php artisan promax:gov-zones                # تقرير
 *   php artisan promax:gov-zones --fix          # ينقل المؤكد بس
 *   php artisan promax:gov-zones --fix --cross  # + اللي محتاج مراجعة
 */
class AuditGovNamedZones extends Command
{
    protected $signature = 'promax:gov-zones
        {--fix   : نفّذ نقل المؤكد — من غيرها تقرير بس}
        {--cross : مع --fix، نفّذ كمان اللي محتاج مراجعة (نقل بين محافظات بعيدة)}';

    protected $description = 'مناطق اسمها اسم محافظة — وتوزيع عملائها على مناطق حقيقية';

    /**
     * القاهرة الكبرى وحدة تشغيلية واحدة — المندوب بيعدّي بين
     * التلاتة في نفس اليوم. فنقل عميل من «القاهرة» لمنطقة في
     * الجيزة (٦ أكتوبر · الدقي · الهرم) **مؤكد مش مراجعة**، لأن
     * الجلوس تحت «القاهرة» معناه «مش مصنّف» أصلاً.
     */
    private const METRO = ['cairo', 'giza', 'qalyubia'];

    /**
     * الكلمات اللي بتخلّي اللي بعدها **اسم شارع مش اسم منطقة**.
     *
     * ⚠️ ده أهم حارس في الأداة. «شارع الرياض» في مدينة نصر كان
     * بينقل العميل لمنطقة «الرياض» في كفر الشيخ، و«طريق أبو قير»
     * في رشدي كان بينقله لـ«أبو قير»، و«شارع العريش» في الهرم كان
     * بينقله لـ«العريش» في شمال سيناء. أسماء المناطق بتتكرر
     * كأسماء شوارع في محافظات تانية أكتر بكتير مما تتوقع.
     */
    private const STREET_WORDS = [
        'شارع', 'ش', 'طريق', 'محور', 'ميدان', 'تقاطع', 'كوبري', 'كورنيش',
        'برج', 'عماره', 'مول', 'محطه', 'بوابه', 'مسجد', 'ناصيه', 'مدرسه',
        'نادي', 'كمبوند', 'فرع', 'امام', 'بجوار', 'خلف', 'st', 'street',
        'rd', 'road', 'sq', 'square', 'mall', 'tower',
    ];

    public function handle(): int
    {
        $fix = (bool) $this->option('fix');
        $cross = (bool) $this->option('cross');

        // ═══ ١. المناطق اللي اسمها اسم محافظة ═══
        //
        // ⚠️ `Governorates::match()` بتعمل **مطابقة تامة** للاسم
        // (عربي أو إنجليزي أو المفتاح) وبترجّع مفتاح المحافظة أو
        // `null`. استخدمتها بدل ما أقرا القاموس الخام: `rows()`
        // خاصة عن قصد، وفتحها كان معناه إن أي كود يقدر يلعب في
        // المرجع الجغرافي من بره.
        //
        // ⚠️ **مطابقة تامة مش احتواء** — «مدينة نصر» فيها «نصر» بس
        // مش محافظة، و«Port Said Road» مش بورسعيد. الاحتواء كان
        // هيرشّح مناطق سليمة للنقل.
        $govOf = fn ($z) => Governorates::match((string) $z->name)
            ?? Governorates::match((string) $z->name_en);

        $all = Zone::withCount('clients')->get();
        $bad = $all->filter(fn ($z) => $govOf($z) !== null);

        if ($bad->isEmpty()) {
            $this->info('مفيش منطقة اسمها اسم محافظة. ✅');

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('  مناطق اسمها اسم محافظة: '.$bad->count());
        $this->line('');

        // ═══ ٢. المناطق الحقيقية — الوجهات الممكنة ═══
        //
        // ⚠️⚠️ **البحث في كل المحافظات مش في محافظة المنطقة الحالية**
        // (تصحيح بعد أول تشغيل). العميل الجالس تحت «القاهرة» عنوانه
        // «٦ أكتوبر» منطقته في **الجيزة** — والبحث المحصور في القاهرة
        // كان بيفشل ويرميه في «يدوي». الجلوس تحت منطقة اسمها محافظة
        // معناه **«مش مصنّف»** مش «موجود في المحافظة دي»، فماينفعش
        // نستخدمه كقيد على البحث. القيد اتحوّل لـ**تصنيف ثقة** تحت.
        $real = $all->filter(fn ($t) => $govOf($t) === null);

        // ⚠️ الأسماء المطبّعة في مصفوفة جنب المودل مش كخاصية عليه.
        // `$zone->__needles = [...]` في إلوكوينت بيعدّي على `__set`
        // ويتخزّن في `attributes` — فالمودل يبقى «متغيّر» وأي `save()`
        // بعدين يحاول يكتب عمود مش موجود.
        $needles = [];

        foreach ($real as $t) {
            $needles[$t->id] = collect([$t->name, $t->name_en])
                ->map(fn ($n) => $this->norm((string) $n))
                ->filter(fn ($n) => mb_strlen($n) >= 4)
                ->unique()->values()->all();
        }

        $real = $real->filter(fn ($t) => $needles[$t->id] !== [])->values();

        $sure = [];      // نفس المحافظة أو داخل القاهرة الكبرى
        $review = [];    // نقل بين محافظات بعيدة — محتاج عين بشرية
        $manual = [];    // العنوان مايدلّش على حاجة

        foreach ($bad as $z) {
            $govKey = $govOf($z);

            $this->line('  ══════════════════════════════════════════');
            $this->line("  #{$z->id}  {$z->name}  ·  عملاء: {$z->clients_count}"
                .'  ·  المحافظة: '.Governorates::label($govKey));

            foreach (Client::where('zone_id', $z->id)->orderBy('id')->get() as $c) {
                $hit = $this->bestZone($c, $real, $needles, $z->id);

                if ($hit === null) {
                    $manual[] = $c;
                    $this->warn(sprintf('    ⚠ #%-5d %-26s %s',
                        $c->id, mb_substr($c->displayName(), 0, 26),
                        mb_substr((string) $c->address, 0, 34)));

                    continue;
                }

                $tgt = $hit->governorate;

                // ⚠️ الوجهة اللي **مالهاش محافظة** بتروح مراجعة مهما
                // كانت المطابقة حلوة — شغّل `promax:zone-govs` الأول.
                $isSure = $tgt !== null && $tgt !== ''
                    && ($tgt === $govKey
                        || (in_array($tgt, self::METRO, true)
                            && in_array($govKey, self::METRO, true)));

                $row = ['client' => $c, 'zone' => $hit];
                $tag = $tgt === $govKey ? '' : '  ['.Governorates::label($tgt).']';

                if ($isSure) {
                    $sure[] = $row;
                    $this->line(sprintf('    ✓ #%-5d %-26s → %s%s',
                        $c->id, mb_substr($c->displayName(), 0, 26), $hit->name, $tag));
                } else {
                    $review[] = $row;
                    $this->line(sprintf('    <fg=yellow>~ #%-5d %-26s → %s%s</>',
                        $c->id, mb_substr($c->displayName(), 0, 26), $hit->name, $tag));
                }
            }
        }

        $this->line('');
        $this->line('  ✓ مؤكد: '.count($sure)
            .'  ·  ~ مراجعة: '.count($review)
            .'  ·  ⚠ يدوي: '.count($manual));

        if ($review !== []) {
            $this->warn('  ~ دول نقل **بره القاهرة الكبرى** — راجعهم بعينك.');
            $this->warn('    اسم المنطقة ممكن يكون اسم شارع في محافظة تانية.');
        }

        if ($manual !== []) {
            $this->warn('  ⚠ العملاء دول عناوينهم ماتدلّش على منطقة موجودة —');
            $this->warn('    حدّد منطقتهم من كارت العميل في /erp/clients.');
        }

        if (! $fix) {
            $this->comment('  (تقرير بس — ضيف --fix للنقل)');

            return self::SUCCESS;
        }

        $apply = $cross ? array_merge($sure, $review) : $sure;

        DB::transaction(function () use ($apply) {
            foreach ($apply as $m) {
                $m['client']->update(['zone_id' => $m['zone']->id]);
            }
        });

        $this->info('  ✓ اتنقل '.count($apply).' عميل.');

        if (! $cross && $review !== []) {
            $this->comment('  الـ'.count($review).' بتوع المراجعة ماتنقلوش — '
                .'`--fix --cross` بعد ما تراجعهم.');
        }

        $this->comment('  المناطق اللي فضلت فاضية تقدر تقفلها من /erp/zones.');

        return self::SUCCESS;
    }

    /**
     * تطبيع نص عربي/إنجليزي للمقارنة.
     *
     * ⚠️ من غير التطبيع ده المطابقة بتفشل على فروق إملائية عادية:
     * «مصر الجديده» ≠ «مصر الجديدة»، و«الاسماعيلية» ≠ «الإسماعيلية».
     * وعلامات الترقيم بتتحوّل مسافات عشان «الدقي،» تبقى كلمة كاملة.
     */
    private function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));

        $s = strtr($s, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي',
        ]);

        // التشكيل والتطويل
        $s = (string) preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $s);
        // أي حاجة مش حرف ولا رقم تبقى فاصل
        $s = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);

        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    /**
     * أنسب منطقة لعميل من عنوانه — أطول اسم منطقة موجود ككلمة كاملة.
     *
     * ⚠️ **كلمة كاملة مش substring**: «دسوق» كانت بتطابق جوه
     * «إبراهيم الدسوقي»، و«الصف» جوه «الصفا»، و«طنطا» جوه «طريق طنطا».
     * دي كانت بتنقل عملاء لمحافظات مالهاش أي علاقة.
     *
     * ⚠️ **أطول مطابقة تكسب**: «القاهرة الجديدة» و«القاهرة» الاتنين
     * ممكن يطابقوا نفس العنوان، والأطول هي الأدق. أول مطابقة كانت
     * بترمي العميل على المنطقة الأعم حسب ترتيب الجدول.
     *
     * ⚠️ **٤ حروف على الأقل**: أسماء قصيرة زي «مصر» أو «K23» بتطابق
     * نصوص عشوائية في العناوين وبتنقل عملاء لمناطق مالهاش علاقة.
     */
    private function bestZone(Client $c, $real, array $needles, int $skipZoneId): ?Zone
    {
        $addr = $this->norm((string) $c->address.' '.$c->name);

        if ($addr === '') {
            return null;
        }

        $hay = ' '.$addr.' ';

        $best = null;
        $bestLen = 0;

        foreach ($real as $t) {
            if ($t->id === $skipZoneId) {
                continue;
            }

            foreach ($needles[$t->id] as $needle) {
                $len = mb_strlen($needle);

                if ($len <= $bestLen) {
                    continue;
                }

                if (! $this->hasWord($hay, $needle)) {
                    continue;
                }

                $best = $t;
                $bestLen = $len;
            }
        }

        return $best;
    }

    /**
     * الاسم موجود ككلمة كاملة، ومش مسبوق بكلمة بتخليه اسم شارع.
     */
    private function hasWord(string $paddedHay, string $needle): bool
    {
        $at = mb_strpos($paddedHay, ' '.$needle.' ');

        while ($at !== false) {
            $before = trim(mb_substr($paddedHay, 0, $at));
            $prev = $before === '' ? '' : (string) mb_strrchr($before, ' ');
            $prev = trim($prev === '' ? $before : $prev);

            if (! in_array($prev, self::STREET_WORDS, true)) {
                return true;
            }

            // نفس الاسم ممكن يتكرر في العنوان — كمّل تدوير
            $at = mb_strpos($paddedHay, ' '.$needle.' ', $at + 1);
        }

        return false;
    }
}
