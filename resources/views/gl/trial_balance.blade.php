@extends('layouts.system')

{{--
    ميزان المراجعة (١٢/٩/٢٠٢٦) — كل حساب فرعي عليه حركة في النافذة:
    رصيد افتتاحي، مدين وداين الفترة، ورصيد آخر المدة. مجموع المدين
    لازم يساوي مجموع الدائن — لو مش متساويين فيه قيد غير متوازن في
    الدفتر وده لازم يتشاف فوراً.
--}}

@section('title', __('gl.trial_balance'))

@php
    $fmt = fn ($n) => number_format((float) $n, 2);
    $balanced = abs($data['totals']['debit'] - $data['totals']['credit']) < 0.005;
@endphp

@section('actions')
    <a class="btn" href="{{ route('gl.trial_balance', array_merge($range->query(), ['export' => 1])) }}">
        📊 {{ __('common.export_screen') }}
    </a>
@endsection

@section('content')

<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ __('gl.debit') }}</div>
        <div class="val">{{ $fmt($data['totals']['debit']) }} {{ __('common.currency') }}</div>
        <div class="sub2">{{ $range->fromValue() }} → {{ $range->toValue() }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('gl.credit') }}</div>
        <div class="val">{{ $fmt($data['totals']['credit']) }} {{ __('common.currency') }}</div>
        <div class="sub2">{{ __('gl.accounts_with_moves', ['n' => count($data['rows'])]) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('gl.balanced') }}</div>
        <div class="val {{ $balanced ? '' : 'neg' }}">{{ $balanced ? '✓' : '✕' }}</div>
        <div class="sub2">{{ $balanced ? __('gl.balanced') : __('gl.not_balanced') }}</div>
    </div>
</div>

<div class="card">
    <h3>⚖️ {{ __('gl.trial_balance') }} <span class="side">{{ __('gl.trial_balance_sub') }}</span></h3>

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
                <th>{{ __('gl.type') }}</th>
                <th class="num" data-nosum>{{ __('gl.opening') }}</th>
                <th class="num">{{ __('gl.debit') }}</th>
                <th class="num">{{ __('gl.credit') }}</th>
                <th class="num" data-nosum>{{ __('gl.closing') }}</th>
            </tr>
            @forelse ($data['rows'] as $r)
                <tr>
                    <td dir="ltr"><b>{{ $r['account']->code }}</b></td>
                    <td style="text-align:start">
                        <a href="{{ route('gl.accounts.show', array_merge(['account' => $r['account']->id], $range->query())) }}">
                            {{ $r['account']->displayName() }}
                        </a>
                    </td>
                    <td style="font-size:11px">{{ __('gl.type_'.$r['account']->type) }}</td>
                    <td class="num">{{ $fmt($r['opening']) }}</td>
                    <td class="num">{{ $fmt($r['debit']) }}</td>
                    <td class="num">{{ $fmt($r['credit']) }}</td>
                    <td class="num"><b>{{ $fmt($r['closing']) }}</b></td>
                </tr>
            @empty
                <tr><td colspan="7" style="color:var(--muted);font-size:12px">{{ __('gl.no_lines') }}</td></tr>
            @endforelse
            <tr>
                <td colspan="4" style="text-align:start"><b>{{ __('common.total') }}</b></td>
                <td class="num"><b>{{ $fmt($data['totals']['debit']) }}</b></td>
                <td class="num"><b>{{ $fmt($data['totals']['credit']) }}</b></td>
                <td></td>
            </tr>
        </table>
    </div>
</div>

@endsection
