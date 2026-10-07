@extends('layouts.system')

@section('title', __('online.order_title').' #'.$order->number)

@php
    $money = fn ($v) => number_format((float) $v, 2);
    // اسم الزرار اللي اتداس — نفس مفاتيح الأزرار في الشاشات
    $actionLabel = fn (?string $r) => match ($r) {
        'online.confirm' => __('online.act_confirm'),
        'online.postpone' => __('online.act_postpone'),
        'online.cancel' => __('online.act_cancel'),
        'online.collect' => __('online.act_collect'),
        'online.return' => __('online.act_return'),
        'online.reship' => __('online.act_reship'),
        'online.relink' => __('online.act_relink'),
        'online.item.link' => __('online.link_item'),
        'online.manualship' => __('online.act_manual_ship'),
        'online.prep.done' => __('online.prep_finish'),
        'online.prep.review' => __('online.prep_review'),
        default => (string) $r,
    };
@endphp

@section('actions')
    <a class="btn" href="{{ route('online.orders') }}">← {{ __('online.nav_orders') }}</a>
    {{-- الفاتورة زرار لوحدها (٧/١٠) — الدوس على رقم الأوردر بيفتح الصفحة دي --}}
    <a class="btn gold" href="{{ route('online.invoice', $order) }}" target="_blank">🖨 {{ __('online.order_print') }}</a>
@endsection

@section('content')

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px">
        <div>
            <h3 style="margin:0">🧾 {{ __('online.order_title') }} #{{ $order->number }}
                <span class="badge {{ $order->statusClass() }}">{{ $order->statusLabel() }}</span></h3>
            <div style="margin-top:6px">{{ $order->customer_name ?: '—' }} · <span dir="ltr">{{ $order->phone ?: '—' }}</span></div>
            <div class="dash-hint">{{ $order->area ?: '—' }}@if ($order->address) — {{ $order->address }}@endif</div>
        </div>
        <div class="dash-hint" style="text-align:end">
            @if ($order->pickup)
                {{ __('online.pickup_no') }}: <a href="{{ route('online.pickup', $order->pickup) }}" style="font-weight:900">{{ $order->pickup->number }}</a><br>
            @endif
            {{ $order->ordered_at?->format('Y-m-d h:i A') }}
        </div>
    </div>
    @if ($order->notes)
        <div class="alert info" style="margin-top:10px;white-space:pre-line"><b>{{ __('online.order_notes') }}:</b> {{ $order->notes }}</div>
    @endif
    @if ($order->cancel_reason)
        <div class="alert" style="margin-top:10px">✖ {{ $order->cancel_reason }}</div>
    @endif
</div>

<div class="kpis">
    <div class="kpi"><div class="lbl">{{ __('online.goods_amount') }}</div><div class="val">{{ $money($order->subtotal) }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('online.shipping') }}</div><div class="val">{{ $money($order->shipping) }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('common.total') }}</div><div class="val">{{ $money($order->total) }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('online.collected') }}</div><div class="val pos">{{ $money($order->collected_total) }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('online.ret_value') }}</div><div class="val mid">{{ $money($order->returned_total) }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('online.remaining') }}</div><div class="val neg">{{ $money($order->remaining()) }}</div></div>
</div>

<div class="card">
    <h3>📦 {{ __('online.order_items') }}</h3>
    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('online.shopify_product') }}</th>
                <th>{{ __('online.order_system_product') }}</th>
                <th class="num">{{ __('common.qty') }}</th>
                <th class="num" data-nosum>{{ __('online.pieces') }}</th>
                <th class="num" data-nosum>{{ __('common.price') }}</th>
                <th class="num">{{ __('common.total') }}</th>
                <th class="num">{{ __('online.order_returned_qty') }}</th>
            </tr>
            @foreach ($order->items as $i)
                <tr>
                    <td>{{ $i->title }}@if ($i->sku)<div class="dash-hint" dir="ltr">{{ $i->sku }}</div>@endif</td>
                    <td>
                        @if ($i->isBundle())
                            <span class="badge b-purple">🧩 {{ __('online.bundle') }}</span>
                            @foreach ($i->componentRows() as $c)
                                <div style="font-size:11.5px">{{ $c['units'] }} × {{ $c['product']?->displayName() ?? '—' }}</div>
                            @endforeach
                        @else
                            {{ $i->product?->displayName() ?? __('online.unlinked') }}
                        @endif
                    </td>
                    <td class="num">{{ $i->qty }}</td>
                    <td class="num">{{ $i->pieces() }}</td>
                    <td class="num">{{ $money($i->price) }}</td>
                    <td class="num">{{ $money($i->total) }}</td>
                    <td class="num">{{ (int) $i->returned_qty ?: '—' }}</td>
                </tr>
            @endforeach
        </table>
    </div>
</div>

<div class="card">
    <h3>🕒 {{ __('online.order_history') }}</h3>
    <div class="tablewrap">
        <table data-plain>
            @foreach ($events as $e)
                <tr>
                    <td class="s" style="white-space:nowrap;width:150px">{{ $e['at']->format('Y-m-d h:i A') }}</td>
                    <td><b>{{ $e['label'] }}</b>@if ($e['detail']) <span class="dash-hint">— {{ $e['detail'] }}</span>@endif</td>
                    <td class="s">{{ $e['who'] ?? '' }}</td>
                </tr>
            @endforeach
        </table>
    </div>
</div>

<div class="card">
    <h3>👤 {{ __('online.order_actions') }}</h3>
    @if ($actions->isEmpty())
        <div class="dash-hint">{{ __('online.order_no_actions') }}</div>
    @else
        <div class="tablewrap">
            <table data-plain>
                @foreach ($actions as $a)
                    <tr>
                        <td class="s" style="white-space:nowrap;width:150px">{{ $a->created_at?->format('Y-m-d h:i A') }}</td>
                        <td><b>{{ $actionLabel($a->route) }}</b></td>
                        <td class="s">{{ $a->user_name }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif
</div>

@if ($picks->isNotEmpty())
    <div class="card">
        <h3>📋 {{ __('online.order_picks') }}</h3>
        <div class="tablewrap">
            <table>
                <tr>
                    <th>#</th><th>{{ __('common.status') }}</th><th data-nosum>{{ __('common.date') }}</th>
                    <th data-nosum>{{ __('online.hist_prepared') }}</th><th class="num" data-nosum>{{ __('online.pieces') }}</th>
                </tr>
                @foreach ($picks as $p)
                    <tr>
                        <td class="num s"><b>{{ $p->number }}</b></td>
                        <td><span class="badge {{ $p->statusClass() }}">{{ $p->statusLabel() }}</span></td>
                        <td class="s">{{ $p->created_at?->format('Y-m-d h:i A') }}</td>
                        <td class="s">{{ $p->ready_at?->format('Y-m-d h:i A') ?? '—' }} {{ $p->picker?->displayName() }}</td>
                        <td class="num">{{ $p->qtyRequested() }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    </div>
@endif

@endsection
