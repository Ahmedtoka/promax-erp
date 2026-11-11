@extends('layouts.system')

{{--
    راسم خط السير للعملاء (١١/١١/٢٠٢٦ — طلب المالك: «فلو المحتملين هو هو»):

    نفس `erp/leads_route.blade.php` بالحرف: فلتر ← نقط على الخريطة ←
    تدوس بالترتيب فتترقّم ١ ٢ ٣ ويترسم خط ← «رتب بالأقرب» ← حفظ.
    الفرق: الخطة نمط أسبوعي، فبتختار **مندوب + يوم أسبوع** وخط اليوم
    الحالي بيتحمّل مترقّم — والحفظ بيخلّي اليوم بالظبط التسلسل ده.
--}}

@section('title', __('journey.rb_title'))

@section('actions')
    <a class="btn" href="{{ route('ops.journeys') }}">📅 {{ __('journey.week_btn') }}</a>
    <a class="btn" href="{{ route('ops.journeys.week') }}">👁 {{ __('journey.follow_btn') }}</a>
@endsection

@section('content')

@if (session('warn'))
    <div class="alert warn" style="margin-bottom:12px"><span>⚠️</span><span>{{ session('warn') }}</span></div>
@endif

{{-- ═══ كروت التغطية (من الشاشة الجغرافية القديمة) ═══ --}}
<div class="rb-kpis">
    <div class="rb-kpi"><b>{{ number_format($kpi['clients']) }}</b><span>{{ __('journey.rb_kpi_clients') }}</span></div>
    <a class="rb-kpi" href="{{ route('ops.geo', ['only' => 'unplanned']) }}"><b>{{ number_format($kpi['unplanned']) }}</b><span>{{ __('journey.rb_kpi_unplanned') }}</span></a>
    <a class="rb-kpi {{ $kpi['norep'] ? 'warn' : '' }}" href="{{ route('ops.assignments', ['only' => 'orphans']) }}"><b>{{ number_format($kpi['norep']) }}</b><span>{{ __('journey.rb_kpi_norep') }} · {{ __('journey.rb_fix') }} ↗</span></a>
    <a class="rb-kpi {{ $kpi['noloc'] ? 'warn' : '' }}" href="{{ route('erp.client_locations', ['show' => 'no_location']) }}"><b>{{ number_format($kpi['noloc']) }}</b><span>{{ __('journey.rb_kpi_noloc') }} · {{ __('journey.rb_fix') }} ↗</span></a>
</div>

{{-- ═══ الفلاتر — بليبلات ═══ --}}
<div class="card" style="margin-bottom:12px">
    <form method="GET" action="{{ route('ops.geo') }}" class="searchbar" style="align-items:flex-end;row-gap:10px" id="rbFilter">
        <input type="hidden" name="rep" id="rbFRep" value="{{ $selRep }}">
        <input type="hidden" name="day" id="rbFDay" value="{{ $selDay }}">
        <label class="fl grow"><span>{{ __('ui.l_search') }}</span>
            <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="🔎 {{ __('common.search') }}"></label>
        <label class="fl"><span>{{ __('ui.l_gov') }}</span>
            <select name="gov">
                <option value="">{{ __('journey.all_govs') }}</option>
                @foreach ($govs as $g => $n)
                    <option value="{{ $g }}" @selected($filters['gov'] === $g)>{{ \App\Support\Governorates::label($g) ?: $g }} ({{ $n }})</option>
                @endforeach
            </select></label>
        <label class="fl wide"><span>{{ __('ui.l_zone') }}</span>
            <select name="zone">
                <option value="">{{ __('journey.all_zones') }}</option>
                @foreach ($zones as $z)
                    <option value="{{ $z->id }}" @selected($filters['zone'] == $z->id)>{{ $z->displayName() }} ({{ $zoneCounts[$z->id] ?? 0 }})</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('journey.f_channel') }}</span>
            <select name="channel">
                <option value="">{{ __('journey.all_channels') }}</option>
                @foreach ($channels as $c)
                    <option value="{{ $c->id }}" @selected($filters['channel'] == $c->id)>{{ $c->displayName() }} ({{ $channelCounts[$c->id] ?? 0 }})</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('journey.f_chain') }}</span>
            <select name="chain">
                <option value="">{{ __('journey.all_chains') }}</option>
                @foreach ($chains as $c)
                    <option value="{{ $c->id }}" @selected($filters['chain'] == $c->id)>{{ $c->displayName() }} ({{ $chainCounts[$c->id] ?? 0 }})</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('journey.f_owner') }}</span>
            <select name="owner">
                <option value="">{{ __('journey.all_owners') }}</option>
                @foreach ($owners as $o)
                    <option value="{{ $o->id }}" @selected($filters['owner'] == $o->id)>{{ $o->displayName() }}</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('journey.f_only') }}</span>
            <select name="only">
                <option value="">{{ __('journey.f_only_all') }}</option>
                <option value="unplanned" @selected($filters['only'] === 'unplanned')>{{ __('journey.f_only_unplanned') }}</option>
                <option value="late" @selected($filters['only'] === 'late')>{{ __('journey.f_only_late') }}</option>
            </select></label>
        <button class="btn gold sm">{{ __('common.filter') }}</button>
        <a class="btn sm" href="{{ route('ops.geo', array_filter(['rep' => $selRep, 'day' => $selDay], fn ($v) => $v !== null)) }}">🧹</a>
        <span class="badge b-blue" style="align-self:center" id="rbMapCount"></span>
    </form>
    @if ($capped)
        <div class="dash-hint" style="margin-top:6px;color:#B45309">{{ __('journey.rb_capped', ['n' => \App\Http\Controllers\RoutePlannerController::MAP_CAP]) }}</div>
    @endif
</div>

{{-- ═══ بار الحفظ: مندوب + يوم + تردد + المحدد + حفظ ═══ --}}
<div class="card" style="margin-bottom:12px">
    <form method="POST" action="{{ route('ops.geo.save') }}" id="rbForm" @unless (\App\Support\Access::action(auth()->user(), 'act.field.plan')) inert @endunless
          class="rb-bar" onsubmit="return rbSubmit(this)">
        @csrf
        @foreach (['search', 'zone', 'gov', 'channel', 'chain', 'owner', 'only'] as $k)
            @if (($filters[$k] ?? '') !== '' && $filters[$k] !== null)
                <input type="hidden" name="{{ $k }}" value="{{ $filters[$k] }}">
            @endif
        @endforeach
        <label class="fl wide"><span>{{ __('ui.l_rep') }}</span>
            <select name="rep_id" id="rbRep" required>
                <option value="">{{ __('ui.choose', ['x' => __('ui.l_rep')]) }}</option>
                @foreach ($reps as $r)
                    <option value="{{ $r->id }}" @selected($selRep === $r->id)>{{ $r->displayName() }} — {{ $r->roleLabel() }}</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('journey.rb_day') }}</span>
            <select name="weekday" id="rbDay" required>
                <option value="">—</option>
                @foreach ([6, 0, 1, 2, 3, 4, 5] as $d)
                    <option value="{{ $d }}" @selected($selDay === $d)>{{ __('journey.day_'.$d) }}</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('journey.rb_freq_new') }}</span>
            <select name="every_weeks">
                @foreach (\App\Models\JourneyPlan::FREQUENCIES as $fq)
                    <option value="{{ $fq }}">{{ __('journey.freq_'.$fq) }}</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('journey.rb_first') }}</span>
            <input type="date" name="starts_on" id="rbFirst"></label>
        <label class="fl"><span>{{ __('journey.rb_time') }}</span>
            <input type="time" name="visit_at" style="width:110px"></label>
        <div id="rbIds"></div>
        <span class="badge b-blue" style="font-size:12px;align-self:center">
            {{ __('journey.rb_selected') }}: <b id="rbCount">0</b></span>
        <button class="btn sm" type="button" id="rbNearBtn">🧭 {{ __('journey.rb_nearest') }}</button>
        <button class="btn sm" type="button" id="rbClearBtn">✕ {{ __('journey.rb_unselect') }}</button>
        <button class="btn gold sm" type="submit" id="rbSaveBtn">✅ {{ __('journey.rb_save') }}</button>
    </form>
    <div class="dash-hint" style="margin-top:8px">{{ __('journey.rb_hint') }}</div>
    {{-- التسلسل المختار — شيبس بالترتيب و✕ لكل واحد --}}
    <div id="rbSeq" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px"></div>
</div>

<div class="card">
    <div class="dash-hint" style="margin-bottom:6px">{{ __('journey.rb_legend_hint') }}</div>
    <div id="rbLegend" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px"></div>
    <div id="rbMap" style="height:64vh;border-radius:12px;border:1px solid var(--border)"></div>
</div>

{{-- ═══ عملاء من غير لوكيشن — ينفع يتضافوا للتسلسل ═══ --}}
<div class="card" id="rbNoLocCard" style="margin-top:12px" hidden>
    <h3 style="margin:0 0 8px">📍✕ {{ __('journey.rb_noloc_list') }} <span class="badge b-gray" id="rbNoLocN"></span></h3>
    <table id="rbNoLoc">
        <thead><tr>
            <th>{{ __('journey.col_code') }}</th><th>{{ __('journey.col_client') }}</th>
            <th>{{ __('journey.col_zone') }}</th><th>{{ __('journey.f_owner') }}</th>
            <th class="act"></th>
        </tr></thead>
        <tbody></tbody>
    </table>
</div>

@endsection

@section('scripts')
@php
    $jsFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP;
    $dayNames = collect(\App\Models\JourneyPlan::WEEKDAYS)->mapWithKeys(fn ($d) => [$d => __('journey.day_'.$d)]);
    $jsT = [
        'pick' => __('journey.rb_pick'),
        'clear' => __('journey.rb_clear_confirm'),
        'dirty' => __('journey.rb_dirty_switch'),
        'locked' => __('journey.rb_locked'),
        'last' => __('journey.tt_last'),
        'never' => __('journey.tt_never'),
        'owes' => __('journey.tt_owes'),
        'days' => __('journey.tt_days'),
        'norep' => __('journey.no_rep'),
        'add' => __('journey.rb_add'),
        'f2' => __('journey.freq_2'),
        'f4' => __('journey.freq_4'),
    ];
@endphp
<style>
.rb-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin-bottom:12px}
@media(max-width:800px){.rb-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
.rb-kpi{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:8px 12px;
    display:flex;align-items:baseline;gap:8px;color:var(--ink);text-decoration:none}
.rb-kpi b{font-size:17px;color:var(--royal-blue,#12399B)}
.rb-kpi span{font-size:11px;color:var(--muted)}
.rb-kpi.warn b{color:#B45309}
a.rb-kpi:hover{border-color:var(--royal-blue,#12399B)}
.rb-bar{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin:0;
    background:var(--blue-050,#E8F1FF);border:1px solid var(--royal-blue,#12399B);border-radius:12px;padding:10px 12px}
#rbSaveBtn.pulsing{box-shadow:0 0 0 3px rgba(230,167,0,.4)}
</style>
<script>
(function () {
    'use strict';

    var ROWS = {!! json_encode($rows, $jsFlags) !!};
    var DAYMAP = {!! json_encode((object) $dayMap, $jsFlags) !!};
    var DAY_NAMES = {!! json_encode($dayNames, $jsFlags) !!};
    var T = {!! json_encode($jsT, $jsFlags) !!};

    /* ═══ ألوان هادية لكل قناة — بترتيب حجمها (زي أقسام المحتملين) ═══ */
    var PALETTE = ['#5B8DEF', '#67B99A', '#C9915B', '#9B7EDE', '#E28BA6',
        '#5FB6C9', '#B5A642', '#8A9BAE', '#D98E73', '#7FA86F', '#C97FB8', '#7C8AE0'];
    var counts = {};
    ROWS.forEach(function (c) { if (!c.extra) { var k = c.channel || '—'; counts[k] = (counts[k] || 0) + 1; } });
    var CHANS = Object.keys(counts).sort(function (a, b) { return counts[b] - counts[a]; });
    var COLOR = {};
    CHANS.forEach(function (c, i) { COLOR[c] = PALETTE[i % PALETTE.length]; });
    function chColor(c) { return COLOR[c || '—'] || '#9CA3AF'; }

    var byId = {};
    ROWS.forEach(function (c) { byId[c.id] = c; });
    function hasXY(c) { return c && c.lat != null && c.lng != null; }

    var sel = [];          // ids بالترتيب
    var loaded = '[]';     // صورة اليوم وقت التحميل — للـ«فيه تعديل»
    var chSel = null;      // فلتر الليجند
    var markers = {};

    var repEl = document.getElementById('rbRep');
    var dayEl = document.getElementById('rbDay');
    var firstEl = document.getElementById('rbFirst');
    var curRep = repEl.value, curDay = dayEl.value;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    }
    function tt(k, v) { var s = T[k] || k; Object.keys(v || {}).forEach(function (x) { s = s.split(':' + x).join(v[x]); }); return s; }
    function fmt(n) { return Number(n).toLocaleString('en-US', { maximumFractionDigits: 0 }); }

    var map = L.map('rbMap', { scrollWheelZoom: true });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19, attribution: '&copy; OpenStreetMap',
    }).addTo(map);
    var lineLayer = L.layerGroup().addTo(map);

    function dotIcon(color, locked) {
        return L.divIcon({
            className: '', iconSize: [16, 16], iconAnchor: [8, 8],
            html: '<div style="width:16px;height:16px;border-radius:50%;background:' + color + ';'
                + 'border:2.5px solid ' + (locked ? '#7C3AED' : '#fff') + ';box-shadow:0 1px 4px rgba(0,0,0,.35)"></div>',
        });
    }
    function numIcon(n) {
        return L.divIcon({
            className: '', iconSize: [30, 30], iconAnchor: [15, 15],
            html: '<div style="width:30px;height:30px;border-radius:50%;background:#12399B;'
                + 'border:3px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.4);color:#fff;'
                + 'font:900 13px Cairo,Inter,sans-serif;display:flex;align-items:center;'
                + 'justify-content:center">' + n + '</div>',
        });
    }

    // عميل على خط مندوب تاني في اليوم المختار؟
    function lockedBy(c) {
        if (curDay === '') return null;
        var p = (c.plans || []).find(function (p) { return p.d === +curDay && String(p.u) !== String(curRep); });
        return p ? p.n : null;
    }

    function tip(c) {
        var days = (c.plans || []).map(function (p) {
            return DAY_NAMES[p.d] + (p.f === 2 ? ' (' + T.f2 + ')' : p.f === 4 ? ' (' + T.f4 + ')' : '') + ' · ' + esc(p.n);
        });
        var lk = lockedBy(c);
        return '<b>' + esc(c.name) + '</b> <span style="color:#888">' + esc(c.code) + '</span>'
            + (c.chain ? '<br>🔗 ' + esc(c.chain) : '')
            + (c.channel ? '<br>🏷 ' + esc(c.channel) : '')
            + (c.zone ? '<br>📍 ' + esc(c.zone) : '')
            + '<br>👤 ' + esc(c.rep || T.norep)
            + (days.length ? '<br>📅 ' + T.days + ': ' + days.join('، ') : '')
            + '<br>🕒 ' + (c.last_days == null ? T.never : tt('last', { n: c.last_days }))
            + (c.balance > 0 ? '<br>💰 ' + tt('owes', { n: fmt(c.balance) }) : '')
            + (lk ? '<br><b style="color:#7C3AED">🔒 ' + T.locked + ': ' + esc(lk) + '</b>' : '');
    }

    function isDirty() { return JSON.stringify(sel) !== loaded; }

    // ضغطة = ضيف آخر التسلسل، وضغطة تانية = شيل
    function toggle(id) {
        if (repEl.value === '' || dayEl.value === '') { alert(T.pick); return; }
        var i = sel.indexOf(id);
        if (i === -1) sel.push(id); else sel.splice(i, 1);
        refresh();
    }

    function refresh() {
        var shown = 0;
        ROWS.forEach(function (c) {
            if (!hasXY(c)) return;
            var m = markers[c.id];
            var i = sel.indexOf(c.id);
            var visible = i !== -1 || (!c.extra && (chSel === null || (c.channel || '—') === chSel));
            if (visible && !map.hasLayer(m)) m.addTo(map);
            if (!visible && map.hasLayer(m)) map.removeLayer(m);
            if (visible) shown++;
            m.setIcon(i === -1 ? dotIcon(chColor(c.channel), !!lockedBy(c)) : numIcon(i + 1));
            m.setZIndexOffset(i === -1 ? 0 : 500);
            m.setTooltipContent(tip(c));
        });
        document.getElementById('rbMapCount').textContent = shown + ' 📍';

        lineLayer.clearLayers();
        var pts = sel.map(function (id) { return byId[id]; }).filter(hasXY)
            .map(function (c) { return [c.lat, c.lng]; });
        if (pts.length > 1) {
            lineLayer.addLayer(L.polyline(pts, { color: '#12399B', weight: 3.5, dashArray: '8 8', opacity: .9 }));
        }

        document.getElementById('rbCount').textContent = sel.length;
        var seq = document.getElementById('rbSeq');
        seq.innerHTML = '';
        sel.forEach(function (id, i) {
            var c = byId[id];
            var chip = document.createElement('span');
            chip.className = 'badge ' + (hasXY(c) ? 'b-blue' : 'b-gray');
            chip.style.cssText = 'font-size:11px;display:inline-flex;align-items:center;gap:5px;padding:4px 9px';
            chip.innerHTML = '<b>' + (i + 1) + '</b> ' + esc(c ? c.name : '#' + id) + (hasXY(c) ? '' : ' 📍✕')
                + ' <span style="cursor:pointer;font-weight:900" data-id="' + id + '">✕</span>';
            chip.querySelector('[data-id]').addEventListener('click', function () { toggle(id); });
            seq.appendChild(chip);
        });

        document.getElementById('rbSaveBtn').classList.toggle('pulsing', isDirty());
        renderNoLoc();
    }

    // ═══ تحميل يوم (مندوب × يوم) — خط اليوم الحالي مترقّم ═══
    function loadDay() {
        var ids = (DAYMAP[repEl.value] && DAYMAP[repEl.value][dayEl.value]) || [];
        sel = ids.filter(function (id) { return byId[id]; });
        loaded = JSON.stringify(sel);
        curRep = repEl.value; curDay = dayEl.value;
        document.getElementById('rbFRep').value = curRep;
        document.getElementById('rbFDay').value = curDay;
        refresh();
        var pts = sel.map(function (id) { return byId[id]; }).filter(hasXY).map(function (c) { return [c.lat, c.lng]; });
        if (pts.length > 1) map.fitBounds(L.latLngBounds(pts).pad(0.25));
        else if (pts.length === 1) map.setView(pts[0], 14);
    }

    function onSwitch(el, prev) {
        if (isDirty() && sel.length && !confirm(T.dirty)) { el.value = prev; return; }
        loadDay();
    }
    repEl.addEventListener('change', function () { onSwitch(repEl, curRep); });
    dayEl.addEventListener('change', function () { onSwitch(dayEl, curDay); });

    // أول زيارة بتحدد اليوم وبتقفله (السيرفر بيشتقه تاني)
    firstEl.addEventListener('change', function () {
        if (!firstEl.value) { dayEl.disabled = false; return; }
        var d = new Date(firstEl.value + 'T00:00:00').getDay();
        if (String(d) !== dayEl.value) {
            var prev = dayEl.value;
            dayEl.value = String(d);
            onSwitch(dayEl, prev);
        }
    });

    /* ═══ ليجند القنوات — فلتر حصري ═══ */
    var legend = document.getElementById('rbLegend');
    CHANS.forEach(function (c) {
        var chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'btn sm';
        chip.style.cssText = 'display:inline-flex;align-items:center;gap:6px';
        chip.innerHTML = '<span style="width:11px;height:11px;border-radius:50%;display:inline-block;'
            + 'background:' + chColor(c) + ';border:1.5px solid rgba(0,0,0,.15)"></span>'
            + esc(c) + ' <span class="badge b-gray" style="font-size:9.5px">' + counts[c] + '</span>';
        chip.addEventListener('click', function () {
            chSel = chSel === c ? null : c;
            Array.prototype.forEach.call(legend.children, function (el) {
                el.style.outline = ''; el.style.opacity = chSel === null ? '' : '.45';
            });
            if (chSel !== null) { chip.style.outline = '2.5px solid ' + chColor(c); chip.style.opacity = ''; }
            refresh();
        });
        legend.appendChild(chip);
    });

    // النقط
    var bounds = [];
    ROWS.forEach(function (c) {
        if (!hasXY(c)) return;
        var m = L.marker([c.lat, c.lng], { icon: dotIcon(chColor(c.channel), false) });
        m.bindTooltip(tip(c), { direction: 'top', offset: [0, -10] });
        m.on('click', function () { toggle(c.id); });
        markers[c.id] = m;
        if (!c.extra) bounds.push([c.lat, c.lng]);
    });
    if (bounds.length === 1) map.setView(bounds[0], 14);
    else if (bounds.length) map.fitBounds(L.latLngBounds(bounds).pad(0.12));
    else map.setView([30.05, 31.4], 11);

    // ═══ عملاء من غير لوكيشن ═══
    function renderNoLoc() {
        var list = ROWS.filter(function (c) { return !hasXY(c) && !c.extra; });
        var card = document.getElementById('rbNoLocCard');
        card.hidden = !list.length;
        document.getElementById('rbNoLocN').textContent = list.length;
        var tb = document.querySelector('#rbNoLoc tbody');
        tb.innerHTML = '';
        list.forEach(function (c) {
            var on = sel.indexOf(c.id) !== -1;
            var tr = document.createElement('tr');
            tr.innerHTML = '<td>' + esc(c.code) + '</td><td>' + esc(c.name) + '</td><td>' + esc(c.zone || '—')
                + '</td><td>' + esc(c.rep || T.norep) + '</td><td class="act"><button type="button" class="btn sm">'
                + (on ? '✕' : '＋ ' + T.add) + '</button></td>';
            tr.querySelector('button').addEventListener('click', function () { toggle(c.id); });
            tb.appendChild(tr);
        });
    }

    // «رتب بالأقرب» — أول مختار ثابت والباقي سلسلة أقرب-فالأقرب
    // (من غير لوكيشن بيتحطوا في الآخر بنفس ترتيبهم)
    document.getElementById('rbNearBtn').addEventListener('click', function () {
        var xy = sel.filter(function (id) { return hasXY(byId[id]); });
        var noxy = sel.filter(function (id) { return !hasXY(byId[id]); });
        if (xy.length < 3) return;
        var chain = [xy[0]];
        var pool = xy.slice(1);
        while (pool.length) {
            var last = byId[chain[chain.length - 1]];
            var bi = 0, bd = Infinity;
            pool.forEach(function (id, i) {
                var l = byId[id];
                var dy = (last.lat - l.lat) * 111320;
                var dx = (last.lng - l.lng) * 111320 * Math.cos(last.lat * Math.PI / 180);
                var d = dx * dx + dy * dy;
                if (d < bd) { bd = d; bi = i; }
            });
            chain.push(pool.splice(bi, 1)[0]);
        }
        sel = chain.concat(noxy);
        refresh();
    });

    document.getElementById('rbClearBtn').addEventListener('click', function () { sel = []; refresh(); });

    window.addEventListener('beforeunload', function (e) {
        if (isDirty() && !window.__rbSubmitting) { e.preventDefault(); e.returnValue = ''; }
    });

    window.rbSubmit = function (form) {
        if (repEl.value === '' || dayEl.value === '') { alert(T.pick); return false; }
        if (!sel.length && !confirm(T.clear)) return false;
        dayEl.disabled = false;
        var box = document.getElementById('rbIds');
        box.innerHTML = '';
        sel.forEach(function (id) {
            var inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = 'ids[]'; inp.value = id;
            box.appendChild(inp);
        });
        if (!sel.length) {
            var e = document.createElement('input');
            e.type = 'hidden'; e.name = 'ids'; e.value = '';
            box.appendChild(e);
        }
        window.__rbSubmitting = true;
        return true;
    };

    loadDay();
})();
</script>
@endsection
