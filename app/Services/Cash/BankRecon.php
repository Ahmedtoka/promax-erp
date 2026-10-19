<?php

namespace App\Services\Cash;

use App\Models\BankStatement;
use App\Models\BankStatementMark;
use App\Models\Gl\GlLine;
use App\Models\Safe;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * تسوية البنك مع كشف الحساب (L4-D6). ⚠️ **مفيش كتابة في `gl_*` هنا ولا
 * في الكنترولر** — العلامة صف في `bank_statement_marks` على `line_key`
 * ثابت، فإعادة البناء اللي بتغيّر أرقام سطور القيود مابتضيّعش العلامات.
 */
class BankRecon
{
    /**
     * مفتاح ثابت للسطر: القيد الآلي = `<نوع المصدر>|<id المصدر>|<الخانة>`
     * (نفس مفتاح التحويلات في `Ledger::rebuild`) — غير كده
     * `entry:<id>|line:<id>` لأن القيد اليدوي مابيتعادش بناؤه.
     */
    public function lineKey(GlLine $l): string
    {
        $e = $l->relationLoaded('entry') ? $l->entry : null;

        return $this->keyFor(
            $l->id, $l->entry_id, $l->slot,
            $e?->origin ?? $l->getAttribute('origin'),
            $e?->source_type ?? $l->getAttribute('source_type'),
            $e?->source_id ?? $l->getAttribute('source_id'),
        );
    }

    private function keyFor(int $lineId, int $entryId, ?string $slot, ?string $origin, ?string $sourceType, $sourceId): string
    {
        if ($origin === 'auto' && $sourceType && $sourceId) {
            // ⚠️ اسم الكلاس من غير الـnamespace — العمود 80 حرف بس
            return substr(class_basename($sourceType).'|'.$sourceId.'|'.($slot ?? 'x'), 0, 80);
        }

        return 'entry:'.$entryId.'|line:'.$lineId;
    }

    /**
     * سطور حساب البنك في الدفتر في نافذة (شامل، على تاريخ القيد)، مرتبة
     * بالتاريخ ثم الرقم. كل سطر عليه `line_key` و`amount` موقّع (مدين −
     * دائن) و`statement_id` لو متعلّم عليه — العلامات في استعلام واحد.
     *
     * @return Collection<int, GlLine>
     */
    public function lines(Safe $bank, ?Carbon $from, ?Carbon $to): Collection
    {
        if (! $bank->gl_account_id) {
            return collect();
        }

        $lines = GlLine::query()
            ->join('gl_entries', 'gl_entries.id', '=', 'gl_lines.entry_id')
            ->where('gl_lines.account_id', $bank->gl_account_id)
            ->when($from, fn ($q) => $q->whereDate('gl_entries.date', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->whereDate('gl_entries.date', '<=', $to->toDateString()))
            ->orderBy('gl_entries.date')->orderBy('gl_lines.id')
            ->get(['gl_lines.*', 'gl_entries.date as entry_date', 'gl_entries.number as entry_number',
                'gl_entries.memo as entry_memo', 'gl_entries.origin', 'gl_entries.source_type', 'gl_entries.source_id']);

        foreach ($lines as $l) {
            $l->line_key = $this->keyFor($l->id, $l->entry_id, $l->slot, $l->origin, $l->source_type, $l->source_id);
            $l->amount = round((float) $l->debit - (float) $l->credit, 2);
            $l->entry_date = Carbon::parse($l->entry_date)->startOfDay();
        }

        $marks = $lines->isEmpty() ? collect() : BankStatementMark::whereIn('line_key', $lines->pluck('line_key')->unique()->all())
            ->pluck('statement_id', 'line_key');
        foreach ($lines as $l) {
            $l->statement_id = isset($marks[$l->line_key]) ? (int) $marks[$l->line_key] : null;
        }

        return $lines;
    }

    /**
     * أرقام الكشف: الدفتر = رصيد الحساب لحد آخر الكشف · المسوّى = رصيد
     * أول الكشف (اللي دخّله المستخدم) + مجموع السطور المتعلّم عليها ·
     * الفرق = رصيد آخر الكشف − المسوّى، ولازم يبقى صفر عشان يتقفل.
     *
     * @return array{book: float, cleared: float, statement: float, difference: float}
     */
    public function summary(BankStatement $s): array
    {
        $bank = $s->safe;
        $marked = (float) DB::table('bank_statement_marks')->where('statement_id', $s->id)->sum('amount');
        $cleared = round((float) $s->opening_balance + $marked, 2);
        $statement = round((float) $s->closing_balance, 2);

        return [
            'book' => $bank ? $bank->balance($s->date_to) : 0.0,
            'cleared' => $cleared,
            'statement' => $statement,
            'difference' => round($statement - $cleared, 2),
        ];
    }
}
