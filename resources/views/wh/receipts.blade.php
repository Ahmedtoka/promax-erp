@extends('layouts.system')

@section('title', __('stock.goods_receipts'))

@php
    $fmt = fn ($n) => number_format((float) $n);
    // ⚠️ **أمين المخزن لازم يشوف الأزرار دي — دي شغله.** كانت
    // `isManager()` وهو مش منهم، فالراوتس اتديتله والأزرار اتخبّت
    // عنه: مخزن للقراية بس.
    $manager = auth()->user()->canWorkWarehouse();

    // قايمة الأصناف بتتبني مرة واحدة هنا وبتتعاد في قالب البند — ممنوع لوب على المنتجات جوه الجافاسكريبت
    $productOptions = '<option value="">'.e(__('stock.choose_item')).'</option>';
    foreach ($products as $p) {
        $productOptions .= '<option value="'.(int) $p->id.'">'
            .e($p->code.' — '.$p->displayName())
            .'</option>';
    }

    // وحدات الإدخال لكل صنف — العرض بس؛ الضرب الحقيقي في السيرفر (storeReceipt)
    $unitMap = $products->mapWithKeys(fn ($p) => [$p->id => $p->unitFactors()]);
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
        <div class="tablewrap" style="max-height:44vh;overflow-y:auto;border:1px solid var(--border);border-radius:10px">
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
                <tbody id="grnRows"></tbody>
            </table>
        </div>

        <div style="margin-top:10px">
            <button class="btn" type="button" onclick="grnAddLine()">+ {{ __('stock.add_line') }}</button>
        </div>

        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgNewGrn')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>

{{-- قالب البند — الأوبشنز مبنية مرة واحدة في PHP فوق --}}
<template id="grnTpl">
    <tr>
        <td class="num grn-no"></td>
        <td>
            <select data-n="product_id" required style="min-width:210px;width:100%">{!! $productOptions !!}</select>
        </td>
        <td><input type="text" data-n="batch_no" required style="width:120px"></td>
        <td><input type="date" data-n="produced_on" style="width:150px"></td>
        <td><input type="date" data-n="expires_on" style="width:150px"></td>
        <td>
            <select data-n="unit" style="width:100px" onchange="grnUnitHint(this)">
                <option value="piece">{{ __('stock.unit_piece') }}</option>
            </select>
        </td>
        <td>
            <input type="number" data-n="qty" min="1" step="1" value="1" required style="width:90px" oninput="grnUnitHint(this)">
            <div class="grn-eq" style="font-size:10.5px;color:var(--muted);margin-top:3px"></div>
        </td>
        <td><input type="number" data-n="cost" min="0" step="0.01" style="width:100px"></td>
        <td><button class="btn sm red" type="button" onclick="grnRemoveLine(this)">{{ __('stock.remove_line') }}</button></td>
    </tr>
</template>
@endif

@endsection

@section('scripts')
@if ($manager)
<script>
// وحدات الإدخال لكل صنف — {id: {piece:1, box:12, case:72}}
// ⚠️ العرض بس: السيرفر بيعيد الضرب بنفسه في storeReceipt
const GRN_UNITS = {!! json_encode($unitMap, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) !!};
const GRN_UNIT_LABELS = {
    piece: @json(__('stock.unit_piece')),
    box: @json(__('stock.unit_box')),
    'case': @json(__('stock.unit_case'))
};

/** الصنف اتغيّر؟ نبني قايمة الوحدات المتاحة ليه (قطعة دايماً + علبة/كرتونة لو معرّفين) */
function grnUnitOptions(row) {
    var pid = row.querySelector('[data-n="product_id"]').value;
    var sel = row.querySelector('[data-n="unit"]');
    var factors = GRN_UNITS[pid] || { piece: 1 };
    var current = sel.value;

    sel.innerHTML = '';
    Object.keys(factors).forEach(function (u) {
        var opt = document.createElement('option');
        opt.value = u;
        opt.textContent = GRN_UNIT_LABELS[u] + (factors[u] > 1 ? ' (' + factors[u] + ')' : '');
        sel.appendChild(opt);
    });

    sel.value = factors[current] ? current : 'piece';
    grnUnitHint(sel);
}

/** «= N قطعة» تحت الكمية — بيبان بس لما الوحدة مش قطعة */
function grnUnitHint(el) {
    var row = el.closest('tr');
    var pid = row.querySelector('[data-n="product_id"]').value;
    var unit = row.querySelector('[data-n="unit"]').value;
    var qty = Number(row.querySelector('[data-n="qty"]').value || 0);
    var eq = row.querySelector('.grn-eq');
    var factor = (GRN_UNITS[pid] || {})[unit] || 1;

    eq.textContent = (factor > 1 && qty > 0)
        ? '= ' + (qty * factor).toLocaleString() + ' ' + GRN_UNIT_LABELS.piece
        : '';
}

document.addEventListener('change', function (e) {
    if (e.target.matches('#grnRows [data-n="product_id"]')) {
        grnUnitOptions(e.target.closest('tr'));
    }
});

function grnReindex() {
    document.querySelectorAll('#grnRows > tr').forEach(function (tr, i) {
        tr.querySelectorAll('[data-n]').forEach(function (el) {
            el.name = 'lines[' + i + '][' + el.dataset.n + ']';
        });
        var no = tr.querySelector('.grn-no');
        if (no) { no.textContent = i + 1; }
    });
}

function grnAddLine() {
    var tpl = document.getElementById('grnTpl');
    var row = tpl.content.firstElementChild.cloneNode(true);
    document.getElementById('grnRows').appendChild(row);
    grnReindex();
    var sel = row.querySelector('[data-n="product_id"]');
    if (sel) { sel.focus(); }
}

function grnRemoveLine(btn) {
    var rows = document.getElementById('grnRows');
    if (rows.children.length <= 1) { return; }
    btn.closest('tr').remove();
    grnReindex();
}

function grnBeforeSubmit() {
    grnReindex();
    return document.querySelectorAll('#grnRows > tr').length > 0;
}

document.addEventListener('DOMContentLoaded', function () {
    if (document.getElementById('grnRows')) { grnAddLine(); }
});
</script>
@endif
@endsection
