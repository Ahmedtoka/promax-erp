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

<form method="GET" class="searchbar" style="margin-bottom:12px">
    <label class="fl wide"><span>{{ __('hr.employee') }}</span>
        <select name="user">
            <option value="">{{ __('ui.all_of', ['x' => __('uib.employees')]) }}</option>
            @foreach ($users as $u)
                <option value="{{ $u->id }}" @selected(request('user') == $u->id)>{{ $u->displayName() }}</option>
            @endforeach
        </select></label>
    <label class="fl"><span>{{ __('ui.l_status') }}</span>
        <select name="status">
            <option value="">{{ __('ui.all_of', ['x' => __('uib.statuses')]) }}</option>
            <option value="open" @selected(request('status') === 'open')>{{ __('hr.status_open') }}</option>
            <option value="closed" @selected(request('status') === 'closed')>{{ __('hr.status_closed') }}</option>
            <option value="auto" @selected(request('status') === 'auto')>{{ __('hr.status_auto') }}</option>
        </select></label>
    {{-- ⚠️ `all => false`: الفترة الفاضية هنا = الشهر الحالي (`AttendanceController::log`) --}}
    @include('partials._range', ['from' => $from, 'to' => $to, 'all' => false])
    <button class="btn gold" type="submit">{{ __('common.filter') }}</button>
    <a class="btn" href="{{ route('erp.attendance.log') }}">{{ __('common.clear') }}</a>
    {{-- ⚠️ التصدير بنفس فلاتر الفورم بالظبط: موظف واحد أو «الكل» —
         الزرار بيبعت لراوت التصدير بنفس الحقول (formaction). --}}
    <button class="btn" type="submit"
            formaction="{{ route('erp.attendance.export') }}">📥 {{ __('hr.export_excel') }}</button>
</form>

{{-- (٢٢/٩) الكروت بتفسّر رقمها: الساعات مفرودة بالموظف (مجموعها = إجمالي الساعات) --}}
@php $byEmp = $rows->groupBy('user_id')->map(fn ($g) => [
    'user' => $g->first()->user, 'days' => $g->count(), 'min' => $g->sum(fn ($d) => $d->payableMinutes()),
])->sortByDesc('min'); @endphp
<div class="kpis" style="margin-bottom:14px">
    <a class="kpi" href="#att-table"><div class="lbl">{{ __('hr.log') }}</div><div class="val">{{ $rows->count() }}</div></a>
    <div class="kpi" data-explain onclick="openDlg('attExplain')" title="{{ __('ui.click_to_explain') }}"><div class="lbl">{{ __('hr.total_hours') }}</div><div class="val" dir="ltr">{{ \App\Models\AttendanceDay::hhmm($totalMinutes) }}</div></div>
    <div class="kpi" data-explain onclick="openDlg('attExplain')" title="{{ __('ui.click_to_explain') }}"><div class="lbl">{{ __('hr.avg_hours') }}</div><div class="val" dir="ltr">{{ \App\Models\AttendanceDay::hhmm($avgMinutes) }}</div></div>
</div>

<dialog id="attExplain" style="max-width:560px">
    <h3>{{ __('hr.total_hours') }} <span class="side">{{ __('ui.explain') }} · <span dir="ltr">{{ $from }} → {{ $to }}</span></span></h3>
    <div class="tablewrap" style="max-height:60vh;overflow:auto">
        <table data-noxl>
            <thead><tr>
                <th style="text-align:start">{{ __('hr.employee') }}</th>
                <th>{{ __('uib.days') }}</th>
                <th>{{ __('hr.total_hours') }}</th>
                <th>{{ __('hr.avg_hours') }}</th>
            </tr></thead>
            <tbody>
            @foreach ($byEmp as $uid => $e)
                <tr>
                    <td style="text-align:start"><a href="{{ request()->fullUrlWithQuery(['user' => $uid]) }}">{{ $e['user']?->displayName() ?? '—' }}</a></td>
                    <td class="num">{{ $e['days'] }}</td>
                    <td class="num" dir="ltr">{{ \App\Models\AttendanceDay::hhmm($e['min']) }}</td>
                    <td class="num" dir="ltr">{{ \App\Models\AttendanceDay::hhmm((int) round($e['min'] / max(1, $e['days']))) }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot><tr>
                <td style="text-align:start">{{ __('common.total') }}</td>
                <td class="num">{{ $rows->count() }}</td>
                <td class="num" dir="ltr">{{ \App\Models\AttendanceDay::hhmm($totalMinutes) }}</td>
                <td class="num" dir="ltr">{{ \App\Models\AttendanceDay::hhmm($avgMinutes) }}</td>
            </tr></tfoot>
        </table>
    </div>
    <div style="text-align:end;margin-top:10px"><button class="btn" type="button" onclick="closeDlg('attExplain')">{{ __('common.close') }}</button></div>
</dialog>

<div class="card" id="att-table">
    <h3>🗓️ {{ __('hr.log') }} <span class="side">{{ __('ui.rows_n', ['n' => number_format($rows->count())]) }}</span></h3>
    @if ($rows->isEmpty())
        <div class="empty">{{ __('hr.no_rows') }}</div>
    @else
        <div class="tablewrap">
        <table class="att-tbl">
            <thead>
                <tr>
                    <th>{{ __('hr.date') }}</th>
                    <th>{{ __('hr.employee') }}</th>
                    <th>{{ __('hr.first_in') }}</th>
                    <th>{{ __('hr.last_out') }}</th>
                    <th>{{ __('hr.worked') }}</th>
                    <th>{{ __('hr.breaks') }}</th>
                    <th data-nosum>{{ __('hr.sessions') }}</th>
                    <th>{{ __('hr.state') }}</th>
                    <th>{{ __('hr.approved') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $d)
                    <tr>
                        <td dir="ltr">{{ $d->date->format('Y-m-d') }}</td>
                        {{-- الموظف بيفلتر السجل عليه --}}
                        <td>@if ($d->user)<a href="{{ request()->fullUrlWithQuery(['user' => $d->user_id]) }}">{{ $d->user->displayName() }}</a>@else — @endif</td>
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
                                {{-- الملاحظة بتوثّق الانصراف الإداري — «انصراف إداري بواسطة فلان» --}}
                                @if ($d->note)
                                    <div class="side" style="font-size:10px">{{ $d->note }}</div>
                                @endif
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
