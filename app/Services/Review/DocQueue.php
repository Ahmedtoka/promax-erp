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
    /** أنواع المستندات في المراجعة — `ReviewCheck.entity_type` و`doc_review_events.doc_type` */
    public const TYPES = ['invoice', 'po', 'return'];

    public const STATUSES = ['pending', 'ok', 'issue', 'changed'];

    /**
     * كل المستندات اللي اليوزر شايفها.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function all(?User $user, ?string $only = null): Collection
    {
        // `$only` = «inv-12» / «po-7» / «ret-3»: صف واحد بعد حركة (من غير ما نبني الطابور كله)
        [$onlyType, $onlyId] = $only === null ? [null, null] : DocReview::parseKey($only);

        $clients = Client::visibleTo(Client::query(), $user)
            ->where('category', '!=', 'internal')
            ->get(['id', 'code', 'name', 'name_en', 'phone', 'group_id', 'rep_id'])->keyBy('id');
        $ids = $clients->keys()->all();

        $groups = ClientGroup::whereIn('id', $clients->pluck('group_id')->filter()->unique())
            ->get(['id', 'name', 'name_en', 'mother_company_id'])->keyBy('id');
        $mothers = MotherCompany::whereIn('id', $groups->pluck('mother_company_id')->filter()->unique())
            ->get(['id', 'name', 'name_en'])->keyBy('id');
        $reps = User::whereIn('id', DB::table('invoices')->whereIn('client_id', $ids)->distinct()->pluck('user_id')
            ->merge(DB::table('purchase_orders')->whereIn('client_id', $ids)->distinct()->pluck('assigned_to'))
            ->merge(DB::table('returns')->whereIn('client_id', $ids)->distinct()->pluck('user_id'))->filter()->unique())
            ->get(['id', 'name', 'name_en'])->keyBy('id');

        $invItems = DB::table('invoice_items')->whereIn('invoice_id', DB::table('invoices')->whereIn('client_id', $ids)->select('id'))
            ->when($onlyType !== null, fn ($q) => $q->where('invoice_id', $onlyType === 'invoice' ? $onlyId : 0))
            ->groupBy('invoice_id')->selectRaw('invoice_id AS id, SUM(qty) AS pcs, COUNT(DISTINCT product_id) AS products')->get()->keyBy('id');
        $poItems = DB::table('purchase_order_items')->whereIn('purchase_order_id', DB::table('purchase_orders')->whereIn('client_id', $ids)->select('id'))
            ->when($onlyType !== null, fn ($q) => $q->where('purchase_order_id', $onlyType === 'po' ? $onlyId : 0))
            ->groupBy('purchase_order_id')->selectRaw('purchase_order_id AS id, SUM(COALESCE(delivered_qty, qty)) AS pcs, COUNT(DISTINCT product_id) AS products')->get()->keyBy('id');

        $retItems = DB::table('return_items')->whereIn('return_id', DB::table('returns')->whereIn('client_id', $ids)->select('id'))
            ->when($onlyType !== null, fn ($q) => $q->where('return_id', $onlyType === 'return' ? $onlyId : 0))
            ->groupBy('return_id')->selectRaw('return_id AS id, SUM(qty) AS pcs, COUNT(DISTINCT product_id) AS products')->get()->keyBy('id');
        // (١٢/١١) المرتجع ← فواتيره الأصلية (من بنوده)، والبنود اللي اتسعّرت غير سعر البيع
        $retLinks = [];
        $retOff = [];
        if ($onlyType === null || $onlyType === 'return') {
            $retIds = DB::table('returns')->whereIn('client_id', $ids)->when($onlyType !== null, fn ($q) => $q->where('id', $onlyId))->select('id');
            foreach (DB::table('return_items')->join('invoices', 'invoices.id', '=', 'return_items.invoice_id')->whereIn('return_items.return_id', $retIds)
                ->distinct()->get(['return_items.return_id', 'invoices.id', 'invoices.number']) as $l) {
                $retLinks[(int) $l->return_id][] = ['id' => (int) $l->id, 'number' => (string) $l->number];
            }
            $retOff = DB::table('return_items')->join('invoice_items', 'invoice_items.id', '=', 'return_items.invoice_item_id')
                ->whereIn('return_items.return_id', $retIds)
                // للقرش: ٨٧٫٧٢ قدام ٨٧٫٧١٩٣ (عشري ٢ قدام ٤ — نفس الفلوس) مش فرق؛ ١٠٠ قدام ٨٧٫٧٢ فرق
                ->whereRaw('ABS(return_items.price - invoice_items.price) >= 0.005')
                ->distinct()->pluck('return_items.return_id')->flip()->all();
        }

        $checks = [];
        foreach (ReviewCheck::with('user:id,name,name_en')->whereIn('entity_type', self::TYPES)->where('item', DocReview::ITEM)
            ->when($onlyType !== null, fn ($q) => $q->where('entity_type', $onlyType)->where('entity_id', $onlyId))->get() as $c) {
            $checks[$c->entity_type][$c->entity_id] = $c;
        }

        $rows = collect();
        $make = function (string $type, object $d, ?object $agg) use ($clients, $groups, $mothers, $reps, $checks, $retLinks) {
            $c = $clients[$d->client_id] ?? null;
            $g = $c?->group_id ? ($groups[$c->group_id] ?? null) : null;
            $m = $g?->mother_company_id ? ($mothers[$g->mother_company_id] ?? null) : null;
            $chk = $checks[$type][$d->id] ?? null;

            return [
                'type' => $type,
                'id' => (int) $d->id,
                'key' => DocReview::keyFor($type, (int) $d->id),
                'number' => (string) $d->number,
                'paper_ref' => DocReview::normSerial($d->paper_ref),
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
                // إثبات الاستلام — أوامر التوريد بس (الفواتير false)
                'receipt' => (bool) ($d->receipt_note ?? false),
                'receipt_no' => $d->receipt_note_no ?? null,
                'signed' => (bool) ($d->paper_signed ?? false),
                // المرتجع: فواتيره الأصلية
                'links' => $type === 'return' ? ($retLinks[(int) $d->id] ?? []) : [],
            ];
        };

        foreach (DB::table('invoices')->whereIn('client_id', $ids)
            ->when($onlyType !== null, fn ($q) => $onlyType === 'invoice' ? $q->where('id', $onlyId) : $q->whereRaw('0 = 1'))
            ->get(['id', 'number', 'paper_ref', 'client_id', 'user_id AS rep_id', 'created_at AS doc_at', 'payment AS st', 'total', 'tax_total', 'grand_total']) as $d) {
            $rows->push($make('invoice', $d, $invItems[$d->id] ?? null));
        }
        foreach (DB::table('purchase_orders')->whereIn('client_id', $ids)->where('status', '!=', 'cancelled')
            ->when($onlyType !== null, fn ($q) => $onlyType === 'po' ? $q->where('id', $onlyId) : $q->whereRaw('0 = 1'))
            ->selectRaw('id, number, paper_ref, receipt_note, receipt_note_no, paper_signed, client_id, assigned_to AS rep_id, COALESCE(delivered_at, due_at, created_at) AS doc_at, status AS st, total, tax_total, grand_total')
            ->get() as $d) {
            $rows->push($make('po', $d, $poItems[$d->id] ?? null));
        }
        foreach (DB::table('returns')->whereIn('client_id', $ids)
            ->when($onlyType !== null, fn ($q) => $onlyType === 'return' ? $q->where('id', $onlyId) : $q->whereRaw('0 = 1'))
            ->get(['id', 'number', 'paper_ref', 'paper_signed', 'client_id', 'user_id AS rep_id', 'created_at AS doc_at', 'policy AS st', 'total', 'tax_total', 'grand_total']) as $d) {
            $rows->push($make('return', $d, $retItems[$d->id] ?? null));
        }

        // السيريال المكرر — بين الفواتير والأوامر مع بعض، على الشكل الموحّد، وعلى **كل**
        // المستندات (مش اللي اليوزر شايفه بس) — نفس اللي اللوحة بتقوله «مكرر مع …»
        $dups = collect(DocReview::serialIndex())->map(fn ($l) => count($l))->filter(fn ($n) => $n > 1);

        // (١٢/١١) التنبيهات الأوتوماتيك — بتفرز الطابور وبتظهر جنب الرقم
        $repriced = self::repricedKeys($onlyType, $onlyId);
        $today = now()->toDateString();
        $first = ReviewCheck::FIRST_MONTH.'-01';

        return $rows->map(function ($r) use ($dups, $repriced, $clients, $today, $first, $retOff) {
            $r['dup'] = $r['paper_ref'] !== null && isset($dups[DocReview::serialKey($r['type'], $r['paper_ref'])]);
            $clientRep = $clients[$r['client_id']]->rep_id ?? null;
            $r['flags'] = array_values(array_filter([
                $r['paper_ref'] === null ? 'noserial' : null,
                $r['dup'] ? 'dup' : null,
                // أمر لسه ماتسلّمش تاريخه «ميعاد التسليم» — المستقبل فيه طبيعي
                ((($r['type'] !== 'po' || $r['status_doc'] === 'delivered') && $r['date'] > $today) || $r['date'] < $first) ? 'odd_date' : null,
                ($clientRep && $r['rep_id'] && (int) $clientRep !== $r['rep_id']) ? 'rep_off' : null,
                isset($repriced[$r['key']]) ? 'repriced' : null,
                $r['state'] === 'changed' ? 'changed' : null,
                self::unproven($r) ? 'noproof' : null,
                ($r['type'] === 'return' && isset($retOff[$r['id']])) ? 'ret_price' : null,
            ]));
            // علامات «كويسة» (مش تنبيه — مش في «أي تنبيه»): إذن استلام / إمضاء-ختم
            $r['tags'] = array_values(array_filter([$r['receipt'] ? 'receipt' : null, $r['signed'] ? 'signed' : null]));

            return $r;
        });
    }

    public const FLAGS = ['noserial', 'dup', 'odd_date', 'rep_off', 'repriced', 'changed', 'noproof', 'ret_price'];

    /** فلاتر إثبات الاستلام الإيجابية (مش تنبيهات) */
    public const PROOF_TAGS = ['receipt', 'signed'];

    /**
     * أمر توريد اتسلّم ومفيش عليه لا إذن استلام ولا إمضاء/ختم = فلوس مستنية إثبات.
     * مرتجع من غير إمضاء/ختم العميل = دائن اتقيّد من غير ما يثبت إن البضاعة رجعت.
     */
    public static function unproven(array $r): bool
    {
        if ($r['type'] === 'return') {
            return ! $r['signed'];
        }

        return $r['type'] === 'po' && $r['status_doc'] === 'delivered' && ! $r['receipt'] && ! $r['signed'];
    }

    /**
     * (١٢/١١) إثبات استلام أوامر التوريد المتسلّمة: الإجمالي ولكل سلسلة (أو عميل
     * من غير سلسلة) — كام أمر عليه إثبات، وكام لأ، وفلوس اللي من غير إثبات
     * (السلسلة مابتدفعهاش غير بالإثبات = فلوس في خطر).
     *
     * @return array{total: array<string, int|float>, groups: list<array<string, mixed>>}
     */
    public static function proofStats(Collection $rows): array
    {
        $blank = ['delivered' => 0, 'proven' => 0, 'receipt' => 0, 'signed' => 0, 'unproven' => 0, 'grand' => 0.0, 'unproven_grand' => 0.0];
        $total = $blank;
        $groups = [];
        foreach ($rows as $r) {
            if ($r['type'] !== 'po' || $r['status_doc'] !== 'delivered') {
                continue;
            }
            $k = $r['group_id'] ? 'g'.$r['group_id'] : 'c'.$r['client_id'];
            $groups[$k] ??= $blank + [
                'label' => $r['group'] ?? $r['client'], 'solo' => $r['group_id'] === null, 'mother' => $r['mother'],
                'filter' => $r['group_id'] ? ['group' => $r['group_id']] : ['client' => $r['client_id']],
            ];
            foreach ([&$total, &$groups[$k]] as &$o) {
                $o['delivered']++;
                $o['grand'] += $r['grand'];
                $o['receipt'] += $r['receipt'] ? 1 : 0;
                $o['signed'] += $r['signed'] ? 1 : 0;
                if (self::unproven($r)) {
                    $o['unproven']++;
                    $o['unproven_grand'] += $r['grand'];
                } else {
                    $o['proven']++;
                }
            }
            unset($o);
        }
        $fix = function (array $o) {
            $o['grand'] = round($o['grand'], 2);
            $o['unproven_grand'] = round($o['unproven_grand'], 2);
            $o['pct'] = self::pct($o['proven'], $o['delivered']);

            return $o;
        };
        $groups = array_map($fix, array_values($groups));
        // الأكتر فلوس من غير إثبات الأول
        usort($groups, fn ($a, $b) => [$b['unproven_grand'], $a['label']] <=> [$a['unproven_grand'], $b['label']]);

        return ['total' => $fix($total), 'groups' => $groups];
    }

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
        $map = ['Invoice' => 'inv-', 'PurchaseOrder' => 'po-', 'ClientReturn' => 'ret-'];

        return DB::table('activity_logs')
            ->whereIn('subject_type', $onlyType === null ? array_keys($map) : [['invoice' => 'Invoice', 'po' => 'PurchaseOrder', 'return' => 'ClientReturn'][$onlyType]])
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
        $checks = ReviewCheck::whereIn('entity_type', self::TYPES)->where('item', DocReview::ITEM)
            ->get(['entity_type', 'entity_id', 'status', 'system_balance'])->keyBy(fn ($c) => $c->entity_type.$c->entity_id);
        $rows = collect();
        foreach (DB::table('returns')->whereIn('client_id', $ids)->get(['id', 'grand_total']) as $d) {
            $rows->push(['type' => 'return', 'state' => DocReview::state($checks['return'.$d->id] ?? null, (float) $d->grand_total)]);
        }
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
            'returns' => $rows->where('type', 'return')->count(),
            'ok_ret' => $rows->where('type', 'return')->where('state', 'ok')->count(),
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

        $checks = ReviewCheck::whereIn('entity_type', self::TYPES)->where('item', DocReview::ITEM)
            ->get(['id', 'entity_type', 'entity_id', 'status', 'system_balance'])->keyBy(fn ($c) => $c->entity_type.$c->entity_id);

        $docs = DB::table('invoices')->whereIn('client_id', $ids)
            ->selectRaw("'invoice' AS t, id, client_id, grand_total, created_at AS doc_at")
            ->unionAll(DB::table('purchase_orders')->whereIn('client_id', $ids)->where('status', '!=', 'cancelled')
                ->selectRaw("'po' AS t, id, client_id, grand_total, COALESCE(delivered_at, due_at, created_at) AS doc_at"))
            ->unionAll(DB::table('returns')->whereIn('client_id', $ids)
                ->selectRaw("'return' AS t, id, client_id, grand_total, created_at AS doc_at"))
            ->get();

        $out = [];
        foreach ($docs as $d) {
            $state = DocReview::state($checks[$d->t.$d->id] ?? null, (float) $d->grand_total);
            $o = &$out[(int) $d->client_id];
            $o ??= self::MONEY_BLANK;
            $m = substr((string) $d->doc_at, 0, 7);
            $isRet = $d->t === 'return';
            $o['docs']++;
            $o['grand'] += (float) $d->grand_total;
            // (١٢/١١) المبيعات والمرتجعات لوحدهم: الرصيد = مبيعات − مرتجعات
            $o[$isRet ? 'ret' : 'sales'] += (float) $d->grand_total;
            $o['ret_docs'] += $isRet ? 1 : 0;
            $o['months'][$m]['docs'] = ($o['months'][$m]['docs'] ?? 0) + 1;
            $o['months'][$m]['ok'] ??= 0;
            if ($state === 'ok') {
                $o['ok']++;
                $o['ok_grand'] += (float) $d->grand_total;
                $o[$isRet ? 'ok_ret' : 'ok_sales'] += (float) $d->grand_total;
                $o['ok_ret_docs'] += $isRet ? 1 : 0;
                $o['months'][$m]['ok']++;
            } elseif ($state === 'issue') {
                $o['issue']++;
            }
            unset($o);
        }

        return $out;
    }

    /**
     * `grand`/`ok_grand` = حجم المستندات (مبيعات + مرتجعات) — عليه نسبة الإنجاز.
     * `sales`/`ret` وصافيهم = الرصيد اللي المستندات بتقوله (مبيعات − مرتجعات).
     */
    public const MONEY_BLANK = ['docs' => 0, 'ok' => 0, 'issue' => 0, 'grand' => 0.0, 'ok_grand' => 0.0,
        'sales' => 0.0, 'ok_sales' => 0.0, 'ret' => 0.0, 'ok_ret' => 0.0, 'ret_docs' => 0, 'ok_ret_docs' => 0, 'months' => []];

    private const MONEY_KEYS = ['docs', 'ok', 'issue', 'grand', 'ok_grand', 'sales', 'ok_sales', 'ret', 'ok_ret', 'ret_docs', 'ok_ret_docs'];

    /**
     * جمع `moneyByClient` لمجموعة عملاء (سلسلة).
     *
     * @param  array<int, array>  $byClient
     * @param  iterable<int>  $clientIds
     */
    public static function moneySum(array $byClient, iterable $clientIds): array
    {
        $o = self::MONEY_BLANK;
        foreach ($clientIds as $id) {
            $c = $byClient[(int) $id] ?? null;
            if ($c === null) {
                continue;
            }
            foreach (self::MONEY_KEYS as $k) {
                $o[$k] += $c[$k];
            }
            foreach ($c['months'] as $m => $x) {
                $o['months'][$m]['docs'] = ($o['months'][$m]['docs'] ?? 0) + $x['docs'];
                $o['months'][$m]['ok'] = ($o['months'][$m]['ok'] ?? 0) + $x['ok'];
            }
        }
        foreach (['grand', 'ok_grand', 'sales', 'ok_sales', 'ret', 'ok_ret'] as $k) {
            $o[$k] = round($o[$k], 2);
        }
        $o['net'] = round($o['sales'] - $o['ret'], 2);
        $o['ok_net'] = round($o['ok_sales'] - $o['ok_ret'], 2);

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
        $digits = fn (string $s) => strtr($s, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        $norm = fn (string $s) => mb_strtolower(Client::normalizeArabic($digits($s)));
        $serialQ = DocReview::normSerial($q);
        $nq = $q === '' ? '' : $norm($q);
        $amount = is_numeric(str_replace(',', '', $q)) ? (float) str_replace(',', '', $q) : null;

        $out = $rows->filter(function ($r) use ($f, $nq, $norm, $amount, $serialQ) {
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
            if (in_array($flag, self::PROOF_TAGS, true)) {
                if (! in_array($flag, $r['tags'] ?? [], true)) {
                    return false;
                }
            } elseif ($flag === 'any' ? $r['flags'] === [] : ($flag !== '' && ! in_array($flag, $r['flags'], true))) {
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
                $hay = $norm(implode(' ', [$r['number'], $r['paper_ref'], $r['receipt_no'] ?? '', implode(' ', array_column($r['links'] ?? [], 'number')), $r['client'], $r['client_code'], $r['phone'], $r['group'], $r['mother'], $r['rep']]));
                $hit = str_contains($hay, $nq) || ($amount !== null && abs($r['grand'] - $amount) < 0.01)
                    || ($serialQ !== null && $r['paper_ref'] !== null && str_contains($r['paper_ref'], $serialQ));
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
            'returns' => $rows->where('type', 'return')->count(),
            // (١٢/١١) الفلوس = مبيعات − مرتجعات (زي الرصيد)، وقيمة المرتجعات لوحدها
            'net' => round($rows->sum(fn ($r) => $r['type'] === 'return' ? -$r['net'] : $r['net']), 2),
            'tax' => round($rows->sum(fn ($r) => $r['type'] === 'return' ? -$r['tax'] : $r['tax']), 2),
            'grand' => round($rows->sum(fn ($r) => $r['type'] === 'return' ? -$r['grand'] : $r['grand']), 2),
            'ret_grand' => round($rows->where('type', 'return')->sum('grand'), 2),
            'pcs' => (int) $rows->sum('pcs'),
            'products' => (int) $rows->sum('products'),
            'ok' => $ok,
            'issue' => $rows->where('state', 'issue')->count(),
            'changed' => $rows->where('state', 'changed')->count(),
            'pending' => $rows->where('state', 'pending')->count(),
            'ok_inv' => $rows->where('type', 'invoice')->where('state', 'ok')->count(),
            'ok_po' => $rows->where('type', 'po')->where('state', 'ok')->count(),
            'ok_ret' => $rows->where('type', 'return')->where('state', 'ok')->count(),
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
