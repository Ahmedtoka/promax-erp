@extends('layouts.system')

{{--
    تسليم PO للمندوب — فلو الكي أكاونت (قرار المالك 2026-08-04):

    سلسلة ← فرع ← مندوب ← مخزن التجهيز ← معاد التوريد (يوم وساعة)
    ← أصناف بالوحدات (قطعة/علبة/كرتونة) ← «إرسال للحسابات».

    ⚠️ **مفيش بضاعة بتتحجز هنا** — أمر التجهيز بيتعمل لما الحسابات
    توافق. والمندوب مابيتبلغش غير لما المخزن يجهّز.
--}}

@php
    $fmt = fn ($n) => number_format((float) $n);

    $catalog = $products->map(fn ($p) => [
        'id' => $p->id,
        'code' => (string) $p->code,
        'name' => $p->displayName(),
        'name_ar' => (string) $p->name,
        'name_en' => (string) $p->name_en,
        'image' => $p->imageSrc(),
        'available' => (int) $p->qtyTotal(),
        'units' => $p->unitFactors(),
    ])->values();

    $branches = $clients->map(fn ($c) => [
        'id' => $c->id,
        'name' => $c->fullName(),
        'group' => (int) $c->group_id,
        'balance' => (float) $c->balance,
    ])->values();

    $oldRows = collect(old('qty', []))->keys()->unique()->values();

    // ═══ وضع التعديل: نفس الشاشة متملية ببيانات أمر pending ═══
    $edit = $editing ?? null;
    $editRows = $edit?->items->map(fn ($i) => [
        'id' => $i->product_id,
        'qty' => (int) $i->qty,   // بالقطع — الوحدة بترجع «قطعة»
    ])->values() ?? collect();
@endphp

@section('title', $edit ? __('ops.po_edit').' '.$edit->number : __('ops.po_handout'))

@section('content')

<div class="card">
    <h3>📦 {{ $edit ? __('ops.po_edit').' '.$edit->number : __('ops.po_handout') }}
        <span class="side">{{ __('ops.po_handout_hint') }}</span></h3>

    @if ($errors->any())
        <div class="alert" style="margin-bottom:12px;flex-direction:column;align-items:stretch;gap:4px">
            @foreach ($errors->all() as $msg)
                <div class="errline" style="margin:0">{{ $msg }}</div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ $edit ? route('ops.po.update', $edit) : route('ops.pos.store') }}" id="poForm">
        @csrf
        <input type="hidden" name="approval" value="1">
        <input type="hidden" name="price_mode" value="channel">

        <div class="frow">
            <div>
                <label class="f">{{ __('nav.chains') }} <b class="req-star">*</b></label>
                <select id="poChain" style="width:100%" onchange="poFilterBranches()">
                    <option value="">—</option>
                    @foreach ($groups as $g)
                        <option value="{{ $g->id }}">{{ $g->displayName() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="f">{{ __('ops.branch_client') }} <b class="req-star">*</b></label>
                <select name="client_id" id="poBranch" required style="width:100%" onchange="poShowBalance()">
                    <option value="">—</option>
                </select>
                {{-- رصيد الفرع قدام مدير القناة من دلوقتي — قبل ما الحسابات ترفض --}}
                <div id="poBalance" style="font-size:11px;font-weight:800;margin-top:5px"></div>
            </div>
            <div>
                <label class="f">{{ __('ops.rep') }} <b class="req-star">*</b></label>
                <select name="assigned_to" required style="width:100%">
                    <option value="">—</option>
                    @foreach ($reps as $r)
                        <option value="{{ $r->id }}" @selected(old('assigned_to', $edit?->assigned_to) == $r->id)>{{ $r->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="frow">
            <div>
                <label class="f">{{ __('stock.warehouse') }} <b class="req-star">*</b></label>
                <select name="warehouse_id" required style="width:100%">
                    @foreach ($warehouses as $w)
                        <option value="{{ $w->id }}" @selected(old('warehouse_id', $edit?->warehouse_id) == $w->id)>{{ $w->displayName() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="f">{{ __('ops.due_at') }} <b class="req-star">*</b></label>
                {{-- باليوم **والساعة** — معاد استلام الفرع للبضاعة --}}
                <input type="datetime-local" name="due_at" required style="width:100%" value="{{ old('due_at', $edit?->due_at?->format('Y-m-d\\TH:i')) }}">
            </div>
            <div>
                <label class="f">{{ __('ops.source') }}</label>
                <input type="text" name="source" maxlength="40" style="width:100%"
                       placeholder="{{ __('ops.source_ph') }}" value="{{ old('source', $edit?->source) }}">
            </div>
        </div>

        {{-- ═══ الأصناف: بحث ← صف بكمية ووحدة ═══ --}}
        <div style="position:relative;margin-top:14px">
            <input type="text" id="prodSearch" autocomplete="off" style="width:100%"
                   placeholder="🔍 {{ __('field.search_product_ph') }}"
                   oninput="poSearch()" onfocus="poSearch()">
            <div id="prodResults" style="display:none;position:absolute;top:100%;inset-inline:0;z-index:30;background:#fff;border:1px solid var(--border);border-radius:10px;box-shadow:0 10px 26px rgba(0,0,0,.12);max-height:300px;overflow-y:auto"></div>
        </div>

        {{-- الهيدر ثابت + الصورة جوه خانة الصنف — نفس نمط تسليم العهدة --}}
        <div class="tablewrap" style="margin-top:12px;max-height:56vh;overflow-y:auto">
            <table>
                <thead style="position:sticky;top:0;z-index:5;background:var(--card, #fff);box-shadow:0 1px 0 var(--border)">
                    <tr>
                        <th>{{ __('stock.item') }}</th>
                        <th class="num">{{ __('stock.available') }}</th>
                        <th style="width:110px">{{ __('stock.entry_unit') }}</th>
                        <th class="num" style="width:110px">{{ __('common.qty') }}</th>
                        <th class="num">{{ __('common.total') }}</th>
                        <th style="width:40px"></th>
                    </tr>
                </thead>
                <tbody id="selBody">
                    <tr id="selEmpty">
                        <td colspan="6" style="text-align:center;color:var(--muted);padding:26px">
                            {{ __('field.no_selected_hint') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div style="display:flex;gap:10px;align-items:center;justify-content:flex-end;margin-top:14px">
            <div style="font-size:12.5px;color:var(--muted)">
                {{ __('common.total') }}: <b id="grand" style="color:var(--ink)">0</b> {{ __('stock.unit_piece') }}
            </div>
            <button class="btn gold" type="submit" id="poBtn" disabled>{{ $edit ? '💾 '.__('ops.save_edit') : '📨 '.__('ops.send_to_accounting') }}</button>
        </div>
    </form>
</div>

@endsection

@section('scripts')
<script>
const CATALOG = {!! json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) !!};
const BRANCHES = {!! json_encode($branches, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) !!};
const OLD_ROWS = {!! json_encode($oldRows, JSON_UNESCAPED_UNICODE) !!};
const OLD_QTY = {!! json_encode(old('qty', new stdClass), JSON_UNESCAPED_UNICODE) !!};
const OLD_UNIT = {!! json_encode(old('unit', new stdClass), JSON_UNESCAPED_UNICODE) !!};
const OLD_BRANCH = @json(old('client_id'));
// وضع التعديل — الصفوف والفرع والسلسلة بتوع الأمر المفتوح
const EDIT_ROWS = {!! json_encode($editRows, JSON_UNESCAPED_UNICODE) !!};
const EDIT_BRANCH = @json($edit?->client_id);
const EDIT_CHAIN = @json($edit?->client?->group_id);

const UNIT_LABELS = {
    piece: @json(__('stock.unit_piece')),
    box: @json(__('stock.unit_box')),
    'case': @json(__('stock.unit_case'))
};

const esc = s => String(s ?? '').replace(/[&<>"']/g,
    ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));

/** الفروع بتتفلتر بالسلسلة المختارة */
function poFilterBranches() {
    const chain = Number(document.getElementById('poChain').value || 0);
    const sel = document.getElementById('poBranch');
    const current = sel.value;

    sel.innerHTML = '<option value="">—</option>';
    BRANCHES.filter(b => !chain || b.group === chain).forEach(b => {
        const opt = document.createElement('option');
        opt.value = b.id;
        opt.textContent = b.name;
        sel.appendChild(opt);
    });

    sel.value = current;
    poShowBalance();
}

/** رصيد الفرع: موجب = عليه فلوس، سالب = ليه رصيد */
function poShowBalance() {
    const b = BRANCHES.find(x => String(x.id) === document.getElementById('poBranch').value);
    const el = document.getElementById('poBalance');

    if (!b) { el.textContent = ''; return; }

    el.style.color = b.balance > 0 ? 'var(--red, #B00020)' : 'var(--green, #1B7A3D)';
    el.textContent = b.balance > 0
        ? @json(__('ops.branch_owes')) + ' ' + b.balance.toLocaleString()
        : @json(__('ops.branch_credit')) + ' ' + Math.abs(b.balance).toLocaleString();
}

function poSearch() {
    const q = document.getElementById('prodSearch').value.trim().toLowerCase();
    const box = document.getElementById('prodResults');
    const hits = CATALOG.filter(p =>
        !q || p.name.toLowerCase().includes(q) || p.name_ar.includes(q)
        || p.name_en.toLowerCase().includes(q) || p.code.toLowerCase().includes(q));

    box.style.display = 'block';
    box.innerHTML = hits.length === 0
        ? '<div style="padding:14px;text-align:center;color:var(--muted)">' + @json(__('common.no_results')) + '</div>'
        : hits.map(p =>
            '<div onclick="addRow(' + p.id + ')" style="display:flex;gap:10px;align-items:center;padding:9px 12px;cursor:pointer;border-bottom:1px solid var(--border)">' +
            (p.image ? '<img src="' + esc(p.image) + '" style="width:52px;height:52px;object-fit:contain;border-radius:6px;border:1px solid var(--border)">' : '') +
            '<div style="flex:1"><b style="font-size:12.5px">' + esc(p.name) + '</b>' +
            '<div style="font-size:10.5px;color:var(--muted)">' + esc(p.code) + ' · ' + @json(__('stock.available')) + ' ' + p.available.toLocaleString() + '</div></div>' +
            '</div>').join('');
}

document.addEventListener('click', e => {
    if (!e.target.closest('#prodSearch') && !e.target.closest('#prodResults')) {
        document.getElementById('prodResults').style.display = 'none';
    }
});

function unitSelect(p) {
    const units = p.units || { piece: 1 };

    return '<select name="unit[' + p.id + ']" data-row="' + p.id + '" data-kind="unit"' +
        ' style="width:100%" onchange="syncRow(' + p.id + ')">' +
        Object.keys(units).map(u =>
            '<option value="' + u + '">' + esc(UNIT_LABELS[u]) +
            (units[u] > 1 ? ' (' + units[u] + ')' : '') + '</option>'
        ).join('') +
        '</select>';
}

function rowFactor(id) {
    const p = CATALOG.find(x => x.id === id);
    const sel = document.querySelector('[data-row="' + id + '"][data-kind="unit"]');

    return (p && p.units && sel && p.units[sel.value]) || 1;
}

function addRow(id) {
    const p = CATALOG.find(x => x.id === id);
    if (!p) return;

    document.getElementById('prodResults').style.display = 'none';
    document.getElementById('prodSearch').value = '';

    const existing = document.querySelector('[data-row="' + id + '"][data-kind="qty"]');
    if (existing) { existing.focus(); return; }

    document.getElementById('selEmpty')?.remove();

    const tr = document.createElement('tr');
    tr.id = 'row' + id;
    // الصورة جوه خانة الصنف — مش عمود لوحدها بمسافة فاضية
    tr.innerHTML =
        '<td><div style="display:flex;gap:10px;align-items:center">' +
            (p.image
                ? '<img src="' + esc(p.image) + '" style="width:56px;height:56px;object-fit:contain;border-radius:10px;border:1px solid var(--border);background:#fff;flex-shrink:0">'
                : '<div style="width:56px;height:56px;border-radius:10px;border:1px dashed var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);flex-shrink:0">📦</div>') +
            '<div><b>' + esc(p.name) + '</b><div style="font-size:10.5px;color:var(--muted)">' + esc(p.code) + '</div></div>' +
        '</div></td>' +
        '<td class="num">' + p.available.toLocaleString() + '</td>' +
        '<td>' + unitSelect(p) + '</td>' +
        '<td class="num"><input type="number" min="0" style="width:100%"' +
            ' name="qty[' + id + ']" data-row="' + id + '" data-kind="qty" oninput="syncRow(' + id + ')"></td>' +
        '<td class="num" id="tot' + id + '">—</td>' +
        '<td class="num"><button type="button" class="btn sm" onclick="removeRow(' + id + ')">✕</button></td>';

    document.getElementById('selBody').appendChild(tr);
    tr.querySelector('[data-kind="qty"]').focus();
}

function removeRow(id) {
    document.getElementById('row' + id)?.remove();
    syncTotals();
}

/** إجمالي السطر **بالقطع** — العرض بس؛ السيرفر بيعيد الضرب بنفسه */
function syncRow(id) {
    const qty = document.querySelector('[data-row="' + id + '"][data-kind="qty"]');
    const cell = document.getElementById('tot' + id);
    if (!qty || !cell) return;

    const pieces = Number(qty.value || 0) * rowFactor(id);

    cell.innerHTML = pieces === 0 ? '—'
        : '<b>' + pieces.toLocaleString() + '</b> <span style="font-size:10px;color:var(--muted)">' + esc(UNIT_LABELS.piece) + '</span>';

    syncTotals();
}

function syncTotals() {
    let total = 0;

    document.querySelectorAll('[data-kind="qty"]').forEach(q => {
        total += Number(q.value || 0) * rowFactor(Number(q.dataset.row));
    });

    document.getElementById('grand').textContent = total.toLocaleString();
    document.getElementById('poBtn').disabled = total === 0;
}

// استرجاع بعد فاليديشن مرفوضة — أو تعبئة وضع التعديل
if (EDIT_CHAIN && !OLD_ROWS.length) { document.getElementById('poChain').value = String(EDIT_CHAIN); }
poFilterBranches();
if (OLD_BRANCH) { document.getElementById('poBranch').value = String(OLD_BRANCH); poShowBalance(); }
else if (EDIT_BRANCH) { document.getElementById('poBranch').value = String(EDIT_BRANCH); poShowBalance(); }

// صفوف الأمر المفتوح للتعديل — بالقطع (الوحدة بترجع «قطعة»)
if (!OLD_ROWS.length) {
    EDIT_ROWS.forEach(r => {
        addRow(r.id);
        const q = document.querySelector('[data-row="' + r.id + '"][data-kind="qty"]');
        if (q) q.value = r.qty;
        syncRow(r.id);
    });
}
OLD_ROWS.forEach(id => {
    addRow(Number(id));
    const q = document.querySelector('[data-row="' + id + '"][data-kind="qty"]');
    if (q) q.value = OLD_QTY[id] ?? '';
    const u = document.querySelector('[data-row="' + id + '"][data-kind="unit"]');
    if (u && OLD_UNIT[id]) u.value = OLD_UNIT[id];
    syncRow(Number(id));
});
syncTotals();
</script>
@endsection
