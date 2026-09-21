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
    {{-- (٢٢/٩) الإيرادات وصافي الربح مفرودين في الجدول تحت، والمصروفات بتفتح شاشتها لنفس الفترة --}}
    <a class="kpi" href="#gl-table">
        <div class="lbl">{{ __('gl.revenue') }}</div>
        <div class="val">{{ $fmt($data['total_revenue']) }} {{ __('common.currency') }}</div>
        <div class="sub2">{{ $from->format('Y-m-d') }} → {{ $to->format('Y-m-d') }}</div>
    </a>
    <a class="kpi" href="{{ route('gl.expenses', ['from' => $from->toDateString(), 'to' => $to->toDateString()]) }}">
        <div class="lbl">{{ __('gl.expenses_total') }}</div>
        <div class="val neg">{{ $fmt($data['total_expenses']) }} {{ __('common.currency') }}</div>
        <div class="sub2">{{ __('gl.expenses') }}</div>
    </a>
    <a class="kpi" href="#gl-table">
        <div class="lbl">{{ __('gl.net_income') }}</div>
        <div class="val {{ $data['net'] < 0 ? 'neg' : '' }}">{{ $fmt($data['net']) }} {{ __('common.currency') }}</div>
        <div class="sub2">{{ __('gl.net_income_hint') }}</div>
    </a>
</div>

<div class="card" id="gl-table">
    <h3>📈 {{ __('gl.income_statement') }}</h3>

    <form method="GET" class="searchbar" data-noprint>
        {{-- ⚠️ `all => false`: الفترة الفاضية هنا = الشهر الحالي (`DateRange` month) مش «كل الفترات» --}}
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue(), 'auto' => true, 'all' => false])
        <button class="btn gold" type="submit">{{ __('common.filter') }}</button>
        <a class="btn" href="{{ route('gl.income') }}">{{ __('common.clear') }}</a>
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                {{-- ⚠️ data-nosum — كود الحساب نص مش مبلغ --}}
                <th data-nosum>{{ __('gl.code') }}</th>
                <th style="text-align:start">{{ __('gl.name') }}</th>
                {{-- ⚠️ data-nosum (٢٢/٩) — الأقسام ليها إجمالياتها جوه الجدول؛ جمع العمود كله (أصول + خصوم / إيراد + مصروف) رقم مالوش معنى --}}
                <th class="num" data-nosum>{{ __('gl.amount') }}</th>
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
