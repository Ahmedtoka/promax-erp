<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * شيل الفواتير المكرّرة من استيراد اتشغّل مرتين  ·  ١٥ أغسطس ٢٠٢٦
 * ═══════════════════════════════════════════════════════════════
 *
 * `promax:import-moves --fix` اتشغّل مرتين على نفس الملف، فكل
 * فاتورة اتدخّلت مرتين وأرصدة العملاء اتضاعفت.
 *
 * ═══ إزاي بيعرف المكرّر ═══
 *
 * الفواتير المستوردة بتاخد `created_at` **مضبوط بالثانية** من
 * الملف. فاتورتين لنفس العميل ونفس المندوب بنفس **الثانية** ونفس
 * الإجمالي = نسخة مكرّرة يقيناً — مستحيل مندوب يعمل فاتورتين
 * متطابقتين في نفس الثانية من الأبلكيشن.
 *
 * ⚠️ **بيسيب الأقدم (أصغر `id`) ويمسح اللي بعدها** — الأقدم هي
 * الأصل، والباقي نسخ.
 *
 * ⚠️ بيمسح القيود المربوطة بالنسخة كمان، وبعدين `recalculate()`.
 * من غير كده الرصيد المخزّن بيفضل مضروب في ٢.
 *
 *   php artisan promax:dedupe-invoices --rep=17
 *   php artisan promax:dedupe-invoices --rep=17 --fix
 */
class DedupeImportedInvoices extends Command
{
    protected $signature = 'promax:dedupe-invoices
        {--rep= : رقم المندوب}
        {--fix : نفّذ — من غيرها معاينة بس}';

    protected $description = 'شيل الفواتير المكرّرة (نفس العميل والثانية والإجمالي)';

    public function handle(): int
    {
        $repId = (int) $this->option('rep');
        $fix = (bool) $this->option('fix');

        if ($repId <= 0) {
            $this->error('حدد --rep=17');

            return self::FAILURE;
        }

        $rep = User::find($repId);

        if ($rep === null) {
            $this->error("مفيش مستخدم رقم {$repId}");

            return self::FAILURE;
        }

        $all = Invoice::where('user_id', $repId)->orderBy('id')->get();

        // المفتاح: عميل + الثانية + الإجمالي
        $groups = $all->groupBy(fn ($i) => $i->client_id
            .'|'.$i->created_at->format('Y-m-d H:i:s')
            .'|'.number_format((float) $i->grand_total, 2, '.', ''));

        $kill = collect();

        foreach ($groups as $key => $g) {
            if ($g->count() < 2) {
                continue;
            }

            $keep = $g->first();
            $dupes = $g->slice(1);

            $this->line('  '.$keep->created_at->format('Y-m-d H:i')
                .'  عميل #'.$keep->client_id
                .'  '.number_format((float) $keep->grand_total, 2)
                .'  ·  نسخ: '.$g->count()
                .'  → هيفضل '.$keep->number
                .' · هيتشال '.$dupes->pluck('number')->join(', '));

            $kill = $kill->merge($dupes);
        }

        if ($kill->isEmpty()) {
            $this->info('  مفيش فواتير مكرّرة. ✅');

            return self::SUCCESS;
        }

        $clientIds = $kill->pluck('client_id')->unique();

        $this->line('');
        $this->line('  هيتشال: '.$kill->count().' فاتورة');
        $this->line('  الأرصدة دلوقتي:');

        foreach (Client::whereIn('id', $clientIds)->get() as $c) {
            $this->line(sprintf('    #%-6d %-28s %12s', $c->id,
                mb_substr($c->displayName(), 0, 28),
                number_format((float) $c->balance, 2)));
        }

        if (! $fix) {
            $this->comment('  (معاينة — ضيف --fix للتنفيذ)');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($kill, $clientIds) {
            foreach ($kill as $inv) {
                Transaction::where('source_type', Invoice::class)
                    ->where('source_id', $inv->id)->delete();

                $inv->items()->delete();
                $inv->delete();
            }

            Client::whereIn('id', $clientIds)->get()->each->recalculate();
        });

        $this->info('  ✓ اتشال '.$kill->count().' فاتورة مكرّرة.');
        $this->line('  الأرصدة بعد التصحيح:');

        foreach (Client::whereIn('id', $clientIds)->get() as $c) {
            $this->line(sprintf('    #%-6d %-28s %12s', $c->id,
                mb_substr($c->displayName(), 0, 28),
                number_format((float) $c->balance, 2)));
        }

        return self::SUCCESS;
    }
}
