@extends('layouts.system')

{{--
    بورد الحضور — مين شغال دلوقتي (2026-08-08).

    ⚠️ الصفحة بتبدأ من **كل الموظفين النشطين** مش من جدول الحضور —
    السؤال الأهم هو «مين مش شغال»، واللي مسجّلش حضور مالوش صف أصلاً.
--}}

@section('title', __('hr.attendance'))

@section('actions')
    <a class="btn" href="{{ route('erp.attendance.log') }}">📋 {{ __('hr.log') }}</a>
    <a class="btn {{ $needsReview ? 'red' : '' }}" href="{{ route('erp.attendance.review') }}">
        ⏰ {{ __('hr.review') }}@if ($needsReview) ({{ $needsReview }})@endif
    </a>
@endsection

@section('content')

<form method="GET" class="filters" style="margin-bottom:12px">
    <label class="f">{{ __('hr.date') }}</label>
    <input type="date" name="date" value="{{ $date }}" onchange="this.form.submit()">
</form>

<div class="kpis" style="margin-bottom:14px">
    <div class="kpi"><div class="n" style="color:#16A34A">{{ $working }}</div><div class="l">🟢 {{ __('hr.working_now') }}</div></div>
    <div class="kpi"><div class="n" style="color:#B86E00">{{ $onBreak }}</div><div class="l">⏸️ {{ __('hr.on_break_now') }}</div></div>
    <div class="kpi"><div class="n">{{ $done }}</div><div class="l">✅ {{ __('hr.done_today') }}</div></div>
    <div class="kpi"><div class="n" style="color:#B00020">{{ $notIn }}</div><div class="l">⚪ {{ __('hr.not_in_yet') }}</div></div>
</div>

<div class="card">
    <table>
        <thead>
            <tr>
                <th>{{ __('hr.employee') }}</th>
                <th>{{ __('hr.role') }}</th>
                <th>{{ __('hr.state') }}</th>
                <th>{{ __('hr.first_in') }}</th>
                <th>{{ __('hr.last_out') }}</th>
                <th>{{ __('hr.worked') }}</th>
                <th>{{ __('hr.breaks') }}</th>
                <th>{{ __('hr.sessions') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $r)
                <tr>
                    <td>
                        <strong>{{ $r['user']->displayName() }}</strong>
                        <div class="side" style="font-size:11px" dir="ltr">{{ $r['user']->code }}</div>
                    </td>
                    <td>{{ $r['user']->roleLabel() }}</td>
                    <td>
                        @if ($r['state'] === 'working')
                            <span class="pill good">🟢 {{ __('hr.state_working') }}</span>
                        @elseif ($r['state'] === 'break')
                            <span class="pill warn">⏸️ {{ __('hr.state_break') }}</span>
                        @elseif (($r['day']?->sessions ?? 0) > 0)
                            <span class="pill">✅ {{ __('hr.done_today') }}</span>
                        @else
                            <span class="pill" style="opacity:.6">⚪ {{ __('hr.not_in_yet') }}</span>
                        @endif

                        @if ($r['status'] === \App\Models\AttendanceDay::STATUS_AUTO)
                            <span class="pill red" style="font-size:10px">{{ __('hr.auto_closed_mark') }}</span>
                        @endif
                    </td>
                    <td dir="ltr">{{ $r['in']?->format('h:i A') ?? '—' }}</td>
                    <td dir="ltr">{{ $r['out']?->format('h:i A') ?? '—' }}</td>
                    <td dir="ltr" style="font-weight:800">{{ $r['worked'] }}</td>
                    <td dir="ltr">{{ $r['breaks'] }}</td>
                    <td>{{ $r['day']?->sessions ?? 0 }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

@endsection
