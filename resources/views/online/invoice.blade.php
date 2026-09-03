@extends('layouts.system')

@section('title', __('online.invoice_title').' #'.$order->number)

@php
    $money = fn ($v) => number_format((float) $v, 2);
    $co = $header;
@endphp

@section('content')

@include('partials._doc_style')

{{-- ═══ فاتورة ريسيت حراري 80mm (قرار المالك ٤/٩ — زي ريسيت المطاعم)
     مش A4: بتتحط جوه الشحنة وبتتقري بالمسدس. الطباعة بعرض بكرة
     الريسيت والطول على قد المحتوى. ═══ --}}
<style>
    .rcpt{
        width:302px;              /* ≈ 80mm على 96dpi */
        margin:0 auto;background:#fff;color:#000;
        font-family:'Cairo', monospace;font-size:12.5px;line-height:1.55;
        padding:14px 10px;border:1px solid var(--border);border-radius:8px;
    }
    .rcpt .c{text-align:center}
    .rcpt .dash{border-top:1.5px dashed #000;margin:8px 0}
    .rcpt .row{display:flex;justify-content:space-between;gap:8px}
    .rcpt .row span:last-child{white-space:nowrap}
    .rcpt .big{font-size:15px;font-weight:900}
    .rcpt .logo{height:40px;width:auto}
    .rcpt table{width:100%;border-collapse:collapse}
    .rcpt td{padding:1.5px 0;vertical-align:top;font-size:12.5px}
    .rcpt .qty{white-space:nowrap;padding-inline-end:6px}
    .rcpt .amt{text-align:end;white-space:nowrap;direction:ltr}
    .rcpt svg{max-width:100%;height:52px}

    @media print{
        @page{size:80mm auto;margin:0}
        body{background:#fff !important}
        .rcpt{
            width:72mm;border:0;border-radius:0;padding:2mm 1mm;margin:0 auto;
        }
    }
</style>

<div style="display:flex;gap:8px;margin-bottom:12px;justify-content:center" class="no-print">
    <button class="btn gold" onclick="window.print()">🖨 {{ __('ops.print') }}</button>
    <a class="btn" href="{{ route('online.prep') }}">← {{ __('online.prep_title') }}</a>
</div>

<div class="rcpt">
    {{-- ═══ الهيدر: اللوجو والشركة في النص ═══ --}}
    <div class="c">
        <img src="{{ file_exists(public_path('brand/logo/logo-h-blue.svg'))
            ? asset('brand/logo/logo-h-blue.svg') : asset('img/promax-logo.png') }}"
             alt="PROMAX" class="logo">
        <div style="font-weight:900;font-size:14px;margin-top:2px">{{ $co['name'] ?: 'PROMAX' }}</div>
        @if ($co['address'])
            <div style="font-size:10.5px">{{ $co['address'] }}</div>
        @endif
        @if ($co['tax_id'])
            <div style="font-size:10.5px">{{ __('doc.tax_id') }}: <b>{{ $co['tax_id'] }}</b></div>
        @endif
    </div>

    <div class="dash"></div>

    {{-- ═══ الأوردر والوقت ═══ --}}
    <div class="row">
        <span style="font-weight:900">{{ __('online.invoice_title') }}</span>
        <span class="big" dir="ltr">#{{ $order->number }}</span>
    </div>
    <div class="row" style="font-size:11px">
        <span>{{ __('online.invoice_sub') }}</span>
        <span dir="ltr">{{ now()->format('d/m/Y h:i A') }}</span>
    </div>

    <div class="dash"></div>

    {{-- ═══ العميل ═══ --}}
    <div style="font-size:12px">
        <div><b>{{ $order->customer_name ?: '—' }}</b></div>
        <div dir="ltr" style="text-align:start">📞 {{ $order->phone ?: '—' }}</div>
        <div>📍 {{ $order->area ? $order->area.' — ' : '' }}{{ $order->address ?: '—' }}</div>
    </div>

    <div class="dash"></div>

    {{-- ═══ البنود: كمية × صنف .... إجمالي ═══ --}}
    <table>
        @foreach ($order->items as $i)
            <tr>
                <td class="qty"><b>{{ $i->qty }}</b> ×</td>
                <td>{{ $i->product?->displayName() ?? $i->title }}
                    @if ((int) $i->units_per > 1)
                        <span style="font-size:10px">({{ $i->pieces() }} {{ __('online.pcs') }})</span>
                    @endif
                </td>
                <td class="amt">{{ $money($i->total) }}</td>
            </tr>
        @endforeach
    </table>

    <div class="dash"></div>

    {{-- ═══ الإجماليات ═══ --}}
    <div class="row"><span>{{ __('online.amount') }}</span><span>{{ $money($order->subtotal) }}</span></div>
    <div class="row"><span>{{ __('online.shipping') }}</span><span>{{ $money($order->shipping) }}</span></div>
    <div class="row big" style="margin-top:4px">
        <span>{{ __('online.cod_total') }}</span>
        <span>{{ $money($order->total) }}</span>
    </div>

    <div class="dash"></div>

    {{-- ═══ الباركود في النص — المسدس بيقراه ═══ --}}
    <div class="c" style="margin:6px 0">
        {!! $barcode !!}
        <div style="font-family:monospace;font-size:13px;font-weight:900;letter-spacing:2px" dir="ltr">
            {{ $order->barcode() }}</div>
    </div>

    <div class="dash"></div>

    {{-- ═══ الفوتر ═══ --}}
    <div class="c" style="font-size:11px">
        <div style="font-weight:900">{{ __('online.rcpt_thanks') }}</div>
        @if ($co['phone'])
            <div dir="ltr">{{ $co['phone'] }}</div>
        @endif
    </div>
</div>

@endsection
