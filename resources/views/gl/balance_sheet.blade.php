@extends('layouts.system')

{{--
    المركز المالي (١٢/٩/٢٠٢٦) — **لحظة** مش فترة، عشان كده فيه تاريخ
    واحد بس. صافي الدخل من أول الدفتر للتاريخ ده بيتضاف لحقوق الملكية
    كـ«أرباح محتجزة» عشان الأصول تتوازن مع الخصوم+الملكية قبل أي إقفال
    سنوي.
--}}

@section('title', __('gl.balance_sheet'))

@php
    $fmt = fn ($n) => number_format((float) $n, 2);
@endphp

@section('actions')
    <a class="btn" href="{{ route('gl.balance_sheet', ['to' => $asOf->toDateString(), 'export' => 1]) }}">
        📊 {{ __('common.export_screen') }}
    </a>
@endsection

@section('content')

<div class="kpis">
    {{-- (٢٢/٩) الأصول والخصوم مفرودين في الجدول تحت، والاتزان بيتراجع من ميزان المراجعة --}}
    <a class="kpi" href="#gl-table">
        <div class="lbl">{{ __('gl.assets') }}</div>
        <div class="val">{{ $fmt($data['total_assets']) }} {{ __('common.currency') }}</div>
        <div class="sub2">{{ $asOf->format('Y-m-d') }}</div>
    </a>
    <a class="kpi" href="#gl-table">
        <div class="lbl">{{ __('gl.liabilities') }} + {{ __('gl.equity') }}</div>
        <div class="val">{{ $fmt($data['total_liabilities_equity']) }} {{ __('common.currency') }}</div>
        <div class="sub2">{{ __('gl.retained_earnings') }}: {{ $fmt($data['retained']) }}</div>
    </a>
    <a class="kpi" href="{{ route('gl.trial_balance', ['to' => $asOf->toDateString()]) }}">
        <div class="lbl">{{ __('gl.balanced') }}</div>
        <div class="val {{ $data['balanced'] ? '' : 'neg' }}">{{ $data['balanced'] ? '✓' : '✕' }}</div>
        <div class="sub2">{{ $data['balanced'] ? __('gl.balanced') : __('gl.not_balanced') }}</div>
    </a>
</div>

<div class="card" id="gl-table">
    <h3>🏛️ {{ __('gl.balance_sheet') }}</h3>

    <form method="GET" class="searchbar" data-noprint>
        <label class="fl"><span>{{ __('gl.as_of') }}</span>
            <input type="date" name="to" value="{{ $asOf->toDateString() }}" onchange="this.form.submit()"></label>
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

            <tr><td colspan="3" style="text-align:start"><b>{{ __('gl.assets') }}</b></td></tr>
            @foreach ($data['assets'] as $r)
                <tr>
                    <td dir="ltr">{{ $r['account']->code }}</td>
                    <td style="text-align:start">
                        <a href="{{ route('gl.accounts.show', ['account' => $r['account']->id, 'to' => $asOf->toDateString()]) }}">
                            {{ $r['account']->displayName() }}
                        </a>
                    </td>
                    <td class="num">{{ $fmt($r['amount']) }}</td>
                </tr>
            @endforeach
            <tr>
                <td colspan="2" style="text-align:start"><b>{{ __('gl.assets') }}</b></td>
                <td class="num"><b>{{ $fmt($data['total_assets']) }}</b></td>
            </tr>

            <tr><td colspan="3" style="text-align:start"><b>{{ __('gl.liabilities') }}</b></td></tr>
            @foreach ($data['liabilities'] as $r)
                <tr>
                    <td dir="ltr">{{ $r['account']->code }}</td>
                    <td style="text-align:start">
                        <a href="{{ route('gl.accounts.show', ['account' => $r['account']->id, 'to' => $asOf->toDateString()]) }}">
                            {{ $r['account']->displayName() }}
                        </a>
                    </td>
                    <td class="num">{{ $fmt($r['amount']) }}</td>
                </tr>
            @endforeach

            <tr><td colspan="3" style="text-align:start"><b>{{ __('gl.equity') }}</b></td></tr>
            @foreach ($data['equity'] as $r)
                <tr>
                    <td dir="ltr">{{ $r['account']->code }}</td>
                    <td style="text-align:start">
                        <a href="{{ route('gl.accounts.show', ['account' => $r['account']->id, 'to' => $asOf->toDateString()]) }}">
                            {{ $r['account']->displayName() }}
                        </a>
                    </td>
                    <td class="num">{{ $fmt($r['amount']) }}</td>
                </tr>
            @endforeach
            <tr>
                <td>—</td>
                <td style="text-align:start">{{ __('gl.retained_earnings') }}</td>
                <td class="num">{{ $fmt($data['retained']) }}</td>
            </tr>
            <tr>
                <td colspan="2" style="text-align:start"><b>{{ __('gl.liabilities') }} + {{ __('gl.equity') }}</b></td>
                <td class="num"><b>{{ $fmt($data['total_liabilities_equity']) }}</b></td>
            </tr>
        </table>
    </div>
</div>

@endsection
