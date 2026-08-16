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
 *   ٢. لكل عميل تحتها، بتقرا **عنوانه** وتحاول تلاقي منطقة حقيقية
 *      موجودة بالفعل في نفس المحافظة اسمها موجود في العنوان.
 *   ٣. بتعرض الاقتراح وتستنى تأكيدك.
 *
 * ⚠️ **مابتعملش مناطق جديدة.** بتنقل لمنطقة **موجودة** بس — إنشاء
 * مناطق من نص عنوان بيولّد مناطق وهمية بأسماء متكررة ومكتوبة غلط،
 * وتنضيفها بعدين أصعب من المشكلة الأصلية.
 *
 * ⚠️ **العميل اللي عنوانه مايدلّش على منطقة بيتساب مكانه** ويتكتب في
 * قايمة «محتاج تحديد يدوي». نقله لأقرب تخمين أسوأ من سيبانه: الأول
 * غلط مخفي، والتاني مشكلة ظاهرة.
 *
 *   php artisan promax:gov-zones
 *   php artisan promax:gov-zones --fix
 */
class AuditGovNamedZones extends Command
{
    protected $signature = 'promax:gov-zones {--fix : نفّذ النقل — من غيرها تقرير بس}';

    protected $description = 'مناطق اسمها اسم محافظة — وتوزيع عملائها على مناطق حقيقية';

    public function handle(): int
    {
        $fix = (bool) $this->option('fix');

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
        // نستخدمه كقيد على البحث.
        $real = $all->filter(fn ($t) => $govOf($t) === null);

        $moves = [];
        $manual = [];

        foreach ($bad as $z) {
            $govKey = $govOf($z);

            $this->line('  ══════════════════════════════════════════');
            $this->line("  #{$z->id}  {$z->name}  ·  عملاء: {$z->clients_count}"
                .'  ·  المحافظة: '.Governorates::label($govKey));

            foreach (Client::where('zone_id', $z->id)->orderBy('id')->get() as $c) {
                $hit = $this->bestZone($c, $real, $z->id);

                if ($hit === null) {
                    $manual[] = $c;
                    $this->warn(sprintf('    ⚠ #%-5d %-26s %s',
                        $c->id, mb_substr($c->displayName(), 0, 26),
                        mb_substr((string) $c->address, 0, 34)));

                    continue;
                }

                $moves[] = ['client' => $c, 'zone' => $hit];

                // ⚠️ المحافظة بتتكتب لما تختلف — النقل عبر المحافظات
                // هو الحالة الشائعة هنا، ولازم تبان عشان تتراجع.
                $cross = $hit->governorate !== $govKey
                    ? '  ['.Governorates::label($hit->governorate).']'
                    : '';

                $this->line(sprintf('    ✓ #%-5d %-26s → %s%s',
                    $c->id, mb_substr($c->displayName(), 0, 26), $hit->name, $cross));
            }
        }

        $this->line('');
        $this->line('  هينتقلوا: '.count($moves).'  ·  يدوي: '.count($manual));

        if ($manual !== []) {
            $this->warn('  ⚠ العملاء دول عناوينهم ماتدلّش على منطقة موجودة —');
            $this->warn('    حدّد منطقتهم من كارت العميل في /erp/clients.');
        }

        if (! $fix) {
            $this->comment('  (تقرير بس — ضيف --fix للنقل)');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($moves) {
            foreach ($moves as $m) {
                $m['client']->update(['zone_id' => $m['zone']->id]);
            }
        });

        $this->info('  ✓ اتنقل '.count($moves).' عميل.');
        $this->comment('  المناطق اللي فضلت فاضية تقدر تقفلها من /erp/zones.');

        return self::SUCCESS;
    }

    /**
     * أنسب منطقة لعميل من عنوانه — أطول اسم منطقة موجود في العنوان.
     *
     * ⚠️ **أطول مطابقة تكسب**: «القاهرة الجديدة» و«القاهرة» الاتنين
     * ممكن يطابقوا نفس العنوان، والأطول هي الأدق. أول مطابقة كانت
     * بترمي العميل على المنطقة الأعم حسب ترتيب الجدول.
     *
     * ⚠️ **٤ حروف على الأقل**: أسماء قصيرة زي «مصر» أو «K23» بتطابق
     * نصوص عشوائية في العناوين وبتنقل عملاء لمناطق مالهاش علاقة.
     */
    private function bestZone(Client $c, $real, int $skipZoneId): ?Zone
    {
        $addr = mb_strtolower((string) $c->address.' '.$c->name);

        if (trim($addr) === '') {
            return null;
        }

        $best = null;
        $bestLen = 0;

        foreach ($real as $t) {
            if ($t->id === $skipZoneId) {
                continue;
            }

            foreach ([$t->name, $t->name_en] as $tn) {
                $tn = mb_strtolower(trim((string) $tn));
                $len = mb_strlen($tn);

                if ($len < 4 || mb_strpos($addr, $tn) === false) {
                    continue;
                }

                if ($len > $bestLen) {
                    $best = $t;
                    $bestLen = $len;
                }
            }
        }

        return $best;
    }
}
