@extends('layouts.system')

@section('title', __('journey.control_room'))

{{-- ═══════════════════════════════════════════════════════════════
     غرفة تحكم المناديب (2026-08-06) — إعادة بناء بنمط غرفة العمليات:
     ثيم داكن خاص بالشاشة دي بس، خريطة داكنة بدواير الزونات، سايدبار
     مناديب ببحث وفلاتر، بانل تفاصيل المندوب المختار بعهدته وبارات
     الأصناف، وفيد تنبيهات حقيقي من الفواتير والزيارات.
     التحديث لايف بـSSE كل ٣ ثواني (2026-08-07)، وبيرجع للبولينج
     كل ١٥ ثانية لوحده لو التدفق مش شغال — مع إيقاف مؤقت وتتبع مندوب.
     ⚠️ الداتا كلها من `livePayload` — أول رسمة والتحديث نفس المصدر. --}}

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
            {{-- مؤشر وضع التحديث — اليوزر يعرف الشاشة لايف ولا بولينج --}}
            <span class="lv-mode" id="lvMode">{{ __('journey.realtime_off') }}</span>
        </div>
        <div class="lv-kpis" id="lvKpis"></div>
    </div>

    {{-- ═════ شريط الحركة — تيكر البورصة ═════ --}}
    <div class="lv-tape"><div class="lv-tape-track" id="lvTape"></div></div>

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
                {{-- طبقات الخريطة (2026-08-06): شيك بوكسات مرنة —
                     الديفولت المناديب بس، والاختيارات بتتحفظ في المتصفح --}}
                <div class="lv-layers">
                    <span class="lv-layers-t">🗺️ {{ __('journey.map_layers') }}:</span>
                    <label class="lv-layer"><input type="checkbox" data-layer="reps" checked>
                        <i style="background:var(--royal)"></i>{{ __('journey.layer_reps') }}</label>
                    <label class="lv-layer"><input type="checkbox" data-layer="covered">
                        <i style="background:var(--green)"></i>{{ __('journey.layer_covered') }}</label>
                    <label class="lv-layer"><input type="checkbox" data-layer="target">
                        <i style="background:var(--orange)"></i>{{ __('journey.layer_target') }}</label>
                    <label class="lv-layer"><input type="checkbox" data-layer="govs">
                        <i style="background:var(--sky)"></i>{{ __('journey.layer_govs') }}</label>
                </div>
                <button class="lv-btn" id="lvFollowBtn">🎯 {{ __('journey.follow_rep') }}</button>
                <button class="lv-btn" id="lvPauseBtn">⏸ {{ __('journey.pause_updates') }}</button>
            </div>
            <div id="lvMap"></div>
            <div class="lv-legend">
                <span><i class="dot d-visit"></i>{{ __('journey.in_visit_now') }}</span>
                <span><i class="dot d-moving"></i>{{ __('journey.moving') }}</span>
                <span><i class="dot d-idle"></i>{{ __('journey.idle') }}</span>
                <span><i class="dot d-off"></i>{{ __('journey.offline') }}</span>
                <span style="border-inline-start:1px solid var(--line);padding-inline-start:12px">
                    <i class="dot" style="background:var(--green)"></i>{{ __('journey.covered_zone') }}</span>
                <span><i class="dot" style="background:var(--orange)"></i>{{ __('journey.target_zone') }}</span>
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
/* ═════ ثيم غرفة التحكم — مشتق من هوية PROMAX ═════
   الأساس نيلي معتم من Royal Blue #12399B (مش رمادي عام)، والأكسنتات
   نسخ مفتحة من ألوان البراند علشان الكونتراست على الخلفية الداكنة:
   royal ← #5B7BE8، purple heart ← #9B6BDB. أخضر/أحمر بدلالة البورصة. */
#lvRoom{
    --bg:#080C1E; --panel:#101635; --panel2:#171E45; --line:#232B5C;
    --txt:#EDF0FB; --dim:#8A92C0;
    --royal:#5B7BE8; --purple:#9B6BDB; --green:#22C55E; --orange:#F59E0B; --red:#F43F5E;
    --gray:#5A6190; --sky:#38BDF8;
    --grad:linear-gradient(90deg,#12399B,#602D90);
    --grad-lite:linear-gradient(90deg,#5B7BE8,#9B6BDB);
    background:
        radial-gradient(1100px 500px at 85% -10%, rgba(18,57,155,.28), transparent 60%),
        radial-gradient(900px 450px at 10% 110%, rgba(96,45,144,.22), transparent 60%),
        var(--bg);
    color:var(--txt);
    margin:-18px -24px -40px; padding:14px 18px 22px; min-height:calc(100vh - 60px);
    font-variant-numeric:tabular-nums;
}
#lvRoom *{box-sizing:border-box}
#lvRoom ::-webkit-scrollbar{width:8px;height:8px}
#lvRoom ::-webkit-scrollbar-thumb{background:var(--line);border-radius:99px}
.lv-top{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:10px}
.lv-title{display:flex;align-items:center;gap:10px;font-size:17px}
.lv-clock{font-size:13px;color:var(--dim);direction:ltr;letter-spacing:.5px}
.lv-live-dot{width:9px;height:9px;border-radius:50%;background:var(--green);box-shadow:0 0 10px var(--green);animation:lvBlink 1.4s infinite}
/* مؤشر وضع التحديث — رمادي = بولينج، أخضر = تدفق لايف */
.lv-mode{font-size:10px;border-radius:999px;padding:2px 9px;background:var(--panel2);border:1px solid var(--line);color:var(--dim);white-space:nowrap}
.lv-mode.on{color:#4ADE80;border-color:rgba(34,197,94,.45);background:rgba(34,197,94,.12)}

/* KPIs — أيقونة بهالة لونية + رقم بلونه الدال + خط علوي */
.lv-kpis{display:flex;gap:8px;flex-wrap:wrap}
.lv-kpi{
    position:relative;background:linear-gradient(180deg, rgba(255,255,255,.03), transparent), var(--panel);
    border:1px solid var(--line);border-radius:12px;
    padding:9px 14px 8px;min-width:104px;overflow:hidden;
    display:flex;align-items:center;gap:10px;
    transition:transform .18s, border-color .18s, box-shadow .18s;
}
.lv-kpi::before{content:'';position:absolute;top:0;left:0;right:0;height:2.5px;background:var(--kc,var(--grad-lite))}
.lv-kpi:hover{transform:translateY(-2px);border-color:var(--kc,var(--royal));box-shadow:0 6px 18px rgba(0,0,0,.35)}
.lv-kpi .ic{
    width:30px;height:30px;border-radius:9px;flex-shrink:0;
    display:flex;align-items:center;justify-content:center;font-size:14px;
    background:color-mix(in srgb, var(--kc) 16%, transparent);
    box-shadow:inset 0 0 0 1px color-mix(in srgb, var(--kc) 35%, transparent);
}
.lv-kpi .v{font-size:17.5px;font-weight:800;color:var(--kc-b,var(--txt));letter-spacing:.3px;line-height:1.1}
.lv-kpi .l{font-size:9.5px;color:var(--dim);white-space:nowrap;margin-top:1px}
.k-royal{--kc:#5B7BE8;--kc-b:#8FA5F0} .k-green{--kc:#22C55E;--kc-b:#4ADE80}
.k-red{--kc:#F43F5E;--kc-b:#FB7185}  .k-orange{--kc:#F59E0B;--kc-b:#FBBF24}
.k-purple{--kc:#9B6BDB;--kc-b:#B794E8} .k-sky{--kc:#38BDF8;--kc-b:#7DD3FC}

/* طبقات الخريطة */
.lv-layers{display:flex;align-items:center;gap:10px;background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:5px 12px;flex-wrap:wrap}
.lv-layers-t{font-size:11px;color:var(--dim)}
.lv-layer{display:flex;align-items:center;gap:5px;font-size:11.5px;cursor:pointer;user-select:none}
.lv-layer input{accent-color:var(--royal);width:14px;height:14px;cursor:pointer}
.lv-layer i{width:8px;height:8px;border-radius:50%;display:inline-block}
.lv-gov-label{background:transparent;border:0;box-shadow:none;color:#7DD3FC;font-size:13px;font-weight:800;letter-spacing:1px;white-space:nowrap;text-shadow:0 0 12px rgba(56,189,248,.5)}

/* شريط الحركة — تيكر البورصة */
.lv-tape{background:var(--panel);border:1px solid var(--line);border-radius:10px;overflow:hidden;margin-bottom:12px;position:relative}
.lv-tape::before,.lv-tape::after{content:'';position:absolute;top:0;bottom:0;width:46px;z-index:2;pointer-events:none}
.lv-tape::before{inset-inline-start:0;background:linear-gradient(to left,transparent,var(--panel))}
.lv-tape::after{inset-inline-end:0;background:linear-gradient(to right,transparent,var(--panel))}
.lv-tape-track{display:inline-flex;gap:34px;white-space:nowrap;padding:7px 0;animation:lvTape 40s linear infinite;will-change:transform}
.lv-tape:hover .lv-tape-track{animation-play-state:paused}
.lv-tk{font-size:11.5px;display:inline-flex;gap:7px;align-items:center}
.lv-tk .sym{font-weight:700}
.lv-tk .up{color:var(--green)} .lv-tk .dn{color:var(--dim)}
@keyframes lvTape{0%{transform:translateX(0)}100%{transform:translateX({{ app()->getLocale() === 'ar' ? '' : '-' }}50%)}}

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
.lv-rep{background:var(--panel2);border:1px solid var(--line);border-radius:10px;padding:8px 10px;cursor:pointer;display:flex;gap:9px;align-items:center;transition:border-color .15s, transform .15s;position:relative;overflow:hidden}
.lv-rep::before{content:'';position:absolute;inset-inline-start:0;top:0;bottom:0;width:3px;background:transparent}
.lv-rep:hover{border-color:var(--royal);transform:translateX({{ app()->getLocale() === 'ar' ? '-2px' : '2px' }})}
.lv-rep.sel{border-color:var(--royal);box-shadow:0 0 0 1px var(--royal), 0 0 18px rgba(91,123,232,.25)}
.lv-rep.sel::before{background:var(--grad-lite)}
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
.lv-card{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:12px;position:relative;overflow:hidden}
.lv-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2.5px;background:var(--grad)}
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
.lv-alert .ic{font-size:12px;line-height:1.3}
.lv-alert .rp{color:var(--dim);white-space:nowrap;max-width:78px;overflow:hidden;text-overflow:ellipsis}

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
let selectedId = null, followId = null, paused = false, filter = '', search = '';
const markers = {}, zoneShapes = [], govShapes = [];

// طبقات الخريطة — الديفولت المناديب بس، والاختيار محفوظ في المتصفح
let layers = { reps: true, covered: false, target: false, govs: false };
try {
    const saved = JSON.parse(localStorage.getItem('lvLayers') || 'null');
    if (saved && typeof saved === 'object') layers = Object.assign(layers, saved);
} catch (e) { /* تخزين بايظ — الديفولت */ }

const T = {
    statuses: {
        visit: {!! json_encode(__('journey.in_visit_now'), JSON_UNESCAPED_UNICODE) !!},
        moving: {!! json_encode(__('journey.moving'), JSON_UNESCAPED_UNICODE) !!},
        idle: {!! json_encode(__('journey.idle'), JSON_UNESCAPED_UNICODE) !!},
        off: {!! json_encode(__('journey.offline'), JSON_UNESCAPED_UNICODE) !!},
    },
    kpis: [
        ['reps', {!! json_encode(__('journey.rep'), JSON_UNESCAPED_UNICODE) !!}, 'k-royal'],
        ['in_zone', {!! json_encode(__('journey.in_zone'), JSON_UNESCAPED_UNICODE) !!}, 'k-green'],
        ['out_zone', {!! json_encode(__('journey.out_zone'), JSON_UNESCAPED_UNICODE) !!}, 'k-red'],
        ['idle', {!! json_encode(__('journey.stopped'), JSON_UNESCAPED_UNICODE) !!}, 'k-orange'],
        ['units', {!! json_encode(__('journey.units_in_custody'), JSON_UNESCAPED_UNICODE) !!}, 'k-purple'],
        ['value', {!! json_encode(__('journey.stock_value'), JSON_UNESCAPED_UNICODE) !!}, 'k-sky'],
        ['sales', {!! json_encode(__('journey.sales_today'), JSON_UNESCAPED_UNICODE) !!}, 'k-green'],
    ],
    covered: {!! json_encode(__('journey.covered_zone'), JSON_UNESCAPED_UNICODE) !!},
    target: {!! json_encode(__('journey.target_zone'), JSON_UNESCAPED_UNICODE) !!},
    activeN: {!! json_encode(__('journey.active_clients_n'), JSON_UNESCAPED_UNICODE) !!},
    potentialN: {!! json_encode(__('journey.potential_n'), JSON_UNESCAPED_UNICODE) !!},
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
    rtOn: {!! json_encode(__('journey.realtime_on'), JSON_UNESCAPED_UNICODE) !!},
    rtOff: {!! json_encode(__('journey.realtime_off'), JSON_UNESCAPED_UNICODE) !!},
};

const SC = { visit: '#9D6FE0', moving: '#2EDE8B', idle: '#FFB020', off: '#5A5F85' };
const AV = ['#4D6FE3','#9D6FE0','#2EDE8B','#FFB020','#FF5D73','#38BDF8','#F472B6','#A3E635'];
const fmt = n => Number(n || 0).toLocaleString('en-US', { maximumFractionDigits: 0 });

/* ⚠️ **إجباري قبل أي نص داتا في innerHTML.** أسماء العملاء والمناديب
   بتتكتب بإيد المستخدم، واسم فيه `<` كان بيكسّر الفيد أو أسوأ. */
const esc = s => String(s ?? '').replace(/[&<>"']/g,
    c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

/* ═════ الخريطة الداكنة ═════ */
const map = L.map('lvMap', { zoomControl: true }).setView([30.05, 31.25], 11);
L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
    attribution: '© OpenStreetMap © CARTO', maxZoom: 19,
}).addTo(map);

function drawZones() {
    zoneShapes.forEach(s => map.removeLayer(s));
    zoneShapes.length = 0;

    // مغطي = أخضر ثابت · مستهدف = برتقالي متقطع — كل نوع بطبقته
    (data.zones || []).forEach(z => {
        const covered = z.kind === 'covered';
        if (covered && !layers.covered) return;
        if (!covered && !layers.target) return;
        const c = L.circle([z.lat, z.lng], {
            radius: 2500,
            color: covered ? '#22C55E' : '#F59E0B',
            weight: covered ? 1.6 : 1.3,
            dashArray: covered ? null : '5 8',
            fillColor: covered ? '#22C55E' : '#F59E0B',
            fillOpacity: covered ? .08 : .05,
        }).addTo(map);

        const sub = covered
            ? T.activeN.replace(':count', z.active)
            : T.potentialN.replace(':count', z.potential);
        const lbl = L.marker([z.lat, z.lng], {
            icon: L.divIcon({
                className: 'lv-zone-label',
                html: `<div style="text-align:center">${z.name}<br>
                        <span style="font-size:9px;color:${covered ? '#4ADE80' : '#FBBF24'}">${covered ? '● ' + T.covered : '◌ ' + T.target} · ${sub}</span></div>`,
                iconSize: null,
            }),
            interactive: false,
        }).addTo(map);
        zoneShapes.push(c, lbl);
    });
}

/* طبقة المحافظات — اسم كبير متوهج + عدد العملاء الشغالين */
function drawGovs() {
    govShapes.forEach(s => map.removeLayer(s));
    govShapes.length = 0;
    if (!layers.govs) return;

    (data.governorates || []).forEach(g => {
        const m = L.marker([g.lat, g.lng], {
            icon: L.divIcon({
                className: 'lv-gov-label',
                html: `<div style="text-align:center">${g.name}${g.clients > 0
                    ? `<br><span style="font-size:9.5px;font-weight:600;color:#BAE6FD">${fmt(g.clients)} ●</span>` : ''}</div>`,
                iconSize: null,
            }),
            interactive: false,
        }).addTo(map);
        govShapes.push(m);
    });
}

/* شريط الحركة — كل مندوب سهم بورصة: أخضر بيبيع، رمادي ساكن */
function renderTape() {
    const items = (data.reps || []).map(r => {
        const up = r.sales > 0;
        return `<span class="lv-tk"><span class="sym">${r.name}</span>
            <span class="${up ? 'up' : 'dn'}">${up ? '▲' : '—'} ${fmt(r.sales)}</span>
            <span class="lv-dim">${r.done}/${r.planned}</span></span>`;
    }).join('');
    // المحتوى مكرر — التيكر بيلف نصه فبيبان متواصل
    document.getElementById('lvTape').innerHTML = items + items;
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
const KICONS = { reps: '🧑‍💼', in_zone: '🎯', out_zone: '⚠️', idle: '🛑', units: '📦', value: '🚚', sales: '💰' };

function render() {
    // KPIs — أيقونة بهالة + رقم بلونه الدال
    document.getElementById('lvKpis').innerHTML = T.kpis.map(([k, l, cls]) =>
        `<div class="lv-kpi ${cls}">
            <div class="ic">${KICONS[k] || '•'}</div>
            <div><div class="v">${fmt(data.totals[k])}</div><div class="l">${l}</div></div>
        </div>`).join('');

    renderTape();

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

    // الماركرز — بتتحرك مش بتتمسح، وطبقة المناديب ممكن تتقفل
    const seen = new Set();
    (data.reps || []).forEach(r => {
        if (r.lat === null || !layers.reps) return;
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
        /* ⚠️ اللون والأيقونة جايين **من السيرفر** مش من كلاس CSS
           (2026-08-07). كانت `.a-sale/.a-checkin/.a-checkout` تلات
           كلاسات مكتوبة بالإيد، فأي نوع حدث جديد (مرتجع، هدية،
           استلام عهدة) بيطلع بلا لون ومحدش واخد باله إنه ناقص.
           دلوقتي `TrackEvent::TYPES` هو المصدر الوحيد للاتنين. */
        ? list.map(a => `<div class="lv-alert" style="border-color:${a.color}">
             <span class="tm">${a.t}</span>
             <span class="ic">${a.icon || ''}</span>
             <span class="rp">${esc(a.rep)}</span>
             <span>${esc(a.text)}</span></div>`).join('')
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

// شيك بوكسات الطبقات — بترسم فوراً وبتتحفظ في المتصفح
document.querySelectorAll('.lv-layer input').forEach(cb => {
    cb.checked = !!layers[cb.dataset.layer];
    cb.addEventListener('change', () => {
        layers[cb.dataset.layer] = cb.checked;
        try { localStorage.setItem('lvLayers', JSON.stringify(layers)); } catch (e) {}
        drawZones();
        drawGovs();
        render();
    });
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
    // ⚠️ «إيقاف مؤقت» لازم يقطع التدفق نفسه مش يوقف البولينج بس —
    // وإلا العملية على السيرفر فاضلة شغالة والشاشة بتتحدث برضه.
    if (paused) { stopStream(); stopPolling(); setMode(false); } else { startLive(); }
});

/* ═════ الساعة ═════ */
setInterval(() => {
    document.getElementById('lvClock').textContent = new Date().toLocaleTimeString('en-GB');
}, 1000);

/* ═══════════════ التحديث: تدفق لايف + فولباك بولينج ═══════════════
   التدفق (SSE) هو الأصل، والبولينج شبكة أمان. الاتنين عمرهم ما
   يشتغلوا مع بعض: أول حمولة توصل من التدفق البولينج بيقف. */
const LV_STREAM = {!! json_encode(route('ops.live.stream')) !!};
const LV_DATA = {!! json_encode(route('ops.live.data')) !!};

let es = null, pollTimer = null, retryTimer = null, retryMs = 5000;

function setMode(on) {
    const el = document.getElementById('lvMode');
    el.textContent = on ? T.rtOn : T.rtOff;
    el.classList.toggle('on', on);
}

function apply(payload) {
    data = payload;
    drawZones();
    drawGovs();
    render();
}

async function refresh() {
    if (paused || document.hidden) return;
    try {
        const res = await fetch(LV_DATA, { headers: { Accept: 'application/json' } });
        if (!res.ok) return;
        apply(await res.json());
    } catch (e) { /* الشبكة وقعت — المحاولة الجاية بعد 15 ثانية */ }
}

function startPolling() {
    if (pollTimer !== null || paused) return;
    pollTimer = setInterval(refresh, 15000);
    setMode(false);
}

function stopPolling() {
    if (pollTimer !== null) { clearInterval(pollTimer); pollTimer = null; }
}

function stopStream() {
    if (retryTimer !== null) { clearTimeout(retryTimer); retryTimer = null; }
    if (es !== null) { try { es.close(); } catch (e) {} es = null; }
}

function retryStream(ms) {
    if (retryTimer !== null) clearTimeout(retryTimer);
    retryTimer = setTimeout(function () { retryTimer = null; startStream(); }, ms);
}

function startStream() {
    // ⚠️ اتصال واحد بس — اتنين معناهم عمليتين PHP لنفس الشاشة
    if (es !== null || paused || document.hidden) return;
    if (typeof window.EventSource !== 'function') { startPolling(); return; }

    let got = false, src;
    try { src = new EventSource(LV_STREAM); } catch (e) { startPolling(); return; }
    es = src;

    src.onmessage = function (e) {
        let payload;
        try { payload = JSON.parse(e.data); } catch (err) { return; }
        got = true;
        retryMs = 5000;
        stopPolling();          // التدفق شغال — البولينج مالوش لزمة
        setMode(true);
        if (!paused && !document.hidden) apply(payload);
    };

    src.onerror = function () {
        stopStream();
        // اتصال جاب داتا وقفل = سقف المدة في السيرفر، بنفتح واحد جديد
        // على طول. اتصال مجابش حاجة أصلاً = التدفق مش شغال على
        // الاستضافة دي، فبنرجع للبولينج ونجرب تاني بعد فترة بتطول.
        if (got) { retryStream(800); return; }
        setMode(false);
        startPolling();
        retryStream(retryMs);
        retryMs = Math.min(retryMs * 2, 300000);
    };
}

function startLive() {
    if (paused || document.hidden) return;
    startPolling();   // شبكة أمان لحد ما أول حمولة توصل من التدفق
    startStream();
}

// ⚠️ تاب متسيّب في الخلفية كان هيفضل ماسك عملية على السيرفر —
// بنقفل الاتصال لما الشاشة تختفي وبنفتحه لما ترجع.
document.addEventListener('visibilitychange', function () {
    if (document.hidden) { stopStream(); stopPolling(); setMode(false); return; }
    refresh();      // رسمة فورية بدل ما نستنى أول حمولة
    startLive();
});

/* أول رسمة من الحمولة المدمجة */
drawZones();
drawGovs();
render();

/* وبعدها التدفق يمسك الشاشة */
startLive();

const first = (data.reps || []).find(r => r.lat !== null);
if (first) {
    map.setView([first.lat, first.lng], 12);
    selectRep(first.id);
}
})();
</script>
@endsection
