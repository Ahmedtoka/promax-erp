{{--
    خانات ملف الموظف — مشتركة بين ديالوج «موظف جديد» وصفحة التعديل.
    بيستلم: $employee (أو null)، $costCenters، $managers، $users، $payMethods، $borneBy.
    ⚠️ البوليان: hidden 0 قبل الـcheckbox — من غيره الشيك المشال مابيتبعتش والقيمة مابتتغيرش.
--}}
@php
    $v = fn (string $k, $default = '') => old($k, $employee?->{$k} ?? $default);
    $checked = fn (string $k) => (bool) old($k, $employee ? $employee->{$k} : true);
@endphp

<div class="frow">
    <div>
        <label class="f">{{ __('hrm.name') }} <b class="req-star">*</b></label>
        <input type="text" name="name" required maxlength="120" value="{{ $v('name') }}" style="width:100%">
    </div>
    <div>
        <label class="f">{{ __('hrm.name_en') }}</label>
        <input type="text" name="name_en" maxlength="120" dir="ltr" value="{{ $v('name_en') }}" style="width:100%">
    </div>
</div>

<div class="frow" style="margin-top:10px">
    <div>
        <label class="f">{{ __('common.code') }}</label>
        <input type="text" name="code" maxlength="20" dir="ltr" value="{{ $v('code') }}" placeholder="EMP-1001" style="width:100%">
        <div style="font-size:10.5px;color:var(--muted);margin-top:4px">{{ __('hrm.code_hint') }}</div>
    </div>
    <div>
        <label class="f">{{ __('hrm.login_user') }}</label>
        <select name="user_id" style="width:100%">
            <option value="">{{ __('hrm.no_login') }}</option>
            @foreach ($users as $u)
                <option value="{{ $u->id }}" @selected((string) $v('user_id') === (string) $u->id)>{{ $u->displayName() }} ({{ $u->code }})</option>
            @endforeach
        </select>
    </div>
</div>

<div class="frow" style="margin-top:10px">
    <div>
        <label class="f">{{ __('hrm.cost_center') }}</label>
        <select name="cost_center_id" style="width:100%">
            <option value="">—</option>
            @foreach ($costCenters as $cc)
                <option value="{{ $cc->id }}" @selected((string) $v('cost_center_id') === (string) $cc->id)>{{ $cc->code }} · {{ $cc->displayName() }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="f">{{ __('hrm.manager') }}</label>
        <select name="manager_id" style="width:100%">
            <option value="">—</option>
            @foreach ($managers as $m)
                <option value="{{ $m->id }}" @selected((string) $v('manager_id') === (string) $m->id)>{{ $m->displayName() }} ({{ $m->code }})</option>
            @endforeach
        </select>
    </div>
</div>

<div class="frow" style="margin-top:10px">
    <div>
        <label class="f">{{ __('hrm.department') }}</label>
        <input type="text" name="department" maxlength="80" value="{{ $v('department') }}" style="width:100%">
    </div>
    <div>
        <label class="f">{{ __('hrm.job_title') }}</label>
        <input type="text" name="job_title" maxlength="80" value="{{ $v('job_title') }}" style="width:100%">
    </div>
</div>

<div class="frow" style="margin-top:10px">
    <div>
        <label class="f">{{ __('hrm.hire_date') }}</label>
        <input type="date" name="hire_date" value="{{ old('hire_date', $employee?->hire_date?->toDateString()) }}" style="width:100%">
    </div>
    <div>
        <label class="f">{{ __('hrm.end_date') }}</label>
        <input type="date" name="end_date" value="{{ old('end_date', $employee?->end_date?->toDateString()) }}" style="width:100%">
    </div>
</div>

<div class="frow" style="margin-top:10px">
    <div>
        <label class="f">{{ __('hrm.national_id') }}</label>
        <input type="text" name="national_id" maxlength="14" inputmode="numeric" pattern="[0-9]{14}" dir="ltr" value="{{ $v('national_id') }}" style="width:100%">
    </div>
    <div>
        <label class="f">{{ __('hrm.insurance_no') }}</label>
        <input type="text" name="insurance_no" maxlength="20" dir="ltr" value="{{ $v('insurance_no') }}" style="width:100%">
    </div>
</div>
<div style="font-size:10.5px;color:var(--muted);margin-top:4px">{{ __('hrm.sensitive_hint') }}</div>

<div class="frow" style="margin-top:10px">
    <div>
        <label class="f">{{ __('hrm.basic_salary') }}</label>
        <input type="number" name="basic_salary" step="0.01" min="0" max="99999999" dir="ltr" value="{{ $v('basic_salary', 0) }}" style="width:100%">
    </div>
    <div>
        <label class="f">{{ __('hrm.allowances') }}</label>
        <input type="number" name="allowances" step="0.01" min="0" max="99999999" dir="ltr" value="{{ $v('allowances', 0) }}" style="width:100%">
    </div>
</div>

<div class="frow" style="margin-top:10px">
    <div>
        <label class="f">{{ __('hrm.pay_method') }}</label>
        <select name="pay_method" style="width:100%">
            @foreach ($payMethods as $pm)
                <option value="{{ $pm }}" @selected($v('pay_method', 'cash') === $pm)>{{ __('hrm.pay_'.$pm) }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="f">{{ __('hrm.bank_account') }}</label>
        <input type="text" name="bank_account" maxlength="40" dir="ltr" value="{{ $v('bank_account') }}" style="width:100%">
    </div>
</div>

<div class="frow" style="margin-top:10px">
    <div>
        <label class="f">{{ __('hrm.insurable_wage') }}</label>
        <input type="number" name="insurable_wage" step="0.01" min="0" max="99999999" dir="ltr" value="{{ $v('insurable_wage') }}" style="width:100%">
        <div style="font-size:10.5px;color:var(--muted);margin-top:4px">{{ __('hrm.insurable_hint') }}</div>
    </div>
    <div>
        <label class="f">{{ __('hrm.borne_by') }}</label>
        <select name="employee_share_borne_by" style="width:100%">
            @foreach ($borneBy as $b)
                <option value="{{ $b }}" @selected($v('employee_share_borne_by', 'employee') === $b)>{{ __('hrm.borne_'.$b) }}</option>
            @endforeach
        </select>
    </div>
</div>

<div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:10px">
    @foreach (['insured', 'taxable', 'attendance_required', 'active'] as $flag)
        <label class="f" style="display:flex;gap:6px;align-items:center">
            <input type="hidden" name="{{ $flag }}" value="0">
            <input type="checkbox" name="{{ $flag }}" value="1" @checked($checked($flag))>
            {{ __('hrm.'.$flag) }}
        </label>
    @endforeach
</div>

<div style="margin-top:10px">
    <label class="f">{{ __('hrm.contract') }}</label>
    <input type="file" name="contract" accept="image/*,application/pdf" style="width:100%">
    <div style="font-size:10.5px;color:var(--muted);margin-top:4px">{{ __('hrm.contract_hint') }}</div>
</div>
