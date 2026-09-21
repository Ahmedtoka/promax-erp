@extends('layouts.system')

@section('title', __('field.returns'))

@php
    $fmt = fn ($n) => number_format((float) $n, 2);
    $f0 = fn ($n) => number_format((float) $n);
    $periodOn = ! $range->isOpen();
    $exportUrl = fn (string $kind) => request()->fullUrlWithQuery(['export' => $kind, 'page' => null]);
    $units = $sumGood + $sumDamaged;
@endphp

@section('actions')
    <a class="btn green" href="{{ $exportUrl('docs') }}">⬇ {{ __('field.ret_export_docs') }}</a>
    <a class="btn" href="{{ $exportUrl('lines') }}">⬇ {{ __('field.ret_export_lines') }}</a>
@endsection

@section('content')

{{-- ⚠️ الـKPIs والتحليلات والتصدير من **نفس الكويري المفلترة** بتاعة الجدول —
     نطاق واحد. والفترة بتاريخ القيد في كشف الحساب، فالإجمالي هنا هو نفس كارت
     المرتجعات في الصفحة الرئيسية لنفس الفترة. --}}
<div class="kpis">
    <div class="kpi" data-explain onclick="openDlg('retExplain')" title="{{ __('ui.click_to_explain') }}">
        <div class="lbl">↩️ {{ __('field.ret_kpi_value') }}</div>
        <div class="val neg num">{{ $fmt($sumValue) }}</div>
        <div class="sub2">{{ __('field.ret_kpi_docs', ['count' => $f0($sumDocs)]) }} • {{ __('client.client_countable', ['count' => $sumClients]) }}</div>
    </div>
    {{-- النسبة = القيمة ÷ مبيعات الفترة — الكارت بيفتح تقرير مبيعات نفس الفترة (نفس مصدر الرقم) --}}
    <a class="kpi" href="{{ route('erp.reports.show', array_filter(['key' => 'sales_by_client', 'from' => $range->fromValue(), 'to' => $range->toValue()])) }}">
        <div class="lbl">📈 {{ __('field.ret_kpi_rate') }}</div>
        <div class="val num {{ $periodSales && $sumValue / max($periodSales, 1) > 0.1 ? 'neg' : 'mid' }}">
            {{ $periodSales ? number_format($sumValue / $periodSales * 100, 1).'%' : '—' }}</div>
        <div class="sub2">{{ $periodSales ? __('field.ret_kpi_rate_hint', ['sales' => $f0($periodSales)]) : __('field.ret_kpi_rate_pick') }}</div>
    </a>
    <a @class(['kpi', 'on' => ($filters['condition'] ?? '') === 'good']) href="{{ request()->fullUrlWithQuery(['condition' => ($filters['condition'] ?? '') === 'good' ? null : 'good', 'page' => null, 'export' => null]) }}" title="{{ __('ui.click_to_filter') }}">
        <div class="lbl">✅ {{ __('field.return_good_units') }}</div>
        <div class="val pos num">{{ $f0($sumGood) }}</div>
        <div class="sub2">{{ __('common.piece') }}</div>
    </a>
    <a @class(['kpi', 'on' => ($filters['condition'] ?? '') === 'damaged']) href="{{ request()->fullUrlWithQuery(['condition' => ($filters['condition'] ?? '') === 'damaged' ? null : 'damaged', 'page' => null, 'export' => null]) }}" title="{{ __('ui.click_to_filter') }}">
        <div class="lbl">⚠️ {{ __('field.return_damaged_units') }}</div>
        <div class="val neg num">{{ $f0($sumDamaged) }}</div>
        <div class="sub2">{{ $units > 0 ? number_format($sumDamaged / $units * 100, 1) : 0 }}% {{ __('field.ret_of_units') }}</div>
    </a>
</div>

<div class="card">
    <form class="searchbar" method="GET">
        <label class="fl grow"><span>{{ __('ui.l_search') }}</span>
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="🔍 {{ __('field.ret_search_ph') }}"></label>
        <label class="fl"><span>{{ __('ui.l_group') }}</span>
            <select name="group">
                <option value="">{{ __('ui.all_of', ['x' => __('nav.chains')]) }}</option>
                @foreach ($groups as $g)
                    <option value="{{ $g->id }}" @selected((int) ($filters['group'] ?? 0) === $g->id)>{{ $g->displayName() }}</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('ui.l_channel') }}</span>
            <select name="channel">
                <option value="">{{ __('client.all_channels') }}</option>
                @foreach ($channels as $ch)
                    <option value="{{ $ch->id }}" @selected((int) ($filters['channel'] ?? 0) === $ch->id)>{{ $ch->displayName() }}</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('ui.l_rep') }}</span>
            <select name="rep">
                <option value="">{{ __('ops.all_reps') }}</option>
                <option value="office" @selected(($filters['rep'] ?? '') === 'office')>{{ __('common.office') }}</option>
                @foreach ($reps as $r)
                    <option value="{{ $r->id }}" @selected(($filters['rep'] ?? '') === (string) $r->id)>{{ $r->displayName() }}</option>
                @endforeach
            </select></label>
        <label class="fl wide"><span>{{ __('ui.l_product') }}</span>
            <select name="product">
                <option value="">{{ __('ui.all_of', ['x' => __('uic.products')]) }}</option>
                @foreach ($products as $p)
                    <option value="{{ $p->id }}" @selected((int) ($filters['product'] ?? 0) === $p->id)>{{ $p->code }} — {{ $p->displayName() }}</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('field.return_policy') }}</span>
            <select name="policy">
                <option value="">{{ __('ui.all_of', ['x' => __('uic.policies')]) }}</option>
                @foreach ($policies as $p)
                    <option value="{{ $p }}" @selected(($filters['policy'] ?? '') === $p)>{{ __('field.return_policy_'.$p) }}</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('field.ret_condition') }}</span>
            <select name="condition">
                <option value="">{{ __('ui.all_of', ['x' => __('uic.conditions')]) }}</option>
                <option value="good" @selected(($filters['condition'] ?? '') === 'good')>{{ __('field.ret_cond_good') }}</option>
                <option value="damaged" @selected(($filters['condition'] ?? '') === 'damaged')>{{ __('field.ret_cond_damaged') }}</option>
            </select></label>
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue()])
        @if (! empty($filters['client']))
            <input type="hidden" name="client" value="{{ (int) $filters['client'] }}">
        @endif
        <button class="btn gold" type="submit">{{ __('common.filter') }}</button>
        <a class="btn" href="{{ route('ops.returns') }}">{{ __('common.clear') }}</a>
        {{-- ⚠️ **الزرار متحرس بنفس مفتاح الراوت.** مدير الفرع عنده
             `!ops.returns.new` — من غير الحارس كان بيشوف الزرار وياخد
             403 أول ما يدوس، وده بالظبط اللي `Access` اتعملت تمنعه. --}}
        @if (\App\Support\Access::allows(auth()->user(), 'ops.returns.new'))
            <a class="btn green" href="{{ route('ops.returns.new') }}">+ {{ __('field.return_doc') }}</a>
        @endif
    </form>

    {{-- (٢٢/٩) فلتر العميل جاي من جدول «حسب العميل» ومالوش خانة — لازم يبان ويتشال --}}
    @if ($pickedClient)
        <div style="margin:8px 0;font-size:12.5px">
            {{ __('ui.l_client') }}:
            <a href="{{ route('erp.clients.show', $pickedClient) }}"><b>{{ $pickedClient->fullName() }}</b></a>
            <a class="btn sm" href="{{ request()->fullUrlWithQuery(['client' => null, 'page' => null, 'export' => null]) }}">✕ {{ __('common.clear') }}</a>
        </div>
    @endif

    @if ($periodOn)
        <div class="alert info" style="margin:10px 0">{{ __('field.ret_period_note') }}</div>
    @endif
</div>

{{-- ═══ مين بيرجّع إيه — أعلى 8 في كل تقسيمة، بنفس الفلاتر ═══ --}}
@if ($sumDocs > 0)
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:14px;margin-bottom:14px">
    <div class="card" style="margin:0">
        <h3>📦 {{ __('field.ret_by_product') }}</h3>
        <div class="tablewrap"><table>
            <tr><th>{{ __('field.ret_item') }}</th><th class="num">{{ __('field.ret_qty') }}</th><th class="num">{{ __('field.return_damaged_units') }}</th><th class="num">{{ __('common.total') }}</th></tr>
            @foreach ($byProduct as $x)
                <tr class="clickable" onclick="location.href='{{ request()->fullUrlWithQuery(['product' => $x->id, 'page' => null]) }}'">
                    <td>{{ app()->getLocale() === 'en' && $x->name_en ? $x->name_en : $x->name }}
                        <a href="{{ route('erp.products.show', $x->id) }}" onclick="event.stopPropagation()" title="{{ __('ui.l_product') }}">↗</a></td>
                    <td class="num">{{ $f0($x->q) }}</td>
                    <td class="num {{ $x->dq > 0 ? 'neg' : '' }}">{{ $f0($x->dq) }}</td>
                    <td class="num neg"><b>{{ $fmt($x->v) }}</b></td>
                </tr>
            @endforeach
        </table></div>
    </div>
    <div class="card" style="margin:0">
        <h3>👥 {{ __('field.ret_by_client') }}</h3>
        <div class="tablewrap"><table>
            <tr><th>{{ __('client.client') }}</th><th class="num">{{ __('field.ret_docs') }}</th><th class="num">{{ __('common.total') }}</th></tr>
            @foreach ($byClient as $x)
                <tr class="clickable" onclick="location.href='{{ request()->fullUrlWithQuery(['client' => $x->client_id, 'page' => null]) }}'">
                    <td>{{ $clientNames->get($x->client_id)?->fullName() ?? '#'.$x->client_id }}
                        <a href="{{ route('erp.clients.show', $x->client_id) }}" onclick="event.stopPropagation()" title="{{ __('ui.l_client') }}">↗</a></td>
                    <td class="num">{{ $f0($x->n) }}</td>
                    <td class="num neg"><b>{{ $fmt($x->v) }}</b></td>
                </tr>
            @endforeach
        </table></div>
    </div>
    <div class="card" style="margin:0">
        <h3>🧑‍💼 {{ __('field.ret_by_rep') }}</h3>
        <div class="tablewrap"><table>
            <tr><th>{{ __('ops.rep') }}</th><th class="num">{{ __('field.ret_docs') }}</th><th class="num">{{ __('common.total') }}</th></tr>
            @foreach ($byRep as $x)
                <tr class="clickable" onclick="location.href='{{ request()->fullUrlWithQuery(['rep' => $x->user_id ?: 'office', 'page' => null]) }}'">
                    <td>{{ $x->user_id ? ($repNames->get($x->user_id)?->displayName() ?? '#'.$x->user_id) : __('common.office') }}
                        @if ($x->user_id)<a href="{{ route('ops.rep', $x->user_id) }}" onclick="event.stopPropagation()" title="{{ __('ui.l_rep') }}">↗</a>@endif</td>
                    <td class="num">{{ $f0($x->n) }}</td>
                    <td class="num neg"><b>{{ $fmt($x->v) }}</b></td>
                </tr>
            @endforeach
        </table></div>
    </div>
</div>
@endif

<div class="card">
    <h3>↩️ {{ __('field.returns') }} <span class="side">{{ __('field.ret_kpi_docs', ['count' => $f0($returns->total())]) }}</span></h3>
    <div class="tablewrap" style="max-height:65vh;overflow-y:auto">
        <table>
            <thead>
            <tr>
                <th>{{ __('common.number') }}</th>
                <th>{{ __('client.client') }}</th>
                <th>{{ __('client.channel') }}</th>
                <th>{{ __('ops.rep') }}</th>
                <th>{{ __('field.return_policy') }}</th>
                <th class="num">{{ __('field.return_good_units') }}</th>
                <th class="num">{{ __('field.return_damaged_units') }}</th>
                <th class="num">{{ __('common.total') }}</th>
                <th data-nosum>{{ __('common.date') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($returns as $r)
                <tr class="clickable" onclick="location.href='{{ route('ops.returns.show', $r) }}'">
                    <td><a href="{{ route('ops.returns.show', $r) }}" onclick="event.stopPropagation()"><b>{{ $r->number }}</b></a></td>
                    <td>
                        @if ($r->client)
                            <a href="{{ route('erp.clients.show', $r->client) }}" onclick="event.stopPropagation()">{{ $r->client->fullName() }}</a>
                        @else — @endif
                    </td>
                    <td>
                        @if ($r->client?->channel)
                            <span class="badge {{ $r->client->channel->badgeClass() }}">{{ $r->client->channel->displayName() }}</span>
                        @else — @endif
                    </td>
                    <td>
                        @if ($r->rep)
                            <a href="{{ route('ops.rep', $r->rep) }}" onclick="event.stopPropagation()">{{ $r->rep->displayName() }}</a>
                        @else <span style="color:var(--muted)">{{ __('common.office') }}</span> @endif
                    </td>
                    <td><span class="badge b-purple">{{ $r->policyLabel() }}</span></td>
                    <td class="num">{{ number_format($r->good_units) }}</td>
                    <td class="num {{ $r->damaged_units > 0 ? 'neg' : '' }}">{{ number_format($r->damaged_units) }}</td>
                    <td class="num neg"><b>{{ $fmt($r->grand_total) }}</b></td>
                    <td class="num">{{ $r->created_at->format('Y-m-d h:i A') }}</td>
                </tr>
            @empty
                <tr><td colspan="9" style="text-align:center;color:var(--muted);padding:24px">
                    {{ __('common.no_results') }}</td></tr>
            @endforelse
            </tbody>
            {{-- (٢٢/٩) الإجمالي من الفلتر كله — نفس أرقام الكروت والتصدير --}}
            @if ($sumDocs > 0)
                <tfoot>
                <tr style="background:var(--card2);font-weight:900">
                    <td>Σ</td>
                    <td colspan="4">{{ __('field.ret_kpi_docs', ['count' => $f0($sumDocs)]) }}</td>
                    <td class="num">{{ $f0($sumGood) }}</td>
                    <td class="num neg">{{ $f0($sumDamaged) }}</td>
                    <td class="num neg">{{ $fmt($sumValue) }}</td>
                    <td></td>
                </tr>
                </tfoot>
            @endif
        </table>
    </div>
    <div class="pag">{{ $returns->links('pagination::simple-default') }}</div>
</div>

{{-- ═══ تفسير قيمة المرتجعات: نفس الفلتر مقسوم بسياسة المرتجع ═══ --}}
<dialog id="retExplain" class="wide">
    <div>
        <h3>{{ __('uic.ret_explain_title') }}</h3>
        <p style="color:var(--muted);font-size:12px;margin-bottom:10px">{{ __('uic.explain_row_hint') }}</p>
        <div class="tablewrap">
            <table>
                <thead><tr>
                    <th>{{ __('field.return_policy') }}</th><th class="num">{{ __('field.ret_docs') }}</th>
                    <th class="num">{{ __('field.return_good_units') }}</th><th class="num">{{ __('field.return_damaged_units') }}</th>
                    <th class="num">{{ __('common.total') }}</th>
                </tr></thead>
                <tbody>
                @foreach ($byPolicy as $x)
                    <tr>
                        <td><a href="{{ request()->fullUrlWithQuery(['policy' => $x->policy, 'page' => null, 'export' => null]) }}"><b>{{ $x->policy ? __('field.return_policy_'.$x->policy) : '—' }}</b></a></td>
                        <td class="num">{{ $f0($x->n) }}</td>
                        <td class="num">{{ $f0($x->g) }}</td>
                        <td class="num">{{ $f0($x->d) }}</td>
                        <td class="num neg"><b>{{ $fmt($x->v) }}</b></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="formbar"><span class="formbar-sp"></span>
            <button class="btn" type="button" onclick="this.closest('dialog').close()">{{ __('common.close') }}</button></div>
    </div>
</dialog>

@endsection
