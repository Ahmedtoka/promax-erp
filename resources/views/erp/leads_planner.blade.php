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
                        {{-- بوب أب خط سير اليوم (٦/٩) — عرض بس --}}
                        <span class="lp-day-map" title="{{ __('lead.route_map') }}"
                              data-d="{{ $d->toDateString() }}"
                              data-t="{{ __('lead.wd_'.$d->dayOfWeek) }} {{ $d->format('d/m') }}">🗺</span>
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

{{-- ═══ بوب أب خط سير اليوم (٦/٩ — اتغيّر): الخريطة الثابتة اتشالت،
     وبدلها زرار 🗺 على هيدر كل يوم بيفتح دايالوج فيه الرسمة
     النهائية لليوم — أرقام ١ ٢ ٣ متوصلين بخط + ليستة المحطات.
     الرسم نفسه بيتعمل من صفحة «راسم خط السير». ═══ --}}
<dialog id="dlgDayMap" class="wide">
    <div class="dlg">
        <h4 style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
            🗺️ <span id="dmTitle"></span>
            <span class="badge b-blue" id="dmCount"></span>
        </h4>
        <div id="dmMap" style="height:52vh;border-radius:12px;border:1px solid var(--border)"></div>
        <div id="dmList" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px"></div>
        <div style="display:flex;justify-content:flex-end;margin-top:12px">
            <button class="btn" type="button" onclick="closeDlg('dlgDayMap')">{{ __('common.close') }}</button>
        </div>
    </div>
</dialog>

@endsection

@section('scripts')
<style>
.lp-week{display:grid;grid-template-columns:repeat(7,1fr);gap:8px}
.lp-day{background:var(--card2,#F7F7FA);border:1px solid var(--border);border-radius:12px;
    padding:8px;min-height:200px;cursor:pointer;transition:.12s}
.lp-day.today{border-color:var(--royal-blue,#12399B);background:#F2F6FF}
.lp-day.armed{border-color:#0F7A38;box-shadow:0 0 0 3px rgba(15,122,56,.15)}
.lp-day-h{display:flex;gap:5px;align-items:center;font-size:11px;margin-bottom:8px;flex-wrap:wrap}
.lp-day-map{cursor:pointer;font-size:14px;margin-inline-start:auto;opacity:.75;transition:.12s}
.lp-day-map:hover{opacity:1;transform:scale(1.15)}
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

    /* ═══ بوب أب خط سير اليوم (٦/٩) — عرض بس: زرار 🗺 على هيدر
       اليوم بيفتح دايالوج فيه الأرقام ١ ٢ ٣ والخط والليستة.
       الرسم والتعديل من صفحة «راسم خط السير». ═══ */
    var dmMap = null;
    var dmLayer = null;

    function hasXY(l) { return l.lat != null && l.lng != null; }

    function numIcon(n) {
        return L.divIcon({
            className: '', iconSize: [28, 28], iconAnchor: [14, 14],
            html: '<div style="width:28px;height:28px;border-radius:50%;background:#12399B;'
                + 'border:2.5px solid #fff;box-shadow:0 2px 5px rgba(0,0,0,.35);color:#fff;'
                + 'font:900 12px Cairo,Inter,sans-serif;display:flex;align-items:center;'
                + 'justify-content:center">' + n + '</div>',
        });
    }

    function dayMapOpen(date, title) {
        var list = days[date] || [];
        document.getElementById('dmTitle').textContent = title;
        document.getElementById('dmCount').textContent = list.length;

        // الليستة — المحطات بالترتيب
        var box = document.getElementById('dmList');
        box.innerHTML = '';
        list.forEach(function (l, i) {
            var chip = document.createElement('span');
            chip.className = 'badge b-blue';
            chip.style.cssText = 'font-size:11px;padding:5px 10px';
            chip.innerHTML = '<b>' + (i + 1) + '</b> ' + esc(l.name);
            box.appendChild(chip);
        });

        openDlg('dlgDayMap');

        // ⚠️ Leaflet جوه dialog: الإنشاء أول مرة بس + invalidateSize
        // بعد ما الدايالوج يبان، وإلا الخريطة بترندر رمادي
        if (dmMap === null) {
            dmMap = L.map('dmMap', { scrollWheelZoom: false });
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19, attribution: '&copy; OpenStreetMap',
            }).addTo(dmMap);
            dmLayer = L.layerGroup().addTo(dmMap);
            dmMap.on('click', function () { dmMap.scrollWheelZoom.enable(); });
            dmMap.on('mouseout', function () { dmMap.scrollWheelZoom.disable(); });
        }

        dmLayer.clearLayers();
        var pts = [];
        list.forEach(function (l, i) {
            if (!hasXY(l)) return;
            var m = L.marker([l.lat, l.lng], { icon: numIcon(i + 1) });
            m.bindTooltip('<b>' + (i + 1) + '. ' + esc(l.name) + '</b>',
                { direction: 'top', offset: [0, -12] });
            dmLayer.addLayer(m);
            pts.push([l.lat, l.lng]);
        });
        if (pts.length > 1) {
            dmLayer.addLayer(L.polyline(pts, { color: '#12399B', weight: 3.5, dashArray: '8 8', opacity: .9 }));
        }

        setTimeout(function () {
            dmMap.invalidateSize();
            if (pts.length === 1) dmMap.setView(pts[0], 14);
            else if (pts.length) dmMap.fitBounds(L.latLngBounds(pts).pad(0.2));
            else dmMap.setView([30.05, 31.4], 10);
        }, 120);
    }

    document.querySelectorAll('.lp-day-map').forEach(function (b) {
        b.addEventListener('click', function (e) {
            // ⚠️ الهيدر جوه اليوم واليوم كليكابل للتسليح — نوقف الفقاعة
            e.stopPropagation();
            dayMapOpen(b.dataset.d, b.dataset.t);
        });
    });

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
