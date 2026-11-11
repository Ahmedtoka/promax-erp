{{--
    ═══ التوزيع التلقائي للعملاء اللي من غير مندوب (١١/١١) ═══
    اتنقل من الشاشة الجغرافية القديمة لشاشة التسكين — هو تسكين
    (`clients.rep_id`) مش خط سير. نفس الراوتات: `ops.geo.distribute`
    (معاينة GET) و`ops.geo.assign` (حفظ) تحت `act.field.plan`.
    القاعدة في `JourneyController::geoDistribute`: المندوب اللي عنده
    أكتر عملاء في المنطقة، وإلا الأقل حمل.
--}}
@php
    $distViewer = auth()->user();
    $distManagers = \App\Models\Branch::scope(\App\Models\User::where('role', 'manager')->where('active', true))
        ->when($distViewer?->role === 'manager', fn ($q) => $q->whereKey($distViewer->id))
        ->orderBy('name')->get();
    $distJs = json_encode([
        'distUrl' => route('ops.geo.distribute'),
        'assignUrl' => route('ops.geo.assign'),
        't' => [
            'loading' => __('common.loading'),
            'fail' => __('common.something_wrong'),
            'client' => __('journey.geo_client'),
            'category' => __('journey.geo_category'),
            'distTitle' => __('journey.geo_dist_title', ['name' => '__N__']),
            'distHint' => __('journey.geo_dist_hint'),
            'distSuggested' => __('journey.geo_dist_suggested'),
            'distCurrent' => __('journey.geo_dist_current'),
            'distNone' => __('journey.geo_dist_none'),
            'distEmpty' => __('journey.geo_dist_empty'),
            'distNoReps' => __('journey.geo_dist_no_reps'),
            'distBlocked' => __('journey.geo_dist_blocked'),
            'distSkip' => __('journey.geo_dist_skip'),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
@endphp

@if ($distManagers->isNotEmpty() && \App\Support\Access::action($distViewer, 'act.field.plan'))
<span class="dist-launch">
    @if ($distManagers->count() === 1)
        <button type="button" class="btn sm" onclick="distOpen({{ $distManagers[0]->id }}, @js($distManagers[0]->displayName()))">⚡ {{ __('journey.geo_dist_title', ['name' => $distManagers[0]->displayName()]) }}</button>
    @else
        <select class="sm" data-nosearch="1" onchange="if (this.value) { distOpen(+this.value, this.options[this.selectedIndex].text); this.value = ''; }">
            <option value="">⚡ {{ __('journey.geo_dist_title', ['name' => '…']) }}</option>
            @foreach ($distManagers as $m)
                <option value="{{ $m->id }}">{{ $m->displayName() }}</option>
            @endforeach
        </select>
    @endif
</span>

<dialog id="distDlg" class="dist-dlg">
    <div class="dlg">
        <h4>⚡ <span id="distTitle">—</span> <span class="side s" id="distSub"></span></h4>
        <div class="alert info" id="distHint"></div>
        <div class="alert" id="distMsg" style="display:none"></div>
        <div id="distReps" class="dist-chips" style="margin-bottom:10px"></div>
        <div id="distBody" style="max-height:52vh;overflow:auto"></div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:12px">
            <label style="font-size:12.5px;display:inline-flex;align-items:center;gap:7px">
                <input type="checkbox" id="distDrivers" onchange="distLoad()">
                {{ __('journey.geo_dist_drivers') }}
            </label>
            <div style="margin-inline-start:auto;display:flex;gap:8px">
                <button class="btn" type="button" onclick="closeDlg('distDlg')">{{ __('common.close') }}</button>
                <button class="btn gold" type="button" id="distSave" onclick="distSave()">✅ {{ __('journey.geo_dist_confirm') }}</button>
            </div>
        </div>
    </div>
</dialog>

<style>
.dist-dlg>.dlg{width:min(980px,97vw)}
.dist-chips{display:flex;gap:6px;flex-wrap:wrap}
.dist-chip{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--border);border-radius:20px;padding:2px 9px;font-size:11.5px}
.dist-chip b{color:var(--royal-blue)}
.dist-next{font-size:11px;color:var(--muted);white-space:nowrap;display:block}
.dist-zgrp td{background:var(--card2);font-weight:900;color:var(--royal-blue)}
</style>
<script>
(function () {
    'use strict';
    var D = {!! $distJs !!};
    var $ = function (id) { return document.getElementById(id); };
    var dist = { manager: null, name: '', rows: [], reps: [] };

    function el(tag, cls, txt) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (txt !== undefined && txt !== null) e.textContent = txt;
        return e;
    }
    function say(text, kind) {
        var m = $('distMsg');
        m.className = 'alert' + (kind ? ' ' + kind : '');
        m.textContent = text || '';
        m.style.display = text ? '' : 'none';
    }
    function counts() {
        var out = {};
        dist.rows.forEach(function (r) { var v = r.sel ? r.sel.value : ''; if (v) out[v] = (out[v] || 0) + 1; });
        return out;
    }
    function renderReps() {
        var c = counts(), box = $('distReps');
        box.innerHTML = '';
        dist.reps.forEach(function (r) {
            var chip = el('span', 'dist-chip');
            chip.appendChild(el('span', null, r.name + ' · ' + r.role));
            chip.appendChild(el('span', 'dist-next', D.t.distCurrent + ' ' + r.current));
            chip.appendChild(el('b', null, '+' + (c[String(r.id)] || 0)));
            box.appendChild(chip);
        });
    }
    function render(data) {
        var body = $('distBody');
        dist.rows = []; dist.reps = data.reps || []; body.innerHTML = '';
        $('distSub').textContent = data.clients.length;
        if (!dist.reps.length) { $('distReps').innerHTML = ''; body.appendChild(el('div', 'alert warn', D.t.distNoReps)); return; }
        if (!data.clients.length) { $('distReps').innerHTML = ''; body.appendChild(el('div', 'alert good', D.t.distEmpty)); return; }
        var table = el('table'), head = document.createElement('tr');
        [D.t.client, D.t.category, D.t.distSuggested].forEach(function (c) { head.appendChild(el('th', null, c)); });
        table.appendChild(head);
        var lastZone = null;
        data.clients.forEach(function (c) {
            var zKey = String(c.zone_id === null ? 'none' : c.zone_id);
            if (zKey !== lastZone) {
                lastZone = zKey;
                var gtr = document.createElement('tr'), gtd = el('td', null, c.governorate + ' › ' + c.zone);
                gtd.colSpan = 3; gtr.className = 'dist-zgrp'; gtr.appendChild(gtd); table.appendChild(gtr);
            }
            var tr = document.createElement('tr'), st = { id: c.id, sel: null };
            var tdN = el('td'); tdN.appendChild(el('b', null, c.name)); tdN.appendChild(el('span', 'dist-next', c.code)); tr.appendChild(tdN);
            var tdC = el('td'); tdC.appendChild(el('span', 'badge ' + c.category_class, c.category)); tr.appendChild(tdC);
            var tdS = el('td');
            if (!c.reps.length) {
                tdS.appendChild(el('span', 'badge b-red', D.t.distBlocked));
            } else {
                var s = document.createElement('select');
                s.dataset.nosearch = '1';
                [{ v: '', t: '— ' + D.t.distSkip + ' —' }].concat(c.reps.map(function (r) { return { v: r.id, t: r.name }; }))
                    .forEach(function (o) {
                        var op = document.createElement('option');
                        op.value = o.v; op.textContent = o.t;
                        if (String(o.v) === String(c.suggested === null ? '' : c.suggested)) op.selected = true;
                        s.appendChild(op);
                    });
                s.addEventListener('change', renderReps);
                st.sel = s; tdS.appendChild(s);
            }
            tr.appendChild(tdS); table.appendChild(tr); dist.rows.push(st);
        });
        body.appendChild(table);
        renderReps();
    }
    window.distLoad = function () {
        $('distBody').innerHTML = ''; $('distReps').innerHTML = ''; dist.rows = [];
        say(D.t.loading, 'info');
        var qs = 'manager=' + encodeURIComponent(dist.manager) + ($('distDrivers').checked ? '&drivers=1' : '');
        fetch(D.distUrl + '?' + qs, { headers: { 'Accept': 'application/json' } })
            .then(function (r) { if (!r.ok) throw new Error('http'); return r.json(); })
            .then(function (j) { render(j); say(''); })
            .catch(function () { say(D.t.fail, 'warn'); });
    };
    window.distOpen = function (managerId, name) {
        dist.manager = managerId; dist.name = name || '';
        $('distTitle').textContent = D.t.distTitle.replace('__N__', dist.name);
        $('distHint').textContent = D.t.distHint;
        $('distSub').textContent = '';
        openDlg('distDlg');
        window.distLoad();
    };
    window.distSave = function () {
        var payload = [];
        dist.rows.forEach(function (r) { if (r.sel && r.sel.value) payload.push({ client_id: r.id, user_id: parseInt(r.sel.value, 10) }); });
        if (!payload.length) { say(D.t.distNone, 'warn'); return; }
        say(D.t.loading, 'info');
        var btn = $('distSave'); btn.disabled = true;
        var token = document.querySelector('meta[name="csrf-token"]');
        fetch(D.assignUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token ? token.content : '' },
            body: JSON.stringify({ rows: payload })
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (j) { return { ok: r.ok, body: j }; });
        }).then(function (res) {
            btn.disabled = false;
            if (!res.ok) { say(res.body.message || D.t.fail, 'warn'); return; }
            say(res.body.message || '', 'good');
            setTimeout(function () { window.location.reload(); }, 900);
        }).catch(function () { btn.disabled = false; say(D.t.fail, 'warn'); });
    };
})();
</script>
@endif
