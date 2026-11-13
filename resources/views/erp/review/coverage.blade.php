@extends('layouts.system')

@section('title', __('review.cov_title'))

@php
    $fmt = fn ($v) => number_format((float) $v, 2);
    $n = fn ($v) => number_format((int) $v);
    $qs = fn (array $over) => request()->fullUrlWithQuery($over);
@endphp

@section('content')

@include('erp.review._tabs')

{{-- (١٣/١١ — أحمد) «كام عميل خلص، وأكتر عميل خلص مراجعة كاملة، وحجم الشغل لكل عميل» --}}
<div class="kpis cov-kpis">
    <a class="kpi {{ $show === 'done' ? 'on' : '' }}" href="{{ $qs(['show' => $show === 'done' ? null : 'done']) }}">
        <div class="lbl">✓ {{ __('review.cov_done_clients') }}</div>
        <div class="val cov-done">{{ $n($top['done_clients']) }} <small>/ {{ $n($top['clients']) }}</small></div>
        <div class="sub2">{{ __('review.cov_done_sub') }}</div>
    </a>
    <div class="kpi">
        <div class="lbl">🧾 {{ __('review.cov_docs') }}</div>
        <div class="val">{{ $top['pct'] }}%</div>
        <div class="sub2" dir="ltr">{{ $n($top['ok']) }} / {{ $n($top['docs']) }} · ✗ {{ $n($top['issue']) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">💰 {{ __('review.cov_sales_ret') }}</div>
        <div class="val" dir="ltr" style="font-size:17px">{{ $fmt($top['sales_amt']) }}</div>
        <div class="sub2" dir="ltr">− {{ $fmt($top['ret_amt']) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">💵 {{ __('review.cov_collections') }}</div>
        <div class="val">{{ $n($top['col']) }}</div>
        <div class="sub2" dir="ltr">{{ $fmt($top['col_amt']) }}</div>
    </div>
</div>

<div class="card">
    <form class="searchbar cov-f" method="GET">
        <input type="hidden" name="by" value="{{ $by }}">
        <input type="hidden" name="sort" value="{{ $sort }}">
        @if ($show)<input type="hidden" name="show" value="{{ $show }}">@endif
        <label class="fl grow"><span>{{ __('ui.l_search') }}</span>
            <input type="search" name="q" value="{{ $f['q'] }}" placeholder="🔍 {{ __('review.cov_search') }}"></label>
        <label class="fl"><span>{{ __('review.f_mother') }}</span>
            <select name="mother" onchange="this.form.submit()"><option value="">{{ __('review.f_mother_all') }}</option>
                @foreach ($mothers as $m)<option value="{{ $m->id }}" @selected((int) $f['mother'] === $m->id)>{{ $m->displayName() }}</option>@endforeach
            </select></label>
        <label class="fl"><span>{{ __('review.f_chain') }}</span>
            <select name="group" onchange="this.form.submit()"><option value="">{{ __('review.f_chain_all') }}</option>
                @foreach ($chains as $g)<option value="{{ $g->id }}" @selected((int) $f['group'] === $g->id)>{{ $g->displayName() }}</option>@endforeach
            </select></label>
        <button class="btn gold" type="submit">{{ __('common.search') }}</button>
    </form>

    <div class="cov-bar">
        <div class="dq-seg" role="group" aria-label="{{ __('review.cov_by') }}">
            <a href="{{ $qs(['by' => null]) }}" class="{{ $by === 'client' ? 'on' : '' }}">{{ __('review.cov_by_client') }}</a>
            <a href="{{ $qs(['by' => 'chain']) }}" class="{{ $by === 'chain' ? 'on' : '' }}">{{ __('review.cov_by_chain') }}</a>
        </div>
        <div class="dq-seg" role="group" aria-label="{{ __('review.f_status') }}">
            <a href="{{ $qs(['show' => null]) }}" class="{{ $show === '' ? 'on' : '' }}">{{ __('review.st_all') }} <b>{{ $counts['all'] }}</b></a>
            <a href="{{ $qs(['show' => 'done']) }}" class="{{ $show === 'done' ? 'on' : '' }}">✓ {{ __('review.cov_show_done') }} <b>{{ $counts['done'] }}</b></a>
            <a href="{{ $qs(['show' => 'partial']) }}" class="{{ $show === 'partial' ? 'on' : '' }}">◐ {{ __('review.cov_show_partial') }} <b>{{ $counts['partial'] }}</b></a>
            <a href="{{ $qs(['show' => 'none']) }}" class="{{ $show === 'none' ? 'on' : '' }}">○ {{ __('review.cov_show_none') }} <b>{{ $counts['none'] }}</b></a>
        </div>
        <div class="dq-seg" role="group" aria-label="{{ __('review.cov_sort') }}">
            @foreach (['work', 'progress', 'left', 'name'] as $s)
                <a href="{{ $qs(['sort' => $s === 'work' ? null : $s]) }}" class="{{ $sort === $s ? 'on' : '' }}">{{ __('review.cov_sort_'.$s) }}</a>
            @endforeach
        </div>
    </div>

    <div class="tablewrap">
        <table id="rvCoverage" class="cov-t">
            <thead><tr>
                <th>{{ $by === 'chain' ? __('review.pf_chain') : __('review.doc_client') }}</th>
                {{-- العدد والمبلغ عمودين منفصلين — لصقهم في خلية واحدة خلّى سكريبت الجداول يقراهم رقم واحد (١٣/١١) --}}
                @foreach (['type_invoices', 'type_pos', 'type_returns', 'cov_collections'] as $h)
                    <th class="num">{{ __('review.'.$h) }}</th>
                    <th class="num cov-amt">{{ __('review.cov_amt', ['t' => __('review.'.$h)]) }}</th>
                @endforeach
                <th class="num">{{ __('review.cov_total_docs') }}</th>
                <th class="num">{{ __('review.cov_reviewed') }}</th>
                <th class="num">{{ __('review.cov_issues') }}</th>
                <th data-nosum>{{ __('review.cov_progress') }}</th>
            </tr></thead>
            <tbody>
            @forelse ($list as $x)
                @php
                    $docsUrl = route('erp.review.docs', array_filter(['status' => 'all',
                        'group' => $by === 'chain' ? $x['group_id'] : null,
                        'client' => $x['client_id'] ?? null]));
                @endphp
                <tr class="{{ $x['done'] ? 'cov-done-row' : ($x['issue'] ? 'cov-iss-row' : '') }}">
                    <td class="cov-name"><a href="{{ $docsUrl }}"><b>{{ $x['label'] }}</b></a>
                        <div class="rv-sub">@if ($x['code'])<span dir="ltr">{{ $x['code'] }}</span> · @endif{{ $by === 'chain' ? ($x['group_id'] ? __('review.branches_n', ['n' => $x['clients']]) : __('review.solo')) : ($x['group'] ?? __('review.solo')) }}@if ($x['mother']) · {{ $x['mother'] }}@endif</div></td>
                    @foreach (['inv', 'po', 'ret', 'col'] as $k)
                        <td class="num">{{ $x[$k] }}</td>
                        <td class="num cov-amt">{{ $fmt($x[$k.'_amt']) }}</td>
                    @endforeach
                    <td class="num"><b>{{ $x['docs'] }}</b></td>
                    <td class="num">{{ $x['ok'] }}</td>
                    <td class="num">@if ($x['issue'])<span class="cov-iss">✗ {{ $x['issue'] }}</span>@else 0 @endif</td>
                    <td class="cov-p" data-sort="{{ $x['pct'] }}">
                        <div class="cov-p-top"><b>{{ $x['done'] ? '✓ ' : '' }}{{ $x['pct'] }}%</b> <span class="rv-sub">{{ __('review.cov_left', ['n' => $x['left']]) }}</span></div>
                        <div class="rv-bar thin"><i style="width:{{ $x['pct'] }}%"></i></div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="13" class="rv-sub">{{ __('review.none_found') }}</td></tr>
            @endforelse
            </tbody>
            @if ($list->isNotEmpty())
            {{-- من السيرفر: كل مستند مرة واحدة، للقايمة كلها بعد الفلاتر (مش الصفحة الظاهرة بس) --}}
            <tfoot><tr>
                <td>Σ {{ __('review.cov_total_row', ['n' => $list->count()]) }}</td>
                @foreach (['inv', 'po', 'ret', 'col'] as $k)
                    <td class="num">{{ $n($foot[$k]['n']) }}</td>
                    <td class="num cov-amt">{{ $fmt($foot[$k]['amt']) }}</td>
                @endforeach
                <td class="num"><b>{{ $n($foot['docs']) }}</b></td>
                <td class="num">{{ $n($foot['ok']) }}</td>
                <td class="num">{{ $n($foot['issue']) }}</td>
                <td>{{ $foot['pct'] }}%</td>
            </tr></tfoot>
            @endif
        </table>
    </div>
</div>

<style>
.dq-seg{display:inline-flex;flex-wrap:wrap;padding:2px;gap:2px;background:var(--card2);border:1px solid var(--border);border-radius:9px}
.dq-seg a{padding:4px 10px;border-radius:7px;font-size:12px;font-weight:800;color:var(--muted);text-decoration:none;white-space:nowrap;display:inline-flex;gap:5px;align-items:center}
.dq-seg a:hover{color:var(--text)}
.dq-seg a.on{background:var(--card);color:var(--royal-blue);box-shadow:0 0 0 1px var(--border),0 1px 2px rgba(10,10,15,.06)}
.cov-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:12px}
.cov-done{color:#15803D}.cov-done small{font-size:13px;color:var(--muted)}
.cov-f{flex-wrap:wrap}
.cov-bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:4px 0 10px}
.cov-t .cov-amt{color:var(--muted);font-size:11.5px}
.cov-t th.cov-amt{font-weight:600}
.cov-t tfoot .cov-amt{color:var(--primary);font-size:12px}
.cov-t td.num{text-align:end;font-variant-numeric:tabular-nums;white-space:nowrap}
.cov-t th{white-space:normal;line-height:1.25;vertical-align:bottom}
.cov-t td,.cov-t th{padding-inline:7px}
.cov-t .cov-name{min-width:150px;white-space:normal}
.cov-t .cov-p{min-width:100px}
.cov-t tfoot td{white-space:nowrap}
.cov-p-top{display:flex;gap:6px;align-items:baseline;margin-bottom:3px}
.cov-t tr.cov-done-row td{background:#F0FDF4}
.cov-t tr.cov-done-row .cov-p b{color:#15803D}
.cov-iss{color:#B91C1C;font-weight:800}
@media (max-width:900px){.cov-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
@endsection
