@extends('layouts.system')

{{--
    مسير المرتبات — قايمة الشهور (Lane 7 — المهمة ٦). الفلتر على تاريخ المسير (آخر يوم في الشهر).
    ⚠️ زرار المسودة راحة بس — الحارس على السيرفر (role + act.hr.payroll).
--}}

@section('title', __('hrm.payroll'))

@php
    $fmt = fn ($n) => number_format((float) $n, 2);
@endphp

@section('actions')
    <a class="btn sm green" href="{{ route('hr.payroll', array_merge($range->query(), ['export' => 1])) }}">⬇ {{ __('ui.export_all') }}</a>
    @if ($canDraft)
        <button class="btn green" type="button" onclick="openDlg('dlgDraft')">＋ {{ __('hrm.payroll_new_draft') }}</button>
    @endif
@endsection

@section('content')

<div class="card">
    <h3>🧾 {{ __('hrm.payroll') }} <span class="side">{{ __('hrm.payroll_sub') }}</span></h3>

    <form method="GET" class="searchbar" data-noprint>
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue()])
        <button class="btn green" type="submit">{{ __('common.filter') }}</button>
        <a class="btn" href="{{ route('hr.payroll') }}">{{ __('common.clear') }}</a>
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('hrm.payroll_period') }}</th>
                <th>{{ __('hrm.status') }}</th>
                <th class="num" data-nosum>{{ __('hrm.payroll_employees') }}</th>
                <th class="num">{{ __('hrm.payroll_gross') }}</th>
                <th class="num">{{ __('hrm.payroll_net') }}</th>
                <th class="num">{{ __('hrm.payroll_employer_cost') }}</th>
                <th data-noprint></th>
            </tr>
            @forelse ($runs as $r)
                <tr>
                    <td class="num"><b>{{ $r->period }}</b></td>
                    <td><span class="badge {{ $r->statusBadge() }}">{{ $r->statusLabel() }}</span></td>
                    <td class="num">{{ $r->payslips_count }}</td>
                    <td class="num">{{ $fmt($r->gross) }}</td>
                    <td class="num">{{ $fmt($r->net) }}</td>
                    <td class="num">{{ $fmt($r->employer_cost) }}</td>
                    <td data-noprint><a class="btn sm" href="{{ route('hr.payroll.show', $r) }}">{{ __('hrm.payroll_slips') }}</a></td>
                </tr>
            @empty
                <tr><td colspan="7" style="color:var(--muted);font-size:12px">{{ __('hrm.payroll_no_runs') }}</td></tr>
            @endforelse
        </table>
    </div>

    @include('partials._pagination', ['p' => $runs])
</div>

@if ($canDraft)
<dialog id="dlgDraft">
    <form class="dlg" method="POST" action="{{ route('hr.payroll.draft') }}">
        @csrf
        <h4>🧾 {{ __('hrm.payroll_new_draft') }}</h4>
        <label class="fl wide"><span>{{ __('hrm.payroll_period') }}</span>
            <input type="month" name="period" value="{{ old('period', $defaultPeriod) }}" required></label>
        <p style="color:var(--muted);font-size:11px;margin:8px 0 0">{{ __('hrm.payroll_redraft_hint') }}</p>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgDraft')">{{ __('common.cancel') }}</button>
            <button class="btn green" type="submit">{{ __('hrm.payroll_new_draft') }}</button>
        </div>
    </form>
</dialog>
@endif

@endsection
