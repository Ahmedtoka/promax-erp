@extends('layouts.system')

{{--
    ملفات الموظفين (Lane 7 — المهمة ١). الموظف ممكن يبقى من غير لوجين.
    ⚠️ الرقم القومي ورقم التأمين مش هنا — في فورم التعديل بس (`hr.employees.edit`).
--}}

@section('title', __('hrm.employees'))

@php
    $fmt = fn ($n) => number_format((float) $n, 2);
    $canEdit = \App\Support\Access::action(auth()->user(), 'act.hr.employees');
    $cols = $canEdit ? 11 : 10;
    $exportQuery = array_merge(request()->only(['q', 'cost_center_id', 'department', 'active']), $range->query(), ['export' => 1]);
@endphp

@section('actions')
    <a class="btn sm green" href="{{ route('hr.employees', $exportQuery) }}">⬇ {{ __('ui.export_all') }}</a>
    @if ($canEdit)
        <button class="btn green" type="button" onclick="openDlg('dlgEmployee')">＋ {{ __('hrm.new_employee') }}</button>
    @endif
@endsection

@section('content')

<div class="kpis">
    <div class="kpi"><div class="lbl">{{ __('hrm.kpi_total') }}</div><div class="val">{{ number_format($total) }}</div></div>
    <a class="kpi" href="{{ request()->fullUrlWithQuery(['active' => request('active') === '1' ? null : '1', 'page' => null]) }}#emp-table">
        <div class="lbl">{{ __('hrm.kpi_active') }}</div><div class="val">{{ number_format($activeCount) }}</div></a>
    <div class="kpi"><div class="lbl">{{ __('hrm.kpi_salary') }}</div><div class="val num">{{ $fmt($salaryTotal) }}</div>
        <div class="sub2">{{ __('hrm.kpi_salary_sub') }}</div></div>
</div>

<div class="card" id="emp-table">
    <h3>🪪 {{ __('hrm.employees') }} <span class="side">{{ __('hrm.employees_sub') }}</span></h3>

    <form method="GET" class="searchbar" data-noprint>
        <label class="fl wide"><span>{{ __('common.search') }}</span>
            <input type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('hrm.search_ph') }}"></label>
        <label class="fl"><span>{{ __('hrm.cost_center') }}</span>
            <select name="cost_center_id" onchange="this.form.submit()">
                <option value="">{{ __('hrm.all_cost_centers') }}</option>
                @foreach ($costCenters as $cc)
                    <option value="{{ $cc->id }}" @selected((string) request('cost_center_id') === (string) $cc->id)>{{ $cc->code }} · {{ $cc->displayName() }}</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('hrm.department') }}</span>
            <select name="department" onchange="this.form.submit()">
                <option value="">{{ __('hrm.all_departments') }}</option>
                @foreach ($departments as $d)
                    <option value="{{ $d }}" @selected(request('department') === $d)>{{ $d }}</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('hrm.active') }}</span>
            <select name="active" onchange="this.form.submit()">
                <option value="">{{ __('hrm.all_states') }}</option>
                <option value="1" @selected(request('active') === '1')>{{ __('hrm.active') }}</option>
                <option value="0" @selected(request('active') === '0')>{{ __('hrm.inactive') }}</option>
            </select></label>
        {{-- الفترة على تاريخ التعيين — الفاضي = كل الموظفين --}}
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue()])
        <button class="btn green" type="submit">{{ __('common.filter') }}</button>
        <a class="btn" href="{{ route('hr.employees') }}">{{ __('common.clear') }}</a>
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                <th data-nosum>{{ __('common.code') }}</th>
                <th style="text-align:start">{{ __('hrm.name') }}</th>
                <th style="text-align:start">{{ __('hrm.cost_center') }}</th>
                <th>{{ __('hrm.department') }}</th>
                <th>{{ __('hrm.job_title') }}</th>
                <th style="text-align:start">{{ __('hrm.manager') }}</th>
                <th>{{ __('hrm.hire_date') }}</th>
                <th>{{ __('hrm.pay_method') }}</th>
                <th class="num">{{ __('hrm.basic_salary') }}</th>
                <th>{{ __('hrm.status') }}</th>
                @if ($canEdit)<th></th>@endif
            </tr>
            @forelse ($rows as $e)
                <tr>
                    <td><b dir="ltr">{{ $e->code }}</b></td>
                    <td style="text-align:start">
                        {{ $e->displayName() }}
                        <div style="font-size:10.5px;color:var(--muted)">
                            {{ $e->user ? __('hrm.linked_user', ['code' => $e->user->code]) : __('hrm.no_login') }}
                        </div>
                    </td>
                    <td style="text-align:start">{{ $e->costCenter?->displayName() ?? '—' }}</td>
                    <td>{{ $e->department ?: '—' }}</td>
                    <td>{{ $e->job_title ?: '—' }}</td>
                    <td style="text-align:start">{{ $e->manager?->displayName() ?? '—' }}</td>
                    <td class="num" style="font-size:11px">{{ $e->hire_date?->format('Y-m-d') ?? '—' }}</td>
                    <td>{{ __('hrm.pay_'.$e->pay_method) }}</td>
                    <td class="num">{{ $fmt($e->basic_salary) }}</td>
                    <td>
                        <span class="badge {{ $e->active ? 'b-green' : 'b-gray' }}">{{ $e->active ? __('hrm.active') : __('hrm.inactive') }}</span>
                        @if (! $e->insured)<span class="badge b-orange">{{ __('hrm.not_insured') }}</span>@endif
                        @if ($e->contract_path)
                            <a class="btn sm" href="{{ route('hr.employees.contract', $e) }}" target="_blank">📎 {{ __('hrm.contract') }}</a>
                        @endif
                    </td>
                    @if ($canEdit)
                        <td><a class="btn sm" href="{{ route('hr.employees.edit', $e) }}">✎ {{ __('common.edit') }}</a></td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $cols }}" style="color:var(--muted);font-size:12px">{{ __('hrm.no_rows') }}</td></tr>
            @endforelse
        </table>
    </div>

    @include('partials._pagination', ['p' => $rows])
</div>

{{-- ═══════════ موظف جديد — الديالوج جوه content عن قصد ═══════════ --}}
@if ($canEdit)
<dialog id="dlgEmployee">
    <form class="dlg" method="POST" enctype="multipart/form-data" action="{{ route('hr.employees.store') }}">
        @csrf
        <h4>🪪 {{ __('hrm.new_employee') }}</h4>
        @include('hr._employee_fields', ['employee' => null])
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgEmployee')">{{ __('common.cancel') }}</button>
            <button class="btn green" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>
@endif

@endsection
