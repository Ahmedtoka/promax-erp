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
    <div class="kpi">
        <div class="lbl">{{ __('gl.assets') }}</div>
        <div class="val">{{ $fmt($data['total_assets']) }} {{ __('common.currency') }}</div>
        <div class="sub2">{{ $asOf->format('Y-m-d') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('gl.liabilities') }} + {{ __('gl.equity') }}</div>
        <div class="val">{{ $fmt($data['total_liabilities_equity']) }} {{ __('common.currency') }}</div>
        <div class="sub2">{{ __('gl.retained_earnings') }}: {{ $fmt($data['retained']) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('gl.balanced') }}</div>
        <div class="val {{ $data['balanced'] ? '' : 'neg' }}">{{ $data['balanced'] ? '✓' : '✕' }}</div>
        <div class="sub2">{{ $data['balanced'] ? __('gl.balanced') : __('gl.not_balanced') }}</div>
    </div>
</div>

<div class="card">
    <h3>🏛️ {{ __('gl.balance_sheet') }}</h3>

    <form method="GET" class="frow" style="margin-bottom:12px" data-noprint>
        <div>
            <label class="f">{{ __('gl.as_of') }}</label>
            <input type="date" name="to" value="{{ $asOf->toDateString() }}" onchange="this.form.submit()">
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
