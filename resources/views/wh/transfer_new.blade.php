@extends('layouts.system')

{{--
    تحويل جديد — صفحة كاملة (2026-08-06) بدل الدايالوج:

    من ← إلى ← معاد ← ناقل ← بحث بالصور ← صف لكل بند (صورة +
    باتش FEFO من المخزن المرسل + وحدة + كمية) ← ملخصات لايف
    (سطور/قطع) ← حفظ. بيبعت لنفس `storeTransfer` زي ما هو.
--}}

@php
    $fmt = fn ($n) => number_format((float) $n);

    // الكتالوج للبحث بالصور — والباتشات الحقيقية بتغذّي قايمة الباتش
    $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP;
    $catalog = json_encode($products->map(fn ($p) => [
        'id' => $p->id,
        'code' => (string) $p->code,
        'name' => $p->displayName(),
        'name_ar' => (string) $p->name,
        'name_en' => (string) $p->name_en,
        'image' => $p->imageSrc(),
        'units' => $p->unitFactors(),
    ])->values(), $jsonFlags);

    $batchData = json_encode($batches->map(fn ($b) => [
        'id' => $b->id,
        'w' => (int) $b->warehouse_id,
        'p' => (int) $b->product_id,
        'no' => $b->batch_no,
        'prod' => $b->produced_on?->toDateString(),
        'exp' => $b->expires_on?->toDateString(),
        'left' => (int) $b->qty_remaining,
    ])->values(), $jsonFlags);
@endphp

@section('title', __('stock.new_transfer'))

@section('actions')
    <a class="btn" href="{{ route('wh.transfers') }}">← {{ __('stock.transfers') }}</a>
@endsection

@section('content')

<div class="card">
    <h3>🚚 {{ __('stock.new_transfer') }}
        <span class="side">{{ __('stock.transfer_hint') }}</span></h3>

    @if ($errors->any())
        <div class="alert" style="margin-bottom:12px;flex-direction:column;align-items:stretch;gap:4px">
            @foreach ($errors->all() as $msg)
                <div class="errline" style="margin:0">{{ $msg }}</div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('wh.transfers.store') }}" id="trForm">
        @csrf

        <div class="frow">
            <div>
                <label class="f">{{ __('stock.from_warehouse') }} <b class="req-star">*</b></label>
                <select name="from_warehouse_id" id="trFrom" required style="width:100%">
                    @foreach ($warehouses as $w)
                        <option value="{{ $w->id }}">{{ $w->displayName() }} — {{ $w->typeLabel() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="f">{{ __('stock.to_warehouse') }} <b class="req-star">*</b></label>
                <select name="to_warehouse_id" required style="width:100%">
                    @foreach ($warehouses as $w)
                        <option value="{{ $w->id }}" @selected($loop->index === 1)>{{ $w->displayName() }} — {{ $w->typeLabel() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="f">{{ __('stock.sent_on') }} <b class="req-star">*</b></label>
                <input type="date" name="sent_on" value="{{ today()->toDateString() }}" required style="width:100%">
            </div>
            <div>
                {{-- اللي بيشيل مش دايماً يوزر في السيستم — اسم نصي --}}
                <label class="f">{{ __('stock.carrier') }}</label>
                <input type="text" name="carrier_name" maxlength="120" style="width:100%"
                       placeholder="{{ __('stock.carrier_ph') }}">
            </div>
        </div>

        {{-- ═══ ملخصات لايف — البوكسات بتتحدث مع كل سطر ═══ --}}
        <div class="kpis" style="margin-top:12px">
            <div class="kpi">
                <div class="lbl">{{ __('stock.transfer_lines') }}</div>
                <div class="val" id="trKpiLines">0</div>
            </div>
            <div class="kpi">
                <div class="lbl">{{ __('stock.total_pieces') }}</div>
                <div class="val pos" id="trKpiPieces">0</div>
                <div class="sub2">{{ __('stock.units') }}</div>
            </div>
        </div>

        {{-- ═══ البحث بالصور — نفس نمط تسليم العهدة ═══ --}}
        <div style="position:relative;margin-top:12px">
            <input type="search" id="trSearch" autocomplete="off" style="width:100%"
                   placeholder="🔍 {{ __('field.search_product_ph') }}"
                   oninput="trSearchNow()" onfocus="trSearchNow()">
            <div id="trResults" style="display:none;position:absolute;top:100%;inset-inline:0;z-index:30;background:#fff;border:1px solid var(--border);border-radius:10px;box-shadow:0 10px 26px rgba(0,0,0,.12);max-height:300px;overflow-y:auto"></div>
        </div>

        <div class="tablewrap" style="margin-top:12px;max-height:52vh;overflow-y:auto">
            <table>
                <thead>
                    <tr>
                        <th>{{ __('stock.item') }}</th>
                        <th style="width:190px">{{ __('stock.batch_no') }}</th>
                        <th class="num" style="width:100px">{{ __('stock.produced_on') }}</th>
                        <th class="num" style="width:90px">{{ __('stock.available') }}</th>
                        <th style="width:110px">{{ __('stock.entry_unit') }}</th>
                        <th class="num" style="width:110px">{{ __('common.qty') }}</th>
                        <th style="width:40px"></th>
                    </tr>
                </thead>
                <tbody id="trRows">
                    <tr id="trEmpty">
                        <td colspan="7" style="text-align:center;color:var(--muted);padding:26px">
                            {{ __('field.no_selected_hint') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div style="margin:12px 0">
            <label class="f">{{ __('common.notes') }}</label>
            <textarea name="notes" rows="2" style="width:100%"></textarea>
        </div>

        <div style="display:flex;gap:8px;justify-content:flex-end">
            <a class="btn" href="{{ route('wh.transfers') }}">{{ __('common.cancel') }}</a>
            <button class="btn gold" type="submit" id="trBtn" disabled>🚚 {{ __('common.save') }}</button>
        </div>
    </form>
</div>

@endsection

@section('scripts')
<script>
const TR_CATALOG = {!! $catalog !!};
const TR_BATCHES = {!! $batchData !!};
const TR_UNIT_LABELS = {
    piece: @json(__('stock.unit_piece')),
    box: @json(__('stock.unit_box')),
    'case': @json(__('stock.unit_case'))
};
const TR_NO_BATCH = @json(__('stock.no_batches_here'));
let trIdx = 0;

const esc = s => String(s ?? '').replace(/[&<>"']/g,
    ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));

function trFromId() { return Number(document.getElementById('trFrom').value || 0); }

/** إجمالي المتاح للصنف في المخزن المرسل — للبحث */
function trAvail(pid) {
    return TR_BATCHES.filter(b => b.p === pid && b.w === trFromId())
        .reduce((s, b) => s + b.left, 0);
}

function trSearchNow() {
    const q = document.getElementById('trSearch').value.trim().toLowerCase();
    const box = document.getElementById('trResults');
    // اللي ليه رصيد في المخزن المرسل بس — مفيش تحويل من العدم
    const hits = TR_CATALOG.filter(p => trAvail(p.id) > 0).filter(p =>
        !q || p.name.toLowerCase().includes(q) || p.name_ar.includes(q)
        || p.name_en.toLowerCase().includes(q) || p.code.toLowerCase().includes(q));

    box.style.display = 'block';
    box.innerHTML = hits.length === 0
        ? '<div style="padding:14px;text-align:center;color:var(--muted)">' + @json(__('common.no_results')) + '</div>'
        : hits.map(p =>
            '<div onclick="trAddRow(' + p.id + ')" style="display:flex;gap:10px;align-items:center;padding:9px 12px;cursor:pointer;border-bottom:1px solid var(--border)">' +
            (p.image ? '<img src="' + esc(p.image) + '" style="width:48px;height:48px;object-fit:contain;border-radius:6px;border:1px solid var(--border)">' : '') +
            '<div style="flex:1"><b style="font-size:12.5px">' + esc(p.name) + '</b>' +
            '<div style="font-size:10.5px;color:var(--muted)">' + esc(p.code) + ' · ' + @json(__('stock.available')) + ' ' + trAvail(p.id).toLocaleString() + '</div></div>' +
            '</div>').join('');
}

document.addEventListener('click', e => {
    if (!e.target.closest('#trSearch') && !e.target.closest('#trResults')) {
        document.getElementById('trResults').style.display = 'none';
    }
});

/** صف بند جديد — الباتشات FEFO (الأقرب انتهاءً الأول) من المخزن المرسل */
function trAddRow(pid) {
    const p = TR_CATALOG.find(x => x.id === pid);
    if (!p) return;

    document.getElementById('trResults').style.display = 'none';
    document.getElementById('trSearch').value = '';
    document.getElementById('trEmpty')?.remove();

    const i = trIdx++;
    const units = p.units || { piece: 1 };
    const tr = document.createElement('tr');
    tr.dataset.pid = pid;
    tr.innerHTML =
        '<td><div style="display:flex;gap:10px;align-items:center">' +
            (p.image
                ? '<img src="' + esc(p.image) + '" style="width:52px;height:52px;object-fit:contain;border-radius:10px;border:1px solid var(--border);background:#fff;flex-shrink:0">'
                : '<div style="width:52px;height:52px;border-radius:10px;border:1px dashed var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);flex-shrink:0">📦</div>') +
            '<div><b>' + esc(p.name) + '</b><div style="font-size:10.5px;color:var(--muted)">' + esc(p.code) + '</div></div>' +
        '</div>' +
        '<input type="hidden" name="lines[' + i + '][product_id]" value="' + pid + '"></td>' +
        '<td><select name="lines[' + i + '][source_batch_id]" required style="width:100%"' +
            ' data-role="batch" onchange="trShowBatch(this)"></select></td>' +
        '<td class="num" data-role="prodOn" style="color:var(--muted);font-size:11.5px">—</td>' +
        '<td class="num" data-role="left" style="color:var(--muted);font-size:11.5px">—</td>' +
        '<td><select name="lines[' + i + '][unit]" data-role="unit" style="width:100%" onchange="trUnitHint(this)">' +
            Object.keys(units).map(u => '<option value="' + u + '">' + esc(TR_UNIT_LABELS[u]) +
                (units[u] > 1 ? ' (' + units[u] + ')' : '') + '</option>').join('') +
        '</select></td>' +
        '<td class="num"><input type="number" min="1" name="lines[' + i + '][qty]" required style="width:100%"' +
            ' data-role="qty" oninput="trUnitHint(this); trTotals()">' +
            '<div data-role="eq" style="font-size:10px;color:var(--muted);margin-top:2px"></div></td>' +
        '<td class="num"><button type="button" class="btn sm" onclick="this.closest(\'tr\').remove(); trTotals()">✕</button></td>';

    document.getElementById('trRows').appendChild(tr);
    trFillBatches(tr);
    tr.querySelector('[data-role="qty"]').focus();
    trTotals();
}

function trRowFactor(tr) {
    const p = TR_CATALOG.find(x => x.id === Number(tr.dataset.pid));
    const unit = tr.querySelector('[data-role="unit"]').value;

    return ((p || {}).units || {})[unit] || 1;
}

/** باتشات الصنف في المخزن المرسل — FEFO جاهزة من السيرفر */
function trFillBatches(tr) {
    const pid = Number(tr.dataset.pid);
    const sel = tr.querySelector('[data-role="batch"]');
    const rows = TR_BATCHES.filter(b => b.p === pid && b.w === trFromId());

    sel.innerHTML = '';
    // ⚠️ الكمية بتتفضّى مع تغيير المخزن — كمية اتكتبت لباتش ماينفعش
    // تتبعت لباتش تاني من غير ما المستخدم ياخد باله
    tr.querySelector('[data-role="qty"]').value = '';

    if (rows.length === 0) {
        sel.innerHTML = '<option value="">' + esc(TR_NO_BATCH) + '</option>';
        tr.querySelector('[data-role="prodOn"]').textContent = '—';
        tr.querySelector('[data-role="left"]').textContent = '—';
        return;
    }

    rows.forEach(b => {
        const opt = document.createElement('option');
        opt.value = b.id;
        opt.textContent = b.no + ' · ' + (b.exp || '—') + ' · ' + b.left.toLocaleString();
        sel.appendChild(opt);
    });

    trShowBatch(sel);
}

function trShowBatch(sel) {
    const tr = sel.closest('tr');
    const batch = TR_BATCHES.find(b => String(b.id) === String(sel.value));

    tr.querySelector('[data-role="prodOn"]').textContent = batch ? (batch.prod || '—') : '—';
    tr.querySelector('[data-role="left"]').textContent = batch ? batch.left.toLocaleString() : '—';
    trUnitHint(tr.querySelector('[data-role="qty"]'));
}

/** «= N قطعة» + الحد الأقصى بوحدة السطر (كرتونة = المتاح ÷ 12) */
function trUnitHint(el) {
    const tr = el.closest('tr');
    const qtyEl = tr.querySelector('[data-role="qty"]');
    const eq = tr.querySelector('[data-role="eq"]');
    const factor = trRowFactor(tr);
    const batch = TR_BATCHES.find(b => String(b.id) === String(tr.querySelector('[data-role="batch"]').value));
    const qty = Number(qtyEl.value || 0);

    qtyEl.max = batch ? Math.floor(batch.left / factor) : '';
    eq.textContent = (factor > 1 && qty > 0)
        ? '= ' + (qty * factor).toLocaleString() + ' ' + TR_UNIT_LABELS.piece
        : '';
    trTotals();
}

/** الملخصات اللايف: عدد السطور + إجمالي القطع */
function trTotals() {
    let lines = 0, pieces = 0;

    document.querySelectorAll('#trRows tr[data-pid]').forEach(tr => {
        lines++;
        pieces += Number(tr.querySelector('[data-role="qty"]').value || 0) * trRowFactor(tr);
    });

    document.getElementById('trKpiLines').textContent = lines;
    document.getElementById('trKpiPieces').textContent = pieces.toLocaleString();
    document.getElementById('trBtn').disabled = pieces === 0;
}

// تغيير المخزن المرسل بيعيد ملا كل قوايم الباتشات
document.getElementById('trFrom').addEventListener('change', () => {
    document.querySelectorAll('#trRows tr[data-pid]').forEach(trFillBatches);
    trTotals();
});
</script>
@endsection
