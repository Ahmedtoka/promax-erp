<?php

namespace App\Services\Review;

use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\MotherCompany;
use App\Models\ReviewCheck;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * طابور مراجعة المستندات (١١/١١) — كل فاتورة وكل أمر توريد مش ملغي،
 * بعميله وسلسلته وأمّه وحالة مراجعته، ونسب الإنجاز لكل مستوى.
 *
 * الحجم الحقيقي ~٧٠٠ مستند — فبنجيبهم كلهم في ٤ كويريز (فواتير،
 * أوامر، بنود مجمّعة، علامات) ونفلتر ونجمّع في PHP. مفيش N+1.
 */
class DocQueue
{
    public const STATUSES = ['pending', 'ok', 'issue', 'changed'];

    /**
     * كل المستندات اللي اليوزر شايفها.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function all(?User $user, ?string $only = null): Collection
    {
        // `$only` = «inv-12» / «po-7»: صف واحد بعد حركة (من غير ما نبني الطابور كله)
        [$onlyType, $onlyId] = $only === null ? [null, null] : [str_starts_with($only, 'inv-') ? 'invoice' : 'po', (int) substr($only, strpos($only, '-') + 1)];

        $clients = Client::visibleTo(Client::query(), $user)
            ->where('category', '!=', 'internal')
            ->get(['id', 'code', 'name', 'name_en', 'phone', 'group_id', 'rep_id'])->keyBy('id');
        $ids = $clients->keys()->all();

        $groups = ClientGroup::whereIn('id', $clients->pluck('group_id')->filter()->unique())
            ->get(['id', 'name', 'name_en', 'mother_company_id'])->keyBy('id');
        $mothers = MotherCompany::whereIn('id', $groups->pluck('mother_company_id')->filter()->unique())
            ->get(['id', 'name', 'name_en'])->keyBy('id');
        $reps = User::whereIn('id', DB::table('invoices')->whereIn('client_id', $ids)->distinct()->pluck('user_id')
            ->merge(DB::table('purchase_orders')->whereIn('client_id', $ids)->distinct()->pluck('assigned_to'))->filter()->unique())
            ->get(['id', 'name', 'name_en'])->keyBy('id');

        $invItems = DB::table('invoice_items')->whereIn('invoice_id', DB::table('invoices')->whereIn('client_id', $ids)->select('id'))
            ->when($onlyType !== null, fn ($q) => $q->where('invoice_id', $onlyType === 'invoice' ? $onlyId : 0))
            ->groupBy('invoice_id')->selectRaw('invoice_id AS id, SUM(qty) AS pcs, COUNT(DISTINCT product_id) AS products')->get()->keyBy('id');
        $poItems = DB::table('purchase_order_items')->whereIn('purchase_order_id', DB::table('purchase_orders')->whereIn('client_id', $ids)->select('id'))
            ->when($onlyType !== null, fn ($q) => $q->where('purchase_order_id', $onlyType === 'po' ? $onlyId : 0))
            ->groupBy('purchase_order_id')->selectRaw('purchase_order_id AS id, SUM(COALESCE(delivered_qty, qty)) AS pcs, COUNT(DISTINCT product_id) AS products')->get()->keyBy('id');

        $checks = [];
        foreach (ReviewCheck::with('user:id,name,name_en')->whereIn('entity_type', ['invoice', 'po'])->where('item', DocReview::ITEM)
            ->when($onlyType !== null, fn ($q) => $q->where('entity_type', $onlyType)->where('entity_id', $onlyId))->get() as $c) {
            $checks[$c->entity_type][$c->entity_id] = $c;
        }

        $rows = collect();
        $make = function (string $type, object $d, ?object $agg) use ($clients, $groups, $mothers, $reps, $checks) {
            $c = $clients[$d->client_id] ?? null;
            $g = $c?->group_id ? ($groups[$c->group_id] ?? null) : null;
            $m = $g?->mother_company_id ? ($mothers[$g->mother_company_id] ?? null) : null;
            $chk = $checks[$type][$d->id] ?? null;

            return [
                'type' => $type,
                'id' => (int) $d->id,
                'key' => ($type === 'invoice' ? 'inv-' : 'po-').$d->id,
                'number' => (string) $d->number,
                'paper_ref' => $d->paper_ref !== null && trim($d->paper_ref) !== '' ? trim($d->paper_ref) : null,
                'date' => substr((string) $d->doc_at, 0, 10),
                'client_id' => (int) $d->client_id,
                'client' => $c?->displayName() ?? '#'.$d->client_id,
                'client_code' => (string) $c?->code,
                'phone' => (string) $c?->phone,
                'group_id' => $g?->id,
                'group' => $g?->displayName(),
                'mother_id' => $m?->id,
                'mother' => $m?->displayName(),
                'rep_id' => $d->rep_id ? (int) $d->rep_id : null,
                'rep' => $d->rep_id ? $reps[$d->rep_id]?->displayName() : null,
                'status_doc' => (string) $d->st,
                'net' => (float) $d->total,
                'tax' => (float) $d->tax_total,
                'grand' => (float) $d->grand_total,
                'pcs' => (int) ($agg->pcs ?? 0),
                'products' => (int) ($agg->products ?? 0),
                'state' => DocReview::state($chk, (float) $d->grand_total),
                'by' => $chk?->user?->displayName(),
                'by_id' => $chk?->user_id ? (int) $chk->user_id : null,
                'at' => $chk?->updated_at?->format('Y-m-d H:i'),
                'note' => $chk?->note,
            ];
        };

        foreach (DB::table('invoices')->whereIn('client_id', $ids)
            ->when($onlyType !== null, fn ($q) => $onlyType === 'invoice' ? $q->where('id', $onlyId) : $q->whereRaw('0 = 1'))
            ->get(['id', 'number', 'paper_ref', 'client_id', 'user_id AS rep_id', 'created_at AS doc_at', 'payment AS st', 'total', 'tax_total', 'grand_total']) as $d) {
            $rows->push($make('invoice', $d, $invItems[$d->id] ?? null));
        }
        foreach (DB::table('purchase_orders')->whereIn('client_id', $ids)->where('status', '!=', 'cancelled')
            ->when($onlyType !== null, fn ($q) => $onlyType === 'po' ? $q->where('id', $onlyId) : $q->whereRaw('0 = 1'))
            ->selectRaw('id, number, paper_ref, client_id, assigned_to AS rep_id, COALESCE(delivered_at, due_at, created_at) AS doc_at, status AS st, total, tax_total, grand_total')
            ->get() as $d) {
            $rows->push($make('po', $d, $poItems[$d->id] ?? null));
        }

        // السيريال المكرر — بين الفواتير والأوامر مع بعض
        $dups = $onlyType === null
            ? $rows->whereNotNull('paper_ref')->countBy('paper_ref')->filter(fn ($n) => $n > 1)
            : $rows->whereNotNull('paper_ref')->mapWithKeys(fn ($r) => [$r['paper_ref'] => 1
                + DB::table('invoices')->where('paper_ref', $r['paper_ref'])->when($r['type'] === 'invoice', fn ($q) => $q->where('id', '!=', $r['id']))->count()
                + DB::table('purchase_orders')->where('paper_ref', $r['paper_ref'])->where('status', '!=', 'cancelled')->when($r['type'] === 'po', fn ($q) => $q->where('id', '!=', $r['id']))->count()])
                ->filter(fn ($n) => $n > 1);

        // (١٢/١١) التنبيهات الأوتوماتيك — بتفرز الطابور وبتظهر جنب الرقم
        $repriced = self::repricedKeys($onlyType, $onlyId);
        $today = now()->toDateString();
        $first = ReviewCheck::FIRST_MONTH.'-01';

        return $rows->map(function ($r) use ($dups, $repriced, $clients, $today, $first) {
            $r['dup'] = $r['paper_ref'] !== null && isset($dups[$r['paper_ref']]);
            $clientRep = $clients[$r['client_id']]->rep_id ?? null;
            $r['flags'] = array_values(array_filter([
                $r['paper_ref'] === null ? 'noserial' : null,
                $r['dup'] ? 'dup' : null,
                // أمر لسه ماتسلّمش تاريخه «ميعاد التسليم» — المستقبل فيه طبيعي
                ((($r['type'] === 'invoice' || $r['status_doc'] === 'delivered') && $r['date'] > $today) || $r['date'] < $first) ? 'odd_date' : null,
                ($clientRep && $r['rep_id'] && (int) $clientRep !== $r['rep_id']) ? 'rep_off' : null,
                isset($repriced[$r['key']]) ? 'repriced' : null,
                $r['state'] === 'changed' ? 'changed' : null,
            ]));

            return $r;
        });
    }

    public const FLAGS = ['noserial', 'dup', 'odd_date', 'rep_off', 'repriced', 'changed'];

    /** يوم بدء تصليح الأسعار — أي مستند إجماليه اتغير من بعده من بره المراجعة بيتعلّم «اتسعّر» */
    public const PRICE_FIX_DAY = '2026-11-10';

    /**
     * مستندات إجماليها اتغير من يوم تصليح الأسعار — من سجل التعديلات (المراقب العام
     * بيسجل grand_total قبل ← بعد)، من غير تعديلات شاشة المراجعة نفسها.
     *
     * @return array<string, true>  «inv-12» => true
     */
    public static function repricedKeys(?string $onlyType, ?int $onlyId): array
    {
        $map = ['Invoice' => 'inv-', 'PurchaseOrder' => 'po-'];

        return DB::table('activity_logs')
            ->whereIn('subject_type', $onlyType === null ? array_keys($map) : [$onlyType === 'invoice' ? 'Invoice' : 'PurchaseOrder'])
            ->when($onlyId !== null, fn ($q) => $q->where('subject_id', $onlyId))
            ->where('event', 'updated')->where('created_at', '>=', self::PRICE_FIX_DAY)
            ->where('changes', 'like', '%grand_total%')
            ->where(fn ($q) => $q->whereNull('route')->orWhere('route', 'not like', 'erp.review.docs%'))
            ->distinct()->get(['subject_type', 'subject_id'])
            ->mapWithKeys(fn ($r) => [$map[$r->subject_type].$r->subject_id => true])->all();
    }

    /**
     * الإنجاز الكلي بس (للشريط بعد كل حركة) — من غير بنود ولا أسماء.
     *
     * @return array<string, int>
     */
    public static function overall(?User $user): array
    {
        $ids = Client::visibleTo(Client::query(), $user)->where('category', '!=', 'internal')->select('id');
        $checks = ReviewCheck::whereIn('entity_type', ['invoice', 'po'])->where('item', DocReview::ITEM)
            ->get(['entity_type', 'entity_id', 'status', 'system_balance'])->keyBy(fn ($c) => $c->entity_type.$c->entity_id);
        $rows = collect();
        foreach (DB::table('invoices')->whereIn('client_id', $ids)->get(['id', 'grand_total']) as $d) {
            $rows->push(['type' => 'invoice', 'state' => DocReview::state($checks['invoice'.$d->id] ?? null, (float) $d->grand_total)]);
        }
        foreach (DB::table('purchase_orders')->whereIn('client_id', $ids)->where('status', '!=', 'cancelled')->get(['id', 'grand_total']) as $d) {
            $rows->push(['type' => 'po', 'state' => DocReview::state($checks['po'.$d->id] ?? null, (float) $d->grand_total)]);
        }
        $total = $rows->count();
        $ok = $rows->where('state', 'ok')->count();

        return [
            'total' => $total, 'ok' => $ok, 'issue' => $rows->where('state', 'issue')->count(),
            'changed' => $rows->where('state', 'changed')->count(), 'pending' => $rows->where('state', 'pending')->count(),
            'invoices' => $rows->where('type', 'invoice')->count(), 'pos' => $rows->where('type', 'po')->count(),
            'ok_inv' => $rows->where('type', 'invoice')->where('state', 'ok')->count(),
            'ok_po' => $rows->where('type', 'po')->where('state', 'ok')->count(),
            'pct' => self::pct($ok, $total),
        ];
    }

    /**
     * (١٢/١١) المراجعة مستند مستند ← الحسابات: لكل عميل كام مستند وكام فلوس
     * اتراجعوا، ولكل شهر كمان — عشان شاشة «الحسابات والشهور» تقول الرصيد ده
     * متراجع منه قد إيه، والشهر ده مستنداته خلصت ولا لأ.
     *
     * ⚠️ نفس تعريف الحالة بتاع الطابور (`DocReview::state`) — ✓ اللي إجمالي
     * المستند اتغير بعده بيتحسب «اتغيّر» مش متراجع.
     *
     * @param  iterable<int>  $clientIds
     * @return array<int, array{docs:int, ok:int, issue:int, grand:float, ok_grand:float, months: array<string, array{docs:int, ok:int}>}>
     */
    public static function moneyByClient(iterable $clientIds): array
    {
        $ids = collect($clientIds)->map(fn ($i) => (int) $i)->unique()->values()->all();
        if ($ids === []) {
            return [];
        }

        $checks = ReviewCheck::whereIn('entity_type', ['invoice', 'po'])->where('item', DocReview::ITEM)
            ->get(['id', 'entity_type', 'entity_id', 'status', 'system_balance'])->keyBy(fn ($c) => $c->entity_type.$c->entity_id);

        $docs = DB::table('invoices')->whereIn('client_id', $ids)
            ->selectRaw("'invoice' AS t, id, client_id, grand_total, created_at AS doc_at")
            ->unionAll(DB::table('purchase_orders')->whereIn('client_id', $ids)->where('status', '!=', 'cancelled')
                ->selectRaw("'po' AS t, id, client_id, grand_total, COALESCE(delivered_at, due_at, created_at) AS doc_at"))
            ->get();

        $out = [];
        foreach ($docs as $d) {
            $state = DocReview::state($checks[$d->t.$d->id] ?? null, (float) $d->grand_total);
            $o = &$out[(int) $d->client_id];
            $o ??= ['docs' => 0, 'ok' => 0, 'issue' => 0, 'grand' => 0.0, 'ok_grand' => 0.0, 'months' => []];
            $m = substr((string) $d->doc_at, 0, 7);
            $o['docs']++;
            $o['grand'] += (float) $d->grand_total;
            $o['months'][$m]['docs'] = ($o['months'][$m]['docs'] ?? 0) + 1;
            $o['months'][$m]['ok'] ??= 0;
            if ($state === 'ok') {
                $o['ok']++;
                $o['ok_grand'] += (float) $d->grand_total;
                $o['months'][$m]['ok']++;
            } elseif ($state === 'issue') {
                $o['issue']++;
            }
            unset($o);
        }

        return $out;
    }

    /**
     * جمع `moneyByClient` لمجموعة عملاء (سلسلة).
     *
     * @param  array<int, array>  $byClient
     * @param  iterable<int>  $clientIds
     */
    public static function moneySum(array $byClient, iterable $clientIds): array
    {
        $o = ['docs' => 0, 'ok' => 0, 'issue' => 0, 'grand' => 0.0, 'ok_grand' => 0.0, 'months' => []];
        foreach ($clientIds as $id) {
            $c = $byClient[(int) $id] ?? null;
            if ($c === null) {
                continue;
            }
            foreach (['docs', 'ok', 'issue', 'grand', 'ok_grand'] as $k) {
                $o[$k] += $c[$k];
            }
            foreach ($c['months'] as $m => $x) {
                $o['months'][$m]['docs'] = ($o['months'][$m]['docs'] ?? 0) + $x['docs'];
                $o['months'][$m]['ok'] = ($o['months'][$m]['ok'] ?? 0) + $x['ok'];
            }
        }
        $o['grand'] = round($o['grand'], 2);
        $o['ok_grand'] = round($o['ok_grand'], 2);

        return $o;
    }

    /** نسبة بكسر عشري لما تكون صغيرة — «0%» بعد أول ✓ من ٦٦٠ كان بيبان إن مفيش حاجة حصلت */
    public static function pct(int|float $part, int|float $whole): int|float
    {
        if ($whole <= 0) {
            return 0;
        }
        $p = $part * 100 / $whole;

        return $p >= 10 ? (int) floor($p) : floor($p * 10) / 10;
    }

    /** فلاتر الشكل (مش الحالة) — اللي النسب بتتحسب عليها */
    public static function filter(Collection $rows, array $f): Collection
    {
        $q = trim((string) ($f['q'] ?? ''));
        $norm = fn (string $s) => mb_strtolower(Client::normalizeArabic($s));
        $nq = $q === '' ? '' : $norm($q);
        $amount = is_numeric(str_replace(',', '', $q)) ? (float) str_replace(',', '', $q) : null;

        $out = $rows->filter(function ($r) use ($f, $nq, $norm, $amount) {
            if (! empty($f['type']) && $r['type'] !== $f['type']) {
                return false;
            }
            if (! empty($f['mother']) && (int) $r['mother_id'] !== (int) $f['mother']) {
                return false;
            }
            if (! empty($f['group'])) {
                if ($f['group'] === 'solo' ? $r['group_id'] !== null : (int) $r['group_id'] !== (int) $f['group']) {
                    return false;
                }
            }
            if (! empty($f['client']) && $r['client_id'] !== (int) $f['client']) {
                return false;
            }
            if (! empty($f['rep']) && (int) $r['rep_id'] !== (int) $f['rep']) {
                return false;
            }
            if (! empty($f['from']) && $r['date'] < $f['from']) {
                return false;
            }
            if (! empty($f['to']) && $r['date'] > $f['to']) {
                return false;
            }
            $flag = $f['flag'] ?? '';
            if ($flag === 'any' ? $r['flags'] === [] : ($flag !== '' && ! in_array($flag, $r['flags'], true))) {
                return false;
            }
            // عينة المراجِع التاني: مستندات راجعها فلان يوم كذا
            if (! empty($f['by']) && $r['by_id'] !== (int) $f['by']) {
                return false;
            }
            if (! empty($f['day']) && substr((string) $r['at'], 0, 10) !== $f['day']) {
                return false;
            }
            if ($nq !== '') {
                $hay = $norm(implode(' ', [$r['number'], $r['paper_ref'], $r['client'], $r['client_code'], $r['phone'], $r['group'], $r['mother'], $r['rep']]));
                $hit = str_contains($hay, $nq) || ($amount !== null && abs($r['grand'] - $amount) < 0.01);
                if (! $hit) {
                    return false;
                }
            }

            return true;
        })->values();

        // عينة ثابتة (نفس المستندات كل ما الصفحة تتفتح لنفس المراجِع واليوم) — مش «أول ن»
        $n = (int) ($f['sample'] ?? 0);
        if ($n > 0) {
            $seed = ($f['by'] ?? '').'|'.($f['day'] ?? '');
            $out = $out->sortBy(fn ($r) => crc32($seed.'|'.$r['key']))->take($n)->values();
        }

        return $out;
    }

    public static function byStatus(Collection $rows, string $status): Collection
    {
        return $status === 'all' ? $rows : $rows->where('state', $status)->values();
    }

    /**
     * أرقام مجموعة مستندات: العدد والفلوس والقطع والأصناف والمراجَع.
     *
     * @return array<string, int|float>
     */
    public static function stats(Collection $rows): array
    {
        $total = $rows->count();
        $ok = $rows->where('state', 'ok')->count();

        return [
            'total' => $total,
            'invoices' => $rows->where('type', 'invoice')->count(),
            'pos' => $rows->where('type', 'po')->count(),
            'net' => round($rows->sum('net'), 2),
            'tax' => round($rows->sum('tax'), 2),
            'grand' => round($rows->sum('grand'), 2),
            'pcs' => (int) $rows->sum('pcs'),
            'products' => (int) $rows->sum('products'),
            'ok' => $ok,
            'issue' => $rows->where('state', 'issue')->count(),
            'changed' => $rows->where('state', 'changed')->count(),
            'pending' => $rows->where('state', 'pending')->count(),
            'ok_inv' => $rows->where('type', 'invoice')->where('state', 'ok')->count(),
            'ok_po' => $rows->where('type', 'po')->where('state', 'ok')->count(),
            'pct' => self::pct($ok, $total),
        ];
    }

    /**
     * التجميع للعرض: أم ← سلسلة ← عميل. العملاء من غير سلسلة تحت «من غير سلسلة».
     *
     * @return list<array{key: string, label: string, mother: ?string, stats: array, rows: Collection}>
     */
    public static function grouped(Collection $rows): array
    {
        $out = [];
        foreach ($rows->groupBy(fn ($r) => $r['group_id'] ? 'g'.$r['group_id'] : 'c'.$r['client_id']) as $k => $g) {
            $first = $g->first();
            $out[] = [
                'key' => $k,
                'label' => $first['group'] ?? $first['client'],
                'solo' => $first['group_id'] === null,
                'mother' => $first['mother'],
                'stats' => self::stats($g),
                'rows' => $g->sortBy([['client', 'asc'], ['date', 'asc'], ['number', 'asc']])->values(),
            ];
        }

        // الأمهات الأول بالترتيب، وبعدين السلاسل، والعملاء الفرادى آخر حاجة
        usort($out, fn ($a, $b) => [$a['solo'], $a['mother'] ?? "\u{FFFF}", $a['label']] <=> [$b['solo'], $b['mother'] ?? "\u{FFFF}", $b['label']]);

        return $out;
    }
}
