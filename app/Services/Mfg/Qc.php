<?php

namespace App\Services\Mfg;

use App\Exceptions\Rejected;
use App\Models\Batch;
use App\Models\ProductionOrder;
use App\Models\ProductSpec;
use App\Models\User;
use App\Services\StockCounting;
use Illuminate\Support\Facades\DB;

/**
 * قرار الجودة على باتش التام (التصنيع ١٩/١٠، spec §3.5، D7).
 *
 * من الحجر بس: إفراج ← الباتش `blocked = false` ويبان للتوزيع (FEFO،
 * `availableFor`)؛ رفض ← الباتش يفضل موقوف في المخزن والملاحظة تتضاف
 * لملاحظاته (التخلص بيمشي من الجرد/التسوية). الملاحظة إجبارية في الحالتين.
 * النتايج = قيمة مقاسة لكل بند من حدود الجودة في المواصفة المعتمدة.
 */
class Qc
{
    public const DECISIONS = ['released', 'rejected'];

    /** @param  array<string, mixed>  $results  param ⇒ القيمة المقاسة */
    public static function decide(ProductionOrder $o, string $decision, string $note, User $by, array $results = []): void
    {
        if (! in_array($decision, self::DECISIONS, true)) {
            throw new Rejected(__('mfg.qc_bad_decision'));
        }

        $note = trim($note);
        if ($note === '') {
            throw new Rejected(__('mfg.qc_note_required'));
        }

        DB::transaction(function () use ($o, $decision, $note, $by, $results) {
            $row = ProductionOrder::query()->whereKey($o->id)->lockForUpdate()->first();

            if ($row === null || $row->qc_status !== 'quarantine') {
                throw new Rejected(__('mfg.qc_bad_state'));
            }

            $batch = $row->fg_batch_id
                ? Batch::query()->whereKey($row->fg_batch_id)->lockForUpdate()->first()
                : null;
            if ($batch === null) {
                throw new Rejected(__('mfg.qc_no_batch'));
            }

            if ($decision === 'released') {
                $batch->forceFill(['blocked' => false])->save();
            } else {
                $line = __('mfg.qc_rejected_note', ['note' => $note]);
                $batch->forceFill([
                    'blocked' => true,
                    'notes' => trim(($batch->notes ? $batch->notes."\n" : '').$line),
                ])->save();
            }

            StockCounting::resync($batch->product_id, $batch->warehouse_id);

            $row->forceFill([
                'qc_status' => $decision,
                'qc_note' => $note,
                'qc_results' => self::results($row, $results) ?: null,
                'qc_by' => $by->id,
                'qc_at' => now(),
            ])->save();

            $o->setRawAttributes($row->getAttributes(), true);
        });
    }

    /**
     * النتايج المقاسة لبنود حدود المواصفة المعتمدة بس — بند مش في المواصفة
     * أو قيمة فاضية/مش رقم بيتساب.
     *
     * @param  array<string, mixed>  $results
     * @return array<string, float>
     */
    private static function results(ProductionOrder $o, array $results): array
    {
        $o->loadMissing('product');
        $spec = $o->product ? ProductSpec::approvedFor($o->product) : null;

        $out = [];
        foreach (self::limits($spec) as $limit) {
            $param = $limit['param'];
            $v = $results[$param] ?? null;
            if ($v === null || $v === '' || ! is_numeric($v)) {
                continue;
            }
            $out[$param] = (float) $v;
        }

        return $out;
    }

    /**
     * حدود الجودة في المواصفة: `{param, min, max, unit}` — البند من غير اسم بيتساب.
     *
     * @return list<array{param:string, min:mixed, max:mixed, unit:mixed}>
     */
    public static function limits(?ProductSpec $spec): array
    {
        return collect($spec?->qc_limits ?? [])
            ->filter(fn ($l) => is_array($l) && trim((string) ($l['param'] ?? '')) !== '')
            ->map(fn (array $l) => [
                'param' => trim((string) $l['param']),
                'min' => $l['min'] ?? null,
                'max' => $l['max'] ?? null,
                'unit' => $l['unit'] ?? null,
            ])->values()->all();
    }
}
