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

<div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap" class="no-print">
    <a class="btn gold" href="{{ route('online.pickup.excel', $pickup) }}">📊 {{ __('online.excel_btn') }}</a>
    <button class="btn" onclick="window.print()">🖨 {{ __('online.print_sheet') }}</button>
    @if ($canAct)
        {{-- علاج بأثر رجعي: Fulfilled/Paid لأوردرات الشيت في شوبيفاي --}}
        <form method="POST" action="{{ route('online.pickup.push', $pickup) }}" style="display:inline">
            @csrf
            <button class="btn" type="submit">🔁 {{ __('online.push_btn') }}</button>
        </form>
    @endif
    <a class="btn" href="{{ route('online.pickups') }}">← {{ __('online.pickups_title') }}</a>
</div>

{{-- ═══ رأس الشيت: مين عمله + المندوب + التاريخ ═══ --}}
<div class="card" style="margin-bottom:12px">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
        <div>
            <b style="font-size:17px">📋 {{ $pickup->number }}</b>
            <span class="badge {{ $totals['remaining'] <= 0 ? 'b-green' : 'b-orange' }}">
                {{ $totals['remaining'] <= 0 ? __('online.settled') : __('online.open') }}</span>
            <div style="font-size:12px;color:var(--muted);margin-top:4px">
                📅 {{ $pickup->date->format('Y-m-d') }}
                · 🛵 {{ $pickup->courier?->name ?: '—' }}
                @if ($pickup->courier?->phone) <span dir="ltr">{{ $pickup->courier->phone }}</span> @endif
                · 👤 {{ __('online.by_user') }}: <b>{{ $pickup->creator?->displayName() ?: '—' }}</b>
            </div>
        </div>
    </div>
</div>

{{-- ═══ المربعات (٤/٩): الرقم كبير في النص وتحته الاسم + لون وأيقونة ═══ --}}
<div class="pu-kpis">
    <div class="pu-kpi"><div class="ic">🧾</div><div class="v">{{ $totals['orders'] }}</div><div class="l">{{ __('online.orders_count') }}</div></div>
    <div class="pu-kpi"><div class="ic">📦</div><div class="v">{{ $totals['pieces'] }}</div><div class="l">{{ __('online.pieces') }}</div></div>
    <div class="pu-kpi"><div class="ic">🛒</div><div class="v">{{ $money($totals['goods']) }}</div><div class="l">{{ __('online.goods_amount') }}</div></div>
    <div class="pu-kpi"><div class="ic">🚚</div><div class="v">{{ $money($totals['ship']) }}</div><div class="l">{{ __('online.shipping') }}</div></div>
    <div class="pu-kpi blue"><div class="ic">💰</div><div class="v">{{ $money($totals['amount']) }}</div><div class="l">{{ __('common.total') }}</div></div>
    <div class="pu-kpi green"><div class="ic">✅</div><div class="v">{{ $money($totals['collected']) }}</div><div class="l">{{ __('online.collected') }}</div></div>
    <div class="pu-kpi {{ $totals['remaining'] > 0 ? 'red' : 'green' }}">
        <div class="ic">⏳</div><div class="v">{{ $money($totals['remaining']) }}</div><div class="l">{{ __('online.remaining') }}</div></div>
</div>

<div class="card">
    {{-- بحث جوه الشيت + اختيار الأعمدة الظاهرة --}}
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px" class="no-print">
        <input id="puFilter" placeholder="{{ __('online.pu_filter_ph') }}" style="min-width:260px" autocomplete="off">
        <span id="puFilterN" class="badge b-gray" style="display:none"></span>

        {{-- كولوم فيزابيلتي: العنوان مقفول افتراضياً والاختيار بيتحفظ --}}
        <details style="margin-inline-start:auto;position:relative" id="puColsBox">
            <summary class="btn sm" style="list-style:none;cursor:pointer">⚙️ {{ __('online.cols_btn') }}</summary>
            <div style="position:absolute;inset-inline-end:0;top:110%;z-index:30;background:#fff;
                        border:1px solid var(--border);border-radius:12px;box-shadow:var(--shadow);
                        padding:10px 14px;min-width:180px;display:flex;flex-direction:column;gap:6px">
                <label style="font-size:12px;display:flex;gap:6px;align-items:center">
                    <input type="checkbox" class="pu-col" data-col="gov" checked> {{ __('online.rcpt_gov') }}</label>
                <label style="font-size:12px;display:flex;gap:6px;align-items:center">
                    <input type="checkbox" class="pu-col" data-col="area" checked> {{ __('online.rcpt_area') }}</label>
                <label style="font-size:12px;display:flex;gap:6px;align-items:center">
                    <input type="checkbox" class="pu-col" data-col="addr"> {{ __('online.rcpt_addr') }}</label>
            </div>
        </details>
    </div>

    <div class="tablewrap frz" style="max-height:64vh;overflow:auto">
        <table id="puTable">
            <tr>
                <th>{{ __('online.shopify_no') }}</th>
                <th>{{ __('common.name') }}</th>
                <th>{{ __('common.phone') }}</th>
                <th data-col="gov">{{ __('online.rcpt_gov') }}</th>
                <th data-col="area">{{ __('online.rcpt_area') }}</th>
                <th data-col="addr">{{ __('online.rcpt_addr') }}</th>
                <th class="num" data-nosum>{{ __('online.pieces') }}</th>
                <th class="num">{{ __('online.goods_amount') }}</th>
                <th class="num">{{ __('online.shipping') }}</th>
                <th class="num">{{ __('common.total') }}</th>
                <th class="num">{{ __('online.collected') }}</th>
                <th>{{ __('common.status') }}</th>
                <th class="no-print"></th>
            </tr>
            @foreach ($pickup->orders as $o)
                @php $parts = array_map('trim', explode(' - ', (string) $o->area, 2)); @endphp
                <tr class="pu-row"
                    data-q="{{ mb_strtolower(($o->number ?? '').' '.($o->customer_name ?? '').' '.($o->phone ?? '')) }}">
                    <td class="num s"><b>#{{ $o->number }}</b></td>
                    <td>{{ $o->customer_name ?: '—' }}</td>
                    <td class="num s" dir="ltr">{{ $o->phone ?: '—' }}</td>
                    <td class="s" data-col="gov">{{ ($parts[1] ?? '') !== '' ? $parts[1] : '—' }}</td>
                    <td class="s" data-col="area">{{ ($parts[0] ?? '') !== '' ? $parts[0] : '—' }}</td>
                    <td class="s" data-col="addr" style="max-width:220px">{{ $o->address ?: '—' }}</td>
                    <td class="num">{{ $o->items_count }}</td>
                    <td class="num">{{ $money($o->subtotal) }}</td>
                    <td class="num">{{ $money($o->shipping) }}</td>
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
                                    @php
                                        // بايلود ديالوج المرتجع الجزئي — ⚠️ ممنوع @json (فخ البارسر)
                                        $retPayload = json_encode([
                                            'id' => $o->id,
                                            'number' => $o->number,
                                            'items' => $o->items->map(fn ($i) => [
                                                'id' => $i->id,
                                                'name' => $i->product?->displayName() ?? $i->title,
                                                'max' => (int) $i->qty - (int) $i->returned_qty,
                                            ])->values()->all(),
                                        ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
                                    @endphp
                                    <button class="btn sm red" type="button"
                                            onclick='openReturn({!! $retPayload !!})'>
                                        ↩ {{ __('online.act_return') }}</button>
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

{{-- ═══ ديالوج المرتجع الجزئي (٥/٩): كمية راجعة لكل بند —
     صفر = مرجعش، الكل = الأوردر يرجع بالكامل ═══ --}}
<dialog id="dlgReturn">
    <form class="dlg" method="POST" id="formReturn" style="min-width:380px"
          onsubmit="return confirm(RETURN_MSG)">
        @csrf
        <h4>↩ {{ __('online.return_title') }} <span id="rtNum"></span></h4>
        <div class="dash-hint" style="margin-bottom:10px">{{ __('online.return_hint') }}</div>
        <div id="rtItems" style="display:flex;flex-direction:column;gap:8px;margin-bottom:12px;
             max-height:50vh;overflow-y:auto"></div>
        <div style="display:flex;gap:8px;justify-content:flex-end">
            <button class="btn" type="button" onclick="closeDlg('dlgReturn')">{{ __('common.cancel') }}</button>
            <button class="btn red" type="submit">↩ {{ __('online.act_return') }}</button>
        </div>
    </form>
</dialog>

{{-- ═══ ديالوج الإلغاء بعد الشحن ═══ --}}
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
<style>
    .frz th{position:sticky;top:0;z-index:2}

    /* مربعات الشيت — الرقم كبير في النص والاسم تحته + شريط لون فوق */
    .pu-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:12px}
    .pu-kpi{
        background:var(--card);border:1px solid var(--border);border-radius:14px;
        padding:14px 10px;text-align:center;position:relative;overflow:hidden;
    }
    .pu-kpi::before{
        content:'';position:absolute;top:0;left:0;right:0;height:3px;
        background:linear-gradient(135deg,#12399B,#602D90);
    }
    .pu-kpi .ic{font-size:17px}
    .pu-kpi .v{font-size:23px;font-weight:900;font-variant-numeric:tabular-nums;margin:2px 0;color:var(--ink)}
    .pu-kpi .l{font-size:11px;color:var(--muted);font-weight:700}
    .pu-kpi.blue .v{color:var(--royal-blue,#12399B)}
    .pu-kpi.green .v{color:#16A34A}
    .pu-kpi.green::before{background:#16A34A}
    .pu-kpi.red .v{color:#DC2626}
    .pu-kpi.red::before{background:#DC2626}
</style>
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

    const RET_OF = @js(__('online.return_of'));

    function escR(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function openReturn(p) {
        document.getElementById('formReturn').action = BASE_URL + '/' + p.id + '/return';
        document.getElementById('rtNum').textContent = '#' + p.number;

        var box = document.getElementById('rtItems');
        box.innerHTML = '';

        p.items.forEach(function (it) {
            box.insertAdjacentHTML('beforeend',
                '<div style="display:flex;align-items:center;gap:10px;border:1px solid var(--border);border-radius:10px;padding:8px 12px">'
                + '<div style="flex:1;min-width:0"><b style="font-size:12.5px">' + escR(it.name) + '</b>'
                + '<div style="font-size:10.5px;color:var(--muted)">' + RET_OF.replace(':n', it.max) + '</div></div>'
                + '<input type="number" name="items[' + it.id + ']" value="0" min="0" max="' + it.max + '"'
                + (it.max <= 0 ? ' disabled' : '')
                + ' style="width:70px;text-align:center">'
                + '</div>');
        });

        openDlg('dlgReturn');
    }

    (function () {
        'use strict';

        /* ═══ بحث جوه الشيت — رقم أوردر / اسم / موبايل ═══ */
        var filter = document.getElementById('puFilter');
        var badge = document.getElementById('puFilterN');

        if (filter) {
            filter.addEventListener('input', function () {
                var q = filter.value.trim().toLowerCase();
                var n = 0;

                document.querySelectorAll('.pu-row').forEach(function (r) {
                    var hit = q === '' || r.dataset.q.indexOf(q) !== -1;
                    r.style.display = hit ? '' : 'none';
                    if (hit) n++;
                });

                badge.style.display = q === '' ? 'none' : '';
                badge.textContent = n;
            });
        }

        /* ═══ إظهار/إخفاء الأعمدة — العنوان مقفول افتراضياً،
           والاختيار بيتحفظ في المتصفح ═══ */
        var KEY = 'pu_cols_v1';
        var saved = {};

        try { saved = JSON.parse(localStorage.getItem(KEY) || '{}'); } catch (e) { saved = {}; }

        function applyCol(col, on) {
            document.querySelectorAll('[data-col="' + col + '"]').forEach(function (el) {
                el.style.display = on ? '' : 'none';
            });
        }

        document.querySelectorAll('.pu-col').forEach(function (ck) {
            var col = ck.dataset.col;

            if (col in saved) { ck.checked = !!saved[col]; }

            applyCol(col, ck.checked);

            ck.addEventListener('change', function () {
                applyCol(col, ck.checked);
                saved[col] = ck.checked;

                try { localStorage.setItem(KEY, JSON.stringify(saved)); } catch (e) { /* خاص */ }
            });
        });
    })();
</script>
@endsection
