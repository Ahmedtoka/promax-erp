@extends('layouts.system')

{{--
    مبيعات المناديب — بورد فلوس كل مندوب (١٢ أغسطس ٢٠٢٦).

    كل مندوب صف: فواتيره، مبيعاته كاش وآجل (وتحت كل رقم تفصيلة
    «منها أوامر توريد» — نفس اللبس اللي حصل في محضر مريم: 31,767 =
    29,045 فواتير + 2,722 أوامر)، تحصيلاته بالطريقة، مرتجعات الكاش،
    والصافي النقدي. فلوس الأوامر من القيود (مصدر الحقيقة) — نفس
    كويريز التصفية بالحرف. الفوتر متحسب في السيرفر من نفس الصفوف.
--}}

@section('title', __('nav.rep_sales'))

@section('actions')
    @if (\App\Support\Access::allows(auth()->user(), 'ops.vans'))
        <a class="btn" href="{{ route('ops.vans') }}">🚐 {{ __('nav.vans_board') }}</a>
    @endif
    @if (\App\Support\Access::allows(auth()->user(), 'ops.rep_board'))
        <a class="btn" href="{{ route('ops.rep_board') }}">📊 {{ __('nav.rep_board') }}</a>
    @endif
    @if (\App\Support\Access::allows(auth()->user(), 'erp.repclose'))
        <a class="btn" href="{{ route('erp.repclose') }}">🤝 {{ __('nav.repclose') }}</a>
    @endif
@endsection

@section('content')

@php $fmt = fn ($n) => number_format((float) $n, 2); @endphp

{{-- ═══ السامري — من نفس صفوف الجدول (نطاق واحد) ═══ --}}
<div class="kpis" style="margin-bottom:14px">
    <div class="kpi"><div class="lbl">💵 {{ __('field.sales_kpi_cash') }}</div><div class="val" style="color:#16A34A">{{ $fmt($kpi['cash']) }}</div></div>
    <div class="kpi"><div class="lbl">🧾 {{ __('field.sales_kpi_credit') }}</div><div class="val">{{ $fmt($kpi['credit']) }}</div></div>
    <div class="kpi"><div class="lbl">🪙 {{ __('field.sales_kpi_cash_coll') }}</div><div class="val" style="color:#16A34A">{{ $fmt($kpi['coll_cash']) }}</div></div>
    <div class="kpi">
        <div class="lbl">🏦 {{ __('field.sales_kpi_other_coll') }}</div>
        <div class="val">{{ $fmt($kpi['coll_other']) }}</div>
        <div class="sub2">{{ __('field.sales_kpi_other_coll_hint') }}</div>
    </div>
    <div class="kpi"><div class="lbl">📥 {{ __('field.sales_kpi_refunds') }}</div><div class="val" style="color:#B00020">{{ $fmt($kpi['refunds']) }}</div></div>
</div>

<div class="card">
    <h3>💵 {{ __('nav.rep_sales') }}
        <span class="side">{{ __('field.sales_sub') }}</span>
    </h3>

    <form class="searchbar" method="GET">
        <label class="f">{{ __('common.from') }}</label>
        <input type="date" name="from" value="{{ $from }}">
        <label class="f">{{ __('common.to') }}</label>
        <input type="date" name="to" value="{{ $to }}">
        <button class="btn gold" type="submit">🔍 {{ __('common.filter') }}</button>
        <a class="btn" href="{{ route('ops.sales') }}">{{ __('common.clear') }}</a>
    </form>

    <div class="tablewrap">
    <table>
        <thead>
            <tr>
                <th style="text-align:start">{{ __('hr.employee') }}</th>
                <th>{{ __('field.sales_invoices') }}</th>
                <th>{{ __('field.sales_cash') }}</th>
                <th>{{ __('field.sales_credit') }}</th>
                <th>{{ __('field.sales_coll_cash') }}</th>
                <th>{{ __('field.sales_coll_transfer') }}</th>
                <th>{{ __('field.sales_coll_cheque_card') }}</th>
                <th>{{ __('field.sales_refunds') }}</th>
                <th title="{{ __('field.sales_net_hint') }}">{{ __('field.sales_net') }}</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $r)
                @php $u = $r['user']; @endphp
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
                    <td class="num">{{ $r['inv_count'] > 0 ? number_format($r['inv_count']) : '—' }}</td>
                    <td class="num">
                        @if ($r['cash'] > 0)
                            <b style="color:#16A34A">{{ $fmt($r['cash']) }}</b>
                            @if ($r['po_cash'] > 0)
                                <div style="font-size:10px;color:var(--muted)">{{ __('field.sales_incl_pos', ['v' => $fmt($r['po_cash'])]) }}</div>
                            @endif
                        @else — @endif
                    </td>
                    <td class="num">
                        @if ($r['credit'] > 0)
                            <b>{{ $fmt($r['credit']) }}</b>
                            @if ($r['po_credit'] > 0)
                                <div style="font-size:10px;color:var(--muted)">{{ __('field.sales_incl_pos', ['v' => $fmt($r['po_credit'])]) }}</div>
                            @endif
                        @else — @endif
                    </td>
                    <td class="num">{{ $r['coll_cash'] > 0 ? $fmt($r['coll_cash']) : '—' }}</td>
                    <td class="num">{{ $r['coll_transfer'] > 0 ? $fmt($r['coll_transfer']) : '—' }}</td>
                    <td class="num">{{ $r['coll_cheque_card'] > 0 ? $fmt($r['coll_cheque_card']) : '—' }}</td>
                    <td class="num">{{ $r['refunds'] > 0 ? $fmt($r['refunds']) : '—' }}</td>
                    <td class="num" style="font-weight:800;color:{{ $r['net'] >= 0 ? 'var(--royal-blue, #12399B)' : '#B00020' }}">
                        {{ $fmt($r['net']) }}
                    </td>
                    <td style="white-space:nowrap">
                        {{-- تفاصيل التصفية — للحسابات والأدمن، نفس حارس الراوت --}}
                        @if (\App\Support\Access::allows(auth()->user(), 'erp.repclose.show'))
                            <a class="btn sm" href="{{ route('erp.repclose.show', $u) }}">👁 {{ __('common.details') }}</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="10"><div class="empty">{{ __('common.no_results') }}</div></td></tr>
            @endforelse
        </tbody>
        {{-- الفوتر متحسب في السيرفر من كل الصفوف — أدوات الجدول العامة مابتلمسوش --}}
        <tfoot>
            <tr>
                <td style="text-align:start">Σ {{ __('common.total') }}</td>
                <td class="num">{{ number_format($rows->sum('inv_count')) }}</td>
                <td class="num">{{ $fmt($kpi['cash']) }}</td>
                <td class="num">{{ $fmt($kpi['credit']) }}</td>
                <td class="num">{{ $fmt($kpi['coll_cash']) }}</td>
                <td class="num">{{ $fmt($rows->sum('coll_transfer')) }}</td>
                <td class="num">{{ $fmt($rows->sum('coll_cheque_card')) }}</td>
                <td class="num">{{ $fmt($kpi['refunds']) }}</td>
                <td class="num">{{ $fmt($rows->sum('net')) }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>
    </div>
</div>

@endsection
