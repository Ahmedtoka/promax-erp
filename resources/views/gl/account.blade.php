@extends('layouts.system')

{{--
    كشف حساب واحد (١٢/٩/٢٠٢٦) — رصيد افتتاحي، وكل سطر بتاريخه ورقم
    قيده ومستنده، والرصيد الجاري بعد كل سطر. الحساب المجموعة بيلمّ
    أحفاده كلهم في نفس الكشف.
--}}

@section('title', $account->code.' · '.$account->displayName())

@php
    $fmt = fn ($n) => number_format((float) $n, 2);
@endphp

@section('actions')
    <a class="btn" href="{{ route('gl.accounts.show', array_merge(['account' => $account->id], $range->query(), ['export' => 1])) }}">
        📊 {{ __('common.export_screen') }}
    </a>
    <a class="btn" href="{{ route('gl.accounts') }}">🌳 {{ __('gl.accounts') }}</a>
@endsection

@section('content')

<div class="kpis">
    {{-- (٢٢/٩) الرصيدين مفرودين في الكشف تحت، وعدد الحركات بيفتح قيود الحساب ده في اليومية --}}
    <a class="kpi" href="#gl-table">
        <div class="lbl">{{ __('gl.opening') }}</div>
        <div class="val">{{ $fmt($statement['opening']) }} {{ __('common.currency') }}</div>
        <div class="sub2">{{ $range->fromValue() }} → {{ $range->toValue() }}</div>
    </a>
    <a class="kpi" href="#gl-table">
        <div class="lbl">{{ __('gl.closing') }}</div>
        <div class="val">{{ $fmt($statement['closing']) }} {{ __('common.currency') }}</div>
        <div class="sub2">{{ __('gl.normal_'.$account->normal_side) }}</div>
    </a>
    <a class="kpi" href="{{ route('gl.entries', ['account' => $account->id] + ['from' => $range->fromValue(), 'to' => $range->toValue()]) }}">
        <div class="lbl">{{ __('gl.lines_count') }}</div>
        <div class="val">{{ number_format(count($statement['rows'])) }}</div>
        <div class="sub2">{{ __('gl.type_'.$account->type) }}</div>
    </a>
</div>

<div class="card" id="gl-table">
    <h3>📄 {{ __('gl.statement') }} <span class="side">{{ $account->code }} · {{ $account->displayName() }}</span></h3>

    <form method="GET" class="searchbar" data-noprint>
        {{-- ⚠️ `all => false`: الفترة الفاضية هنا = الشهر الحالي (`DateRange` month) مش «كل الفترات» --}}
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue(), 'auto' => true, 'all' => false])
        <button class="btn gold" type="submit">{{ __('common.filter') }}</button>
        <a class="btn" href="{{ route('gl.accounts.show', $account) }}">{{ __('common.clear') }}</a>
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('common.date') }}</th>
                {{-- ⚠️ data-nosum — رقم القيد والكود نصوص مش مبالغ --}}
                <th data-nosum>{{ __('gl.number') }}</th>
                <th style="text-align:start">{{ __('gl.memo') }}</th>
                <th data-nosum>{{ __('gl.account') }}</th>
                <th class="num">{{ __('gl.debit') }}</th>
                <th class="num">{{ __('gl.credit') }}</th>
                <th class="num" data-nosum>{{ __('gl.running') }}</th>
            </tr>
            <tr>
                <td colspan="6" style="text-align:start;color:var(--muted)">{{ __('gl.opening') }}</td>
                <td class="num"><b>{{ $fmt($statement['opening']) }}</b></td>
            </tr>
            @forelse ($statement['rows'] as $r)
                <tr>
                    <td class="num" style="font-size:11px">{{ $r['entry']?->date?->format('Y-m-d') }}</td>
                    <td>@if ($r['entry'])<a href="{{ route('gl.entries', ['q' => $r['entry']->number, 'from' => $r['entry']->date?->toDateString(), 'to' => $r['entry']->date?->toDateString()]) }}"><b>{{ $r['entry']->number }}</b></a>@endif</td>
                    <td style="text-align:start">
                        {{ $r['entry']?->memo }}
                        @if ($r['line']->overridden)
                            <span class="badge b-orange">{{ __('gl.override') }}</span>
                        @endif
                    </td>
                    <td style="font-size:11px" dir="ltr">{{ $r['line']->account?->code }}</td>
                    <td class="num">{{ $fmt($r['line']->debit) }}</td>
                    <td class="num">{{ $fmt($r['line']->credit) }}</td>
                    <td class="num"><b>{{ $fmt($r['running']) }}</b></td>
                </tr>
            @empty
                <tr><td colspan="7" style="color:var(--muted);font-size:12px">{{ __('gl.no_lines') }}</td></tr>
            @endforelse
            <tr>
                <td colspan="6" style="text-align:start"><b>{{ __('gl.closing') }}</b></td>
                <td class="num"><b>{{ $fmt($statement['closing']) }}</b></td>
            </tr>
        </table>
    </div>
</div>

@endsection
