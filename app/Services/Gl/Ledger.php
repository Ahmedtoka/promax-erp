<?php

namespace App\Services\Gl;

use App\Models\Gl\GlAccount;
use App\Models\Gl\GlEntry;
use App\Models\Gl\GlLine;
use App\Models\Gl\GlLineOverride;
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

    public function assertOpen(Carbon $date): void
    {
        if (GlPeriod::isClosed($date)) {
            throw new ClosedPeriod(__('gl.period_closed', ['period' => GlPeriod::keyFor($date)]));
        }
    }

    /**
     * حساب العملاء/الموردين بيتحرّك من مستنداته بس (فاتورة/تحصيل/PO...) —
     * مش من قيد يدوي ولا تحويل حساب، عشان ده اللي بيحافظ على تطابق
     * الرصيد مع كشف حساب العميل/المورد. الحارس ده مش على `reverse()`
     * ولا على نسخة التصحيح الداخلية (بتنسخ سطور القيد الآلي زي ما هي).
     */
    private function assertNotControl(array $accountIds): void
    {
        $touchesControl = GlAccount::whereIn('id', $accountIds)
            ->whereIn('system_key', ['receivables', 'payables'])
            ->exists();
        if ($touchesControl) {
            throw new \InvalidArgumentException(__('gl.control_account'));
        }
    }

    /** شكل سطور القيد اليدوي: سطرين على الأقل، حساب صحيح، مبالغ رقمية */
    private function validateLines(array $lines): void
    {
        if (count($lines) < 2) {
            throw new \InvalidArgumentException('Manual entry needs at least two lines.');
        }
        foreach ($lines as $l) {
            if (! is_array($l) || ! isset($l['account_id']) || ! is_int($l['account_id'])) {
                throw new \InvalidArgumentException('Manual entry lines: account_id is required and must be an integer.');
            }
            foreach (['debit', 'credit'] as $k) {
                if (isset($l[$k]) && ! is_numeric($l[$k])) {
                    throw new \InvalidArgumentException("Manual entry lines: {$k} must be numeric.");
                }
            }
        }
    }

    /**
     * كتابة قيد فعلياً — رأس + سطور داخل ترانزاكشن. حارس حسابات التحكم
     * اختياري (`$guardControl`) عشان `reverse()` والتصحيح الداخلي في
     * `overrideAccount()` بيعملوا نسخ من قيود آلية معتمدة أصلاً — ماينفعش
     * نرفضهم بنفس حارس القيد اليدوي الطازج.
     */
    private function writeEntry(Carbon $date, string $memo, array $lines, User $by, string $origin, ?int $reversesId, bool $guardControl): GlEntry
    {
        if ($guardControl) {
            $this->assertNotControl(array_column($lines, 'account_id'));
        }

        return DB::transaction(function () use ($date, $memo, $lines, $by, $origin, $reversesId) {
            $entry = GlEntry::create([
                'number' => GlEntry::nextNumber(),
                'date' => $date->toDateString(),
                'period_key' => GlPeriod::keyFor($date),
                'memo' => mb_substr($memo, 0, 250),
                'origin' => $origin,
                'reverses_entry_id' => $reversesId,
                'created_by' => $by->id,
            ]);
            $this->writeLines($entry, array_map(fn ($l) => [
                'account_id' => (int) $l['account_id'],
                'debit' => (float) ($l['debit'] ?? 0),
                'credit' => (float) ($l['credit'] ?? 0),
                'memo' => $l['memo'] ?? null,
                'slot' => $l['slot'] ?? null,
                'rule_account_id' => isset($l['rule_account_id']) ? (int) $l['rule_account_id'] : null,
                'overridden' => $l['overridden'] ?? false,
            ], array_values($lines)));

            return $entry->load('lines');
        });
    }

    /** قيد يدوي — سطور حرة، لازم تتوازن، الفترة لازم تكون مفتوحة */
    public function manual(Carbon $date, string $memo, array $lines, User $by, string $origin = 'manual'): GlEntry
    {
        if (! in_array($origin, ['manual', 'opening'], true)) {
            throw new \InvalidArgumentException("Invalid manual entry origin: {$origin}");
        }
        $this->validateLines($lines);
        $this->assertOpen($date);

        return $this->writeEntry($date, $memo, $lines, $by, $origin, null, true);
    }

    /** قيد عكسي بتاريخ النهاردة (أو تاريخ مفتوح تختاره) */
    public function reverse(GlEntry $entry, User $by, ?Carbon $date = null, ?string $memo = null): GlEntry
    {
        $date ??= today();
        $this->assertOpen($date);
        $lines = $entry->lines->map(fn (GlLine $l) => [
            'account_id' => $l->account_id, 'debit' => (float) $l->credit, 'credit' => (float) $l->debit,
        ])->all();

        return $this->writeEntry($date, $memo ?? __('gl.reversal_of', ['number' => $entry->number]), $lines, $by, 'reversal', $entry->id, false);
    }

    /**
     * تغيير حساب سطر: في الفترة المفتوحة تعديل في مكانه بسجل تدقيق؛ في
     * الفترة المقفولة قيد عكسي + قيد صحيح بتاريخ النهاردة، وبنفس أثر
     * التدقيق (سجل `gl_line_overrides` على السطر الأصلي + السطر المصحَّح
     * في القيد الجديد `overridden=true` و`rule_account_id` = الحساب
     * الأصلي). الأصل مايتلمسش خالص. بيرجّع القيد اللي فيه الترحيل الصحيح دلوقتي.
     */
    public function overrideAccount(GlLine $line, GlAccount $to, User $by, ?string $note = null): GlEntry
    {
        $entry = $line->entry;

        if (! $to->is_postable || ! $to->active) {
            throw new \InvalidArgumentException(__('gl.account_not_postable'));
        }
        // المصدر والهدف مع بعض — نقل من أو إلى حساب تحكم ممنوع بالتساوي
        $this->assertNotControl([$line->account_id, $to->id]);

        if ($line->account_id === $to->id) {
            return $entry->load('lines');
        }

        if (! GlPeriod::isClosed($entry->date)) {
            return DB::transaction(function () use ($line, $to, $by, $note, $entry) {
                GlLineOverride::create([
                    'line_id' => $line->id, 'from_account_id' => $line->account_id, 'to_account_id' => $to->id,
                    'user_id' => $by->id, 'at' => now(), 'note' => $note,
                ]);
                $line->update(['account_id' => $to->id, 'overridden' => true, 'rule_account_id' => $line->rule_account_id ?? $line->account_id]);
                $entry->update(['edited_at' => now(), 'edited_by' => $by->id]);

                return $entry->fresh('lines');
            });
        }

        return DB::transaction(function () use ($line, $to, $by, $note, $entry) {
            $this->reverse($entry, $by);

            // نفس أثر التدقيق اللي بيحصل في الفترة المفتوحة — على السطر
            // الأصلي (اللي فضل زي ما هو) مش على أي سطر في القيد الجديد
            GlLineOverride::create([
                'line_id' => $line->id, 'from_account_id' => $line->account_id, 'to_account_id' => $to->id,
                'user_id' => $by->id, 'at' => now(), 'note' => $note,
            ]);

            $lines = $entry->lines->map(fn (GlLine $l) => [
                'account_id' => $l->id === $line->id ? $to->id : $l->account_id,
                'debit' => (float) $l->debit,
                'credit' => (float) $l->credit,
                'rule_account_id' => $l->id === $line->id ? $l->account_id : null,
                'overridden' => $l->id === $line->id,
            ])->all();

            return $this->writeEntry(today(), __('gl.correction_of', ['number' => $entry->number]).($note ? ' — '.$note : ''), $lines, $by, 'manual', $entry->id, false);
        });
    }
}
