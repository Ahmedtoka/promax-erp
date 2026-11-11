@extends('layouts.system')

{{--
    ✨ محفظة العملاء الحاليين (١١/١١/٢٠٢٦ — «عاوز زي المحتملين بالظبط»):
    نفس `erp/leads.blade.php` سكشن بسكشن: كروت بتفلتر ← فلاتر بليبلات ←
    خريطة بشيبس المناطق ← توزيعة المناطق ← بار التسكين الجماعي ← القايمة.
--}}

@section('title', __('journey.pf_portfolio_title'))

@php
    $fmt = fn ($n) => number_format((float) $n);
    $money = fn ($n) => number_format((float) $n, 2);
@endphp

@section('actions')
    @if (\App\Support\Access::allows(auth()->user(), 'ops.geo'))
        <a class="btn" href="{{ route('ops.geo') }}">🧭 {{ __('journey.rb_title') }}</a>
    @endif
    @if (\App\Support\Access::allows(auth()->user(), 'erp.clients'))
        <a class="btn gold" href="{{ route('erp.clients') }}">➕ {{ __('journey.pf_new_client') }}</a>
    @endif
@endsection

@section('content')

@include('partials._module_tabs', ['module' => 'clients'])

@php $kUrl = fn (array $x) => request()->fullUrlWithQuery($x + ['page' => null, 'export' => null]).'#pfList'; @endphp
<div class="kpis">
    <a @class(['kpi', 'on' => ($filters['focus'] ?? '') === 'active']) href="{{ $kUrl(['focus' => 'active', 'status' => null]) }}">
        <div class="lbl">{{ __('journey.pf_k_active') }}</div>
        <div class="val">{{ $fmt($stats['active']) }}</div>
        <div class="sub2"><span dir="ltr">{{ $fmt($stats['total']) }} =</span> {{ collect($stats['parts'])->map(fn ($n, $st) => __('journey.pf_status_'.$st).' '.$fmt($n))->implode(' + ') ?: '0' }}</div>
    </a>
    <a @class(['kpi', 'on' => ($filters['focus'] ?? '') === 'planned']) href="{{ $kUrl(['focus' => 'planned']) }}">
        <div class="lbl">{{ __('journey.pf_k_planned') }}</div>
        <div class="val pos">{{ $fmt($stats['planned']) }}</div>
        <div class="sub2"><span dir="ltr">{{ $stats['total'] > 0 ? number_format($stats['planned'] / $stats['total'] * 100, 1) : 0 }}% = {{ $fmt($stats['planned']) }} ÷ {{ $fmt($stats['total']) }}</span></div>
    </a>
    <a @class(['kpi', 'on' => ($filters['focus'] ?? '') === 'late']) href="{{ $kUrl(['focus' => 'late']) }}">
        <div class="lbl">{{ __('journey.pf_k_late') }}</div>
        <div class="val {{ $stats['late'] > 0 ? 'neg' : 'pos' }}">{{ $fmt($stats['late']) }}</div>
        <div class="sub2">{{ __('journey.pf_k_late_note', ['n' => \App\Http\Controllers\RoutePortfolioController::LATE_DAYS]) }}</div>
    </a>
    <a @class(['kpi', 'on' => ($filters['focus'] ?? '') === 'new']) href="{{ $kUrl(['focus' => 'new']) }}">
        <div class="lbl">{{ __('journey.pf_k_new') }}</div>
        <div class="val pos">{{ $fmt($stats['new']) }}</div>
        <div class="sub2">{{ __('journey.pf_k_new_note') }}</div>
    </a>
    <a @class(['kpi', 'on' => ($filters['status'] ?? '') === 'pending']) href="{{ $kUrl(['status' => 'pending', 'focus' => null]) }}">
        <div class="lbl">{{ __('journey.pf_k_pending') }}</div>
        <div class="val">{{ $fmt($stats['parts']['pending'] ?? 0) }}</div>
        <div class="sub2">{{ __('journey.pf_k_pending_note') }}</div>
    </a>
    <a @class(['kpi', 'on' => ($filters['focus'] ?? '') === 'owes']) href="{{ $kUrl(['focus' => 'owes']) }}">
        <div class="lbl">{{ __('journey.pf_k_balance') }}</div>
        <div class="val num">{{ $money($stats['balance']) }}</div>
        <div class="sub2">{{ __('journey.pf_k_balance_note', ['n' => $fmt($stats['owes_n'])]) }}</div>
    </a>
    <a @class(['kpi', 'on' => ($filters['focus'] ?? '') === 'assigned']) href="{{ $kUrl(['focus' => 'assigned', 'unassigned' => null]) }}">
        <div class="lbl">{{ __('journey.pf_k_assigned') }}</div>
        <div class="val pos">{{ $fmt($stats['assigned']) }}</div>
        <div class="sub2"><span dir="ltr">{{ $fmt($stats['assigned']) }} = {{ $fmt($stats['total']) }} − {{ $fmt($stats['total'] - $stats['assigned']) }}</span></div>
    </a>
    <a @class(['kpi', 'on' => (bool) ($filters['unassigned'] ?? false)]) href="{{ $kUrl(['unassigned' => 1, 'focus' => null]) }}">
        <div class="lbl">{{ __('journey.pf_k_unassigned') }}</div>
        <div class="val {{ $stats['total'] - $stats['assigned'] > 0 ? 'mid' : 'pos' }}">{{ $fmt($stats['total'] - $stats['assigned']) }}</div>
        <div class="sub2">{{ __('journey.pf_k_unassigned_note') }}</div>
    </a>
</div>

<div class="card">
    <h3>🎯 {{ __('journey.pf_portfolio_title') }} <span class="side">{{ __('journey.pf_sub') }}</span></h3>

    <form method="GET" action="{{ route('ops.portfolio') }}" class="searchbar" style="row-gap:10px">
        <label class="fl grow"><span>{{ __('ui.l_search') }}</span>
            <input type="text" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="🔎 {{ __('common.search') }}"></label>
        <label class="fl"><span>{{ __('ui.l_status') }}</span>
            <select name="status">
                <option value="">{{ __('ui.all_of', ['x' => __('uia.x_statuses')]) }}</option>
                @foreach ($statuses as $s)
                    <option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ __('journey.pf_status_'.$s) }}</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('uia.l_followup') }}</span>
            <select name="focus">
                <option value="">{{ __('journey.pf_focus_all') }}</option>
                @foreach (\App\Http\Controllers\RoutePortfolioController::FOCUSES as $fk)
                    <option value="{{ $fk }}" @selected(($filters['focus'] ?? '') === $fk)>{{ __('journey.pf_focus_'.$fk) }}</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('ui.l_zone') }}</span>
            @include('partials._zone_select', [
                'zones' => $zones, 'name' => 'zone',
                'selected' => $filters['zone'] ?? null, 'placeholder' => __('client.all_zones'),
            ])</label>
        <label class="fl"><span>{{ __('ui.l_rep') }}</span>
            <select name="rep">
                <option value="">{{ __('ui.all_of', ['x' => __('uia.x_reps')]) }}</option>
                @foreach ($reps as $r)
                    <option value="{{ $r->id }}" @selected(($filters['rep'] ?? '') == $r->id)>{{ $r->displayName() }}</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('journey.f_channel') }}</span>
            <select name="channel">
                <option value="">{{ __('journey.all_channels') }}</option>
                @foreach ($channels as $c)
                    <option value="{{ $c->id }}" @selected(($filters['channel'] ?? '') == $c->id)>{{ $c->displayName() }}</option>
                @endforeach
            </select></label>
        <label class="fl wide"><span>{{ __('journey.f_chain') }}</span>
            <select name="chain">
                <option value="">{{ __('journey.all_chains') }}</option>
                @foreach ($chains as $g)
                    <option value="{{ $g->id }}" @selected(($filters['chain'] ?? '') == $g->id)>{{ $g->displayName() }} ({{ $chainCounts[$g->id] ?? 0 }})</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('ui.l_sort') }}</span>
            <select name="sort">
                <option value="name" @selected($sort === 'name')>{{ __('journey.pf_sort_name') }}</option>
                <option value="balance" @selected($sort === 'balance')>{{ __('journey.pf_sort_balance') }}</option>
                <option value="recent" @selected($sort === 'recent')>{{ __('journey.pf_sort_recent') }}</option>
            </select></label>
        <label style="display:inline-flex;align-items:center;gap:5px;font-size:12px;white-space:nowrap;padding-bottom:8px">
            <input type="checkbox" name="unassigned" value="1" @checked($filters['unassigned'] ?? false)>
            {{ __('journey.pf_only_unassigned') }}
        </label>
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue()])
        <button class="btn gold">{{ __('common.filter') }}</button>
        <a class="btn" href="{{ route('ops.portfolio') }}">🧹 {{ __('lead.clear_filters') }}</a>
        <a class="btn sm green" href="{{ request()->fullUrlWithQuery(['export' => 1, 'page' => null]) }}">⬇ {{ __('ui.export_all') }}</a>
    </form>
</div>

{{-- ═══ خريطة المحفظة — النقط ملونة بحداثة آخر زيارة ═══ --}}
@if ($mapPoints->isNotEmpty())
<div class="card" style="margin-bottom:14px">
    <h3 style="margin:0 0 10px">🗺️ {{ __('journey.pf_map_title') }}
        <span class="side">{{ number_format($mapPoints->count()) }} {{ __('lead.map_pts') }}</span></h3>
    <div id="pfZoneChips" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px;max-height:92px;overflow-y:auto"></div>
    <div class="mapbox" id="pfMap" style="height:440px"></div>
    <div style="display:flex;gap:14px;flex-wrap:wrap;margin-top:8px;font-size:11px;color:var(--muted)">
        @foreach (['week' => '#0F7A38', 'fortnight' => '#2563EB', 'late' => '#B45309', 'never' => '#DC2626', 'off' => '#6B7280'] as $st => $cc)
            <span><i style="display:inline-block;width:10px;height:10px;border-radius:50%;background:{{ $cc }}"></i>
                {{ __('journey.pf_pin_'.$st) }}</span>
        @endforeach
        <span style="margin-inline-start:auto">⚪ {{ __('journey.pf_map_unassigned_hint') }}</span>
    </div>
</div>
@endif

{{-- ═══ توزيعة المناطق — «وزّع N من المنطقة دي» ═══ --}}
@if ($zoneRows->isNotEmpty())
<div class="card" style="margin-bottom:14px">
    <h3 style="margin:0 0 10px">📍 {{ __('lead.zones_title') }}
        <span class="side">{{ __('journey.pf_zones_hint') }}</span></h3>
    <div class="tablewrap" style="max-height:340px;overflow-y:auto">
        <table data-plain>
            <thead>
            <tr>
                <th style="text-align:start">{{ __('client.zone') }}</th>
                <th>{{ __('lead.z_total') }}</th>
                <th>{{ __('journey.pf_z_active') }}</th>
                <th>{{ __('journey.pf_z_unassigned') }}</th>
                <th>{{ __('journey.pf_z_unplanned') }}</th>
                <th data-nosum style="text-align:start">{{ __('lead.z_with') }}</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @php $zoneById = $zones->keyBy('id'); @endphp
            @foreach ($zoneRows as $zr)
                @php $z = $zr->zone_id !== null ? $zoneById->get($zr->zone_id) : null; @endphp
                <tr class="ld-zrow" @if ($z !== null)
                        onclick="if (event.target.closest('button,form,a')) return; window.location = {{ json_encode(route('ops.portfolio', ['zone' => $z->id]), JSON_HEX_APOS | JSON_HEX_QUOT) }};"
                        style="cursor:pointer" title="{{ __('lead.zrow_hint') }}"
                    @endif>
                    <td style="text-align:start;font-weight:800">
                        {{ $z?->displayName() ?? __('lead.no_zone') }}
                        @if ($z !== null && ! $z->active)
                            <span class="badge b-gray" style="font-size:9px">{{ __('lead.zone_inactive') }}</span>
                        @endif
                    </td>
                    <td class="num">{{ number_format($zr->total) }}</td>
                    <td class="num">{{ number_format($zr->active_n) }}</td>
                    <td class="num {{ $zr->unassigned > 0 ? 'mid' : '' }}"><b>{{ number_format($zr->unassigned) }}</b></td>
                    <td class="num {{ $zr->unplanned > 0 ? 'mid' : '' }}">{{ number_format($zr->unplanned) }}</td>
                    <td style="text-align:start">
                        @forelse ($zoneReps->get($zr->zone_id) ?? collect() as $rr)
                            <span class="badge b-blue" style="font-size:9.5px;margin:1px">{{ $rr->rep_name }} ×{{ $rr->n }}</span>
                        @empty
                            <span style="color:var(--muted);font-size:11px">—</span>
                        @endforelse
                    </td>
                    <td>
                        @if ($canAssign && $zr->unassigned > 0 && $z !== null)
                            <button class="btn sm" type="button"
                                    onclick="pfOpenBulk({{ $z->id }}, {{ json_encode($z->displayName(), JSON_UNESCAPED_UNICODE) }}, {{ (int) $zr->unassigned }})">
                                🎯 {{ __('lead.bulk_btn') }}</button>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

<div class="card">
    @if (\App\Support\Access::action(auth()->user(), 'act.field.plan'))
        <form method="POST" action="{{ route('ops.portfolio.bulkset') }}" id="pfBulkForm"
              style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px;
                     background:var(--blue-050,#E8F1FF);border:1px solid var(--royal-blue,#12399B);
                     border-radius:12px;padding:10px 14px">
            @csrf
            <input type="hidden" name="all_filtered" id="pfAllFiltered" value="">
            @foreach (['status', 'zone', 'rep', 'channel', 'chain', 'search', 'unassigned', 'focus', 'from', 'to'] as $fk)
                @if (($filters[$fk] ?? '') !== '' && $filters[$fk] !== null)
                    <input type="hidden" name="{{ $fk }}" value="{{ $filters[$fk] }}">
                @endif
            @endforeach
            <b style="font-size:12.5px">🎯 {{ __('journey.pf_bulkset_title') }}</b>
            <span id="pfCkCount" class="badge b-blue">0</span>
            <select name="target" required style="flex:0 1 260px">
                <option value="">— {{ __('lead.assign_to') }} —</option>
                @foreach ($reps as $r)
                    <option value="r-{{ $r->id }}">{{ $r->displayName() }} ({{ $r->code }})</option>
                @endforeach
            </select>
            <button class="btn gold" type="submit" id="pfApplyBtn" disabled>✅ {{ __('lead.apply_all') }}</button>
            <span id="pfAllBanner" style="display:none;align-items:center;gap:6px;font-size:11.5px;
                   font-weight:800;background:#FFF4D6;border:1px solid #E3C56A;border-radius:999px;padding:4px 12px">
                ⚡ <span id="pfAllBannerTxt"></span>
                <button type="button" class="btn sm" id="pfAllCancel" style="padding:1px 8px">✕</button>
            </span>
        </form>
    @endif
    <div class="tablewrap" id="pfList">
        <table>
            <tr>
                @if ($canAssign)
                    <th data-nosum style="width:32px"><input type="checkbox" id="pfCkAll" title="{{ __('lead.select_all') }}"></th>
                @endif
                <th>{{ __('common.code') }}</th>
                <th class="num" data-nosum title="{{ __('journey.pf_last_visit_hint') }}">{{ __('journey.pf_last_visit') }}</th>
                <th>{{ __('common.name') }}</th>
                <th data-nosum>{{ __('common.phone') }}</th>
                <th>{{ __('client.zone') }}</th>
                <th>{{ __('journey.pf_rep') }}</th>
                <th class="num">{{ __('journey.pf_balance') }}</th>
                <th data-nosum>{{ __('journey.pf_plan_days') }}</th>
                <th>{{ __('common.status') }}</th>
                <th></th>
            </tr>
            @forelse ($clients as $c)
                @php
                    $lv = $lastVisit->get($c->id);
                    $ld = $lv !== null ? (int) \Illuminate\Support\Carbon::parse($lv)->diffInDays(now()) : null;
                    $lcls = $ld === null ? 'b-red' : ($ld <= 7 ? 'b-green' : ($ld <= 14 ? 'b-blue' : 'b-orange'));
                @endphp
                <tr>
                    @if ($canAssign)
                        <td>@if ($c->status === 'active')<input type="checkbox" class="pf-ck" form="pfBulkForm" name="ids[]" value="{{ $c->id }}">@endif</td>
                    @endif
                    <td class="num s">{{ $c->code }}</td>
                    <td class="num"><span class="badge {{ $lcls }}" title="{{ $lv }}">{{ $ld === null ? '—' : __('journey.pf_days_ago', ['n' => $ld]) }}</span></td>
                    <td>
                        <a href="{{ route('erp.clients.show', $c) }}"><b>{{ $c->fullName() }}</b></a>
                        @if ($c->group || $c->channel)
                            <br><span style="font-size:10.5px;color:var(--muted)">{{ collect([$c->group?->displayName(), $c->channel?->displayName()])->filter()->implode(' · ') }}</span>
                        @endif
                    </td>
                    <td class="num s">{{ $c->phone ?: '—' }}</td>
                    <td class="s">
                        {{ $c->zone?->displayName() ?: '—' }}
                        @if ($c->address)
                            <div style="font-size:10px;color:var(--muted);max-width:180px" title="{{ $c->address }}">📍 {{ \Illuminate\Support\Str::limit($c->address, 45) }}</div>
                        @endif
                    </td>
                    <td class="s">
                        @if ($c->rep)<a href="{{ route('ops.rep', $c->rep) }}">{{ $c->rep->displayName() }}</a>@else — @endif
                        @if ($c->manager_id !== null && $c->rep_id === null)
                            <br><span class="badge b-purple" style="font-size:9px">👔 {{ __('lead.at_manager') }}: {{ $c->manager?->displayName() ?? '—' }}</span>
                        @endif
                    </td>
                    <td class="num {{ (float) $c->balance > 0 ? 'neg' : '' }}">{{ $money($c->balance) }}</td>
                    <td class="s">{{ $planDays[$c->id] ?? '—' }}</td>
                    <td><span class="badge {{ $c->status === 'active' ? 'b-green' : 'b-gray' }}">{{ __('journey.pf_status_'.$c->status) }}</span></td>
                    <td class="num">
                        <a class="btn sm" href="{{ route('erp.clients.show', $c) }}">{{ __('journey.pf_card') }}</a>
                        @if ($c->rep_id && \App\Support\Access::allows(auth()->user(), 'ops.geo'))
                            <a class="btn sm" href="{{ route('ops.geo', ['rep' => $c->rep_id]) }}" title="{{ __('journey.rb_title') }}">🧭</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ $canAssign ? 11 : 10 }}" style="text-align:center;color:var(--muted);padding:28px">{{ __('journey.pf_none') }}</td></tr>
            @endforelse
            @if ($clients->total() > 0)
                <tfoot><tr>
                    <td colspan="{{ $canAssign ? 7 : 6 }}"><b>Σ {{ __('common.total') }}</b> — {{ __('ui.rows_n', ['n' => number_format($clients->total())]) }}</td>
                    <td class="num"><b>{{ $money($stats['balance']) }}</b></td>
                    <td colspan="3"></td>
                </tr></tfoot>
            @endif
        </table>
    </div>

    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:12px">
        <span style="font-size:11.5px;color:var(--muted);font-weight:800">{{ __('lead.per_page') }}:</span>
        @foreach ([30, 60, 100, 'all'] as $opt)
            @php $lbl = $opt === 'all' ? __('lead.show_all') : $opt; @endphp
            @if ($per === $opt)
                <span class="btn sm gold" style="pointer-events:none">{{ $lbl }}</span>
            @else
                <a class="btn sm" href="{{ request()->fullUrlWithQuery(['per' => $opt, 'page' => null]) }}">{{ $lbl }}</a>
            @endif
        @endforeach
        @if ($per === 'all' && $stats['total'] > 2000)
            <span class="badge b-orange">{{ __('lead.all_capped', ['n' => 2000]) }}</span>
        @endif
    </div>

    @include('partials._pagination', ['p' => $clients])
</div>

@if (\App\Support\Access::action(auth()->user(), 'act.field.plan'))
<dialog id="dlgPfBulk">
    <form method="POST" action="{{ route('ops.portfolio.bulk') }}" class="dlg">
        @csrf
        <h3>🎯 {{ __('journey.pf_bulk_title') }}</h3>
        <div style="font-size:12px;color:var(--muted);margin-bottom:12px" id="pfBlkZoneLine"></div>
        <input type="hidden" name="zone_id" id="pfBlkZone" value="">
        <label class="f">{{ __('lead.assign_to') }}</label>
        <select name="target" required style="width:100%;margin-bottom:10px">
            <option value="">— {{ __('lead.assign_to') }} —</option>
            @foreach ($reps as $r)
                <option value="r-{{ $r->id }}">{{ $r->displayName() }} ({{ $r->code }})</option>
            @endforeach
        </select>
        <label class="f">{{ __('lead.bulk_count') }}</label>
        <input type="number" name="count" id="pfBlkCount" min="1" max="200" value="5" style="width:100%;margin-bottom:6px">
        <div class="dash-hint" style="margin-bottom:12px">{{ __('journey.pf_bulk_hint') }}</div>
        <div style="display:flex;gap:8px;justify-content:flex-end">
            <button class="btn" type="button" onclick="closeDlg('dlgPfBulk')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">🎯 {{ __('lead.bulk_btn') }}</button>
        </div>
    </form>
</dialog>

<dialog id="dlgPfScope">
    <div class="dlg" style="max-width:420px">
        <h4>☑️ {{ __('lead.sel_scope_title') }}</h4>
        <div style="font-size:12px;color:var(--muted);margin-bottom:14px" id="pfScopeHint"></div>
        <div style="display:flex;flex-direction:column;gap:8px">
            <button class="btn" type="button" id="pfScopePage"></button>
            <button class="btn gold" type="button" id="pfScopeAll"></button>
            <button class="btn" type="button" onclick="closeDlg('dlgPfScope')" style="opacity:.7">{{ __('common.cancel') }}</button>
        </div>
    </div>
</dialog>
@endif

@endsection

@section('scripts')
<style>
.ld-zchip{display:inline-flex;align-items:center;gap:6px;padding:4px 11px;border-radius:999px;
    cursor:pointer;border:1.5px solid var(--royal-blue,#12399B);
    background:var(--blue-050,#E8F1FF);font:inherit;font-size:11.5px;font-weight:800}
.ld-zchip.off{opacity:.35;border-style:dashed;background:transparent}
</style>
<script>
    /* ═══ التحديد المتعدد + Apply — نفس سلوك المحتملين (الصفحة دي / كل نتايج الفلتر) ═══ */
    (function () {
        'use strict';
        var all = document.getElementById('pfCkAll');
        var btn = document.getElementById('pfApplyBtn');
        var count = document.getElementById('pfCkCount');
        if (!all || !btn) return;
        var allInput = document.getElementById('pfAllFiltered');
        var banner = document.getElementById('pfAllBanner');
        var bannerTxt = document.getElementById('pfAllBannerTxt');
        var form = document.getElementById('pfBulkForm');
        var TOTAL = {{ (int) $stats['active'] }};
        var T = {
            page: @js(__('lead.sel_page_only')), allF: @js(__('lead.sel_all_filtered')),
            hint: @js(__('lead.sel_scope_hint')), on: @js(__('lead.sel_all_on')), confirm: @js(__('lead.sel_all_confirm')),
        };
        function boxes() { return document.querySelectorAll('.pf-ck'); }
        function refresh() {
            var n = document.querySelectorAll('.pf-ck:checked').length;
            var allMode = allInput.value === '1';
            count.textContent = allMode ? TOTAL : n;
            btn.disabled = allMode ? false : n === 0;
        }
        function setAllMode(on) {
            allInput.value = on ? '1' : '';
            banner.style.display = on ? 'inline-flex' : 'none';
            if (on) bannerTxt.textContent = T.on.replace(':n', TOTAL);
            refresh();
        }
        function checkPage(v) { boxes().forEach(function (c) { c.checked = v; }); }
        all.addEventListener('change', function () {
            if (!all.checked) { checkPage(false); setAllMode(false); return; }
            var pageN = boxes().length;
            if (TOTAL > pageN) {
                all.checked = false;
                document.getElementById('pfScopeHint').textContent = T.hint.replace(':total', TOTAL).replace(':page', pageN);
                document.getElementById('pfScopePage').textContent = '☑️ ' + T.page.replace(':n', pageN);
                document.getElementById('pfScopeAll').textContent = '⚡ ' + T.allF.replace(':n', TOTAL);
                openDlg('dlgPfScope');
            } else { checkPage(true); setAllMode(false); }
        });
        document.getElementById('pfScopePage').addEventListener('click', function () {
            closeDlg('dlgPfScope'); all.checked = true; checkPage(true); setAllMode(false);
        });
        document.getElementById('pfScopeAll').addEventListener('click', function () {
            closeDlg('dlgPfScope'); all.checked = true; checkPage(true); setAllMode(true);
        });
        document.getElementById('pfAllCancel').addEventListener('click', function () { setAllMode(false); });
        boxes().forEach(function (c) {
            c.addEventListener('change', function () {
                if (!c.checked && allInput.value === '1') { setAllMode(false); all.checked = false; }
                refresh();
            });
        });
        form.addEventListener('submit', function (e) {
            if (allInput.value === '1' && !confirm(T.confirm.replace(':n', TOTAL))) e.preventDefault();
        });
        refresh();
    })();

    const PF_BULK_WORD = @js(__('lead.bulk_available'));
    function pfOpenBulk(zoneId, zoneName, available) {
        document.getElementById('pfBlkZone').value = zoneId;
        document.getElementById('pfBlkZoneLine').textContent = zoneName + ' — ' + PF_BULK_WORD.replace(':n', available);
        const c = document.getElementById('pfBlkCount');
        c.max = Math.min(200, available);
        c.value = Math.min(5, available);
        openDlg('dlgPfBulk');
    }

    /* ═══ خريطة المحفظة — نفس بن المحتملين، اللون بحداثة الزيارة ═══ */
    (function () {
        'use strict';
        const el = document.getElementById('pfMap');
        if (!el) return;
        const PTS = {!! json_encode($mapPoints, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!};
        const ZNAMES = @js($zones->mapWithKeys(fn ($z) => [$z->id => $z->displayName()]));
        const NO_ZONE = @js(__('lead.no_zone'));
        const COLOR = { week: '#0F7A38', fortnight: '#2563EB', late: '#B45309', never: '#DC2626', off: '#6B7280' };
        const LABEL = @js(collect(['week', 'fortnight', 'late', 'never', 'off'])->mapWithKeys(fn ($s) => [$s => __('journey.pf_pin_'.$s)]));
        const DAYS = @js(__('journey.pf_days_ago', ['n' => '__N__']));
        function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

        const map = L.map('pfMap', { scrollWheelZoom: false });
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);

        function pinIcon(c, unassigned) {
            return L.divIcon({
                className: '', iconSize: [26, 34], iconAnchor: [13, 32], popupAnchor: [0, -28],
                html: '<svg width="26" height="34" viewBox="0 0 26 34" style="filter:drop-shadow(0 2px 3px rgba(0,0,0,.35))">'
                    + '<path d="M13 1C6.4 1 1 6.4 1 13c0 8.8 12 20 12 20s12-11.2 12-20C25 6.4 19.6 1 13 1z" fill="' + c + '" stroke="#fff" stroke-width="1.6"/>'
                    + '<circle cx="13" cy="13" r="4.6" fill="' + (unassigned ? '#fff' : 'rgba(255,255,255,.4)') + '"/></svg>',
            });
        }
        const layers = {}, counts = {}, bounds = [];
        PTS.forEach(function (p) {
            const zid = String(p.zone || 0);
            if (!layers[zid]) { layers[zid] = L.layerGroup().addTo(map); counts[zid] = 0; }
            counts[zid]++;
            const m = L.marker([p.lat, p.lng], { icon: pinIcon(COLOR[p.st] || '#2563EB', !p.rep) });
            m.bindPopup('<div style="font:700 12.5px Cairo,Inter,sans-serif;text-align:start">' + esc(p.name)
                + '<div style="font-weight:400;font-size:11px;color:#6B6B66;margin-top:2px">'
                + (LABEL[p.st] || '') + (p.days !== '—' ? ' · ' + DAYS.replace('__N__', p.days) : '')
                + ' · ' + esc(ZNAMES[p.zone] || NO_ZONE) + '</div></div>');
            layers[zid].addLayer(m);
            // الزووم على نقط مصر بس — لوكيشن غلط واحد كان بيفتح الخريطة على العالم كله
            if (p.lat > 22 && p.lat < 32 && p.lng > 24 && p.lng < 37) bounds.push([p.lat, p.lng]);
        });
        if (bounds.length === 1) map.setView(bounds[0], 13);
        else if (bounds.length) map.fitBounds(L.latLngBounds(bounds).pad(0.1));
        map.on('click', function () { map.scrollWheelZoom.enable(); });
        map.on('mouseout', function () { map.scrollWheelZoom.disable(); });

        const chipBox = document.getElementById('pfZoneChips');
        const chips = {};
        function setZone(zid, on) {
            chips[zid].classList.toggle('off', !on);
            if (on) layers[zid].addTo(map); else map.removeLayer(layers[zid]);
        }
        [['✅ ' + @js(__('lead.chips_all')), true], ['⬜ ' + @js(__('lead.chips_none')), false]].forEach(function (b) {
            const x = document.createElement('button');
            x.type = 'button'; x.className = 'btn sm'; x.textContent = b[0];
            x.addEventListener('click', function () { Object.keys(layers).forEach(function (z) { setZone(z, b[1]); }); });
            chipBox.appendChild(x);
        });
        Object.keys(layers).sort(function (a, b) { return counts[b] - counts[a]; }).forEach(function (zid) {
            const b = document.createElement('button');
            b.type = 'button'; b.className = 'ld-zchip';
            b.innerHTML = '📍 ' + esc(ZNAMES[zid] || NO_ZONE) + ' <span class="badge b-gray" style="font-size:9.5px">' + counts[zid] + '</span>';
            b.addEventListener('click', function () { setZone(zid, b.classList.contains('off')); });
            chips[zid] = b;
            chipBox.appendChild(b);
        });
    })();
</script>
@endsection
