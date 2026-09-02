@extends('layouts.system')

@section('title', __('online.invoice_title').' #'.$order->number)

@php
    $money = fn ($v) => number_format((float) $v, 2);
    $co = $header;
@endphp

@section('content')

@include('partials._doc_style')

<div style="display:flex;gap:8px;margin-bottom:12px" class="no-print">
    <button class="btn gold" onclick="window.print()">🖨 {{ __('ops.print') }}</button>
    <a class="btn" href="{{ route('online.prep') }}">← {{ __('online.prep_title') }}</a>
</div>

<div class="doc has-bolt">
    {!! file_exists(public_path('brand/bolt-watermark.svg'))
        ? '<img src="'.asset('brand/bolt-watermark.svg').'" class="bolt-mark" alt="">' : '' !!}

    {{-- ═══ الترويسة — نفس نظام مستندات المنصة (هيدر أبيض + خط التدرج) ═══ --}}
    <div class="doc-head" style="background:#fff;color:var(--ink,#0A0A0F);padding:18px 24px">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;width:100%">
            <div style="display:flex;flex-direction:column;gap:4px;align-items:flex-start">
                <img src="{{ file_exists(public_path('brand/logo/logo-h-blue.svg'))
                    ? asset('brand/logo/logo-h-blue.svg') : asset('img/promax-logo.png') }}"
                     alt="PROMAX" class="doc-logo">
                <div style="font-size:12px;font-weight:900">{{ $co['name'] ?: 'PROMAX' }}</div>
            </div>
            <div style="text-align:center;flex:1">
                <div style="font-size:26px;font-weight:900">{{ __('online.invoice_title') }}</div>
                <div style="font-size:12px;color:var(--muted)">{{ __('online.invoice_sub') }}</div>
            </div>
            <div style="text-align:end">
                <div style="font-size:20px;font-weight:900;color:var(--royal-blue,#12399B)" dir="ltr">#{{ $order->number }}</div>
                <div style="font-size:11px;color:var(--muted)" dir="ltr">{{ now()->format('Y-m-d H:i') }}</div>
            </div>
        </div>
    </div>
    <div style="height:4px;background:var(--brand-gradient, linear-gradient(135deg,#12399B,#602D90))"></div>

    <div class="doc-body" style="padding:18px 24px">

        {{-- ═══ بيانات العميل ═══ --}}
        <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;
                    background:var(--blue-050,#E8F1FF);border:1px solid var(--royal-blue,#12399B);
                    border-radius:12px;padding:12px 15px;margin-bottom:14px">
            <div>
                <div style="font-size:10.5px;color:var(--muted)">{{ __('common.name') }}</div>
                <div style="font-size:15px;font-weight:900">{{ $order->customer_name ?: '—' }}</div>
            </div>
            <div>
                <div style="font-size:10.5px;color:var(--muted)">{{ __('common.phone') }}</div>
                <div style="font-size:15px;font-weight:900" dir="ltr">{{ $order->phone ?: '—' }}</div>
            </div>
            <div style="max-width:340px">
                <div style="font-size:10.5px;color:var(--muted)">{{ __('online.address') }}</div>
                <div style="font-size:13px;font-weight:700">{{ $order->area ? $order->area.' — ' : '' }}{{ $order->address ?: '—' }}</div>
            </div>
        </div>

        {{-- ═══ الباركود في النص — المسدس بيقراه pro{{ $order->number }} ═══ --}}
        <div style="text-align:center;margin:14px 0">
            {!! $barcode !!}
            <div style="font-family:monospace;font-size:14px;font-weight:900;letter-spacing:2px" dir="ltr">
                {{ $order->barcode() }}</div>
        </div>

        {{-- ═══ البنود ═══ --}}
        <table style="width:100%;border-collapse:collapse;margin-bottom:12px">
            <tr style="background:var(--blue-050,#E8F1FF)">
                <th style="padding:7px 10px;text-align:start;font-size:12px">{{ __('stock.product') }}</th>
                <th style="padding:7px 10px;font-size:12px" class="num">{{ __('common.qty') }}</th>
                <th style="padding:7px 10px;font-size:12px" class="num">{{ __('common.price') }}</th>
                <th style="padding:7px 10px;font-size:12px" class="num">{{ __('common.total') }}</th>
            </tr>
            @foreach ($order->items as $i)
                <tr style="border-bottom:1px solid var(--border)">
                    <td style="padding:7px 10px;font-size:12.5px">{{ $i->product?->displayName() ?? $i->title }}</td>
                    <td style="padding:7px 10px;text-align:center" class="num">{{ $i->qty }}</td>
                    <td style="padding:7px 10px;text-align:center" class="num">{{ $money($i->price) }}</td>
                    <td style="padding:7px 10px;text-align:center" class="num"><b>{{ $money($i->total) }}</b></td>
                </tr>
            @endforeach
        </table>

        {{-- ═══ الإجماليات ═══ --}}
        <div style="display:flex;justify-content:flex-end">
            <table style="min-width:260px;border-collapse:collapse">
                <tr>
                    <td style="padding:4px 10px;font-size:12.5px">{{ __('online.amount') }}</td>
                    <td style="padding:4px 10px;text-align:end" class="num">{{ $money($order->subtotal) }}</td>
                </tr>
                <tr>
                    <td style="padding:4px 10px;font-size:12.5px">{{ __('online.shipping') }}</td>
                    <td style="padding:4px 10px;text-align:end" class="num">{{ $money($order->shipping) }}</td>
                </tr>
                <tr style="border-top:2px solid var(--royal-blue,#12399B)">
                    <td style="padding:6px 10px;font-weight:900">{{ __('online.cod_total') }}</td>
                    <td style="padding:6px 10px;text-align:end;font-weight:900;font-size:16px" class="num">
                        {{ $money($order->total) }}</td>
                </tr>
            </table>
        </div>
    </div>

    <div class="doc-foot" style="padding:10px 24px;border-top:1px solid var(--border);font-size:10.5px;color:var(--muted);display:flex;justify-content:space-between;flex-wrap:wrap;gap:6px">
        <span>{{ $co['name'] }} @if ($co['phone']) · 📞 <span dir="ltr">{{ $co['phone'] }}</span> @endif</span>
        <span>{{ $co['address'] }}</span>
    </div>
</div>

@endsection
