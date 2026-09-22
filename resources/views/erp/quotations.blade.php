@extends('layouts.system')

@section('title', __('rpt.qts_title'))

@section('actions')
    <a class="btn" href="{{ route('erp.reports.hub') }}">← {{ __('rpt.hub_title') }}</a>
    <a class="btn gold" href="{{ route('erp.reports.quotation') }}">➕ {{ __('rpt.qts_new') }}</a>
@endsection

@section('content')

{{-- ═══ الفلاتر ═══ --}}
<div class="card" style="padding:12px 14px">
    <form class="searchbar" method="GET" action="{{ route('erp.reports.quotations') }}" style="margin-bottom:0">
        @if ($creators->isNotEmpty())
            <label class="fl"><span>{{ __('rpt.qts_creator') }}</span>
                <select name="creator_id">
                    <option value="">{{ __('ui.all_of', ['x' => __('uib.users')]) }}</option>
                    @foreach ($creators as $u)
                        <option value="{{ $u->id }}" @selected(request('creator_id') == $u->id)>{{ $u->displayName() }}</option>
                    @endforeach
                </select></label>
        @endif
        <label class="fl wide grow"><span>{{ __('ui.l_search') }}</span>
            <input type="search" name="q" value="{{ request('q') }}" dir="auto"
                   placeholder="{{ __('rpt.qts_search_ph') }}"></label>
        {{-- ⚠️ `all => false`: الفترة الفاضية هنا = الشهر الحالي (`ReportController::range`) --}}
        @include('partials._range', ['from' => $periodFrom, 'to' => $periodTo, 'all' => false])
        <button class="btn gold" type="submit">{{ __('common.filter') }}</button>
        <a class="btn" href="{{ route('erp.reports.quotations') }}">{{ __('common.clear') }}</a>
    </form>
</div>

{{-- ═══ السامري ═══ --}}
<div class="kpis">
    {{-- (٢٢/٩) العدد والقيمة هما الجدول اللي تحت، و«الشهر ده» فلتر على الشهر الحالي --}}
    @php $mFrom = today()->startOfMonth()->toDateString(); $mTo = today()->toDateString(); @endphp
    <a class="kpi" href="#qt-list"><div class="lbl">{{ __('rpt.qts_count') }}</div><div class="val">{{ $kCount }}</div>
        {{-- (٢٢/٩) الأجزاء من صفوف الجدول نفسها — بتتكتب بس لو الجدول شايل كل النتيجة (مش مقصوص بالحد الأقصى) --}}
        @php $qAll = number_format($rows->count()) === $kCount; $qExp = $rows->filter(fn ($r) => $r->valid_until?->isPast())->count(); @endphp
        @if ($qAll)<div class="sub2">@include('erp._eq', ['total' => $rows->count(), 'dec' => 0, 'zeros' => true, 'parts' => [[__('uib.qt_valid'), $rows->count() - $qExp], [__('rpt.qts_expired'), $qExp]]])</div>@endif
        <div class="sub2">{{ __('uib.qt_count_sub') }}</div></a>
    <a class="kpi" style="grid-column:span 2" href="#qt-list"><div class="lbl">{{ __('rpt.qts_value') }}</div><div class="val pos">{{ $kValue }}</div>
        @if ($qAll)<div class="sub2">@include('erp._eq', ['total' => $rows->sum('grand'), 'zeros' => true, 'parts' => [[__('rpt.qt_subtotal'), $rows->sum('subtotal')], [__('rpt.qt_disc'), $rows->sum('discount'), '-'], [__('uib.dc_vat'), $rows->sum('tax')]]])</div>@endif</a>
    <a @class(['kpi', 'on' => $periodFrom === $mFrom && $periodTo === $mTo && ! request('q') && ! request('creator_id')])
       href="{{ route('erp.reports.quotations', ['from' => $mFrom, 'to' => $mTo]) }}"><div class="lbl">{{ __('rpt.qts_month') }}</div><div class="val mid">{{ $kMonth }}</div><div class="sub2">{{ __('uib.qt_month_sub', ['from' => $mFrom]) }}</div></a>
</div>

{{-- ═══ الليستة ═══ --}}
<div class="card" id="qt-list">
    <h3>📄 {{ __('rpt.qts_title') }} <span class="side">{{ __('rpt.rows_n', ['n' => number_format($rows->count())]) }}</span></h3>

    <div class="tablewrap rpt-wrap">
        <table>
            <thead>
            <tr>
                <th>{{ __('rpt.c_date') }}</th>
                <th>{{ __('rpt.c_number') }}</th>
                <th style="text-align:start">{{ __('rpt.qt_to') }}</th>
                <th>{{ __('rpt.qts_creator') }}</th>
                <th class="num" data-nosum>{{ __('rpt.qts_items') }}</th>
                <th class="num">{{ __('rpt.qt_disc') }}</th>
                <th class="num">{{ __('rpt.qt_grand') }}</th>
                <th data-nosum>{{ __('rpt.qt_valid_until') }}</th>
                <th class="act"></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($rows as $qt)
                <tr>
                    <td class="num" dir="ltr">{{ $qt->created_at->format('Y-m-d') }}</td>
                    <td class="num" dir="ltr"><a href="{{ route('erp.reports.quotations.show', $qt) }}"><b>{{ $qt->number }}</b></a></td>
                    <td style="text-align:start">
                        @if ($qt->client_id ?? null)<a href="{{ route('erp.clients.show', $qt->client_id) }}"><b>{{ $qt->client_name }}</b></a>@else<b>{{ $qt->client_name }}</b>@endif
                    </td>
                    <td>{{ $qt->creator?->displayName() ?? '—' }}</td>
                    <td class="num">{{ $qt->items->count() }}
                        <div style="font-size:10px;color:var(--muted)">{{ number_format($qt->items->sum('qty')) }} {{ __('rpt.k_qty') }}</div>
                    </td>
                    <td class="num" dir="ltr">{{ $qt->discount > 0 ? number_format($qt->discount, 2) : '—' }}</td>
                    <td class="num pos" dir="ltr"><b>{{ number_format($qt->grand, 2) }}</b></td>
                    <td class="num" dir="ltr">
                        {{ $qt->valid_until->format('Y-m-d') }}
                        @if ($qt->valid_until->isPast())
                            <span class="badge b-gray" style="font-size:9.5px">{{ __('rpt.qts_expired') }}</span>
                        @else
                            <span class="badge b-green" style="font-size:9.5px">{{ __('rpt.qts_active') }}</span>
                        @endif
                    </td>
                    <td class="act">
                        <a class="btn sm" href="{{ route('erp.reports.quotations.show', $qt) }}">🖨️ {{ __('rpt.qts_open') }}</a>
                        <a class="btn sm" href="{{ route('erp.reports.quotations.edit', $qt) }}">✏️ {{ __('rpt.qt_edit') }}</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" style="text-align:center;color:var(--muted);padding:26px">{{ __('rpt.no_rows') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@endsection

@section('scripts')
<style>
.rpt-wrap{max-height:68vh;overflow:auto}
.rpt-wrap thead th{position:sticky;top:0;z-index:3;background:var(--royal-blue);color:#fff}
</style>
@endsection
