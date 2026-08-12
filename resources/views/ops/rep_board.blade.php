@extends('layouts.system')

{{--
    بورد المناديب — عهدة + مبيعات + حركة في نظرة واحدة (١٢ أغسطس ٢٠٢٦).

    الدمج بين «عهد المناديب» و«مبيعات المناديب» واللايف: كل مندوب صف
    فيه حضوره دلوقتي، حالة عهدته والباقي معاه بقيمته، بار نسبة
    التصريف، مبيعاته كاش وآجل (فواتيره + أوامر التوريد المسلَّمة من
    القيود — عقيدة ١١/٨)، تحصيلاته نقدي وغيره، زياراته المقفولة من
    الكل، وآخر حركة تراكينج له. كل الداتاسِتس مجمّعة — مفيش كويري لكل صف.
--}}

@section('title', __('nav.rep_board'))

@section('actions')
    <a class="btn" href="{{ route('ops.vans') }}">🚐 {{ __('nav.vans_board') }}</a>
    <a class="btn" href="{{ route('ops.sales') }}">💵 {{ __('nav.rep_sales') }}</a>
    <a class="btn" href="{{ route('ops.live') }}">📡 {{ __('nav.live') }}</a>
@endsection

@section('content')

@php
    $fmt = fn ($n) => number_format((float) $n, 2);
    $fmt0 = fn ($n) => number_format((float) $n);
@endphp

{{-- ═══ السامري — من نفس صفوف الجدول (نطاق واحد) ═══ --}}
<div class="kpis" style="margin-bottom:14px">
    <div class="kpi"><div class="lbl">🟢 {{ __('field.board_kpi_working') }}</div><div class="val" style="color:#16A34A">{{ $kpi['working'] }}</div></div>
    <div class="kpi"><div class="lbl">🚐 {{ __('field.vans_open') }}</div><div class="val">{{ $kpi['open_vans'] }}</div></div>
    <div class="kpi"><div class="lbl">💵 {{ __('field.board_kpi_sales') }}</div><div class="val">{{ $fmt($kpi['sales']) }}</div></div>
    <div class="kpi"><div class="lbl">🧾 {{ __('field.board_kpi_colls') }}</div><div class="val" style="color:#16A34A">{{ $fmt($kpi['collections']) }}</div></div>
</div>

<div class="card">
    <h3>📊 {{ __('nav.rep_board') }}
        <span class="side">{{ __('field.board_sub') }}</span>
    </h3>

    <form class="searchbar" method="GET">
        <label class="f">{{ __('common.from') }}</label>
        <input type="date" name="from" value="{{ $from }}">
        <label class="f">{{ __('common.to') }}</label>
        <input type="date" name="to" value="{{ $to }}">
        <button class="btn gold" type="submit">🔍 {{ __('common.filter') }}</button>
        <a class="btn" href="{{ route('ops.rep_board') }}">{{ __('common.clear') }}</a>
    </form>

    <div class="tablewrap">
    <table>
        <thead>
            <tr>
                <th style="text-align:start">{{ __('hr.employee') }}</th>
                <th>{{ __('field.board_attendance') }}</th>
                <th>{{ __('field.board_custody') }}</th>
                <th style="width:150px">{{ __('field.vans_progress') }}</th>
                <th data-nosum>{{ __('field.board_sales_col') }}</th>
                <th data-nosum>{{ __('field.board_colls_col') }}</th>
                <th data-nosum title="{{ __('field.board_visits_hint') }}">{{ __('field.board_visits') }}</th>
                <th>{{ __('field.board_last_event') }}</th>
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
                                </div>
                            </div>
                        </div>
                    </td>
                    <td>
                        @if ($r['att'] === 'working')
                            <span class="pill good">🟢 {{ __('hr.state_working') }}</span>
                        @elseif ($r['att'] === 'break')
                            <span class="pill warn">⏸ {{ __('hr.state_break') }}</span>
                        @else
                            <span class="pill" style="opacity:.55">— {{ __('hr.state_off') }}</span>
                        @endif
                    </td>
                    <td>
                        @if ($r['state'] === 'open')
                            <span class="pill good">🟢 {{ __('field.van_open') }}</span>
                            <div style="font-size:10.5px;margin-top:2px">
                                {{ __('field.vans_left') }}:
                                <b dir="ltr">{{ $fmt0($r['remaining']) }}</b>
                            </div>
                            {{-- القيمة بكل قايمة مفعّلة — عرض فقط (طلب المالك ١٢/٨) --}}
                            @include('partials._list_values', ['totals' => $r['values']])
                        @elseif ($r['state'] === 'closed')
                            <span class="pill">🔒 {{ __('field.van_closed') }}</span>
                        @else
                            <span class="pill" style="opacity:.55">— {{ __('field.van_none') }}</span>
                        @endif
                    </td>
                    <td>
                        @if ($c)
                            <div style="display:flex;align-items:center;gap:7px">
                                <div style="flex:1;height:9px;border-radius:6px;background:var(--card2, #eee);border:1px solid var(--border);overflow:hidden">
                                    <div style="height:100%;width:{{ $r['pct'] }}%;border-radius:6px;background:{{ $r['pct'] >= 70 ? '#16A34A' : ($r['pct'] >= 35 ? '#B86E00' : '#B00020') }}"></div>
                                </div>
                                <span style="font-size:10.5px;font-weight:800" dir="ltr">{{ $r['pct'] }}%</span>
                            </div>
                        @else — @endif
                    </td>
                    <td class="num">
                        @if ($r['sales'] > 0)
                            <b>{{ $fmt($r['sales']) }}</b>
                            <div style="font-size:10px;color:var(--muted)">
                                {{ __('field.board_cash') }} {{ $fmt($r['cash']) }} · {{ __('field.board_credit') }} {{ $fmt($r['credit']) }}
                            </div>
                        @else — @endif
                    </td>
                    <td class="num">
                        @if ($r['coll_total'] > 0)
                            <b style="color:#16A34A">{{ $fmt($r['coll_total']) }}</b>
                            <div style="font-size:10px;color:var(--muted)">
                                {{ __('field.sales_coll_cash') }} {{ $fmt($r['coll_cash']) }} · {{ __('field.board_noncash') }} {{ $fmt($r['coll_other']) }}
                            </div>
                        @else — @endif
                    </td>
                    <td class="num">
                        @if ($r['visits_total'] > 0)
                            <span dir="ltr"><b>{{ $r['visits_done'] }}</b> / {{ $r['visits_total'] }}</span>
                        @else — @endif
                    </td>
                    <td class="num" style="font-size:11px">
                        @if ($r['last_at'])
                            <span dir="ltr">{{ $r['last_at']->format($from === $to ? 'h:i A' : 'd/m h:i A') }}</span>
                        @else
                            <span style="color:var(--muted)">—</span>
                        @endif
                    </td>
                    <td style="white-space:nowrap">
                        {{-- التراكينج بيقبل ?user= — بيفتح على المندوب ده وآخر يوم في النافذة --}}
                        <a class="btn sm" href="{{ route('ops.tracking', ['user' => $u->id, 'date' => $to]) }}">👁 {{ __('common.details') }}</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9"><div class="empty">{{ __('common.no_results') }}</div></td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>

@endsection
