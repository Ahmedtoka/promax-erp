<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Console\Command;

/**
 * ═══════════════════════════════════════════════════════════════
 * فحص مندوب — «هو شايف إيه في الأبلكيشن وليه؟»
 * ═══════════════════════════════════════════════════════════════
 *
 * لما مندوب يقول «المناطق فاضية» الأمر ده بيجاوب في ثانية:
 * رولّه إيه، متسكن على إيه (zone_user + zone_id)، عملاؤه فين،
 * والمناطق اللي الـAPI هيرجّعها له فعلاً.
 *
 *   php artisan promax:rep mohamed@promax.com
 *   php artisan promax:rep EMP-102
 */
class InspectRep extends Command
{
    protected $signature = 'promax:rep {login : الإيميل أو كود الموظف}';

    protected $description = 'فحص مندوب: الرول، التسكين، العملاء، والمناطق اللي الأبلكيشن هيوريها';

    public function handle(): int
    {
        $login = $this->argument('login');

        $user = User::where('email', $login)->orWhere('code', $login)->first();

        if (! $user) {
            $this->error("مفيش يوزر بالإيميل أو الكود: $login");

            return self::FAILURE;
        }

        $this->info("👤 {$user->displayName()} — {$user->roleLabel()} ({$user->role})".($user->active ? '' : ' ⛔ موقوف'));
        $this->line('   القناة: '.($user->channel?->displayName() ?? '— (بيشوف كل القنوات)'));
        $this->line('   المنطقة الأساسية (zone_id): '.($user->zone?->displayName() ?? '—'));

        if (! $user->isSalesAgent()) {
            $this->warn('⚠️ الرول مش sales_agent — تاب «المناطق» بيتبعت فاضي لأي رول تاني!');
        }

        // التسكين من شاشة التوزيع
        $pivotZones = $user->zones()->get();
        $this->newLine();
        $this->info('📍 التسكين من شاشة التوزيع (zone_user): '.$pivotZones->count());
        foreach ($pivotZones as $z) {
            $this->line("   - {$z->displayName()}".($z->active ? '' : ' ⛔ المنطقة موقوفة'));
        }

        // عملاؤه ومناطقهم — **بول الفريق** (١١/٨ مساءً): عملاءه هو
        // + كل عملاء مديره. نفس قاعدة zonesPayload بالظبط.
        $clients = Client::poolWhere(Client::query(), $user)
            ->where('status', 'active')->with('zone')->get();
        $this->newLine();
        $mine = $clients->where('rep_id', $user->id)->count();
        $this->info("🏪 بول الفريق النشط: {$clients->count()} (منهم {$mine} هو المسؤول الأساسي عنهم)");
        if ($user->manager_id === null) {
            $this->line('   ⚠️ مالوش مدير — البول = عملاؤه المسجّلين (rep_id) بس، زي القاعدة القديمة.');
        }
        foreach ($clients->groupBy(fn ($c) => $c->zone?->displayName() ?? 'بدون منطقة') as $zoneName => $group) {
            $this->line("   - $zoneName: {$group->count()} عميل");
        }

        // اللي الـAPI هيرجّعه فعلاً — نفس منطق zonesPayload
        $zoneIds = $user->zones()->pluck('zones.id');

        if ($zoneIds->isEmpty() && $user->zone_id) {
            $zoneIds = collect([$user->zone_id]);
        }

        $zoneIds = $zoneIds
            ->merge($clients->pluck('zone_id')->filter())
            ->unique()->values();

        $apiZones = Zone::whereIn('id', $zoneIds)->where('active', true)->get();

        $this->newLine();
        $this->info('📱 اللي الأبلكيشن هيوريه في تاب المناطق: '.$apiZones->count().' منطقة');
        foreach ($apiZones as $z) {
            $mine = $clients->where('zone_id', $z->id)->count();
            $free = Client::whereNull('rep_id')->where('status', 'active')
                ->where('zone_id', $z->id)
                ->when($user->channel_id, fn ($q) => $q->where('channel_id', $user->channel_id))
                // نفس قاعدة الـAPI: يتيم مدير تاني = بول فريق تاني
                ->where(fn ($w) => $w->whereNull('manager_id')
                    ->when($user->manager_id !== null,
                        fn ($q) => $q->orWhere('manager_id', $user->manager_id)))
                ->count();
            $this->line("   - {$z->displayName()}: $mine من البول + $free من غير مندوب");
        }

        if ($apiZones->isEmpty()) {
            $this->newLine();
            $this->warn('⚠️ فاضية فعلاً: لا تسكين في zone_user، ولا zone_id، ولا عملاء متسكنين عليه.');
            $this->line('   سكّنه من /ops/assignments (علّم على المناطق واحفظ، أو علّم على عملاء واعمل تسكين).');
        }

        return self::SUCCESS;
    }
}
