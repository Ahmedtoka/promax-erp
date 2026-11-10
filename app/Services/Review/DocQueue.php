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
            ->get(['id', 'code', 'name', 'name_en', 'phone', 'group_id'])->keyBy('id');
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

        return $rows->map(fn ($r) => $r + ['dup' => $r['paper_ref'] !== null && isset($dups[$r['paper_ref']])]);
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
            'pct' => $total === 0 ? 0 : (int) floor($ok * 100 / $total),
        ];
    }

    /** فلاتر الشكل (مش الحالة) — اللي النسب بتتحسب عليها */
    public static function filter(Collection $rows, array $f): Collection
    {
        $q = trim((string) ($f['q'] ?? ''));
        $norm = fn (string $s) => mb_strtolower(Client::normalizeArabic($s));
        $nq = $q === '' ? '' : $norm($q);
        $amount = is_numeric(str_replace(',', '', $q)) ? (float) str_replace(',', '', $q) : null;

        return $rows->filter(function ($r) use ($f, $nq, $norm, $amount) {
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
            if (($f['flag'] ?? '') === 'dup' && ! $r['dup']) {
                return false;
            }
            if (($f['flag'] ?? '') === 'noserial' && $r['paper_ref'] !== null) {
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
            'pct' => $total === 0 ? 0 : (int) floor($ok * 100 / $total),
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
