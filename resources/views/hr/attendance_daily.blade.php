@extends('layouts.system')

{{--
    حضور اليوم (٧/١١/٢٠٢٦): كل موظف مطلوب حضوره — حضور · انصراف · ساعات · تأخير ·
    انصراف بدري · غياب · إجازة · المصدر (المكنة / الأبلكيشن / ...).
    ⚠️ الحسبة في `App\Services\Hr\AttendanceSheet` — نفس قواعد المرتبات.
--}}

@section('title', __('fp.daily_title'))

@php
    $badge = ['present' => 'b-green', 'late' => 'b-orange', 'absent' => 'b-red', 'leave' => 'b-blue',
        'weekend' => 'b-gray', 'future' => 'b-gray', 'not_yet' => 'b-gray'];
    $srcBadge = ['device' => 'b-purple', 'app' => 'b-blue', 'dashboard' => 'b-green', 'supervisor' => 'b-orange'];
    $s = \App\Services\Hr\HrSettings::class;
@endphp

@section('actions')
    <a class="btn sm green" href="{{ route('hr.attendance.daily', array_filter(['date' => $date->toDateString(), 'status' => $status, 'export' => 1])) }}">⬇ {{ __('ui.export_all') }}</a>
@endsection

@section('content')

<div class="kpis">
    <a class="kpi" href="{{ route('hr.attendance.daily', ['date' => $date->toDateString()]) }}"><div class="lbl">{{ __('fp.all') }}</div><div class="val">{{ $total }}</div></a>
    @foreach (['present', 'late', 'absent', 'leave', 'not_yet'] as $st)
        <a class="kpi" href="{{ route('hr.attendance.daily', ['date' => $date->toDateString(), 'status' => $status === $st ? null : $st]) }}">
            <div class="lbl">{{ __('fp.st_'.$st) }}</div>
            <div class="val {{ $st === 'absent' ? 'neg' : ($st === 'late' ? 'mid' : ($st === 'present' ? 'pos' : '')) }}">{{ (int) ($counts[$st] ?? 0) }}</div></a>
    @endforeach
</div>

<div class="card">
    <h3>🗓️ {{ __('fp.daily_title') }} — {{ $date->translatedFormat('l j F Y') }}
        <span class="side">{{ __('fp.shift_note', ['start' => $s::get('hr_shift_start'), 'grace' => (int) $s::num('hr_late_grace_minutes'), 'hours' => round($s::num('hr_day_minutes') / 60, 1)]) }}</span></h3>

    <form method="GET" class="searchbar" data-noprint>
        <a class="btn" href="{{ route('hr.attendance.daily', ['date' => $date->copy()->subDay()->toDateString()]) }}">‹</a>
        <label class="fl"><span>{{ __('hr.date') }}</span><input type="date" name="date" value="{{ $date->toDateString() }}" onchange="this.form.submit()"></label>
        <a class="btn" href="{{ route('hr.attendance.daily', ['date' => $date->copy()->addDay()->toDateString()]) }}">›</a>
        <a class="btn" href="{{ route('hr.attendance.daily') }}">{{ __('fp.daily_title') }}</a>
        @if (\App\Support\Access::allows(auth()->user(), 'hr.attendance.monthly'))
            <a class="btn" href="{{ route('hr.attendance.monthly', ['month' => $date->format('Y-m')]) }}">📆 {{ __('fp.nav_monthly') }}</a>
        @endif
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('common.code') }}</th>
                <th style="text-align:start">{{ __('hr.employee') }}</th>
                <th>{{ __('fp.check_in') }}</th>
                <th>{{ __('fp.check_out') }}</th>
                <th>{{ __('fp.worked') }}</th>
                <th>{{ __('fp.late_min') }}</th>
                <th>{{ __('fp.early_min') }}</th>
                <th>{{ __('fp.status') }}</th>
                <th>{{ __('fp.sources') }}</th>
            </tr>
            @foreach ($rows as $r)
                <tr>
                    <td class="num">{{ $r['e']->code }}</td>
                    <td style="text-align:start">
                        @if (\App\Support\Access::allows(auth()->user(), 'hr.attendance.monthly'))
                            <a href="{{ route('hr.attendance.monthly', ['month' => $date->format('Y-m'), 'employee_id' => $r['e']->id]) }}">{{ $r['e']->displayName() }}</a>
                        @else {{ $r['e']->displayName() }} @endif
                    </td>
                    <td class="num" dir="ltr">{{ $r['in']?->format('H:i') ?? '—' }}</td>
                    <td class="num" dir="ltr">{{ $r['out']?->format('H:i') ?? '—' }}</td>
                    <td class="num">{{ \App\Models\AttendanceDay::hhmm($r['worked']) }}</td>
                    <td class="num {{ $r['late'] > 0 ? 'mid' : 'muted' }}">{{ $r['late'] ?: '—' }}</td>
                    <td class="num {{ $r['early'] > 0 ? 'mid' : 'muted' }}">{{ $r['early'] ?: '—' }}</td>
                    <td>
                        <span class="badge {{ $badge[$r['status']] ?? 'b-gray' }}">{{ __('fp.st_'.$r['status']) }}</span>
                        @if ($r['leave'])<span class="muted" style="font-size:11px">{{ $r['leave']->typeLabel() }}</span>@endif
                        @if ($r['perm_minutes'] > 0)<span class="badge b-gold">{{ __('fp.perm_minutes_short', ['min' => $r['perm_minutes']]) }}</span>@endif
                    </td>
                    <td>
                        @foreach ($r['sources'] as $src)
                            <span class="badge {{ $srcBadge[$src] ?? 'b-gray' }}">{{ __('hrm.source_'.$src) }}</span>
                        @endforeach
                    </td>
                </tr>
            @endforeach
        </table>
    </div>
</div>
@endsection
