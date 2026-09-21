<?php

namespace App\Http\Controllers;

use App\Exceptions\Rejected;
use App\Models\Client;
use App\Models\ClientReturn;
use App\Models\User;
use App\Services\Returns;
use App\Support\Scope;
use Illuminate\Http\Request;

/**
 * ═══════════════════════════════════════════════════════════════
 * المرتجعات من الـERP (٨ أغسطس ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **قبل الشاشة دي ماكانش فيه مسار مرتجع من الـERP خالص** — الطريقة
 * الوحيدة لكتابة قيد `return` كانت أبلكيشن مندوب بزيارة مفتوحة. يعني
 * أي مرتجع بييجي المخزن مباشرة، أو بيتفق عليه المكتب مع سلسلة، ماكانش
 * له مكان في السيستم فبيتسجّل «تسوية» يدوية في كشف الحساب من غير بنود.
 *
 * ⚠️ **بينده على نفس `App\Services\Returns` اللي الأبلكيشن بينده عليها**
 * — نفس التسعير من الفاتورة الأصلية، نفس السقف، نفس القيود. تفريع
 * المنطق هنا كان هيخلّي مرتجع الويب يطلع بأرقام غير مرتجع الأبلكيشن.
 *
 * ⚠️ **المندوب اختياري.** الافتراضي إن البضاعة رجعت المخزن مباشرة
 * (بلا عهدة وبلا `returned_in`). لو المكتب حدّد مندوب، معناها إن
 * البضاعة اتسلّمت في عربيته — وساعتها `Returns::create` بيطلب إن
 * يكون عنده عهدة مفتوحة النهارده وبينزّلها فيها زي مرتجع الأبلكيشن،
 * عشان تظهر في تصفيته. مندوب من غير عهدة = رفض برسالة واضحة.
 */
class ReturnController extends Controller
{
    public function index(Request $request)
    {
        $u = $request->user();

        // ⚠️ `client.group` محمّلة لأن `fullName()` بتعرض «السلسلة —
        // الفرع»، و`items` **مش** محمّلة لأن الجدول بيعرض المجاميع
        // المخزّنة على المستند مش البنود.
        $q = ClientReturn::with(['client.group', 'client.channel', 'rep'])
            // ⚠️ سكوب الفريق — مرتجعات عملاء اللي فاتح الشاشة بس
            ->whereIn('returns.client_id', Client::visibleTo(Client::query(), $u)->select('clients.id'));

        $txt = fn (string $k) => is_string($request->input($k)) ? trim($request->input($k)) : '';

        if ($clientId = $request->integer('client')) {
            $q->where('client_id', $clientId);
        }
        if ($groupId = $request->integer('group')) {
            $q->whereHas('client', fn ($c) => $c->where('group_id', $groupId));
        }
        if ($channelId = $request->integer('channel')) {
            $q->whereHas('client', fn ($c) => $c->where('channel_id', $channelId));
        }
        if ($repId = $request->integer('rep')) {
            $q->where('user_id', $repId);
        } elseif ($txt('rep') === 'office') {
            $q->whereNull('user_id');
        }
        if ($productId = $request->integer('product')) {
            $q->whereHas('items', fn ($i) => $i->where('product_id', $productId));
        }
        if (in_array($txt('condition'), ['good', 'damaged'], true)) {
            $q->where($txt('condition') === 'good' ? 'good_units' : 'damaged_units', '>', 0);
        }
        if ($policy = $txt('policy')) {
            $q->where('policy', $policy);
        }
        if (($search = $txt('q')) !== '') {
            $ids = Client::search(Client::visibleTo(Client::query(), $u), $search)->pluck('id');
            $q->where(fn ($w) => $w->where('number', 'like', '%'.$search.'%')->orWhereIn('client_id', $ids));
        }

        // ═══ الفترة بتاريخ **القيد في كشف الحساب** (٢١/٩) ═══
        // نفس قاعدة الداشبورد والتقارير (`SalesSource`): المرتجع بيدخل الفترة
        // بتاريخ قيده، فإجمالي الشاشة = كارت المرتجعات في الصفحة الرئيسية.
        // المرتجع اللي مالوش قيد (نادر) بيتحسب بتاريخ المستند.
        $range = \App\Support\DateRange::fromRequest($request);

        if (! $range->isOpen()) {
            $q->where(fn ($w) => $w
                ->whereIn('transaction_id', $range->apply(\App\Models\Transaction::query(), 'date')->select('id'))
                ->orWhere(fn ($x) => $range->apply($x->whereNull('transaction_id'), 'created_at')));
        }

        if (in_array($request->query('export'), ['docs', 'lines'], true)) {
            return $this->exportReturns(clone $q, $request->query('export') === 'lines', $request->integer('product'));
        }

        // ⚠️ الإجماليات والتحليلات من **نفس الكويري المفلترة** — نطاق واحد،
        // وقبل `paginate()` عشان تحسب الكل مش الصفحة.
        $ids = (clone $q)->select('returns.id');

        $lines = fn () => \Illuminate\Support\Facades\DB::table('return_items')
            ->join('returns', 'returns.id', '=', 'return_items.return_id')
            ->whereIn('return_items.return_id', $ids)
            // مع فلتر الصنف، التحليل على بنود الصنف ده بس
            ->when($request->integer('product'), fn ($w, $p) => $w->where('return_items.product_id', $p));

        $byProduct = $lines()->join('products', 'products.id', '=', 'return_items.product_id')
            ->selectRaw("products.id, products.name, products.name_en, SUM(return_items.qty) q,
                SUM(return_items.total + return_items.tax) v,
                SUM(CASE WHEN return_items.`condition` = 'damaged' THEN return_items.qty ELSE 0 END) dq")
            ->groupBy('products.id', 'products.name', 'products.name_en')->orderByDesc('v')->take(8)->get();

        $byRep = (clone $q)->setEagerLoads([])->reorder()->selectRaw('user_id, COUNT(*) n, SUM(grand_total) v')
            ->groupBy('user_id')->orderByDesc('v')->take(8)->get();
        $byClient = (clone $q)->setEagerLoads([])->reorder()->selectRaw('client_id, COUNT(*) n, SUM(grand_total) v')
            ->groupBy('client_id')->orderByDesc('v')->take(8)->get();

        $sumValue = (float) (clone $q)->sum('grand_total');

        // (٢٢/٩) تفسير كارت القيمة: نفس الكويري مقسومة بالسياسة — مجموعها = الكارت
        $byPolicy = (clone $q)->setEagerLoads([])->reorder()
            ->selectRaw('policy, COUNT(*) n, SUM(good_units) g, SUM(damaged_units) d, SUM(grand_total) v')
            ->groupBy('policy')->orderByDesc('v')->get();

        // نسبة المرتجعات من مبيعات نفس الفترة — من كشف الحساب، ومن غير فلاتر الشاشة
        $periodSales = $range->isOpen() ? null
            : (float) \App\Services\SalesSource::docs($range->from ?? now()->subYears(20), $range->to ?? now())
                ->whereIn('client_id', Client::visibleTo(Client::query(), $u)->select('clients.id'))->sum('grand_total');

        return view('ops.returns', [
            'returns' => $q->latest()->paginate(30)->withQueryString(),
            'sumValue' => $sumValue,
            'sumDocs' => (int) (clone $q)->count(),
            'sumClients' => (int) (clone $q)->distinct()->count('client_id'),
            'sumGood' => (int) (clone $q)->sum('good_units'),
            'sumDamaged' => (int) (clone $q)->sum('damaged_units'),
            'periodSales' => $periodSales,
            'byProduct' => $byProduct,
            'byRep' => $byRep,
            'byClient' => $byClient,
            'byPolicy' => $byPolicy,
            'pickedClient' => $request->integer('client') ? Client::with('group')->find($request->integer('client')) : null,
            'repNames' => \App\Models\User::whereIn('id', $byRep->pluck('user_id')->filter())->get()->keyBy('id'),
            'clientNames' => Client::with('group')->whereIn('id', $byClient->pluck('client_id'))->get()->keyBy('id'),
            'policies' => ClientReturn::POLICIES,
            'range' => $range,
            'reps' => \App\Models\User::fieldVisibleTo(
                \App\Models\User::whereIn('role', \App\Models\User::FIELD_WORK_ROLES), $u)
                ->where('active', true)->orderBy('name')->get(),
            'products' => \App\Models\Product::where('active', true)->orderBy('code')->get(['id', 'code', 'name', 'name_en']),
            'channels' => \App\Models\Channel::orderBy('id')->get(),
            'groups' => \App\Models\ClientGroup::orderBy('name')->get(['id', 'name', 'name_en']),
            'filters' => $request->only(['client', 'group', 'channel', 'rep', 'product', 'condition', 'policy', 'q']),
        ]);
    }

    /**
     * تصدير المرتجعات بنفس فلاتر الشاشة — مستند لكل صف، أو بند لكل صف.
     * ⚠️ من غير صفحات وبنفس الكويري، فإجمالي الملف هو إجمالي الكروت.
     */
    private function exportReturns($q, bool $lines, int $productId = 0)
    {
        $n2 = fn ($v) => number_format((float) $v, 2, '.', '');
        $docs = $q->with($lines ? ['client.group', 'rep', 'items.product'] : ['client.group', 'rep'])
            ->reorder()->orderBy('id')->get();

        return response()->streamDownload(function () use ($docs, $lines, $productId, $n2) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");   // BOM — إكسيل بيفتح العربي صح

            $head = [__('common.date'), __('common.number'), __('client.client'), __('common.code'), __('ops.rep'), __('field.return_policy')];

            if (! $lines) {
                fputcsv($out, [...$head, __('field.return_good_units'), __('field.return_damaged_units'),
                    __('field.ret_net'), __('field.ret_tax'), __('common.total')]);
                $t = [0, 0, 0.0, 0.0, 0.0];

                foreach ($docs as $d) {
                    fputcsv($out, [$d->created_at->format('Y-m-d'), $d->number, $d->client?->fullName() ?? '', $d->client?->code ?? '',
                        $d->rep?->displayName() ?? __('common.office'), $d->policyLabel(),
                        (int) $d->good_units, (int) $d->damaged_units, $n2($d->total), $n2($d->tax_total), $n2($d->grand_total)]);
                    $t[0] += $d->good_units; $t[1] += $d->damaged_units; $t[2] += $d->total; $t[3] += $d->tax_total; $t[4] += $d->grand_total;
                }

                fputcsv($out, [__('common.total'), $docs->count(), '', '', '', '', $t[0], $t[1], $n2($t[2]), $n2($t[3]), $n2($t[4])]);
            } else {
                fputcsv($out, [...$head, __('field.ret_item'), __('field.ret_condition'), __('field.ret_qty'),
                    __('field.ret_unit_price'), __('field.ret_net'), __('field.ret_tax'), __('common.total')]);
                $tq = 0; $tv = 0.0;

                foreach ($docs as $d) {
                    foreach ($d->items as $it) {
                        if ($productId && (int) $it->product_id !== $productId) {
                            continue;
                        }

                        fputcsv($out, [$d->created_at->format('Y-m-d'), $d->number, $d->client?->fullName() ?? '', $d->client?->code ?? '',
                            $d->rep?->displayName() ?? __('common.office'), $d->policyLabel(),
                            $it->product?->displayName() ?? '#'.$it->product_id, __('field.ret_cond_'.($it->condition ?: 'good')),
                            (int) $it->qty, $n2($it->price), $n2($it->total), $n2($it->tax), $n2($it->total + $it->tax)]);
                        $tq += $it->qty; $tv += $it->total + $it->tax;
                    }
                }

                fputcsv($out, [__('common.total'), '', '', '', '', '', '', '', $tq, '', '', '', $n2($tv)]);
            }

            fclose($out);
        }, 'returns-'.($lines ? 'lines' : 'docs').'-'.now()->format('Y-m-d-Hi').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** شاشة إنشاء مرتجع لعميل — بتوري المتاح للرد بسعره الأصلي */
    public function create(Request $request)
    {
        $client = $request->filled('client')
            ? Client::find($request->integer('client'))
            : null;

        if ($client !== null) {
            Scope::assertClient($request->user(), $client);
        }

        return view('ops.return_new', [
            'client' => $client,
            'clients' => Client::visibleTo(Client::query())
                ->where('status', 'active')->orderBy('name')->get(['id', 'name', 'name_en', 'code']),
            // ⚠️ مجمّعة بالصنف — المكتب بيفكر بالصنف، والتوزيع على
            // سطور الفواتير بيحصل في الخدمة وقت الحفظ.
            'lines' => $client ? Returns::returnable($client) : [],
            'policies' => $client ? $client->returnPolicies() : [],
            // ⚠️ **مفلترين بالفريق** — تسكين مرتجع على مندوب مدير
            // تاني بيحط بضاعة في عهدة مالهاش علاقة، و`Scope::assertRep`
            // في `store()` بيرفضها بعد ما المستخدم يكون ملا الفورم.
            'reps' => User::fieldVisibleTo(
                User::whereIn('role', User::FIELD_ROLES), $request->user())
                ->where('active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'policy' => ['required', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:500'],
            'qty' => ['required', 'array'],
            'qty.*' => ['nullable', 'integer', 'min:0'],
            'condition' => ['nullable', 'array'],
            'condition.*' => ['nullable', 'in:good,damaged'],
            // مرتجع اتسلّمه مندوب فعلاً — اختياري
            'user_id' => ['nullable', 'exists:users,id'],
        ]);

        $client = Client::findOrFail($data['client_id']);
        Scope::assertClient($request->user(), $client);

        $rep = null;

        if (! empty($data['user_id'])) {
            $rep = User::find($data['user_id']);
            Scope::assertRep($request->user(), $rep, $client);
        }

        $items = [];

        foreach ($data['qty'] as $productId => $qty) {
            if ((int) $qty <= 0) {
                continue;
            }

            $items[] = [
                'product_id' => (int) $productId,
                'qty' => (int) $qty,
                'condition' => $data['condition'][$productId] ?? ClientReturn::CONDITION_GOOD,
            ];
        }

        try {
            $doc = Returns::create(
                client: $client,
                items: $items,
                policy: $data['policy'],
                rep: $rep,
                visit: null,
                note: $data['note'] ?? null,
                idemKey: null,
                actor: $request->user(),
                source: 'erp',
            );
        } catch (Rejected $e) {
            return back()->withInput()->withErrors(['qty' => $e->getMessage()]);
        }

        return redirect()->route('ops.returns.show', $doc)
            ->with('ok', __('field.return_saved', ['number' => $doc->number]));
    }

    public function show(Request $request, ClientReturn $return)
    {
        Scope::assertClient($request->user(), $return->client);

        $return->load(['items.product', 'items.invoiceItem.invoice', 'client', 'rep', 'entry']);

        return view('ops.return_doc', ['r' => $return]);
    }

    /**
     * ═══ مسح مرتجع غلط (١٩ أغسطس ٢٠٢٦) — أدمن بس ═══
     *
     * عكس كامل جوه ترانزاكشن واحدة:
     *   • البضاعة اللي دخلت العهدة بالمرتجع بتتسحب منها تاني —
     *     السليم من `returned_in` والتالف من `damaged_in`، من نفس
     *     صف العهدة اللي `intoCustody` كتب فيه بالحرف.
     *   • قيدا الكشف (return + refund لو كاش) بيتمسحوا،
     *     و`recalculate()` بيظبط الرصيد.
     *
     * ⚠️ الحراس:
     *   • عهدة المرتجع اتقفلت/اتصفّت → ممنوع — المحضر اتوقع على
     *     أرقام فيها المرتجع ده.
     *   • البضاعة الراجعة اتصرفت من العهدة بعد كده (الأرقام مش
     *     مكفية للسحب) → ممنوع — مفيش مسح بيطلّع عهدة بالسالب.
     *   • مرتجع الويب (من غير عهدة خالص) بيتعكس قيوده بس.
     */
    public function destroy(Request $request, ClientReturn $return)
    {
        $client = $return->client;
        $custody = $return->custody_id !== null
            ? \App\Models\Custody::find($return->custody_id)
            : null;

        // ⚠️ «مقفولة» = `status === 'closed'` بالحرف — المفتوحة ممكن
        // تكون null (نفس منطق `currentCustody()` في User).
        if ($custody !== null && (string) $custody->status === 'closed') {
            return back()->withErrors(['delete' => __('ops.del_ret_custody_closed')]);
        }

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($return, $client, $custody) {
                if ($custody !== null) {
                    // نفس مفتاح الصف اللي intoCustody كتب فيه بالحرف
                    $byProduct = [];

                    foreach ($return->items as $it) {
                        $key = (int) $it->product_id;
                        $byProduct[$key][$it->condition] = ($byProduct[$key][$it->condition] ?? 0) + (int) $it->qty;
                    }

                    foreach ($byProduct as $productId => $cond) {
                        $row = \App\Models\CustodyItem::where([
                            'custody_id' => $custody->id,
                            'product_id' => $productId,
                            'batch_id' => null,
                            'source' => 'custody',
                            'source_ref_id' => 0,
                        ])->lockForUpdate()->first();

                        $good = (int) ($cond[ClientReturn::CONDITION_GOOD] ?? 0);
                        $dmg = (int) ($cond[ClientReturn::CONDITION_DAMAGED] ?? 0);

                        if ($row === null
                            || (int) $row->returned_in < $good
                            || (int) $row->damaged_in < $dmg) {
                            // البضاعة الراجعة اتحركت بعد كده — مفيش
                            // مسح بيطلّع عهدة بالسالب في صمت.
                            throw new Rejected(__('ops.del_ret_moved'));
                        }

                        if ($good > 0) {
                            $row->decrement('returned_in', $good);
                        }

                        if ($dmg > 0) {
                            $row->decrement('damaged_in', $dmg);
                        }
                    }
                }

                \App\Models\Transaction::where('source_type', ClientReturn::class)
                    ->where('source_id', $return->id)
                    ->delete();

                $return->items()->delete();
                $return->delete();

                $client->recalculate();
            });
        } catch (Rejected $e) {
            return back()->withErrors(['delete' => $e->getMessage()]);
        }

        return redirect()->route('ops.returns')
            ->with('ok', __('ops.return_deleted', ['number' => $return->number]));
    }
}
