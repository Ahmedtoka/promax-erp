@extends('layouts.system')

@section('title', $r->number)

@php
    $money = fn ($n) => number_format((float) $n, 2);
@endphp

@section('actions')
    <a class="btn" href="{{ route('erp.purchasing.returns') }}">← {{ __('supplier.supplier_returns') }}</a>
    <button class="btn gold" type="button" onclick="window.print()">🖨️ {{ __('supplier.return_print') }}</button>
@endsection

@section('content')

<div class="card">
    <h3>↩️ {{ __('supplier.return_doc') }} {{ $r->number }}
        <span class="side">{{ $r->returned_on->format('Y-m-d') }}</span>
    </h3>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
        <span class="badge b-gray">{{ __('supplier.supplier') }}: <b>{{ $r->supplier?->displayName() ?? '—' }}</b></span>
        @if ($r->order)
            <a class="badge b-blue" href="{{ route('erp.purchasing.show', $r->order) }}">{{ __('supplier.purchase_orders') }}: {{ $r->order->number }}</a>
            <span class="badge b-gray">{{ __('stock.warehouse') }}: {{ $r->order->warehouse?->displayName() ?? '—' }}</span>
        @endif
        @if ($r->receipt)
            <a class="badge b-blue" href="{{ route('wh.receipt', $r->receipt) }}">{{ __('supplier.return_receipt') }}: {{ $r->receipt->number }}</a>
        @endif
        <span class="badge b-gold">{{ __('supplier.return_total') }}: {{ $money($r->total) }}</span>
    </div>

    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('stock.product') }}</th>
                <th class="num">{{ __('common.qty') }}</th>
                <th class="num" data-nosum>{{ __('supplier.unit_cost') }}</th>
                <th class="num">{{ __('common.total') }}</th>
                <th>{{ __('supplier.return_note') }}</th>
                <th data-nosum>{{ __('supplier.return_photo') }}</th>
            </tr>
            @foreach ($r->items as $it)
                <tr>
                    <td><b>{{ $it->product?->displayName() }}</b>
                        <span class="s" style="color:var(--muted)">· {{ $it->product?->code }}</span></td>
                    <td class="num">{{ number_format($it->qty) }}</td>
                    <td class="num">{{ $money($it->unit_cost) }}</td>
                    <td class="num">{{ $money($it->lineTotal()) }}</td>
                    <td>{{ $it->note ?: '—' }}</td>
                    <td>
                        @if ($it->photo_path)
                            <a target="_blank" rel="noopener" href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($it->photo_path) }}">
                                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($it->photo_path) }}" alt="" style="max-height:64px;border-radius:6px">
                            </a>
                        @else — @endif
                    </td>
                </tr>
            @endforeach
        </table>
    </div>
</div>

@endsection
