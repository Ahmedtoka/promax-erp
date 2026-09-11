<?php

namespace App\Services\Gl;

use App\Models\Gl\GlEntry;
use App\Models\Gl\GlLine;
use App\Models\Gl\GlPeriod;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ═══ المكان الوحيد اللي بيكتب في دفتر الأستاذ ═══
 * post/unpost/repost للقيود الآلية · manual/reverse/overrideAccount للتصحيح ·
 * rebuild لإعادة البناء. كل كتابة داخل ترانزاكشن وكل قيد لازم يتوازن.
 */
class Ledger
{
    public function __construct(private Rules $rules)
    {
    }

    public function enabledFor(Carbon $date): bool
    {
        if (Setting::read('gl_enabled') !== '1') {
            return false;
        }
        $start = Setting::read('gl_start_date');

        return ! $start || $date->toDateString() >= $start;
    }

    /** تاريخ المستند اللي القيد بياخده */
    public static function dateOf(Model $source): Carbon
    {
        $d = $source->date ?? $source->to_at ?? $source->created_at ?? now();

        return Carbon::parse($d);
    }

    public function post(Model $source, ?User $by = null): ?GlEntry
    {
        $date = self::dateOf($source);
        if (! $this->enabledFor($date)) {
            return null;
        }
        $existing = $this->autoEntryFor($source);
        if ($existing) {
            return $existing->load('lines');
        }
        $spec = $this->rules->linesFor($source);
        if ($spec['lines'] === []) {
            return null;
        }

        return DB::transaction(function () use ($source, $date, $spec, $by) {
            $entry = GlEntry::create([
                'number' => GlEntry::nextNumber(),
                'date' => $date->toDateString(),
                'period_key' => GlPeriod::keyFor($date),
                'memo' => mb_substr($spec['memo'], 0, 250),
                'origin' => 'auto',
                'source_type' => $source->getMorphClass(),
                'source_id' => $source->getKey(),
                'rule_key' => $spec['rule'],
                'needs_review' => $spec['needs_review'],
                'created_by' => $by?->id,
            ]);
            $this->writeLines($entry, $spec['lines']);

            return $entry->load('lines');
        });
    }

    public function unpost(Model $source): void
    {
        $this->autoEntryFor($source)?->delete();
    }

    public function repost(Model $source, ?User $by = null): ?GlEntry
    {
        // unpost+post داخل ترانزاكشن واحد — لو الـpost فشل (مثلاً القاعدة
        // بقت غير مفعّلة) مايتمسحش القيد القديم من غير بديل
        return DB::transaction(function () use ($source, $by) {
            $this->unpost($source);

            return $this->post($source, $by);
        });
    }

    public function autoEntryFor(Model $source): ?GlEntry
    {
        return GlEntry::where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())->where('origin', 'auto')->first();
    }

    /** @param list<array{slot?:string,account_id:int,debit:float,credit:float,memo?:string,rule_account_id?:int,overridden?:bool}> $lines */
    protected function writeLines(GlEntry $entry, array $lines): void
    {
        $dr = round(array_sum(array_column($lines, 'debit')), 2);
        $cr = round(array_sum(array_column($lines, 'credit')), 2);
        if ($dr <= 0) {
            throw new UnbalancedEntry("Entry {$entry->number} has no amount");
        }
        if ($dr !== $cr) {
            throw new UnbalancedEntry("Entry {$entry->number}: debit {$dr} != credit {$cr}");
        }
        foreach ($lines as $l) {
            GlLine::create([
                'entry_id' => $entry->id,
                'account_id' => $l['account_id'],
                'debit' => round($l['debit'], 2),
                'credit' => round($l['credit'], 2),
                'memo' => $l['memo'] ?? null,
                'slot' => $l['slot'] ?? null,
                'rule_account_id' => $l['rule_account_id'] ?? $l['account_id'],
                'overridden' => $l['overridden'] ?? false,
            ]);
        }
    }
}
