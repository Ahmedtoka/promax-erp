@extends('layouts.system')

{{--
    عهد المناديب — بورد المراجعة بنظرة واحدة (١٠ أغسطس ٢٠٢٦).

    كل مندوب صف: صورته، عربيته، حالة عهدته (مفتوحة من امتى / مقفولة /
    مفيش)، المحمّل واتباع كام ومرجّع كام والهدايا، **الباقي معاه**
    بالوحدات والقيمة، بار نسبة التصريف، وهو فين دلوقتي — وزرار قفل
    العربية من نفس الصف. العهدة من `currentCustody()` (عقيدة ١٠/٨:
    المفتوحة من امبارح لسه شغالة).
--}}

@section('title', __('nav.vans_board'))

@section('actions')
    <a class="btn" href="{{ route('ops.handout') }}">📤 {{ __('field.handout') }}</a>
    <a class="btn" href="{{ route('ops.live') }}">🛰️ {{ __('nav.live') }}</a>
@endsection

@section('content')

@php $fmt = fn ($n) => number_format((float) $n); @endphp

{{-- ═══ السامري ═══ --}}
<div class="kpis" style="margin-bottom:14px">
    <div class="kpi"><div class="lbl">🚐 {{ __('field.vans_open') }}</div><div class="val" style="color:#16A34A">{{ $openCount }}</div></div>
    <div class="kpi"><div class="lbl">💰 {{ __('field.vans_street_value') }}</div><div class="val">{{ $fmt($streetValue) }}</div></div>
    <div class="kpi"><div class="lbl">📦 {{ __('field.vans_units_left') }}</div><div class="val">{{ $fmt($unitsLeft) }}</div></div>
    <div class="kpi"><div class="lbl">⚪ {{ __('field.vans_no_custody') }}</div><div class="val" style="color:var(--muted)">{{ $noneCount }}</div></div>
</div>

<div class="card">
    <h3>🚐 {{ __('nav.vans_board') }}
        <span class="side">{{ __('field.vans_value_hint') }}</span>
    </h3>
    <div class="tablewrap">
    <table class="att-tbl">
        <thead>
            <tr>
                <th style="text-align:start">{{ __('hr.employee') }}</th>
                <th>{{ __('field.van_status') }}</th>
                <th>{{ __('field.vans_loaded') }}</th>
                <th>{{ __('field.vans_sold') }}</th>
                <th>{{ __('field.vans_returned') }}</th>
                <th>{{ __('field.vans_gifts') }}</th>
                <th>{{ __('field.vans_left') }}</th>
                <th style="width:150px">{{ __('field.vans_progress') }}</th>
                <th>{{ __('field.vans_now_at') }}</th>
                <th>{{ __('field.vans_sales_today') }}</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $r)
                @php $u = $r['user']; $c = $r['custody']; @endphp
                <tr>
                    <td>
                        <div style="display:flex;gap:9px;align-items:center">
                            @include('partials._avatar', ['u' => $u, 'size' => 34])
                            <div>
                                <b>{{ $u->displayName() }}</b>
                                <div style="font-size:10.5px;color:var(--muted)">
                                    {{ $u->roleLabel() }} · <span dir="ltr">{{ $u->code }}</span>
                                    @if ($c?->vehicle) · 🚐 <span dir="ltr">{{ $c->vehicle->plate }}</span>@endif
                                </div>
                            </div>
                        </div>
                    </td>
                    <td>
                        @if ($r['state'] === 'open')
                            <span class="pill good">🟢 {{ __('field.van_open') }}</span>
                            <div style="font-size:10px;color:var(--muted);margin-top:2px" dir="ltr">{{ $c->date->format('d/m') }}</div>
                        @elseif ($r['state'] === 'closed')
                            <span class="pill">🔒 {{ __('field.van_closed') }}</span>
                        @else
                            <span class="pill" style="opacity:.55">— {{ __('field.van_none') }}</span>
                        @endif
                        {{-- حالة الحضور — مراجعة أسرع: شغال/بريك/مش حاضر --}}
                        @if ($r['att'] === 'working')
                            <span class="pill good" style="font-size:10px">{{ __('hr.state_working') }}</span>
                        @elseif ($r['att'] === 'break')
                            <span class="pill warn" style="font-size:10px">{{ __('hr.state_break') }}</span>
                        @endif
                    </td>
                    <td dir="ltr">
                        @if ($c)
                            <b>{{ $fmt($r['assigned']) }}</b>
                            <div style="font-size:10px;color:var(--muted)">{{ $fmt($r['assigned_value']) }}</div>
                        @else — @endif
                    </td>
                    <td dir="ltr">{{ $c ? $fmt($r['sold']) : '—' }}</td>
                    <td dir="ltr">{{ $c ? $fmt($r['returned']) : '—' }}</td>
                    <td dir="ltr">{{ $c && $r['gifts_left'] > 0 ? '🎁 '.$fmt($r['gifts_left']) : '—' }}</td>
                    <td dir="ltr">
                        @if ($c)
                            <b style="font-size:13.5px">{{ $fmt($r['remaining']) }}</b>
                            <div style="font-size:10.5px;font-weight:800;color:var(--royal-blue, #12399B)">{{ $fmt($r['remaining_value']) }}</div>
                        @else — @endif
                    </td>
                    <td>
                        @if ($c)
                            <div style="display:flex;align-items:center;gap:7px">
                                <div style="flex:1;height:9px;border-radius:6px;background:var(--card2, #eee);border:1px solid var(--border);overflow:hidden">
                                    <div style="height:100%;width:{{ $r['pct'] }}%;border-radius:6px;background:{{ $r['pct'] >= 70 ? '#16A34A' : ($r['pct'] >= 35 ? '#B86E00' : '#B00020') }}"></div>
                                </div>
                                <span style="font-size:10.5px;font-weight:800" dir="ltr">{{ $r['pct'] }}%</span>
                            </div>
                            @if ($r['expiring'] > 0)
                                <div style="font-size:10px;color:#B00020;margin-top:2px">⏳ {{ __('field.vans_expiring', ['count' => $r['expiring']]) }}</div>
                            @endif
                        @else — @endif
                    </td>
                    <td style="font-size:11.5px">
                        @if ($r['active_client'])
                            📍 {{ $r['active_client'] }}
                        @else
                            <span style="color:var(--muted)">—</span>
                        @endif
                    </td>
                    <td dir="ltr" style="font-weight:800">{{ $r['sales_today'] > 0 ? $fmt($r['sales_today']) : '—' }}</td>
                    <td style="white-space:nowrap">
                        <a class="btn sm" href="{{ route('ops.rep', $u) }}">👁 {{ __('common.details') }}</a>
                        {{-- تصحيح إداري للعهدة (١٢/٨) — بيفتح كارت المندوب
                             والديالوج بيتفتح لوحده (?adjust=1) --}}
                        @if ($r['state'] === 'open' && \App\Support\Access::action(auth()->user(), 'act.custody.adjust'))
                            <a class="btn sm" href="{{ route('ops.rep', $u) }}?adjust=1"
                               title="{{ __('field.custody_adjust') }}">🛠️</a>
                        @endif
                        @if ($r['state'] === 'open')
                            <form method="POST" action="{{ route('ops.rep.close', $u) }}" style="display:inline"
                                  onsubmit="return confirm(@js(__('field.vans_close_confirm', ['name' => $u->displayName()])))">
                                @csrf
                                <button class="btn sm red" type="submit">🔒 {{ __('field.vans_close') }}</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="11"><div class="empty">{{ __('common.no_results') }}</div></td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>

@endsection
