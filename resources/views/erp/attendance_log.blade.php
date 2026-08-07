@extends('layouts.system')

{{-- سجل الحضور — فترة وفلاتر وإجماليات (2026-08-08) --}}

@section('title', __('hr.log'))

@section('actions')
    <a class="btn" href="{{ route('erp.attendance') }}">📊 {{ __('hr.today_board') }}</a>
    <a class="btn {{ $needsReview ? 'red' : '' }}" href="{{ route('erp.attendance.review') }}">
        ⏰ {{ __('hr.review') }}@if ($needsReview) ({{ $needsReview }})@endif
    </a>
@endsection

@section('content')

<form method="GET" class="filters" style="margin-bottom:12px">
    <div>
        <label class="f">{{ __('hr.from') }}</label>
        <input type="date" name="from" value="{{ $from }}">
    </div>
    <div>
        <label class="f">{{ __('hr.to') }}</label>
        <input type="date" name="to" value="{{ $to }}">
    </div>
    <div>
        <label class="f">{{ __('hr.employee') }}</label>
        <select name="user">
            <option value="">{{ __('common.all') }}</option>
            @foreach ($users as $u)
                <option value="{{ $u->id }}" @selected(request('user') == $u->id)>{{ $u->displayName() }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="f">{{ __('hr.state') }}</label>
        <select name="status">
            <option value="">{{ __('common.all') }}</option>
            <option value="open" @selected(request('status') === 'open')>{{ __('hr.status_open') }}</option>
            <option value="closed" @selected(request('status') === 'closed')>{{ __('hr.status_closed') }}</option>
            <option value="auto" @selected(request('status') === 'auto')>{{ __('hr.status_auto') }}</option>
        </select>
    </div>
    <button class="btn primary" type="submit">{{ __('common.search') }}</button>
</form>

<div class="kpis" style="margin-bottom:14px">
    <div class="kpi"><div class="lbl">{{ __('hr.log') }}</div><div class="val">{{ $rows->count() }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('hr.total_hours') }}</div><div class="val" dir="ltr">{{ \App\Models\AttendanceDay::hhmm($totalMinutes) }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('hr.avg_hours') }}</div><div class="val" dir="ltr">{{ \App\Models\AttendanceDay::hhmm($avgMinutes) }}</div></div>
</div>

<div class="card">
    @if ($rows->isEmpty())
        <div class="empty">{{ __('hr.no_rows') }}</div>
    @else
        <div class="tablewrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('hr.date') }}</th>
                    <th>{{ __('hr.employee') }}</th>
                    <th>{{ __('hr.first_in') }}</th>
                    <th>{{ __('hr.last_out') }}</th>
                    <th>{{ __('hr.worked') }}</th>
                    <th>{{ __('hr.breaks') }}</th>
                    <th>{{ __('hr.sessions') }}</th>
                    <th>{{ __('hr.state') }}</th>
                    <th>{{ __('hr.approved') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $d)
                    <tr>
                        <td dir="ltr">{{ $d->date->format('Y-m-d') }}</td>
                        <td>{{ $d->user?->displayName() ?? '—' }}</td>
                        <td dir="ltr">{{ $d->first_in_at?->format('h:i A') ?? '—' }}</td>
                        <td dir="ltr">{{ $d->last_out_at?->format('h:i A') ?? '—' }}</td>
                        <td dir="ltr" style="font-weight:800">{{ $d->workedLabel() }}</td>
                        <td dir="ltr">{{ \App\Models\AttendanceDay::hhmm($d->break_minutes) }}</td>
                        <td>{{ $d->sessions }}</td>
                        <td>
                            @if ($d->status === \App\Models\AttendanceDay::STATUS_AUTO)
                                <span class="pill red">{{ __('hr.status_auto') }}</span>
                            @elseif ($d->status === \App\Models\AttendanceDay::STATUS_OPEN)
                                <span class="pill warn">{{ __('hr.status_open') }}</span>
                            @else
                                <span class="pill good">{{ __('hr.status_closed') }}</span>
                            @endif
                        </td>
                        <td>
                            @if ($d->approved_at)
                                {{-- ⚠️ المعتمد بيتعرض حتى لو مساوي للمحسوب —
                                     «مين شاف الرقم ده» سؤال بيتسأل في المرتبات --}}
                                <span class="pill good" dir="ltr">
                                    {{ \App\Models\AttendanceDay::hhmm($d->payableMinutes()) }}
                                </span>
                                <div class="side" style="font-size:10.5px">
                                    {{ __('hr.approved_by', ['name' => $d->approver?->displayName() ?? '—']) }}
                                </div>
                            @else
                                <span class="side">{{ __('hr.not_approved') }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    @endif
</div>

@endsection
