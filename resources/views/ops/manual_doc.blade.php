@extends('layouts.system')

@section('title', __('ops.md_title'))

@section('content')

<div class="card">
    <h3>✍️ {{ __('ops.md_title') }} <span class="side">{{ __('ops.md_sub') }}</span></h3>

    {{-- ═══ المراسي المشتركة: المندوب + العميل + التاريخ ═══ --}}
    <div class="searchbar" style="align-items:flex-end;flex-wrap:wrap">
        <div style="min-width:220px">
            <label class="f">{{ __('ops.md_rep') }}</label>
            <select id="mdRep" style="width:100%">
                <option value="">—</option>
                @foreach ($reps as $r)
                    <option value="{{ $r->id }}">{{ $r->displayName() }} — {{ $r->roleLabel() }}</option>
                @endforeach
            </select>
        </div>
        <div style="min-width:260px;flex:1">
            <label class="f">{{ __('ops.md_client') }}</label>
            {{-- بحث + سيلكت — العملاء بالمئات والسيلكت الخام مش عملي --}}
            <input type="search" id="mdClientSearch" placeholder="{{ __('common.search') }}…"
                   style="width:100%;margin-bottom:5px"
                   onkeydown="if (event.key === 'Enter') event.preventDefault()">
            <select id="mdClient" size="1" style="width:100%">
                <option value="">—</option>
                @foreach ($clients as $c)
                    <option value="{{ $c->id }}" data-t="{{ mb_strtolower($c->fullName().' '.$c->name_en) }}">
                        {{ $c->fullName() }}
                    </option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="f">{{ __('ops.md_date') }}</label>
            <input type="date" id="mdDate" value="{{ today()->toDateString() }}"
                   max="{{ today()->toDateString() }}">
        </div>
    </div>

    <div id="mdHint" class="alert info" style="margin-top:10px">{{ __('ops.md_pick_first') }}</div>
    <div id="mdCustodyWarn" class="alert warn" style="display:none;margin-top:10px">{{ __('ops.md_no_custody') }}</div>
    <div id="mdDisc" class="s" style="margin-top:8px;display:none;color:var(--muted)"></div>
</div>

{{-- ═══ التابات ═══ --}}
<div class="card" id="mdTabsCard" style="display:none">
    <div style="display:flex;gap:7px;margin-bottom:14px">
        <button type="button" class="btn md-tab on" data-tab="inv">🧾 {{ __('ops.md_tab_invoice') }}</button>
        <button type="button" class="btn md-tab" data-tab="ret">↩️ {{ __('ops.md_tab_return') }}</button>
        <button type="button" class="btn md-tab" data-tab="gift">🎁 {{ __('ops.md_tab_gift') }}</button>
    </div>

    {{-- ═══ فاتورة ═══ --}}
    <form method="POST" action="{{ route('ops.manual.invoice') }}" class="md-pane" data-pane="inv"
          onsubmit="return mdSubmit(this, 'inv')">
        @csrf
        <div class="searchbar" style="flex-wrap:wrap">
            <div>
                <label class="f">{{ __('ops.md_serial') }} *</label>
                <input type="text" name="paper_ref" required maxlength="30" placeholder="65221" dir="ltr">
            </div>
            <div id="mdPayBox" style="display:none">
                <label class="f">{{ __('ops.md_payment') }}</label>
                <select name="payment" id="mdPay">
                    <option value="cash">{{ __('ops.md_pay_cash') }}</option>
                    <option value="credit">{{ __('ops.md_pay_credit') }}</option>
                </select>
            </div>
            <div id="mdPayFixed" class="s" style="align-self:center;color:var(--muted)"></div>
        </div>
        <div class="md-items" data-list="inv"></div>
        <div class="md-est" data-est="inv"></div>
        <button class="btn gold" type="submit" style="margin-top:12px">✓ {{ __('ops.md_save_invoice') }}</button>
    </form>

    {{-- ═══ مرتجع ═══ --}}
    <form method="POST" action="{{ route('ops.manual.return') }}" class="md-pane" data-pane="ret"
          style="display:none" onsubmit="return mdSubmit(this, 'ret')">
        @csrf
        <div class="searchbar" style="flex-wrap:wrap">
            <div style="min-width:220px">
                <label class="f">{{ __('ops.md_policy') }} *</label>
                <select name="policy" id="mdPolicy" required></select>
            </div>
            <div style="flex:1;min-width:220px">
                <label class="f">{{ __('ops.md_note') }}</label>
                <input type="text" name="note" maxlength="400" style="width:100%">
            </div>
        </div>
        <div class="md-items" data-list="ret"></div>
        <div class="md-est" data-est="ret"></div>
        <button class="btn gold" type="submit" style="margin-top:12px">✓ {{ __('ops.md_save_return') }}</button>
    </form>

    {{-- ═══ هدية ═══ --}}
    <form method="POST" action="{{ route('ops.manual.gift') }}" class="md-pane" data-pane="gift"
          style="display:none" onsubmit="return mdSubmit(this, 'gift')">
        @csrf
        <div class="searchbar">
            <div style="flex:1">
                <label class="f">{{ __('ops.md_note') }}</label>
                <input type="text" name="note" maxlength="250" style="width:100%">
            </div>
        </div>
        <div class="md-items" data-list="gift"></div>
        <button class="btn gold" type="submit" style="margin-top:12px">✓ {{ __('ops.md_save_gift') }}</button>
    </form>

    {{-- ═══ منتقي الأصناف المشترك ═══ --}}
    <div style="margin-top:16px;border-top:1px solid var(--border);padding-top:12px">
        <label class="f">{{ __('ops.md_items') }}</label>
        <input type="search" id="mdProdSearch" style="width:100%"
               placeholder="{{ __('ops.md_add_item') }}"
               onkeydown="if (event.key === 'Enter') event.preventDefault()">
        <div id="mdProdList" class="md-prods"></div>
    </div>
</div>

@endsection

@section('scripts')
@php
    $jsFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP;
    $jsT = [
        'have' => __('ops.md_have'),
        'giftLeft' => __('ops.md_gift_left'),
        'qty' => __('ops.md_qty'),
        'good' => __('ops.md_cond_good'),
        'damaged' => __('ops.md_cond_damaged'),
        'est' => __('ops.md_est_total'),
        'noItems' => __('ops.md_no_items'),
        'payCash' => __('ops.md_pay_cash'),
        'payCredit' => __('ops.md_pay_credit'),
        'discNow' => __('ops.md_discount_now'),
    ];
@endphp
<script>
    const T = {!! json_encode($jsT, $jsFlags) !!};
    const DATA_URL = @js(route('ops.manual.data'));

    let PRODUCTS = [];
    let TERMS = null;

    // صفوف كل تاب: [{id, name, price, have, gift_left, qty, cond}]
    const rows = {inv: [], ret: [], gift: []};
    let activeTab = 'inv';

    function fmt(n) {
        return Number(n).toLocaleString('en-US', {maximumFractionDigits: 2});
    }

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    // ═══ فلترة العملاء بالبحث ═══
    document.getElementById('mdClientSearch').addEventListener('input', function () {
        const q = this.value.trim().toLowerCase();
        document.querySelectorAll('#mdClient option').forEach(function (o) {
            if (!o.value) return;
            o.hidden = q !== '' && !(o.dataset.t || '').includes(q);
        });
    });

    // ═══ تحميل داتا المندوب + العميل ═══
    async function mdLoad() {
        const rep = document.getElementById('mdRep').value;
        const client = document.getElementById('mdClient').value;
        const hint = document.getElementById('mdHint');
        const card = document.getElementById('mdTabsCard');

        if (!rep || !client) {
            card.style.display = 'none';
            hint.style.display = '';
            return;
        }

        hint.style.display = 'none';

        const res = await fetch(DATA_URL + '?user_id=' + rep + '&client_id=' + client, {
            headers: {Accept: 'application/json'},
        });
        const d = await res.json();

        PRODUCTS = d.products || [];
        TERMS = d;
        rows.inv = []; rows.ret = []; rows.gift = [];

        document.getElementById('mdCustodyWarn').style.display = d.custody_open ? 'none' : '';

        const disc = document.getElementById('mdDisc');
        disc.style.display = '';
        disc.textContent = T.discNow.replaceAll(':n', d.discount_pct);

        // كاش/آجل: سويتش للعميل المختلط بس — غير كده لابل ثابت
        const payBox = document.getElementById('mdPayBox');
        const payFixed = document.getElementById('mdPayFixed');
        if (d.pay_choice) {
            payBox.style.display = '';
            payFixed.textContent = '';
        } else {
            payBox.style.display = 'none';
            payFixed.textContent = d.terms === 'cash' ? T.payCash : T.payCredit;
        }

        // سياسات المرتجع
        const pol = document.getElementById('mdPolicy');
        pol.innerHTML = (d.policies || []).map(function (p) {
            return '<option value="' + esc(p.code) + '">' + esc(p.label) + '</option>';
        }).join('');

        card.style.display = '';
        renderProds();
        renderRows();
    }

    document.getElementById('mdRep').addEventListener('change', mdLoad);
    document.getElementById('mdClient').addEventListener('change', mdLoad);

    // ═══ التابات ═══
    document.querySelectorAll('.md-tab').forEach(function (b) {
        b.addEventListener('click', function () {
            activeTab = b.dataset.tab;
            document.querySelectorAll('.md-tab').forEach(x => x.classList.toggle('on', x === b));
            document.querySelectorAll('.md-pane').forEach(function (p) {
                p.style.display = p.dataset.pane === activeTab ? '' : 'none';
            });
            renderProds();
        });
    });

    // ═══ منتقي الأصناف ═══
    function renderProds() {
        const q = document.getElementById('mdProdSearch').value.trim().toLowerCase();
        const holder = document.getElementById('mdProdList');

        holder.innerHTML = PRODUCTS
            .filter(p => !q || p.name.toLowerCase().includes(q) || String(p.code).includes(q))
            .slice(0, 30)
            .map(function (p) {
                const meta = activeTab === 'gift'
                    ? T.giftLeft + ': ' + p.gift_left
                    : T.have + ': ' + p.have + ' · ' + fmt(p.price);
                return '<button type="button" class="md-prod" onclick="addRow(' + p.id + ')">' +
                    '<b>' + esc(p.name) + '</b><span>' + esc(meta) + '</span></button>';
            }).join('');
    }

    document.getElementById('mdProdSearch').addEventListener('input', renderProds);

    function addRow(pid) {
        const p = PRODUCTS.find(x => x.id === pid);
        if (!p) return;
        if (rows[activeTab].some(r => r.id === pid && activeTab !== 'ret')) return;

        rows[activeTab].push({id: p.id, name: p.name, price: p.price,
            have: p.have, gift_left: p.gift_left, qty: 1, cond: 'good'});
        renderRows();
    }

    function delRow(tab, i) {
        rows[tab].splice(i, 1);
        renderRows();
    }

    function setQty(tab, i, v) {
        rows[tab][i].qty = Math.max(1, parseInt(v || '1', 10));
        renderRows(true);
    }

    function setCond(tab, i, v) {
        rows[tab][i].cond = v;
    }

    function renderRows(keepFocus) {
        ['inv', 'ret', 'gift'].forEach(function (tab) {
            const holder = document.querySelector('.md-items[data-list="' + tab + '"]');
            if (!holder) return;

            holder.innerHTML = rows[tab].map(function (r, i) {
                return '<div class="md-row">' +
                    '<div class="nm"><b>' + esc(r.name) + '</b>' +
                    '<span>' + (tab === 'gift'
                        ? T.giftLeft + ': ' + r.gift_left
                        : T.have + ': ' + r.have + ' · ' + fmt(r.price)) + '</span></div>' +
                    '<input type="number" min="1" max="9999" value="' + r.qty + '" ' +
                    'onchange="setQty(\'' + tab + '\',' + i + ', this.value)" title="' + esc(T.qty) + '">' +
                    (tab === 'ret'
                        ? '<select onchange="setCond(\'' + tab + '\',' + i + ', this.value)">' +
                          '<option value="good"' + (r.cond === 'good' ? ' selected' : '') + '>' + esc(T.good) + '</option>' +
                          '<option value="damaged"' + (r.cond === 'damaged' ? ' selected' : '') + '>' + esc(T.damaged) + '</option>' +
                          '</select>'
                        : '') +
                    (tab !== 'gift'
                        ? '<b class="tot" dir="ltr">' + fmt(r.qty * r.price) + '</b>'
                        : '') +
                    '<button type="button" class="x" onclick="delRow(\'' + tab + '\',' + i + ')">✕</button>' +
                    '</div>';
            }).join('');

            const est = document.querySelector('.md-est[data-est="' + tab + '"]');
            if (est) {
                const total = rows[tab].reduce((t, r) => t + r.qty * r.price, 0);
                est.textContent = rows[tab].length ? T.est.replaceAll(':n', fmt(total)) : '';
            }
        });
    }

    // ═══ الإرسال: المراسي المشتركة + الصفوف كحقول مخفية ═══
    function mdSubmit(form, tab) {
        if (!rows[tab].length) {
            alert(T.noItems);
            return false;
        }

        form.querySelectorAll('.md-h').forEach(e => e.remove());

        const add = function (name, value) {
            const i = document.createElement('input');
            i.type = 'hidden';
            i.className = 'md-h';
            i.name = name;
            i.value = value;
            form.appendChild(i);
        };

        add('user_id', document.getElementById('mdRep').value);
        add('client_id', document.getElementById('mdClient').value);
        add('doc_date', document.getElementById('mdDate').value);

        rows[tab].forEach(function (r, i) {
            add('items[' + i + '][product_id]', r.id);
            add('items[' + i + '][qty]', r.qty);
            if (tab === 'ret') add('items[' + i + '][condition]', r.cond);
        });

        // ⚠️ دوستين سريعتين = مستندين — قفل الزرار أول ما يتبعت
        form.querySelector('button[type=submit]').disabled = true;

        return true;
    }
</script>
<style>
.md-tab{opacity:.65}
.md-tab.on{opacity:1;background:var(--royal-blue);color:#fff;border-color:var(--royal-blue)}
.md-prods{display:flex;flex-direction:column;gap:4px;max-height:260px;overflow-y:auto;margin-top:7px}
.md-prod{display:flex;justify-content:space-between;gap:10px;align-items:center;
  border:1px solid var(--border);background:var(--card);border-radius:10px;
  padding:8px 11px;cursor:pointer;font-family:inherit;font-size:12.5px;text-align:start}
.md-prod:hover{background:var(--blue-050);border-color:var(--royal-blue)}
.md-prod span{font-size:10.5px;color:var(--muted);white-space:nowrap}
.md-items{display:flex;flex-direction:column;gap:6px;margin-top:10px}
.md-row{display:flex;align-items:center;gap:9px;border:1px solid var(--border);
  border-radius:10px;padding:7px 10px;background:var(--card2)}
.md-row .nm{flex:1;min-width:0;font-size:12.5px}
.md-row .nm span{display:block;font-size:10px;color:var(--muted)}
.md-row input[type=number]{width:80px;text-align:center}
.md-row .tot{font-size:13px;min-width:70px;text-align:end}
.md-row .x{background:none;border:none;color:var(--muted);cursor:pointer;font-size:13px}
.md-row .x:hover{color:var(--red)}
.md-est{margin-top:10px;font-size:12.5px;font-weight:800;color:var(--royal-blue)}
</style>
@endsection
