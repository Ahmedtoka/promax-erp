@extends('layouts.system')

@section('title', __('online.ready_title'))

@php
    $canAct = in_array(auth()->user()->role, ['admin', 'manager'], true);
    $money = fn ($v) => number_format((float) $v, 2);
@endphp

@section('content')

@if ($errors->any())
    <div class="alert" style="margin-bottom:12px">{{ $errors->first() }}</div>
@endif
@if (session('ok'))
    <div class="alert good" style="margin-bottom:12px">{{ session('ok') }}</div>
@endif

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:8px">
        <h3 style="margin:0">🚚 {{ __('online.ready_title') }}
            <span class="badge b-gold">{{ $orders->count() }}</span></h3>
    </div>
    <div class="dash-hint" style="margin-bottom:10px">{{ __('online.ready_hint') }}</div>

    @if ($canAct)
        {{-- ═══ بار الشحن: مسدس + مندوب + زرار ═══ --}}
        <form method="POST" action="{{ route('online.ship') }}" id="shipForm"
              style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px;
                     background:var(--blue-050,#E8F1FF);border:1px solid var(--royal-blue,#12399B);
                     border-radius:12px;padding:10px 14px">
            @csrf
            {{-- خانة المسدس: امسح pro1234 → الصف بيتعلم لوحده --}}
            <input id="scanBox" placeholder="{{ __('online.scan_ph') }}" dir="ltr"
                   style="flex:0 1 200px" autocomplete="off">
            <span id="shipCount" class="badge b-blue">0</span>
            <select name="courier_id" required style="flex:0 1 220px">
                <option value="">— {{ __('online.courier') }} —</option>
                @foreach ($couriers as $c)
                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                @endforeach
            </select>
            <button class="btn sm" type="button" onclick="openDlg('dlgCourier')">➕ {{ __('online.courier_new') }}</button>
            <button class="btn gold" type="submit" id="shipBtn" disabled
                    onclick="return confirm(SHIP_MSG)">
                🚚 {{ __('online.ship_btn') }}</button>
        </form>
    @endif

    <div class="tablewrap">
        <table>
            <tr>
                @if ($canAct)<th data-nosum style="width:32px"><input type="checkbox" id="shipAll"></th>@endif
                <th>{{ __('online.shopify_no') }}</th>
                <th>{{ __('common.name') }}</th>
                <th>{{ __('common.phone') }}</th>
                <th>{{ __('online.area') }}</th>
                <th class="num" data-nosum>{{ __('online.pieces') }}</th>
                <th class="num">{{ __('online.cod_total') }}</th>
                <th>{{ __('online.ready_since') }}</th>
                <th></th>
            </tr>
            @forelse ($orders as $o)
                <tr data-barcode="{{ $o->barcode() }}">
                    @if ($canAct)
                        <td><input type="checkbox" class="ship-ck" form="shipForm"
                                   name="ids[]" value="{{ $o->id }}"></td>
                    @endif
                    <td class="num s"><b>#{{ $o->number }}</b></td>
                    <td>{{ $o->customer_name ?: '—' }}</td>
                    <td class="num s" dir="ltr">{{ $o->phone ?: '—' }}</td>
                    <td class="s">{{ $o->area ?: '—' }}</td>
                    <td class="num">{{ $o->items_count }}</td>
                    <td class="num"><b>{{ $money($o->total) }}</b></td>
                    <td class="s">{{ $o->ready_at?->format('d/m h:i A') ?: '—' }}</td>
                    <td class="num">
                        <a class="btn sm" href="{{ route('online.invoice', $o) }}" target="_blank">🖨</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ $canAct ? 9 : 8 }}" style="text-align:center;color:var(--muted);padding:28px">
                    {{ __('online.ready_empty') }}
                </td></tr>
            @endforelse
        </table>
    </div>
</div>

{{-- ═══ مودال مندوب أونلاين جديد ═══ --}}
<dialog id="dlgCourier">
    <form class="dlg" method="POST" action="{{ route('online.couriers.store') }}">
        @csrf
        <h4>➕ {{ __('online.courier_new') }}</h4>
        <label class="f">{{ __('common.name') }}</label>
        <input name="name" required maxlength="120" style="width:100%;margin-bottom:8px">
        <label class="f">{{ __('common.phone') }}</label>
        <input name="phone" maxlength="30" style="width:100%;margin-bottom:12px" dir="ltr">
        <div class="dash-hint" style="margin-bottom:10px">{{ __('online.courier_hint') }}</div>
        <div style="display:flex;gap:8px;justify-content:flex-end">
            <button class="btn" type="button" onclick="closeDlg('dlgCourier')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">💾 {{ __('common.save') }}</button>
        </div>
    </form>
</dialog>

@endsection

@section('scripts')
<script>
    const SHIP_MSG = @js(__('online.ship_msg'));

    (function () {
        'use strict';

        var btn = document.getElementById('shipBtn');
        var count = document.getElementById('shipCount');
        var all = document.getElementById('shipAll');
        if (!btn) return;

        function refresh() {
            var n = document.querySelectorAll('.ship-ck:checked').length;
            count.textContent = n;
            btn.disabled = n === 0;
        }

        document.querySelectorAll('.ship-ck').forEach(function (c) {
            c.addEventListener('change', refresh);
        });

        if (all) {
            all.addEventListener('change', function () {
                document.querySelectorAll('.ship-ck').forEach(function (c) { c.checked = all.checked; });
                refresh();
            });
        }

        /* ═══ المسدس: بيكتب pro1234 وينهيها Enter — بنلاقي الصف
           وبنعلّمه وبنفضّي الخانة للمسحة الجاية ═══ */
        var scan = document.getElementById('scanBox');

        if (scan) {
            scan.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter') return;
                e.preventDefault();

                var code = scan.value.trim().toLowerCase();
                scan.value = '';
                if (!code) return;

                var row = document.querySelector('tr[data-barcode="' + code + '"]');

                if (row) {
                    var ck = row.querySelector('.ship-ck');
                    if (ck) { ck.checked = true; refresh(); }
                    row.style.background = '#E9F9EF';
                } else {
                    alert(@js(__('online.scan_not_found')) + ' ' + code);
                }
            });

            scan.focus();
        }

        refresh();
    })();
</script>
@endsection
