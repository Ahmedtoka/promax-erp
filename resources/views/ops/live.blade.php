@extends('layouts.system')

@section('title', __('journey.control_room'))

{{-- ═══════════════════════════════════════════════════════════════
     غرفة تحكم المناديب — نسخة شاشة التلفزيون (١٢ أغسطس ٢٠٢٦) بطلب المالك:
     «أوضح» — خط أكبر وتباين أعلى على 1080p والخريطة في النص.
     «الحضور جوه الشاشة» — KPI مندوبين/مديرين في الشارع + أوفلاين،
     وكل كارت فيه شغال من / انصرف / مش مسجل.
     «جريد مش قايمة» — بانل الأشخاص قسمين: المديرين ثم المناديب، كروت
     مدمجة فيها الحالة بمدتها وقيمة العهدة والمبيعات وآخر إشارة.
     «مفيش إشارة مش مفهومة» — آخر إشارة h:i A ومن قد إيه، أو مفيش
     إشارة النهارده، أو مش مسجل حضور — والمنصرف أوفلاين مش «مفيش إشارة».
     «واقف بقاله قد إيه» — كل حالة بمدتها من live_state/live_min.
     «الزيارة واضحة جداً» — كارت وماركر اللي في زيارة بينبضوا بنفسجي.
     «البار المتحرك تحت خالص» — التيكر fixed أسفل الشاشة.
     «دوس على الشخص» — بوب أب بكل بياناته + آخر ٥ أحداث + زرار
     التراكينج بيفتح في تاب جديد.
     «أسرع» — نفس الـSSE كل ٣ ثواني، الماركرز بتنزلق (lerp ~2ث) بدل
     القفز، وفولباك البولينج اتشد لـ10 ثواني.
     كل الأوقات h:i A بتوقيت القاهرة جاهزة من السيرفر — مفيش JS Date
     parsing لنصوص UTC نهائياً.
     ⚠️ الداتا كلها من `livePayload` — أول رسمة والتحديث نفس المصدر،
     وكل المفاتيح الجديدة additive فالصفحة القديمة المتكاشة ماتقعش. --}}

@section('actions')
    <a class="btn" href="{{ route('ops.journeys') }}">🗓️ {{ __('journey.page') }}</a>
    <a class="btn" href="{{ route('ops.tracking') }}">📍 {{ __('nav.tracking') }}</a>
@endsection

@section('content')

@php $isRtl = app()->getLocale() === 'ar'; @endphp

<div id="lvRoom" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">

    {{-- ═════ الهيدر: عنوان + ساعة القاهرة + مؤشر التحديث ═════ --}}
    <div class="lv-top">
        <div class="lv-title">
            <span class="lv-live-dot"></span>
            <b>{{ __('journey.control_room') }}</b>
            <span class="lv-clock" id="lvClock">--:--:--</span>
            <span class="lv-mode" id="lvMode">{{ __('journey.realtime_off') }}</span>
        </div>
        <div>
            <button class="lv-btn" id="lvFollowBtn">🎯 {{ __('journey.follow_rep') }}</button>
            <button class="lv-btn" id="lvPauseBtn">⏸ {{ __('journey.pause_updates') }}</button>
        </div>
    </div>

    {{-- ═════ شريط الـKPI — الحضور والفلوس مجاميع الفريق ═════ --}}
    <div class="lv-kpis" id="lvKpis"></div>

    <div class="lv-grid">

        {{-- ═════ بانل الأشخاص — جريد: المديرين ثم المناديب ═════ --}}
        <aside class="lv-side">
            <div class="lv-side-head">
                <input type="text" id="lvSearch" class="lv-search" placeholder="{{ __('journey.search_rep') }}">
                <span id="lvSideCount" class="lv-dim"></span>
            </div>
            <div class="lv-chips" id="lvChips">
                <button class="lv-chip on" data-f="">{{ __('common.all') }}</button>
                <button class="lv-chip" data-f="visit">{{ __('journey.in_visit_now') }}</button>
                <button class="lv-chip" data-f="moving">{{ __('journey.moving_now') }}</button>
                <button class="lv-chip" data-f="standing">{{ __('journey.idle') }}</button>
                <button class="lv-chip" data-f="nosignal">{{ __('journey.no_signal_today') }}</button>
                <button class="lv-chip" data-f="off">{{ __('journey.offline_chip') }}</button>
            </div>
            <div class="lv-peoplewrap" id="lvPeople"></div>
        </aside>

        {{-- ═════ الخريطة — نجمة الشاشة ═════ --}}
        <div class="lv-maparea">
            <div class="lv-mapbar">
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
            </div>
            <div id="lvMap"></div>
            <div class="lv-legend">
                <span><i class="dot d-visit"></i>{{ __('journey.in_visit_now') }}</span>
                <span><i class="dot d-moving"></i>{{ __('journey.moving_now') }}</span>
                <span><i class="dot d-idle"></i>{{ __('journey.idle') }}</span>
                <span><i class="dot d-off"></i>{{ __('journey.offline_chip') }}</span>
                <span style="border-inline-start:1px solid var(--line);padding-inline-start:12px">
                    <i class="dot" style="background:var(--green)"></i>{{ __('journey.covered_zone') }}</span>
                <span><i class="dot" style="background:var(--orange)"></i>{{ __('journey.target_zone') }}</span>
            </div>
        </div>

        {{-- ═════ التايم لاين — أوقات h:i A من السيرفر ═════ --}}
        <aside class="lv-detail">
            <div class="lv-card lv-tl">
                <div class="lv-card-h">🔔 {{ __('journey.alerts_feed') }}
                    <span id="lvAlertScope"></span></div>
                <div class="lv-alerts" id="lvAlerts"></div>
            </div>
        </aside>
    </div>

    {{-- ═════ التيكر — تحت خالص، fixed (طلب المالك ١٢/٨) ═════ --}}
    <div class="lv-tape"><div class="lv-tape-track" id="lvTape"></div></div>

    {{-- ═════ بوب أب الشخص — بكل بياناته، بيفضل بيتحدث مع كل حمولة ═════ --}}
    <div class="lv-ovl" id="lvOvl" hidden>
        <div class="lv-pop" id="lvPop"></div>
    </div>
</div>

<style>
/* ═════ ثيم غرفة التحكم — TV grade: خط أكبر وتباين أعلى ═════
   الأساس نيلي معتم من Royal Blue #12399B، والأكسنتات نسخ مفتحة من
   ألوان البراند: royal ← #5B7BE8، purple heart ← #9B6BDB. */
#lvRoom{
    --bg:#080C1E; --panel:#101635; --panel2:#171E45; --line:#2A3468;
    --txt:#F2F4FD; --dim:#9AA3D0;
    --royal:#5B7BE8; --purple:#9B6BDB; --green:#22C55E; --orange:#F59E0B; --red:#F43F5E;
    --gray:#5A6190; --sky:#38BDF8;
    --grad:linear-gradient(90deg,#12399B,#602D90);
    --grad-lite:linear-gradient(90deg,#5B7BE8,#9B6BDB);
    background:
        radial-gradient(1100px 500px at 85% -10%, rgba(18,57,155,.28), transparent 60%),
        radial-gradient(900px 450px at 10% 110%, rgba(96,45,144,.22), transparent 60%),
        var(--bg);
    color:var(--txt);
    margin:-18px -24px -40px; padding:12px 18px 64px; min-height:calc(100vh - 60px);
    font-variant-numeric:tabular-nums; font-size:13.5px;
}
#lvRoom *{box-sizing:border-box}
#lvRoom ::-webkit-scrollbar{width:8px;height:8px}
#lvRoom ::-webkit-scrollbar-thumb{background:var(--line);border-radius:99px}
.lv-top{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:10px}
.lv-title{display:flex;align-items:center;gap:12px;font-size:19px}
.lv-clock{font-size:16px;color:var(--txt);direction:ltr;letter-spacing:1px;background:var(--panel);border:1px solid var(--line);border-radius:8px;padding:2px 10px}
.lv-live-dot{width:10px;height:10px;border-radius:50%;background:var(--green);box-shadow:0 0 10px var(--green);animation:lvBlink 1.4s infinite}
.lv-mode{font-size:11px;border-radius:999px;padding:2px 10px;background:var(--panel2);border:1px solid var(--line);color:var(--dim);white-space:nowrap}
.lv-mode.on{color:#4ADE80;border-color:rgba(34,197,94,.45);background:rgba(34,197,94,.12)}

/* KPIs — كبيرة تتقري من آخر الأوضة */
.lv-kpis{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px}
.lv-kpi{
    position:relative;background:linear-gradient(180deg, rgba(255,255,255,.03), transparent), var(--panel);
    border:1px solid var(--line);border-radius:12px;
    padding:10px 14px 9px;min-width:120px;overflow:hidden;flex:1 1 120px;
    display:flex;align-items:center;gap:10px;
    transition:transform .18s, border-color .18s;
}
.lv-kpi::before{content:'';position:absolute;top:0;left:0;right:0;height:2.5px;background:var(--kc,var(--grad-lite))}
.lv-kpi .ic{
    width:34px;height:34px;border-radius:9px;flex-shrink:0;
    display:flex;align-items:center;justify-content:center;font-size:16px;
    background:color-mix(in srgb, var(--kc) 16%, transparent);
    box-shadow:inset 0 0 0 1px color-mix(in srgb, var(--kc) 35%, transparent);
}
.lv-kpi .v{font-size:21px;font-weight:800;color:var(--kc-b,var(--txt));letter-spacing:.3px;line-height:1.1}
.lv-kpi .l{font-size:10.5px;color:var(--dim);white-space:nowrap;margin-top:1px}
.k-royal{--kc:#5B7BE8;--kc-b:#8FA5F0} .k-green{--kc:#22C55E;--kc-b:#4ADE80}
.k-red{--kc:#F43F5E;--kc-b:#FB7185}  .k-orange{--kc:#F59E0B;--kc-b:#FBBF24}
.k-purple{--kc:#9B6BDB;--kc-b:#B794E8} .k-sky{--kc:#38BDF8;--kc-b:#7DD3FC}

/* طبقات الخريطة */
.lv-layers{display:flex;align-items:center;gap:10px;background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:5px 12px;flex-wrap:wrap}
.lv-layers-t{font-size:11px;color:var(--dim)}
.lv-layer{display:flex;align-items:center;gap:5px;font-size:12px;cursor:pointer;user-select:none}
.lv-layer input{accent-color:var(--royal);width:14px;height:14px;cursor:pointer}
.lv-layer i{width:8px;height:8px;border-radius:50%;display:inline-block}
.lv-gov-label{background:transparent;border:0;box-shadow:none;color:#7DD3FC;font-size:13px;font-weight:800;letter-spacing:1px;white-space:nowrap;text-shadow:0 0 12px rgba(56,189,248,.5)}

/* التيكر — تحت خالص (fixed) بطلب المالك */
.lv-tape{position:fixed;bottom:0;inset-inline:0;z-index:70;background:#0B102A;border-top:1px solid var(--line);overflow:hidden}
.lv-tape::before,.lv-tape::after{content:'';position:absolute;top:0;bottom:0;width:46px;z-index:2;pointer-events:none}
.lv-tape::before{inset-inline-start:0;background:linear-gradient(to left,transparent,#0B102A)}
.lv-tape::after{inset-inline-end:0;background:linear-gradient(to right,transparent,#0B102A)}
.lv-tape-track{display:inline-flex;gap:38px;white-space:nowrap;padding:9px 0;animation:lvTape 40s linear infinite;will-change:transform}
.lv-tape:hover .lv-tape-track{animation-play-state:paused}
.lv-tk{font-size:13px;display:inline-flex;gap:8px;align-items:center}
.lv-tk .sym{font-weight:700}
.lv-tk .up{color:var(--green)} .lv-tk .dn{color:var(--dim)}
@keyframes lvTape{0%{transform:translateX(0)}100%{transform:translateX({{ $isRtl ? '' : '-' }}50%)}}

/* عمود التنبيهات اتوسّع على حساب الخريطة شوية (طلب المالك ١٢/٨) */
.lv-grid{display:grid;grid-template-columns:430px 1fr 360px;gap:12px;align-items:start}
@media(max-width:1500px){.lv-grid{grid-template-columns:380px 1fr 320px}}
@media(max-width:1200px){.lv-grid{grid-template-columns:1fr}.lv-side,.lv-detail{max-height:none}}

/* بانل الأشخاص — جريد كروت بدل القايمة الطويلة */
.lv-side{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:10px;max-height:calc(100vh - 235px);display:flex;flex-direction:column}
.lv-side-head{display:flex;gap:8px;align-items:center;margin-bottom:8px}
.lv-search{flex:1;background:var(--panel2);border:1px solid var(--line);border-radius:8px;color:var(--txt);padding:7px 10px;font-size:12.5px}
.lv-search::placeholder{color:var(--dim)}
.lv-chips{display:flex;gap:5px;flex-wrap:wrap;margin-bottom:8px}
.lv-chip{background:var(--panel2);border:1px solid var(--line);color:var(--dim);border-radius:999px;padding:3px 10px;font-size:11px;cursor:pointer}
.lv-chip.on{background:var(--royal);border-color:var(--royal);color:#fff}
.lv-peoplewrap{overflow-y:auto;flex:1}
.lv-sec-h{display:flex;justify-content:space-between;align-items:center;font-size:12.5px;font-weight:800;color:var(--dim);letter-spacing:.5px;margin:8px 2px 6px;text-transform:uppercase}
.lv-sec-h:first-child{margin-top:0}
.lv-people{display:grid;grid-template-columns:1fr 1fr;gap:7px}
@media(max-width:1500px){.lv-people{grid-template-columns:1fr}}
.lv-p{background:var(--panel2);border:1px solid var(--line);border-radius:10px;padding:8px 9px;cursor:pointer;transition:border-color .15s, transform .15s;position:relative;overflow:hidden}
.lv-p:hover{border-color:var(--royal);transform:translateY(-1px)}
.lv-p.sel{border-color:var(--royal);box-shadow:0 0 0 1px var(--royal)}
/* الزيارة واضحة جداً — نبضة بنفسجية على الكارت كله */
.lv-p.visiting{border-color:var(--purple);animation:lvVisitCard 1.6s ease-in-out infinite}
@keyframes lvVisitCard{0%,100%{box-shadow:0 0 0 1px var(--purple)}50%{box-shadow:0 0 0 3px rgba(155,107,219,.55), 0 0 22px rgba(155,107,219,.35)}}
.lv-p .r1{display:flex;gap:8px;align-items:center}
.lv-avatar{display:inline-block;width:36px;height:36px;border-radius:50%;flex-shrink:0;border:2px solid;overflow:hidden;background:#fff}
.lv-avatar img{width:100%;height:100%;object-fit:cover;border-radius:50%;display:block}
.lv-avatar span{display:flex;width:100%;height:100%;align-items:center;justify-content:center;font-size:13px;font-weight:800;color:#fff;border-radius:50%}
/* الاسم لوحده جنب الصورة — ياخد عرض الكارت كله، والشارة سطر منفصل (١٢/٨) */
.lv-p .nm{font-size:13.5px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.lv-p .r2{margin-top:5px}
.lv-p .zn{font-size:10.5px;color:var(--dim);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:120px}
.lv-status{font-size:10.5px;border-radius:999px;padding:2px 8px;white-space:nowrap;flex-shrink:0;display:inline-block}
.s-visit{background:rgba(157,111,224,.2);color:#C6A9F2}
.s-moving{background:rgba(46,222,139,.16);color:#4ADE80}
.s-standing{background:rgba(255,176,32,.16);color:#FBBF24}
.s-nosignal{background:rgba(56,189,248,.14);color:#7DD3FC}
.s-off{background:rgba(90,95,133,.3);color:var(--dim)}
.lv-p .ln{font-size:11px;color:var(--dim);margin-top:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.lv-p .ln b{color:var(--txt);font-weight:700}
.lv-p .ln.vis{color:#C6A9F2;font-weight:700;white-space:normal}
.lv-p .nums{display:flex;gap:10px;font-size:11.5px;color:var(--dim);margin-top:5px}
.lv-p .nums b{color:var(--txt)} .lv-p .nums .up b{color:var(--green)}

/* الخريطة */
.lv-maparea{position:relative}
.lv-mapbar{display:flex;gap:7px;margin-bottom:8px;flex-wrap:wrap}
.lv-btn{background:var(--panel);border:1px solid var(--line);color:var(--txt);border-radius:8px;padding:6px 12px;font-size:12px;cursor:pointer}
.lv-btn.on{background:var(--royal);border-color:var(--royal)}
.lv-btn.sm{padding:2px 9px;font-size:10.5px;border-radius:999px}
#lvMap{height:calc(100vh - 320px);min-height:430px;border-radius:12px;border:1px solid var(--line);background:var(--panel)}
.lv-legend{display:flex;gap:14px;justify-content:flex-end;margin-top:7px;font-size:11px;color:var(--dim)}
.lv-legend .dot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-inline-end:4px}
.d-visit{background:var(--purple)} .d-moving{background:var(--green)} .d-idle{background:var(--orange)} .d-off{background:var(--gray)}

/* التايم لاين */
.lv-detail{display:flex;flex-direction:column;gap:12px;max-height:calc(100vh - 235px)}
.lv-card{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:12px;position:relative;overflow:hidden}
.lv-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2.5px;background:var(--grad)}
.lv-card-h{font-size:14px;font-weight:700;margin-bottom:9px;display:flex;justify-content:space-between;align-items:center;gap:6px}
.lv-dim{color:var(--dim);font-size:11px;font-weight:400}
.lv-tl{display:flex;flex-direction:column;min-height:0;flex:1}
/* التنبيهات زي الرسايل (طلب المالك ١٢/٨): الاسم فوق، الوقت تحته،
   ونص التنبيه سطر لوحده — أسهل في القراية من السطر الواحد المزنوق */
.lv-alerts{display:flex;flex-direction:column;gap:9px;overflow-y:auto;flex:1;min-height:0}
.lv-alert{
  display:flex;flex-direction:column;gap:3px;font-size:12.5px;line-height:1.55;
  border-inline-start:3px solid var(--line);padding:7px 10px;
  background:rgba(255,255,255,.03);border-radius:0 10px 10px 0;
}
[dir=rtl] .lv-alert{border-radius:10px 0 0 10px}
.lv-alert .hd{display:flex;align-items:center;gap:6px}
.lv-alert .ic{font-size:13px;line-height:1}
.lv-alert .rp{color:#E8EAF6;font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.lv-alert .tm{color:var(--dim);font-size:10.5px;direction:ltr;white-space:nowrap;margin-inline-start:auto}
.lv-alert .tx{color:#B9BEDC}

/* الماركرز — صورة الموظف بإطار بلون حالته */
.lv-marker{position:relative;width:40px;height:40px}
.lv-marker .pic{width:36px;height:36px;border-radius:50%;border:2.5px solid;overflow:hidden;background:#fff;position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);z-index:2;box-shadow:0 1px 6px rgba(0,0,0,.5)}
.lv-marker .pic img{width:100%;height:100%;object-fit:cover;border-radius:50%;display:block}
.lv-marker .pic span{display:flex;width:100%;height:100%;align-items:center;justify-content:center;font-size:13px;font-weight:900;color:#fff;border-radius:50%}
.lv-marker .ring{width:48px;height:48px;border-radius:50%;position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);opacity:.5}
.lv-marker.mv .ring{animation:lvPing 1.6s ease-out infinite}
.lv-marker.vs .ring{animation:lvPing 1s ease-out infinite}
.lv-marker .tag{position:absolute;top:-18px;left:50%;transform:translateX(-50%);background:rgba(13,16,34,.88);color:#E8EAF6;font-size:10.5px;padding:1px 8px;border-radius:99px;white-space:nowrap;border:1px solid #262C55;z-index:3}
@keyframes lvPing{0%{transform:translate(-50%,-50%) scale(.5);opacity:.7}100%{transform:translate(-50%,-50%) scale(1.8);opacity:0}}
@keyframes lvBlink{0%,100%{opacity:1}50%{opacity:.35}}
.lv-zone-label{background:transparent;border:0;box-shadow:none;color:#8B90B5;font-size:11px;font-weight:700;white-space:nowrap}
.leaflet-container{background:#0D1022}

/* البوب أب */
/* ⚠️ z-index أعلى من طبقات Leaflet (اللي بتوصل ~700) — البوب أب كان
   بيطلع ورا الخريطة (١٢/٨) */
.lv-ovl{position:fixed;inset:0;z-index:1200;background:rgba(4,7,20,.66);backdrop-filter:blur(3px);display:flex;align-items:center;justify-content:center;padding:20px}
/* ⚠️ display:flex بتاعتنا بتغلب [hidden] بتاعة المتصفح — من غير
   السطر ده الأوفرلاي بيفضل ظاهر على طول (نفس فخ ssel-panel الموثّق) */
.lv-ovl[hidden]{display:none}
.lv-pop{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:16px;width:min(520px, 94vw);max-height:88vh;overflow-y:auto;position:relative;box-shadow:0 20px 60px rgba(0,0,0,.6);animation:lvPopIn .18s ease-out}
@keyframes lvPopIn{0%{transform:scale(.95);opacity:0}100%{transform:scale(1);opacity:1}}
.lv-pop::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--grad)}
.lv-pop .x{position:absolute;top:10px;inset-inline-end:12px;background:var(--panel2);border:1px solid var(--line);color:var(--txt);border-radius:8px;width:30px;height:30px;font-size:15px;cursor:pointer}
.lv-pop .head{display:flex;gap:10px;align-items:center;margin-bottom:10px}
.lv-pop .head .nm{font-size:17px;font-weight:800}
.lv-pop .stateline{font-size:14px;font-weight:700;margin:4px 0 8px}
.lv-pop .stateline.vis{color:#C6A9F2}
.lv-pop .metaline{font-size:12.5px;color:var(--dim);margin-bottom:4px}
.lv-pop .metaline b{color:var(--txt)}
.lv-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin:10px 0}
.lv-stat{background:var(--panel2);border-radius:8px;padding:7px 4px;text-align:center}
.lv-stat .v{font-size:15px;font-weight:700}
.lv-stat .l{font-size:9.5px;color:var(--dim)}
.lv-item{margin-bottom:9px}
.lv-item .r1{display:flex;justify-content:space-between;font-size:11.5px;margin-bottom:3px}
.lv-item .bar{height:5px;background:var(--panel2);border-radius:99px;overflow:hidden}
.lv-item .bar i{display:block;height:100%;border-radius:99px}
.lv-pop .evts{display:flex;flex-direction:column;gap:6px;margin-top:6px}
.lv-pop .evt{display:flex;gap:8px;font-size:12px;border-inline-start:2px solid var(--line);padding-inline-start:8px}
.lv-pop .evt .tm{color:var(--dim);font-size:11px;direction:ltr;white-space:nowrap}
.lv-pop .foot{display:flex;gap:8px;margin-top:12px}
.lv-pop .foot a{flex:1;text-align:center;text-decoration:none;background:var(--grad);color:#fff;border-radius:9px;padding:9px 10px;font-size:13px;font-weight:700}
.lv-pop .foot a.alt{background:var(--panel2);border:1px solid var(--line);color:var(--txt)}
</style>
@endsection

@section('scripts')
<script>
(function () {
'use strict';

/* ═════ الحالة ═════
   ⚠️ فلاجات الـHEX إجبارية: أسماء المناديب والعملاء وعناوين الأحداث
   بتتكتب بإيد المستخدم — تاج قفل سكريبت جوه اسم كان بيقفل البلوك كله.
   (وممنوع كتابة التاج نفسه هنا حتى في تعليق — البراوزر بيقص عنده
   أياً كان مكانه، وده بالظبط اللي كسر الصفحة ١٢/٨) */
let data = {!! json_encode($initial, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) !!};
let selectedId = null, followId = null, paused = false, filter = '', search = '';
let popupId = null;
const markers = {}, zoneShapes = [], govShapes = [];
let trackLine = null, trackStart = null;

// طبقات الخريطة — الديفولت المناديب بس، والاختيار محفوظ في المتصفح
let layers = { reps: true, covered: false, target: false, govs: false };
try {
    const saved = JSON.parse(localStorage.getItem('lvLayers') || 'null');
    if (saved && typeof saved === 'object') layers = Object.assign(layers, saved);
} catch (e) { /* تخزين بايظ — الديفولت */ }

const T = {
    statuses: {
        visit: {!! json_encode(__('journey.in_visit_now'), JSON_UNESCAPED_UNICODE) !!},
        moving: {!! json_encode(__('journey.moving_now'), JSON_UNESCAPED_UNICODE) !!},
        standing: {!! json_encode(__('journey.idle'), JSON_UNESCAPED_UNICODE) !!},
        nosignal: {!! json_encode(__('journey.no_signal_today'), JSON_UNESCAPED_UNICODE) !!},
        off: {!! json_encode(__('journey.offline_chip'), JSON_UNESCAPED_UNICODE) !!},
    },
    work: {
        working: {!! json_encode(__('hr.state_working'), JSON_UNESCAPED_UNICODE) !!},
        break: {!! json_encode(__('hr.state_break'), JSON_UNESCAPED_UNICODE) !!},
        off: {!! json_encode(__('hr.state_off'), JSON_UNESCAPED_UNICODE) !!},
    },
    kpis: [
        ['reps_on', {!! json_encode(__('journey.kpi_reps_street'), JSON_UNESCAPED_UNICODE) !!}, 'k-green', '🧑‍💼'],
        ['managers_on', {!! json_encode(__('journey.kpi_managers_street'), JSON_UNESCAPED_UNICODE) !!}, 'k-purple', '👔'],
        ['offline_n', {!! json_encode(__('journey.kpi_offline'), JSON_UNESCAPED_UNICODE) !!}, 'k-red', '📴'],
        ['sales', {!! json_encode(__('journey.sales_today'), JSON_UNESCAPED_UNICODE) !!}, 'k-green', '💰'],
        ['value', {!! json_encode(__('journey.stock_value'), JSON_UNESCAPED_UNICODE) !!}, 'k-sky', '🚚'],
        ['units', {!! json_encode(__('journey.units_in_custody'), JSON_UNESCAPED_UNICODE) !!}, 'k-purple', '📦'],
        ['visits', {!! json_encode(__('journey.visits_today'), JSON_UNESCAPED_UNICODE) !!}, 'k-orange', '📍'],
        ['pos', {!! json_encode(__('journey.pos_delivered_today'), JSON_UNESCAPED_UNICODE) !!}, 'k-royal', '🧾'],
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
    visitsT: {!! json_encode(__('journey.visits_today'), JSON_UNESCAPED_UNICODE) !!},
    posT: {!! json_encode(__('journey.pos_delivered_today'), JSON_UNESCAPED_UNICODE) !!},
    done: {!! json_encode(__('journey.done'), JSON_UNESCAPED_UNICODE) !!},
    custody: {!! json_encode(__('journey.custody_panel'), JSON_UNESCAPED_UNICODE) !!},
    sold: {!! json_encode(__('journey.sold_label'), JSON_UNESCAPED_UNICODE) !!},
    left: {!! json_encode(__('journey.remaining_label'), JSON_UNESCAPED_UNICODE) !!},
    remTotal: {!! json_encode(__('journey.remaining_total'), JSON_UNESCAPED_UNICODE) !!},
    worth: {!! json_encode(__('journey.worth'), JSON_UNESCAPED_UNICODE) !!},
    latest: {!! json_encode(__('journey.alerts_latest'), JSON_UNESCAPED_UNICODE) !!},
    noAlerts: {!! json_encode(__('journey.no_alerts'), JSON_UNESCAPED_UNICODE) !!},
    noAlertsRep: {!! json_encode(__('journey.no_alerts_rep'), JSON_UNESCAPED_UNICODE) !!},
    tlFor: {!! json_encode(__('journey.timeline_for'), JSON_UNESCAPED_UNICODE) !!},
    lastSignal: {!! json_encode(__('journey.last_signal'), JSON_UNESCAPED_UNICODE) !!},
    tlAll: {!! json_encode(__('journey.timeline_all_hint'), JSON_UNESCAPED_UNICODE) !!},
    all: {!! json_encode(__('common.all'), JSON_UNESCAPED_UNICODE) !!},
    secManagers: {!! json_encode(__('journey.sec_managers'), JSON_UNESCAPED_UNICODE) !!},
    secReps: {!! json_encode(__('journey.sec_reps'), JSON_UNESCAPED_UNICODE) !!},
    standingFor: {!! json_encode(__('journey.standing_for'), JSON_UNESCAPED_UNICODE) !!},
    visitingFor: {!! json_encode(__('journey.visiting_for'), JSON_UNESCAPED_UNICODE) !!},
    signalAgo: {!! json_encode(__('journey.signal_ago'), JSON_UNESCAPED_UNICODE) !!},
    noSignalToday: {!! json_encode(__('journey.no_signal_today'), JSON_UNESCAPED_UNICODE) !!},
    notChecked: {!! json_encode(__('journey.not_checked_in'), JSON_UNESCAPED_UNICODE) !!},
    workingSince: {!! json_encode(__('journey.working_since'), JSON_UNESCAPED_UNICODE) !!},
    leftAt: {!! json_encode(__('journey.left_at'), JSON_UNESCAPED_UNICODE) !!},
    offSince: {!! json_encode(__('journey.off_since'), JSON_UNESCAPED_UNICODE) !!},
    durMin: {!! json_encode(__('journey.dur_min'), JSON_UNESCAPED_UNICODE) !!},
    durHr: {!! json_encode(__('journey.dur_hr'), JSON_UNESCAPED_UNICODE) !!},
    openTracking: {!! json_encode(__('journey.open_tracking'), JSON_UNESCAPED_UNICODE) !!},
    repDay: {!! json_encode(__('journey.rep_day'), JSON_UNESCAPED_UNICODE) !!},
    lastEvents: {!! json_encode(__('journey.last_events'), JSON_UNESCAPED_UNICODE) !!},
    noEvents: {!! json_encode(__('journey.no_events_today'), JSON_UNESCAPED_UNICODE) !!},
    statusL: {!! json_encode(__('journey.status_label'), JSON_UNESCAPED_UNICODE) !!},
    workState: {!! json_encode(__('journey.work_state'), JSON_UNESCAPED_UNICODE) !!},
    follow: '🎯 ' + {!! json_encode(__('journey.follow_rep'), JSON_UNESCAPED_UNICODE) !!},
    unfollow: '🎯 ' + {!! json_encode(__('journey.unfollow'), JSON_UNESCAPED_UNICODE) !!},
    pause: '⏸ ' + {!! json_encode(__('journey.pause_updates'), JSON_UNESCAPED_UNICODE) !!},
    resume: '▶ ' + {!! json_encode(__('journey.resume_updates'), JSON_UNESCAPED_UNICODE) !!},
    rtOn: {!! json_encode(__('journey.realtime_on'), JSON_UNESCAPED_UNICODE) !!},
    rtOff: {!! json_encode(__('journey.realtime_off'), JSON_UNESCAPED_UNICODE) !!},
};

/* ألوان الحالات الخمسة — nosignal سماوي عشان يبان إنه عطل مش انصراف */
const SC = { visit: '#9D6FE0', moving: '#2EDE8B', standing: '#FFB020', nosignal: '#38BDF8', off: '#5A5F85' };
const AV = ['#4D6FE3','#9D6FE0','#2EDE8B','#FFB020','#FF5D73','#38BDF8','#F472B6','#A3E635'];
const repColor = id => AV[Math.abs(id) % AV.length];
const fmt = n => Number(n || 0).toLocaleString('en-US', { maximumFractionDigits: 0 });

/* ⚠️ **إجباري قبل أي نص داتا في innerHTML.** أسماء العملاء والمناديب
   بتتكتب بإيد المستخدم، واسم فيه `<` كان بيكسّر الفيد أو أسوأ. */
const esc = s => String(s ?? '').replace(/[&<>"']/g,
    c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

/* ⚠️ استبدال بداتا مستخدم لازم يعدي من هنا — الوسيط التاني بتاع
   String.replace بيفسّر `$&` و`$'` لو اتبعت كنص، والفنكشن بتعطّل ده */
const sub = (tpl, key, val) => tpl.replace(key, () => val);

/* ═════ أدوات الحالة الجديدة (١٢/٨) — كل الأوقات جاهزة من السيرفر ═════ */

/* الحالة الفعلية — live_state من السيرفر، وفولباك للقديمة لو حمولة
   قديمة متكاشة وصلت (مايحصلش عملياً بس الشاشة ماتقعش) */
function effState(r) {
    if (r.live && r.live.state) return r.live.state;
    return r.status === 'idle' ? 'standing' : (r.status || 'off');
}

/* مدة بالعربي/الإنجليزي من مفاتيح اللغة — أرقام بس، مفيش Date */
function dur(m) {
    if (m === null || m === undefined) return '';
    m = Math.max(0, Math.round(m));
    if (m < 60) return T.durMin.replace(':count', m);
    return T.durHr.replace(':h', Math.floor(m / 60)).replace(':m', m % 60);
}

/* نص شيب الحالة — كل حالة بمدتها (طلب المالك: «واقف بقاله قد إيه»).
   nosignal بإشارة قديمة النهارده بيوري وقتها الفعلي، مش «مفيش إشارة». */
function stateTxt(r) {
    const s = effState(r);
    const m = r.live ? r.live.min : null;
    if (s === 'visit') return T.statuses.visit + (m !== null && m !== undefined ? ' · ' + dur(m) : '');
    if (s === 'moving') return T.statuses.moving;
    if (s === 'standing') return (m !== null && m !== undefined) ? T.standingFor.replace(':dur', dur(m)) : T.statuses.standing;
    if (s === 'nosignal') return r.signal_at ? T.lastSignal + ' ' + r.signal_at : T.statuses.nosignal;
    return (m !== null && m !== undefined) ? T.offSince.replace(':dur', dur(m)) : T.statuses.off;
}

/* سطر الحضور — شغال من / انصرف / مش مسجل حضور */
function attLine(r) {
    const a = r.att || { state: 'none', in: null, out: null };
    if (a.state === 'working' || a.state === 'break') {
        let t = '🕐 ' + T.workingSince.replace(':time', a.in || '—');
        if (a.state === 'break') t += ' · ' + T.work.break;
        return t;
    }
    if (a.state === 'out') return '🏁 ' + T.leftAt.replace(':time', a.out || '—');
    return '⛔ ' + T.notChecked;
}

/* سطر آخر إشارة — الوقت الفعلي h:i A + من قد إيه */
function signalLine(r) {
    if (r.signal_at) {
        return '📡 ' + T.signalAgo.replace(':time', r.signal_at).replace(':dur', dur(r.minutes === null ? 0 : r.minutes));
    }
    const a = r.att || {};
    if (a.state === 'working' || a.state === 'break') return '📡 ' + T.noSignalToday;
    return '';
}

/* ═════ الخريطة الداكنة ═════ */
const map = L.map('lvMap', { zoomControl: true }).setView([30.05, 31.25], 11);
L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
    attribution: '© OpenStreetMap © CARTO', maxZoom: 19,
}).addTo(map);

function drawZones() {
    zoneShapes.forEach(s => map.removeLayer(s));
    zoneShapes.length = 0;

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
                html: `<div style="text-align:center">${esc(z.name)}<br>
                        <span style="font-size:9px;color:${covered ? '#4ADE80' : '#FBBF24'}">${covered ? '● ' + T.covered : '◌ ' + T.target} · ${sub}</span></div>`,
                iconSize: null,
            }),
            interactive: false,
        }).addTo(map);
        zoneShapes.push(c, lbl);
    });
}

function drawGovs() {
    govShapes.forEach(s => map.removeLayer(s));
    govShapes.length = 0;
    if (!layers.govs) return;

    (data.governorates || []).forEach(g => {
        const m = L.marker([g.lat, g.lng], {
            icon: L.divIcon({
                className: 'lv-gov-label',
                html: `<div style="text-align:center">${esc(g.name)}${g.clients > 0
                    ? `<br><span style="font-size:9.5px;font-weight:600;color:#BAE6FD">${fmt(g.clients)} ●</span>` : ''}</div>`,
                iconSize: null,
            }),
            interactive: false,
        }).addTo(map);
        govShapes.push(m);
    });
}

/* مسار المندوب المختار — polyline بلونه، بيتحدث مع كل حمولة */
function drawTrack() {
    if (trackLine !== null) { map.removeLayer(trackLine); trackLine = null; }
    if (trackStart !== null) { map.removeLayer(trackStart); trackStart = null; }
    if (selectedId === null) return;

    const r = (data.reps || []).find(x => x.id === selectedId);
    if (!r || !Array.isArray(r.track) || r.track.length === 0) return;

    const pts = r.track.map(p => [p.lat, p.lng]);
    const color = repColor(r.id);

    if (pts.length > 1) {
        trackLine = L.polyline(pts, { color: color, weight: 4, opacity: .85 }).addTo(map);
    }
    trackStart = L.circleMarker(pts[0], {
        radius: 6, color: '#fff', weight: 2, fillColor: color, fillOpacity: 1,
    }).addTo(map);
    if (r.track[0].t) trackStart.bindTooltip(r.track[0].t, { direction: 'top' });
}

function fitTrack() {
    const r = (data.reps || []).find(x => x.id === selectedId);
    if (!r || !Array.isArray(r.track) || r.track.length === 0) return;
    const pts = r.track.map(p => [p.lat, p.lng]);
    if (pts.length === 1) map.setView(pts[0], 14);
    else map.fitBounds(L.latLngBounds(pts).pad(0.2));
}

/* التيكر — تحت خالص: كل واحد سهم بورصة، أخضر بيبيع رمادي ساكن */
function renderTape() {
    const items = (data.reps || []).map(r => {
        const up = r.sales > 0;
        return `<span class="lv-tk"><span class="sym">${esc(r.name)}</span>
            <span class="${up ? 'up' : 'dn'}">${up ? '▲' : '—'} ${fmt(r.sales)}</span>
            <span class="lv-dim">${r.done}/${r.planned}</span></span>`;
    }).join('');
    document.getElementById('lvTape').innerHTML = items + items;
}

/* ماركر بصورة الموظف — إطار بلون الحالة ونبضة للمتحرك/اللي في زيارة */
function repIcon(r) {
    const s = effState(r);
    const cls = s === 'moving' ? 'mv' : (s === 'visit' ? 'vs' : '');
    const col = SC[s] || SC.off;
    const inner = r.avatar_url
        ? `<img src="${esc(r.avatar_url)}" alt="">`
        : `<span style="background:${repColor(r.id)}">${esc(r.initials || '')}</span>`;
    return L.divIcon({
        className: '',
        html: `<div class="lv-marker ${cls}">
                 <div class="ring" style="background:${col}"></div>
                 <div class="pic" style="border-color:${col}">${inner}</div>
                 <div class="tag">${esc(r.name)}</div>
               </div>`,
        iconSize: [40, 40], iconAnchor: [20, 20],
    });
}

/* ═════ انزلاق الماركر — lerp ~2 ثانية بدل النطة (طلب المالك:
   «عاوز أشوفه بيتحرك») ═════ */
function slideMarker(m, lat, lng) {
    const from = m.getLatLng();
    if (Math.abs(from.lat - lat) < 1e-7 && Math.abs(from.lng - lng) < 1e-7) return;
    if (m._lvAnim) cancelAnimationFrame(m._lvAnim);
    const t0 = performance.now(), D = 2000;
    const step = now => {
        const k = Math.min(1, (now - t0) / D);
        const e = k < .5 ? 2 * k * k : 1 - Math.pow(-2 * k + 2, 2) / 2;
        m.setLatLng([from.lat + (lat - from.lat) * e, from.lng + (lng - from.lng) * e]);
        if (k < 1) { m._lvAnim = requestAnimationFrame(step); } else { m._lvAnim = null; }
    };
    m._lvAnim = requestAnimationFrame(step);
}

/* ═════ الرسم ═════ */
function render() {
    document.getElementById('lvKpis').innerHTML = T.kpis.map(([k, l, cls, ic]) =>
        `<div class="lv-kpi ${cls}">
            <div class="ic">${ic}</div>
            <div><div class="v">${fmt((data.totals || {})[k])}</div><div class="l">${l}</div></div>
        </div>`).join('');

    renderTape();
    renderPeople();

    // الماركرز — بتنزلق مش بتنط، وطبقة المناديب ممكن تتقفل
    const seen = new Set();
    (data.reps || []).forEach(r => {
        if (r.lat === null || !layers.reps) return;
        seen.add(r.id);
        if (markers[r.id]) {
            slideMarker(markers[r.id], r.lat, r.lng);
            markers[r.id].setIcon(repIcon(r));
        } else {
            markers[r.id] = L.marker([r.lat, r.lng], { icon: repIcon(r) })
                .addTo(map).on('click', () => selectRep(r.id));
        }
    });
    Object.keys(markers).forEach(id => {
        if (!seen.has(parseInt(id, 10))) { map.removeLayer(markers[id]); delete markers[id]; }
    });

    drawTrack();

    if (followId !== null) {
        const fr = (data.reps || []).find(r => r.id === followId);
        if (fr && fr.lat !== null) map.panTo([fr.lat, fr.lng]);
    }

    renderAlerts();
    if (popupId !== null) renderPopup();
}

/* كارت شخص واحد في الجريد */
function personCard(r) {
    const s = effState(r);
    const avatar = r.avatar_url
        ? `<img src="${esc(r.avatar_url)}" alt="">`
        : `<span style="background:${repColor(r.id)}">${esc(r.initials || '')}</span>`;
    const visitLine = s === 'visit' && r.open_client
        ? `<div class="ln vis">🛒 ${sub(sub(T.visitingFor, ':client', esc(r.open_client)), ':dur', dur(r.live ? r.live.min : null))}</div>`
        : '';
    const sig = signalLine(r);
    return `
    <div class="lv-p ${s === 'visit' ? 'visiting' : ''} ${r.id === selectedId ? 'sel' : ''}" data-id="${r.id}">
        <div class="r1">
            <div class="lv-avatar" style="border-color:${repColor(r.id)}">${avatar}</div>
            <div style="min-width:0;flex:1">
                <div class="nm">${esc(r.name)}</div>
                <div class="zn">${esc(r.zone || '—')}</div>
            </div>
        </div>
        <div class="r2"><span class="lv-status s-${s}">${stateTxt(r)}</span></div>
        ${visitLine}
        <div class="ln">${attLine(r)}</div>
        <div class="nums">
            <span title="${esc(T.custody)}">🚚 <b>${fmt(r.value)}</b></span>
            <span class="up" title="${esc(T.sales)}">💰 <b>${fmt(r.sales)}</b></span>
            <span title="${esc(T.visitsT)}">📍 <b>${r.visits || 0}</b></span>
        </div>
        ${sig ? `<div class="ln">${sig}</div>` : ''}
    </div>`;
}

/* بانل الأشخاص — قسمين: المديرين فوق والمناديب تحت (طلب المالك ١٢/٨) */
function renderPeople() {
    const q = search.trim().toLowerCase();
    const match = r => (!filter || effState(r) === filter)
        && (!q || (r.name + ' ' + (r.zone || '')).toLowerCase().includes(q));

    const all = data.reps || [];
    const managers = all.filter(r => r.role_key === 'manager' && match(r));
    const reps = all.filter(r => r.role_key !== 'manager' && match(r));

    document.getElementById('lvSideCount').textContent = (managers.length + reps.length) + ' / ' + all.length;

    let html = '';
    if (managers.length) {
        html += `<div class="lv-sec-h">👔 ${T.secManagers} <span>${managers.length}</span></div>
                 <div class="lv-people">${managers.map(personCard).join('')}</div>`;
    }
    html += `<div class="lv-sec-h">🧑‍💼 ${T.secReps} <span>${reps.length}</span></div>
             <div class="lv-people">${reps.map(personCard).join('')}</div>`;

    // ⚠️ الحاوية بتتكتب كل ٣ ثواني — من غير حفظ scrollTop مفيش حد
    // يعرف ينزّل في القايمة أكتر من ٣ ثواني
    const wrap = document.getElementById('lvPeople');
    const st = wrap.scrollTop;
    wrap.innerHTML = html;
    wrap.scrollTop = st;
    document.querySelectorAll('#lvPeople .lv-p').forEach(el =>
        el.addEventListener('click', () => selectRep(parseInt(el.dataset.id, 10))));
}

/* الضغط على شخص = اختياره (مسار + فلترة تايم لاين) + بوب أب بياناته */
function selectRep(id) {
    if (selectedId === id) {
        openPopup(id);
        return;
    }
    selectedId = id;
    if (followId !== null) followId = id;
    openPopup(id);
    render();
    fitTrack();
    const r = (data.reps || []).find(x => x.id === id);
    if (r && trackLine === null && trackStart === null && r.lat !== null) map.panTo([r.lat, r.lng]);
}

function clearSelection() {
    selectedId = null;
    if (followId !== null) {
        followId = null;
        const b = document.getElementById('lvFollowBtn');
        b.classList.remove('on');
        b.textContent = T.follow;
    }
    render();
}

/* ═════ البوب أب — كل بيانات الشخص، وبيتحدث مع كل حمولة جديدة ═════ */
function openPopup(id) {
    popupId = id;
    document.getElementById('lvOvl').hidden = false;
    renderPopup();
}

function closePopup() {
    popupId = null;
    document.getElementById('lvOvl').hidden = true;
}

function renderPopup() {
    const r = (data.reps || []).find(x => x.id === popupId);
    if (!r) { closePopup(); return; }

    const s = effState(r);
    const avatar = r.avatar_url
        ? `<img src="${esc(r.avatar_url)}" alt="">`
        : `<span style="background:${repColor(r.id)}">${esc(r.initials || '')}</span>`;

    const stateLine = s === 'visit' && r.open_client
        ? `<div class="stateline vis">🛒 ${sub(sub(T.visitingFor, ':client', esc(r.open_client)), ':dur', dur(r.live ? r.live.min : null))}</div>`
        : `<div class="stateline"><span class="lv-status s-${s}">${stateTxt(r)}</span></div>`;

    const zoneChip = r.in_zone === null || r.in_zone === undefined ? ''
        : ` · <span style="color:${r.in_zone ? 'var(--green)' : 'var(--red)'}">● ${r.in_zone ? T.inZone : T.outZone}</span>`;

    const items = (r.items || []).map(i => {
        const pct = i.assigned > 0 ? Math.round(i.sold / i.assigned * 100) : 0;
        return `<div class="lv-item">
            <div class="r1"><span>${esc(i.name)}</span>
                <span class="lv-dim">${T.sold} ${i.sold} · ${T.left} <b style="color:var(--txt)">${i.remaining}</b> / ${i.assigned}</span></div>
            <div class="bar"><i style="width:${pct}%;background:linear-gradient(90deg,#4D6FE3,#9D6FE0)"></i></div>
        </div>`;
    }).join('');

    const evts = (r.events || []).map(ev => `
        <div class="evt" style="border-color:${ev.color || 'var(--line)'}">
            <span class="tm">${ev.t}</span>
            <span>${ev.icon || ''}</span>
            <span>${esc(ev.text)}</span>
        </div>`).join('');

    const sig = signalLine(r);

    document.getElementById('lvPop').innerHTML = `
        <button class="x" type="button" id="lvPopX" aria-label="×">✕</button>
        <div class="head">
            <span class="lv-avatar" style="border-color:${repColor(r.id)};width:46px;height:46px">${avatar}</span>
            <div>
                <div class="nm">${esc(r.name)}</div>
                <div class="lv-dim">${esc(r.role)} · ${esc(r.zone || '—')}${zoneChip}</div>
            </div>
        </div>
        ${stateLine}
        <div class="metaline">${attLine(r)}</div>
        ${sig ? `<div class="metaline">${sig}</div>` : ''}
        <div class="lv-stats">
            <div class="lv-stat"><div class="v" style="color:var(--green)">${fmt(r.sales)}</div><div class="l">${T.sales}</div></div>
            <div class="lv-stat"><div class="v">${r.done}/${r.planned}</div><div class="l">${T.done}</div></div>
            <div class="lv-stat"><div class="v">${r.visits || 0}</div><div class="l">${T.visitsT}</div></div>
            <div class="lv-stat"><div class="v">${r.pos || 0}</div><div class="l">${T.posT}</div></div>
            <div class="lv-stat"><div class="v">${r.km}</div><div class="l">${T.kmU}</div></div>
            <div class="lv-stat"><div class="v">${fmt(r.value)}</div><div class="l">${T.custody}</div></div>
        </div>
        ${items ? `<div class="lv-card-h" style="margin-top:4px">📦 ${T.custody}
            <span class="lv-dim">${T.remTotal.replace(':count', fmt(r.units))} · ${T.worth.replace(':value', fmt(r.value))}</span></div>${items}` : ''}
        <div class="lv-card-h" style="margin-top:6px">🕐 ${T.lastEvents}</div>
        <div class="evts">${evts || `<div class="lv-dim">${T.noEvents}</div>`}</div>
        <div class="foot">
            <a href="${r.tracking_url || '#'}" target="_blank" rel="noopener">📍 ${T.openTracking} ↗</a>
            <a class="alt" href="${r.url}" target="_blank" rel="noopener">📅 ${T.repDay} ↗</a>
        </div>`;

    document.getElementById('lvPopX').addEventListener('click', closePopup);
}

document.getElementById('lvOvl').addEventListener('click', function (e) {
    if (e.target === this) closePopup();
});

/* التايم لاين — بتاع الكل افتراضياً، وبيتفلتر على المختار */
function renderAlerts() {
    const all = data.alerts || [];
    const list = selectedId === null
        ? all
        : all.filter(a => a.user_id === undefined || a.user_id === selectedId);

    const sel = (data.reps || []).find(x => x.id === selectedId);
    const scope = document.getElementById('lvAlertScope');
    scope.innerHTML = selectedId === null
        ? `<span class="lv-dim">${T.tlAll}${list.length ? ' · ' + T.latest.replace(':count', list.length) : ''}</span>`
        : `<span class="lv-dim">${sub(T.tlFor, ':name', esc(sel ? sel.name : ''))}</span>
           <button class="lv-btn sm" id="lvTlAll" type="button">${T.all}</button>`;
    const allBtn = document.getElementById('lvTlAll');
    if (allBtn) allBtn.addEventListener('click', clearSelection);

    const box = document.getElementById('lvAlerts');
    const st = box.scrollTop;
    box.innerHTML = list.length
        ? list.map(a => `<div class="lv-alert" style="border-color:${a.color}">
             <div class="hd"><span class="ic">${a.icon || ''}</span><b class="rp">${esc(a.rep)}</b><span class="tm">${a.t}</span></div>
             <div class="tx">${esc(a.text)}</div></div>`).join('')
        : `<div class="lv-dim">${selectedId === null ? T.noAlerts : T.noAlertsRep}</div>`;
    box.scrollTop = st;
}

/* ═════ التحكم ═════ */
document.getElementById('lvSearch').addEventListener('input', e => { search = e.target.value; render(); });
document.querySelectorAll('#lvChips .lv-chip').forEach(ch => ch.addEventListener('click', () => {
    document.querySelectorAll('#lvChips .lv-chip').forEach(c => c.classList.remove('on'));
    ch.classList.add('on');
    filter = ch.dataset.f;
    render();
}));

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
    if (paused) { stopStream(); stopPolling(); setMode(false); } else { startLive(); }
});

/* ═════ الساعة — القاهرة صراحةً: التلفزيون ممكن توقيته UTC ═════ */
const clockFmt = new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Africa/Cairo', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true,
});
setInterval(() => {
    document.getElementById('lvClock').textContent = clockFmt.format(new Date()).toUpperCase();
}, 1000);

/* ═══════════════ التحديث: تدفق لايف + فولباك بولينج ═══════════════
   التدفق (SSE كل ٣ ثواني) هو الأصل، والبولينج شبكة أمان — اتشد
   لـ10 ثواني (طلب المالك «أسرع»). عمرهم ما يشتغلوا مع بعض. */
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
    } catch (e) { /* الشبكة وقعت — المحاولة الجاية */ }
}

function startPolling() {
    if (pollTimer !== null || paused) return;
    pollTimer = setInterval(refresh, 10000);
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
        stopPolling();
        setMode(true);
        if (!paused && !document.hidden) apply(payload);
    };

    src.onerror = function () {
        stopStream();
        if (got) { retryStream(800); return; }
        setMode(false);
        startPolling();
        retryStream(retryMs);
        retryMs = Math.min(retryMs * 2, 300000);
    };
}

function startLive() {
    if (paused || document.hidden) return;
    startPolling();
    startStream();
}

document.addEventListener('visibilitychange', function () {
    if (document.hidden) { stopStream(); stopPolling(); setMode(false); return; }
    refresh();
    startLive();
});

/* أول رسمة من الحمولة المدمجة، وبعدها التدفق يمسك الشاشة */
drawZones();
drawGovs();
render();
startLive();

const first = (data.reps || []).find(r => r.lat !== null);
if (first) map.setView([first.lat, first.lng], 12);
})();
</script>
@endsection
