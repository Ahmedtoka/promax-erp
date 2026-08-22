@extends('layouts.system')

@section('title', __('ops.md_title'))

@section('content')

<div class="card">
    <h3>✍️ {{ __('ops.md_title') }} <span class="side">{{ __('ops.md_sub') }}</span></h3>

    {{-- ═══ ١. المراسي: المندوب + التاريخ ═══ --}}
    <div class="searchbar" style="align-items:flex-end;flex-wrap:wrap">
        <div style="min-width:240px">
            <label class="f">{{ __('ops.md_rep') }}</label>
            <select id="mdRep" style="width:100%">
                <option value="">—</option>
                @foreach ($reps as $r)
                    <option value="{{ $r->id }}">{{ $r->displayName() }} — {{ $r->roleLabel() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="f">{{ __('ops.md_date') }}</label>
            <input type="date" id="mdDate" value="{{ today()->toDateString() }}"
                   max="{{ today()->toDateString() }}">
        </div>
    </div>

    {{-- ═══ ٢. العميل — بحث بليستة نتايج، مش سيلكت (إصلاح ٢١/٨) ═══ --}}
    <div style="margin-top:12px">
        <label class="f">{{ __('ops.md_client') }}</label>
        <div id="mdClientPicked" class="md-picked" style="display:none">
            <b id="mdClientName"></b>
            <button type="button" class="x" onclick="clearClient()">✕</button>
        </div>
        <input type="search" id="mdClientSearch" placeholder="{{ __('ops.md_client_search') }}"
               style="width:100%"
               onkeydown="if (event.key === 'Enter') event.preventDefault()">
        <div id="mdClientList" class="md-prods" style="display:none"></div>
        <input type="hidden" id="mdClient" value="">
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

        {{-- البحث فوق — والمختار بينزل تحتيه في الجدول (طلب المالك) --}}
        <div class="md-pickwrap">
            <input type="search" class="md-psearch" data-tab="inv" style="width:100%"
                   placeholder="{{ __('ops.md_add_item') }}"
                   onkeydown="if (event.key === 'Enter') event.preventDefault()">
            <div class="md-prods md-plist" data-plist="inv"></div>
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
        <div class="md-pickwrap">
            <input type="search" class="md-psearch" data-tab="ret" style="width:100%"
                   placeholder="{{ __('ops.md_add_item') }}"
                   onkeydown="if (event.key === 'Enter') event.preventDefault()">
            <div class="md-prods md-plist" data-plist="ret"></div>
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
        <div class="md-pickwrap">
            <input type="search" class="md-psearch" data-tab="gift" style="width:100%"
                   placeholder="{{ __('ops.md_add_item') }}"
                   onkeydown="if (event.key === 'Enter') event.preventDefault()">
            <div class="md-prods md-plist" data-plist="gift"></div>
        </div>
        <div class="md-items" data-list="gift"></div>
        <button class="btn gold" type="submit" style="margin-top:12px">✓ {{ __('ops.md_save_gift') }}</button>
    </form>
</div>

@endsection

@section('scripts')
@php
    $jsFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP;

    // العملاء للجافاسكربت — البحث عابر اللغات زي كل الشاشات
    $clientRows = $clients->map(fn ($c) => [
        'id' => $c->id,
        'name' => $c->fullName(),
        'q' => mb_strtolower($c->fullName().' '.$c->name_en),
    ])->values();

    $jsT = [
        'more' => __('ops.md_more_hint'),
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
        'add' => __('ops.md_add_btn'),
        'noProds' => __('ops.md_no_custody_items'),
    ];
@endphp
<script>
    const T = {!! json_encode($jsT, $jsFlags) !!};
    const CLIENTS = {!! json_encode($clientRows, $jsFlags) !!};
    const DATA_URL = @js(route('ops.manual.data'));

    let PRODUCTS = [];      // عهدة المندوب — للبيع والهدية
    let RET_PRODUCTS = [];  // كتالوج العميل المتسعّر — للمرتجع

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

    // ═══════════ اختيار العميل — بحث بليستة نتايج ═══════════
    const cSearch = document.getElementById('mdClientSearch');
    const cList = document.getElementById('mdClientList');

    cSearch.addEventListener('input', function () {
        const q = this.value.trim().toLowerCase();

        if (!q) {
            cList.style.display = 'none';
            return;
        }

        // ⚠️ السقف كان 15 وساكت (بلاغ ٢٢/٨: «كاريبو 27 وطالع 15 بس») —
        // بقى 60 + سطر بيقول لو فيه أكتر عشان الناقص مايبقاش خفي
        const all = CLIENTS.filter(c => c.q.includes(q));
        const hits = all.slice(0, 60);
        cList.innerHTML = (hits.map(function (c) {
            return '<button type="button" class="md-prod" onclick="pickClient(' + c.id + ')">' +
                '<b>' + esc(c.name) + '</b></button>';
        }).join('') || '<div class="s" style="padding:8px">—</div>')
            + (all.length > 60
                ? '<div class="s" style="padding:8px;color:var(--muted)">+' + (all.length - 60) + ' — ' + esc(T.more) + '</div>'
                : '');
        cList.style.display = '';
    });

    function pickClient(id) {
        const c = CLIENTS.find(x => x.id === id);
        if (!c) return;

        document.getElementById('mdClient').value = c.id;
        document.getElementById('mdClientName').textContent = c.name;
        document.getElementById('mdClientPicked').style.display = '';
        cSearch.value = '';
        cSearch.style.display = 'none';
        cList.style.display = 'none';
        mdLoad();
    }

    function clearClient() {
        document.getElementById('mdClient').value = '';
        document.getElementById('mdClientPicked').style.display = 'none';
        cSearch.style.display = '';
        document.getElementById('mdTabsCard').style.display = 'none';
        document.getElementById('mdHint').style.display = '';
    }

    // ═══════════ تحميل داتا المندوب + العميل ═══════════
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
        RET_PRODUCTS = d.ret_products || [];
        rows.inv = []; rows.ret = []; rows.gift = [];

        document.getElementById('mdCustodyWarn').style.display = d.custody_open ? 'none' : '';

        const disc = document.getElementById('mdDisc');
        disc.style.display = '';
        disc.textContent = T.discNow.replaceAll(':n', d.discount_pct);

        const payBox = document.getElementById('mdPayBox');
        const payFixed = document.getElementById('mdPayFixed');
        if (d.pay_choice) {
            payBox.style.display = '';
            payFixed.textContent = '';
        } else {
            payBox.style.display = 'none';
            payFixed.textContent = d.terms === 'cash' ? T.payCash : T.payCredit;
        }

        const pol = document.getElementById('mdPolicy');
        pol.innerHTML = (d.policies || []).map(function (p) {
            return '<option value="' + esc(p.code) + '">' + esc(p.label) + '</option>';
        }).join('');

        card.style.display = '';
        renderProds();
        renderRows();
    }

    document.getElementById('mdRep').addEventListener('change', mdLoad);

    // ═══════════ التابات ═══════════
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

    // ═══════════ منتقي الأصناف — بالصور، زي تسليم العهدة ═══════════
    //
    // البيع والهدية من **عهدة المندوب بس**، والمرتجع من كتالوج
    // العميل المتسعّر (العميل بيرجّع بضاعة عنده هو).
    function sourceFor(tab) {
        if (tab === 'ret') return RET_PRODUCTS;
        if (tab === 'gift') return PRODUCTS.filter(p => p.gift_left > 0);
        return PRODUCTS.filter(p => p.have > 0);
    }

    function renderProds() {
        document.querySelectorAll('.md-plist').forEach(function (holder) {
            const tab = holder.dataset.plist;
            const input = document.querySelector('.md-psearch[data-tab="' + tab + '"]');
            const q = (input?.value || '').trim().toLowerCase();
            const src = sourceFor(tab);

            const hits = src.filter(p =>
                !q || p.name.toLowerCase().includes(q) || String(p.code).includes(q)
            ).slice(0, 30);

            holder.innerHTML = hits.map(function (p) {
                const meta = tab === 'gift'
                    ? T.giftLeft + ': ' + p.gift_left
                    : (tab === 'inv' ? T.have + ': ' + p.have + ' · ' : '') + fmt(p.price);

                return '<button type="button" class="md-prod" onclick="addRow(\'' + tab + '\',' + p.id + ')">' +
                    (p.image
                        ? '<img src="' + esc(p.image) + '" loading="lazy" alt="">'
                        : '<span class="md-noimg">📦</span>') +
                    '<span class="md-pinfo"><b>' + esc(p.name) + '</b>' +
                    '<i>' + esc(String(p.code)) + ' · ' + esc(meta) + '</i></span>' +
                    '<span class="md-addbtn">＋ ' + esc(T.add) + '</span>' +
                    '</button>';
            }).join('') || '<div class="s" style="padding:10px;text-align:center">' + esc(T.noProds) + '</div>';
        });
    }

    document.querySelectorAll('.md-psearch').forEach(function (i) {
        i.addEventListener('input', renderProds);
    });

    function addRow(tab, pid) {
        const p = sourceFor(tab).find(x => x.id === pid);
        if (!p) return;

        const existing = rows[tab].find(r => r.id === pid);
        if (existing && tab !== 'ret') {
            existing.qty++;
            renderRows();
            return;
        }

        rows[tab].push({id: p.id, name: p.name, image: p.image, price: p.price,
            have: p.have, gift_left: p.gift_left, qty: 1, cond: 'good'});
        renderRows();
    }

    function delRow(tab, i) {
        rows[tab].splice(i, 1);
        renderRows();
    }

    function setQty(tab, i, v) {
        rows[tab][i].qty = Math.max(1, parseInt(v || '1', 10));
        renderRows();
    }

    function setCond(tab, i, v) {
        rows[tab][i].cond = v;
    }

    function renderRows() {
        ['inv', 'ret', 'gift'].forEach(function (tab) {
            const holder = document.querySelector('.md-items[data-list="' + tab + '"]');
            if (!holder) return;

            holder.innerHTML = rows[tab].map(function (r, i) {
                return '<div class="md-row">' +
                    (r.image
                        ? '<img src="' + esc(r.image) + '" alt="">'
                        : '<span class="md-noimg">📦</span>') +
                    '<div class="nm"><b>' + esc(r.name) + '</b>' +
                    '<span>' + (tab === 'gift'
                        ? T.giftLeft + ': ' + r.gift_left
                        : (tab === 'inv' ? T.have + ': ' + r.have + ' · ' : '') + fmt(r.price)) + '</span></div>' +
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

    // ═══════════ الإرسال ═══════════
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

        form.querySelector('button[type=submit]').disabled = true;

        return true;
    }
</script>
<style>
.md-tab{opacity:.65}
.md-tab.on{opacity:1;background:var(--royal-blue);color:#fff;border-color:var(--royal-blue)}
.md-picked{display:flex;align-items:center;gap:9px;border:1.5px solid var(--royal-blue);
  background:var(--blue-050);border-radius:10px;padding:9px 12px;margin-bottom:6px;font-size:13px}
.md-picked .x{margin-inline-start:auto;background:none;border:none;color:var(--muted);
  cursor:pointer;font-size:13px}
.md-picked .x:hover{color:var(--red)}
.md-pickwrap{margin-top:6px}
.md-prods{display:flex;flex-direction:column;gap:4px;max-height:300px;overflow-y:auto;margin-top:7px}
.md-prod{display:flex;gap:10px;align-items:center;
  border:1px solid var(--border);background:var(--card);border-radius:10px;
  padding:7px 10px;cursor:pointer;font-family:inherit;font-size:12.5px;text-align:start}
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
.md-row .nm span{display:block;font-size:10px;color:var(--muted)}
.md-row input[type=number]{width:80px;text-align:center}
.md-row .tot{font-size:13px;min-width:70px;text-align:end}
.md-row .x{background:none;border:none;color:var(--muted);cursor:pointer;font-size:13px}
.md-row .x:hover{color:var(--red)}
.md-est{margin-top:10px;font-size:12.5px;font-weight:800;color:var(--royal-blue)}
</style>
@endsection
