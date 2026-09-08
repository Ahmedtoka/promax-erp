<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\PriceList;
use App\Models\Setting;
use App\Models\Zone;
use App\Services\Coverage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * تنظيف داتا ٨ سبتمبر ٢٠٢٦ — العملاء والقنوات والمناطق المكررة
 * ═══════════════════════════════════════════════════════════════
 *
 * ورقة القرار اللي المالك اعتمدها يوم ٨/٩ واتنفذت على النسخة المحلية
 * من اللايف بسكريبتين — الأمر ده هو نفس الورقة بالحرف عشان تتنفذ على
 * السيرفر بنفس الأمان:
 *
 *   php artisan promax:cleanup-2026-09-08            ← معاينة بس
 *   php artisan promax:cleanup-2026-09-08 --apply    ← تنفيذ
 *
 * اللي بيعمله:
 *   أ. 13 عميل كاش فان بلا قايمة سعر → قايمتهم (كاش فان / بنجورنو / way to go)
 *      والـ11 منهم بلا زون → زونهم، مع `Coverage::sync` (سلسلة الظهور للمندوب).
 *   ب. قنوات: فروع كاريبو تورث كاش فان · فولجا مارت = كاش فان · واي تو جو =
 *      كي أكاونت · ديلي مارت الدقي = كاش فان، وصفوفه المكررة (نفس التليفون،
 *      بلا حركة) تتقفل `pending` بملاحظة.
 *   ج. دمج المناطق المكررة في الأصلية: «٦ أكتوبر» و«اكتوبر» → «السادس من
 *      أكتوبر» · «التجمع الأول — القاهرة الجديدة» → «التجمع الأول» ·
 *      «حدائق الاهرام» → «حدائق الأهرام». نقل العملاء والطلبات والليدز
 *      والموظفين، ضم مناديب المكرر للأصلي (إضافة بس)، وإيقاف المكرر بلا مسح.
 *
 * ⚠️ كل بند بيتحقق من حالته الحالية قبل ما يلمسها (idempotent) — العميل
 * اللي عنده قايمة أصلاً بيتخطى، والزون اللي اتوقف خلاص بيتخطى — فتكرار
 * التشغيل مايكسرش. وفيه حارس `Setting` زي باقي أوامر التصليح.
 *
 * ⚠️ المطابقة بالكود للعملاء وبالـid **مع فحص الاسم** للمناطق والسلاسل —
 * لو id على السيرفر بيشاور على اسم تاني الأمر بيرفض البند ده ويقول.
 */
class CleanupClientsAndZones20260908 extends Command
{
    protected $signature = 'promax:cleanup-2026-09-08
                            {--apply : التنفيذ الفعلي — من غيره معاينة بس}
                            {--force : إعادة التنفيذ رغم حارس التكرار (كل بند بيتخطى اللي اتعمل خلاص)}';

    protected $description = 'ورقة قرار ٨/٩/٢٠٢٦: قوايم وزونات الـ13 عميل، قنوات السلاسل، مكررات ديلي مارت، دمج المناطق المكررة';

    private const FLAG = 'cleanup_2026_09_08_done';

    /** كود العميل ⇒ [قايمة السعر, الزون|null] */
    private const CLIENTS = [
        'CL-53' => [3, null], 'CL-71' => [3, 82], 'CL-74' => [3, 433], 'CL-75' => [3, 82],
        'CL-76' => [15, 82], 'CL-78' => [3, 6], 'CL-93' => [13, 73], 'CL-151' => [3, null],
        'CL-152' => [3, 82], 'CL-153' => [3, 77], 'CL-154' => [3, 77], 'CL-171' => [3, 6], 'CL-172' => [3, 82],
    ];

    /** id السلسلة ⇒ [اسمها للفحص, القناة] — null قناة = ورّث اللي موجود على السلسلة */
    private const CHAINS = [
        29 => ['كاريبو', null],
        34 => ['فولجا مارت', 3],
        32 => ['واي تو جو', 1],
        30 => ['باسم ماركت', 1],
    ];

    private const DAILY_MART_KEEP = 'CL-58';

    private const DAILY_MART_DUPES = ['CL-59', 'CL-60', 'CL-61'];

    private const DAILY_MART_CHANNEL = 3;

    /** المكرر ⇒ [اسمه للفحص, الأصلي, اسم الأصلي للفحص] */
    private const ZONES = [
        680 => ['٦ أكتوبر', 6, 'السادس من أكتوبر'],
        697 => ['اكتوبر', 6, 'السادس من أكتوبر'],
        702 => ['التجمع الأول — القاهرة الجديدة', 73, 'التجمع الأول'],
        701 => ['حدائق الاهرام', 77, 'حدائق الأهرام'],
    ];

    private bool $apply = false;

    private int $changes = 0;

    public function handle(): int
    {
        $this->apply = (bool) $this->option('apply');

        if ($this->apply && Setting::read(self::FLAG) !== null && ! $this->option('force')) {
            $this->error('❌ التنظيف اتعمل قبل كده ('.Setting::read(self::FLAG).'). كل بند idempotent، فلو عايز تعيده: --force');

            return self::FAILURE;
        }

        $this->info($this->apply
            ? '🚀 تنفيذ فعلي'
            : '👀 معاينة بس — من غير --apply مفيش أي تعديل');

        $run = function (): void {
            $this->stepClients();
            $this->stepChains();
            $this->stepDailyMart();
            $this->stepZones();
        };

        if ($this->apply) {
            DB::transaction($run);
            Setting::writeMany([self::FLAG => now()->toDateTimeString().' ('.$this->changes.' تغيير)']);
        } else {
            $run();
        }

        $this->verify();

        $this->newLine();
        $this->info($this->apply
            ? "✅ خلص — {$this->changes} تغيير، والحارس اتسجّل."
            : "المعاينة: {$this->changes} تغيير هيتعمل. لو منطقي شغّل بـ--apply.");

        return self::SUCCESS;
    }

    // ═══════════════ أ. القوايم والزونات ═══════════════

    private function stepClients(): void
    {
        $this->line("\n=== أ. قوايم وزونات الـ13 عميل ===");

        foreach (self::CLIENTS as $code => [$listId, $zoneId]) {
            $c = Client::where('code', $code)->first();

            if ($c === null) {
                $this->warn("  {$code}: مش موجود — اتخطى");

                continue;
            }

            $list = PriceList::find($listId);
            $zone = $zoneId !== null ? Zone::find($zoneId) : null;
            $todo = [];

            if ($c->price_list_id === null) {
                if ($list === null || ! $list->active) {
                    $this->warn("  {$code}: قايمة {$listId} مش موجودة أو موقوفة — اتخطى");

                    continue;
                }
                $todo[] = "قايمة → {$list->name}";
            }

            if ($c->zone_id === null && $zoneId !== null) {
                if ($zone === null || ! $zone->active) {
                    $this->warn("  {$code}: زون {$zoneId} مش موجود أو موقوف — الزون اتخطى");
                } else {
                    $todo[] = "زون → {$zone->name}";
                }
            }

            if ($todo === []) {
                $this->line("  {$code}: تمام أصلاً (قايمة {$c->price_list_id}، زون ".($c->zone_id ?? '—').')');

                continue;
            }

            $this->line("  {$code}: ".implode(' · ', $todo));
            $this->changes += count($todo);

            if ($this->apply) {
                if ($c->price_list_id === null) {
                    $c->price_list_id = $listId;
                }
                if ($c->zone_id === null && $zone !== null && $zone->active) {
                    $c->zone_id = $zoneId;
                }
                $c->save();
                Coverage::sync($c->fresh());
            }
        }
    }

    // ═══════════════ ب. القنوات ═══════════════

    private function stepChains(): void
    {
        $this->line("\n=== ب. قنوات السلاسل وفروعها ===");

        foreach (self::CHAINS as $gid => [$name, $channelId]) {
            $g = ClientGroup::find($gid);

            if ($g === null || ! str_contains((string) $g->name, $name)) {
                $this->warn("  سلسلة #{$gid} «{$name}»: مش موجودة أو الاسم مختلف (".($g?->name ?? '—').') — اتخطت');

                continue;
            }

            $target = $channelId ?? $g->channel_id;

            if ($target === null) {
                $this->warn("  {$g->name}: مالهاش قناة ولا قناة مقترحة — اتخطت");

                continue;
            }

            if ($channelId !== null && ! DB::table('channels')->where('id', $channelId)->where('active', 1)->exists()) {
                $this->warn("  {$g->name}: القناة {$channelId} مش موجودة — اتخطت");

                continue;
            }

            $orphans = Client::where('group_id', $gid)->where('status', 'active')->whereNull('channel_id')->get();
            $chainNeeds = (int) $g->channel_id !== (int) $target;

            if (! $chainNeeds && $orphans->isEmpty()) {
                $this->line("  {$g->name}: تمام أصلاً");

                continue;
            }

            $this->line("  {$g->name}: ".($chainNeeds ? "قناة السلسلة ".($g->channel_id ?? '—')." → {$target} · " : '')."فروع بلا قناة هتورثها: {$orphans->count()}");
            $this->changes += ($chainNeeds ? 1 : 0) + $orphans->count();

            if ($this->apply) {
                if ($chainNeeds) {
                    $g->channel_id = $target;
                    $g->save();
                }
                foreach ($orphans as $b) {
                    $b->channel_id = $target;
                    $b->save();
                }
            }
        }
    }

    private function stepDailyMart(): void
    {
        $this->line("\n=== ب٢. ديلي مارت الدقي ===");

        $keep = Client::where('code', self::DAILY_MART_KEEP)->first();

        if ($keep === null) {
            $this->warn('  '.self::DAILY_MART_KEEP.' مش موجود — اتخطى');

            return;
        }

        if ($keep->channel_id === null) {
            $this->line('  '.self::DAILY_MART_KEEP.': قناة → '.self::DAILY_MART_CHANNEL);
            $this->changes++;

            if ($this->apply) {
                $keep->channel_id = self::DAILY_MART_CHANNEL;
                $keep->save();
            }
        } else {
            $this->line('  '.self::DAILY_MART_KEEP.': قناته موجودة ('.$keep->channel_id.')');
        }

        foreach (Client::whereIn('code', self::DAILY_MART_DUPES)->get() as $d) {
            if ($d->status === 'pending') {
                $this->line("  {$d->code}: مقفول أصلاً");

                continue;
            }

            // ⚠️ مكرر «فاضي» بس — أي حركة عليه معناها إنه عميل حقيقي ومايتقفلش أوتوماتيك
            if ((float) $d->balance != 0.0 || $d->transactions()->exists() || $d->invoices()->exists()) {
                $this->warn("  {$d->code}: عليه حركة — مش مكرر فاضي، اتخطى (راجعه بإيدك)");

                continue;
            }

            $this->line("  {$d->code}: → pending (مكرر لـ{$keep->code})");
            $this->changes++;

            if ($this->apply) {
                $d->status = 'pending';
                $d->notes = trim(($d->notes ? $d->notes."\n" : '')."مكرر لـ{$keep->code} — اتقفل ٨/٩/٢٠٢٦ (نفس التليفون والاسم، بلا أي حركة)");
                $d->save();
            }
        }
    }

    // ═══════════════ ج. دمج المناطق ═══════════════

    private function stepZones(): void
    {
        $this->line("\n=== ج. دمج المناطق المكررة ===");

        foreach (self::ZONES as $dupId => [$dupName, $canonId, $canonName]) {
            $dup = Zone::find($dupId);
            $canon = Zone::find($canonId);

            if ($dup === null || $canon === null || $dup->name !== $dupName || $canon->name !== $canonName) {
                $this->warn("  #{$dupId} «{$dupName}» → #{$canonId} «{$canonName}»: الـid أو الاسم مش مطابق على الداتابيز دي (".($dup?->name ?? '—').' / '.($canon?->name ?? '—').') — اتخطى');

                continue;
            }

            if (! $canon->active) {
                $this->warn("  «{$canonName}» الأصلي موقوف — اتخطى");

                continue;
            }

            $clients = Client::where('zone_id', $dupId)->count();
            $reqs = DB::table('client_requests')->where('zone_id', $dupId)->count();
            $leads = DB::table('leads')->where('zone_id', $dupId)->count();
            $users = DB::table('users')->where('zone_id', $dupId)->count();
            $dupReps = DB::table('zone_user')->where('zone_id', $dupId)->pluck('user_id');

            if (! $dup->active && $clients + $reqs + $leads + $users + $dupReps->count() === 0) {
                $this->line("  «{$dupName}»: مدموج أصلاً");

                continue;
            }

            $this->line("  «{$dupName}» (#{$dupId}) → «{$canonName}» (#{$canonId}): عملاء {$clients} · طلبات {$reqs} · ليدز {$leads} · موظفين {$users} · مناديب {$dupReps->count()}".($dup->active ? ' · وإيقاف المكرر' : ''));
            $this->changes += $clients + $reqs + $leads + $users + $dupReps->count() + ($dup->active ? 1 : 0);

            if (! $this->apply) {
                continue;
            }

            $moved = Client::where('zone_id', $dupId)->get();
            Client::where('zone_id', $dupId)->update(['zone_id' => $canonId]);
            DB::table('client_requests')->where('zone_id', $dupId)->update(['zone_id' => $canonId]);
            DB::table('leads')->where('zone_id', $dupId)->update(['zone_id' => $canonId]);
            DB::table('users')->where('zone_id', $dupId)->update(['zone_id' => $canonId]);

            foreach ($dupReps as $uid) {
                $exists = DB::table('zone_user')->where('zone_id', $canonId)->where('user_id', $uid)->exists();

                if (! $exists) {
                    DB::table('zone_user')->insert(['zone_id' => $canonId, 'user_id' => $uid]);
                }
            }
            DB::table('zone_user')->where('zone_id', $dupId)->delete();

            if ($dup->active) {
                $dup->active = false;
                $dup->save();
            }

            foreach ($moved as $c) {
                Coverage::sync($c->fresh());
            }
        }
    }

    // ═══════════════ التحقق ═══════════════

    private function verify(): void
    {
        $this->line("\n=== تحقق ===");
        $inactiveZones = Zone::where('active', false)->pluck('id');

        $this->table(['الفحص', 'العدد'], [
            ['عملاء نشطين بلا قايمة سعر', Client::where('status', 'active')->whereNull('price_list_id')->count()],
            ['عملاء نشطين بلا زون', Client::where('status', 'active')->whereNull('zone_id')->count()],
            ['عملاء نشطين بلا قناة', Client::where('status', 'active')->whereNull('channel_id')->count()],
            ['عملاء نشطين على مناطق موقوفة', Client::where('status', 'active')->whereIn('zone_id', $inactiveZones)->count()],
            ['مناديب متعلّمين لمناطق موقوفة', DB::table('zone_user')->whereIn('zone_id', $inactiveZones)->count()],
            ['سلاسل نشطة بلا قناة', ClientGroup::where('active', true)->whereNull('channel_id')->count()],
        ]);
    }
}
