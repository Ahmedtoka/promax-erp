@extends('layouts.system')

@section('title', __('online.pickup_no').' '.$pickup->number)

@php
    $money = fn ($v) => number_format((float) $v, 2);
    $canAct = in_array(auth()->user()->role, ['admin', 'manager'], true);
    $canCollect = in_array(auth()->user()->role, ['admin', 'manager', 'accountant'], true);
@endphp

@section('content')

@if ($errors->any())
    <div class="alert" style="margin-bottom:12px">{{ $errors->first() }}</div>
@endif
@if (session('ok'))
    <div class="alert good" style="margin-bottom:12px">{{ session('ok') }}</div>
@endif

<div style="display:flex;gap:8px;margin-bottom:12px" class="no-print">
    <button class="btn gold" onclick="window.print()">🖨 {{ __('online.print_sheet') }}</button>
    <a class="btn" href="{{ route('online.pickups') }}">← {{ __('online.pickups_title') }}</a>
</div>

{{-- ═══ رأس الشيت + الأرصدة ═══ --}}
<div class="card" style="margin-bottom:12px">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
        <div>
            <b style="font-size:17px">📋 {{ $pickup->number }}</b>
            <span class="badge {{ $totals['remaining'] <= 0 ? 'b-green' : 'b-orange' }}">
                {{ $totals['remaining'] <= 0 ? __('online.settled') : __('online.open') }}</span>
            <div style="font-size:12px;color:var(--muted);margin-top:3px">
                📅 {{ $pickup->date->format('Y-m-d') }}
                · 🛵 {{ $pickup->courier?->name ?: '—' }}
                @if ($pickup->courier?->phone) · <span dir="ltr">{{ $pickup->courier->phone }}</span> @endif
            </div>
        </div>
    </div>
</div>

<div class="kpis">
    <div class="kpi"><b class="num">{{ $totals['orders'] }}</b><span>{{ __('online.orders_count') }}</span></div>
    <div class="kpi"><b class="num">{{ $totals['pieces'] }}</b><span>{{ __('online.pieces') }}</span></div>
    <div class="kpi"><b class="num">{{ $money($totals['amount']) }}</b><span>{{ __('online.amount') }}</span></div>
    <div class="kpi"><b class="num pos">{{ $money($totals['collected']) }}</b><span>{{ __('online.collected') }}</span></div>
    <div class="kpi"><b class="num {{ $totals['remaining'] > 0 ? 'neg' : 'pos' }}">{{ $money($totals['remaining']) }}</b>
        <span>{{ __('online.remaining') }}</span></div>
</div>

<div class="card">
    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('online.shopify_no') }}</th>
                <th>{{ __('common.name') }}</th>
                <th>{{ __('common.phone') }}</th>
                <th>{{ __('online.area') }}</th>
                <th class="num" data-nosum>{{ __('online.pieces') }}</th>
                <th class="num">{{ __('online.cod_total') }}</th>
                <th class="num">{{ __('online.collected') }}</th>
                <th>{{ __('common.status') }}</th>
                <th class="no-print"></th>
            </tr>
            @foreach ($pickup->orders as $o)
                <tr>
                    <td class="num s"><b>#{{ $o->number }}</b></td>
                    <td>{{ $o->customer_name ?: '—' }}</td>
                    <td class="num s" dir="ltr">{{ $o->phone ?: '—' }}</td>
                    <td class="s">{{ $o->area ?: '—' }}</td>
                    <td class="num">{{ $o->items_count }}</td>
                    <td class="num"><b>{{ $money($o->total) }}</b></td>
                    <td class="num pos">{{ $money($o->collected_total) }}</td>
                    <td><span class="badge {{ $o->statusClass() }}">{{ $o->statusLabel() }}</span></td>
                    <td class="num no-print">
                        @if ($o->status === 'shipped')
                            <div style="display:flex;gap:4px;justify-content:flex-end;flex-wrap:wrap">
                                @if ($canCollect)
                                    <button class="btn sm green" type="button"
                                            onclick="openCollect({{ $o->id }}, '{{ $o->number }}', {{ $o->remaining() }})">
                                        💰 {{ __('online.act_collect') }}</button>
                                @endif
                                @if ($canAct)
                                    <form method="POST" action="{{ route('online.return', $o) }}"
                                          onsubmit="return confirm(RETURN_MSG)">
                                        @csrf
                                        <button class="btn sm red" type="submit">↩ {{ __('online.act_return') }}</button>
                                    </form>
                                    <button class="btn sm" type="button"
                                            onclick="openCancel({{ $o->id }}, '{{ $o->number }}')">
                                        ✖ {{ __('online.act_cancel') }}</button>
                                @endif
                            </div>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    </div>
</div>

{{-- ═══ ديالوج التحصيل ═══ --}}
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

{{-- ═══ ديالوج الإلغاء بعد الشحن — العميل رجّعه قبل ما يفتحه ═══ --}}
<dialog id="dlgCancel">
    <form class="dlg" method="POST" id="formCancel">
        @csrf
        <h4>✖ {{ __('online.cancel_title') }} <span id="ccNum"></span></h4>
        <label class="f">{{ __('online.cancel_reason') }}</label>
        <input name="reason" required maxlength="250" style="width:100%;margin-bottom:12px">
        <div style="display:flex;gap:8px;justify-content:flex-end">
            <button class="btn" type="button" onclick="closeDlg('dlgCancel')">{{ __('common.cancel') }}</button>
            <button class="btn red" type="submit">✖ {{ __('online.act_cancel') }}</button>
        </div>
    </form>
</dialog>

@endsection

@section('scripts')
<script>
    const RETURN_MSG = @js(__('online.return_msg'));
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

    function openCancel(id, num) {
        document.getElementById('formCancel').action = BASE_URL + '/' + id + '/cancel';
        document.getElementById('ccNum').textContent = '#' + num;
        openDlg('dlgCancel');
    }
</script>
@endsection
