@extends('layouts.system')

{{--
    فورم الكوتيشن (إعادة بناء ٢٣ أغسطس ٢٠٢٦ — ترتيب المالك):

    ١. اسم العميل وجمبه دروب داون قايمة الأسعار (الافتراضية متعلّمة
       أوتوماتيك) — تغيير القايمة بيعيد تسعير الصفوف النازلة.
    ٢. الأصناف مخفية ورا خانة البحث — المنتقي المشترك بالتشيك بوكس
       (نفس نمط تسليم العهدة): علّم علّم علّم ← «إضافة» ← تنزل جدول.
    ٣. تحت الجدول جمب التوتال: العرض ساري (٣٠ يوم أوتوماتيك) +
       خصم خاص % (صفر) + ضريبة % (صفر والمالك بيكتبها لو فيه).
--}}

@php
    // ═══ وضع التعديل (٢٣/٨): نفس الفورم متملي بعرض محفوظ ═══
    $edit = $edit ?? null;
    $editRows = $editRows ?? collect();
    $editDays = $editDays ?? 30;
@endphp

@section('title', $edit ? __('rpt.qt_edit').' '.$edit->number : __('rpt.quotation'))

@section('actions')
    <a class="btn" href="{{ route('erp.reports.quotations') }}">← {{ __('rpt.qts_title') }}</a>
    @if ($edit)
        <a class="btn" href="{{ route('erp.reports.quotations.show', $edit) }}">🖨️ {{ __('rpt.qts_open') }}</a>
    @endif
@endsection

@section('content')

<div class="card">
    <h3>📄 {{ $edit ? __('rpt.qt_edit').' '.$edit->number : __('rpt.quotation') }}
        <span class="side">{{ __('rpt.quotation_hint') }}</span></h3>

    {{-- ⚠️ الفورم بيتبعت POST وبيفتح صفحة العرض المحفوظ —
         وفي التعديل بيحدّث نفس الرقم مش بيطلّع عرض جديد --}}
    <form method="POST"
          action="{{ $edit ? route('erp.reports.quotations.update', $edit) : route('erp.reports.quotation.print') }}"
          onsubmit="return qtSubmit(this)">
        @csrf

        {{-- ═══ ١) العميل + قايمة الأسعار ═══ --}}
        <div class="frow">
            <div style="flex:2;min-width:240px">
                <label class="f">{{ __('rpt.qt_client') }} <b class="req-star">*</b></label>
                <input type="text" name="client_name" id="qtClient" required maxlength="190"
                       style="width:100%" placeholder="{{ __('rpt.qt_client_ph') }}"
                       value="{{ $edit?->client_name }}" list="qtClientsDl">
                {{-- اختيار من العملاء المسجلين بيملى الاسم بس — الكوتيشن
                     ممكن يروح لعميل محتمل مش متسجل أصلاً --}}
                <datalist id="qtClientsDl">
                    @foreach ($clients as $c)
                        <option value="{{ $c->fullName() }}"></option>
                    @endforeach
                </datalist>
            </div>
            <div style="flex:1;min-width:200px">
                <label class="f">💲 {{ __('rpt.qt_list') }}</label>
                {{-- ⚠️ الـid مش «qtList» — ده محجوز لحاوية نتايج المنتقي
                     المشترك (qt + List). التصادم كان بيخلي المنتقي يرندر
                     النتايج جوه السيلكت والليستة تطلع فاضية (٢٣/٨). --}}
                <select name="price_list_id" id="qtPriceList" style="width:100%" onchange="qtListChanged()">
                    @foreach ($lists as $l)
                        <option value="{{ $l->id }}" @selected($l->id === ($edit?->price_list_id ?? $defaultListId))>
                            {{ $l->name }}@if($l->is_default) ★ @endif
                        </option>
                    @endforeach
                </select>
                <div class="side" style="font-size:10.5px;margin-top:4px">{{ __('rpt.qt_list_hint') }}</div>
            </div>
        </div>

        {{-- ═══ ٢) الأصناف — بحث بس وكل حاجة مخفية وراه ═══ --}}
        <div style="margin-top:14px;border-top:1px solid var(--border);padding-top:12px">
            <label class="f">{{ __('ops.md_items') }}</label>
            @include('partials._item_picker', [
                'id' => 'qt',
                'catalog' => $products,
                'onPick' => 'addRow',
                'filter' => 'qtPickable',
                'sub' => 'qtPickSub',
            ])
        </div>

        {{-- الجدول — نفس نمط تسليم العهدة --}}
        <div class="tablewrap" style="margin-top:12px;max-height:52vh;overflow-y:auto">
            <table>
                <thead>
                    <tr>
                        <th>{{ __('stock.item') }}</th>
                        <th class="num" style="width:110px">{{ __('common.qty') }}</th>
                        <th class="num" style="width:130px">{{ __('rpt.qt_price') }}</th>
                        <th class="num">{{ __('common.total') }}</th>
                        <th style="width:40px"></th>
                    </tr>
                </thead>
                <tbody id="qtBody">
                    <tr id="qtEmpty">
                        <td colspan="5" style="text-align:center;color:var(--muted);padding:26px">
                            {{ __('field.no_selected_hint') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- ═══ ٣) السريان والخصم والضريبة — جمب التوتال ═══ --}}
        <div style="display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end;margin-top:14px">
            <div>
                <label class="f">📅 {{ __('rpt.qt_valid') }}</label>
                <input type="number" name="valid_days" id="qtDays" value="{{ $editDays }}" min="1" max="365"
                       style="width:110px" oninput="qtTotals()">
                <div class="side" style="font-size:10.5px" id="qtUntil"></div>
            </div>
            <div>
                <label class="f">🏷️ {{ __('rpt.qt_disc') }} %</label>
                <input type="number" name="discount_pct" id="qtDisc" min="0" max="100"
                       value="{{ $edit !== null ? rtrim(rtrim(number_format((float) $edit->discount_pct, 2, '.', ''), '0'), '.') : 0 }}"
                       step="0.5" style="width:100px" oninput="qtTotals()">
            </div>
            <div>
                <label class="f">🧾 {{ __('rpt.qt_tax') }} %</label>
                <input type="number" name="tax_pct" id="qtTax" min="0" max="100"
                       value="{{ $edit !== null ? rtrim(rtrim(number_format((float) $edit->tax_pct, 2, '.', ''), '0'), '.') : $taxPct }}"
                       step="0.5" style="width:100px" oninput="qtTotals()">
            </div>
            {{-- التجميعة اللحظية --}}
            <div id="qtSum" style="margin-inline-start:auto;text-align:end;font-size:12.5px;
                                   border:1px solid var(--border);border-radius:12px;padding:10px 16px;min-width:230px">
            </div>
        </div>

        <div style="margin-top:12px">
            <label class="f">{{ __('rpt.qt_notes') }}</label>
            <textarea name="notes" rows="2" maxlength="1000" style="width:100%"
                      placeholder="{{ __('rpt.qt_notes_ph') }}">{{ $edit?->notes }}</textarea>
        </div>

        <button class="btn gold" type="submit" style="margin-top:14px" id="qtBtn" disabled>
            {{ $edit ? '💾 '.__('rpt.qt_save_edit') : '🖨️ '.__('rpt.qt_make') }}</button>
    </form>
</div>

@endsection

@section('scripts')
<script>
{{-- الكتالوج من المنتقي المشترك — أسعار كل القوايم محمّلة معاه --}}
const CATALOG = window.PICKER_QT;
const T = {
    est: @json(__('ops.md_est_total')),
    noItems: @json(__('ops.md_no_items')),
    sub: @json(__('rpt.qt_subtotal')),
    disc: @json(__('rpt.qt_disc')),
    tax: @json(__('rpt.qt_tax')),
    grand: @json(__('rpt.qt_grand')),
    until: @json(__('rpt.qt_valid_until')),
    box: @json(__('stock.unit_box')),
    kase: @json(__('stock.unit_case')),
};

{{-- بنود العرض المفتوح للتعديل — بتتزرع تحت بعد تعريف الدوال --}}
@php
    $editRowsJson = json_encode($editRows,
        JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
@endphp
const EDIT_ROWS = {!! $editRowsJson !!};

let rows = [];

const fmt = n => Number(n).toLocaleString('en-US', {maximumFractionDigits: 2});
const esc = s => String(s ?? '').replace(/[&<>"']/g,
    ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));

function qtListId() { return Number(document.getElementById('qtPriceList').value || 0); }
function qtPriceOf(p) { return Number((p.prices || {})[qtListId()] || 0); }

{{-- هوكات المنتقي: الأصناف المتسعّرة بالقايمة المختارة بس --}}
function qtPickable(p) { return qtPriceOf(p) > 0; }
function qtPickSub(p) { return fmt(qtPriceOf(p)); }

function addRow(id) {
    const p = CATALOG.find(x => x.id === id);
    if (!p) return;

    const ex = rows.find(r => r.id === id);
    if (ex) { ex.qty++; qtRender(); return; }

    rows.push({id: p.id, code: p.code, name: p.name, image: p.image,
               units: p.units || {piece: 1}, qty: 1, price: qtPriceOf(p), touched: false});
    qtRender();
}

function delRow(i) { rows.splice(i, 1); qtRender(); }
function setQty(i, v) { rows[i].qty = Math.max(1, parseInt(v || '1', 10)); qtRender(); }
function setPrice(i, v) { rows[i].price = Math.max(0, parseFloat(v || '0')); rows[i].touched = true; qtRender(); }

{{-- تغيير القايمة بيعيد تسعير الصفوف اللي ماتلمستش بالإيد --}}
function qtListChanged() {
    rows.forEach(function (r) {
        if (r.touched) return;
        const p = CATALOG.find(x => x.id === r.id);
        if (p) r.price = qtPriceOf(p);
    });
    qtRender();
    if (typeof qtPickerSearch === 'function'
        && document.getElementById('qtResults')?.style.display === 'block') {
        qtPickerSearch();
    }
}

function qtRender() {
    const body = document.getElementById('qtBody');
    document.getElementById('qtEmpty')?.remove();

    body.innerHTML = rows.map(function (r, i) {
        {{-- ملحوظة العلبة/الكرتونة تحت الاسم — بيشوف سعرهم وهو بيسعّر --}}
        let u = [];
        if (r.units.box) u.push(T.box + ' (' + r.units.box + ') = ' + fmt(r.price * r.units.box));
        if (r.units['case']) u.push(T.kase + ' (' + r.units['case'] + ') = ' + fmt(r.price * r.units['case']));

        return '<tr>' +
            '<td><div style="display:flex;gap:10px;align-items:center">' +
                (r.image
                    ? '<img src="' + esc(r.image) + '" style="width:52px;height:52px;object-fit:contain;border-radius:9px;border:1px solid var(--border);background:#fff;flex-shrink:0">'
                    : '<div style="width:52px;height:52px;border-radius:9px;border:1px dashed var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);flex-shrink:0">📦</div>') +
                '<div><b>' + esc(r.name) + '</b>' +
                '<div style="font-size:10.5px;color:var(--muted)">' + esc(r.code) +
                (u.length ? ' · ' + u.join(' · ') : '') + '</div></div>' +
            '</div></td>' +
            '<td class="num"><input type="number" min="1" max="99999" value="' + r.qty + '" style="width:100%" onchange="setQty(' + i + ', this.value)"></td>' +
            '<td class="num"><input type="number" min="0" step="0.01" value="' + r.price + '" style="width:100%" onchange="setPrice(' + i + ', this.value)"></td>' +
            '<td class="num"><b dir="ltr">' + fmt(r.qty * r.price) + '</b></td>' +
            '<td class="num"><button type="button" class="btn sm" onclick="delRow(' + i + ')">✕</button></td>' +
        '</tr>';
    }).join('') || '<tr id="qtEmpty"><td colspan="5" style="text-align:center;color:var(--muted);padding:26px">' + esc(T.noItems) + '</td></tr>';

    qtTotals();
}

function qtTotals() {
    const sub = rows.reduce((t, r) => t + r.qty * r.price, 0);
    const dPct = Math.min(100, Math.max(0, parseFloat(document.getElementById('qtDisc').value || '0')));
    const tPct = Math.min(100, Math.max(0, parseFloat(document.getElementById('qtTax').value || '0')));
    const disc = sub * dPct / 100;
    const net = sub - disc;
    const tax = net * tPct / 100;

    let html = '<div>' + esc(T.sub) + ': <b dir="ltr">' + fmt(sub) + '</b></div>';
    if (disc > 0) html += '<div style="color:var(--red,#DC2626)">' + esc(T.disc) + ' ' + dPct + '%: <b dir="ltr">-' + fmt(disc) + '</b></div>';
    if (tax > 0) html += '<div>' + esc(T.tax) + ' ' + tPct + '%: <b dir="ltr">+' + fmt(tax) + '</b></div>';
    html += '<div style="border-top:2px solid var(--royal-blue,#12399B);margin-top:5px;padding-top:5px;' +
        'font-weight:900;font-size:14px;color:var(--royal-blue,#12399B)">' + esc(T.grand) + ': <b dir="ltr">' + fmt(net + tax) + '</b></div>';
    document.getElementById('qtSum').innerHTML = html;

    {{-- «ساري حتى» بيتحسب قدامه لايف --}}
    const days = Math.max(1, parseInt(document.getElementById('qtDays').value || '30', 10));
    const d = new Date();
    d.setDate(d.getDate() + days);
    document.getElementById('qtUntil').textContent = T.until + ': ' + d.toISOString().slice(0, 10);

    document.getElementById('qtBtn').disabled = rows.length === 0;
}

function qtSubmit(form) {
    if (!rows.length) { alert(T.noItems); return false; }

    form.querySelectorAll('.md-h').forEach(e => e.remove());

    rows.forEach(function (r, i) {
        [['id', r.id], ['name', r.name], ['qty', r.qty], ['price', r.price]].forEach(function (kv) {
            const inp = document.createElement('input');
            inp.type = 'hidden';
            inp.className = 'md-h';
            inp.name = 'items[' + i + '][' + kv[0] + ']';
            inp.value = kv[1];
            form.appendChild(inp);
        });
    });

    return true;
}

{{-- وضع التعديل: البنود المحفوظة بتنزل الجدول بأسعارها المجمّدة.
     touched=true عشان تغيير القايمة مايدوسش على أسعار متفاوَض عليها.
     صنف اتشال من الكتالوج بينزل من غير صورة ومن غير id (بيتحفظ نص). --}}
EDIT_ROWS.forEach(function (r) {
    const p = CATALOG.find(x => x.id === r.id);
    rows.push({
        id: p ? r.id : null,
        code: r.code || (p ? p.code : ''),
        name: r.name,
        image: p ? p.image : null,
        units: p ? (p.units || {piece: 1}) : {piece: 1},
        qty: r.qty,
        price: r.price,
        touched: true,
    });
});

if (rows.length) { qtRender(); } else { qtTotals(); }
</script>
@endsection
