@extends('layouts.system')

@section('title', __('rpt.qts_title'))

@section('actions')
    <a class="btn" href="{{ route('erp.reports.hub') }}">← {{ __('rpt.hub_title') }}</a>
    <a class="btn gold" href="{{ route('erp.reports.quotation') }}">➕ {{ __('rpt.qts_new') }}</a>
@endsection

@section('content')

{{-- ═══ الفلاتر ═══ --}}
<div class="card" style="padding:12px 14px">
    <form method="GET" action="{{ route('erp.reports.quotations') }}"
          style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
        <div>
            <label class="f">{{ __('rpt.f_from') }}</label>
            <input type="date" name="from" value="{{ request('from', today()->startOfMonth()->toDateString()) }}">
        </div>
        <div>
            <label class="f">{{ __('rpt.f_to') }}</label>
            <input type="date" name="to" value="{{ request('to', today()->toDateString()) }}">
        </div>
        @if ($creators->isNotEmpty())
            <div style="min-width:180px">
                <label class="f">{{ __('rpt.qts_creator') }}</label>
                <select name="creator_id">
                    <option value="">{{ __('rpt.f_all') }}</option>
                    @foreach ($creators as $u)
                        <option value="{{ $u->id }}" @selected(request('creator_id') == $u->id)>{{ $u->displayName() }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div style="flex:1;min-width:180px">
            <label class="f">{{ __('common.search') }}</label>
            <input type="search" name="q" value="{{ request('q') }}" style="width:100%"
                   placeholder="{{ __('rpt.qts_search_ph') }}">
        </div>
        <button class="btn gold" type="submit">🔍 {{ __('rpt.apply') }}</button>
    </form>
</div>

{{-- ═══ السامري ═══ --}}
<div class="kpis">
    <div class="kpi"><div class="lbl">{{ __('rpt.qts_count') }}</div><div class="val">{{ $kCount }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('rpt.qts_value') }}</div><div class="val pos">{{ $kValue }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('rpt.qts_month') }}</div><div class="val mid">{{ $kMonth }}</div></div>
</div>

{{-- ═══ الليستة ═══ --}}
<div class="card">
    <h3>📄 {{ __('rpt.qts_title') }} <span class="side">{{ __('rpt.rows_n', ['n' => number_format($rows->count())]) }}</span></h3>

    <div class="tablewrap rpt-wrap">
        <table>
            <thead>
            <tr>
                <th>{{ __('rpt.c_date') }}</th>
                <th>{{ __('rpt.c_number') }}</th>
                <th style="text-align:start">{{ __('rpt.qt_to') }}</th>
                <th>{{ __('rpt.qts_creator') }}</th>
                <th class="num">{{ __('rpt.qts_items') }}</th>
                <th class="num">{{ __('rpt.qt_disc') }}</th>
                <th class="num">{{ __('rpt.qt_grand') }}</th>
                <th>{{ __('rpt.qt_valid_until') }}</th>
                <th class="act"></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($rows as $qt)
                <tr>
                    <td class="num" dir="ltr">{{ $qt->created_at->format('Y-m-d') }}</td>
                    <td class="num" dir="ltr"><b>{{ $qt->number }}</b></td>
                    <td style="text-align:start"><b>{{ $qt->client_name }}</b></td>
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
