<?php

namespace App\Services\Gl;

use App\Contracts\Gl\AdvancesRegister;
use App\Contracts\Gl\FixedAssetRegister;
use App\Contracts\Gl\OpeningProvider;
use App\Models\Gl\GlAccount;
use App\Models\Gl\GlEntry;
use App\Models\Gl\GlOpeningLine;
use App\Models\Setting;
use App\Models\User;
use App\Services\Gl\Opening\InventoryOpening;
use App\Services\Gl\Opening\PayablesOpening;
use App\Services\Gl\Opening\ReceivablesOpening;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ═══ قيد الافتتاح الواحد (الخط ٣، ١٩/١٠/٢٠٢٦ — D6) ═══
 *
 * بيجمع: السطور اليدوية (`gl_opening_lines`) + المصادر (العملاء، الموردين،
 * المخزون، الأصول الثابتة، السلف + أي مصدر متسجّل بـ`register()`). أي فرق
 * بين المدين والدائن بيروح على `opening_difference` — وبيفضل ظاهر كتحذير
 * لحد ما يبقى صفر (`differenceBalance()`).
 *
 * ⚠️ `post()` بيعيد كتابة القيد كله كل مرة (`Ledger::opening` بيمسح القديم
 * في نفس الترانزاكشن) — مفيش «إضافة على الافتتاحي».
 */
class OpeningBuilder
{
    /** @var list<OpeningProvider> */
    private array $extra = [];

    public function __construct(private Ledger $ledger)
    {
    }

    /** تاريخ القيد = يوم قبل `gl_start_date` — null لو البداية مش متحددة */
    public function asOf(): ?Carbon
    {
        $start = Setting::read('gl_start_date');

        return $start ? Carbon::parse($start)->subDay()->startOfDay() : null;
    }

    public function register(OpeningProvider $p): void
    {
        $this->extra[] = $p;
    }

    /** @return list<OpeningProvider> */
    public function providers(): array
    {
        return [
            app(ReceivablesOpening::class),
            app(PayablesOpening::class),
            app(InventoryOpening::class),
            app(FixedAssetRegister::class),
            app(AdvancesRegister::class),
            ...$this->extra,
        ];
    }

    /**
     * @return array{lines: list<array{account_id:int, cost_center_id:?int, debit:float, credit:float, memo:?string, source:string}>,
     *               by_source: array<string, array{label:string, debit:float, credit:float}>,
     *               debit:float, credit:float, difference:float}
     */
    public function compose(): array
    {
        $asOf = $this->asOf();
        if ($asOf === null) {
            throw new \InvalidArgumentException(__('golive.opening_no_start'));
        }

        $lines = [];
        $bySource = [];
        $add = function (string $source, string $label, array $raw) use (&$lines, &$bySource) {
            $bySource[$source] ??= ['label' => $label, 'debit' => 0.0, 'credit' => 0.0];
            foreach ($raw as $l) {
                $dr = round((float) ($l['debit'] ?? 0), 2);
                $cr = round((float) ($l['credit'] ?? 0), 2);
                if ($dr == 0.0 && $cr == 0.0) {
                    continue;
                }
                $lines[] = [
                    'account_id' => (int) $l['account_id'],
                    'cost_center_id' => isset($l['cost_center_id']) ? (int) $l['cost_center_id'] : null,
                    'debit' => $dr,
                    'credit' => $cr,
                    'memo' => $l['memo'] ?? null,
                    'source' => $source,
                ];
                $bySource[$source]['debit'] = round($bySource[$source]['debit'] + $dr, 2);
                $bySource[$source]['credit'] = round($bySource[$source]['credit'] + $cr, 2);
            }
        };

        $add('manual', __('golive.opening_src_manual'), GlOpeningLine::orderBy('id')->get()->map(fn ($r) => [
            'account_id' => $r->account_id,
            'cost_center_id' => $r->cost_center_id,
            'debit' => (float) $r->debit,
            'credit' => (float) $r->credit,
            'memo' => $r->note,
        ])->all());

        foreach ($this->providers() as $p) {
            $add($p->key(), $p->label(), $p->lines($asOf));
        }

        $debit = round(array_sum(array_column($lines, 'debit')), 2);
        $credit = round(array_sum(array_column($lines, 'credit')), 2);

        return [
            'lines' => $lines,
            'by_source' => $bySource,
            'debit' => $debit,
            'credit' => $credit,
            'difference' => round($debit - $credit, 2),
        ];
    }

    /** ترحيل (أو إعادة ترحيل) قيد الافتتاح — الفرق على `opening_difference` */
    public function post(User $by): GlEntry
    {
        return DB::transaction(function () use ($by) {
            $lines = $this->entryLines($this->compose());
            if ($lines === []) {
                throw new \InvalidArgumentException(__('golive.opening_empty'));
            }

            return $this->ledger->opening($this->asOf(), $lines, $by, __('golive.opening_memo', ['user' => $by->name]));
        });
    }

    /** سطور القيد زي ما هتترحّل: المجمّع + سطر الفرق لو فيه */
    private function entryLines(array $c): array
    {
        $lines = $c['lines'];
        if ($lines !== [] && $c['difference'] != 0.0) {
            $diff = $c['difference'];
            $lines[] = [
                'account_id' => GlAccount::openingDifference()->id,
                'cost_center_id' => null,
                'debit' => $diff < 0 ? -$diff : 0.0,
                'credit' => $diff > 0 ? $diff : 0.0,
                'memo' => __('golive.opening_difference_memo'),
                'source' => 'difference',
            ];
        }

        return $lines;
    }

    /**
     * القيد المرحّل قديم؟ — أي تغيير بعد الترحيل (رصيد بالاسم، سطر يدوي،
     * حركة مخزون، تاريخ البداية) بيخلي الصافي لكل (حساب، مركز) مختلف عن
     * المرحّل، أو تاريخه مختلف. false لو مفيش قيد مرحّل أصلاً.
     */
    public function isStale(): bool
    {
        $posted = GlEntry::where('origin', 'opening')->where('rule_key', Ledger::OPENING_RULE)->with('lines')->latest('id')->first();
        $asOf = $this->asOf();
        if ($posted === null || $asOf === null) {
            return false;
        }
        if ($posted->date->toDateString() !== $asOf->toDateString()) {
            return true;
        }
        $net = function (iterable $lines): array {
            $out = [];
            foreach ($lines as $l) {
                $l = (array) (is_array($l) ? $l : $l->only(['account_id', 'cost_center_id', 'debit', 'credit']));
                $k = $l['account_id'].'|'.($l['cost_center_id'] ?? '');
                $out[$k] = round(($out[$k] ?? 0.0) + (float) $l['debit'] - (float) $l['credit'], 2);
            }

            return array_filter($out, fn ($v) => abs($v) >= 0.005);
        };
        $want = $net($this->entryLines($this->compose()));
        $have = $net($posted->lines);
        ksort($want);
        ksort($have);

        return $want != $have;
    }

    /** رصيد حساب الفرق الحي في الدفتر — التحذير بيفضل لحد ما يبقى صفر */
    public function differenceBalance(): float
    {
        $acc = GlAccount::where('system_key', 'opening_difference')->first();

        return $acc ? $acc->balanceBetween(null, null) : 0.0;
    }
}
