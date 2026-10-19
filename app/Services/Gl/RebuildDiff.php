<?php

namespace App\Services\Gl;

use App\Models\Gl\GlAccount;
use App\Models\Gl\GlEntry;
use App\Models\Gl\GlRebuildRun;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ═══ إعادة البناء بفروق تفصيلية: معاينة → موافقة → تنفيذ (الخط ٣ — D8/D9) ═══
 *
 * • المعاينة: `Ledger::rebuild(dryRun, skipClosed)` + مجس جوه الترانزاكشن
 *   بياخد صورة القيود الآلية بعد التوليد وبيشغّل الفحوص قبل الـrollback.
 * • هوية القيد الآلي = `source_type|source_id`. التوقيع = سطوره مترتبة
 *   (الخانة، الحساب، مركز التكلفة، مدين، دائن). اتعمل = المفتاح بعد بس ·
 *   اتمسح = قبل بس · اتغيّر = الاتنين بتوقيع مختلف (الفرق = إجمالي بعد − قبل).
 * • التنفيذ مربوط بمعاينة `ok` اتوافق عليها في آخر ٢٤ ساعة.
 * • `addCheck()` — الخط ٤ (الخزن) والخط ٩ (التكاليف) بيضيفوا فحوصهم.
 */
class RebuildDiff
{
    /** @var array<string, callable(): array{label_key:string, expected:float, actual:float, ok:bool}> */
    private static array $extraChecks = [];

    public static function addCheck(string $key, callable $fn): void
    {
        self::$extraChecks[$key] = $fn;
    }

    public static function removeCheck(string $key): void
    {
        unset(self::$extraChecks[$key]);
    }

    public function __construct(private Ledger $ledger)
    {
    }

    public static function from(): ?Carbon
    {
        $start = Setting::read('gl_start_date');

        return $start ? Carbon::parse($start)->startOfDay() : null;
    }

    public function preview(User $by): GlRebuildRun
    {
        return $this->run('preview', $by);
    }

    /** آخر معاينة بس هي اللي تتوافق/تتنفذ — معاينة أقدم ممكن تكون اتغطّت بواحدة فشلت */
    private function assertLatest(GlRebuildRun $preview): void
    {
        $latest = GlRebuildRun::where('mode', 'preview')->max('id');
        if ((int) $latest !== (int) $preview->id) {
            throw new \InvalidArgumentException(__('golive.rebuild_not_latest'));
        }
    }

    /** في تشغيل ناجح اتعمل خلاص (الكرون والقفل التلقائي مستنيينه) */
    public static function executed(): bool
    {
        return GlRebuildRun::where('mode', 'execute')->where('status', 'ok')->exists();
    }

    public function approve(GlRebuildRun $preview, User $by): void
    {
        if ($preview->mode !== 'preview' || $preview->status !== 'ok') {
            throw new \InvalidArgumentException(__('golive.rebuild_approve_not_ok'));
        }
        $this->assertLatest($preview);
        if ($preview->created_at === null || $preview->created_at->lt(now()->subDay())) {
            throw new \InvalidArgumentException(__('golive.rebuild_preview_expired'));
        }
        // فحص فاشل (العملاء ≠ الدفتر، ميزان مش متوازن...) = مفيش موافقة
        if (collect($preview->checks ?? [])->contains(fn ($c) => ! $c['ok'])) {
            throw new \InvalidArgumentException(__('golive.rebuild_checks_failed'));
        }
        $preview->forceFill(['approved_at' => now(), 'approved_by' => $by->id])->save();
    }

    /** @param  (callable(int, int, string): void)|null  $progress */
    public function execute(GlRebuildRun $preview, User $by, ?callable $progress = null): GlRebuildRun
    {
        if ($preview->mode !== 'preview' || $preview->status !== 'ok' || $preview->approved_at === null) {
            throw new \InvalidArgumentException(__('golive.rebuild_needs_approval'));
        }
        if ($preview->approved_at->lt(now()->subDay())) {
            throw new \InvalidArgumentException(__('golive.rebuild_preview_expired'));
        }
        $this->assertLatest($preview);
        // المعاينة الواحدة = تنفيذ واحد
        if (GlRebuildRun::where('mode', 'execute')->where('preview_id', $preview->id)->where('status', 'ok')->exists()) {
            throw new \InvalidArgumentException(__('golive.rebuild_already_executed'));
        }

        return $this->run('execute', $by, $preview, $progress);
    }

    private function run(string $mode, User $by, ?GlRebuildRun $preview = null, ?callable $progress = null): GlRebuildRun
    {
        $tick = function (int $done, string $phase) use ($progress) {
            if ($progress !== null) {
                $progress($done, 4, $phase);
            }
        };
        $from = self::from();
        $base = ['mode' => $mode, 'from_date' => ($from ?? today())->toDateString(), 'preview_id' => $preview?->id, 'created_by' => $by->id];

        if ($from === null) {
            return GlRebuildRun::create($base + ['status' => 'failed', 'error' => __('golive.opening_no_start')]);
        }
        try {
            GlMapping::assertComplete();
        } catch (UnmappedRules $e) {
            return GlRebuildRun::create($base + ['status' => 'blocked', 'error' => mb_substr(implode(' · ', $e->problems), 0, 500)]);
        }

        $tick(0, 'snapshot');
        $before = $this->snapshot($from);
        $tick(1, 'snapshot');

        $tick(1, 'rebuild');
        try {
            $report = $this->ledger->rebuild($from, keepOverrides: true, dryRun: $mode === 'preview', by: $by,
                skipClosed: true, probe: function (RebuildReport $r) use ($mode, $from) {
                    $probe = ['after' => $this->snapshot($from), 'checks' => $this->checks($r)];
                    // التنفيذ الحقيقي: فحص فاشل (الداتا اتغيرت بعد المعاينة) = rollback
                    if ($mode === 'execute' && collect($probe['checks'])->contains(fn ($c) => ! $c['ok'])) {
                        $r->probe = $probe;
                        $e = new RebuildFailed(__('golive.rebuild_checks_failed'));
                        $e->report = $r;
                        throw $e;
                    }

                    return $probe;
                });
        } catch (RebuildFailed $e) {
            $report = $e->report;
            $failed = $e->getMessage();
        } catch (ClosedPeriod $e) {
            return GlRebuildRun::create($base + ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
        }
        $tick(2, 'rebuild');

        $tick(2, 'diff');
        [$summary, $rows] = $this->diff($before, $report?->probe['after'] ?? $before);
        $summary['overrides_kept'] = $report?->overridesKept ?? 0;
        $summary['overrides_dropped'] = $report?->overridesDropped ?? 0;
        $summary['frozen'] = $report?->frozenPeriods ?? [];
        $tick(3, 'diff');

        $tick(3, 'checks');
        $checks = $report?->probe['checks'] ?? [];
        $allOk = collect($checks)->every(fn ($c) => $c['ok']);
        $tick(4, 'checks');

        return GlRebuildRun::create($base + [
            'status' => isset($failed) ? 'failed' : 'ok',
            'error' => isset($failed) ? mb_substr($failed, 0, 500) : null,
            'summary' => $summary + ['checks_ok' => $allOk],
            'rows' => $rows,
            'checks' => $checks,
        ]);
    }

    /**
     * صورة القيود الآلية من تاريخ البداية: مفتاح المصدر → فترة/نوع/توقيع/إجمالي.
     *
     * @return array<string, array{period:string, source:string, sig:string, total:float}>
     */
    public function snapshot(Carbon $from): array
    {
        $out = [];
        GlEntry::where('origin', 'auto')->whereDate('date', '>=', $from->toDateString())
            ->with('lines')->orderBy('id')->chunk(500, function ($entries) use (&$out) {
                foreach ($entries as $e) {
                    $lines = $e->lines->map(fn ($l) => implode('/', [
                        $l->slot ?? '', $l->account_id, $l->cost_center_id ?? '',
                        number_format((float) $l->debit, 2, '.', ''), number_format((float) $l->credit, 2, '.', ''),
                    ]))->sort()->values()->all();
                    $out[$e->source_type.'|'.$e->source_id] = [
                        'period' => (string) $e->period_key,
                        'source' => self::sourceName((string) $e->source_type),
                        'sig' => implode(';', $lines),
                        'total' => round((float) $e->lines->sum('debit'), 2),
                    ];
                }
            });

        return $out;
    }

    public static function sourceName(string $morph): string
    {
        return class_basename(Relation::getMorphedModel($morph) ?? $morph);
    }

    /**
     * @param  array<string, array{period:string, source:string, sig:string, total:float}>  $before
     * @param  array<string, array{period:string, source:string, sig:string, total:float}>  $after
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    public function diff(array $before, array $after): array
    {
        $summary = ['created' => 0, 'deleted' => 0, 'changed' => 0,
            'created_amount' => 0.0, 'deleted_amount' => 0.0, 'changed_delta' => 0.0, 'samples' => []];
        $rows = [];
        $bump = function (array $e, string $kind, float $amount) use (&$rows) {
            $k = $e['period'].'|'.$e['source'];
            $rows[$k] ??= ['period' => $e['period'], 'source' => $e['source'], 'created' => 0, 'deleted' => 0, 'changed' => 0,
                'created_amount' => 0.0, 'deleted_amount' => 0.0, 'changed_delta' => 0.0];
            $rows[$k][$kind]++;
            $field = $kind === 'changed' ? 'changed_delta' : $kind.'_amount';
            $rows[$k][$field] = round($rows[$k][$field] + $amount, 2);
        };

        foreach ($after as $key => $a) {
            $b = $before[$key] ?? null;
            if ($b === null) {
                $summary['created']++;
                $summary['created_amount'] += $a['total'];
                $bump($a, 'created', $a['total']);
            } elseif ($b['sig'] !== $a['sig']) {
                $delta = round($a['total'] - $b['total'], 2);
                $summary['changed']++;
                $summary['changed_delta'] += $delta;
                $bump($a, 'changed', $delta);
                if (count($summary['samples']) < 20) {
                    $summary['samples'][] = ['key' => $key, 'period' => $a['period'], 'before' => $b['total'], 'after' => $a['total']];
                }
            }
        }
        foreach ($before as $key => $b) {
            if (! isset($after[$key])) {
                $summary['deleted']++;
                $summary['deleted_amount'] += $b['total'];
                $bump($b, 'deleted', $b['total']);
            }
        }
        foreach (['created_amount', 'deleted_amount', 'changed_delta'] as $f) {
            $summary[$f] = round($summary[$f], 2);
        }
        ksort($rows);

        return [$summary, array_values($rows)];
    }

    /**
     * فحوص التشغيل — بتتنادى جوه ترانزاكشن إعادة البناء (المجس) عشان تشوف
     * الدفتر الجديد قبل الـrollback.
     *
     * @return list<array{key:string, label_key:string, expected:float, actual:float, ok:bool}>
     */
    public function checks(?RebuildReport $report = null): array
    {
        $ok = fn (float $e, float $a) => abs(round($e - $a, 2)) < 0.005;
        $out = [];

        // العملاء: الشجرة (كل التواريخ — الافتتاحي + الآلي) مقابل أرصدة العملاء
        // من كشوفهم (من غير الأمانة — نفس معادلة الفحص الثابت)
        $recvGl = GlAccount::findKey('receivables')->balanceBetween(null, null);
        $recvExp = round((float) DB::table('transactions')->where('kind', '!=', 'consignment')
            ->selectRaw('COALESCE(SUM(debit - credit),0) v')->value('v'), 2);
        $out[] = ['key' => 'receivables_total', 'label_key' => 'golive.check_receivables_total', 'expected' => $recvExp, 'actual' => $recvGl, 'ok' => $ok($recvExp, $recvGl)];

        $payGl = GlAccount::findKey('payables')->balanceBetween(null, null);
        $payExp = round((float) DB::table('supplier_transactions')->selectRaw('COALESCE(SUM(credit - debit),0) v')->value('v'), 2);
        $out[] = ['key' => 'payables_total', 'label_key' => 'golive.check_payables_total', 'expected' => $payExp, 'actual' => $payGl, 'ok' => $ok($payExp, $payGl)];

        $sums = DB::table('gl_lines')->selectRaw('COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c')->first();
        $d = round((float) $sums->d, 2);
        $c = round((float) $sums->c, 2);
        $out[] = ['key' => 'trial_balanced', 'label_key' => 'golive.check_trial_balanced', 'expected' => $d, 'actual' => $c, 'ok' => $ok($d, $c)];

        if ($report !== null) {
            $bad = collect($report->invariants)->filter(fn ($i) => ! $i['ok'])->count();
            $out[] = ['key' => 'invariants', 'label_key' => 'golive.check_invariants', 'expected' => 0.0, 'actual' => (float) $bad, 'ok' => $bad === 0];
        }

        foreach (self::$extraChecks as $key => $fn) {
            $r = $fn();
            $out[] = ['key' => $key, 'label_key' => $r['label_key'], 'expected' => round((float) $r['expected'], 2),
                'actual' => round((float) $r['actual'], 2), 'ok' => (bool) $r['ok']];
        }

        return $out;
    }
}
