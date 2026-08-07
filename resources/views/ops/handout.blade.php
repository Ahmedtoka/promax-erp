@extends('layouts.system')

{{--
    تسليم عهدة — الفلو الجديد (قرار المالك 2026-08-03):

    اختار المخزن والمندوب ← دوّر على الأصناف وضيفها بالكميات ←
    «إرسال للتجهيز» ← الورقة بتتطبع ← المخزن بيجهّز فيزيكال من شاشة
    «تجهيز الطلبات» وبيأكد ← إشعار للمندوب ← يستلم من الأبلكيشن.

    ⚠️ **البضاعة مابتخرجش من هنا** — بتخرج عند تأكيد التجهيز بس.
--}}

@php
    $fmt = fn ($n) => number_format((float) $n);

    // كتالوج البحث — بالاسمين والكود والمتاح والصورة
    $catalog = $products->map(fn ($p) => [
        'id' => $p->id,
        'code' => (string) $p->code,
        'name' => $p->displayName(),
        'name_ar' => (string) $p->name,
        'name_en' => (string) $p->name_en,
        'unit' => $p->unitLabel(),
        'family' => $p->familyLabel(),
        'available' => (int) $p->available,
        'image' => $p->imageSrc(),
        // وحدات الإدخال — العرض بس؛ الضرب الحقيقي في السيرفر (store)
        'units' => $p->unitFactors(),
    ])->values();

    // صفوف رجعت من فاليديشن مرفوضة — بنعيد بناءها
    $oldRows = collect(old('qty', []))->keys()
        ->merge(collect(old('gift', []))->keys())
        ->unique()->values();
@endphp

@section('title', __('field.handout'))

@section('content')

<div class="card">
    <h3>🚚 {{ __('field.handout') }}
        <span class="side">{{ $warehouse?->displayName() ?? '—' }}</span></h3>

    {{-- ⚠️ التحذير ده مش زينة: البضاعة بتخرج قبل ما المندوب يدوس
         استلام، فاللي بيسلّم لازم يعرف إنه مسؤول عنها من دلوقتي. --}}
    <div class="alert warn" style="margin-bottom:14px">
        <span>⚠️</span><span>{{ __('field.handout_warning') }}</span>
    </div>

    @if ($errors->any())
        <div class="alert" style="margin-bottom:12px;flex-direction:column;align-items:stretch;gap:4px">
            @foreach ($errors->all() as $msg)
                <div class="errline" style="margin:0">{{ $msg }}</div>
            @endforeach
        </div>
    @endif

    @if ($warehouse === null)
        <div class="alert"><span>⛔</span><span>{{ __('stock.no_warehouses') }}</span></div>
    @else
    <form method="POST" action="{{ route('ops.handout.store') }}" id="hoForm">
        @csrf
        <input type="hidden" name="warehouse_id" value="{{ $warehouse->id }}">

        <div class="frow">
            <div>
                <label class="f">{{ __('stock.warehouse') }}</label>
                <select style="width:100%" onchange="location.href='?warehouse='+this.value">
                    @foreach ($warehouses as $w)
                        <option value="{{ $w->id }}" @selected($w->id === $warehouse->id)>
                            {{ $w->displayName() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="f">{{ __('ops.rep') }} <b class="req-star">*</b></label>
                <select name="rep_id" required style="width:100%">
                    <option value="">— {{ __('field.pick_rep') }} —</option>
                    @foreach ($reps as $r)
                        <option value="{{ $r->id }}" @selected(old('rep_id') == $r->id)>
                            {{ $r->displayName() }} · {{ $r->code }} · {{ $r->roleLabel() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                {{-- ⚠️ **موعد وصول المندوب المخزن** (2026-08-08). من
                     غيره أمين المخزن مايعرفش يجهّز لمين الأول،
                     والمندوب مايعرفش ييجي إمتى — وكل الاتنين بيستنوا. --}}
                <label class="f" style="color:#0E7C5A">📦 {{ __('ops.pickup_at') }}</label>
                <input type="datetime-local" name="pickup_at" style="width:100%"
                       value="{{ old('pickup_at') }}">
                <div class="side" style="font-size:10.5px">{{ __('ops.pickup_at_hint') }}</div>
            </div>
            <div>
                <label class="f">{{ __('field.carrier_note') }}</label>
                <input type="text" name="carrier_note" maxlength="190" style="width:100%"
                       value="{{ old('carrier_note') }}" placeholder="{{ __('field.carrier_note_ph') }}">
            </div>
        </div>

        {{-- ═══════════ البحث — اكتب أو دوس مسافة يفتح الكل ═══════════ --}}
        <div style="position:relative;margin-top:14px">
            <input type="text" id="prodSearch" autocomplete="off" style="width:100%"
                   placeholder="🔍 {{ __('field.search_product_ph') }}"
                   oninput="searchProducts()" onfocus="searchProducts()">
            <div id="prodResults"
                 style="display:none;position:absolute;top:calc(100% + 4px);right:0;left:0;z-index:60;
                        background:#fff;border:1px solid var(--border);border-radius:12px;
                        box-shadow:0 10px 30px rgba(0,0,0,.12);max-height:320px;overflow-y:auto"></div>
        </div>

        {{-- ═══════════ الأصناف المختارة — بتنزل هنا صف صف ═══════════ --}}
        {{-- ⚠️ الهيدر ثابت (sticky) — القايمة بتطول والمستخدم لازم
             يفضل شايف أسماء الأعمدة وهو نازل (قرار المالك 2026-08-04) --}}
        <div class="tablewrap" style="margin-top:12px;max-height:56vh;overflow-y:auto">
            <table>
                <thead style="position:sticky;top:0;z-index:5;background:var(--card, #fff);box-shadow:0 1px 0 var(--border)">
                <tr>
                    <th>{{ __('stock.item') }}</th>
                    <th class="num">{{ __('stock.available') }}</th>
                    <th style="width:110px">{{ __('stock.entry_unit') }}</th>
                    <th class="num" style="width:110px">{{ __('field.qty_sale') }}</th>
                    {{-- الهدايا خانة منفصلة عن البيع — عشان الفرق مايضيعش --}}
                    <th class="num" style="width:110px">🎁 {{ __('field.qty_gift') }}</th>
                    <th class="num">{{ __('common.total') }}</th>
                    <th style="width:40px"></th>
                </tr>
                </thead>
                <tbody id="selBody">
                    <tr id="selEmpty">
                        <td colspan="7" style="text-align:center;color:var(--muted);padding:26px">
                            {{ __('field.no_selected_hint') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div style="display:flex;gap:8px;justify-content:space-between;align-items:center;margin-top:14px">
            <span style="font-size:12.5px;color:var(--muted)">
                {{ __('field.total_units') }}: <b id="grand">0</b>
                · 🎁 <b id="grandGift">0</b>
            </span>
            @if (\App\Support\Access::action(auth()->user(), 'act.custody.handout'))<button class="btn gold" type="submit" id="hoBtn" disabled>
                📋 {{ __('field.send_to_prep') }}
            </button>@endif
        </div>
    </form>
    @endif
</div>

{{-- ═══════════ تحت التجهيز — المخزن لسه بيجمع ═══════════ --}}
@if ($preparing->isNotEmpty())
<div class="card">
    <h3>📋 {{ __('field.preparing_title') }}
        <span class="side">{{ __('field.preparing_hint') }}</span></h3>
    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('stock.pick_order') }}</th>
                <th>{{ __('ops.rep') }}</th>
                <th>{{ __('stock.warehouse') }}</th>
                <th>{{ __('common.date') }}</th>
                <th class="num">{{ __('common.total') }}</th>
                <th class="num">🎁</th>
                <th></th>
            </tr>
            @foreach ($preparing as $o)
                <tr>
                    <td class="num"><b>{{ $o->number }}</b></td>
                    <td>{{ $o->rep?->displayName() ?? '—' }}</td>
                    <td style="font-size:11.5px">{{ $o->warehouse?->displayName() ?? '—' }}</td>
                    <td class="num" style="font-size:11.5px">{{ $o->created_at?->format('Y-m-d H:i') }}</td>
                    <td class="num">{{ $fmt($o->items->sum('qty_requested')) }}</td>
                    <td class="num">{{ $fmt($o->items->sum('gift_qty')) ?: '—' }}</td>
                    <td class="num" style="white-space:nowrap">
                        <a class="btn sm" href="{{ route('ops.handout.print', $o) }}">🖨️</a>
                        {{-- تأكيد التجهيز — بيفتح الأمر في شاشة التجهيز --}}
                        <a class="btn sm gold" href="{{ route('wh.picks.show', $o) }}">✅ {{ __('field.go_prepare') }}</a>
                    </td>
                </tr>
            @endforeach
        </table>
    </div>
</div>
@endif

@if ($open->isNotEmpty())
<div class="card">
    <h3>⏳ {{ __('field.awaiting_receipt') }}
        <span class="side">{{ __('field.awaiting_hint') }}</span></h3>
    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('stock.pick_order') }}</th>
                <th>{{ __('ops.rep') }}</th>
                <th>{{ __('stock.warehouse') }}</th>
                <th>{{ __('field.issued_at') }}</th>
                <th class="num">{{ __('common.total') }}</th>
                <th class="num">🎁</th>
                <th></th>
            </tr>
            @foreach ($open as $o)
                <tr>
                    <td class="num"><b>{{ $o->number }}</b></td>
                    <td>{{ $o->rep?->displayName() ?? '—' }}</td>
                    <td style="font-size:11.5px">{{ $o->warehouse?->displayName() ?? '—' }}</td>
                    <td class="num" style="font-size:11.5px">{{ ($o->issued_at ?? $o->ready_at)?->format('Y-m-d H:i') ?? '—' }}</td>
                    <td class="num">{{ $fmt($o->items->sum('qty_picked')) }}</td>
                    <td class="num">{{ $fmt($o->items->sum('gift_qty')) ?: '—' }}</td>
                    <td class="num">
                        <a class="btn sm" href="{{ route('ops.handout.print', $o) }}">🖨️</a>
                    </td>
                </tr>
            @endforeach
        </table>
    </div>
</div>
@endif

{{-- ═══════════ الهيستوري — كل التسليمات اللي تمّت ═══════════ --}}
@if ($done->isNotEmpty())
<div class="card">
    <h3>📦 {{ __('field.handout_history') }}
        <span class="side">{{ __('field.handout_history_hint') }}</span></h3>
    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('stock.pick_order') }}</th>
                <th>{{ __('ops.rep') }}</th>
                <th>{{ __('stock.warehouse') }}</th>
                <th>{{ __('field.handed_at') }}</th>
                <th class="num">{{ __('common.total') }}</th>
                <th class="num">🎁</th>
                <th class="num">{{ __('field.handout_value') }}</th>
                <th></th>
            </tr>
            @foreach ($done as $o)
                @php
                    // القيمة بقايمة المندوب — السواق قديمة والسيلز جديدة
                    $mode = $o->rep?->isDriver() ? 'old' : 'new';
                    $value = $o->items->sum(fn ($i) => (int) ($i->qty_received ?? $i->qty_picked)
                        * (float) ($i->product?->priceFor($mode) ?? 0));
                @endphp
                <tr>
                    <td class="num"><b>{{ $o->number }}</b></td>
                    <td>{{ $o->rep?->displayName() ?? '—' }}</td>
                    <td style="font-size:11.5px">{{ $o->warehouse?->displayName() ?? '—' }}</td>
                    <td class="num" style="font-size:11.5px">{{ $o->handed_at?->format('Y-m-d H:i') ?? '—' }}</td>
                    <td class="num">{{ $fmt($o->items->sum(fn ($i) => (int) ($i->qty_received ?? $i->qty_picked))) }}</td>
                    <td class="num">{{ $fmt($o->items->sum('gift_qty')) ?: '—' }}</td>
                    <td class="num"><b>{{ number_format($value, 2) }}</b></td>
                    <td class="num">
                        {{-- إعادة طباعة ورقة التسليم في أي وقت --}}
                        <a class="btn sm" href="{{ route('ops.handout.print', $o) }}">🖨️</a>
                    </td>
                </tr>
            @endforeach
        </table>
    </div>
</div>
@endif

@endsection

@section('scripts')
<script>
{{-- الكتالوج كله للبحث — 31 صنف، مش وجع للمتصفح --}}
const CATALOG = {!! json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) !!};
const OLD_ROWS = {!! json_encode($oldRows, JSON_UNESCAPED_UNICODE) !!};
const OLD_QTY = {!! json_encode(old('qty', new stdClass), JSON_UNESCAPED_UNICODE) !!};
const OLD_GIFT = {!! json_encode(old('gift', new stdClass), JSON_UNESCAPED_UNICODE) !!};
const OLD_UNIT = {!! json_encode(old('unit', new stdClass), JSON_UNESCAPED_UNICODE) !!};

const esc = s => String(s ?? '').replace(/[&<>"']/g,
    ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));

/**
 * البحث: اكتب اسم (عربي أو إنجليزي) أو كود — أو مسافة/فوكس يفتح الكل.
 */
function searchProducts() {
    const box = document.getElementById('prodResults');
    const q = document.getElementById('prodSearch').value.trim().toLowerCase();

    const hits = CATALOG.filter(p =>
        q === '' ||
        p.name.toLowerCase().includes(q) ||
        (p.name_ar || '').includes(q) ||
        (p.name_en || '').toLowerCase().includes(q) ||
        p.code.toLowerCase().includes(q)
    );

    box.innerHTML = hits.length === 0
        ? '<div style="padding:14px;text-align:center;color:var(--muted);font-size:12px">{{ __('stock.no_items') }}</div>'
        : hits.map(p => {
            const out = p.available <= 0;
            return '<div onclick="' + (out ? '' : 'addRow(' + p.id + ')') + '"' +
                ' style="display:flex;align-items:center;gap:10px;padding:9px 13px;cursor:' + (out ? 'not-allowed' : 'pointer') + ';' +
                'border-bottom:1px solid var(--border);opacity:' + (out ? '.45' : '1') + '">' +
                (p.image ? '<img src="' + esc(p.image) + '" style="width:52px;height:52px;object-fit:contain;border-radius:6px;border:1px solid var(--border);background:#fff">' : '<span style="width:52px"></span>') +
                '<span style="flex:1;min-width:0"><b style="font-size:12.5px">' + esc(p.name) + '</b>' +
                '<span style="display:block;font-size:10.5px;color:var(--muted)">' + esc(p.code) + ' · ' + esc(p.unit) + '</span></span>' +
                '<span style="font-size:11px;font-weight:800;color:' + (out ? 'var(--red, #B00020)' : 'var(--muted)') + '">' +
                (out ? '{{ __('stock.out_of') }}' : '{{ __('stock.available') }}: ' + p.available.toLocaleString()) + '</span></div>';
        }).join('');

    box.style.display = 'block';
}

// قفل القايمة عند الضغط بره
document.addEventListener('click', e => {
    if (!e.target.closest('#prodSearch') && !e.target.closest('#prodResults')) {
        const box = document.getElementById('prodResults');
        if (box) box.style.display = 'none';
    }
});

const UNIT_LABELS = {
    piece: @json(__('stock.unit_piece')),
    box: @json(__('stock.unit_box')),
    'case': @json(__('stock.unit_case'))
};

/**
 * سيلكت الوحدة — قطعة دايماً + علبة/كرتونة لو معرّفين للصنف.
 * ⚠️ العرض بس: السيرفر بيعيد ضرب الكمية والهدية بنفسه في store.
 */
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

/** تجميعة العرض: 245 → «3 كرتونة + 1 علبة + 5 قطعة» — عرض بس */
function packBd(p, n) {
    if (!p.units || n <= 0) return '';

    const parts = [];
    let rest = n;

    [['case', p.units['case']], ['box', p.units.box]].forEach(([u, size]) => {
        if (size > 1 && rest >= size) {
            parts.push(Math.floor(rest / size).toLocaleString() + ' ' + UNIT_LABELS[u]);
            rest %= size;
        }
    });

    if (!parts.length) return '';
    if (rest > 0) parts.push(rest.toLocaleString() + ' ' + UNIT_LABELS.piece);

    return parts.join(' + ');
}

/** مضاعِف الوحدة المختارة في صف — بالقطع */
function rowFactor(id) {
    const p = CATALOG.find(x => x.id === id);
    const sel = document.querySelector('[data-row="' + id + '"][data-kind="unit"]');

    return (p && p.units && sel && p.units[sel.value]) || 1;
}

/** اختيار صنف — ينزل صف في الجدول بكمية وهدية */
function addRow(id) {
    const p = CATALOG.find(x => x.id === id);
    if (!p) return;

    document.getElementById('prodResults').style.display = 'none';
    document.getElementById('prodSearch').value = '';

    const existing = document.querySelector('[data-row="' + id + '"][data-kind="qty"]');
    if (existing) { existing.focus(); return; }

    const empty = document.getElementById('selEmpty');
    if (empty) empty.remove();

    const tr = document.createElement('tr');
    tr.id = 'row' + id;
    // الصورة جوه خانة الصنف نفسها — مش عمود لوحدها بمسافة فاضية
    tr.innerHTML =
        '<td><div style="display:flex;gap:10px;align-items:center">' +
            (p.image
                ? '<img src="' + esc(p.image) + '" style="width:56px;height:56px;object-fit:contain;border-radius:10px;border:1px solid var(--border);background:#fff;flex-shrink:0">'
                : '<div style="width:56px;height:56px;border-radius:10px;border:1px dashed var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);flex-shrink:0">📦</div>') +
            '<div><b>' + esc(p.name) + '</b><div style="font-size:10.5px;color:var(--muted)">' + esc(p.code) + ' · ' + esc(p.unit) + '</div></div>' +
        '</div></td>' +
        '<td class="num"><b>' + p.available.toLocaleString() + '</b>' +
            (packBd(p, p.available) ? '<div style="font-size:10px;color:var(--muted);white-space:nowrap">' + esc(packBd(p, p.available)) + '</div>' : '') +
        '</td>' +
        '<td>' + unitSelect(p) + '</td>' +
        '<td class="num"><input type="number" min="0" style="width:100%"' +
            ' name="qty[' + id + ']" data-row="' + id + '" data-kind="qty" data-max="' + p.available + '"' +
            ' oninput="syncRow(' + id + ')"></td>' +
        '<td class="num"><input type="number" min="0" style="width:100%"' +
            ' name="gift[' + id + ']" data-row="' + id + '" data-kind="gift" oninput="syncRow(' + id + ')"></td>' +
        '<td class="num" id="tot' + id + '">—</td>' +
        '<td class="num"><button type="button" class="btn sm" onclick="removeRow(' + id + ')">✕</button></td>';

    document.getElementById('selBody').appendChild(tr);
    tr.querySelector('[data-kind="qty"]').focus();
}

function removeRow(id) {
    document.getElementById('row' + id)?.remove();
    syncTotals();
}

/**
 * إجمالي السطر = بيع + هدايا، والحد الأقصى للاتنين مع بعض هو المتاح.
 *
 * ⚠️ **الهدية بتتحجز من نفس المخزون.** المتاح 40، وكتبت 40 بيع
 * و5 هدايا — السيرفر هيرفض الأمر كله. الشاشة بتقولها قبل ما تدوس.
 */
function syncRow(id) {
    const qty = document.querySelector('[data-row="' + id + '"][data-kind="qty"]');
    const gift = document.querySelector('[data-row="' + id + '"][data-kind="gift"]');
    const cell = document.getElementById('tot' + id);

    if (!qty || !gift || !cell) return;

    // الإجمالي **بالقطع** — الكمية المكتوبة × مضاعِف الوحدة المختارة
    const factor = rowFactor(id);
    const max = Number(qty.dataset.max || 0);
    const sum = (Number(qty.value || 0) + Number(gift.value || 0)) * factor;
    const over = sum > max;

    cell.innerHTML = sum === 0 ? '—'
        : '<b>' + sum.toLocaleString() + '</b>' +
          (factor > 1 ? ' <span style="font-size:10px;color:var(--muted)">' + esc(UNIT_LABELS.piece) + '</span>' : '');
    cell.className = over ? 'num neg' : 'num';
    qty.className = gift.className = over ? 'bad' : '';

    syncTotals();
}

function syncTotals() {
    let sale = 0, gift = 0, over = false;

    document.querySelectorAll('[data-kind="qty"]').forEach(q => {
        const g = document.querySelector('[data-row="' + q.dataset.row + '"][data-kind="gift"]');
        const f = rowFactor(Number(q.dataset.row));
        const s = Number(q.value || 0) * f;
        const gv = Number(g ? g.value || 0 : 0) * f;

        sale += s;
        gift += gv;

        if (s + gv > Number(q.dataset.max || 0)) over = true;
    });

    document.getElementById('grand').textContent = sale.toLocaleString();
    document.getElementById('grandGift').textContent = gift.toLocaleString();

    const btn = document.getElementById('hoBtn');

    if (btn) btn.disabled = over || (sale + gift) === 0;
}

// الفاليديشن رفضت؟ نرجّع الصفوف اللي المستخدم كان مليها
OLD_ROWS.forEach(id => {
    addRow(Number(id));
    const q = document.querySelector('[data-row="' + id + '"][data-kind="qty"]');
    const g = document.querySelector('[data-row="' + id + '"][data-kind="gift"]');
    if (q) q.value = OLD_QTY[id] ?? '';
    if (g) g.value = OLD_GIFT[id] ?? '';
    const u = document.querySelector('[data-row="' + id + '"][data-kind="unit"]');
    if (u && OLD_UNIT[id]) u.value = OLD_UNIT[id];
    syncRow(Number(id));
});

syncTotals();
</script>
@endsection
