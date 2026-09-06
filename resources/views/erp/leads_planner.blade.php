@extends('layouts.system')

{{--
    جدولة أسبوع المحتملين (سكشن المحتملين ٢٦/٨):

    مندوب + أسبوع → بورد ٧ أيام (السبت للجمعة). ليداته الغير مجدولة
    في بانل جنبية بالسكور — تضغط على اليوم المستهدف وهي «مسلّحة»
    فتنزل فيه، أو ✕ من جوه اليوم ترجعها. كله في الذاكرة وحفظة
    واحدة بتزامن الأسبوع كله (POST days JSON).
--}}

@section('title', __('lead.planner_title'))

@section('actions')
    <a class="btn" href="{{ route('erp.leads') }}">← {{ __('lead.page') }}</a>
    <a class="btn" href="{{ route('erp.leads.week', ['week' => $start->toDateString()]) }}">
        👁 {{ __('lead.week_title') }}</a>
@endsection

@section('content')

{{-- ═══ المندوب والأسبوع ═══ --}}
<div class="card" style="margin-bottom:14px;padding:12px 16px">
    <form method="GET" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
        <div style="flex:0 1 260px">
            <label class="f">{{ __('ops.rep') }}</label>
            <select name="rep" onchange="this.form.submit()">
                @foreach ($reps as $r)
                    <option value="{{ $r->id }}" @selected($repId === $r->id)>{{ $r->name }} ({{ $r->code }})</option>
                @endforeach
            </select>
        </div>
        <div style="flex:0 1 190px">
            <label class="f">{{ __('lead.week_of') }}</label>
            <input type="date" name="week" value="{{ $start->toDateString() }}" onchange="this.form.submit()">
        </div>
        <a class="btn" href="{{ route('erp.leads.planner', ['rep' => $repId, 'week' => $start->copy()->subWeek()->toDateString()]) }}">→</a>
        <a class="btn" href="{{ route('erp.leads.planner', ['rep' => $repId, 'week' => $start->copy()->addWeek()->toDateString()]) }}">←</a>
    </form>
</div>

<div style="display:flex;gap:14px;align-items:flex-start;flex-wrap:wrap">
    {{-- ═══ بانل الغير مجدولين ═══ --}}
    <div class="card" style="flex:0 1 300px;min-width:260px">
        <h3 style="margin:0 0 8px">🎯 {{ __('lead.pool_title') }}
            <span class="badge b-blue" id="lpPoolCount">{{ $pool->count() }}</span></h3>
        <input type="search" id="lpSearch" placeholder="🔎 {{ __('common.search') }}"
               style="width:100%;margin-bottom:8px">
        <div class="dash-hint" style="margin-bottom:8px">{{ __('lead.pool_hint') }}</div>
        <div id="lpPool" style="max-height:60vh;overflow-y:auto;display:flex;flex-direction:column;gap:6px"></div>
    </div>

    {{-- ═══ بورد الأسبوع ═══ --}}
    <div style="flex:1;min-width:520px">
        <div class="lp-week" id="lpWeek">
            @foreach ($days as $d)
                <div class="lp-day @if($d->isToday()) today @endif" data-date="{{ $d->toDateString() }}">
                    <div class="lp-day-h">
                        <b>{{ __('lead.wd_'.$d->dayOfWeek) }}</b>
                        <span dir="ltr">{{ $d->format('d/m') }}</span>
                        <span class="badge b-gray lp-day-n">0</span>
                    </div>
                    <div class="lp-day-body"></div>
                </div>
            @endforeach
        </div>

        <form method="POST" action="{{ route('erp.leads.planner.save') }}" style="margin-top:12px"
              onsubmit="document.getElementById('lpDays').value = lpSerialize();">
            @csrf
            <input type="hidden" name="rep_id" value="{{ $repId }}">
            <input type="hidden" name="week" value="{{ $start->toDateString() }}">
            <input type="hidden" name="days" id="lpDays" value="">
            <button class="btn gold" type="submit">💾 {{ __('lead.plan_save_btn') }}</button>
            <span class="dash-hint" style="margin-inline-start:10px">{{ __('lead.plan_save_hint') }}</span>
        </form>
    </div>
</div>

{{-- ═══ خريطة خط السير 2D (٦/٩ — طلب المالك): اختار اليوم وشوف
     ليداته أرقام ١ ٢ ٣ متوصلين بخط — الرمادي غير مجدول (اضغطه من
     البوب أب يتضاف لليوم)، والمرقّم بيتشال من البوب أب برضو،
     وزرار «رتب بالأقرب» بيعيد ترتيب اليوم سلسلة أقرب-فالأقرب ═══ --}}
<div class="card" style="margin-top:14px">
    <h3 style="margin:0 0 10px">🗺️ {{ __('lead.route_map') }}
        <span class="side">{{ __('lead.route_map_hint') }}</span></h3>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:10px">
        <label class="f" style="margin:0">{{ __('lead.route_day') }}</label>
        <select id="lpMapDay">
            @foreach ($days as $d)
                <option value="{{ $d->toDateString() }}" @selected($d->isToday())>
                    {{ __('lead.wd_'.$d->dayOfWeek) }} {{ $d->format('d/m') }}</option>
            @endforeach
        </select>
        <button class="btn sm" type="button" id="lpNearBtn">🧭 {{ __('lead.route_nearest') }}</button>
    </div>
    <div id="lpMap" style="height:440px;border-radius:12px;border:1px solid var(--border)"></div>
</div>

@endsection

@section('scripts')
<style>
.lp-week{display:grid;grid-template-columns:repeat(7,1fr);gap:8px}
.lp-day{background:var(--card2,#F7F7FA);border:1px solid var(--border);border-radius:12px;
    padding:8px;min-height:200px;cursor:pointer;transition:.12s}
.lp-day.today{border-color:var(--royal-blue,#12399B);background:#F2F6FF}
.lp-day.armed{border-color:#0F7A38;box-shadow:0 0 0 3px rgba(15,122,56,.15)}
.lp-day-h{display:flex;gap:5px;align-items:center;font-size:11px;margin-bottom:8px;flex-wrap:wrap}
.lp-card{background:#fff;border:1px solid var(--border);border-radius:9px;padding:6px 8px;
    margin-bottom:6px;font-size:11px;position:relative}
.lp-card .x{position:absolute;top:2px;inset-inline-end:4px;cursor:pointer;color:var(--red,#DC2626);
    font-weight:900;font-size:12px}
.lp-pool-card{background:#fff;border:1.5px solid var(--border);border-radius:10px;padding:8px 10px;
    cursor:pointer;font-size:12px;transition:.12s}
.lp-pool-card:hover{border-color:var(--royal-blue,#12399B)}
.lp-pool-card.armed{border-color:#0F7A38;background:#EFFAF3;box-shadow:0 0 0 2px rgba(15,122,56,.2)}
@media (max-width:1100px){.lp-week{grid-template-columns:repeat(4,1fr)}}
</style>
@php
    // خامة البورد — الليدات الغير مجدولة + المجدولة بأيامها
    $lpPool = $pool->map(fn ($l) => [
        'id' => $l->id, 'name' => $l->displayName(),
        'zone' => $l->zone?->displayName() ?? '', 'score' => (int) $l->score,
        'lat' => $l->lat !== null ? (float) $l->lat : null,
        'lng' => $l->lng !== null ? (float) $l->lng : null,
    ])->values();
    $lpPlanned = $plans->map(fn ($g) => $g->map(fn ($p) => [
        'id' => $p->lead_id, 'name' => $p->lead?->displayName() ?? '—',
        'zone' => $p->lead?->zone?->displayName() ?? '', 'score' => (int) ($p->lead?->score ?? 0),
        'lat' => $p->lead?->lat !== null ? (float) $p->lead->lat : null,
        'lng' => $p->lead?->lng !== null ? (float) $p->lead->lng : null,
    ])->values());
@endphp
<script>
/* ═══ بورد الجدولة — كله في الذاكرة وحفظة واحدة ═══
   الفلو: تدوس ليد في البانل (بيتسلّح أخضر) ← تدوس اليوم ← بينزل
   فيه. ✕ على كارت اليوم بيرجّعه للبانل. */
(function () {
    'use strict';

    var POOL = {!! json_encode($lpPool, JSON_UNESCAPED_UNICODE) !!};
    var PLANNED = {!! json_encode($lpPlanned, JSON_UNESCAPED_UNICODE) !!};
    var armed = null;   // الليد المتسلّح المستني يوم

    var poolBox = document.getElementById('lpPool');
    var poolCount = document.getElementById('lpPoolCount');
    var search = document.getElementById('lpSearch');
    // خريطة اليوم ← ليست ليداته (بالترتيب)
    var days = {};
    document.querySelectorAll('.lp-day').forEach(function (d) {
        days[d.dataset.date] = PLANNED[d.dataset.date]
            ? PLANNED[d.dataset.date].slice() : [];
    });

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    }

    function renderPool() {
        var q = search.value.trim().toLowerCase();
        poolBox.innerHTML = '';
        var shown = 0;
        POOL.forEach(function (l) {
            if (q && (l.name + ' ' + l.zone).toLowerCase().indexOf(q) === -1) return;
            shown++;
            var el = document.createElement('div');
            el.className = 'lp-pool-card' + (armed === l.id ? ' armed' : '');
            el.innerHTML = '<b>' + esc(l.name) + '</b>'
                + '<div style="font-size:10px;color:var(--muted)">📍 ' + esc(l.zone)
                + ' · ⚡ ' + l.score + '</div>';
            el.addEventListener('click', function () {
                armed = armed === l.id ? null : l.id;
                renderPool();
                document.querySelectorAll('.lp-day').forEach(function (d) {
                    d.classList.toggle('armed', armed !== null);
                });
            });
            poolBox.appendChild(el);
        });
        poolCount.textContent = POOL.length;
        if (!shown) poolBox.innerHTML = '<div style="font-size:11px;color:var(--muted);padding:10px;text-align:center">—</div>';
    }

    function renderDays() {
        document.querySelectorAll('.lp-day').forEach(function (d) {
            var list = days[d.dataset.date] || [];
            var body = d.querySelector('.lp-day-body');
            body.innerHTML = '';
            list.forEach(function (l, i) {
                var c = document.createElement('div');
                c.className = 'lp-card';
                c.innerHTML = '<b>' + (i + 1) + '.</b> ' + esc(l.name)
                    + '<div style="font-size:9.5px;color:var(--muted)">📍 ' + esc(l.zone) + '</div>'
                    + '<span class="x" data-id="' + l.id + '">✕</span>';
                c.querySelector('.x').addEventListener('click', function (e) {
                    e.stopPropagation();
                    days[d.dataset.date] = list.filter(function (x) { return x.id !== l.id; });
                    POOL.unshift(l);
                    renderDays(); renderPool();
                });
                body.appendChild(c);
            });
            d.querySelector('.lp-day-n').textContent = list.length;
        });

        // الخريطة مرآة البورد (٦/٩) — أي تغيير هنا يبان هناك فوراً
        mapRender();
    }

    document.querySelectorAll('.lp-day').forEach(function (d) {
        d.addEventListener('click', function () {
            if (armed === null) return;
            var idx = POOL.findIndex(function (l) { return l.id === armed; });
            if (idx === -1) return;
            days[d.dataset.date].push(POOL[idx]);
            POOL.splice(idx, 1);
            armed = null;
            document.querySelectorAll('.lp-day').forEach(function (x) { x.classList.remove('armed'); });
            renderDays(); renderPool();
        });
    });

    search.addEventListener('input', renderPool);

    /* ═══ خريطة خط السير 2D (٦/٩) — جوه نفس الكلوجر عشان تشارك
       days و POOL مع البورد: أي تعديل هنا بيبان هناك والعكس ═══ */
    var NEAR_ADD = @js(__('lead.route_add'));
    var NEAR_REMOVE = @js(__('lead.route_remove'));
    var mapEl = document.getElementById('lpMap');
    var daySel = document.getElementById('lpMapDay');
    var lpMap = null;
    var routeLayer = null;
    var selDate = daySel ? daySel.value : null;
    var didFit = false;

    function hasXY(l) { return l.lat != null && l.lng != null; }

    // مسافة تقريبية بالمتر — كفاية للمقارنة النسبية بين النقط
    function dist(a, b) {
        var dy = (a.lat - b.lat) * 111320;
        var dx = (a.lng - b.lng) * 111320 * Math.cos(a.lat * Math.PI / 180);
        return Math.sqrt(dx * dx + dy * dy);
    }

    function numIcon(n) {
        return L.divIcon({
            className: '', iconSize: [28, 28], iconAnchor: [14, 14], popupAnchor: [0, -14],
            html: '<div style="width:28px;height:28px;border-radius:50%;background:#12399B;'
                + 'border:2.5px solid #fff;box-shadow:0 2px 5px rgba(0,0,0,.35);color:#fff;'
                + 'font:900 12px Cairo,Inter,sans-serif;display:flex;align-items:center;'
                + 'justify-content:center">' + n + '</div>',
        });
    }

    function dotIcon() {
        return L.divIcon({
            className: '', iconSize: [14, 14], iconAnchor: [7, 7], popupAnchor: [0, -8],
            html: '<div style="width:14px;height:14px;border-radius:50%;background:#9CA3AF;'
                + 'border:2px solid #fff;box-shadow:0 1px 3px rgba(0,0,0,.3)"></div>',
        });
    }

    function mapRender() {
        if (!lpMap) return;
        routeLayer.clearLayers();

        var pts = [];
        (days[selDate] || []).forEach(function (l, i) {
            if (!hasXY(l)) return;
            var m = L.marker([l.lat, l.lng], { icon: numIcon(i + 1), zIndexOffset: 500 });
            m.bindPopup('<div style="font:700 12.5px Cairo,Inter,sans-serif;text-align:start">'
                + (i + 1) + '. ' + esc(l.name)
                + '<br><button class="btn sm red" style="margin-top:6px" '
                + 'onclick="lpMapRemove(' + l.id + ')">✕ ' + esc(NEAR_REMOVE) + '</button></div>');
            routeLayer.addLayer(m);
            pts.push([l.lat, l.lng]);
        });

        if (pts.length > 1) {
            routeLayer.addLayer(L.polyline(pts, { color: '#12399B', weight: 3, dashArray: '7 7', opacity: .85 }));
        }

        POOL.forEach(function (l) {
            if (!hasXY(l)) return;
            var m = L.marker([l.lat, l.lng], { icon: dotIcon() });
            m.bindPopup('<div style="font:700 12.5px Cairo,Inter,sans-serif;text-align:start">'
                + esc(l.name)
                + '<div style="font-weight:400;font-size:10.5px;color:#6B7280">📍 ' + esc(l.zone) + '</div>'
                + '<button class="btn sm green" style="margin-top:6px" '
                + 'onclick="lpMapAdd(' + l.id + ')">➕ ' + esc(NEAR_ADD) + '</button></div>');
            routeLayer.addLayer(m);
        });

        if (!didFit) {
            var all = pts.slice();
            POOL.forEach(function (l) { if (hasXY(l)) all.push([l.lat, l.lng]); });
            if (all.length) { lpMap.fitBounds(L.latLngBounds(all).pad(0.15)); didFit = true; }
        }
    }
    // البورد بينده mapRender بعد أي تغيير — نخليها متاحة للكل جوه الكلوجر
    window.lpMapAdd = function (id) {
        var i = POOL.findIndex(function (l) { return l.id === id; });
        if (i === -1 || !selDate) return;
        (days[selDate] = days[selDate] || []).push(POOL[i]);
        POOL.splice(i, 1);
        renderDays(); renderPool(); mapRender();
    };

    window.lpMapRemove = function (id) {
        var list = days[selDate] || [];
        var i = list.findIndex(function (l) { return l.id === id; });
        if (i === -1) return;
        POOL.unshift(list[i]);
        list.splice(i, 1);
        renderDays(); renderPool(); mapRender();
    };

    if (mapEl && daySel) {
        lpMap = L.map('lpMap', { scrollWheelZoom: false });
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19, attribution: '&copy; OpenStreetMap',
        }).addTo(lpMap);
        routeLayer = L.layerGroup().addTo(lpMap);
        lpMap.on('click', function () { lpMap.scrollWheelZoom.enable(); });
        lpMap.on('mouseout', function () { lpMap.scrollWheelZoom.disable(); });

        daySel.addEventListener('change', function () {
            selDate = daySel.value;
            mapRender();
        });

        // «رتب بالأقرب» — سلسلة greedy من أول نقطة في اليوم
        document.getElementById('lpNearBtn').addEventListener('click', function () {
            var list = days[selDate] || [];
            var located = list.filter(hasXY);
            var rest = list.filter(function (l) { return !hasXY(l); });
            if (located.length < 3) return;

            var chain = [located[0]];
            var pool = located.slice(1);
            while (pool.length) {
                var last = chain[chain.length - 1];
                var bi = 0, bd = Infinity;
                pool.forEach(function (l, i) {
                    var d = dist(last, l);
                    if (d < bd) { bd = d; bi = i; }
                });
                chain.push(pool.splice(bi, 1)[0]);
            }
            days[selDate] = chain.concat(rest);
            renderDays(); mapRender();
        });

        mapRender();
    }

    // بيتنده وقت السبمت — بيرجّع JSON {تاريخ: [ids بالترتيب]}
    window.lpSerialize = function () {
        var out = {};
        Object.keys(days).forEach(function (date) {
            out[date] = days[date].map(function (l) { return l.id; });
        });
        return JSON.stringify(out);
    };

    renderPool();
    renderDays();
})();
</script>
@endsection
