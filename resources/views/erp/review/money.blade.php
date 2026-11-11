@extends('layouts.system')

@section('title', __('review.tab_money'))

@php
    $fmt = fn ($n) => number_format((float) $n);
    $tab = fn ($s) => route('erp.review.money', array_filter($filters + ['show' => $s, 'per' => $per]));
    $rowItems = \App\Models\ReviewCheck::moneyItems();
    $canDocs = \App\Support\Access::allows(auth()->user(), 'erp.review.docs');
    // لينك طابور المستندات لسطر (سلسلة أو عميل) — ولشهر لو اتبعت
    $docsUrl = function (array $r, ?string $m = null) {
        $q = ($r['type'] === 'group' ? ['group' => $r['model']->id] : ['client' => $r['model']->id]) + ['status' => 'all'];
        if ($m !== null) {
            $d = \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $m.'-01');
            $q += ['from' => $d->toDateString(), 'to' => $d->copy()->endOfMonth()->toDateString()];
        }

        return route('erp.review.docs', $q);
    };
    $pct = fn ($a, $b) => \App\Services\Review\DocQueue::pct($a, $b);
@endphp

@section('content')
@php $can = fn ($r) => \App\Support\Access::allows(auth()->user(), $r); @endphp

@include('erp.review._tabs')

<div class="kpis">
    @foreach (['pending' => '⏳', 'issue' => '❌', 'done' => '✅', 'all' => '📋'] as $k => $ico)
        <a class="kpi {{ $show === $k ? 'on' : '' }}" href="{{ $tab($k) }}">
            <div class="lbl">{{ $ico }} {{ __('review.show_'.$k) }}</div>
            <div class="val">{{ $counts[$k] }}</div>
        </a>
    @endforeach
</div>

{{-- (١٢/١١) الرصيد قدام المراجعة: كام من فلوس المستندات اتراجع ✓ --}}
<div class="mn-sum card">
    <div><span class="rv-sub">{{ __('review.m_balance_all') }}</span><b dir="ltr">{{ $fmt($totals['balance']) }}</b></div>
    <div class="mn-sum-bar">
        <span class="rv-sub">{{ __('review.m_docs_reviewed') }}</span>
        <b>{{ $pct($totals['ok_grand'], $totals['grand']) }}%</b>
        <span class="rv-sub" dir="ltr">{{ $fmt($totals['ok_grand']) }} / {{ $fmt($totals['grand']) }}</span>
        <div class="rv-bar"><i style="width:{{ $pct($totals['ok_grand'], $totals['grand']) }}%"></i></div>
    </div>
    <div><span class="rv-sub">{{ __('review.m_docs_count') }}</span><b dir="ltr">{{ number_format($totals['ok']) }} / {{ number_format($totals['docs']) }}</b></div>
    <div><span class="rv-sub">{{ __('review.m_unreviewed') }}</span><b dir="ltr" class="mn-left">{{ $fmt($totals['grand'] - $totals['ok_grand']) }}</b></div>
</div>

<div class="card">
    <form class="searchbar" method="GET">
        <input type="hidden" name="show" value="{{ $show }}">
        <input type="hidden" name="per" value="{{ $per }}">
        <label class="fl grow"><span>{{ __('ui.l_search') }}</span>
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="🔍 {{ __('review.col_entity') }}"></label>
        <button class="btn gold" type="submit">{{ __('common.search') }}</button>
        @if (! empty($filters['q']))<a class="btn" href="{{ route('erp.review.money', ['show' => $show]) }}">{{ __('common.clear') }}</a>@endif
    </form>

    <div class="tablewrap">
        <table id="rvMoney" class="mn-t">
            <thead><tr>
                <th>{{ __('review.col_entity') }}</th>
                <th class="num">{{ __('review.m_balance') }}</th>
                <th data-nosum>{{ __('review.m_docs') }}</th>
                <th data-nosum>{{ __('review.item_prices') }}</th>
                <th data-nosum>{{ __('review.item_pos') }}</th>
                @foreach ($months as $m)<th data-nosum>{{ $m }}</th>@endforeach
                <th data-nosum>{{ __('review.item_opening') }}</th>
            </tr></thead>
            <tbody>
            @forelse ($rows as $r)
                @php
                    $e = $r['model'];
                    $ch = $checks[$r['type']][$e->id] ?? [];
                    $cell = fn ($item) => view('erp.review._mark', ['type' => $r['type'], 'id' => $e->id, 'item' => $item, 'check' => $ch[$item] ?? null])->render();
                    $link = $r['type'] === 'group' ? route('erp.groups.show', $e) : route('erp.clients.show', $e);
                    $rowCls = $r['state'] === 'issue' ? 'rv-issue' : ($r['state'] === 'done' ? 'rv-done' : '');
                    $d = $r['docs'];
                    $dp = $pct($d['ok_grand'], $d['grand']);
                @endphp
                <tr data-row="{{ $r['type'] }}-{{ $e->id }}" class="{{ $rowCls }}">
                    <td class="mn-name">
                        <a class="rv-val rv-edit" href="{{ $link }}" target="_blank" rel="noopener">{{ $e->displayName() }}</a>
                        <div class="rv-sub">
                            @if ($r['type'] === 'group')
                                {{ __('review.branches_n', ['n' => $r['branches']]) }}@if ($e->motherCompany) · {{ $e->motherCompany->displayName() }}@endif
                            @else
                                {{ $e->code }} · {{ __('review.solo') }}
                            @endif
                        </div>
                    </td>
                    <td class="num"><b>{{ $fmt($r['balance']) }}</b></td>
                    <td class="mn-docs">
                        @if ($d['docs'] > 0)
                            <div class="mn-d-top"><b>{{ $dp }}%</b>
                                <span class="rv-sub">{{ __('review.m_docs_n', ['ok' => $d['ok'], 'n' => $d['docs']]) }}</span>
                                @if ($d['issue'] > 0)<span class="mn-iss">✗ {{ $d['issue'] }}</span>@endif
                            </div>
                            <div class="rv-bar thin"><i style="width:{{ $dp }}%"></i></div>
                            <div class="rv-sub" dir="ltr">{{ $fmt($d['ok_grand']) }} / {{ $fmt($d['grand']) }}</div>
                            @if ($canDocs)<a class="mn-go" href="{{ $docsUrl($r) }}">{{ __('review.m_open_docs') }}</a>@endif
                        @else
                            <span class="rv-sub">{{ __('review.m_no_docs') }}</span>
                        @endif
                    </td>
                    <td>{!! $cell('prices') !!}</td>
                    <td>
                        @if ($r['pending_pos'] > 0 && $can('ops.pos'))
                            <a class="badge b-orange" href="{{ route('ops.pos', $r['type'] === 'group' ? ['group' => $e->id] : ['q' => $e->code]) }}" target="_blank" rel="noopener">{{ __('review.pending_pos', ['n' => $r['pending_pos']]) }}</a><br>
                        @endif
                        {!! $cell('pos') !!}
                    </td>
                    @foreach ($months as $m)
                        @php
                            $f = $r['figures'][$m] ?? ['net' => 0, 'balance' => 0];
                            $c = $ch['m:'.$m] ?? null;
                            $md = $d['months'][$m] ?? ['docs' => 0, 'ok' => 0];
                        @endphp
                        <td class="mc">
                            <div class="mc-sys" title="{{ __('review.month_system') }}">
                                <span>{{ __('review.m_net') }}</span><b dir="ltr">{{ $fmt($f['net']) }}</b>
                                <span>{{ __('review.m_bal') }}</span><b dir="ltr">{{ $fmt($f['balance']) }}</b>
                            </div>
                            @if ($c && $c->paper_net !== null)
                                @php $dd = round((float) $c->paper_net - $f['net'], 2); $db = $c->paper_balance !== null ? round((float) $c->paper_balance - $f['balance'], 2) : 0; @endphp
                                <div class="mc-sys paper"><span>{{ __('review.month_paper') }}</span><b dir="ltr">{{ $fmt($c->paper_net) }}</b><span></span><b dir="ltr">{{ $c->paper_balance !== null ? $fmt($c->paper_balance) : '—' }}</b></div>
                                @if (abs($dd) >= 1 || abs($db) >= 1)
                                    <div class="mc-diff">{{ __('review.month_diff') }}: <span dir="ltr">{{ $fmt($dd) }} / {{ $fmt($db) }}</span></div>
                                @endif
                            @endif
                            <div class="mc-row">
                                {{-- ⚠️ القيم في data-* مش جوه onclick — @js بيطلّع علامات تنصيص بتكسر الأتريبيوت --}}
                                <button type="button" class="btn sm rv-month"
                                        data-type="{{ $r['type'] }}" data-id="{{ $e->id }}" data-month="{{ $m }}" data-name="{{ $e->displayName() }}"
                                        data-net="{{ $f['net'] }}" data-bal="{{ $f['balance'] }}"
                                        onclick="rvMonth(this.dataset.type, +this.dataset.id, this.dataset.month, this.dataset.name, +this.dataset.net, +this.dataset.bal)">{{ __('review.month_match') }}</button>
                                @if ($md['docs'] > 0 && $canDocs)
                                    <a class="mc-docs {{ $md['ok'] >= $md['docs'] ? 'done' : '' }}" href="{{ $docsUrl($r, $m) }}" title="{{ __('review.m_month_docs_hint') }}">📄 <span dir="ltr">{{ $md['ok'] }}/{{ $md['docs'] }}</span></a>
                                @endif
                            </div>
                            {!! $cell('m:'.$m) !!}
                        </td>
                    @endforeach
                    <td class="mn-open">
                        @if ($r['type'] === 'group')
                            @if (\App\Support\Access::action(auth()->user(), 'act.review.mark'))
                            <form method="POST" action="{{ route('erp.review.opening', $e) }}" class="mn-open-f">
                                @csrf
                                <input type="number" step="0.01" name="amount" value="{{ $e->opening_balance }}" placeholder="{{ __('review.opening_chain') }}" aria-label="{{ __('review.opening_chain') }}">
                                <input type="date" name="as_of" value="{{ $e->opening_as_of?->toDateString() ?? \App\Models\ReviewCheck::FIRST_MONTH.'-01' }}" aria-label="{{ __('review.doc_date') }}">
                                <button class="btn sm gold" type="submit">{{ __('review.save') }}</button>
                            </form>
                            @endif
                        @else
                            <a class="btn sm rv-edit" href="{{ route('erp.clients.show', $e) }}" target="_blank" rel="noopener">{{ __('review.opening_client') }}</a>
                        @endif
                        {!! $cell('opening') !!}
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ 6 + count($months) }}" style="text-align:center;color:var(--muted);padding:28px">{{ __('review.no_rows') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @include('erp.review._pager', ['p' => $page, 'per' => $per])
    <div class="rv-sub" style="margin-top:8px">ℹ️ {{ __('review.opening_note') }} {{ __('review.m_link_note') }}</div>
</div>

<dialog id="dlgMonth">
    <form class="dlg" onsubmit="return rvMonthSave(event)">
        <h4 id="mTitle"></h4>
        <div class="alert info" style="margin-bottom:10px"><span>ℹ️</span><span>{{ __('review.month_hint') }}</span></div>
        <div class="frow">
            <div><label class="f">{{ __('review.month_net') }} — {{ __('review.month_paper') }}</label>
                <input type="number" step="0.01" id="mNet" required style="width:100%">
                <div class="rv-sub">{{ __('review.month_system') }}: <b id="mSysNet"></b></div></div>
            <div><label class="f">{{ __('review.month_balance') }} — {{ __('review.month_paper') }}</label>
                <input type="number" step="0.01" id="mBal" style="width:100%">
                <div class="rv-sub">{{ __('review.month_system') }}: <b id="mSysBal"></b></div></div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
            <button class="btn" type="button" onclick="closeDlg('dlgMonth')">{{ __('review.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('review.save') }}</button>
        </div>
    </form>
</dialog>

<style>
.mn-sum{display:grid;grid-template-columns:auto minmax(220px,1fr) auto auto;gap:22px;align-items:center}
.mn-sum>div{display:flex;flex-direction:column;gap:2px;min-width:0}
.mn-sum b{font-size:18px;font-variant-numeric:tabular-nums}
.mn-sum .rv-bar{margin:4px 0 0}
.mn-sum-bar{display:grid!important;grid-template-columns:auto auto 1fr;column-gap:8px;align-items:baseline}
.mn-sum-bar .rv-bar{grid-column:1/-1}
.mn-left{color:#B45309}
.mn-t td{vertical-align:top}
.mn-name{min-width:170px;max-width:220px;white-space:normal}
.mn-docs{min-width:150px}
.mn-d-top{display:flex;gap:6px;align-items:baseline}
.mn-iss{color:var(--red);font-weight:800;font-size:11px}
.rv-bar.thin{height:5px;margin:4px 0}
.mn-go{display:inline-block;margin-top:3px;font-size:11.5px;font-weight:800;color:var(--royal-blue)}
.mc{min-width:150px}
.mc-sys{display:grid;grid-template-columns:auto 1fr;column-gap:6px;font-size:11px;color:var(--muted);line-height:1.5}
.mc-sys b{color:var(--text);font-variant-numeric:tabular-nums;text-align:end}
.mc-sys.paper b{color:#1E3A8A}
.mc-diff{color:var(--red);font-weight:800;font-size:11px}
.mc-row{display:flex;gap:6px;align-items:center;margin-top:4px}
.mc-docs{font-size:11px;font-weight:800;padding:2px 7px;border-radius:6px;background:#FFF7ED;color:#9A3412;text-decoration:none;white-space:nowrap}
.mc-docs.done{background:#F0FDF4;color:#166534}
.mn-open{min-width:170px}
.mn-open-f{display:flex;gap:4px;flex-wrap:wrap}
.mn-open-f input[type=number]{width:105px}.mn-open-f input[type=date]{width:128px}
@media (max-width:900px){.mn-sum{grid-template-columns:1fr 1fr;gap:12px}.mn-sum-bar{grid-column:1/-1}}
</style>

<script>
let rvM = null;
function rvMonth(type, id, month, name, net, bal) {
    rvM = { type, id, month, net, bal };
    document.getElementById('mTitle').textContent = @js(__('review.month_dialog', ['month' => '__M__', 'name' => '__N__'])).replace('__M__', month).replace('__N__', name);
    document.getElementById('mSysNet').textContent = net.toLocaleString();
    document.getElementById('mSysBal').textContent = bal.toLocaleString();
    document.getElementById('mNet').value = '';
    document.getElementById('mBal').value = '';
    openDlg('dlgMonth');
}
async function rvMonthSave(ev) {
    ev.preventDefault();
    const pn = parseFloat(document.getElementById('mNet').value);
    const pbRaw = document.getElementById('mBal').value;
    const pb = pbRaw === '' ? null : parseFloat(pbRaw);
    const match = Math.abs(pn - rvM.net) < 1 && (pb === null || Math.abs(pb - rvM.bal) < 1);
    let note = null;
    if (!match) {
        note = prompt(@js(__('review.issue_prompt')), '');
        if (note === null) return false;
    }
    await rvSend(rvM.type, rvM.id, ['m:' + rvM.month], match ? 'ok' : 'issue', note, {
        paper_net: pn, paper_balance: pb, system_net: rvM.net, system_balance: rvM.bal,
    });
    closeDlg('dlgMonth');
    location.reload();
    return false;
}
</script>

@endsection
