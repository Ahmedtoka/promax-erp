@extends('layouts.system')

{{--
    تحويل بضاعة من عربية مندوب — ١٤ أغسطس ٢٠٢٦.

    طلب المالك: «عملت تسليم عهدة ومندوب خرج بيها، ولقينا غلط في
    التحضير. عاوز أحوّل منه للمخزن أو لمندوب تاني، أختار البضاعة
    حسب المتوفر معاه بالسيلكت زي البيع الطبيعي، ولازم أكتب السبب،
    وتقوللي البضاعة دي بتاعة أنهي مصدر.»

    الشاشة: اتجاه ← مندوب مصدر ← وجهة (مخزن أو مندوب) ← منتقي بنود
    العهدة الحية (المتاح + الباتش + شارة المصدر) ← كمية لكل سطر ←
    سبب إجباري ← حفظ. الأصناف **مش من الكتالوج** — من `custody_items`
    نفسها، فمفيش طريقة تختار بضاعة مش موجودة معاه.
--}}

{{-- ⚠️ **كتالوج المنتقي = بنود العهدة نفسها**، فالـ`id` اللي بيتبعت
     هو `custody_items.id` مش `products.id`. السيرفر بيقفل على البند
     ده بالذات ويقارن الكمية بـ`remaining()` بتاعته — فمفيش «حوّل
     صنف» مبهم يتوزّع على باتشات على مزاج الكود. الـJSON بيتولّد جوه
     البارشيال المشترك ومتعرّض على `window.PICKER_VTPICK`. --}}

@section('title', __('stock.van_transfer'))

@section('actions')
    <a class="btn" href="{{ route('wh.transfers') }}">← {{ __('stock.transfers') }}</a>
@endsection

@section('content')

<div class="card">
    <h3>🔄 {{ __('stock.van_transfer') }}
        <span class="side">{{ __('stock.van_transfer_hint') }}</span></h3>

    @if ($errors->any())
        <div class="alert" style="margin-bottom:12px;flex-direction:column;align-items:stretch;gap:4px">
            @foreach ($errors->all() as $msg)
                <div class="errline" style="margin:0">{{ $msg }}</div>
            @endforeach
        </div>
    @endif

    @if ($reps->isEmpty())
        <div class="alert warn"><span>⚠️</span><span>{{ __('stock.van_no_stock') }}</span></div>
    @else
    <form method="POST" action="{{ route('wh.transfers.van.store') }}" id="vtForm">
        @csrf

        <div class="frow">
            <div>
                <label class="f">{{ __('stock.kind') }} <b class="req-star">*</b></label>
                <select name="kind" id="vtKind" required style="width:100%" onchange="vtKindChanged()">
                    {{-- الفورم بيرجع بـ`withInput()` لو الفاليديشن رفض — الاختيار بيفضل --}}
                    <option value="rep_wh" @selected(old('kind', 'rep_wh') === 'rep_wh')>🚐→🏭 {{ __('stock.kind_rep_wh') }}</option>
                    <option value="rep_rep" @selected(old('kind') === 'rep_rep')>🚐→🚐 {{ __('stock.kind_rep_rep') }}</option>
                </select>
            </div>
            <div>
                <label class="f">{{ __('stock.from_rep') }} <b class="req-star">*</b></label>
                {{-- جاي من كارت المندوب أو بورد العربيات بـ`?rep=` — بيتفتح عليه --}}
                <select name="from_user_id" id="vtFrom" required style="width:100%" onchange="vtSourceChanged()">
                    @foreach ($reps as $r)
                        <option value="{{ $r->id }}"
                            @selected((int) old('from_user_id', request()->integer('rep')) === (int) $r->id)>
                            {{ $r->displayName() }} — {{ $r->roleLabel() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div id="vtWhBox">
                <label class="f">{{ __('stock.to_warehouse') }} <b class="req-star">*</b></label>
                <select name="to_warehouse_id" id="vtWh" style="width:100%" onchange="vtSourceChanged()">
                    @foreach ($warehouses as $w)
                        <option value="{{ $w->id }}" @selected((int) old('to_warehouse_id') === (int) $w->id)>
                            {{ $w->displayName() }} — {{ $w->typeLabel() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div id="vtRepBox" style="display:none">
                <label class="f">{{ __('stock.to_rep') }} <b class="req-star">*</b></label>
                <select name="to_user_id" id="vtToRep" style="width:100%">
                    @foreach ($reps as $r)
                        <option value="{{ $r->id }}" @selected((int) old('to_user_id') === (int) $r->id)>
                            {{ $r->displayName() }} — {{ $r->roleLabel() }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="alert info" id="vtKindHint" style="margin-top:10px">
            <span>ℹ️</span><span id="vtKindHintText">{{ __('stock.van_transfer_wh_hint') }}</span>
        </div>

        {{-- ═══ السبب — إجباري، والسيرفر بيرفض من غيره ═══ --}}
        <div style="margin-top:12px">
            <label class="f">{{ __('stock.transfer_reason') }} <b class="req-star">*</b></label>
            <input type="text" name="reason" required minlength="3" maxlength="300" style="width:100%"
                   value="{{ old('reason') }}"
                   placeholder="{{ __('stock.transfer_reason_ph') }}">
        </div>

        {{-- ═══ ملخصات لايف ═══ --}}
        <div class="kpis" style="margin-top:12px">
            <div class="kpi">
                <div class="lbl">{{ __('stock.transfer_lines') }}</div>
                <div class="val" id="vtKpiLines">0</div>
            </div>
            <div class="kpi">
                <div class="lbl">{{ __('stock.total_pieces') }}</div>
                <div class="val pos" id="vtKpiPieces">0</div>
                <div class="sub2">{{ __('stock.units') }}</div>
            </div>
        </div>

        {{-- ═══ منتقي بنود العهدة — نفس «علّم وضيف» المشترك ═══ --}}
        <div style="margin-top:12px">
            <label class="f">{{ __('stock.van_pick_line') }}
                <span style="font-weight:600;color:var(--muted);font-size:11px">{{ __('stock.van_line_hint') }}</span>
            </label>
            @include('partials._item_picker', [
                'id' => 'vtpick',
                'catalog' => $lines,
                'onPick' => 'vtAddRow',
                'filter' => 'vtPickable',
                'sub' => 'vtPickSub',
            ])
        </div>

        <div class="tablewrap" style="margin-top:12px;max-height:52vh;overflow-y:auto">
            <table>
                <thead>
                    <tr>
                        <th>{{ __('stock.item') }}</th>
                        <th style="width:150px">{{ __('stock.batch_no') }}</th>
                        <th style="width:170px">{{ __('stock.source') }}</th>
                        <th class="num" style="width:90px">{{ __('stock.available') }}</th>
                        <th class="num" style="width:110px">{{ __('common.qty') }}</th>
                        <th style="width:40px"></th>
                    </tr>
                </thead>
                <tbody id="vtRows">
                    <tr id="vtEmpty">
                        <td colspan="6" style="text-align:center;color:var(--muted);padding:26px">
                            {{ __('field.no_selected_hint') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div style="margin:12px 0">
            <label class="f">{{ __('common.notes') }}</label>
            <textarea name="notes" rows="2" style="width:100%">{{ old('notes') }}</textarea>
        </div>

        <div style="display:flex;gap:8px;justify-content:flex-end">
            <a class="btn" href="{{ route('wh.transfers') }}">{{ __('common.cancel') }}</a>
            <button class="btn gold" type="submit" id="vtBtn" disabled>🔄 {{ __('common.save') }}</button>
        </div>
    </form>
    @endif
</div>

@endsection

@section('scripts')
@if ($reps->isNotEmpty())
<script>
(function () {
    'use strict';

    // بنود العهدة الحية — جاية من المنتقي المشترك، مفيش نسخة تانية
    const VT_LINES = window.PICKER_VTPICK || [];
    const VT_HINT_WH = @json(__('stock.van_transfer_wh_hint'));
    const VT_HINT_REP = @json(__('stock.van_transfer_rep_hint'));
    const VT_AVAIL = @json(__('stock.available'));
    const VT_BATCH = @json(__('stock.batch_no'));

    let vtIdx = 0;

    const esc = s => String(s ?? '').replace(/[&<>"']/g,
        ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));

    const kind = () => document.getElementById('vtKind').value;
    const fromId = () => Number(document.getElementById('vtFrom').value || 0);
    const whId = () => Number(document.getElementById('vtWh').value || 0);

    // ⚠️ هوك المنتقي: بنود المندوب المختار بس — ولو الوجهة مخزن،
    // بنود الباتشات اللي في المخزن ده بس. البضاعة بترجع لرفها
    // الأصلي، فوجهة تانية معناها رقم غلط في مخزن ثالث. الحارس
    // نفسه موجود في السيرفر كمان.
    window.vtPickable = function (l) {
        if (l.rep !== fromId()) { return false; }
        if (kind() === 'rep_wh' && l.wh > 0 && l.wh !== whId()) { return false; }
        return l.avail > 0;
    };

    window.vtPickSub = function (l) {
        return VT_AVAIL + ': ' + Number(l.avail || 0).toLocaleString()
            + ' · ' + (l.batch || '—');
    };

    /** صف بند — الكمية محدودة بالمتاح في البند نفسه */
    window.vtAddRow = function (id) {
        const l = VT_LINES.find(x => x.id === id);
        if (!l) { return; }

        // نفس البند مرتين = كميتين على نفس الصف — بنركّز على الموجود
        const existing = document.querySelector('#vtRows tr[data-lid="' + id + '"]');
        if (existing) {
            existing.querySelector('[data-role="qty"]').focus();
            return;
        }

        const empty = document.getElementById('vtEmpty');
        if (empty) { empty.remove(); }

        const i = vtIdx++;
        const tr = document.createElement('tr');
        tr.dataset.lid = id;
        tr.dataset.avail = l.avail;
        tr.innerHTML =
            '<td><div style="display:flex;gap:10px;align-items:center">' +
                (l.image
                    ? '<img src="' + esc(l.image) + '" style="width:46px;height:46px;object-fit:contain;border-radius:10px;border:1px solid var(--border);background:#fff;flex-shrink:0">'
                    : '<div style="width:46px;height:46px;border-radius:10px;border:1px dashed var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);flex-shrink:0">📦</div>') +
                '<div><b>' + esc(l.name) + '</b>' +
                '<div style="font-size:10.5px;color:var(--muted)">' + esc(l.code) + '</div></div>' +
            '</div>' +
            '<input type="hidden" name="lines[' + i + '][custody_item_id]" value="' + l.id + '"></td>' +
            '<td><b>' + esc(l.batch || '—') + '</b>' +
                (l.exp ? '<div style="font-size:10px;color:var(--muted)" dir="ltr">' + esc(l.exp) + '</div>' : '') +
            '</td>' +
            '<td><span class="badge ' + esc(vtSrcClass(l.src)) + '">' + esc(l.src_label || '') + '</span>' +
                (l.src_ref ? '<div style="font-size:10px;color:var(--muted)" dir="ltr">' + esc(l.src_ref) + '</div>' : '') +
            '</td>' +
            '<td class="num" style="color:var(--muted)">' + Number(l.avail).toLocaleString() + '</td>' +
            '<td class="num"><input type="number" min="1" max="' + l.avail + '" value="' + l.avail + '"' +
                ' name="lines[' + i + '][qty]" required style="width:100%" data-role="qty" oninput="vtTotals()"></td>' +
            '<td class="num"><button type="button" class="btn sm" onclick="vtDrop(this)">✕</button></td>';

        document.getElementById('vtRows').appendChild(tr);
        tr.querySelector('[data-role="qty"]').focus();
        vtTotals();
    };

    function vtSrcClass(src) {
        return ({
            custody: 'b-blue',
            purchase_order: 'b-purple',
            transfer: 'b-orange',
        })[src] || 'b-gray';
    }

    window.vtDrop = function (btn) {
        btn.closest('tr').remove();
        vtTotals();
    };

    window.vtTotals = function () {
        let lines = 0;
        let pieces = 0;

        document.querySelectorAll('#vtRows tr[data-lid]').forEach(function (tr) {
            const el = tr.querySelector('[data-role="qty"]');
            const cap = Number(tr.dataset.avail || 0);
            let q = Number(el.value || 0);

            // ⚠️ سقف في الشاشة **وفي السيرفر** — ده عرض بس
            if (q > cap) { q = cap; el.value = cap; }

            lines++;
            pieces += q;
        });

        document.getElementById('vtKpiLines').textContent = lines;
        document.getElementById('vtKpiPieces').textContent = pieces.toLocaleString();
        document.getElementById('vtBtn').disabled = pieces === 0;
    };

    /** تغيير المندوب أو المخزن بيفضّي السطور — سطر من مندوب تاني غلط */
    window.vtSourceChanged = function () {
        // تغيير المصدر بيغيّر قايمة الوجهة كمان (مايحوّلش لنفسه)
        if (window.vtToRepOptions) vtToRepOptions();

        document.querySelectorAll('#vtRows tr[data-lid]').forEach(tr => tr.remove());

        if (!document.getElementById('vtEmpty')) {
            const tr = document.createElement('tr');
            tr.id = 'vtEmpty';
            tr.innerHTML = '<td colspan="6" style="text-align:center;color:var(--muted);padding:26px">'
                + @json(__('field.no_selected_hint')) + '</td>';
            document.getElementById('vtRows').appendChild(tr);
        }

        vtTotals();
    };

    window.vtKindChanged = function () {
        const rep = kind() === 'rep_rep';

        document.getElementById('vtWhBox').style.display = rep ? 'none' : '';
        document.getElementById('vtRepBox').style.display = rep ? '' : 'none';

        // ⚠️ **`disabled` مش بس `display:none`.** السيلكت المخفي بيتبعت
        // مع الفورم، فكان «مندوب ← مخزن» بيبعت `to_user_id` كمان
        // والسيرفر يشوف طرفين متناقضين.
        const wh = document.getElementById('vtWh');
        const toRep = document.getElementById('vtToRep');

        wh.disabled = rep;
        toRep.disabled = !rep;
        wh.required = !rep;
        toRep.required = rep;
        document.getElementById('vtKindHintText').textContent = rep ? VT_HINT_REP : VT_HINT_WH;

        // ⚠️ **لازم `change` بعد تغيير `disabled`** (إصلاح ١٤/٨): محسّن
        // القوايم القابلة للبحث بيرسم زرار بديل للسيلكت وبيزامن حالته
        // (`btn.disabled = sel.disabled`) في `refresh()` — واللي بتتنادى
        // من حدث `change` بس. إحنا بنغيّر `disabled` بالكود، فالزرار كان
        // بيفضل مقفول رمادي و«للمندوب مش بيختار حد» (بلاغ المالك).
        wh.dispatchEvent(new Event('change', { bubbles: false }));
        toRep.dispatchEvent(new Event('change', { bubbles: false }));

        vtToRepOptions();
        vtSourceChanged();
    };

    /**
     * ⚠️ **المندوب مايحوّلش لنفسه.** القايمتين بتتبنوا من نفس المصدر،
     * فأول اسم بيتختار في الاتنين تلقائياً — والمالك بيبص يلاقي
     * «من اسلام ← لاسلام». بنخبّي المصدر من قايمة الوجهة، ولو كان
     * هو المختار بننقل الاختيار لأول حد تاني.
     */
    window.vtToRepOptions = function () {
        const from = document.getElementById('vtFrom');
        const toRep = document.getElementById('vtToRep');
        if (!from || !toRep) return;

        let firstOther = null;

        Array.from(toRep.options).forEach(function (o) {
            const same = o.value === from.value;
            o.hidden = same;
            o.disabled = same;
            if (!same && firstOther === null) firstOther = o.value;
        });

        if (toRep.value === from.value && firstOther !== null) toRep.value = firstOther;

        // نفس السبب: الزرار البديل لازم يعرف إن الاختيار اتغيّر
        toRep.dispatchEvent(new Event('change', { bubbles: false }));
    };

    vtKindChanged();
})();
</script>
@endif
@endsection
