@extends('layouts.system')

{{--
    قسيمة مرتب — مستند A4 (Lane 7 — المهمة ٦). نفس نظام المستندات المشترك
    (partials._doc_style + ops._po_doc_style) وهوية الشركة من settings.
    ⚠️ الأرقام من القسيمة المحفوظة — مفيش حساب حي هنا.
--}}

@php
    $fmt = fn ($n) => number_format((float) $n, 2);
    $co = \App\Models\Setting::docHeader();
    $e = $s->employee;
    $earn = $s->lines->where('kind', 'earning');
    $ded = $s->lines->where('kind', 'deduction');
    $er = $s->lines->where('kind', 'employer');
    $qty = fn ($l) => $l->qty === null ? '' : rtrim(rtrim((string) $l->qty, '0'), '.');
@endphp

@section('title', __('hrm.payroll_slip_title').' '.$s->run->period.' — '.($e?->displayName() ?? ''))

@section('actions')
    <a class="btn" href="{{ route('hr.payroll.show', $s->run) }}">← {{ __('hrm.payroll') }} {{ $s->run->period }}</a>
    <button class="btn green" onclick="window.print()">🖨️ {{ __('hrm.payroll_print') }}</button>
@endsection

@section('content')

<div class="doc po-doc has-bolt">
    <img class="bolt-mark po-bolt" src="{{ asset('brand/bolt.svg') }}" alt="">

    <header class="doc-head">
        <div class="po-brandrow">
            <img src="{{ asset('img/promax-logo.png') }}" alt="PROMAX" class="doc-logo">
            <div class="po-corp">
                <div class="po-corp-name">{{ $co['name'] ?: __('ops.corp_name') }}</div>
                @if ($co['tax_id'])
                    <div class="po-corp-line">{{ __('doc.tax_id') }}: <b>{{ $co['tax_id'] }}</b></div>
                @endif
            </div>
        </div>
        <div class="doc-id">
            <div class="doc-no">{{ $s->run->period }}</div>
            <div class="doc-date">{{ __('doc.date') }}: <b>{{ $s->run->date->format('Y-m-d') }}</b></div>
        </div>
    </header>

    <div class="po-title">{{ __('hrm.payroll_slip_title') }}</div>

    <div class="doc-body">
        <div class="po-parties">
            <div class="po-party">
                <span>{{ __('hr.employee') }}: <b>{{ $e?->displayName() ?? '—' }}</b></span>
                <span class="sep">·</span>
                <span>{{ __('hrm.emp_code') }}: <b>{{ $e?->code ?? '—' }}</b></span>
            </div>
            <div class="po-party">
                <span>{{ __('hrm.job_title') }}: <b>{{ $e?->job_title ?: '—' }}</b></span>
                <span class="sep">·</span>
                <span>{{ __('hrm.cost_center') }}: <b>{{ $s->costCenter?->displayName() ?? '—' }}</b></span>
                <span class="sep">·</span>
                <span>{{ __('hrm.pay_method') }}: <b>{{ __('hrm.pay_'.$s->pay_method) }}</b></span>
            </div>
        </div>

        <div class="ps-grid">
            <table class="doc-table">
                <thead><tr><th style="text-align:start">{{ __('hrm.payroll_earnings') }}</th><th class="num">{{ __('hrm.payroll_qty') }}</th><th class="num">{{ __('common.amount') }}</th></tr></thead>
                <tbody>
                    @foreach ($earn as $l)
                        <tr><td style="text-align:start">{{ $l->label() }}</td><td class="num">{{ $qty($l) }}</td><td class="num">{{ $fmt($l->amount) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
            <table class="doc-table">
                <thead><tr><th style="text-align:start">{{ __('hrm.payroll_deductions') }}</th><th class="num">{{ __('hrm.payroll_qty') }}</th><th class="num">{{ __('common.amount') }}</th></tr></thead>
                <tbody>
                    @forelse ($ded as $l)
                        <tr><td style="text-align:start">{{ $l->label() }}</td><td class="num">{{ $qty($l) }}</td><td class="num">{{ $fmt($l->amount) }}</td></tr>
                    @empty
                        <tr><td colspan="3">—</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <table class="doc-table ps-totals">
            <tr><td style="text-align:start">{{ __('hrm.payroll_gross') }}</td><td class="num"><b>{{ $fmt($s->gross) }}</b></td></tr>
            <tr><td style="text-align:start">{{ __('hrm.line_insurance') }}</td><td class="num">− {{ $fmt($s->insurance_employee) }}</td></tr>
            <tr><td style="text-align:start">{{ __('hrm.line_tax') }}</td><td class="num">− {{ $fmt($s->tax) }}</td></tr>
            <tr><td style="text-align:start">{{ __('hrm.line_advance') }}</td><td class="num">− {{ $fmt($s->advances) }}</td></tr>
            <tr class="ps-net"><td style="text-align:start">{{ __('hrm.payroll_net') }}</td><td class="num"><b>{{ $fmt($s->net) }}</b></td></tr>
        </table>

        @if ($er->isNotEmpty())
            <div class="ps-er">{{ __('hrm.payroll_employer_share') }}:
                @foreach ($er as $l) {{ $l->label() }} <b class="num">{{ $fmt($l->amount) }}</b> @endforeach
                · {{ __('hrm.payroll_insurance_wage') }} <b class="num">{{ $fmt($s->insurance_wage) }}</b>
            </div>
        @endif

        <div class="doc-sign three">
            <div><span></span>{{ __('hrm.payroll_sign_employee') }}</div>
            <div><span></span>{{ __('hrm.payroll_sign_hr') }}</div>
            <div><span></span>{{ __('hrm.payroll_sign_management') }}</div>
        </div>
    </div>
</div>

@endsection

@section('scripts')
@include('partials._doc_style')
@include('ops._po_doc_style')
<style>
.ps-grid{position:relative;z-index:1;display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:10px}
.ps-totals{position:relative;z-index:1;width:100%;margin-top:12px}
.ps-totals tr.ps-net td{border-top:2px solid var(--royal-blue);font-size:14px}
.ps-er{position:relative;z-index:1;font-size:11px;color:var(--muted);margin-top:8px}
@media (max-width:640px){.ps-grid{grid-template-columns:1fr}}
@media print{.ps-totals tr.ps-net td{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
</style>
@endsection
