@extends('layouts.system')

@section('title', __('online.invoice_title').' #'.$order->number)

@php
    $money = fn ($v) => number_format((float) $v, 2);
    $co = $header;

    // فاتورة الأونلاين **إنجليزي دايماً** (قرار المالك ٤/٩) مهما كانت
    // لغة الداشبورد — النصوص من lang/en عبر باراميتر اللوكيل
    $t = fn (string $k) => __('online.'.$k, [], 'en');

    // المحافظة والمنطقة: المخزنة "city - province" من شوبيفاي
    $areaParts = array_map('trim', explode(' - ', (string) $order->area, 2));
    $city = $areaParts[0] ?? '';
    $gov = $areaParts[1] ?? '';
@endphp

@section('content')

@include('partials._doc_style')

{{-- ═══ ريسيت 80mm — أبيض وأسود بالكامل، إنجليزي بالكامل، من غير
     أي أيقونات ملونة (قرار المالك ٤/٩). Poppins زي الهوية. ═══ --}}
<style>
    .rcpt{
        width:302px;margin:0 auto;background:#fff;color:#000;
        font-family:Poppins, Zagma, sans-serif;font-size:12px;line-height:1.5;
        padding:16px 14px;border:1px solid var(--border);border-radius:8px;
        direction:ltr;text-align:left;
    }
    .rcpt *{color:#000}
    .rcpt .c{text-align:center}
    .rcpt .dash{border-top:1.5px dashed #000;margin:9px 0}
    .rcpt .logo{height:34px;width:auto;filter:grayscale(1) contrast(1.2);display:block;margin:0 auto}
    .rcpt .inv-no{font-size:17px;font-weight:800;letter-spacing:.5px}

    /* سطور البيانات: ليبل ثابت وقيمة بتلف من غير ما تخرج */
    .rcpt .kv{display:flex;gap:6px;font-size:11.5px;margin:1px 0}
    .rcpt .kv b{flex-shrink:0;font-weight:700}
    .rcpt .kv span{word-break:break-word;min-width:0}
    .rcpt .kv2{display:flex;gap:14px}
    .rcpt .kv2 .kv{flex:1;margin:0}

    /* جدول الأصناف — شكل فاتورة: رأس بخط علوي وسفلي، أعمدة مظبوطة */
    .rcpt table{width:100%;border-collapse:collapse;table-layout:fixed}
    {{-- ⚠️ background صريحة — ستايل الجداول العام في اللاي أوت بيلوّن
         الهيدر أزرق، والريسيت أبيض وأسود (قرار المالك ٥/٩: #ededed) --}}
    .rcpt th{
        font-size:10.5px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;
        background:#ededed !important;color:#000 !important;
        border-top:1.5px solid #000;border-bottom:1.5px solid #000;padding:4px 3px;
    }
    .rcpt td{
        font-size:11.5px;padding:4px 3px;vertical-align:top;
        border-bottom:1px solid #ddd;word-break:break-word;white-space:normal;
    }
    .rcpt .col-item{width:56%;text-align:left}
    .rcpt .col-qty{width:12%;text-align:center}
    .rcpt .col-price{width:32%;text-align:right;white-space:nowrap}

    .rcpt .tot{display:flex;justify-content:space-between;font-size:12px;margin:2px 0}
    {{-- فاصل منقّط بين الشحن والإجمالي + الإجمالي بخط كبير (٥/٩) --}}
    .rcpt .tot.big{
        font-size:18px;font-weight:900;
        border-top:2px dotted #000;padding-top:6px;margin-top:6px;
    }

    .rcpt svg{max-width:100%;height:48px}
    .rcpt .qr{width:86px;height:86px;display:block;margin:6px auto 2px}
    .rcpt .foot{font-size:10.5px;line-height:1.6}

    @media print{
        @page{size:80mm auto;margin:0}
        body{background:#fff !important}
        .rcpt{width:72mm;border:0;border-radius:0;padding:3mm 2mm;margin:0 auto}
        {{-- زراير الطباعة والرجوع ماينزلوش على الورقة (٥/٩) --}}
        .no-print{display:none !important}
    }
</style>

<div style="display:flex;gap:8px;margin-bottom:12px;justify-content:center" class="no-print">
    <button class="btn gold" onclick="window.print()">{{ __('ops.print') }}</button>
    <a class="btn" href="{{ route('online.prep') }}">{{ __('online.prep_title') }}</a>
</div>

<div class="rcpt">
    {{-- ═══ ١) اللوجو في النص ═══ --}}
    <div class="c">
        <img src="{{ file_exists(public_path('brand/logo/logo-h-blue.svg'))
            ? asset('brand/logo/logo-h-blue.svg') : asset('img/promax-logo.png') }}"
             alt="PROMAX" class="logo">
    </div>

    {{-- ═══ ٢) الباركود، وبعده INVOICE بين خطين منقطين (٥/٩) —
         نص pro123 اتشال، المسدس بيقرا الباركود نفسه ═══ --}}
    <div class="c" style="margin-top:8px">
        {!! $barcode !!}
    </div>

    <div class="dash"></div>
    <div class="c inv-no">INVOICE #{{ $order->number }}</div>
    <div class="dash"></div>

    {{-- ═══ ٣) البيانات ═══ --}}
    <div class="kv"><b>{{ $t('rcpt_date') }}:</b><span>{{ now()->format('d/m/Y h:i A') }}</span></div>
    <div class="kv"><b>{{ $t('rcpt_order') }}:</b><span>#{{ $order->number }}</span></div>
    <div class="kv"><b>{{ $t('rcpt_customer') }}:</b><span>{{ $order->customer_name ?: '-' }}</span></div>
    <div class="kv"><b>{{ $t('rcpt_mobile') }}:</b><span>{{ $order->phone ?: '-' }}</span></div>
    <div class="kv2">
        <div class="kv"><b>{{ $t('rcpt_gov') }}:</b><span>{{ $gov ?: '-' }}</span></div>
        <div class="kv"><b>{{ $t('rcpt_area') }}:</b><span>{{ $city ?: '-' }}</span></div>
    </div>
    <div class="kv"><b>{{ $t('rcpt_addr') }}:</b><span>{{ $order->address ?: '-' }}</span></div>

    {{-- ═══ ٤) جدول الأصناف — Product / Qty / Price (من غير فاصل
         قبله — الفاصل اتنقل حوالين INVOICE فوق، قرار ٥/٩) ═══ --}}
    <table style="margin-top:8px">
        <tr>
            <th class="col-item">{{ $t('rcpt_product') }}</th>
            <th class="col-qty">{{ $t('rcpt_qty') }}</th>
            <th class="col-price">{{ $t('rcpt_price') }}</th>
        </tr>
        @foreach ($order->items as $i)
            <tr>
                {{-- الاسم الإنجليزي مباشرة — الريسيت إنجليزي مهما كان لوكيل الداشبورد --}}
                <td class="col-item">{{ $i->product?->name_en ?: ($i->product?->name ?? $i->title) }}
                    @if ((int) $i->units_per > 1)
                        <span style="font-size:9.5px">({{ $i->pieces() }} pcs)</span>
                    @endif
                </td>
                <td class="col-qty">{{ $i->qty }}</td>
                <td class="col-price">{{ $money($i->total) }}</td>
            </tr>
        @endforeach
    </table>

    {{-- ═══ ٥) الإجماليات ═══ --}}
    <div style="margin-top:8px">
        <div class="tot"><span>{{ $t('rcpt_subtotal') }}</span><span>{{ $money($order->subtotal) }}</span></div>
        <div class="tot"><span>{{ $t('rcpt_shipping') }}</span><span>{{ $money($order->shipping) }}</span></div>
        <div class="tot big"><span>{{ $t('rcpt_total_due') }}</span><span>{{ $money($order->total) }} EGP</span></div>
    </div>

    <div class="dash"></div>

    {{-- ═══ ٦) الفوتر: شكر + التليفون كبير + العنوان أصغر + QR ═══ --}}
    <div class="c foot">
        <div style="font-weight:800;font-size:11.5px">{{ $t('rcpt_thank_en') }}</div>
        @if ($co['phone'])
            <div style="font-size:15px;font-weight:900;letter-spacing:.5px">{{ $co['phone'] }}</div>
        @endif
        <div style="font-size:9.5px">{{ $t('rcpt_addr_en') }}</div>
        <img src="{{ asset('brand/qr-promax-market.svg') }}" alt="promax.market" class="qr">
        <div style="font-weight:700">promax.market</div>
    </div>
</div>

@endsection

@section('scripts')
@if (request()->boolean('autoback'))
<script>
    /* ═══ وضع المراجعة (٥/٩): الفاتورة بتطبع لوحدها، وبعد الطباعة
       بثانيتين بترجع لشاشة التجهيز — وفولباك ١٥ ثانية لو حوار
       الطباعة اتقفل من غير ما يبلّغ ═══ */
    (function () {
        'use strict';

        var BACK = @js(route('online.prep'));
        var went = false;

        function goBack() {
            if (went) return;
            went = true;
            location.href = BACK;
        }

        window.addEventListener('afterprint', function () {
            setTimeout(goBack, 2000);
        });

        setTimeout(function () { window.print(); }, 500);
        setTimeout(goBack, 15000);
    })();
</script>
@endif
@endsection
