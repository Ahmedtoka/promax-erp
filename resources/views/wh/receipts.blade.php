@extends('layouts.system')

@section('title', __('stock.goods_receipts'))

@php
    $fmt = fn ($n) => number_format((float) $n);
    // ⚠️ **أمين المخزن لازم يشوف الأزرار دي — دي شغله.** كانت
    // `isManager()` وهو مش منهم، فالراوتس اتديتله والأزرار اتخبّت
    // عنه: مخزن للقراية بس.
    $manager = auth()->user()->canWorkWarehouse();

    // كتالوج البحث — بحث ليست بالاسمين والكود والصورة (بدل السيلكت الجاف).
    // وحدات الإدخال جوّاه للعرض بس؛ الضرب الحقيقي في السيرفر (storeReceipt).
    $catalog = $products->map(fn ($p) => [
        'id' => $p->id,
        'code' => (string) $p->code,
        'name' => $p->displayName(),
        'name_ar' => (string) $p->name,
        'name_en' => (string) $p->name_en,
        'image' => $p->imageSrc(),
        'units' => $p->unitFactors(),
    ])->values();
@endphp

@section('actions')
    <a class="btn" href="{{ route('wh.index', $warehouse ? ['warehouse' => $warehouse->id] : []) }}">🏭 {{ __('stock.warehouse_overview') }}</a>
    @if ($manager)
        @if (\App\Support\Access::action(auth()->user(), 'act.wh.receive'))
            <button class="btn" onclick="openDlg('dlgImpGrn')">⬆️ {{ __('stock.import_receipt') }}</button>
            <button class="btn gold" onclick="openDlg('dlgNewGrn')">+ {{ __('stock.new_receipt') }}</button>
        @endif
    @endif
@endsection

@section('content')

{{-- ═══ إذن استلام من شيت (2026-08-05) — نفس أعمدة ملف التصدير ═══ --}}
@if ($manager && \App\Support\Access::action(auth()->user(), 'act.wh.receive'))
<dialog id="dlgImpGrn">
    <form class="dlg" method="POST" action="{{ route('wh.receipts.import') }}" enctype="multipart/form-data">
        @csrf
        <h4>⬆️ {{ __('stock.import_receipt') }}</h4>
        <div class="alert info" style="margin-bottom:12px">
            <span>ℹ️</span><span>{{ __('stock.import_receipt_hint') }}</span>
        </div>
        <div class="frow">
            <div>
                <label class="f">{{ __('stock.warehouse') }} <b class="req-star">*</b></label>
                <select name="warehouse_id" required style="width:100%">
                    @foreach ($warehouses as $w)
                        <option value="{{ $w->id }}" @selected($warehouse && $warehouse->id === $w->id)>{{ $w->displayName() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="f">{{ __('stock.received_on') }} <b class="req-star">*</b></label>
                <input type="date" name="received_on" required value="{{ today()->toDateString() }}" style="width:100%">
            </div>
        </div>
        <div style="margin-top:10px">
            <label class="f">{{ __('stock.import_file') }} <b class="req-star">*</b></label>
            <input type="file" name="file" required accept=".xlsx,.xls,.csv" style="width:100%">
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgImpGrn')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">⬆️ {{ __('stock.import_receipt') }}</button>
        </div>
    </form>
</dialog>
@endif

@if ($warehouses->count() > 1)
    <div class="searchbar">
        <span style="font-size:11.5px;font-weight:800;color:var(--muted)">{{ __('stock.warehouses') }}</span>
        @foreach ($warehouses as $w)
            <a class="btn {{ $warehouse && $w->id === $warehouse->id ? 'gold' : '' }}"
               href="{{ route('wh.receipts', ['warehouse' => $w->id]) }}">{{ $w->displayName() }}</a>
        @endforeach
    </div>
@endif

@if ($warehouse === null)
    <div class="card"><div class="alert warn">{{ __('stock.no_warehouse') }}</div></div>
@else

<div class="card">
    <h3>📥 {{ __('stock.goods_receipts') }}
        <span class="side">{{ $warehouse->displayName() }} — {{ $warehouse->typeLabel() }}</span></h3>
    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('stock.receipt_number') }}</th>
                <th>{{ __('stock.received_on') }}</th>
                <th>{{ __('stock.supplier') }}</th>
                <th>{{ __('stock.batches') }}</th>
                <th>{{ __('stock.total_units') }}</th>
                <th>{{ __('common.status') }}</th>
                <th></th>
            </tr>
            @forelse ($receipts as $r)
                <tr class="clickable" onclick="location.href='{{ route('wh.receipt', $r) }}'">
                    <td class="num"><b>{{ $r->number }}</b>
                        @if ($r->reference)
                            <br><span style="font-size:10.5px;color:var(--muted)">{{ $r->reference }}</span>
                        @endif
                    </td>
                    <td class="num">{{ $r->received_on?->format('Y-m-d') ?? '—' }}</td>
                    <td>
                        @if ($r->sourceWarehouse)
                            <span class="badge b-blue">🔁 {{ $r->sourceWarehouse->displayName() }}</span>
                        @else
                            {{ $r->supplier ?: '—' }}
                        @endif
                    </td>
                    <td class="num">{{ $r->batches->count() }}</td>
                    <td class="num">{{ $fmt($r->totalQty()) }}</td>
                    <td>
                        @if ($r->isFullyShelved())
                            <span class="badge b-green">{{ __('stock.fully_shelved') }}</span>
                        @else
                            <span class="badge b-orange">{{ __('stock.partly_shelved') }} — {{ $fmt($r->unshelvedQty()) }}</span>
                        @endif
                    </td>
                    <td><a class="btn sm" href="{{ route('wh.receipt', $r) }}">{{ __('common.details') }}</a></td>
                </tr>
            @empty
                <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:28px">
                    {{ __('stock.no_receipts') }}
                </td></tr>
            @endforelse
        </table>
    </div>
    <div class="pag">{{ $receipts->links('pagination::simple-default') }}</div>
</div>

@endif

@if ($manager)
<dialog id="dlgNewGrn" class="wide">
    <form class="dlg" method="POST" action="{{ route('wh.receipts.store') }}" style="width:min(1040px,96vw)"
          onsubmit="return grnBeforeSubmit()">
        @csrf
        <h4>{{ __('stock.new_receipt') }}</h4>

        <div class="frow">
            <div>
                <label class="f">{{ __('stock.warehouse') }}</label>
                <select name="warehouse_id" required style="width:100%">
                    @foreach ($warehouses as $w)
                        <option value="{{ $w->id }}" @selected($warehouse && $w->id === $warehouse->id)>
                            {{ $w->displayName() }} — {{ $w->typeLabel() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="f">{{ __('stock.received_on') }}</label>
                <input type="date" name="received_on" value="{{ today()->toDateString() }}" required style="width:100%">
            </div>
            <div>
                <label class="f">{{ __('stock.supplier') }}</label>
                <input type="text" name="supplier" style="width:100%">
            </div>
            <div>
                <label class="f">{{ __('stock.reference') }}</label>
                <input type="text" name="reference" style="width:100%">
            </div>
        </div>

        <div style="margin-bottom:12px">
            <label class="f">{{ __('common.notes') }}</label>
            <textarea name="notes" rows="2" style="width:100%"></textarea>
        </div>

        <div class="alert info" style="margin-bottom:10px">
            <span>ℹ️</span><span>{{ __('stock.expiry_auto_hint') }}</span>
        </div>

        <h4 style="font-size:13.5px;margin-bottom:8px">{{ __('stock.receipt_lines') }}</h4>

        {{-- ═══ منتقي الأصناف: بحث ليست بدل السيلكت الجاف (ليست مش دروب داون) ═══
             نفس نمط تسليم العهدة/التحويلات. اختيار صنف بينزّل بند باتش
             ليه — ونفس الصنف ممكن يتضاف أكتر من مرة (باتشات مختلفة). --}}
        @include('partials._item_picker', [
            'id' => 'grn',
            'catalog' => $catalog,
            'onPick' => 'grnPickProduct',
        ])

        <div class="tablewrap" style="margin-top:10px;max-height:44vh;overflow-y:auto;border:1px solid var(--border);border-radius:10px">
            <table id="grnTbl">
                <thead>
                    <tr>
                        <th style="width:34px">#</th>
                        <th>{{ __('stock.item') }}</th>
                        <th>{{ __('stock.batch_no') }}</th>
                        <th>{{ __('stock.produced_on') }}</th>
                        <th>{{ __('stock.expires_on') }}</th>
                        <th>{{ __('stock.entry_unit') }}</th>
                        <th>{{ __('common.qty') }}</th>
                        <th>{{ __('stock.cost') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="grnRows">
                    <tr id="grnEmpty">
                        <td colspan="9" style="text-align:center;color:var(--muted);padding:24px">
                            {{ __('field.no_selected_hint') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgNewGrn')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>

@endif

@endsection

@section('scripts')
@if ($manager)
<script>
// الكتالوج (id, code, name, units...) بيتعرّض من بارشيال المنتقي
const GRN_CAT = window.PICKER_GRN || [];
const GRN_UNIT_LABELS = {
    piece: @json(__('stock.unit_piece')),
    box: @json(__('stock.unit_box')),
    'case': @json(__('stock.unit_case'))
};

const grnEsc = s => String(s ?? '').replace(/[&<>"']/g,
    ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));

function grnProduct(id) { return GRN_CAT.find(function (p) { return p.id === id; }); }

/** «= N قطعة» تحت الكمية — بيبان بس لما الوحدة مش قطعة */
function grnUnitHint(el) {
    var row = el.closest('tr');
    var pid = Number(row.dataset.pid);
    var unit = row.querySelector('[data-n="unit"]').value;
    var qty = Number(row.querySelector('[data-n="qty"]').value || 0);
    var eq = row.querySelector('.grn-eq');
    var factor = ((grnProduct(pid) || {}).units || {})[unit] || 1;

    eq.textContent = (factor > 1 && qty > 0)
        ? '= ' + (qty * factor).toLocaleString() + ' ' + GRN_UNIT_LABELS.piece
        : '';
}

/** بترقّم البنود وتحدّث أسماء الحقول lines[i][...] — بتشيل أي فراغ في الترتيب */
function grnReindex() {
    document.querySelectorAll('#grnRows > tr[data-pid]').forEach(function (tr, i) {
        tr.querySelectorAll('[data-n]').forEach(function (el) {
            el.name = 'lines[' + i + '][' + el.dataset.n + ']';
        });
        var no = tr.querySelector('.grn-no');
        if (no) { no.textContent = i + 1; }
    });
}

/**
 * اختيار صنف من الليست → بند باتش جديد ليه.
 * ⚠️ **مفيش منع تكرار** — نفس الصنف ممكن يوصل في أكتر من باتش
 * (رقم/تاريخ مختلف)، والسيرفر بيجمّعهم بـ product+batch_no.
 */
function grnPickProduct(id) {
    var p = grnProduct(id);
    if (!p) { return; }

    grnPickerReset();                       // من البارشيال — يفضّي البحث ويقفل القايمة
    document.getElementById('grnEmpty')?.remove();

    var units = p.units || { piece: 1 };
    // الديفولت الموحّد: علبة لو موجودة، وإلا قطعة
    var def = units.box ? 'box' : 'piece';

    var tr = document.createElement('tr');
    tr.dataset.pid = id;
    tr.innerHTML =
        '<td class="num grn-no"></td>' +
        '<td><div style="display:flex;gap:9px;align-items:center">' +
            (p.image
                ? '<img src="' + grnEsc(p.image) + '" style="width:48px;height:48px;object-fit:contain;border-radius:8px;border:1px solid var(--border);background:#fff;flex-shrink:0">'
                : '<div style="width:48px;height:48px;border-radius:8px;border:1px dashed var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);flex-shrink:0">📦</div>') +
            '<div><b>' + grnEsc(p.name) + '</b><div style="font-size:10.5px;color:var(--muted)">' + grnEsc(p.code) + '</div></div>' +
        '</div>' +
        '<input type="hidden" data-n="product_id" value="' + id + '"></td>' +
        '<td><input type="text" data-n="batch_no" required style="width:120px"></td>' +
        '<td><input type="date" data-n="produced_on" style="width:150px"></td>' +
        '<td><input type="date" data-n="expires_on" style="width:150px"></td>' +
        '<td><select data-n="unit" style="width:110px" onchange="grnUnitHint(this)">' +
            Object.keys(units).map(function (u) {
                return '<option value="' + u + '"' + (u === def ? ' selected' : '') + '>' +
                    grnEsc(GRN_UNIT_LABELS[u]) + (units[u] > 1 ? ' (' + units[u] + ')' : '') + '</option>';
            }).join('') +
        '</select></td>' +
        '<td><input type="number" data-n="qty" min="1" step="1" value="1" required style="width:90px" oninput="grnUnitHint(this)">' +
            '<div class="grn-eq" style="font-size:10.5px;color:var(--muted);margin-top:3px"></div></td>' +
        '<td><input type="number" data-n="cost" min="0" step="0.01" style="width:100px"></td>' +
        '<td><button class="btn sm red" type="button" onclick="grnRemoveLine(this)">' + grnEsc(@json(__('stock.remove_line'))) + '</button></td>';

    document.getElementById('grnRows').appendChild(tr);
    grnReindex();
    tr.querySelector('[data-n="batch_no"]').focus();
}

function grnRemoveLine(btn) {
    btn.closest('tr').remove();
    grnReindex();
    // رجّع صف «فاضي» لو مبقاش فيه بنود
    if (!document.querySelector('#grnRows > tr[data-pid]')) {
        document.getElementById('grnRows').innerHTML =
            '<tr id="grnEmpty"><td colspan="9" style="text-align:center;color:var(--muted);padding:24px">' +
            grnEsc(@json(__('field.no_selected_hint'))) + '</td></tr>';
    }
}

function grnBeforeSubmit() {
    grnReindex();
    return document.querySelectorAll('#grnRows > tr[data-pid]').length > 0;
}
</script>
@endif
@endsection
