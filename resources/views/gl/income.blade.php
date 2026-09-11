@extends('layouts.system')

{{--
    قائمة الدخل (١٢/٩/٢٠٢٦) — إيرادات الفترة ناقص مصروفاتها.
    ⚠️ مرتجعات المبيعات والخصومات المسموح بها حسابات **إيراد** طبيعتها
    مدينة، فرصيدها بيطلع بالسالب وبيقلّل الإيراد لوحده — مش بنطرحها
    مرة تانية هنا.
--}}

@section('title', __('gl.income_statement'))

@php
    $fmt = fn ($n) => number_format((float) $n, 2);
@endphp

@section('actions')
    <a class="btn" href="{{ route('gl.income', array_merge($range->query(), ['export' => 1])) }}">
        📊 {{ __('common.export_screen') }}
    </a>
@endsection

@section('content')

<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ __('gl.revenue') }}</div>
        <div class="val">{{ $fmt($data['total_revenue']) }} {{ __('common.currency') }}</div>
        <div class="sub2">{{ $from->format('Y-m-d') }} → {{ $to->format('Y-m-d') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('gl.expenses_total') }}</div>
        <div class="val neg">{{ $fmt($data['total_expenses']) }} {{ __('common.currency') }}</div>
        <div class="sub2">{{ __('gl.expenses') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('gl.net_income') }}</div>
        <div class="val {{ $data['net'] < 0 ? 'neg' : '' }}">{{ $fmt($data['net']) }} {{ __('common.currency') }}</div>
        <div class="sub2">{{ __('gl.net_income_hint') }}</div>
    </div>
</div>

<div class="card">
    <h3>📈 {{ __('gl.income_statement') }}</h3>

    <form method="GET" class="frow" style="margin-bottom:12px" data-noprint>
        <div>
            <label class="f">{{ __('common.from') }}</label>
            <input type="date" name="from" value="{{ $range->fromValue() }}" onchange="this.form.submit()">
        </div>
        <div>
            <label class="f">{{ __('common.to') }}</label>
            <input type="date" name="to" value="{{ $range->toValue() }}" onchange="this.form.submit()">
        </div>
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                {{-- ⚠️ data-nosum — كود الحساب نص مش مبلغ --}}
                <th data-nosum>{{ __('gl.code') }}</th>
                <th style="text-align:start">{{ __('gl.name') }}</th>
                <th class="num">{{ __('gl.amount') }}</th>
            </tr>
            <tr><td colspan="3" style="text-align:start"><b>{{ __('gl.revenue') }}</b></td></tr>
            @foreach ($data['revenue'] as $r)
                <tr>
                    <td dir="ltr">{{ $r['account']->code }}</td>
                    <td style="text-align:start">
                        <a href="{{ route('gl.accounts.show', array_merge(['account' => $r['account']->id], $range->query())) }}">
                            {{ $r['account']->displayName() }}
                        </a>
                    </td>
                    <td class="num">{{ $fmt($r['amount']) }}</td>
                </tr>
            @endforeach
            <tr>
                <td colspan="2" style="text-align:start"><b>{{ __('gl.revenue') }}</b></td>
                <td class="num"><b>{{ $fmt($data['total_revenue']) }}</b></td>
            </tr>

            <tr><td colspan="3" style="text-align:start"><b>{{ __('gl.expenses') }}</b></td></tr>
            @foreach ($data['expenses'] as $r)
                <tr>
                    <td dir="ltr">{{ $r['account']->code }}</td>
                    <td style="text-align:start">
                        <a href="{{ route('gl.accounts.show', array_merge(['account' => $r['account']->id], $range->query())) }}">
                            {{ $r['account']->displayName() }}
                        </a>
                    </td>
                    <td class="num">{{ $fmt($r['amount']) }}</td>
                </tr>
            @endforeach
            <tr>
                <td colspan="2" style="text-align:start"><b>{{ __('gl.expenses_total') }}</b></td>
                <td class="num"><b>{{ $fmt($data['total_expenses']) }}</b></td>
            </tr>

            <tr>
                <td colspan="2" style="text-align:start"><b>{{ __('gl.net_income') }}</b></td>
                <td class="num"><b>{{ $fmt($data['net']) }}</b></td>
            </tr>
        </table>
    </div>
</div>

@endsection
