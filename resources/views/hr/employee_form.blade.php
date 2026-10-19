@extends('layouts.system')

{{-- تعديل ملف موظف — صفحة لوحدها عشان الرقم القومي ورقم التأمين مايظهروش في القايمة --}}

@section('title', __('hrm.edit_employee'))

@section('actions')
    <a class="btn" href="{{ route('hr.employees') }}">↩ {{ __('hrm.employees') }}</a>
    @if ($employee->contract_path)
        <a class="btn" href="{{ route('hr.employees.contract', $employee) }}" target="_blank">📎 {{ __('hrm.contract') }}</a>
    @endif
@endsection

@section('content')

<div class="card" style="max-width:760px">
    <h3>🪪 {{ $employee->displayName() }} <span class="side" dir="ltr">{{ $employee->code }}</span></h3>

    <form method="POST" enctype="multipart/form-data" action="{{ route('hr.employees.update', $employee) }}">
        @csrf
        @method('PUT')
        @include('hr._employee_fields', ['employee' => $employee])
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <a class="btn" href="{{ route('hr.employees') }}">{{ __('common.cancel') }}</a>
            <button class="btn green" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</div>

@endsection
