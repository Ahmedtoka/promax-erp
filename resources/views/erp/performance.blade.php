@extends('layouts.system')

{{--
    لوحة أداء المناديب (2026-08-06) — كل مؤشرات RepKpis في جدول واحد:
    مبيعات وتحقيق (ببارات) + زيارات ونشاط (فتح/تشيك إن/أوت/قعدة/كم)
    + نقاط وعمولة — ومنح نقاط يدوي بسبب.
--}}

@php
    $fmt = fn ($n) => number_format((float) $n);
    $canPoints = auth()->user()->isAdmin() || auth()->user()->role === 'manager';

    // بار تحقيق صغير — لون حسب النسبة
    $barColor = fn ($p) => $p >= 100 ? 'var(--green, #1B7A3D)' : ($p >= 70 ? 'var(--orange, #B86E00)' : 'var(--red, #B00020)');
@endphp

@section('title', __('incent.performance_title'))

@section('actions')
    <a class="btn" href="{{ route('erp.targets', ['month' => $month->format('Y-m')]) }}">🎯 {{ __('nav.targets') }}</a>
@endsection

@section('content')

@if (session('ok'))
    <div class="alert good" style="margin-bottom:12px"><span>✅</span><span>{{ session('ok') }}</span></div>
@endif
@if ($errors->any())
    <div class="alert" style="margin-bottom:12px;flex-direction:column;align-items:stretch;gap:4px">
        @foreach ($errors->all() as $msg)<div class="errline" style="margin:0">{{ $msg }}</div>@endforeach
    </div>
@endif

<div class="card">
    <h3>🏆 {{ __('incent.performance_title') }}
        <span class="side">{{ __('incent.performance_hint') }}</span></h3>

    <div class="searchbar" style="margin-bottom:12px">
        <form method="GET" style="display:flex;gap:8px;align-items:center">
            <label class="f" style="margin:0">{{ __('incent.month') }}</label>
            <input type="month" name="month" value="{{ $month->format('Y-m') }}" onchange="this.form.submit()">
        </form>
        @if ($canPoints)
            <button class="btn gold" style="margin-inline-start:auto" onclick="openDlg('dlgPts')">➕ {{ __('incent.add_points') }}</button>
        @endif
    </div>

    <div class="tablewrap pf-tbl" style="max-height:66vh;overflow-y:auto">
        <table>
            <thead>
                <tr>
                    <th style="text-align:start">{{ __('settle.rep') }}</th>
                    <th style="width:190px">💰 {{ __('incent.net_sales') }}</th>
                    <th style="width:150px">📍 {{ __('incent.visits_done') }}</th>
                    <th style="width:130px">🏪 {{ __('incent.new_clients') }}</th>
                    <th style="width:150px">📦 {{ __('incent.pieces_sold') }}</th>
                    <th>{{ __('incent.app_opens') }}</th>
                    <th>{{ __('incent.check_ins') }}/{{ __('incent.check_outs') }}</th>
                    <th>{{ __('incent.avg_visit') }}</th>
                    <th>{{ __('incent.km_today') }}</th>
                    <th>⭐ {{ __('incent.points') }}</th>
                    <th>💵 {{ __('incent.commission') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $r)
                    @php $k = $r['kpi']; $t = $k['target']; @endphp
                    <tr>
                        <td style="text-align:start">
                            <b>{{ $r['rep']->displayName() }}</b>
                            <div style="font-size:10px;color:var(--muted)">{{ $r['rep']->code }}</div>
                        </td>
                        {{-- فلوس: الرقم + بار التحقيق من التارجت --}}
                        <td>
                            <b>{{ $fmt($k['net_sales']) }}</b>
                            @if ($t && (float) $t->money_target > 0)
                                <div style="display:flex;align-items:center;gap:6px;margin-top:3px">
                                    <div style="flex:1;height:7px;border-radius:5px;background:var(--card2,#eee);overflow:hidden">
                                        <div style="height:100%;width:{{ min($k['money_pct'], 100) }}%;background:{{ $barColor($k['money_pct']) }}"></div>
                                    </div>
                                    <span style="font-size:9.5px;font-weight:800" dir="ltr">{{ $k['money_pct'] }}%</span>
                                </div>
                                <div style="font-size:9px;color:var(--muted)">/ {{ $fmt($t->money_target) }}</div>
                            @else
                                <div style="font-size:9px;color:var(--muted)">{{ __('incent.no_target') }}</div>
                            @endif
                        </td>
                        <td>
                            <b>{{ $k['visits'] }}</b>@if ($t && $t->visits_target) <span style="color:var(--muted);font-size:10px">/ {{ $t->visits_target }}</span>
                                <div style="height:7px;border-radius:5px;background:var(--card2,#eee);overflow:hidden;margin-top:3px">
                                    <div style="height:100%;width:{{ min($k['visits_pct'], 100) }}%;background:{{ $barColor($k['visits_pct']) }}"></div>
                                </div>
                            @endif
                        </td>
                        <td>
                            <b>{{ $k['new_clients'] }}</b>@if ($t && $t->new_clients_target) <span style="color:var(--muted);font-size:10px">/ {{ $t->new_clients_target }}</span>@endif
                        </td>
                        <td>
                            <b>{{ $fmt($k['pieces']) }}</b>@if ($t && $t->pieces_target) <span style="color:var(--muted);font-size:10px">/ {{ $fmt($t->pieces_target) }}</span>
                                <div style="height:7px;border-radius:5px;background:var(--card2,#eee);overflow:hidden;margin-top:3px">
                                    <div style="height:100%;width:{{ min($k['pieces_pct'], 100) }}%;background:{{ $barColor($k['pieces_pct']) }}"></div>
                                </div>
                            @endif
                        </td>
                        <td class="num">{{ $k['app_opens'] }}</td>
                        <td class="num">{{ $k['check_ins'] }} / {{ $k['check_outs'] }}</td>
                        <td class="num">{{ $k['avg_visit_minutes'] }} {{ __('incent.minute_unit') }}</td>
                        <td class="num">{{ $r['km_today'] }}</td>
                        <td>
                            <b style="font-size:14px">{{ $k['points'] }}</b>
                            <div style="font-size:9px;color:var(--muted)">≈ {{ $fmt($k['points_money']) }} {{ __('common.currency') }}</div>
                        </td>
                        <td>
                            <b class="pos" style="font-size:13px">{{ $fmt($k['commission']) }}</b>
                            <div style="font-size:9px;color:var(--muted)" dir="ltr">{{ number_format($k['commission_rate'] * 100, 2) }}%</div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

{{-- آخر النقاط اليدوية --}}
<div class="card">
    <h3>⭐ {{ __('incent.recent_points') }}</h3>
    <div class="tablewrap pf-tbl">
        <table>
            <tr><th>{{ __('settle.rep') }}</th><th>{{ __('incent.points_value') }}</th><th style="text-align:start">{{ __('incent.reason') }}</th><th>{{ __('common.date') }}</th><th>{{ __('settle.by') }}</th></tr>
            @forelse ($recentPoints as $p)
                <tr>
                    <td>{{ $p->user?->displayName() ?? '—' }}</td>
                    <td class="num"><b class="{{ $p->points > 0 ? 'pos' : 'neg' }}">{{ $p->points > 0 ? '+' : '' }}{{ $p->points }}</b></td>
                    <td style="text-align:start">{{ $p->reason }}</td>
                    <td class="num" style="font-size:11px">{{ $p->date->format('Y-m-d') }}</td>
                    <td class="s">{{ $p->creator?->name ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:20px">—</td></tr>
            @endforelse
        </table>
    </div>
</div>

@if ($canPoints)
<dialog id="dlgPts">
    <form class="dlg" method="POST" action="{{ route('erp.performance.points') }}">
        @csrf
        <h4>⭐ {{ __('incent.add_points') }}</h4>
        <div class="alert info" style="margin-bottom:12px"><span>ℹ️</span><span>{{ __('incent.points_hint') }}</span></div>
        <div class="frow">
            <div>
                <label class="f">{{ __('settle.rep') }} <b class="req-star">*</b></label>
                <select name="user_id" required style="width:100%">
                    @foreach ($rows as $r)
                        <option value="{{ $r['rep']->id }}">{{ $r['rep']->displayName() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="f">{{ __('incent.points_value') }} <b class="req-star">*</b></label>
                <input type="number" name="points" required min="-1000" max="1000" step="1" dir="ltr"
                       style="width:100%;text-align:center;font-weight:800">
            </div>
        </div>
        <label class="f" style="margin-top:10px">{{ __('incent.reason') }} <b class="req-star">*</b></label>
        <input type="text" name="reason" required maxlength="190" style="width:100%">
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgPts')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">💾 {{ __('common.save') }}</button>
        </div>
    </form>
</dialog>
@endif

@endsection

@section('scripts')
<style>.pf-tbl th, .pf-tbl td { text-align: center; vertical-align: middle; }</style>
@endsection
