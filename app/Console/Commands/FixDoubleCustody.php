<?php

namespace App\Console\Commands;

use App\Models\Custody;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ═══ قفل عهدة زيادة لمندوب عنده أكتر من عهدة مفتوحة (L-7، ١٧/١٠/٢٠٢٦) ═══
 *
 * «عهدة واحدة مفتوحة في كل لحظة» (`promax-system` §6). المندوب اللي عنده
 * عهدتين، `currentCustody()` بتقرا الأحدث بس، فالقديمة بضاعتها مش ظاهرة.
 *
 * ⚠️ القرار للمالك بعد مطابقة البضاعة، فالأمر:
 *   · من غير `--apply` = معاينة بس (قراءة)، وده بيشتغل في أي مكان.
 *   · `--apply` ممنوع على `promax` (نسخة اللايف المحلية) وفي production.
 *   · لازم `--close=<id>` بالاسم، مفيش اختيار أوتوماتيك.
 *   · العهدة اللي فيها بضاعة متبقية بيرفض يقفلها إلا بـ`--remaining-ok`
 *     (يعني البضاعة اتطابقت ورجعت أو اتسوّت). القفل مابيمسحش بنود ولا بيحرّك مخزون.
 *
 *   php artisan promax:fix-double-custody
 *   php artisan promax:fix-double-custody --close=22 --apply --remaining-ok
 */
class FixDoubleCustody extends Command
{
    protected $signature = 'promax:fix-double-custody
        {--close= : رقم العهدة اللي هتتقفل (id)}
        {--apply : نفّذ فعلاً، من غيرها معاينة بس}
        {--remaining-ok : اقفلها حتى لو فيها بضاعة متبقية (بعد المطابقة)}';

    protected $description = 'معاينة وقفل العهدة الزيادة لمندوب عنده أكتر من عهدة مفتوحة';

    public function handle(): int
    {
        $open = fn ($q) => $q->whereNull('status')->orWhere('status', '<>', 'closed');

        $userIds = Custody::query()->where($open)
            ->groupBy('user_id')->havingRaw('COUNT(*) > 1')->pluck('user_id');

        $custodies = Custody::with(['user', 'items'])->where($open)
            ->whereIn('user_id', $userIds)->orderBy('user_id')->orderBy('date')->get();

        $this->table(['custody', 'user', 'date', 'lines', 'remaining', 'gift remaining', 'last movement'],
            $custodies->map(fn (Custody $c) => [
                '#'.$c->id,
                $c->user?->code,
                $c->date?->toDateString(),
                $c->items->count(),
                $c->remainingUnits(),
                $c->items->sum(fn ($i) => (int) $i->gift_assigned - (int) $i->gift_given),
                (string) $c->items->max('updated_at'),
            ])->all());

        if ($userIds->isEmpty()) {
            $this->info('No user has more than one open custody.');

            return self::SUCCESS;
        }

        $closeId = (int) $this->option('close');

        if (! $closeId) {
            $this->line('Preview only. Pick the custody with --close=<id>, then add --apply.');

            return self::SUCCESS;
        }

        $target = $custodies->firstWhere('id', $closeId);

        if (! $target) {
            $this->error("Custody #{$closeId} is not one of the double open custodies above.");

            return self::FAILURE;
        }

        $giftLeft = $target->items->sum(fn ($i) => (int) $i->gift_assigned - (int) $i->gift_given);
        $left = $target->remainingUnits() + $giftLeft;

        if ($left > 0 && ! $this->option('remaining-ok')) {
            $this->error("Custody #{$closeId} still holds {$left} units. Match the goods first, then add --remaining-ok.");

            return self::FAILURE;
        }

        if (! $this->option('apply')) {
            $this->line("Dry run: custody #{$closeId} ({$target->user?->code}) would be closed. Add --apply to write.");

            return self::SUCCESS;
        }

        $db = (string) config('database.connections.'.config('database.default').'.database');

        if (app()->isProduction() || $db === 'promax') {
            $this->error("Refused on {$db}/".app()->environment().': promax is live data and stays read-only.');

            return self::FAILURE;
        }

        $done = DB::transaction(function () use ($closeId) {
            $c = Custody::whereKey($closeId)->lockForUpdate()->first();

            if (! $c || $c->status === 'closed') {
                return false;
            }

            // لسه فيه عهدة مفتوحة تانية؟ ماينفعش نسيب المندوب من غير عهدة بالغلط
            $others = Custody::where('user_id', $c->user_id)->whereKeyNot($c->id)
                ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '<>', 'closed'))
                ->lockForUpdate()->count();

            if ($others < 1) {
                return false;
            }

            $c->update(['status' => 'closed', 'closed_at' => now()]);

            return true;
        });

        if (! $done) {
            $this->error("Custody #{$closeId} changed meanwhile (already closed or it is the only open one). Nothing written.");

            return self::FAILURE;
        }

        $this->info("Custody #{$closeId} closed.");

        return self::SUCCESS;
    }
}
