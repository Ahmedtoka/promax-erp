@extends('layouts.system')

@section('title', __('review.tab_money'))

@php
    $fmt = fn ($n) => number_format((float) $n);
    $tab = fn ($s) => route('erp.review.money', array_filter($filters + ['show' => $s]));
    $rowItems = \App\Models\ReviewCheck::moneyItems();
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

<div class="card">
    <form class="searchbar" method="GET">
        <input type="hidden" name="show" value="{{ $show }}">
        <label class="fl grow"><span>{{ __('ui.l_search') }}</span>
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="🔍 {{ __('review.col_entity') }}"></label>
        <button class="btn gold" type="submit">{{ __('common.search') }}</button>
        <a class="btn" href="{{ route('erp.review.money') }}">{{ __('common.clear') }}</a>
    </form>

    <div class="tablewrap">
        <table id="rvMoney">
            <thead><tr>
                <th>{{ __('review.col_entity') }}</th>
                <th>{{ __('review.item_prices') }}</th>
                <th>{{ __('review.item_pos') }}</th>
                @foreach ($months as $m)<th data-nosum>{{ $m }}</th>@endforeach
                <th data-nosum>{{ __('review.item_opening') }}</th>
            </tr></thead>
            <tbody>
            @forelse ($rows as $r)
                @php
                    $e = $r['model'];
                    $ch = $checks[$r['type']][$e->id] ?? [];
                    $cell = fn ($item) => view('erp.review._mark', ['type' => $r['type'], 'id' => $e->id, 'item' => $item, 'check' => $ch[$item] ?? null])->render();
                    $page = $r['type'] === 'group' ? route('erp.groups.show', $e) : route('erp.clients.show', $e);
                    $rowCls = $r['state'] === 'issue' ? 'rv-issue' : ($r['state'] === 'done' ? 'rv-done' : '');
                @endphp
                <tr data-row="{{ $r['type'] }}-{{ $e->id }}" class="{{ $rowCls }}">
                    <td>
                        <a class="rv-val rv-edit" href="{{ $page }}" target="_blank" rel="noopener">{{ $e->displayName() }}</a>
                        <div class="rv-sub">
                            @if ($r['type'] === 'group')
                                🏬 {{ __('review.branches_n', ['n' => $r['branches']]) }}@if ($e->motherCompany) · 🏢 {{ $e->motherCompany->displayName() }}@endif
                            @else
                                👤 {{ $e->code }} · {{ __('review.solo') }}
                            @endif
                        </div>
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
                        @endphp
                        <td style="min-width:150px">
                            <div class="rv-sub">{{ __('review.month_system') }}: <b>{{ $fmt($f['net']) }}</b> / {{ $fmt($f['balance']) }}</div>
                            @if ($c && $c->paper_net !== null)
                                @php $d = round((float) $c->paper_net - $f['net'], 2); $db = $c->paper_balance !== null ? round((float) $c->paper_balance - $f['balance'], 2) : 0; @endphp
                                <div class="rv-sub">{{ __('review.month_paper') }}: <b>{{ $fmt($c->paper_net) }}</b> / {{ $c->paper_balance !== null ? $fmt($c->paper_balance) : '—' }}</div>
                                @if (abs($d) >= 1 || abs($db) >= 1)
                                    <div style="color:#DC2626;font-weight:800;font-size:12px">{{ __('review.month_diff') }}: {{ $fmt($d) }} / {{ $fmt($db) }}</div>
                                @endif
                            @endif
                            {{-- ⚠️ القيم في data-* مش جوه onclick — @js بيطلّع علامات تنصيص بتكسر الأتريبيوت --}}
                            <button type="button" class="btn sm rv-month" style="margin-top:4px"
                                    data-type="{{ $r['type'] }}" data-id="{{ $e->id }}" data-month="{{ $m }}" data-name="{{ $e->displayName() }}"
                                    data-net="{{ $f['net'] }}" data-bal="{{ $f['balance'] }}"
                                    onclick="rvMonth(this.dataset.type, +this.dataset.id, this.dataset.month, this.dataset.name, +this.dataset.net, +this.dataset.bal)">{{ __('review.month_match') }}</button>
                            {!! $cell('m:'.$m) !!}
                        </td>
                    @endforeach
                    <td style="min-width:190px">
                        @if ($r['type'] === 'group')
                            @if (\App\Support\Access::action(auth()->user(), 'act.review.mark'))
                            <form method="POST" action="{{ route('erp.review.opening', $e) }}" style="display:flex;gap:4px;flex-wrap:wrap">
                                @csrf
                                <input type="number" step="0.01" name="amount" value="{{ $e->opening_balance }}" placeholder="{{ __('review.opening_chain') }}" style="width:120px;padding:6px 8px">
                                <input type="date" name="as_of" value="{{ $e->opening_as_of?->toDateString() ?? \App\Models\ReviewCheck::FIRST_MONTH.'-01' }}" style="width:130px;padding:6px 8px">
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
                <tr><td colspan="{{ 4 + count($months) }}" style="text-align:center;color:var(--muted);padding:28px">{{ __('review.no_rows') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="rv-sub" style="margin-top:10px">ℹ️ {{ __('review.opening_note') }}</div>
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
