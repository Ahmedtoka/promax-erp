@extends('layouts.system')

@section('title', __('rpt.quotation'))

@section('actions')
    <a class="btn" href="{{ route('erp.reports.quotations') }}">← {{ __('rpt.qts_title') }}</a>
@endsection

@section('content')

<div class="card">
    <h3>📄 {{ __('rpt.quotation') }} <span class="side">{{ __('rpt.quotation_hint') }}</span></h3>

    {{-- ⚠️ الفورم بيتبعت POST ويفتح صفحة الطباعة في تاب جديد —
         عشان الفورم يفضل قدامه لو حب يعدّل ويطلع نسخة تانية --}}
    <form method="POST" action="{{ route('erp.reports.quotation.print') }}" target="_blank"
          onsubmit="return qtSubmit(this)">
        @csrf

        <div class="searchbar" style="flex-wrap:wrap;align-items:flex-end">
            <div style="flex:1;min-width:240px">
                <label class="f">{{ __('rpt.qt_client') }} *</label>
                <input type="text" name="client_name" id="qtClient" required maxlength="190"
                       style="width:100%" placeholder="{{ __('rpt.qt_client_ph') }}"
                       list="qtClientsDl">
                {{-- اختيار من العملاء المسجلين بيملى الاسم بس — الكوتيشن
                     ممكن يروح لعميل محتمل مش متسجل أصلاً --}}
                <datalist id="qtClientsDl">
                    @foreach ($clients as $c)
                        <option value="{{ $c->fullName() }}"></option>
                    @endforeach
                </datalist>
            </div>
            <div>
                <label class="f">{{ __('rpt.qt_valid') }}</label>
                <input type="number" name="valid_days" value="14" min="1" max="365" style="width:90px">
            </div>
            <div>
                <label class="f">{{ __('rpt.qt_disc') }} %</label>
                <input type="number" name="discount_pct" value="0" min="0" max="100" step="0.5" style="width:90px">
            </div>
            <div>
                <label class="f">{{ __('rpt.qt_tax') }} %</label>
                <input type="number" name="tax_pct" value="{{ $taxPct }}" min="0" max="100" step="0.5" style="width:90px">
            </div>
        </div>

        <div style="margin-top:10px">
            <label class="f">{{ __('rpt.qt_notes') }}</label>
            <textarea name="notes" rows="2" maxlength="1000" style="width:100%"
                      placeholder="{{ __('rpt.qt_notes_ph') }}"></textarea>
        </div>

        {{-- ═══ الأصناف: بحث فوق والمختار بينزل تحتيه ═══ --}}
        <div style="margin-top:14px;border-top:1px solid var(--border);padding-top:12px">
            <label class="f">{{ __('ops.md_items') }}</label>
            <input type="search" id="qtSearch" style="width:100%"
                   placeholder="{{ __('ops.md_add_item') }}"
                   onkeydown="if (event.key === 'Enter') event.preventDefault()">
            <div id="qtProds" class="md-prods"></div>
        </div>

        <div id="qtRows" class="md-items"></div>
        <div id="qtEst" class="md-est"></div>

        <button class="btn gold" type="submit" style="margin-top:14px">🖨️ {{ __('rpt.qt_make') }}</button>
    </form>
</div>

@endsection

@section('scripts')
@php
    $jsFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP;
@endphp
<script>
    const PRODUCTS = {!! json_encode($products, $jsFlags) !!};
    const T = {
        add: @js(__('ops.md_add_btn')),
        qty: @js(__('ops.md_qty')),
        price: @js(__('rpt.qt_price')),
        est: @js(__('ops.md_est_total')),
        noItems: @js(__('ops.md_no_items')),
    };

    let rows = [];

    function fmt(n) {
        return Number(n).toLocaleString('en-US', {maximumFractionDigits: 2});
    }

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function renderProds() {
        const q = document.getElementById('qtSearch').value.trim().toLowerCase();
        const holder = document.getElementById('qtProds');

        holder.innerHTML = PRODUCTS
            .filter(p => !q || p.name.toLowerCase().includes(q) || String(p.code).includes(q))
            .slice(0, 30)
            .map(function (p) {
                return '<button type="button" class="md-prod" onclick="addRow(' + p.id + ')">' +
                    (p.image ? '<img src="' + esc(p.image) + '" loading="lazy" alt="">'
                             : '<span class="md-noimg">📦</span>') +
                    '<span class="md-pinfo"><b>' + esc(p.name) + '</b>' +
                    '<i>' + esc(String(p.code)) + ' · ' + fmt(p.price) + '</i></span>' +
                    '<span class="md-addbtn">＋ ' + esc(T.add) + '</span></button>';
            }).join('');
    }

    document.getElementById('qtSearch').addEventListener('input', renderProds);

    function addRow(pid) {
        const p = PRODUCTS.find(x => x.id === pid);
        if (!p) return;

        const ex = rows.find(r => r.id === pid);
        if (ex) { ex.qty++; renderRows(); return; }

        rows.push({id: p.id, name: p.name, image: p.image, qty: 1, price: p.price});
        renderRows();
    }

    function delRow(i) { rows.splice(i, 1); renderRows(); }
    function setQty(i, v) { rows[i].qty = Math.max(1, parseInt(v || '1', 10)); renderRows(); }
    function setPrice(i, v) { rows[i].price = Math.max(0, parseFloat(v || '0')); renderRows(); }

    function renderRows() {
        const holder = document.getElementById('qtRows');

        holder.innerHTML = rows.map(function (r, i) {
            return '<div class="md-row">' +
                (r.image ? '<img src="' + esc(r.image) + '" alt="">'
                         : '<span class="md-noimg">📦</span>') +
                '<div class="nm"><b>' + esc(r.name) + '</b></div>' +
                '<input type="number" min="1" max="99999" value="' + r.qty + '" ' +
                'onchange="setQty(' + i + ', this.value)" title="' + esc(T.qty) + '">' +
                // ⚠️ السعر قابل للتعديل — الكوتيشن تفاوض مش فاتورة
                '<input type="number" min="0" step="0.01" value="' + r.price + '" ' +
                'onchange="setPrice(' + i + ', this.value)" title="' + esc(T.price) + '" style="width:100px">' +
                '<b class="tot" dir="ltr">' + fmt(r.qty * r.price) + '</b>' +
                '<button type="button" class="x" onclick="delRow(' + i + ')">✕</button></div>';
        }).join('');

        const total = rows.reduce((t, r) => t + r.qty * r.price, 0);
        document.getElementById('qtEst').textContent =
            rows.length ? T.est.replaceAll(':n', fmt(total)) : '';
    }

    function qtSubmit(form) {
        if (!rows.length) { alert(T.noItems); return false; }

        form.querySelectorAll('.md-h').forEach(e => e.remove());

        rows.forEach(function (r, i) {
            ['name', 'qty', 'price'].forEach(function (k) {
                const inp = document.createElement('input');
                inp.type = 'hidden';
                inp.className = 'md-h';
                inp.name = 'items[' + i + '][' + k + ']';
                inp.value = r[k];
                form.appendChild(inp);
            });
        });

        return true;
    }

    renderProds();
</script>
<style>
.md-prods{display:flex;flex-direction:column;gap:4px;max-height:280px;overflow-y:auto;margin-top:7px}
.md-prod{display:flex;gap:10px;align-items:center;border:1px solid var(--border);
  background:var(--card);border-radius:10px;padding:7px 10px;cursor:pointer;
  font-family:inherit;font-size:12.5px;text-align:start}
.md-prod:hover{background:var(--blue-050);border-color:var(--royal-blue)}
.md-prod img,.md-row img{width:42px;height:42px;object-fit:contain;border-radius:8px;
  background:#fff;border:1px solid var(--border);flex-shrink:0}
.md-noimg{width:42px;height:42px;display:inline-flex;align-items:center;justify-content:center;
  font-size:18px;border-radius:8px;background:var(--card2);border:1px solid var(--border);flex-shrink:0}
.md-pinfo{flex:1;min-width:0;display:flex;flex-direction:column}
.md-pinfo i{font-style:normal;font-size:10.5px;color:var(--muted)}
.md-addbtn{color:var(--royal-blue);font-weight:800;font-size:11.5px;white-space:nowrap}
.md-items{display:flex;flex-direction:column;gap:6px;margin-top:10px}
.md-row{display:flex;align-items:center;gap:9px;border:1px solid var(--border);
  border-radius:10px;padding:7px 10px;background:var(--card2)}
.md-row .nm{flex:1;min-width:0;font-size:12.5px}
.md-row input[type=number]{width:80px;text-align:center}
.md-row .tot{font-size:13px;min-width:80px;text-align:end}
.md-row .x{background:none;border:none;color:var(--muted);cursor:pointer;font-size:13px}
.md-row .x:hover{color:var(--red)}
.md-est{margin-top:10px;font-size:12.5px;font-weight:800;color:var(--royal-blue)}
</style>
@endsection
