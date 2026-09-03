@extends('layouts.system')

@section('title', __('online.collections_title'))

@php
    $money = fn ($v) => number_format((float) $v, 2);
    $canCollect = in_array(auth()->user()->role, ['admin', 'manager', 'accountant'], true);
@endphp

@section('content')

@if ($errors->any())
    <div class="alert" style="margin-bottom:12px">{{ $errors->first() }}</div>
@endif
@if (session('ok'))
    <div class="alert good" style="margin-bottom:12px">{{ session('ok') }}</div>
@endif

<div class="kpis">
    <div class="kpi"><b class="num neg">{{ $money($outstanding) }}</b><span>{{ __('online.k_outstanding') }}</span></div>
</div>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:10px">
        <h3 style="margin:0">💰 {{ __('online.collections_title') }}</h3>
        <form method="GET" class="searchbar" style="margin:0">
            <input name="search" value="{{ request('search') }}" placeholder="🔎 {{ __('common.search') }}">
        </form>
    </div>
    <div class="dash-hint" style="margin-bottom:10px">{{ __('online.collections_hint') }}</div>

    <div class="tablewrap frz" style="max-height:68vh;overflow:auto">
        <table>
            <tr>
                <th>{{ __('online.shopify_no') }}</th>
                <th>{{ __('online.pickup_no') }}</th>
                <th>{{ __('online.courier') }}</th>
                <th>{{ __('common.name') }}</th>
                <th>{{ __('common.phone') }}</th>
                {{-- فصل الفلوس (٤/٩): بضاعة + شحن = إجمالي --}}
                <th class="num">{{ __('online.goods_amount') }}</th>
                <th class="num">{{ __('online.shipping') }}</th>
                <th class="num">{{ __('common.total') }}</th>
                <th class="num">{{ __('online.collected') }}</th>
                <th class="num">{{ __('online.remaining') }}</th>
                <th>{{ __('online.shipped_at') }}</th>
                <th></th>
            </tr>
            @forelse ($orders as $o)
                <tr>
                    <td class="num s"><b>#{{ $o->number }}</b></td>
                    <td class="num s">
                        @if ($o->pickup)
                            <a href="{{ route('online.pickup', $o->pickup) }}"
                               style="font-weight:900;color:var(--royal-blue)">{{ $o->pickup->number }}</a>
                        @else
                            —
                        @endif
                    </td>
                    <td class="s">{{ $o->pickup?->courier?->name ?: '—' }}</td>
                    <td>{{ $o->customer_name ?: '—' }}</td>
                    <td class="num s" dir="ltr">{{ $o->phone ?: '—' }}</td>
                    <td class="num">{{ $money($o->subtotal) }}</td>
                    <td class="num">{{ $money($o->shipping) }}</td>
                    <td class="num"><b>{{ $money($o->total) }}</b></td>
                    <td class="num pos">{{ $money($o->collected_total) }}</td>
                    <td class="num neg">{{ $money($o->remaining()) }}</td>
                    <td class="s">{{ $o->shipped_at?->format('d/m h:i A') ?: '—' }}</td>
                    <td class="num">
                        @if ($canCollect)
                            <button class="btn sm green" type="button"
                                    onclick="openCollect({{ $o->id }}, '{{ $o->number }}', {{ $o->remaining() }})">
                                💰 {{ __('online.act_collect') }}</button>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="12" style="text-align:center;color:var(--muted);padding:28px">
                    {{ __('online.collections_empty') }}
                </td></tr>
            @endforelse
        </table>
    </div>

    @include('partials._pagination', ['p' => $orders])
</div>

<dialog id="dlgCollect">
    <form class="dlg" method="POST" id="formCollect">
        @csrf
        <h4>💰 {{ __('online.collect_title') }} <span id="clNum"></span></h4>
        <label class="f">{{ __('online.collect_amount') }}</label>
        <input type="number" step="0.01" min="0.01" name="amount" id="clAmount" required
               style="width:100%;margin-bottom:6px">
        <div class="dash-hint" style="margin-bottom:12px" id="clHint"></div>
        <div style="display:flex;gap:8px;justify-content:flex-end">
            <button class="btn" type="button" onclick="closeDlg('dlgCollect')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">💰 {{ __('online.act_collect') }}</button>
        </div>
    </form>
</dialog>

@endsection

@section('scripts')
<style>
    .frz th{position:sticky;top:0;z-index:2}
</style>
<script>
    const COLLECT_REMAIN = @js(__('online.collect_remaining'));
    const BASE_URL = @js(url('erp/online/orders'));

    function openCollect(id, num, remaining) {
        document.getElementById('formCollect').action = BASE_URL + '/' + id + '/collect';
        document.getElementById('clNum').textContent = '#' + num;
        var a = document.getElementById('clAmount');
        a.value = remaining.toFixed(2);
        a.max = remaining.toFixed(2);
        document.getElementById('clHint').textContent = COLLECT_REMAIN.replace(':v', remaining.toFixed(2));
        openDlg('dlgCollect');
    }
</script>
@endsection
