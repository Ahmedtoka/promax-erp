@extends('layouts.system')

@section('title', __('review.docs_title'))

@php
    $money = fn ($v) => number_format((float) $v, 2);
    $u = auth()->user();
    $statuses = ['pending', 'issue', 'changed', 'ok', 'all'];
    $qs = fn (array $over) => request()->fullUrlWithQuery($over + ['doc' => null]);
@endphp

@section('content')

@include('erp.review._tabs')

{{-- ═══ الإنجاز الكلي ═══ --}}
<div class="dq-progress card">
    <div class="dq-p-head">
        <b class="dq-pct" data-o="pct">{{ $overall['pct'] }}%</b>
        <span data-o="label">{{ __('review.docs_progress', ['ok' => $overall['ok'], 'total' => $overall['total']]) }}</span>
        <span class="rv-sub" data-o="inv">{{ __('review.docs_inv_progress', ['ok' => $overall['ok_inv'], 'n' => $overall['invoices']]) }}</span>
        <span class="rv-sub" data-o="po">{{ __('review.docs_po_progress', ['ok' => $overall['ok_po'], 'n' => $overall['pos']]) }}</span>
        <span class="rv-sub" style="color:#DC2626" data-o="issue">✗ {{ $overall['issue'] }}</span>
        <span class="rv-sub" style="color:#B45309" data-o="changed">⚠ {{ $overall['changed'] }}</span>
        <button type="button" class="dq-keys" onclick="document.getElementById('dqKeys').showModal()" title="{{ __('review.keys_title') }}">⌨ ?</button>
    </div>
    <div class="rv-bar"><b data-o="bissue" style="width:{{ $overall['total'] ? round($overall['issue'] * 100 / $overall['total'], 1) : 0 }}%"></b><i data-o="bok" style="width:{{ $overall['total'] ? round($overall['ok'] * 100 / $overall['total'], 1) : 0 }}%"></i></div>
</div>

{{-- ═══ الفلاتر ═══ --}}
<form class="dq-filters card" method="GET" action="{{ route('erp.review.docs') }}">
    <label class="fl dq-q"><span>{{ __('review.f_search') }} <kbd>/</kbd></span>
        <input name="q" id="dqSearch" value="{{ $f['q'] }}" placeholder="{{ __('review.docs_search') }}" autocomplete="off"></label>
    <label class="fl"><span>{{ __('review.f_type') }}</span>
        <select name="type"><option value="">{{ __('review.f_type_all') }}</option>
            @foreach (['invoice', 'po'] as $t)<option value="{{ $t }}" @selected($f['type'] === $t)>{{ __('review.type_'.$t) }}</option>@endforeach
        </select></label>
    <label class="fl"><span>{{ __('review.f_mother') }}</span>
        <select name="mother"><option value="">{{ __('review.f_mother_all') }}</option>
            @foreach ($mothers as $m)<option value="{{ $m->id }}" @selected((int) $f['mother'] === $m->id)>{{ $m->displayName() }}</option>@endforeach
        </select></label>
    <label class="fl"><span>{{ __('review.f_chain') }}</span>
        <select name="group"><option value="">{{ __('review.f_chain_all') }}</option>
            <option value="solo" @selected($f['group'] === 'solo')>{{ __('review.f_solo') }}</option>
            @foreach ($chains as $g)<option value="{{ $g->id }}" @selected((int) $f['group'] === $g->id)>{{ $g->displayName() }}</option>@endforeach
        </select></label>
    <label class="fl"><span>{{ __('review.f_client') }}</span>
        <select name="client"><option value="">{{ __('review.f_client_all') }}</option>
            @foreach ($clientOpts as $id => $label)<option value="{{ $id }}" @selected((int) $f['client'] === (int) $id)>{{ $label }}</option>@endforeach
        </select></label>
    <label class="fl"><span>{{ __('review.f_rep') }}</span>
        <select name="rep"><option value="">{{ __('review.f_rep_all') }}</option>
            @foreach ($repOpts as $id => $label)<option value="{{ $id }}" @selected((int) $f['rep'] === (int) $id)>{{ $label }}</option>@endforeach
        </select></label>
    <label class="fl"><span>{{ __('review.f_flag') }}</span>
        <select name="flag"><option value="">{{ __('review.f_flag_all') }}</option>
            <option value="dup" @selected($f['flag'] === 'dup')>{{ __('review.f_flag_dup') }}</option>
            <option value="noserial" @selected($f['flag'] === 'noserial')>{{ __('review.f_flag_noserial') }}</option>
        </select></label>
    <input type="hidden" name="status" value="{{ $f['status'] }}">
    @include('partials._range', ['from' => $f['from'], 'to' => $f['to']])
    <div class="dq-f-btns">
        <button class="btn">{{ __('review.f_apply') }}</button>
        <a class="btn ghost" href="{{ route('erp.review.docs') }}">{{ __('review.f_reset') }}</a>
    </div>
</form>

<div class="dq-status">
    @foreach ($statuses as $s)
        <a href="{{ $qs(['status' => $s]) }}" class="chip st-{{ $s }} {{ $f['status'] === $s ? 'on' : '' }}">{{ __('review.st_'.$s) }} <b>{{ $counts[$s] }}</b></a>
    @endforeach
    <span class="rv-sub">{{ $money($scoped['grand']) }} · {{ __('review.g_stats', ['inv' => $scoped['invoices'], 'po' => $scoped['pos'], 'pcs' => number_format($scoped['pcs']), 'products' => number_format($scoped['products'])]) }}</span>
</div>

<div class="dq">
    {{-- ═══ الطابور ═══ --}}
    <div class="dq-list">
        @forelse ($groups as $g)
            @php $s = $g['stats']; @endphp
            <div class="dq-g">
                <div class="dq-g-head">
                    <div><b>{{ $g['solo'] ? $g['label'] : $g['label'] }}</b>
                        <span class="rv-sub">{{ $g['solo'] ? __('review.solo_group') : ($g['mother'] ?? '') }}</span></div>
                    <div class="rv-sub">{{ $s['ok'] }}/{{ $s['total'] }} · {{ $money($s['grand']) }}</div>
                </div>
                <div class="rv-bar thin"><b style="width:{{ $s['total'] ? round($s['issue'] * 100 / $s['total'], 1) : 0 }}%"></b><i style="width:{{ $s['pct'] }}%"></i></div>
                <div class="rv-sub dq-g-stats">{{ __('review.g_stats', ['inv' => $s['invoices'], 'po' => $s['pos'], 'pcs' => number_format($s['pcs']), 'products' => $s['products']]) }}</div>
                <div class="tablewrap">
                    <table class="dq-t">
                        <thead><tr>
                            <th data-nosum></th><th>{{ __('review.f_type') }}</th><th>#</th><th>{{ __('review.serial') }}</th>
                            <th>{{ __('review.doc_date') }}</th><th>{{ __('review.doc_client') }}</th><th>{{ __('review.doc_rep') }}</th>
                            <th>{{ __('review.col_incl') }}</th>
                        </tr></thead>
                        <tbody>
                        @foreach ($g['rows'] as $r)
                            <tr data-key="{{ $r['key'] }}" data-type="{{ $r['type'] }}" data-id="{{ $r['id'] }}" class="dq-row st-{{ $r['state'] }}" tabindex="-1">
                                <td><span class="dot st-{{ $r['state'] }}" title="{{ __('review.st_'.$r['state']) }}"></span></td>
                                <td>{{ $r['type'] === 'invoice' ? __('review.type_invoice') : __('review.type_po') }}</td>
                                <td dir="ltr"><b>{{ $r['number'] }}</b></td>
                                <td dir="ltr" class="c-serial">@if ($r['paper_ref']){{ $r['paper_ref'] }}@if ($r['dup']) <span class="flag" title="{{ __('review.f_flag_dup') }}">⚠</span>@endif @else<span class="miss">{{ __('review.serial_missing') }}</span>@endif</td>
                                <td dir="ltr">{{ $r['date'] }}</td>
                                <td>{{ $r['client_code'] }} · {{ $r['client'] }}</td>
                                <td class="c-rep">{{ $r['rep'] }}</td>
                                <td dir="ltr" class="c-grand">{{ $money($r['grand']) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @empty
            <div class="card rv-sub">{{ __('review.none_found') }}</div>
        @endforelse
    </div>

    {{-- ═══ المستند ═══ --}}
    <aside class="dq-pane card" id="dqPane" aria-live="polite">
        <div class="rv-sub dq-empty">{{ __('review.pick_doc') }}</div>
    </aside>
</div>

<div class="dq-toast" id="dqToast" role="status" hidden></div>

<dialog id="dqKeys" class="card">
    <h3>⌨ {{ __('review.keys_title') }}</h3>
    <p>{{ __('review.keys') }}</p>
    <form method="dialog"><button class="btn">OK</button></form>
</dialog>

@php
    // ⚠️ لينكات الـJS — كل واحد محروس بصلاحيته (فحص PermissionInventoryTest)
    $urls = ['pane' => route('erp.review.docs.pane', ['type' => '__T', 'id' => 0])];
    if (\App\Support\Access::allows($u, 'erp.review.docs.mark')) { $urls['mark'] = route('erp.review.docs.mark', ['type' => '__T', 'id' => 0]); }
    if (\App\Support\Access::allows($u, 'erp.review.docs.reopen')) { $urls['reopen'] = route('erp.review.docs.reopen', ['type' => '__T', 'id' => 0]); }
    if (\App\Support\Access::allows($u, 'erp.review.docs.serial')) { $urls['serial'] = route('erp.review.docs.serial', ['type' => '__T', 'id' => 0]); }
    if (\App\Support\Access::allows($u, 'erp.review.docs.rep')) { $urls['rep'] = route('erp.review.docs.rep', ['type' => '__T', 'id' => 0]); }
    if (\App\Support\Access::allows($u, 'erp.review.docs.date')) { $urls['date'] = route('erp.review.docs.date', ['type' => '__T', 'id' => 0]); }
    if (\App\Support\Access::allows($u, 'erp.review.docs.preview')) { $urls['preview'] = route('erp.review.docs.preview', ['type' => '__T', 'id' => 0]); }
    if (\App\Support\Access::allows($u, 'erp.review.docs.lines')) { $urls['lines'] = route('erp.review.docs.lines', ['type' => '__T', 'id' => 0]); }
@endphp

<style>
.dq-progress{padding:14px 16px;margin-bottom:12px}
.dq-p-head{display:flex;gap:14px;align-items:baseline;flex-wrap:wrap}
.dq-pct{font-size:30px;font-weight:900;color:#16A34A;font-variant-numeric:tabular-nums}
.dq-keys{margin-inline-start:auto;border:1px solid var(--border);background:var(--card);border-radius:8px;padding:4px 10px;cursor:pointer;font-weight:800;color:var(--muted)}
.dq-filters{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;padding:12px 14px;margin-bottom:10px}
.dq-q{flex:1 1 320px}.dq-q input{width:100%}
.dq-f-btns{display:flex;gap:6px}
.dq-status{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
.chip{display:inline-flex;gap:5px;align-items:center;padding:5px 11px;border-radius:999px;border:1px solid var(--border);background:var(--card);font-size:12px;font-weight:800;color:var(--text)}
.chip.on{outline:2px solid var(--royal-blue);outline-offset:1px}
.chip.st-ok{background:#F0FDF4;border-color:#86EFAC;color:#166534}
.chip.st-issue{background:#FEF2F2;border-color:#FCA5A5;color:#991B1B}
.chip.st-changed{background:#FFFBEB;border-color:#FCD34D;color:#92400E}
.chip.st-lock{background:#F1F5F9;color:#475569}
.dq{display:grid;grid-template-columns:minmax(0,1fr) minmax(380px,46%);gap:14px;align-items:start}
.dq-list{max-height:calc(100vh - 150px);overflow:auto;padding-inline-end:4px}
.dq-g{margin-bottom:14px}
.dq-g-head{display:flex;justify-content:space-between;gap:8px;align-items:baseline}
.dq-g-stats{margin:-2px 0 6px}
.rv-bar.thin{height:6px;margin:5px 0}
.dq-t td,.dq-t th{padding:6px 8px;font-size:12.5px;white-space:nowrap}
.dq-row{cursor:pointer}
.dq-row:hover td{background:#F8FAFC}
.dq-row.cur td{background:#EFF6FF!important;box-shadow:inset 0 1px 0 #93C5FD,inset 0 -1px 0 #93C5FD}
.dq-row.st-ok td{color:#64748B}
.dot{display:inline-block;width:10px;height:10px;border-radius:50%;background:#CBD5E1}
.dot.st-ok{background:#16A34A}.dot.st-issue{background:#DC2626}.dot.st-changed{background:#F59E0B}
.miss{color:#B45309;font-weight:800;font-size:11px}
.flag{color:#B45309}
.dq-pane{position:sticky;top:10px;max-height:calc(100vh - 20px);overflow:auto;padding:16px}
.dq-empty{padding:40px 10px;text-align:center}
.dq-toast{position:fixed;bottom:18px;inset-inline-start:50%;transform:translateX(-50%);background:#0F172A;color:#fff;padding:10px 18px;border-radius:10px;font-weight:800;z-index:60;max-width:90vw}
.dq-toast.bad{background:#B91C1C}.dq-toast.warn{background:#B45309}
kbd{font:600 10px/1 ui-monospace,monospace;border:1px solid var(--border);border-bottom-width:2px;border-radius:4px;padding:2px 4px;color:var(--muted);background:var(--card)}
/* اللوحة */
.dp-head{display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap}
.dp-num{font-size:20px;margin-inline:6px}
.dp-type{font-size:11px;font-weight:900;padding:3px 8px;border-radius:6px;background:#E0E7FF;color:#3730A3}
.dp-type.po{background:#CFFAFE;color:#155E75}
.dp-client{margin:8px 0 10px;display:flex;flex-direction:column;gap:2px}
.dp-lock,.dp-warn,.dp-note{padding:8px 10px;border-radius:8px;font-size:12.5px;margin-bottom:8px}
.dp-lock{background:#F1F5F9;color:#334155}.dp-lock a{margin-inline-start:6px}
.dp-warn{background:#FFFBEB;color:#92400E;border:1px solid #FCD34D}
.dp-note{background:#EFF6FF;color:#1E3A8A}
.dp-sbs{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px}
/* الورقة: ورق دفتر بسطور زرقا خفيفة وحافة مخرّمة — الحاجة الوحيدة اللي بتشبه الورق في الشاشة */
.slip{padding:10px 12px 12px;border-radius:4px;border:1px solid #E2E8F0;border-inline-start:3px dashed #94A3B8;
      background:#fff repeating-linear-gradient(180deg,transparent 0 27px,#E0EAFF 27px 28px)}
.slip h4,.sys h4{margin:0 0 8px;font-size:13px}
.slip .fl{display:block;margin-bottom:8px}.slip input{width:100%;font-size:16px;font-weight:800;letter-spacing:.5px;background:#fff}
.slip input.dp-serial:placeholder-shown{border-color:#F59E0B;background:#FFFBEB}
.dp-dup{font-size:12px;color:#B45309;margin:-4px 0 8px}.dp-dup a{margin-inline-start:6px;font-weight:800}
.dp-pdiff{font-size:12.5px}.dp-pdiff.bad b{color:#DC2626}.dp-pdiff.good b{color:#16A34A}
.sys{padding:10px 12px;border-radius:8px;background:#F8FAFC;border:1px solid var(--border)}
.sys-row{display:flex;justify-content:space-between;align-items:center;gap:8px;padding:4px 0;border-bottom:1px dashed #E2E8F0;font-size:13px}
.sys-row:last-child{border-bottom:0}.sys-row.big b{font-size:17px}
.sys-val{display:flex;gap:6px;align-items:center}.sys-val select,.sys-val input{max-width:170px;font-size:12px;padding:3px 6px}
.dp-lines table td,.dp-lines table th{padding:5px 7px;font-size:12px}
.dp-lines tr.off td{background:#FFF7ED}
.dp-lines tr.off td:nth-child(4){color:#C2410C;font-weight:800}
.dp-edit-lines{margin-top:8px}
.dp-lines-form{margin-top:10px;padding:10px;border:1px solid #93C5FD;border-radius:8px;background:#F8FBFF}
.dp-edit{width:100%}.dp-edit input{width:90px}.dp-edit .x{border:0;background:none;color:#DC2626;cursor:pointer;font-weight:900}
.dp-add{margin:8px 0}.dp-add select{width:100%}
.dp-preview{padding:8px 10px;border-radius:8px;background:#fff;border:1px solid var(--border);margin-bottom:8px;font-size:13px}
.dp-preview .d-up{color:#B91C1C}.dp-preview .d-down{color:#15803D}
.dp-mark{margin:14px 0 10px;padding-top:10px;border-top:1px solid var(--border)}
.dp-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
.btn.ok{background:#16A34A;border-color:#16A34A;color:#fff;font-size:15px;padding:10px 18px}
.btn.bad{background:#fff;border:1px solid #DC2626;color:#B91C1C}
.btn.ok kbd{color:#DCFCE7;background:transparent;border-color:#86EFAC}
.dp-hist summary{cursor:pointer;font-weight:800;margin-bottom:6px}
.ev{display:flex;gap:8px;padding:5px 0;border-bottom:1px dashed #E2E8F0;font-size:12.5px}
.ev-i{width:18px;text-align:center;font-weight:900}.ev-money{margin-inline-start:6px;font-weight:800}
.dp.busy{opacity:.55;pointer-events:none}
@media (max-width:1000px){.dq{grid-template-columns:1fr}.dq-pane{position:static;max-height:none}.dq-list{max-height:none}.dp-sbs{grid-template-columns:1fr}}
@media (prefers-reduced-motion:no-preference){.dq-row.flash td{animation:dqf .9s ease-out}@keyframes dqf{from{background:#DCFCE7}}}
</style>

<script>
(() => {
    const URLS = @js($urls);
    const CSRF = @js(csrf_token());
    const T = {
        issue: @js(__('review.issue_prompt')), reopen: @js(__('review.reopen_prompt')),
        net: @js(__('review.tot_net')), vat: @js(__('review.tot_vat')), incl: @js(__('review.tot_incl')),
        delta: @js(__('review.preview_delta')), after: @js(__('review.preview_title')),
        prog: @js(__('review.docs_progress', ['ok' => '__OK', 'total' => '__TOTAL'])),
        inv: @js(__('review.docs_inv_progress', ['ok' => '__OK', 'n' => '__N'])),
        po: @js(__('review.docs_po_progress', ['ok' => '__OK', 'n' => '__N'])),
        st: @js(collect(['pending', 'ok', 'issue', 'changed'])->mapWithKeys(fn ($s) => [$s => __('review.st_'.$s)])),
        missing: @js(__('review.serial_missing')),
    };
    const pane = document.getElementById('dqPane');
    const rows = () => [...document.querySelectorAll('.dq-row')];
    const url = (k, type, id) => URLS[k].replace('__T', type).replace(/\/0(\/|$)/, '/' + id + '$1');
    const fmt = n => Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    let cur = null, openedAt = 0;

    function toast(msg, kind) {
        const t = document.getElementById('dqToast');
        t.textContent = msg; t.className = 'dq-toast ' + (kind || ''); t.hidden = false;
        clearTimeout(t._h); t._h = setTimeout(() => t.hidden = true, kind === 'warn' ? 6000 : 2600);
    }

    async function open(key, scroll = true) {
        const tr = document.querySelector('.dq-row[data-key="' + key + '"]');
        const [type, id] = tr ? [tr.dataset.type, tr.dataset.id] : [key.startsWith('inv-') ? 'invoice' : 'po', key.split('-')[1]];
        rows().forEach(r => r.classList.toggle('cur', r === tr));
        if (tr && scroll) tr.scrollIntoView({ block: 'nearest' });
        const res = await fetch(url('pane', type, id), { headers: { 'Accept': 'text/html' } });
        if (!res.ok) { toast('⚠️ ' + res.status, 'bad'); return; }
        pane.innerHTML = await res.text();
        cur = key; openedAt = performance.now();
        history.replaceState(null, '', setParam('doc', key));
        bindPane();
    }

    function setParam(k, v) { const u = new URL(location.href); v ? u.searchParams.set(k, v) : u.searchParams.delete(k); return u.toString(); }

    function step(dir) {
        const list = rows();
        if (!list.length) return;
        const i = list.findIndex(r => r.dataset.key === cur);
        const n = list[Math.min(list.length - 1, Math.max(0, i + dir))] || list[0];
        if (n) open(n.dataset.key);
    }

    async function post(kind, body) {
        const dp = pane.querySelector('.dp');
        if (!dp || !URLS[kind]) return null;
        const fd = body instanceof FormData ? body : new FormData();
        if (!(body instanceof FormData)) Object.entries(body || {}).forEach(([k, v]) => v !== null && v !== undefined && fd.append(k, v));
        fd.append('_token', CSRF);
        dp.classList.add('busy');
        try {
            const res = await fetch(url(kind, dp.dataset.type, dp.dataset.id), { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } });
            const data = await res.json().catch(() => ({ ok: false, message: '⚠️ ' + res.status }));
            if (!data.ok && kind !== 'preview') { toast(data.message || ('⚠️ ' + res.status), 'bad'); return null; }
            if (kind === 'preview') return data;
            if (data.pane) { pane.innerHTML = data.pane; bindPane(); }
            if (data.row) paintRow(data.row);
            if (data.overall) paintOverall(data.overall);
            toast(data.message);
            if (data.warn) setTimeout(() => toast(data.warn, 'warn'), 600);
            return data;
        } finally { pane.querySelector('.dp')?.classList.remove('busy'); }
    }

    function paintRow(r) {
        const tr = document.querySelector('.dq-row[data-key="' + r.key + '"]');
        if (!tr) return;
        tr.className = 'dq-row st-' + r.state + (r.key === cur ? ' cur' : '');
        const dot = tr.querySelector('.dot'); dot.className = 'dot st-' + r.state; dot.title = T.st[r.state] || '';
        tr.querySelector('.c-serial').innerHTML = r.paper_ref ? escapeHtml(r.paper_ref) + (r.dup ? ' <span class="flag">⚠</span>' : '') : '<span class="miss">' + escapeHtml(T.missing) + '</span>';
        tr.querySelector('.c-rep').textContent = r.rep || '';
        tr.querySelector('.c-grand').textContent = fmt(r.grand);
        tr.classList.add('flash'); setTimeout(() => tr.classList.remove('flash'), 900);
    }

    function paintOverall(o) {
        const q = k => document.querySelector('[data-o="' + k + '"]');
        q('pct').textContent = o.pct + '%';
        q('label').textContent = T.prog.replace('__OK', o.ok).replace('__TOTAL', o.total);
        q('inv').textContent = T.inv.replace('__OK', o.ok_inv).replace('__N', o.invoices);
        q('po').textContent = T.po.replace('__OK', o.ok_po).replace('__N', o.pos);
        q('issue').textContent = '✗ ' + o.issue; q('changed').textContent = '⚠ ' + o.changed;
        q('bok').style.width = (o.total ? o.ok * 100 / o.total : 0) + '%';
        q('bissue').style.width = (o.total ? o.issue * 100 / o.total : 0) + '%';
    }

    function escapeHtml(s) { return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }

    async function markOk() {
        if (!pane.querySelector('.dp-ok')) return;
        const key = cur;
        const d = await post('mark', { status: 'ok', open_ms: Math.round(performance.now() - openedAt) });
        if (d && key === cur) step(1);
    }
    async function markIssue() {
        if (!pane.querySelector('.dp-issue')) return;
        const note = prompt(T.issue, '');
        if (note === null || !note.trim()) return;
        await post('mark', { status: 'issue', note, open_ms: Math.round(performance.now() - openedAt) });
    }

    function bindPane() {
        const dp = pane.querySelector('.dp');
        if (!dp) return;
        dp.querySelector('.dp-ok')?.addEventListener('click', markOk);
        dp.querySelector('.dp-issue')?.addEventListener('click', markIssue);
        dp.querySelector('.dp-reopen')?.addEventListener('click', () => { const n = prompt(T.reopen, ''); if (n !== null) post('reopen', { note: n }); });
        dp.querySelectorAll('[data-goto]').forEach(a => a.addEventListener('click', e => { e.preventDefault(); open(a.dataset.goto); }));

        const serial = dp.querySelector('.dp-serial');
        serial?.addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); e.stopPropagation(); if (!serial.readOnly) post('serial', { paper_ref: serial.value }); }
        });

        const pt = dp.querySelector('.dp-ptotal'), pd = dp.querySelector('.dp-pdiff');
        pt?.addEventListener('input', () => {
            const v = parseFloat(pt.value.replace(/,/g, ''));
            if (isNaN(v)) { pd.hidden = true; return; }
            const diff = Math.round((Number(pt.dataset.grand) - v) * 100) / 100;
            pd.hidden = false; pd.querySelector('b').textContent = fmt(diff);
            pd.className = 'dp-pdiff ' + (Math.abs(diff) < 0.005 ? 'good' : 'bad');
        });

        dp.querySelector('.dp-date')?.addEventListener('change', e => post('date', { date: e.target.value }));
        dp.querySelector('.dp-rep')?.addEventListener('change', e => e.target.value && post('rep', { rep_id: e.target.value }));

        const form = dp.querySelector('.dp-lines-form');
        if (!form) return;
        const openEdit = () => { form.hidden = false; form.querySelector('input[type=number]')?.focus(); preview(); };
        dp.querySelector('.dp-edit-lines')?.addEventListener('click', openEdit);
        dp._openEdit = openEdit;
        dp.querySelector('.dp-cancel').addEventListener('click', () => { open(cur, false); });
        form.addEventListener('input', debounce(preview, 300));
        form.addEventListener('click', e => { if (e.target.matches('.x')) { e.target.closest('tr').querySelector('input').value = 0; preview(); } });
        form.querySelector('.dp-add-sel').addEventListener('change', e => {
            const o = e.target.selectedOptions[0];
            if (!o.value) return;
            const tb = form.querySelector('.dp-edit tbody');
            let tr = tb.querySelector('tr[data-pid="' + o.value + '"]');
            if (!tr) {
                tr = document.createElement('tr'); tr.dataset.pid = o.value;
                tr.innerHTML = '<td>' + escapeHtml(o.dataset.label) + '</td><td><input type="number" min="0" step="1" name="qty[' + o.value + ']" value="1" dir="ltr"></td><td dir="ltr">' + escapeHtml(o.dataset.price) + '</td><td><button type="button" class="x">✕</button></td>';
                tb.appendChild(tr);
            }
            tr.querySelector('input').focus(); tr.querySelector('input').select();
            e.target.value = ''; preview();
        });
        form.addEventListener('submit', e => { e.preventDefault(); post('lines', new FormData(form)); });

        async function preview() {
            if (!URLS.preview) return;
            const fd = new FormData(form); fd.delete('note');
            const d = await post('preview', fd);
            const box = form.querySelector('.dp-preview');
            if (!d) return;
            box.hidden = false;
            if (d.not_priced) { box.textContent = '⚠ ' + d.not_priced; return; }
            if (d.message && d.ok === false) { box.textContent = '⚠ ' + d.message; return; }
            const delta = Math.round((d.grand - d.before.grand) * 100) / 100;
            box.innerHTML = '<b>' + escapeHtml(T.after) + '</b> — ' + escapeHtml(T.net) + ' <b dir="ltr">' + fmt(d.net) + '</b> · ' + escapeHtml(T.vat) + ' <b dir="ltr">' + fmt(d.tax) + '</b> · ' + escapeHtml(T.incl) + ' <b dir="ltr">' + fmt(d.grand) + '</b><br>' + escapeHtml(T.delta) + ': <b dir="ltr" class="' + (delta > 0 ? 'd-up' : 'd-down') + '">' + (delta > 0 ? '+' : '') + fmt(delta) + '</b> <span class="rv-sub">(' + fmt(d.before.grand) + ' → ' + fmt(d.grand) + ')</span>';
        }
    }

    function debounce(fn, ms) { let h; return (...a) => { clearTimeout(h); h = setTimeout(() => fn(...a), ms); }; }

    document.querySelector('.dq-list').addEventListener('click', e => { const tr = e.target.closest('.dq-row'); if (tr) open(tr.dataset.key, false); });

    document.addEventListener('keydown', e => {
        if (e.ctrlKey || e.metaKey || e.altKey) return;
        const typing = e.target.closest('input, select, textarea, [contenteditable]');
        if (e.key === 'Escape') {
            if (pane.querySelector('.dp-lines-form:not([hidden])')) { e.preventDefault(); open(cur, false); }
            else if (typing) e.target.blur();
            return;
        }
        if (typing || document.querySelector('dialog[open]')) return;
        const dp = pane.querySelector('.dp');
        switch (e.key) {
            case '/': e.preventDefault(); document.getElementById('dqSearch').focus(); break;
            case 'j': case 'J': case 'ArrowDown': e.preventDefault(); step(1); break;
            case 'k': case 'K': case 'ArrowUp': e.preventDefault(); step(-1); break;
            case 'Enter': if (dp) { e.preventDefault(); markOk(); } break;
            case 'x': case 'X': if (dp) { e.preventDefault(); markIssue(); } break;
            case 's': case 'S': if (dp) { e.preventDefault(); dp.querySelector('.dp-serial')?.focus(); dp.querySelector('.dp-serial')?.select(); } break;
            case 'd': case 'D': if (dp) { e.preventDefault(); dp.querySelector('.dp-date')?.focus(); } break;
            case 'r': case 'R': if (dp) { e.preventDefault(); dp.querySelector('.dp-rep')?.focus(); } break;
            case 'e': case 'E': if (dp?._openEdit) { e.preventDefault(); dp._openEdit(); } break;
            case '?': document.getElementById('dqKeys').showModal(); break;
        }
    });

    const start = @js($open) || (rows()[0] && rows()[0].dataset.key);
    if (start) open(start);
})();
</script>
@endsection
