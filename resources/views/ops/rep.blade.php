@extends('layouts.system')

@section('title', $u->displayName())

@php $fmt = fn ($n) => number_format((float) $n);         // ⚠️ **مدير الفرع مش هنا.** الراوتس دي `role:admin,manager`،
    // و`isManager()` بترجّع له true — فكان بيشوف الزرار ويترمي على
    // 403 بعد ما يملا الفورم.
    $manager = auth()->user()->canDecideOps(); @endphp

@section('actions')
    <a class="btn" href="{{ route('ops.dashboard') }}">← {{ __('ops.dashboard') }}</a>
    @if ($manager)
        {{-- التحميل بقى من فلو تسليم العهدة الرسمي — مش ديالوج مباشر --}}
        <a class="btn gold" href="{{ route('ops.handout') }}">📤 {{ __('field.handout') }}</a>
        @if ($custody && $custody->status === 'open')
            <form method="POST" action="{{ route('ops.rep.close', $u) }}" style="display:inline" onsubmit="return confirm({{ \Illuminate\Support\Js::from(__('ops.confirm_close_van')) }})">
                @csrf<button class="btn red" type="submit">{{ __('ops.close_van_stock') }}</button>
            </form>
        @endif
    @endif
@endsection

@section('content')

<div class="kpis">
    <div class="kpi"><div class="lbl">{{ __('team.role') }}</div><div class="val" style="font-size:17px">{{ $u->roleLabel() }}</div><div class="sub2">{{ $u->code }} • {{ $u->zone?->displayName() ?? __('ops.delivery_run') }}</div></div>
    {{-- مبيعات المندوب = فواتيره + أوامره المسلَّمة (عقيدة ١١/٨) —
         التفرّع القديم كان بيخفي آجل السيلز المسلَّم بأمر توريد --}}
    <div class="kpi"><div class="lbl">{{ __('ops.sales_today') }}</div>
        <div class="val pos">{{ $fmt($stats['sales']) }} {{ __('common.currency') }}</div>
        @if ($stats['posValue'] > 0)
            <div class="sub2">{{ __('ops.delivered_value') }}: {{ $fmt($stats['posValue']) }} {{ __('common.currency') }}</div>
        @endif
    </div>
    <div class="kpi"><div class="lbl">{{ $u->isDriver() ? __('ops.deliveries') : __('ops.visits') }}</div>
        <div class="val">{{ $u->isDriver() ? $stats['posDone'].'/'.$stats['pos'] : $stats['visitsDone'].'/'.$stats['visits'] }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('ops.van_stock_left') }}</div><div class="val">{{ $stats['remaining'] }}</div>
        {{-- القيمة بكل قايمة مفعّلة — عرض فقط (طلب المالك ١٢/٨) --}}
        <div class="sub2">@include('partials._list_values', ['totals' => $custodyValues])</div></div>
    <div class="kpi"><div class="lbl">{{ __('ops.van_stock_status') }}</div>
        <div class="val" style="font-size:17px">{{ $custody ? ($custody->status === 'open' ? __('ops.open') : __('ops.closed')) : __('common.none') }}</div>
        <div class="sub2">{{ $custody?->date?->format('Y-m-d') ?? '—' }}</div></div>
</div>

@php
    // ═══ تصحيح إداري للعهدة (١٢/٨) — زرار محكوم بأكشن أدمن ═══
    $canAdjust = $custody && $custody->status === 'open'
        && \App\Support\Access::action(auth()->user(), 'act.custody.adjust');

    if ($canAdjust) {
        // صف لكل صنف (البنود بالباتش بتتجمّع) — الأرضية = المتصرّف فعلاً
        $adjRows = $custody->items->groupBy('product_id')->map(fn ($g) => [
            'product' => $g->first()->product,
            'assigned' => (int) $g->sum('assigned'),
            'floor' => (int) $g->sum('sold') + (int) $g->sum('returned'),
            'gift' => (int) $g->sum('gift_assigned'),
            'gift_floor' => (int) $g->sum('gift_given'),
        ])->values();
    }
@endphp

@if ($custody)
<div class="card">
    <h3>📦 {{ __('ops.van_stock') }} <span class="side">{{ __('ops.loaded') }} ← {{ __('ops.remaining') }}</span>
        {{-- ⚠️ الأزرار متحرسة بنفس مفاتيح أكشناتها — زرار بيودّي
             لـ403 أسوأ من زرار مش موجود --}}
        @if (\App\Support\Access::action(auth()->user(), 'act.wh.van_transfer') && $custody->status === 'open')
            <a class="btn sm" style="float:inline-end;margin-inline-start:6px"
               href="{{ route('wh.transfers.van') }}?rep={{ $u->id }}">🔄 {{ __('stock.van_transfer_short') }}</a>
        @endif
        @if ($canAdjust)
            <button class="btn sm" type="button" style="float:inline-end" onclick="openDlg('dlgAdjust')">🛠️ {{ __('field.custody_adjust') }}</button>
        @endif
    </h3>
    @php
        // ═══ القوايم المفعّلة — عمود قيمة لكل قايمة (طلب المالك ١٢/٨) ═══
        // «الصنف ده 100 قطعة: لو بالقديمة بكده ولو بالجديدة بكده» —
        // السعر صغير جوه الخلية والقيمة بالعريض. عرض فقط، والقوايم
        // ميمو للريكوست (CustodyValue) — مفيش كويري لكل صف.
        $cvLists = \App\Support\CustodyValue::lists();
    @endphp
    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('common.code') }}</th><th>{{ __('stock.item') }}</th><th>{{ __('stock.unit') }}</th>
                {{-- مصدر البضاعة (١٤/٨) — طلب المالك: «تقوللي البضاعة دي
                     بتاعة أنهي مصدر: عهدة عادية ولا أمر توريد ولا تحويل» --}}
                <th data-nosum>{{ __('stock.source') }}</th>
                <th>{{ __('ops.loaded') }}</th><th>{{ __('field.sold') }}</th><th>{{ __('ops.remaining') }}</th>
                @foreach ($cvLists as $cvL)
                    <th>{{ __('ops.remaining_value') }}
                        <div style="font-size:9.5px;font-weight:600;color:var(--muted)">{{ $cvL->displayName() }}</div>
                    </th>
                @endforeach
            </tr>
            @foreach ($custody->items as $it)
                <tr>
                    <td class="num">{{ $it->product->code }}</td>
                    <td><b>{{ $it->product->displayName() }}</b></td>
                    <td style="color:var(--muted);font-size:11.5px">{{ $it->product->unitLabel() }}</td>
                    <td>
                        <span class="badge {{ $it->sourceClass() }}">{{ $it->sourceLabel() }}</span>
                        @if ($it->sourceRefLabel())
                            <div style="font-size:10px;color:var(--muted)" dir="ltr">{{ $it->sourceRefLabel() }}</div>
                        @endif
                    </td>
                    <td class="num">{{ $it->assigned }}</td>
                    <td class="num" style="color:var(--blue)">{{ $it->sold }}</td>
                    <td class="num pos"><b>{{ $it->remaining() }}</b>
                        @if ($bd = $it->product?->packBreakdown((int) $it->remaining()))
                            <div style="font-size:10px;color:var(--muted);white-space:nowrap">{{ $bd }}</div>
                        @endif
                    </td>
                    @foreach ($cvLists as $cvL)
                        @php $cvPrice = \App\Support\CustodyValue::priceIn($cvL, $it->product); @endphp
                        <td class="num"><b>{{ number_format($it->remaining() * $cvPrice, 2) }}</b>
                            <div style="font-size:10px;color:var(--muted)" dir="ltr">× {{ number_format($cvPrice, 2) }}</div>
                        </td>
                    @endforeach
                </tr>
            @endforeach
            {{-- الإجماليات من السيرفر — خلايا القيمة فيها سعر + قيمة
                 فالفوتر الأوتوماتيك بيستبعدها؛ الإجمالي من نفس مصدر الكروت --}}
            <tfoot>
                <tr>
                    <td></td>
                    <td><b>Σ {{ __('common.total') }}</b></td>
                    <td></td>
                    {{-- ⚠️ زوّدنا عمود «المصدر» — خانة فاضية في الفوتر تقابله --}}
                    <td></td>
                    <td class="num"><b>{{ $custody->items->sum('assigned') }}</b></td>
                    <td class="num"><b>{{ $custody->items->sum('sold') }}</b></td>
                    <td class="num"><b>{{ $stats['remaining'] }}</b></td>
                    @foreach ($cvLists as $cvL)
                        <td class="num"><b>{{ number_format((float) ($custodyValues[$cvL->id]['total'] ?? 0), 2) }}</b></td>
                    @endforeach
                </tr>
            </tfoot>
        </table>
    </div>
</div>
@endif

<div class="grid2">
    <div class="card">
        <h3>🧾 {{ __('ops.latest_invoices') }}</h3>
        <div class="tablewrap">
            <table>
                <tr>
                    <th>{{ __('ops.invoice') }}</th><th>{{ __('client.client') }}</th><th>{{ __('ops.payment') }}</th>
                    <th>{{ __('common.total') }}</th><th>{{ __('common.time') }}</th>
                </tr>
                @forelse ($invoices as $inv)
                    <tr class="clickable" onclick="location.href='{{ route('ops.invoice', $inv) }}'">
                        <td><b>{{ $inv->number }}</b></td>
                        <td>{{ $inv->client->displayName() }}</td>
                        <td><span class="badge {{ $inv->payment === 'cash' ? 'b-green' : 'b-orange' }}">{{ $inv->paymentLabel() }}</span></td>
                        <td class="num pos">{{ $fmt($inv->total) }}</td>
                        <td class="num">{{ $inv->created_at->format('m-d h:i A') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:20px">{{ __('ops.no_invoices') }}</td></tr>
                @endforelse
            </table>
        </div>
    </div>

    <div class="card">
        <h3>🛰️ {{ __('ops.todays_timeline') }}</h3>
        <div class="alerts" style="max-height:400px;overflow-y:auto">
            @forelse ($events as $e)
                @php $cls = match ($e->type) { 'sale','deliver' => 'good', 'check_in','start' => 'info', 'request' => 'warn', default => '' }; @endphp
                <div class="alert {{ $cls }}"><div><b>{{ $e->happened_at->format('h:i A') }}</b> — {{ $e->title }}
                    @if ($e->subtitle)<span style="color:var(--muted)"> • {{ $e->subtitle }}</span>@endif</div></div>
            @empty
                <div style="text-align:center;color:var(--muted);padding:20px">{{ __('ops.no_activity') }}</div>
            @endforelse
        </div>
    </div>
</div>

{{-- ⚠️ ديالوج «تحميل عهدة» المباشر **اتشال** (قرار المالك 2026-08-03):
     كان بيجهّز ويسلّم في نفس الثانية من غير ما المندوب يستلم من
     الأبلكيشن — متناقض مع الفلو الرسمي: تسليم عهدة ← تجهيز الطلبات
     ← تأكيد ← إشعار ← استلام المندوب. الزرار فوق بقى بيوصّل للفلو ده. --}}

{{-- ═══ تصحيح إداري للعهدة (١٢ أغسطس ٢٠٢٦) — «التحميل اتسجّل غلط» ═══
     أرقام مستهدفة مش فروق: الأدمن بيكتب المحمَّل الصح، والسيرفر بيظبط
     العهدة والأرفف مع بعض (Custody::adjustTo) — الزيادة بأمر تجهيز
     حقيقي بيتسلّم فوراً، والنقص بيرجع لرف باتشه. --}}
@if ($canAdjust)
    <dialog id="dlgAdjust" class="wide">
        <form class="dlg" method="POST" action="{{ route('ops.rep.adjust', $u) }}"
              style="width:min(760px,96vw);max-height:88vh;overflow-y:auto">
            @csrf
            <h4>🛠️ {{ __('field.custody_adjust') }} — {{ $u->displayName() }}</h4>

            <div class="alert warn" style="margin-bottom:12px">
                <span>⚠️</span><span>{{ __('field.custody_adjust_hint') }}</span>
            </div>

            <div style="margin-bottom:12px">
                <label class="f">{{ __('field.custody_adjust_reason') }} <b class="req-star">*</b></label>
                <input type="text" name="reason" required maxlength="300" style="width:100%"
                       placeholder="{{ __('field.custody_adjust_reason_ph') }}">
            </div>

            {{-- منتقي إضافة صنف مش في العهدة — نفس الليست المشتركة --}}
            @php
                $adjCatalog = $products->map(fn ($p) => [
                    'id' => $p->id, 'code' => $p->code,
                    'name' => $p->displayName(), 'name_ar' => $p->name,
                    'name_en' => $p->name_en, 'image' => $p->imageSrc(),
                ])->values()->all();

                // ═══ أسعار كل الأصناف بقايمة المندوب (١٢/٨) — للقيمة اللايف ═══
                // عرض فقط: الديالوج بيوري «الرقم اللي بتكتبه = قيمة كام»
                // والسيرفر مابيستلمش منها حاجة. json_encode هنا في بلوك
                // بي‌اتش‌بي مش دايركتيف — قاعدة البليد المعروفة.
                $cadjPrices = $products->mapWithKeys(fn ($p) => [
                    $p->id => round(\App\Support\CustodyValue::priceIn($repList, $p), 2),
                ])->all();
                $cadjPricesJson = json_encode($cadjPrices, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
            @endphp
            <label class="f">{{ __('stock.pick_add_item') }}</label>
            @include('partials._item_picker', [
                'id' => 'cadj',
                'catalog' => $adjCatalog,
                'onPick' => 'custodyAdjAdd',
            ])

            <div class="tablewrap" style="margin-top:12px;max-height:46vh;overflow-y:auto;border:1px solid var(--border);border-radius:10px">
                <table>
                    <thead>
                        <tr>
                            <th style="text-align:start">{{ __('stock.item') }}</th>
                            <th>{{ __('field.custody_adjust_loaded') }}</th>
                            {{-- الأرضية معروضة قصاد كل صنف — الحارس في السيرفر برضه --}}
                            <th>{{ __('field.custody_adjust_floor') }}</th>
                            <th>{{ __('field.custody_adjust_new') }}</th>
                            <th>{{ __('field.custody_adjust_gift_new') }}</th>
                            {{-- القيمة لايف بقايمة المندوب — عرض فقط (١٢/٨) --}}
                            <th data-nosum>{{ __('field.handout_value') }}
                                <div style="font-size:9.5px;font-weight:600;color:var(--muted)">{{ $repList?->displayName() }}</div>
                            </th>
                        </tr>
                    </thead>
                    <tbody id="cadjRows">
                        @foreach ($adjRows as $r)
                            @php $p = $r['product']; @endphp
                            {{-- صنف اتمسح من الكتالوج؟ مفيش مفتاح نبعته — نتخطى --}}
                            @continue($p === null)
                            <tr data-pid="{{ $p->id }}">
                                <td style="text-align:start">
                                    <b>{{ $p?->displayName() ?? '—' }}</b>
                                    @if ($p)<div style="font-size:10.5px;color:var(--muted)">{{ $p->code }}</div>@endif
                                </td>
                                <td class="num">{{ $r['assigned'] }}
                                    @if ($r['gift'] > 0)<div style="font-size:10px;color:var(--muted)">🎁 {{ $r['gift'] }}</div>@endif
                                </td>
                                <td class="num" style="color:var(--muted)">{{ $r['floor'] }}
                                    @if ($r['gift_floor'] > 0)<div style="font-size:10px">🎁 {{ $r['gift_floor'] }}</div>@endif
                                </td>
                                <td>
                                    <input type="number" name="assigned[{{ $p?->id }}]" min="{{ $r['floor'] }}" step="1"
                                           value="{{ $r['assigned'] }}" style="width:92px"
                                           oninput="cadjSync({{ $p->id }})">
                                </td>
                                <td>
                                    <input type="number" name="gift[{{ $p?->id }}]" min="{{ $r['gift_floor'] }}" step="1"
                                           value="{{ $r['gift'] }}" style="width:82px">
                                </td>
                                @php $adjPrice = (float) ($cadjPrices[$p->id] ?? 0); @endphp
                                <td class="num">
                                    <b id="cadjV{{ $p->id }}" dir="ltr">{{ number_format($r['assigned'] * $adjPrice, 2) }}</b>
                                    <div style="font-size:10px;color:var(--muted)" dir="ltr">× {{ number_format($adjPrice, 2) }}</div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- إجمالي القيمة الجديدة لايف — استرشادي، التصفية بالقطع --}}
            <div style="display:flex;justify-content:flex-end;align-items:center;gap:6px;margin-top:10px;font-size:12.5px;color:var(--muted)">
                {{ __('field.custody_adjust_total_value') }} ({{ $repList?->displayName() ?? '—' }}):
                <b id="cadjTotal" dir="ltr" style="color:var(--royal-blue, #12399B);font-size:14px">0.00</b>
            </div>

            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
                <button class="btn" type="button" onclick="closeDlg('dlgAdjust')">{{ __('common.cancel') }}</button>
                <button class="btn gold" type="submit">{{ __('common.save') }}</button>
            </div>
        </form>
    </dialog>
@endif

@endsection

@section('scripts')
@if ($canAdjust)
<script>
(function () {
    'use strict';

    // ═══ أسعار قايمة المندوب — القيمة لايف وانت بتكتب (١٢/٨) ═══
    // ⚠️ عرض فقط: السيرفر مابيستلمش أي سعر من الديالوج ده.
    const CADJ_PRICES = {!! $cadjPricesJson !!};

    const money = n => Number(n || 0).toLocaleString(undefined, {
        minimumFractionDigits: 2, maximumFractionDigits: 2
    });

    // قيمة صف = المحمَّل الجديد × سعر قايمة المندوب — والإجمالي بعده
    window.cadjSync = function (id) {
        const q = document.querySelector('#cadjRows input[name="assigned[' + id + ']"]');
        const cell = document.getElementById('cadjV' + id);
        if (q && cell) {
            cell.textContent = money(Number(q.value || 0) * (CADJ_PRICES[id] || 0));
        }
        cadjTotal();
    };

    function cadjTotal() {
        let total = 0;
        document.querySelectorAll('#cadjRows input[name^="assigned"]').forEach(q => {
            const m = q.name.match(/\d+/);
            if (m) total += Number(q.value || 0) * (CADJ_PRICES[m[0]] || 0);
        });
        const el = document.getElementById('cadjTotal');
        if (el) el.textContent = money(total);
    }

    // صنف جديد من الليست — محمَّل حالي 0 وأرضية 0
    window.custodyAdjAdd = function (id) {
        const prod = (window.PICKER_CADJ || []).find(p => p.id === id);
        if (!prod) return;

        const existing = document.querySelector('#cadjRows tr[data-pid="' + id + '"]');
        if (existing) {
            const q = existing.querySelector('input[name^="assigned"]');
            if (q) { q.focus(); }
        } else {
            const tr = document.createElement('tr');
            tr.setAttribute('data-pid', id);
            tr.innerHTML =
                '<td style="text-align:start"><b>' + (prod.name || '') + '</b>' +
                '<div style="font-size:10.5px;color:var(--muted)">' + (prod.code || '') + '</div></td>' +
                '<td class="num">0</td>' +
                '<td class="num" style="color:var(--muted)">0</td>' +
                '<td><input type="number" name="assigned[' + id + ']" min="0" step="1" value="1" style="width:92px" oninput="cadjSync(' + id + ')"></td>' +
                '<td><input type="number" name="gift[' + id + ']" min="0" step="1" value="0" style="width:82px"></td>' +
                '<td class="num"><b id="cadjV' + id + '" dir="ltr">' + money(CADJ_PRICES[id] || 0) + '</b>' +
                '<div style="font-size:10px;color:var(--muted)" dir="ltr">× ' + money(CADJ_PRICES[id] || 0) + '</div></td>';
            document.getElementById('cadjRows').appendChild(tr);
        }
        cadjTotal();
        window.cadjPickerReset();
    };

    cadjTotal();

    // جاي من بورد العربيات بزرار «تعديل العهدة»؟ افتح الديالوج على طول
    if (new URLSearchParams(location.search).get('adjust') === '1') {
        openDlg('dlgAdjust');
    }
})();
</script>
@endif
@endsection
