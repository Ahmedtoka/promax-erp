@extends('layouts.system')

{{--
    مسير شهر واحد (Lane 7 — المهمة ٦): القسايم + تعديل البنود في المسودة + الاعتماد والصرف.
    ⚠️ الأزرار متحرسة بنفس مفاتيح الراوت (`act.hr.payroll*`) والحارس الحقيقي على السيرفر.
--}}

@section('title', __('hrm.payroll').' '.$run->period)

@php
    $fmt = fn ($n) => number_format((float) $n, 2);
    $cashTotal = $slips->where('pay_method', '!=', 'bank')->sum('net');
    $bankTotal = $slips->where('pay_method', 'bank')->sum('net');
    $extra = ['commission', 'incentive', 'overtime', 'company_borne'];
@endphp

@section('actions')
    <a class="btn" href="{{ route('hr.payroll') }}">← {{ __('hrm.payroll_back') }}</a>
    <a class="btn sm green" href="{{ route('hr.payroll.show', ['run' => $run, 'export' => 1]) }}">⬇ {{ __('ui.export_all') }}</a>
    @if ($canEdit)
        <form method="POST" action="{{ route('hr.payroll.draft') }}" style="display:inline" title="{{ __('hrm.payroll_redraft_hint') }}">
            @csrf
            <input type="hidden" name="period" value="{{ $run->period }}">
            <button class="btn">🔄 {{ __('hrm.payroll_redraft') }}</button>
        </form>
    @endif
    @if ($canApprove)
        <form method="POST" action="{{ route('hr.payroll.approve', $run) }}" style="display:inline"
              onsubmit="return confirm(@js(__('hrm.payroll_approve_confirm')))">
            @csrf
            <button class="btn green">✔ {{ __('hrm.payroll_approve') }}</button>
        </form>
    @endif
    @if ($canPay)
        <button class="btn green" type="button" onclick="openDlg('dlgPay')">💸 {{ __('hrm.payroll_pay') }}</button>
    @endif
@endsection

@section('content')

<div class="kpis">
    <div class="kpi"><div class="lbl">{{ __('hrm.payroll_period') }}</div><div class="val num">{{ $run->period }}</div>
        <div class="sub2"><span class="badge {{ $run->statusBadge() }}">{{ $run->statusLabel() }}</span></div></div>
    <div class="kpi"><div class="lbl">{{ __('hrm.payroll_gross') }}</div><div class="val num">{{ $fmt($run->gross) }}</div>
        <div class="sub2">{{ $slips->count() }} {{ __('hrm.payroll_employees') }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('hrm.payroll_net') }}</div><div class="val num">{{ $fmt($run->net) }}</div>
        <div class="sub2">{{ __('hrm.payroll_cash_total') }} {{ $fmt($cashTotal) }} · {{ __('hrm.payroll_bank_total') }} {{ $fmt($bankTotal) }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('hrm.payroll_employer_cost') }}</div><div class="val num">{{ $fmt($run->employer_cost) }}</div>
        @if ($run->approver)<div class="sub2">{{ __('hrm.payroll_approved_by') }}: {{ $run->approver->name }}</div>@endif</div>
</div>

@if ($run->payments->isNotEmpty())
    <div class="card">
        <h3>💸 {{ __('hrm.payroll_payments') }}</h3>
        <div class="tablewrap"><table>
            <tr><th>{{ __('common.date') }}</th><th>{{ __('hrm.pay_method') }}</th><th style="text-align:start">{{ __('hrm.adv_safe') }}</th><th class="num">{{ __('common.amount') }}</th></tr>
            @foreach ($run->payments as $p)
                <tr>
                    <td class="num">{{ $p->date->format('Y-m-d') }}</td>
                    <td>{{ __('hrm.pay_'.$p->kind) }}</td>
                    <td style="text-align:start">{{ $p->safe?->displayName() ?? '—' }}</td>
                    <td class="num">{{ $fmt($p->amount) }}</td>
                </tr>
            @endforeach
        </table></div>
    </div>
@endif

<div class="card">
    <h3>🧾 {{ __('hrm.payroll_slips') }}</h3>
    <div class="tablewrap">
        <table>
            <tr>
                <th data-nosum>{{ __('hrm.emp_code') }}</th>
                <th style="text-align:start">{{ __('hr.employee') }}</th>
                <th>{{ __('hrm.cost_center') }}</th>
                <th>{{ __('hrm.pay_method') }}</th>
                <th class="num">{{ __('hrm.line_basic') }}</th>
                <th class="num">{{ __('hrm.line_allowances') }}</th>
                <th class="num">{{ __('hrm.payroll_earnings') }} +</th>
                <th class="num">{{ __('hrm.payroll_deductions') }} −</th>
                <th class="num">{{ __('hrm.payroll_gross') }}</th>
                <th class="num">{{ __('hrm.line_insurance') }}</th>
                <th class="num">{{ __('hrm.line_tax') }}</th>
                <th class="num">{{ __('hrm.line_advance') }}</th>
                <th class="num">{{ __('hrm.payroll_net') }}</th>
                <th class="num">{{ __('hrm.line_insurance_employer') }}</th>
                <th data-noprint></th>
            </tr>
            @forelse ($slips as $s)
                <tr>
                    <td class="num">{{ $s->employee?->code }}</td>
                    <td style="text-align:start">{{ $s->employee?->displayName() ?? '—' }}</td>
                    <td style="font-size:11px">{{ $s->costCenter?->code ?? '—' }}</td>
                    <td style="font-size:11px">{{ __('hrm.pay_'.$s->pay_method) }}</td>
                    <td class="num">{{ $fmt($s->amountOf('basic')) }}</td>
                    <td class="num">{{ $fmt($s->amountOf('allowances')) }}</td>
                    <td class="num">{{ $fmt(collect($extra)->sum(fn ($c) => $s->amountOf($c))) }}</td>
                    <td class="num">{{ $fmt($s->deductions) }}</td>
                    <td class="num"><b>{{ $fmt($s->gross) }}</b></td>
                    <td class="num">{{ $fmt($s->insurance_employee) }}</td>
                    <td class="num">{{ $fmt($s->tax) }}</td>
                    <td class="num">{{ $fmt($s->advances) }}</td>
                    <td class="num"><b>{{ $fmt($s->net) }}</b></td>
                    <td class="num">{{ $fmt($s->insurance_employer) }}</td>
                    <td data-noprint style="white-space:nowrap">
                        <details style="display:inline-block">
                            <summary class="btn sm">≡</summary>
                            <div style="font-size:11px;text-align:start;min-width:220px;padding:6px">
                                @foreach ($s->lines as $l)
                                    <div style="display:flex;justify-content:space-between;gap:10px">
                                        <span>{{ $l->label() }}@if ($l->qty !== null) <span style="color:var(--muted)">({{ rtrim(rtrim($l->qty, '0'), '.') }})</span>@endif
                                            @if ($l->manual)<span class="badge b-purple">{{ __('hrm.payroll_manual') }}</span>@endif</span>
                                        <span class="num">{{ $l->kind === 'deduction' ? '−' : '' }}{{ $fmt($l->amount) }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </details>
                        @if ($canEdit)
                            <button class="btn sm" type="button" data-action="{{ route('hr.payroll.line', $s) }}"
                                    data-name="{{ $s->employee?->displayName() }}" onclick="editLine(this)">✎</button>
                        @endif
                        <a class="btn sm" href="{{ route('hr.payroll.print', $s) }}" title="{{ __('hrm.payroll_print') }}">🖨</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="15" style="color:var(--muted);font-size:12px">{{ __('hrm.payroll_no_slips') }}</td></tr>
            @endforelse
        </table>
    </div>
</div>

@if ($canEdit)
<dialog id="dlgLine">
    <form class="dlg" method="POST" id="lineForm" action="">
        @csrf
        <h4>✎ {{ __('hrm.payroll_edit_line') }} — <span id="lineEmp"></span></h4>
        <label class="fl wide"><span>{{ __('hrm.payroll_line') }}</span>
            <select name="code" required>
                @foreach ($editable as $c)
                    <option value="{{ $c }}">{{ __('hrm.line_'.$c) }}</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('hrm.payroll_line_amount') }}</span>
            <input type="number" name="amount" step="0.01" min="0" required></label>
        <label class="fl wide"><span>{{ __('hrm.adv_note') }}</span>
            <input type="text" name="note" maxlength="300"></label>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgLine')">{{ __('common.cancel') }}</button>
            <button class="btn green" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>
<script>
function editLine(btn) {
    document.getElementById('lineForm').action = btn.dataset.action;
    document.getElementById('lineEmp').textContent = btn.dataset.name || '';
    openDlg('dlgLine');
}
</script>
@endif

@if ($canPay)
<dialog id="dlgPay">
    <form class="dlg" method="POST" action="{{ route('hr.payroll.pay', $run) }}">
        @csrf
        <h4>💸 {{ __('hrm.payroll_pay') }} {{ $run->period }}</h4>
        <label class="fl"><span>{{ __('hrm.payroll_pay_date') }}</span>
            <input type="date" name="date" value="{{ old('date', now()->toDateString()) }}" required></label>
        @if ($cashTotal > 0 || $slips->where('pay_method', '!=', 'bank')->isNotEmpty())
            <label class="fl wide"><span>{{ __('hrm.payroll_cash_safe') }} — {{ $fmt($cashTotal) }}</span>
                <select name="cash_safe_id" required>
                    <option value="">—</option>
                    @foreach ($cashSafes as $c)
                        <option value="{{ $c->id }}">{{ $c->code }} · {{ $c->displayName() }}</option>
                    @endforeach
                </select></label>
        @endif
        @if ($slips->where('pay_method', 'bank')->isNotEmpty())
            <label class="fl wide"><span>{{ __('hrm.payroll_bank_safe') }} — {{ $fmt($bankTotal) }}</span>
                <select name="bank_safe_id" required>
                    <option value="">—</option>
                    @foreach ($bankSafes as $b)
                        <option value="{{ $b->id }}">{{ $b->code }} · {{ $b->displayName() }}</option>
                    @endforeach
                </select></label>
        @endif
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgPay')">{{ __('common.cancel') }}</button>
            <button class="btn green" type="submit">{{ __('hrm.payroll_pay') }}</button>
        </div>
    </form>
</dialog>
@endif

@endsection
