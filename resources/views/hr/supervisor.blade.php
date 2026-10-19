@extends('layouts.system')

{{--
    بصمة المشرف (Lane 7 — المهمة ٢): العمال اللي مالهمش لوجين تحت إيدي
    وحالتهم النهارده. الموظف اللي ليه لوجين بيبصم بنفسه (أبلكيشن/داش بورد).
--}}

@section('title', __('hrm.supervisor'))

@php
    $badge = ['working' => 'b-green', 'break' => 'b-orange', 'off' => 'b-gray'];
    $btn = ['in' => 'green', 'break' => '', 'back' => '', 'out' => 'red'];
    $counts = collect($states)->countBy();
@endphp

@section('actions')
    @if (\App\Support\Access::allows(auth()->user(), 'hr.attendance.punches'))
        <a class="btn" href="{{ route('hr.attendance.punches', ['source' => 'supervisor']) }}">📍 {{ __('hrm.nav_punches') }}</a>
    @endif
@endsection

@section('content')

<div class="kpis">
    <div class="kpi"><div class="lbl">{{ __('hrm.workers') }}</div><div class="val">{{ $rows->count() }}</div>
        <div class="sub2">{{ __('hrm.workers_sub') }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('hr.state_working') }}</div><div class="val">{{ $counts->get('working', 0) }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('hr.state_break') }}</div><div class="val">{{ $counts->get('break', 0) }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('hr.state_off') }}</div><div class="val">{{ $counts->get('off', 0) }}</div></div>
</div>

<div class="card">
    <h3>👷 {{ __('hrm.supervisor') }} <span class="side">{{ __('hrm.supervisor_sub') }} · {{ today()->format('Y-m-d') }}</span></h3>

    <div class="tablewrap">
        <table>
            <tr>
                <th data-nosum>{{ __('common.code') }}</th>
                <th style="text-align:start">{{ __('hrm.name') }}</th>
                <th>{{ __('hrm.department') }}</th>
                <th>{{ __('hrm.job_title') }}</th>
                <th style="text-align:start">{{ __('hrm.cost_center') }}</th>
                <th>{{ __('hr.state') }}</th>
                <th data-noprint></th>
            </tr>
            @forelse ($rows as $e)
                @php $st = $states[$e->id] ?? 'off'; @endphp
                <tr>
                    <td><b dir="ltr">{{ $e->code }}</b></td>
                    <td style="text-align:start">{{ $e->displayName() }}</td>
                    <td>{{ $e->department ?: '—' }}</td>
                    <td>{{ $e->job_title ?: '—' }}</td>
                    <td style="text-align:start">{{ $e->costCenter?->displayName() ?? '—' }}</td>
                    <td><span class="badge {{ $badge[$st] ?? 'b-gray' }}">{{ __('hr.state_'.$st) }}</span></td>
                    <td data-noprint style="white-space:nowrap">
                        @foreach ($next[$e->id] ?? [] as $type)
                            <form method="POST" action="{{ route('hr.attendance.supervisor.punch') }}" style="display:inline">
                                @csrf
                                <input type="hidden" name="employee_id" value="{{ $e->id }}">
                                <input type="hidden" name="type" value="{{ $type }}">
                                <button class="btn sm {{ $btn[$type] }}" type="submit">{{ __('hr.punch_'.$type) }}</button>
                            </form>
                        @endforeach
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" style="color:var(--muted);font-size:12px">{{ __('hrm.no_workers') }}</td></tr>
            @endforelse
        </table>
    </div>
</div>

@endsection
