@extends('layouts.system')

{{--
    تقرير البانشات (Lane 7 — المهمة ٢): سطر لكل بانش — المصدر (أبلكيشن /
    داش بورد / مشرف)، مين سجّله، المكان، الجهاز والـIP.
    ⚠️ الصفوف جاهزة من الكنترولر (`$lines`) — نفس أعمدة التصدير بالظبط.
--}}

@section('title', __('hrm.punches'))

@php
    $exportQuery = array_merge(request()->only(['employee_id', 'source']), $range->query(), ['export' => 1]);
    $srcBadge = ['app' => 'b-blue', 'dashboard' => 'b-green', 'supervisor' => 'b-orange'];
@endphp

@section('actions')
    <a class="btn sm green" href="{{ route('hr.attendance.punches', $exportQuery) }}">⬇ {{ __('ui.export_all') }}</a>
@endsection

@section('content')

<div class="kpis">
    <div class="kpi"><div class="lbl">{{ __('hrm.punches') }}</div><div class="val">{{ number_format($total) }}</div></div>
    @foreach (\App\Models\AttendancePunch::SOURCES as $src)
        <a class="kpi" href="{{ request()->fullUrlWithQuery(['source' => request('source') === $src ? null : $src, 'page' => null]) }}#punch-table">
            <div class="lbl">{{ __('hrm.source_'.$src) }}</div><div class="val">{{ number_format((int) ($bySource[$src] ?? 0)) }}</div></a>
    @endforeach
</div>

<div class="card" id="punch-table">
    <h3>📍 {{ __('hrm.punches') }} <span class="side">{{ __('hrm.punches_sub') }}</span></h3>

    <form method="GET" class="searchbar" data-noprint>
        <label class="fl wide"><span>{{ __('hr.employee') }}</span>
            <select name="employee_id" onchange="this.form.submit()">
                <option value="">{{ __('hrm.all_states') }}</option>
                @foreach ($employees as $emp)
                    <option value="{{ $emp->id }}" @selected((string) request('employee_id') === (string) $emp->id)>{{ $emp->code }} · {{ $emp->displayName() }}</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('hrm.source') }}</span>
            <select name="source" onchange="this.form.submit()">
                <option value="">{{ __('hrm.all_states') }}</option>
                @foreach (\App\Models\AttendancePunch::SOURCES as $src)
                    <option value="{{ $src }}" @selected(request('source') === $src)>{{ __('hrm.source_'.$src) }}</option>
                @endforeach
            </select></label>
        {{-- الفترة الفاضية = الشهر الحالي --}}
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue(), 'all' => false])
        <button class="btn green" type="submit">{{ __('common.filter') }}</button>
        <a class="btn" href="{{ route('hr.attendance.punches') }}">{{ __('common.clear') }}</a>
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('hr.date') }}</th>
                <th>{{ __('hrm.time') }}</th>
                <th style="text-align:start">{{ __('hr.employee') }}</th>
                <th>{{ __('hrm.punch_type') }}</th>
                <th>{{ __('hrm.source') }}</th>
                <th>{{ __('hrm.recorded_by') }}</th>
                <th>{{ __('hrm.location') }}</th>
                <th style="text-align:start">{{ __('hrm.device') }}</th>
                <th>{{ __('hrm.ip') }}</th>
            </tr>
            @forelse ($rows as $p)
                @php $l = $lines[$p->id]; @endphp
                <tr>
                    <td class="num" style="font-size:11px">{{ $l[0] }}</td>
                    <td class="num" dir="ltr" style="font-size:11px">{{ $l[1] }}</td>
                    <td style="text-align:start">{{ $l[2] }}</td>
                    <td><span style="color:{{ $p->color() }};font-weight:700">{{ $p->icon() }} {{ $l[3] }}</span>
                        @if ($p->auto)<span class="badge b-gray">{{ __('hr.status_auto') }}</span>@endif</td>
                    <td><span class="badge {{ $srcBadge[$p->source] ?? 'b-gray' }}">{{ $l[4] }}</span></td>
                    <td>{{ $l[5] }}</td>
                    <td>
                        @if ($l[6] !== '—')
                            <a class="btn sm" href="{{ $l[6] }}" target="_blank" rel="noopener">🗺️ {{ __('hrm.map') }}</a>
                        @else — @endif
                    </td>
                    <td style="text-align:start;font-size:10.5px;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="{{ $l[7] }}">{{ $l[7] }}</td>
                    <td class="num" dir="ltr" style="font-size:11px">{{ $l[8] }}</td>
                </tr>
            @empty
                <tr><td colspan="9" style="color:var(--muted);font-size:12px">{{ __('hrm.no_punches') }}</td></tr>
            @endforelse
        </table>
    </div>

    @include('partials._pagination', ['p' => $rows])
</div>

@endsection
