<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\ClientReturn;
use App\Models\Custody;
use App\Models\GiftHandout;
use App\Models\Invoice;
use App\Models\PickOrder;
use App\Models\PurchaseOrder;
use App\Models\RepSettlement;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * ═══════════════════════════════════════════════════════════════
 * مسح حركات مندوب بالكامل — بباك أب  ·  ١٥ أغسطس ٢٠٢٦
 * ═══════════════════════════════════════════════════════════════
 *
 * قرار المالك: «امسح كل حاجة تخص اسلام طلبه من حركات وبيع وعهدة،
 * وأنا هبعتلك كل حاجة بتاريخها تدخّلها في الداتابيز».
 *
 * ═══ اللي بيتمسح (مندوب واحد بس) ═══
 *
 *   · الفواتير (`invoices.user_id`) + بنودها + قيودها
 *   · أوامر التوريد (`assigned_to`) + بنودها + قيودها
 *   · المرتجعات (`client_returns.user_id`) + بنودها + قيودها
 *   · التحصيلات الميدانية — قيود `collection` مرساة على زياراته
 *   · العهد (`custodies.user_id`) + بنودها
 *   · أوامر التجهيز (`assigned_to`) + بنودها
 *   · الهدايا والتصفيات
 *
 * ⚠️ **الزيارات والحضور والتراكينج مابيتمسحوش** إلا بـ`--visits`.
 * دي حركة ميدان مش فلوس، ومسحها بيضيّع سجل إن المندوب كان فين.
 *
 * ═══ الباك أب مش اختيار ═══
 *
 * كل صف بيتمسح بيتكتب في JSON تحت
 * `storage/app/backups/rep-{id}-wipe-{وقت}.json` **قبل** أي مسح،
 * وجوه نفس الترانزاكشن. لو الكتابة فشلت، المسح مابيحصلش.
 *
 * ═══ نقطة لازم تكون واضحة ═══
 *
 * ⚠️ **مسح أمر التجهيز مابيرجّعش البضاعة للرف.** البضاعة خرجت من
 * المخزن فعلاً، فرصيد الرف الأقل ده **صح**. لما تدخّل العهدة من
 * تاني، أداة الإدخال بتكتب `custody_items` مباشرة من غير ما تلمس
 * الأرفف — وإلا كنا هنخصم البضاعة مرتين.
 *
 * ⚠️ كل عميل اتأثر بيتعمله `recalculate()` — الرصيد المخزّن
 * مابيتحدّثش لوحده لما القيود تتمسح.
 *
 *   php artisan promax:wipe-rep --rep=17
 *   php artisan promax:wipe-rep --rep=17 --fix
 */
class WipeRepMovements extends Command
{
    protected $signature = 'promax:wipe-rep
        {--rep= : رقم المندوب}
        {--visits : امسح الزيارات كمان (الافتراضي: سيبها)}
        {--fix : نفّذ — من غيرها معاينة بس}';

    protected $description = 'مسح كل حركات البيع والعهدة لمندوب واحد، مع باك أب JSON';

    public function handle(): int
    {
        $repId = (int) $this->option('rep');
        $withVisits = (bool) $this->option('visits');
        $fix = (bool) $this->option('fix');

        $rep = User::find($repId);

        if ($rep === null) {
            $this->error("مفيش مستخدم رقم {$repId}");

            return self::FAILURE;
        }

        // ═══ التجميع ═══
        $invoices = Invoice::with('items')->where('user_id', $repId)->get();
        $pos = PurchaseOrder::with('items')->where('assigned_to', $repId)->get();
        $returns = ClientReturn::with('items')->where('user_id', $repId)->get();
        $visits = Visit::where('user_id', $repId)->get();
        $custodies = Custody::with('items')->where('user_id', $repId)->get();
        $picks = PickOrder::with('items')->where('assigned_to', $repId)->get();
        $gifts = GiftHandout::where('user_id', $repId)->get();
        $settles = RepSettlement::where('user_id', $repId)->get();

        // القيود: المرساة على فواتيره/أوامره/مرتجعاته/زياراته
        $txn = Transaction::where(function ($q) use ($invoices, $pos, $returns, $visits) {
            $q->where(fn ($w) => $w->where('source_type', Invoice::class)
                ->whereIn('source_id', $invoices->pluck('id')))
                ->orWhere(fn ($w) => $w->where('source_type', PurchaseOrder::class)
                    ->whereIn('source_id', $pos->pluck('id')))
                ->orWhere(fn ($w) => $w->where('source_type', ClientReturn::class)
                    ->whereIn('source_id', $returns->pluck('id')))
                ->orWhere(fn ($w) => $w->where('source_type', Visit::class)
                    ->whereIn('source_id', $visits->pluck('id')));
        })->get();

        $clientIds = $txn->pluck('client_id')
            ->merge($invoices->pluck('client_id'))
            ->merge($pos->pluck('client_id'))
            ->merge($returns->pluck('client_id'))
            ->filter()->unique()->values();

        // ═══ التقرير ═══
        $this->line('');
        $this->line('  المندوب: '.$rep->displayName()." (#{$rep->id})");
        $this->line('  ────────────────────────────────────');
        $rows = [
            'فواتير' => $invoices->count(),
            'بنود فواتير' => $invoices->sum(fn ($i) => $i->items->count()),
            'أوامر توريد' => $pos->count(),
            'مرتجعات' => $returns->count(),
            'قيود على حسابات العملاء' => $txn->count(),
            'عهد' => $custodies->count(),
            'بنود عهدة' => $custodies->sum(fn ($c) => $c->items->count()),
            'أوامر تجهيز' => $picks->count(),
            'هدايا' => $gifts->count(),
            'تصفيات' => $settles->count(),
            'زيارات'.($withVisits ? ' (هتتمسح)' : ' (هتتساب)') => $visits->count(),
        ];

        foreach ($rows as $k => $v) {
            $this->line(sprintf('  %-32s %6d', $k, $v));
        }

        $this->line('');
        $this->line('  أرصدة العملاء اللي هتتغيّر:');

        foreach (Client::whereIn('id', $clientIds)->get() as $c) {
            $effect = $txn->where('client_id', $c->id)
                ->sum(fn ($t) => (float) $t->debit - (float) $t->credit);

            $this->line(sprintf('    #%-6d %-30s %12s  →  %12s',
                $c->id, mb_substr($c->displayName(), 0, 30),
                number_format((float) $c->balance, 2),
                number_format((float) $c->balance - $effect, 2)));
        }

        if (! $fix) {
            $this->line('');
            $this->comment('  (معاينة — ضيف --fix للتنفيذ. الباك أب بيتعمل أوتوماتيك.)');

            return self::SUCCESS;
        }

        // ═══ الباك أب — قبل أي مسح، وجوه الترانزاكشن ═══
        $stamp = now()->format('Ymd-His');
        $path = "backups/rep-{$repId}-wipe-{$stamp}.json";

        DB::transaction(function () use (
            $repId, $rep, $invoices, $pos, $returns, $visits, $custodies,
            $picks, $gifts, $settles, $txn, $clientIds, $withVisits, $path
        ) {
            $dump = [
                'rep' => ['id' => $rep->id, 'name' => $rep->name],
                'at' => now()->toIso8601String(),
                'invoices' => $invoices->map(fn ($i) => $i->toArray() + [
                    'items' => $i->items->toArray(),
                ])->all(),
                'purchase_orders' => $pos->map(fn ($p) => $p->toArray() + [
                    'items' => $p->items->toArray(),
                ])->all(),
                'client_returns' => $returns->map(fn ($r) => $r->toArray() + [
                    'items' => $r->items->toArray(),
                ])->all(),
                'transactions' => $txn->toArray(),
                'custodies' => $custodies->map(fn ($c) => $c->toArray() + [
                    'items' => $c->items->toArray(),
                ])->all(),
                'pick_orders' => $picks->map(fn ($p) => $p->toArray() + [
                    'items' => $p->items->toArray(),
                ])->all(),
                'gift_handouts' => $gifts->toArray(),
                'rep_settlements' => $settles->toArray(),
                'visits' => $withVisits ? $visits->toArray() : [],
            ];

            // ⚠️ لو الكتابة فشلت الترانزاكشن بترجع ومفيش حاجة بتتمسح
            $ok = Storage::disk('local')->put(
                $path,
                json_encode($dump, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            );

            if ($ok === false) {
                throw new \RuntimeException('فشل كتابة الباك أب — المسح اتلغى.');
            }

            // ═══ المسح — القيود الأول عشان مايفضلش يتيم ═══
            Transaction::whereIn('id', $txn->pluck('id'))->delete();

            foreach ($invoices as $i) {
                $i->items()->delete();
                $i->delete();
            }

            foreach ($pos as $p) {
                $p->replenishmentRequest?->update([
                    'purchase_order_id' => null,
                    'status' => 'pending',
                    'assigned_to' => null,
                    'assigned_at' => null,
                ]);
                $p->items()->delete();
                $p->delete();
            }

            foreach ($returns as $r) {
                $r->items()->delete();
                $r->delete();
            }

            foreach ($picks as $p) {
                $p->replenishmentRequest?->update(['status' => 'pending']);
                $p->items()->delete();
                $p->delete();
            }

            foreach ($custodies as $c) {
                $c->items()->delete();
                $c->delete();
            }

            GiftHandout::whereIn('id', $gifts->pluck('id'))->delete();
            RepSettlement::whereIn('id', $settles->pluck('id'))->delete();

            if ($withVisits) {
                Visit::whereIn('id', $visits->pluck('id'))->delete();
            }

            // ═══ إعادة حساب كل عميل اتأثر ═══
            Client::whereIn('id', $clientIds)->get()->each->recalculate();
        });

        $this->info('  ✓ الباك أب: storage/app/'.$path);
        $this->info('  ✓ المسح خلص. أرصدة العملاء بعد إعادة الحساب:');

        foreach (Client::whereIn('id', $clientIds)->get() as $c) {
            $this->line(sprintf('    #%-6d %-30s %12s',
                $c->id, mb_substr($c->displayName(), 0, 30),
                number_format((float) $c->balance, 2)));
        }

        return self::SUCCESS;
    }
}
