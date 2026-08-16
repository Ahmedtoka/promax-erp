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

        $moves = [];
        $manual = [];

        foreach ($bad as $z) {
            $govKey = $govOf($z);

            $this->line('  ══════════════════════════════════════════');
            $this->line("  #{$z->id}  {$z->name}  ·  عملاء: {$z->clients_count}"
                .'  ·  المحافظة: '.Governorates::label($govKey));

            // المناطق الحقيقية في نفس المحافظة — الوجهات الممكنة
            $targets = $all->filter(fn ($t) => $t->id !== $z->id
                && $t->governorate === $govKey
                && $govOf($t) === null);

            if ($targets->isEmpty()) {
                $this->warn('    ⚠ مفيش مناطق تانية في المحافظة دي — اعمل المناطق الأول.');
            }

            foreach (Client::where('zone_id', $z->id)->orderBy('id')->get() as $c) {
                $addr = mb_strtolower((string) $c->address.' '.$c->name);
                $hit = null;

                foreach ($targets as $t) {
                    foreach ([$t->name, $t->name_en] as $tn) {
                        $tn = mb_strtolower(trim((string) $tn));

                        if ($tn !== '' && mb_strpos($addr, $tn) !== false) {
                            $hit = $t;

                            break 2;
                        }
                    }
                }

                if ($hit === null) {
                    $manual[] = $c;
                    $this->warn(sprintf('    ⚠ #%-5d %-26s %s',
                        $c->id, mb_substr($c->displayName(), 0, 26),
                        mb_substr((string) $c->address, 0, 34)));

                    continue;
                }

                $moves[] = ['client' => $c, 'zone' => $hit];
                $this->line(sprintf('    ✓ #%-5d %-26s → %s',
                    $c->id, mb_substr($c->displayName(), 0, 26), $hit->name));
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
}
