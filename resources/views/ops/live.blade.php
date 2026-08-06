@extends('layouts.system')

{{--
    التيرمينال اللايف (2026-08-06) — زي شاشة البورصة:

    تيكر متحرك فيه كل مندوب ببضاعته بكام ومبيعاته وحالته ← خريطة
    بأيقونات نابضة بتتحرك وبتنور بالحالة (بنفسجي في زيارة / أخضر
    متحرك / برتقالي واقف / رمادي مفيش إشارة) ← جدول حي.
    كله بيتحدث من `ops.live.data` كل 15 ثانية من غير ريلود.
--}}

@section('title', __('journey.live'))

@section('actions')
    <a class="btn" href="{{ route('ops.journeys') }}">🗺️ {{ __('journey.page') }}</a>
    <a class="btn" href="{{ route('ops.tracking') }}">📍 {{ __('nav.tracking') }}</a>
@endsection

@section('content')

<div class="card" style="padding:10px 16px">
    <h3 style="margin:0">📡 {{ __('journey.live') }}
        <span class="side">{{ __('journey.terminal_hint') }}</span>
        <span style="margin-inline-start:auto;display:inline-flex;align-items:center;gap:6px;font-size:11px;color:var(--muted)">
            <span id="lvDot" style="width:8px;height:8px;border-radius:50%;background:var(--green, #1B7A3D)" class="lv-pulse"></span>
            <span id="lvStamp">—</span>
        </span>
    </h3>
</div>

{{-- ═══ التيكر — بورصة المناديب ═══ --}}
<div class="card" style="padding:0;overflow:hidden">
    <div class="lv-ticker"><div class="lv-track" id="lvTicker"></div></div>
</div>

<div class="kpis">
    <div class="kpi"><div class="lbl">🧑‍💼 {{ __('journey.active_reps') }}</div><div class="val"><span id="kActive">—</span> <span style="font-size:14px;color:var(--muted)">/ <span id="kReps">—</span></span></div></div>
    <div class="kpi"><div class="lbl">📍 {{ __('journey.done') }}</div><div class="val pos"><span id="kDone">—</span> <span style="font-size:14px;color:var(--muted)">/ <span id="kPlanned">—</span></span></div></div>
    <div class="kpi"><div class="lbl">💼 {{ __('journey.stock_value') }}</div><div class="val" style="color:var(--primary)"><span id="kValue">—</span></div><div class="sub2">{{ __('common.currency') }}</div></div>
    <div class="kpi"><div class="lbl">🧾 {{ __('journey.sales_today') }}</div><div class="val pos"><span id="kSales">—</span></div><div class="sub2">{{ __('common.currency') }}</div></div>
</div>

{{-- ═══ الخريطة — الأيقونات بتتحرك وتنبض ═══ --}}
<div class="card">
    <h3>🗺️ {{ __('journey.on_map') }} <span class="side" id="mapCount">—</span></h3>
    <div id="liveMap" class="mapbox" style="height:440px"></div>
    <div style="display:flex;gap:14px;margin-top:8px;font-size:11px;color:var(--muted);flex-wrap:wrap">
        <span><span class="lv-dot" style="background:#602D90"></span> {{ __('journey.in_visit_now') }}</span>
        <span><span class="lv-dot" style="background:#1B7A3D"></span> {{ __('journey.moving') }}</span>
        <span><span class="lv-dot" style="background:#B86E00"></span> {{ __('journey.idle') }}</span>
        <span><span class="lv-dot" style="background:#9aa3b2"></span> {{ __('journey.offline') }}</span>
    </div>
</div>

{{-- ═══ الجدول الحي ═══ --}}
<div class="card">
    <h3>🧑‍💼 {{ __('journey.rep') }}</h3>
    <div class="tablewrap lv-tbl">
        <table>
            <thead>
                <tr>
                    <th style="text-align:start">{{ __('journey.rep') }}</th>
                    <th>{{ __('common.status') }}</th>
                    <th>{{ __('journey.done') }}</th>
                    <th>{{ __('journey.completion') }}</th>
                    <th>{{ __('journey.stock_units') }}</th>
                    <th>💼 {{ __('journey.stock_value') }}</th>
                    <th>🧾 {{ __('journey.sales_today') }}</th>
                    <th>🛣️ {{ __('journey.km_unit') }}</th>
                    <th>{{ __('journey.last_signal') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="lvRows"></tbody>
        </table>
    </div>
</div>

@endsection

@section('scripts')
@php
    // الرسمة الأولى بنفس داتا الـJSON — بعدها الرفرش بياخد من الإندبوينت
    $initial = [
        'totals' => $totals + ['sales' => round($rows->sum(fn ($r) => $r['sales_today']), 2)],
        'reps' => $rows->map(fn ($r) => [
            'id' => $r['rep']->id,
            'name' => $r['rep']->displayName(),
            'role' => $r['rep']->roleLabel(),
            'zone' => $r['rep']->zone?->displayName(),
            'lat' => $r['lat'] !== null ? (float) $r['lat'] : null,
            'lng' => $r['lng'] !== null ? (float) $r['lng'] : null,
            'status' => $r['status'],
            'minutes' => $r['minutes_ago'],
            'done' => $r['summary']['done'],
            'planned' => $r['summary']['planned'],
            'pct' => $r['summary']['pct'],
            'off_plan' => $r['summary']['off_plan'],
            'units' => $r['remaining_units'],
            'value' => $r['remaining_value'],
            'sales' => $r['sales_today'],
            'km' => $r['km_today'],
            'url' => route('ops.rep_day', $r['rep']),
        ])->values()->all(),
    ];
@endphp
<style>
/* نبضة الحالة — الأيقونة بتنور وتطفي */
@keyframes lvPing { 0% { transform: scale(1); opacity: .7; } 80% { transform: scale(2.1); opacity: 0; } 100% { opacity: 0; } }
@keyframes lvBlink { 0%, 100% { opacity: 1; } 50% { opacity: .35; } }
.lv-pulse { animation: lvBlink 1.6s infinite; }
.lv-dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; }
.lv-marker { position: relative; }
.lv-marker .ring { position: absolute; inset: 0; border-radius: 50%; animation: lvPing 1.8s infinite; }
.lv-marker .core {
  position: absolute; inset: 4px; border-radius: 50%; border: 2.5px solid #fff;
  box-shadow: 0 2px 6px rgba(0,0,0,.35); display: flex; align-items: center;
  justify-content: center; font-size: 13px;
}
/* التيكر — شريط بورصة بيلف */
.lv-ticker { overflow: hidden; white-space: nowrap; background: #0d1330; }
.lv-track { display: inline-block; padding: 9px 0; animation: lvScroll 40s linear infinite; }
.lv-ticker:hover .lv-track { animation-play-state: paused; }
@keyframes lvScroll { 0% { transform: translateX(0); } 100% { transform: translateX(50%); } }
.lv-chip {
  display: inline-flex; align-items: center; gap: 7px; margin: 0 14px;
  color: #fff; font-size: 12px; font-weight: 700;
}
.lv-chip .v { color: #FFF927; font-weight: 900; }
.lv-chip .s { color: #9fb0e8; font-weight: 600; }
.lv-tbl th, .lv-tbl td { text-align: center; vertical-align: middle; }
</style>
<script>
const LV0 = {!! json_encode($initial, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) !!};
const LV_URL = @js(route('ops.live.data'));
const LV_STATUS = {
    visit: { color: '#602D90', label: @js(__('journey.in_visit_now')), icon: '🏪' },
    moving: { color: '#1B7A3D', label: @js(__('journey.moving')), icon: '🚚' },
    idle: { color: '#B86E00', label: @js(__('journey.idle')), icon: '⏸️' },
    off: { color: '#9aa3b2', label: @js(__('journey.offline')), icon: '💤' },
};
const LV_NOW = @js(__('journey.now'));
const LV_MIN = @js(__('journey.minutes_ago', ['count' => '#N#']));
const LV_DAY = @js(__('journey.rep_day'));

const esc = s => String(s ?? '').replace(/[&<>"']/g,
    ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
const fm = n => Number(n || 0).toLocaleString();

const map = L.map('liveMap').setView([30.05, 31.25], 10);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(map);
const markers = {};   // id → L.marker
let fitted = false;

function lvIcon(rep) {
    const st = LV_STATUS[rep.status] || LV_STATUS.off;
    // النبضة للنشط بس — الرمادي ساكن
    const ring = rep.status === 'off' ? '' :
        '<span class="ring" style="background:' + st.color + '"></span>';

    return L.divIcon({
        className: '', iconSize: [38, 38], iconAnchor: [19, 19],
        html: '<div class="lv-marker" style="width:38px;height:38px">' + ring +
              '<span class="core" style="background:' + st.color + '">' + st.icon + '</span></div>',
    });
}

function lvPopup(r) {
    const st = LV_STATUS[r.status] || LV_STATUS.off;
    return '<b>' + esc(r.name) + '</b> — ' + st.label + '<br>' +
        '💼 ' + fm(r.value) + ' · 🧾 ' + fm(r.sales) + '<br>' +
        '📍 ' + r.done + '/' + r.planned + ' · 🛣️ ' + r.km + '<br>' +
        '<a href="' + r.url + '">' + esc(LV_DAY) + ' ←</a>';
}

function lvRender(data) {
    // KPIs
    document.getElementById('kActive').textContent = fm(data.totals.active);
    document.getElementById('kReps').textContent = fm(data.totals.reps);
    document.getElementById('kDone').textContent = fm(data.totals.done);
    document.getElementById('kPlanned').textContent = fm(data.totals.planned);
    document.getElementById('kValue').textContent = fm(data.totals.value);
    document.getElementById('kSales').textContent = fm(data.totals.sales);
    document.getElementById('lvStamp').textContent = new Date().toLocaleTimeString();

    // التيكر — النسخة مكررة عشان اللفة تبقى متصلة
    const chips = data.reps.map(r => {
        const st = LV_STATUS[r.status] || LV_STATUS.off;
        return '<span class="lv-chip"><span class="lv-dot lv-pulse" style="background:' + st.color + '"></span>' +
            esc(r.name) + ' <span class="v">💼 ' + fm(r.value) + '</span>' +
            ' <span class="s">🧾 ' + fm(r.sales) + ' · 📍 ' + r.done + '/' + r.planned + ' · 🛣️ ' + r.km + '</span></span>';
    }).join('<span style="color:#3d4a86">|</span>');
    document.getElementById('lvTicker').innerHTML = chips + '<span style="color:#3d4a86">|</span>' + chips;

    // الماركرز — بتتحرك مش بتتمسح وتترسم (عشان الحركة تبان)
    const bounds = [];
    let onMap = 0;

    data.reps.forEach(r => {
        if (r.lat === null) { if (markers[r.id]) { map.removeLayer(markers[r.id]); delete markers[r.id]; } return; }
        onMap++;
        bounds.push([r.lat, r.lng]);

        if (markers[r.id]) {
            markers[r.id].setLatLng([r.lat, r.lng]);
            markers[r.id].setIcon(lvIcon(r));
            markers[r.id].setPopupContent(lvPopup(r));
        } else {
            markers[r.id] = L.marker([r.lat, r.lng], { icon: lvIcon(r) })
                .addTo(map).bindPopup(lvPopup(r));
        }
    });

    document.getElementById('mapCount').textContent = onMap;

    if (! fitted && bounds.length) { map.fitBounds(bounds, { padding: [40, 40], maxZoom: 13 }); fitted = true; }

    // الجدول
    document.getElementById('lvRows').innerHTML = data.reps.map(r => {
        const st = LV_STATUS[r.status] || LV_STATUS.off;
        const seen = r.minutes === null ? st.label
            : (r.minutes < 2 ? LV_NOW : LV_MIN.replace('#N#', r.minutes));
        const pctCls = r.pct >= 80 ? 'b-green' : (r.pct >= 40 ? 'b-orange' : 'b-red');

        return '<tr>' +
            '<td style="text-align:start"><b>' + esc(r.name) + '</b>' +
            '<div style="font-size:10px;color:var(--muted)">' + esc(r.role) + (r.zone ? ' · ' + esc(r.zone) : '') + '</div></td>' +
            '<td><span class="lv-dot lv-pulse" style="background:' + st.color + '"></span> ' + st.label + '</td>' +
            '<td class="num">' + r.done + ' / ' + r.planned + (r.off_plan > 0 ? ' <span style="color:var(--orange,#B86E00);font-size:10px">+' + r.off_plan + '</span>' : '') + '</td>' +
            '<td><span class="badge ' + pctCls + '">' + r.pct + '%</span></td>' +
            '<td class="num">' + fm(r.units) + '</td>' +
            '<td class="num"><b>' + fm(r.value) + '</b></td>' +
            '<td class="num pos"><b>' + fm(r.sales) + '</b></td>' +
            '<td class="num">' + r.km + '</td>' +
            '<td style="font-size:11px">' + seen + '</td>' +
            '<td><a class="btn sm" href="' + r.url + '">←</a></td>' +
            '</tr>';
    }).join('');
}

lvRender(LV0);

// ⚠️ الرفرش بيتلغى والصفحة مخفية — تبويب في الخلفية مايضربش السيرفر
setInterval(async () => {
    if (document.hidden) return;

    try {
        const res = await fetch(LV_URL, { headers: { Accept: 'application/json' } });
        if (res.ok) lvRender(await res.json());
    } catch (e) { /* اتصال وقع — المحاولة الجاية بعد 15 ثانية */ }
}, 15000);
</script>
@endsection
