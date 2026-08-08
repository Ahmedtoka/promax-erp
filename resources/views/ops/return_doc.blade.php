@extends('layouts.system')

@section('title', $r->number)

@php $fmt = fn ($n) => number_format((float) $n, 2); @endphp

@section('content')

<div class="card">
    <h3>📥 {{ $r->number }}
        <span class="side">{{ $r->created_at->format('Y-m-d H:i') }}</span></h3>

    <div class="frow">
        <div class="f"><span>{{ __('client.client') }}</span>
            <b>{{ $r->client?->fullName() ?? '—' }}</b></div>
        <div class="f"><span>{{ __('ops.rep') }}</span>
            <b>{{ $r->rep?->displayName() ?? __('common.office') }}</b></div>
        <div class="f"><span>{{ __('field.return_policy') }}</span>
            <span class="badge b-purple">{{ $r->policyLabel() }}</span></div>
    </div>

    <div class="kpis">
        <div class="kpi"><div class="lbl">{{ __('field.return_good_units') }}</div>
            <div class="val pos">{{ number_format($r->good_units) }}</div></div>
        <div class="kpi"><div class="lbl">{{ __('field.return_damaged_units') }}</div>
            <div class="val {{ $r->damaged_units > 0 ? 'neg' : '' }}">{{ number_format($r->damaged_units) }}</div></div>
        <div class="kpi"><div class="lbl">{{ __('common.subtotal') }}</div>
            <div class="val">{{ $fmt($r->subtotal) }}</div></div>
        <div class="kpi"><div class="lbl">{{ __('common.discount') }}</div>
            <div class="val mid">{{ $fmt($r->discount) }}</div></div>
        <div class="kpi"><div class="lbl">{{ __('tax.tax') }}</div>
            <div class="val">{{ $fmt($r->tax_total) }}</div></div>
        {{-- ⚠️ **ده الرقم اللي اتقيّد في الليدجر** — شامل الضريبة،
             زي `grand_total` بتاع الفاتورة بالظبط. --}}
        <div class="kpi"><div class="lbl">{{ __('common.total') }}</div>
            <div class="val neg"><b>{{ $fmt($r->grand_total) }}</b></div></div>
    </div>

    @if ($r->note)
        <div class="alert info">{{ $r->note }}</div>
    @endif

    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('stock.product') }}</th>
                <th>{{ __('ops.invoice') }}</th>
                <th>{{ __('common.qty') }}</th>
                <th>{{ __('field.return_cond') }}</th>
                <th>{{ __('price.unit_price') }}</th>
                <th>{{ __('common.total') }}</th>
            </tr>
            @foreach ($r->items as $it)
                <tr>
                    <td><b>{{ $it->product?->displayName() ?? '—' }}</b></td>
                    {{-- ⚠️ **الفاتورة الأصلية جنب كل بند** — دي الحاجة
                         اللي بتخلّي المراجعة ممكنة: السعر جه منين. --}}
                    <td style="color:var(--muted)">{{ $it->invoiceItem?->invoice?->number ?? '—' }}</td>
                    <td class="num">{{ number_format($it->qty) }}</td>
                    <td>
                        <span class="badge {{ $it->isDamaged() ? 'b-red' : 'b-green' }}">
                            {{ $it->conditionLabel() }}</span>
                    </td>
                    <td class="num">{{ $fmt($it->price) }}</td>
                    <td class="num"><b>{{ $fmt($it->total) }}</b></td>
                </tr>
            @endforeach
        </table>
    </div>

    @if ($r->entry)
        <div class="alert good">
            {{ __('field.return_entry_posted', ['memo' => $r->entry->memo]) }}
        </div>
    @endif
</div>

@endsection
