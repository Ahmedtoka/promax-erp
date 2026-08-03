<?php

namespace App\Console\Commands;

use App\Models\Client;
use Illuminate\Console\Command;

/**
 * ═══════════════════════════════════════════════════════════════
 * العملاء اللي «اسمهم العربي» مكتوب إنجليزي
 * ═══════════════════════════════════════════════════════════════
 *
 * الشيتات الأصلية ماكانش فيها اسم عربي لمعظم العملاء، والاستيراد
 * لما ملقاش عربي حط الإنجليزي مكانه (`name_ar ?: name_en`) — فالأبلكيشن
 * بيعرض إنجليزي حتى والواجهة عربي. الأسماء دي محتاجة **إدخال يدوي**
 * من كارت العميل (خانة الاسم العربي) — مش تصليح كود.
 *
 *   php artisan promax:names
 */
class AuditNames extends Command
{
    protected $signature = 'promax:names';

    protected $description = 'قايمة العملاء اللي اسمهم العربي مكتوب إنجليزي — محتاجين إدخال الاسم العربي';

    public function handle(): int
    {
        $clients = Client::where('status', 'active')->orderBy('code')->get();

        // «مافيهوش حرف عربي واحد» = الخانة العربي فيها إنجليزي
        $latin = $clients->filter(fn ($c) => ! preg_match('/\p{Arabic}/u', (string) $c->name));

        if ($latin->isEmpty()) {
            $this->info('✅ كل العملاء النشطين ليهم أسماء عربي.');

            return self::SUCCESS;
        }

        $this->warn("⚠️ {$latin->count()} عميل من {$clients->count()} «اسمهم العربي» مكتوب إنجليزي:");
        $this->newLine();

        $this->table(
            ['الكود', 'الاسم الحالي (في خانة العربي)', 'الإنجليزي'],
            $latin->take(100)->map(fn ($c) => [
                $c->code,
                mb_substr((string) $c->name, 0, 40),
                mb_substr((string) $c->name_en, 0, 40),
            ])->all(),
        );

        if ($latin->count() > 100) {
            $this->line('   … و'.($latin->count() - 100).' كمان.');
        }

        $this->newLine();
        $this->line('التصليح: افتح كارت العميل في الـERP واكتب الاسم في خانة «الاسم بالعربي» —');
        $this->line('هيظهر فوراً في الأبلكيشن وكل الشاشات العربي.');

        return self::SUCCESS;
    }
}
