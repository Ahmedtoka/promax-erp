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
    <div class="kpi">
        <div class="lbl">{{ __('gl.opening') }}</div>
        <div class="val">{{ $fmt($statement['opening']) }} {{ __('common.currency') }}</div>
        <div class="sub2">{{ $range->fromValue() }} → {{ $range->toValue() }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('gl.closing') }}</div>
        <div class="val">{{ $fmt($statement['closing']) }} {{ __('common.currency') }}</div>
        <div class="sub2">{{ __('gl.normal_'.$account->normal_side) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('gl.lines_count') }}</div>
        <div class="val">{{ number_format(count($statement['rows'])) }}</div>
        <div class="sub2">{{ __('gl.type_'.$account->type) }}</div>
    </div>
</div>

<div class="card">
    <h3>📄 {{ __('gl.statement') }} <span class="side">{{ $account->code }} · {{ $account->displayName() }}</span></h3>

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
                    <td><b>{{ $r['entry']?->number }}</b></td>
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
