@extends('layouts.system')

{{--
    كشف حضور الشهر (٧/١١/٢٠٢٦): ملخص لكل موظف (أيام حضور/تأخير/غياب/إجازة/ساعات)،
    أو موظف واحد يوم بيوم. ⚠️ الحسبة في `AttendanceSheet` — نفس قواعد المرتبات.
--}}

@section('title', __('fp.monthly_title'))

@php
    $badge = ['present' => 'b-green', 'late' => 'b-orange', 'absent' => 'b-red', 'leave' => 'b-blue',
        'weekend' => 'b-gray', 'future' => 'b-gray', 'not_yet' => 'b-gray'];
    $q = array_filter(['month' => $month->format('Y-m'), 'employee_id' => $one?->id]);
@endphp

@section('actions')
    <a class="btn sm green" href="{{ route('hr.attendance.monthly', $q + ['export' => 1]) }}">⬇ {{ __('ui.export_all') }}</a>
@endsection

@section('content')

<div class="card">
    <h3>📆 {{ __('fp.monthly_title') }} — {{ $month->translatedFormat('F Y') }}@if ($one) — {{ $one->displayName() }}@endif</h3>

    <form method="GET" class="searchbar" data-noprint>
        <label class="fl"><span>{{ __('fp.month') }}</span><input type="month" name="month" value="{{ $month->format('Y-m') }}" onchange="this.form.submit()"></label>
        <label class="fl wide"><span>{{ __('fp.one_employee') }}</span>
            <select name="employee_id" onchange="this.form.submit()">
                <option value="">{{ __('fp.all') }}</option>
                @foreach ($employees as $e)
                    <option value="{{ $e->id }}" @selected($one?->id === $e->id)>{{ $e->code }} · {{ $e->displayName() }}</option>
                @endforeach
            </select></label>
        <button class="btn green" type="submit">{{ __('common.filter') }}</button>
    </form>

    @if ($one)
        <div class="kpis">
            @foreach (['present' => 'present_days', 'late' => 'late_days', 'absent' => 'absent_days', 'leave' => 'leave_days', 'late_minutes' => 'late_min'] as $k => $lbl)
                <div class="kpi"><div class="lbl">{{ __('fp.'.$lbl) }}</div><div class="val">{{ $totals[$k] }}</div></div>
            @endforeach
            <div class="kpi"><div class="lbl">{{ __('fp.worked') }}</div><div class="val">{{ \App\Models\AttendanceDay::hhmm($totals['worked']) }}</div></div>
        </div>

        <div class="tablewrap"><table>
            <tr><th>{{ __('hr.date') }}</th><th>{{ __('fp.check_in') }}</th><th>{{ __('fp.check_out') }}</th><th>{{ __('fp.worked') }}</th>
                <th>{{ __('fp.late_min') }}</th><th>{{ __('fp.early_min') }}</th><th>{{ __('fp.status') }}</th><th>{{ __('fp.sources') }}</th></tr>
            @foreach ($days as $d => $r)
                <tr @if ($r['status'] === 'weekend') style="opacity:.55" @endif>
                    <td class="num" dir="ltr">{{ \Illuminate\Support\Carbon::parse($d)->translatedFormat('D d/m') }}</td>
                    <td class="num" dir="ltr">{{ $r['in']?->format('H:i') ?? '—' }}</td>
                    <td class="num" dir="ltr">{{ $r['out']?->format('H:i') ?? '—' }}</td>
                    <td class="num">{{ \App\Models\AttendanceDay::hhmm($r['worked']) }}</td>
                    <td class="num {{ $r['late'] > 0 ? 'mid' : 'muted' }}">{{ $r['late'] ?: '—' }}</td>
                    <td class="num {{ $r['early'] > 0 ? 'mid' : 'muted' }}">{{ $r['early'] ?: '—' }}</td>
                    <td><span class="badge {{ $badge[$r['status']] ?? 'b-gray' }}">{{ __('fp.st_'.$r['status']) }}</span>
                        @if ($r['leave'])<span class="muted" style="font-size:11px">{{ $r['leave']->typeLabel() }}</span>@endif
                        @if ($r['perm_minutes'] > 0)<span class="badge b-gold">{{ __('fp.perm_minutes_short', ['min' => $r['perm_minutes']]) }}</span>@endif</td>
                    <td>{{ collect($r['sources'])->map(fn ($s) => __('hrm.source_'.$s))->implode(' + ') }}</td>
                </tr>
            @endforeach
        </table></div>
    @else
        <div class="tablewrap"><table>
            <tr><th>{{ __('common.code') }}</th><th style="text-align:start">{{ __('hr.employee') }}</th><th>{{ __('fp.present_days') }}</th>
                <th>{{ __('fp.late_days') }}</th><th>{{ __('fp.late_min') }}</th><th>{{ __('fp.absent_days') }}</th><th>{{ __('fp.leave_days') }}</th>
                <th>{{ __('fp.early_min') }}</th><th>{{ __('fp.worked') }}</th></tr>
            @foreach ($summary as $r)
                <tr>
                    <td class="num">{{ $r['e']->code }}</td>
                    <td style="text-align:start"><a href="{{ route('hr.attendance.monthly', ['month' => $month->format('Y-m'), 'employee_id' => $r['e']->id]) }}">{{ $r['e']->displayName() }}</a></td>
                    <td class="num pos">{{ $r['present'] }}</td>
                    <td class="num {{ $r['late'] ? 'mid' : 'muted' }}">{{ $r['late'] }}</td>
                    <td class="num {{ $r['late_minutes'] ? 'mid' : 'muted' }}">{{ $r['late_minutes'] }}</td>
                    <td class="num {{ $r['absent'] ? 'neg' : 'muted' }}">{{ $r['absent'] }}</td>
                    <td class="num">{{ $r['leave'] }}</td>
                    <td class="num">{{ $r['early_minutes'] }}</td>
                    <td class="num">{{ \App\Models\AttendanceDay::hhmm($r['worked']) }}</td>
                </tr>
            @endforeach
        </table></div>
    @endif
</div>
@endsection
