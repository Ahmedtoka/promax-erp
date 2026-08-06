@extends('layouts.system')

@section('title', __('journey.control_room'))

{{-- ═══════════════════════════════════════════════════════════════
     غرفة تحكم المناديب (2026-08-06) — إعادة بناء بنمط غرفة العمليات:
     ثيم داكن خاص بالشاشة دي بس، خريطة داكنة بدواير الزونات، سايدبار
     مناديب ببحث وفلاتر، بانل تفاصيل المندوب المختار بعهدته وبارات
     الأصناف، وفيد تنبيهات حقيقي من الفواتير والزيارات.
     الرفرش كل 15 ثانية زي ما كان — مع إيقاف مؤقت وتتبع مندوب.
     ⚠️ الداتا كلها من `livePayload` — أول رسمة والرفرش نفس المصدر. --}}

@section('actions')
    <a class="btn" href="{{ route('ops.journeys') }}">🗓️ {{ __('journey.page') }}</a>
    <a class="btn" href="{{ route('ops.tracking') }}">📍 {{ __('nav.tracking') }}</a>
@endsection

@section('content')

<div id="lvRoom" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">

    {{-- ═════ الهيدر: عنوان + ساعة + KPIs ═════ --}}
    <div class="lv-top">
        <div class="lv-title">
            <span class="lv-live-dot"></span>
            <b>{{ __('journey.control_room') }}</b>
            <span class="lv-clock" id="lvClock">--:--:--</span>
        </div>
        <div class="lv-kpis" id="lvKpis"></div>
    </div>

    <div class="lv-grid">

        {{-- ═════ سايدبار المناديب ═════ --}}
        <aside class="lv-side">
            <div class="lv-side-head">
                <b>{{ __('journey.rep') }}</b>
                <span id="lvSideCount" class="lv-dim"></span>
            </div>
            <input type="text" id="lvSearch" class="lv-search" placeholder="{{ __('journey.search_rep') }}">
            <div class="lv-chips" id="lvChips">
                <button class="lv-chip on" data-f="">{{ __('common.all') }}</button>
                <button class="lv-chip" data-f="moving">{{ __('journey.moving') }}</button>
                <button class="lv-chip" data-f="visit">{{ __('journey.in_visit_now') }}</button>
                <button class="lv-chip" data-f="idle">{{ __('journey.idle') }}</button>
                <button class="lv-chip" data-f="off">{{ __('journey.offline') }}</button>
            </div>
            <div class="lv-replist" id="lvRepList"></div>
        </aside>

        {{-- ═════ الخريطة ═════ --}}
        <div class="lv-maparea">
            <div class="lv-mapbar">
                <button class="lv-btn" id="lvFollowBtn">🎯 {{ __('journey.follow_rep') }}</button>
                <button class="lv-btn on" id="lvZonesBtn">{{ __('journey.zones_shown') }}</button>
                <button class="lv-btn" id="lvPauseBtn">⏸ {{ __('journey.pause_updates') }}</button>
            </div>
            <div id="lvMap"></div>
            <div class="lv-legend">
                <span><i class="dot d-visit"></i>{{ __('journey.in_visit_now') }}</span>
                <span><i class="dot d-moving"></i>{{ __('journey.moving') }}</span>
                <span><i class="dot d-idle"></i>{{ __('journey.idle') }}</span>
                <span><i class="dot d-off"></i>{{ __('journey.offline') }}</span>
            </div>
        </div>

        {{-- ═════ بانل المندوب المختار + التنبيهات ═════ --}}
        <aside class="lv-detail">
            <div class="lv-card" id="lvRepCard">
                <div class="lv-dim" style="text-align:center;padding:26px 8px">{{ __('journey.pick_rep') }}</div>
            </div>
            <div class="lv-card">
                <div class="lv-card-h">🔔 {{ __('journey.alerts_feed') }} <span class="lv-dim" id="lvAlertCount"></span></div>
                <div class="lv-alerts" id="lvAlerts"></div>
            </div>
        </aside>
    </div>
</div>

<style>
/* ═════ ثيم غرفة التحكم — داكن، للشاشة دي بس ═════ */
#lvRoom{
    --bg:#0D1022; --panel:#151936; --panel2:#1C2144; --line:#262C55;
    --txt:#E8EAF6; --dim:#8B90B5;
    --royal:#4D6FE3; --purple:#9D6FE0; --green:#2EDE8B; --orange:#FFB020; --red:#FF5D73; --gray:#5A5F85;
    background:var(--bg); color:var(--txt);
    margin:-18px; padding:14px; min-height:calc(100vh - 60px);
    font-variant-numeric:tabular-nums;
}
#lvRoom *{box-sizing:border-box}
.lv-top{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px}
.lv-title{display:flex;align-items:center;gap:10px;font-size:17px}
.lv-clock{font-size:13px;color:var(--dim);direction:ltr}
.lv-live-dot{width:9px;height:9px;border-radius:50%;background:var(--green);box-shadow:0 0 8px var(--green);animation:lvBlink 1.4s infinite}
.lv-kpis{display:flex;gap:8px;flex-wrap:wrap}
.lv-kpi{background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:7px 13px;text-align:center;min-width:86px}
.lv-kpi .v{font-size:16.5px;font-weight:700}
.lv-kpi .l{font-size:10px;color:var(--dim);margin-top:1px;white-space:nowrap}

.lv-grid{display:grid;grid-template-columns:250px 1fr 300px;gap:12px;align-items:start}
@media(max-width:1200px){.lv-grid{grid-template-columns:1fr}.lv-side,.lv-detail{max-height:none}}

/* السايدبار */
.lv-side{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:10px;max-height:78vh;display:flex;flex-direction:column}
.lv-side-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;font-size:13px}
.lv-search{width:100%;background:var(--panel2);border:1px solid var(--line);border-radius:8px;color:var(--txt);padding:7px 10px;font-size:12px;margin-bottom:8px}
.lv-search::placeholder{color:var(--dim)}
.lv-chips{display:flex;gap:5px;flex-wrap:wrap;margin-bottom:8px}
.lv-chip{background:var(--panel2);border:1px solid var(--line);color:var(--dim);border-radius:999px;padding:3px 10px;font-size:10.5px;cursor:pointer}
.lv-chip.on{background:var(--royal);border-color:var(--royal);color:#fff}
.lv-replist{overflow-y:auto;display:flex;flex-direction:column;gap:6px;flex:1}
.lv-rep{background:var(--panel2);border:1px solid var(--line);border-radius:10px;padding:8px 10px;cursor:pointer;display:flex;gap:9px;align-items:center}
.lv-rep:hover{border-color:var(--royal)}
.lv-rep.sel{border-color:var(--royal);box-shadow:0 0 0 1px var(--royal)}
.lv-avatar{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;color:#fff}
.lv-rep .nm{font-size:12.5px;font-weight:600}
.lv-rep .mt{font-size:10px;color:var(--dim);margin-top:2px}
.lv-status{font-size:9.5px;border-radius:999px;padding:1px 7px;margin-inline-start:auto;white-space:nowrap}
.s-visit{background:rgba(157,111,224,.18);color:var(--purple)}
.s-moving{background:rgba(46,222,139,.15);color:var(--green)}
.s-idle{background:rgba(255,176,32,.15);color:var(--orange)}
.s-off{background:rgba(90,95,133,.25);color:var(--dim)}
.z-in{color:var(--green)} .z-out{color:var(--red)}

/* الخريطة */
.lv-maparea{position:relative}
.lv-mapbar{display:flex;gap:7px;margin-bottom:8px;flex-wrap:wrap}
.lv-btn{background:var(--panel);border:1px solid var(--line);color:var(--txt);border-radius:8px;padding:6px 12px;font-size:11.5px;cursor:pointer}
.lv-btn.on{background:var(--royal);border-color:var(--royal)}
#lvMap{height:64vh;border-radius:12px;border:1px solid var(--line);background:var(--panel)}
.lv-legend{display:flex;gap:14px;justify-content:flex-end;margin-top:7px;font-size:10.5px;color:var(--dim)}
.lv-legend .dot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-inline-end:4px}
.d-visit{background:var(--purple)} .d-moving{background:var(--green)} .d-idle{background:var(--orange)} .d-off{background:var(--gray)}

/* بانل التفاصيل */
.lv-detail{display:flex;flex-direction:column;gap:12px;max-height:78vh;overflow-y:auto}
.lv-card{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:12px}
.lv-card-h{font-size:13px;font-weight:700;margin-bottom:9px;display:flex;justify-content:space-between;align-items:center}
.lv-dim{color:var(--dim);font-size:10.5px;font-weight:400}
.lv-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin:10px 0}
.lv-stat{background:var(--panel2);border-radius:8px;padding:6px 4px;text-align:center}
.lv-stat .v{font-size:13.5px;font-weight:700}
.lv-stat .l{font-size:9px;color:var(--dim)}
.lv-item{margin-bottom:9px}
.lv-item .r1{display:flex;justify-content:space-between;font-size:11px;margin-bottom:3px}
.lv-item .bar{height:5px;background:var(--panel2);border-radius:99px;overflow:hidden}
.lv-item .bar i{display:block;height:100%;border-radius:99px}
.lv-alerts{display:flex;flex-direction:column;gap:7px;max-height:34vh;overflow-y:auto}
.lv-alert{display:flex;gap:8px;font-size:11px;line-height:1.5;border-inline-start:2px solid var(--line);padding-inline-start:8px}
.lv-alert .tm{color:var(--dim);font-size:10px;direction:ltr;white-space:nowrap}
.a-sale{border-color:var(--green)} .a-checkin{border-color:var(--purple)} .a-checkout{border-color:var(--royal)}

/* الماركرز */
.lv-marker{position:relative}
.lv-marker .core{width:15px;height:15px;border-radius:50%;border:2px solid #fff;position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);z-index:2}
.lv-marker .ring{width:34px;height:34px;border-radius:50%;position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);opacity:.55}
.lv-marker.mv .ring{animation:lvPing 1.6s ease-out infinite}
.lv-marker.vs .ring{animation:lvPing 1.1s ease-out infinite}
.lv-marker .tag{position:absolute;top:-19px;left:50%;transform:translateX(-50%);background:rgba(13,16,34,.85);color:#E8EAF6;font-size:9.5px;padding:1px 7px;border-radius:99px;white-space:nowrap;border:1px solid #262C55}
@keyframes lvPing{0%{transform:translate(-50%,-50%) scale(.5);opacity:.7}100%{transform:translate(-50%,-50%) scale(1.7);opacity:0}}
@keyframes lvBlink{0%,100%{opacity:1}50%{opacity:.35}}
.lv-zone-label{background:transparent;border:0;box-shadow:none;color:#8B90B5;font-size:11px;font-weight:700;white-space:nowrap}
.leaflet-container{background:#0D1022}
</style>
@endsection

@section('scripts')
<script>
(function () {
'use strict';

/* ═════ الحالة ═════ */
let data = {!! json_encode($initial, JSON_UNESCAPED_UNICODE) !!};
let selectedId = null, followId = null, paused = false, zonesOn = true, filter = '', search = '';
const markers = {}, zoneShapes = [];

const T = {
    statuses: {
        visit: {!! json_encode(__('journey.in_visit_now'), JSON_UNESCAPED_UNICODE) !!},
        moving: {!! json_encode(__('journey.moving'), JSON_UNESCAPED_UNICODE) !!},
        idle: {!! json_encode(__('journey.idle'), JSON_UNESCAPED_UNICODE) !!},
        off: {!! json_encode(__('journey.offline'), JSON_UNESCAPED_UNICODE) !!},
    },
    kpis: [
        ['reps', {!! json_encode(__('journey.rep'), JSON_UNESCAPED_UNICODE) !!}],
        ['in_zone', {!! json_encode(__('journey.in_zone'), JSON_UNESCAPED_UNICODE) !!}],
        ['out_zone', {!! json_encode(__('journey.out_zone'), JSON_UNESCAPED_UNICODE) !!}],
        ['idle', {!! json_encode(__('journey.stopped'), JSON_UNESCAPED_UNICODE) !!}],
        ['units', {!! json_encode(__('journey.units_in_custody'), JSON_UNESCAPED_UNICODE) !!}],
        ['value', {!! json_encode(__('journey.stock_value'), JSON_UNESCAPED_UNICODE) !!}],
        ['sales', {!! json_encode(__('journey.sales_today'), JSON_UNESCAPED_UNICODE) !!}],
    ],
    inZone: {!! json_encode(__('journey.in_zone'), JSON_UNESCAPED_UNICODE) !!},
    outZone: {!! json_encode(__('journey.out_zone'), JSON_UNESCAPED_UNICODE) !!},
    speedU: {!! json_encode(__('journey.speed_unit'), JSON_UNESCAPED_UNICODE) !!},
    kmU: {!! json_encode(__('journey.km_unit'), JSON_UNESCAPED_UNICODE) !!},
    sales: {!! json_encode(__('journey.sales_today'), JSON_UNESCAPED_UNICODE) !!},
    orders: {!! json_encode(__('journey.orders_today'), JSON_UNESCAPED_UNICODE) !!},
    done: {!! json_encode(__('journey.done'), JSON_UNESCAPED_UNICODE) !!},
    custody: {!! json_encode(__('journey.custody_panel'), JSON_UNESCAPED_UNICODE) !!},
    sold: {!! json_encode(__('journey.sold_label'), JSON_UNESCAPED_UNICODE) !!},
    left: {!! json_encode(__('journey.remaining_label'), JSON_UNESCAPED_UNICODE) !!},
    remTotal: {!! json_encode(__('journey.remaining_total'), JSON_UNESCAPED_UNICODE) !!},
    worth: {!! json_encode(__('journey.worth'), JSON_UNESCAPED_UNICODE) !!},
    latest: {!! json_encode(__('journey.alerts_latest'), JSON_UNESCAPED_UNICODE) !!},
    noAlerts: {!! json_encode(__('journey.no_alerts'), JSON_UNESCAPED_UNICODE) !!},
    lastSignal: {!! json_encode(__('journey.last_signal'), JSON_UNESCAPED_UNICODE) !!},
    follow: '🎯 ' + {!! json_encode(__('journey.follow_rep'), JSON_UNESCAPED_UNICODE) !!},
    unfollow: '🎯 ' + {!! json_encode(__('journey.unfollow'), JSON_UNESCAPED_UNICODE) !!},
    zShown: {!! json_encode(__('journey.zones_shown'), JSON_UNESCAPED_UNICODE) !!},
    zHidden: {!! json_encode(__('journey.zones_hidden'), JSON_UNESCAPED_UNICODE) !!},
    pause: '⏸ ' + {!! json_encode(__('journey.pause_updates'), JSON_UNESCAPED_UNICODE) !!},
    resume: '▶ ' + {!! json_encode(__('journey.resume_updates'), JSON_UNESCAPED_UNICODE) !!},
};

const SC = { visit: '#9D6FE0', moving: '#2EDE8B', idle: '#FFB020', off: '#5A5F85' };
const AV = ['#4D6FE3','#9D6FE0','#2EDE8B','#FFB020','#FF5D73','#38BDF8','#F472B6','#A3E635'];
const fmt = n => Number(n || 0).toLocaleString('en-US', { maximumFractionDigits: 0 });

/* ═════ الخريطة الداكنة ═════ */
const map = L.map('lvMap', { zoomControl: true }).setView([30.05, 31.25], 11);
L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
    attribution: '© OpenStreetMap © CARTO', maxZoom: 19,
}).addTo(map);

function drawZones() {
    zoneShapes.forEach(s => map.removeLayer(s));
    zoneShapes.length = 0;
    if (!zonesOn) return;

    (data.zones || []).forEach(z => {
        const c = L.circle([z.lat, z.lng], {
            radius: 2500, color: '#8B90B5', weight: 1.2, dashArray: '6 7',
            fillColor: '#4D6FE3', fillOpacity: .05,
        }).addTo(map);
        const lbl = L.marker([z.lat, z.lng], {
            icon: L.divIcon({ className: 'lv-zone-label', html: z.name, iconSize: null }),
            interactive: false,
        }).addTo(map);
        zoneShapes.push(c, lbl);
    });
}

function repIcon(r) {
    const cls = r.status === 'moving' ? 'mv' : (r.status === 'visit' ? 'vs' : '');
    return L.divIcon({
        className: '',
        html: `<div class="lv-marker ${cls}">
                 <div class="ring" style="background:${SC[r.status]}"></div>
                 <div class="core" style="background:${SC[r.status]}"></div>
                 <div class="tag">${r.name}</div>
               </div>`,
        iconSize: [34, 34], iconAnchor: [17, 17],
    });
}

/* ═════ الرسم ═════ */
function render() {
    // KPIs
    document.getElementById('lvKpis').innerHTML = T.kpis.map(([k, l]) =>
        `<div class="lv-kpi"><div class="v">${fmt(data.totals[k])}</div><div class="l">${l}</div></div>`).join('');

    // قايمة المناديب
    const q = search.trim().toLowerCase();
    const reps = (data.reps || []).filter(r =>
        (!filter || r.status === filter)
        && (!q || (r.name + ' ' + (r.zone || '')).toLowerCase().includes(q)));

    document.getElementById('lvSideCount').textContent = reps.length + ' / ' + (data.reps || []).length;
    document.getElementById('lvRepList').innerHTML = reps.map((r, i) => `
        <div class="lv-rep ${r.id === selectedId ? 'sel' : ''}" data-id="${r.id}">
            <div class="lv-avatar" style="background:${AV[i % AV.length]}">${r.name.slice(0, 2)}</div>
            <div>
                <div class="nm">${r.name}</div>
                <div class="mt">
                    ${r.speed !== null ? r.speed + ' ' + T.speedU + ' · ' : ''}${r.zone || '—'}
                    ${r.in_zone === true ? ' · <b class="z-in">●</b>' : (r.in_zone === false ? ' · <b class="z-out">●</b>' : '')}
                </div>
            </div>
            <span class="lv-status s-${r.status}">${T.statuses[r.status]}</span>
        </div>`).join('');

    document.querySelectorAll('#lvRepList .lv-rep').forEach(el =>
        el.addEventListener('click', () => selectRep(parseInt(el.dataset.id, 10))));

    // الماركرز — بتتحرك مش بتتمسح
    const seen = new Set();
    (data.reps || []).forEach(r => {
        if (r.lat === null) return;
        seen.add(r.id);
        if (markers[r.id]) {
            markers[r.id].setLatLng([r.lat, r.lng]);
            markers[r.id].setIcon(repIcon(r));
        } else {
            markers[r.id] = L.marker([r.lat, r.lng], { icon: repIcon(r) })
                .addTo(map).on('click', () => selectRep(r.id));
        }
    });
    Object.keys(markers).forEach(id => {
        if (!seen.has(parseInt(id, 10))) { map.removeLayer(markers[id]); delete markers[id]; }
    });

    // التتبع
    if (followId !== null) {
        const fr = (data.reps || []).find(r => r.id === followId);
        if (fr && fr.lat !== null) map.panTo([fr.lat, fr.lng]);
    }

    renderDetail();
    renderAlerts();
}

function selectRep(id) {
    selectedId = id;
    if (followId !== null) followId = id;
    const r = (data.reps || []).find(x => x.id === id);
    if (r && r.lat !== null) map.panTo([r.lat, r.lng]);
    render();
}

function renderDetail() {
    const el = document.getElementById('lvRepCard');
    const r = (data.reps || []).find(x => x.id === selectedId);
    if (!r) return;

    const zoneChip = r.in_zone === null ? ''
        : `<span class="lv-status ${r.in_zone ? 's-moving' : 's-idle'}" style="${r.in_zone ? '' : 'color:var(--red);background:rgba(255,93,115,.15)'}">
             ${r.in_zone ? T.inZone : T.outZone}</span>`;

    const items = (r.items || []).map(i => {
        const pct = i.assigned > 0 ? Math.round(i.sold / i.assigned * 100) : 0;
        return `<div class="lv-item">
            <div class="r1"><span>${i.name}</span>
                <span class="lv-dim">${T.sold} ${i.sold} · ${T.left} <b style="color:var(--txt)">${i.remaining}</b> / ${i.assigned}</span></div>
            <div class="bar"><i style="width:${pct}%;background:linear-gradient(90deg,#4D6FE3,#9D6FE0)"></i></div>
        </div>`;
    }).join('');

    el.innerHTML = `
        <div class="lv-card-h">
            <span><a href="${r.url}" style="color:var(--txt);text-decoration:none">${r.name} ↗</a>
                <div class="lv-dim">${r.zone || '—'} · ${r.role}</div></span>
            <span>${zoneChip}<br><span class="lv-status s-${r.status}" style="margin-top:4px;display:inline-block">${T.statuses[r.status]}</span></span>
        </div>
        <div class="lv-stats">
            <div class="lv-stat"><div class="v">${r.speed !== null ? r.speed : '—'}</div><div class="l">${T.speedU}</div></div>
            <div class="lv-stat"><div class="v">${r.km}</div><div class="l">${T.kmU}</div></div>
            <div class="lv-stat"><div class="v">${r.done}/${r.planned}</div><div class="l">${T.done}</div></div>
            <div class="lv-stat"><div class="v" style="color:var(--green)">${fmt(r.sales)}</div><div class="l">${T.sales}</div></div>
        </div>
        <div class="lv-card-h" style="margin-top:4px">📦 ${T.custody}
            <span class="lv-dim">${T.remTotal.replace(':count', fmt(r.units))} · ${T.worth.replace(':value', fmt(r.value))}</span></div>
        ${items || '<div class="lv-dim">—</div>'}
        <div class="lv-dim" style="margin-top:6px">${T.lastSignal}: ${r.minutes !== null ? r.minutes + ' د' : '—'}</div>`;
}

function renderAlerts() {
    const list = data.alerts || [];
    document.getElementById('lvAlertCount').textContent = list.length ? T.latest.replace(':count', list.length) : '';
    document.getElementById('lvAlerts').innerHTML = list.length
        ? list.map(a => `<div class="lv-alert a-${a.kind}"><span class="tm">${a.t}</span><span>${a.text}</span></div>`).join('')
        : `<div class="lv-dim">${T.noAlerts}</div>`;
}

/* ═════ التحكم ═════ */
document.getElementById('lvSearch').addEventListener('input', e => { search = e.target.value; render(); });
document.querySelectorAll('#lvChips .lv-chip').forEach(ch => ch.addEventListener('click', () => {
    document.querySelectorAll('#lvChips .lv-chip').forEach(c => c.classList.remove('on'));
    ch.classList.add('on');
    filter = ch.dataset.f;
    render();
}));

document.getElementById('lvZonesBtn').addEventListener('click', function () {
    zonesOn = !zonesOn;
    this.classList.toggle('on', zonesOn);
    this.textContent = zonesOn ? T.zShown : T.zHidden;
    drawZones();
});

document.getElementById('lvFollowBtn').addEventListener('click', function () {
    followId = followId === null ? selectedId : null;
    this.classList.toggle('on', followId !== null);
    this.textContent = followId !== null ? T.unfollow : T.follow;
});

document.getElementById('lvPauseBtn').addEventListener('click', function () {
    paused = !paused;
    this.classList.toggle('on', paused);
    this.textContent = paused ? T.resume : T.pause;
});

/* ═════ الساعة + الرفرش ═════ */
setInterval(() => {
    document.getElementById('lvClock').textContent = new Date().toLocaleTimeString('en-GB');
}, 1000);

async function refresh() {
    if (paused || document.hidden) return;
    try {
        const res = await fetch({!! json_encode(route('ops.live.data')) !!}, { headers: { Accept: 'application/json' } });
        if (!res.ok) return;
        data = await res.json();
        drawZones();
        render();
    } catch (e) { /* الشبكة وقعت — المحاولة الجاية بعد 15 ثانية */ }
}
setInterval(refresh, 15000);

/* أول رسمة من الحمولة المدمجة */
drawZones();
render();

const first = (data.reps || []).find(r => r.lat !== null);
if (first) {
    map.setView([first.lat, first.lng], 12);
    selectRep(first.id);
}
})();
</script>
@endsection
