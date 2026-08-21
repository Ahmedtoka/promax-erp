@php
    // اللغة والاتجاه بيتحددوا من SetLocale middleware (users.locale)
    $locale = app()->getLocale();
    $isRtl = $locale === 'ar';
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title') — PROMAX ERP</title>
<link rel="icon" href="{{ asset('brand/logo/logo-v-blue.png') }}">
{{-- طبقة البراند: الألوان والفونتات الرسمية من الجايد لاين 2024 --}}
<link rel="stylesheet" href="{{ asset('brand/promax.css') }}">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
{{-- خرائط حقيقية — OpenStreetMap عن طريق Leaflet --}}
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--paper);color:var(--text);font-family:{{ $isRtl ? 'Cairo,Poppins,Tahoma' : 'Poppins,Cairo,Tahoma' }},sans-serif;font-size:14px}
/* ⚠️ **عناصر الفورم مابترثش الفونت من `body` افتراضياً** — المتصفح
   بيديها فونت النظام. كل عنصر كان بيحط `font-family:inherit` بإيده،
   وأي زرار أو سيلكت أو `summary` اتكتب من غيره كان بيطلع بفونت تاني
   خالص جنب باقي الشاشة. السطر ده بيقفل الباب على مستوى المشروع. */
button,input,select,textarea,optgroup,summary,option{
  font-family:inherit;
}
/* زرار تبديل اللغة */
.langsw{display:flex;gap:0;border:1px solid rgba(255,255,255,.18);border-radius:9px;overflow:hidden;margin-bottom:8px}
.langsw button{flex:1;background:transparent;color:#EDEDEA;border:none;padding:6px 0;font-family:inherit;font-size:11.5px;font-weight:800;cursor:pointer;transition:.15s}
.langsw button.on{background:var(--brand-yellow);color:var(--ink)}
.langsw button:not(.on):hover{background:rgba(255,255,255,.08)}
a{color:inherit;text-decoration:none}
img{display:block;max-width:100%}

.wrap{display:flex;min-height:100vh;align-items:flex-start}
/* السايدبار ثابت مع التمرير. الصاعقة علامة مائية كخلفية —
   مش pseudo-element بإزاحة سالبة، عشان مايعملش سكرول زيادة.
   ⚠️ ممنوع تحط position تانية هنا — كانت relative بتلغي الـ sticky
   والقايمة كانت بتتقص وإحنا بننزل. */
.sidebar{
  width:252px;flex-shrink:0;
  position:sticky;top:0;
  height:100vh;max-height:100vh;
  overflow-y:auto;overflow-x:hidden;overscroll-behavior:contain;
  padding:16px 12px;
  color:#EDEDF5;
  display:flex;flex-direction:column;
  background:
    url('/brand/bolt-watermark.svg') no-repeat,
    var(--brand-gradient);
  background-size:265px auto, cover;
  background-position:-72px bottom, center;
  background-attachment:local, scroll;
}
.logo{display:block;padding:4px 6px 14px;border-bottom:1px solid rgba(255,255,255,.16);margin-bottom:10px}
.logo .brandmark{width:150px;height:auto;margin-bottom:7px}
.logo .sub{font-size:9.5px;color:rgba(255,255,255,.62);letter-spacing:1.4px;font-weight:600}
.logo .sub span{color:var(--brand-yellow);font-weight:800}
/* ═══════════════════════════════════════════════════════════
   المنيو الأكورديون (قرار المالك ٨/٨/٢٠٢٦ + بوليش)
   ═══════════════════════════════════════════════════════════

   ⚠️ **عنوان المجموعة كان أصغر من اللينكات اللي جواه** — ١٠px
   رمادي باهت جنب لينكات ١٣px بيضا. العين كانت بتقرا العناوين
   كأنها هوامش، والمستخدم بيسكرول يدوّر بدل ما يمسح المجموعات.
   دلوقتي العنوان **أكبر وأبيض وبأيقونة** واللينك اللي جواه أصغر —
   التسلسل البصري بقى مطابق للتسلسل المنطقي.

   ⚠️ `list-style:none` + `::-webkit-details-marker` — المثلث
   الافتراضي بيبان بشكلين مختلفين في كروم وفايرفوكس وبيكسر
   المحاذاة في الواجهة العربية. */
.navgrp{font-size:10px;color:rgba(255,255,255,.5);font-weight:800;padding:12px 10px 5px;letter-spacing:.6px}

.navgrp-acc{margin-bottom:3px;border-radius:var(--r-sm);transition:background .15s}

.navgrp-acc>summary{
  display:flex;align-items:center;gap:9px;cursor:pointer;
  list-style:none;user-select:none;
  /* أكبر من اللينك (١٣px) عشان يبان إنه أب مش أخ */
  font-size:12.5px;font-weight:800;color:#fff;
  padding:8px 11px;border-radius:var(--r-sm);
  transition:.15s;
}
.navgrp-acc>summary::-webkit-details-marker{display:none}
.navgrp-acc>summary:hover{background:rgba(255,255,255,.10)}

/* أيقونة المجموعة — عرض ثابت عشان النصوص تتراص على خط واحد
   مهما اختلف عرض الإيموجي */
.navgrp-acc>summary .gi{
  width:19px;flex-shrink:0;text-align:center;font-size:14px;line-height:1;
}
.navgrp-acc>summary .gt{flex:1;min-width:0}

/* ⚠️ **المجموعة النشطة ملوّنة** — المستخدم لازم يعرف هو فين من غير
   ما يفتح المجموعات واحدة واحدة. */
.navgrp-acc>summary.on{color:var(--brand-yellow)}

/* ⚠️ **السهم آخر حاجة في السطر دايماً** — كان `margin-inline-start:auto`
   على السهم وعلى العدّاد الاتنين، فأول ما يبقى فيه عدّاد كان بياخد
   المساحة والسهم يلزق جنبه في النص. دلوقتي السهم `order:99` والعدّاد
   `order:98`، فالترتيب ثابت: أيقونة · اسم · عدّاد · سهم. */
.navgrp-acc>summary::after{
  content:'▾';order:99;
  font-size:11px;opacity:.55;transition:transform .15s;flex-shrink:0;
}
.navgrp-acc[open]>summary::after{transform:rotate(180deg);opacity:.9}
.navgrp-acc>summary .cnt{
  order:98;background:var(--brand-yellow);color:var(--ink);
  font-weight:800;border-radius:20px;padding:1px 8px;font-size:10.5px;
  margin-inline-end:2px;flex-shrink:0;
}

/* ═══ جسم المجموعة المفتوحة — أغمق عشان تبان إنها جوّه ═══
   ⚠️ **ده اللي المستخدم طلبه**: من غير خلفية، اللينكات المفتوحة
   بتسبح على نفس تدرج السايدبار ومفيش حد بصري بيقول «دول تبع اللي
   فوق». الطبقة الغامقة + الخط الجانبي بيعملوا الحد ده. */
.navgrp-acc[open]{background:rgba(8,12,30,.28)}
.navgrp-acc[open]>summary{background:rgba(8,12,30,.22)}
.navgrp-acc .navbody{
  padding:4px 6px 7px;
  border-inline-start:2px solid rgba(255,255,255,.14);
  margin-inline-start:20px;   /* بمحاذاة أيقونة المجموعة */
}
/* اللينك جوّه المجموعة أصغر من العنوان — التسلسل البصري */
.navgrp-acc .navbody .navlink{font-size:11.5px;padding:6.5px 10px}
.navlink{display:flex;align-items:center;gap:8px;padding:7.5px 11px;border-radius:var(--r-sm);font-weight:600;font-size:12px;color:rgba(255,255,255,.82);margin-bottom:2px;transition:.15s}
.navlink:hover{background:rgba(255,255,255,.12);color:#fff}
.navlink.active{background:#fff;color:var(--royal-blue);font-weight:800;box-shadow:0 2px 10px rgba(0,0,0,.18)}
.navlink .cnt{margin-inline-start:auto;background:var(--brand-yellow);color:var(--ink);font-weight:800;border-radius:20px;padding:1px 8px;font-size:10.5px}
.navlink.active .cnt{background:var(--royal-blue);color:#fff}
.side-user{margin-top:auto;border-top:1px solid rgba(255,255,255,.16);padding-top:12px;font-size:12px}
.side-user .nm{font-weight:800;color:#fff}
.side-user .rl{color:rgba(255,255,255,.6);font-size:10.5px;margin-bottom:8px}
.side-user button{width:100%;background:rgba(255,255,255,.14);color:#fff;border:none;border-radius:9px;padding:7px;font-family:inherit;font-size:12px;font-weight:700;cursor:pointer}
.side-user button:hover{background:rgba(220,38,38,.25)}

.main{flex:1;min-width:0;padding:18px 24px 40px}
.topbar{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid var(--border)}
.topbar h1{font-size:22px;font-weight:800;letter-spacing:-.5px;color:var(--ink)}
.topbar .meta{display:flex;align-items:center;gap:8px}

/* ═══ جرس الإشعارات (2026-08-09) ═══ */
.bell{position:relative}
.bell summary{
  list-style:none;cursor:pointer;position:relative;display:flex;
  align-items:center;justify-content:center;width:38px;height:38px;
  border:1px solid var(--border);border-radius:12px;background:var(--card);
  font-size:17px;user-select:none;
}
.bell summary::-webkit-details-marker{display:none}
.bell[open] summary{border-color:var(--royal-blue);box-shadow:var(--shadow)}
.bell-badge{
  position:absolute;top:-6px;inset-inline-end:-6px;background:var(--red);
  color:#fff;font-size:9.5px;font-weight:800;border-radius:99px;
  padding:2px 5.5px;line-height:1.2;border:2px solid var(--card);
}
/* ⚠️ **absolute جوه الجرس نفسه** (إصلاح نهائي ١١/٨ مساءً) — الحل
   الـfixed + جافاسكربت كان بيطلّع القايمة بره الشاشة لو السكربت
   ماشتغلش أو اتأخر. دلوقتي القايمة معلّقة على الزرار مباشرة بـCSS
   بحت: بتنزل تحته وتمتد للداخل (بعيد عن حافة الشاشة). الجرس نفسه
   `position:relative` تحت. مفيش اعتماد على أي جافاسكربت للتموضع. */
.bell-panel{
  position:absolute;top:calc(100% + 8px);z-index:600;
  width:340px;max-width:calc(100vw - 24px);max-height:min(430px, calc(100vh - 120px));
  overflow:auto;background:var(--card);
  border:1px solid var(--border);border-radius:var(--r-md);box-shadow:var(--shadow-lift);
}
/* الجرس في LTR على اليمين → القايمة حافتها اليمنى معاه وتمتد شمال.
   في RTL على الشمال → حافتها الشمال معاه وتمتد يمين. الاتنين للداخل. */
[dir=ltr] .bell-panel{right:0;left:auto}
[dir=rtl] .bell-panel{left:0;right:auto}
.bell-head{
  display:flex;justify-content:space-between;align-items:center;
  padding:10px 13px;border-bottom:1px solid var(--border);font-size:12.5px;
  position:sticky;top:0;background:var(--card);z-index:1;
}
.bell-clear{
  border:0;background:none;color:var(--royal-blue);font-size:11px;
  font-weight:700;cursor:pointer;font-family:inherit;
}
.bell-item{
  display:flex;gap:9px;padding:10px 13px;border-bottom:1px solid var(--border);
  text-decoration:none;color:var(--ink);align-items:flex-start;
}
.bell-item:hover{background:var(--card2)}
/* ⚠️ غير المقروء لازم يبان من غير تدقيق (١١/٨): الخلفية الزرقا
   الفاتحة لوحدها كانت شبه غير مرئية — فبان إن «الريد مش شغال».
   دلوقتي: خلفية + شريط جانبي + عنوان تقيل، والمقروء باهت وعادي. */
.bell-item.unread{
  background:var(--blue-050);
  border-inline-start:3px solid var(--royal-blue);
}
.bell-item.unread .bell-txt b{font-weight:900}
.bell-item:not(.unread){opacity:.65}
.bell-item:not(.unread) .bell-txt b{font-weight:600}
.bell-item:not(.unread) .bell-dot{opacity:.25}
.bell-dot{width:8px;height:8px;border-radius:99px;margin-top:5px;flex:none}
.bell-dot.good{background:var(--green)}
.bell-dot.bad{background:var(--red)}
.bell-txt{display:flex;flex-direction:column;gap:2px;min-width:0}
.bell-txt b{font-size:12px;line-height:1.5}
.bell-txt span{font-size:11px;color:var(--muted);line-height:1.55}
.bell-txt small{font-size:10px;color:var(--muted);opacity:.8}
.bell-empty{padding:26px;text-align:center;color:var(--muted);font-size:12px}
@media print{.bell{display:none !important}}

/* ═══ شريط الاختصارات (١١ أغسطس ٢٠٢٦) ═══ */
.qbar{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.qchip{
  display:inline-flex;align-items:center;gap:6px;text-decoration:none;
  padding:5px 11px;border-radius:999px;border:1px solid var(--border);
  background:var(--card);color:var(--ink);font-size:12px;font-weight:700;
  white-space:nowrap;transition:.12s;
}
.qchip:hover{border-color:var(--royal-blue);box-shadow:var(--shadow)}
.qchip-t{font-size:11.5px}
/* على الموبايل الشيبس بتاخد مساحة — نخبّي نصها ونسيب الأيقونة */
@media (max-width:760px){.qchip-t{display:none}}
.qsc>summary{cursor:pointer}
.qmenu .qrow{
  display:flex;align-items:center;gap:8px;padding:8px 12px;
  border-bottom:1px solid var(--border);
}
.qmenu .qrow.on{background:var(--blue-050)}
.qmenu .qgo{
  flex:1;display:flex;align-items:center;gap:9px;text-decoration:none;
  color:var(--ink);font-size:12.5px;font-weight:700;min-width:0;
}
.qmenu .qgo:hover{color:var(--royal-blue)}
.qmenu .qic{font-size:15px;width:20px;text-align:center;flex:none}
.qmenu .qpin{
  border:0;background:none;cursor:pointer;font-size:16px;color:var(--muted);
  line-height:1;padding:2px;flex:none;
}
.qmenu .qpin.on{color:#E6A700}
@media print{.qbar,.qsc{display:none !important}}

.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:16px}
.kpi{background:var(--card);border:1px solid var(--border);border-radius:var(--r-md);padding:15px 16px;box-shadow:var(--shadow);position:relative;overflow:hidden}
.kpi::before{content:"";position:absolute;inset-block-start:0;inset-inline-start:0;width:100%;height:3px;background:var(--brand-gradient);opacity:.85}
.kpi .lbl{color:var(--muted);font-size:11.5px;font-weight:700}
.kpi .val{
  font-size:22px;font-weight:900;margin-top:4px;letter-spacing:-.5px;
  /* ⚠️ **`plaintext` مش `ltr`.** الاتجاه بيتحدد من أول حرف قوي في
     النص نفسه: «20,372 ج» بيبدأ برقم فيتقرا شمال-يمين والعملة
     بتفضل في آخره، و«2 مستنية» بيفضل عربي طبيعي. لو فرضنا `ltr`
     على الكل كانت الكروت اللي قيمتها جملة عربية هتتقلب. */
  unicode-bidi:plaintext;
  font-variant-numeric:tabular-nums;
}
.kpi .sub2{font-size:11px;color:var(--muted);margin-top:3px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.grid2 > .card{min-width:0}
@media(max-width:1000px){.grid2{grid-template-columns:1fr}}
.card{background:var(--card);border:1px solid var(--border);border-radius:var(--r-md);padding:16px 18px;margin-bottom:14px;box-shadow:var(--shadow)}
.card h3{font-size:14.5px;font-weight:800;margin-bottom:14px;display:flex;align-items:center;gap:8px}
.card h3::before{content:"";width:4px;height:17px;background:var(--brand-gradient);border-radius:4px}
.card h3 .side{margin-inline-start:auto;font-size:12px;font-weight:700;color:var(--muted)}
.chartbox{position:relative;height:290px}
.mapbox{height:340px;border-radius:14px;overflow:hidden;background:var(--card2);z-index:0}
.mapbox.sm{height:220px}
.leaflet-container{font-family:{{ $isRtl ? 'Cairo,Poppins,Tahoma' : 'Poppins,Cairo,Tahoma' }},sans-serif}
.alerts{display:flex;flex-direction:column;gap:8px}
.alert{display:flex;gap:10px;background:var(--card2);border-radius:10px;padding:10px 13px;border-inline-start:4px solid var(--red);font-size:13px;line-height:1.7}
.alert.warn{border-inline-start-color:var(--orange)}
/* ═══ علامات الخطأ — مشتركة بين كل الشاشات ═══
   ⚠️ الخانة الغلط لازم تبان **بلون وحدود** مش بالرسالة بس. المستخدم
   بيرجع للصفحة وعينه بتدوّر على حاجة حمرا، مش بيقرا 40 لابل.
   ⚠️ في اللياوت مش في شاشة واحدة: كل فورم في السيستم بيستخدم نفس
   الكلاسات، ونسخة لكل شاشة بتفضل تفرق عن التانية مع الوقت. */
input.bad, select.bad, textarea.bad{border-color:var(--red)!important;background:#FDECEC!important}
/* شرح تحت الخانة — بيقول القيمة دي بتروح فين.
   ⚠️ أصغر وأخفت من سطر الخطأ عن قصد: الخطأ لازم يخطف العين والشرح
   لازم يتقرا لما حد يدوّر عليه بس. */
.fhint{font-size:11px;color:var(--muted);margin-top:5px;line-height:1.55}
.errline{color:var(--red);font-size:11px;font-weight:800;margin-top:5px;line-height:1.6}
.req-star{color:var(--red);font-weight:900}
.alert.info{border-inline-start-color:var(--blue)}
.alert.good{border-inline-start-color:var(--green)}
/* ═══════════════════════════════════════════════════════════════
   الجداول — طبقة واحدة لكل السيستم (بوليش 2026-08-08)
   ═══════════════════════════════════════════════════════════════
   ⚠️ **ممنوع تظبيط جدول في بليد واحد.** أي قاعدة هنا بتتطبق على
   الـ50 جدول مرة واحدة؛ والتظبيط الموضعي هو اللي خلّى نص الجداول
   محاذاتها مختلفة عن التانية. */
table{width:100%;border-collapse:collapse;font-size:13px;table-layout:auto}

/* ⚠️ **الهيدر بأزرق البراند وكلام أبيض** (قرار المالك 2026-08-08).
   كان رمادي باهت على أبيض — بيضيع وسط الصفوف، والعين مابتعرفش
   الجدول بدأ فين خصوصاً بعد سكرول. */
th{
  color:#fff;font-weight:800;text-align:start;padding:11px 9px;
  background:var(--primary);white-space:nowrap;
  border-bottom:2px solid rgba(0,0,0,.12);
  border-inline-end:1px solid rgba(255,255,255,.13);
}
th:last-child{border-inline-end:none}
thead tr:first-child th:first-child{border-start-start-radius:10px}
thead tr:first-child th:last-child{border-start-end-radius:10px}

/* ⚠️ **`max-width` + قصّ** — من غيرها عمود العنوان أو الملاحظات
   بياخد نص الجدول والباقي يتزنق. النص الكامل بيفضل في `title`
   (السكريبت بيحطه) فمفيش معلومة بتضيع. */
td{
  padding:9px;border-bottom:1px solid var(--border);white-space:nowrap;
  max-width:320px;overflow:hidden;text-overflow:ellipsis;
}
tr:last-child td{border-bottom:none}
tr.clickable{cursor:pointer;transition:.12s}
tr.clickable:hover{background:var(--card2)}
tbody tr:nth-child(even){background:rgba(0,0,0,.014)}
.tablewrap{overflow-x:auto;border-radius:10px}

/* ⚠️ **الأرقام في النص — الهيدر والمحتوى بنفس المحاذاة بالظبط**
   (إصلاح 2026-08-08). كان `.num` بـ`text-align:left` والهيدر
   بـ`text-align:start` (= يمين في العربي)، فالرقم في ناحية
   والعنوان بتاعه في الناحية التانية على عرض العمود كله — ومستحيل
   تعرف الرقم ده تحت أنهي عمود.
   `direction:ltr` باقية عشان «1,234.50» تترسم صح في واجهة عربي. */
.num, th.num{font-variant-numeric:tabular-nums;direction:ltr;text-align:center}

/* صف الإجماليات — بيتولّد من السكريبت */
tfoot td{
  background:var(--card2);font-weight:900;border-top:2px solid var(--primary);
  border-bottom:none;position:sticky;bottom:0;
}
tfoot td.num{color:var(--primary)}

/* الباجينيشن المحلي */
.gs-pager{display:flex;gap:5px;align-items:center;justify-content:center;
  flex-wrap:wrap;margin-top:10px;font-size:12px}
.gs-pager button{border:1px solid var(--border);background:var(--card);
  border-radius:8px;padding:5px 10px;cursor:pointer;font-weight:800;font-size:12px}
.gs-pager button[disabled]{opacity:.4;cursor:default}
.gs-pager button.on{background:var(--primary);color:#fff;border-color:var(--primary)}
.gs-pager .gs-info{color:var(--muted);margin-inline-end:6px}
/* قايمة «كام صف في الصفحة» — جنب أزرار الترقيم */
.gs-pager .gs-size{border:1px solid var(--border);background:var(--card);
  border-radius:7px;padding:3px 6px;font:inherit;font-size:11.5px;
  color:var(--muted);cursor:pointer;margin-inline-end:8px}

/* ═══ تكبير صورة المنتج — `data-zoom` على أي <img> ═══
   ⚠️ صور المنتجات في الجداول ٥٦ بكسل، واللي بيسعّر بيدوّر على
   الفرق بين «زبدة فول سوداني ٣٠٠» و«٥٠٠» — والفرق في الشكل مش
   في الاسم. الصورة الصغيرة مابتفرّقش. */
img[data-zoom]{cursor:zoom-in}
#imgZoom{border:0;background:transparent;padding:0;max-width:92vw;max-height:92vh}
#imgZoom::backdrop{background:rgba(0,0,0,.72)}
#imgZoom img{max-width:92vw;max-height:88vh;object-fit:contain;
  border-radius:12px;background:#fff;display:block}
#imgZoom .zcap{color:#fff;text-align:center;font-size:12.5px;margin-top:8px}
.pos{color:var(--green)}.neg{color:var(--red)}.mid{color:var(--orange)}.muted{color:var(--muted)}
.badge{display:inline-block;padding:3px 11px;border-radius:20px;font-size:11px;font-weight:800}
.b-red{background:#FDECEC;color:var(--red)}
.b-orange{background:#FDF1E3;color:#B96C0A}
.b-green{background:#E7F7EE;color:#0F7A38}
.b-blue{background:#E8EFFD;color:var(--blue)}
.b-gray{background:#F0EEE8;color:var(--muted)}
.b-purple{background:#F1EAFD;color:var(--purple)}
.b-gold{background:#FFFDE0;color:#8A7A00}
.searchbar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;align-items:center}
input[type=text],input[type=search],input[type=number],input[type=date],input[type=datetime-local],input[type=time],input[type=email],input[type=password],select,textarea{background:var(--card);border:1px solid var(--border);color:var(--text);border-radius:10px;padding:9px 13px;font-family:inherit;font-size:13px;outline:none;transition:.15s}
input[type=search]{-webkit-appearance:none;appearance:none}
input:focus,select:focus,textarea:focus{border-color:var(--royal-blue);box-shadow:0 0 0 3px rgba(18,57,155,.14)}
.searchbar input[type=text],.searchbar input[type=search]{flex:1;min-width:200px}
label.f{display:block;font-size:11.5px;font-weight:800;margin-bottom:5px;color:var(--muted)}

/* ═══ `.filters` و`.pill` — كانوا مستخدمين في ٤ صفحات (الحضور
   والإصدار) **ومش معرّفين خالص** (إصلاح ٩/٨): الفلاتر كانت واقعة
   تحت بعض كل واحدة بعرض الصفحة، والحالات نص عادي من غير شكل. ═══ */
.filters{
  display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;
  background:var(--card);border:1px solid var(--border);
  border-radius:var(--r-lg);padding:13px 16px;box-shadow:var(--shadow);
}
.filters>div{flex:1 1 165px;min-width:0}
.filters>label.f{margin-bottom:0;align-self:center;flex:0 0 auto}
.filters input,.filters select{width:100%}
.filters button{flex:0 0 auto}

.pill{
  display:inline-block;padding:3px 10px;border-radius:99px;
  font-size:11px;font-weight:700;line-height:1.7;
  background:var(--card2);color:var(--muted);
}
.pill.good{background:#E7F7EE;color:#16A34A}
.pill.warn{background:#FFF4E5;color:#B45309}
.pill.red{background:#FDECEC;color:#B00020}

/* الحالة الفاضية — كانت مستخدمة من غير تعريف برضه */
.empty{padding:34px;text-align:center;color:var(--muted);font-size:13px}

/* ═══ كروت سامري كليكبل (٩/٨) — فلترة بالضغطة ═══ */
a.kpi{display:block;text-decoration:none;color:inherit;cursor:pointer;transition:box-shadow .12s,transform .12s}
a.kpi:hover{box-shadow:var(--shadow-lift);transform:translateY(-1px)}
.kpi.on{border-color:var(--royal-blue);box-shadow:0 0 0 2px var(--blue-050),var(--shadow-lift)}

/* جداول الحضور — محتوى في النص وأول عمود على البداية */
.att-tbl th,.att-tbl td{text-align:center;vertical-align:middle}
.att-tbl th:first-child,.att-tbl td:first-child{text-align:start}
.frow{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:12px}
/* ⚠️ **صف بأعمدة ثابتة — الخانة المخبّية بتسيب مكانها فاضي.**

   `.frow` العادي بيستخدم `auto-fit`، وده **بيطوي** العمود الفاضي:
   لما خانتين من تلاتة بيتخبّوا بـ`display:none`، الباقية بتتمدد على
   عرض الصف كله. حصل ده في «مدة التعاقد»: الدروب داون كانت بعرض
   الشاشة قبل الاختيار وبترجع طبيعية بعده — شكل بيتغيّر تحت إيد
   المستخدم من غير سبب مفهوم.

   `auto-fill` بيحجز الأعمدة الفاضية بدل ما يطويها، فالخانة الظاهرة
   بتفضل بعرضها الطبيعي والباقي فراغ. */
.frow.keep{grid-template-columns:repeat(auto-fill,minmax(170px,1fr))}
.btn{display:inline-block;padding:8px 18px;border-radius:10px;background:var(--card);border:1px solid var(--border);cursor:pointer;font-weight:700;font-size:12.5px;color:var(--text);font-family:inherit;transition:.15s}
.btn:hover{border-color:var(--royal-blue);color:var(--royal-blue)}
.btn.gold{background:var(--brand-gradient);color:#fff;border-color:transparent;box-shadow:0 2px 8px rgba(18,57,155,.25)}
.btn.gold:hover{color:#fff;filter:brightness(1.1)}
.btn.green{background:#E7F7EE;color:#0F7A38;border-color:transparent}
.btn.red{background:#FDECEC;color:var(--red);border-color:transparent}
.btn.sm{padding:5px 12px;font-size:11.5px}

/* ═══ زرار «عرض» في آخر صف الجدول — `partials/_view` ═══
   طلب المالك (١٥ أغسطس): «أي حاجة في الجدول اعملي فيو ليها في تاب
   جديد أو يفتح على صفحتها». الستايل هنا مش في الصفحات عشان أي شاشة
   تستخدم البارشال تلاقيه شغال من غير نسخ CSS.
   ⚠️ `td.act` عرضه `1%` — التريك المعروفة اللي بتخلّي العمود ياخد
   أقل عرض ممكن، فمابياكلش من عرض أعمدة البيانات. */
.vbtn{display:inline-flex;align-items:center;gap:5px;padding:4px 9px;border-radius:8px;
      border:1px solid var(--border);background:var(--card);color:var(--royal-blue);
      font-size:11px;font-weight:800;text-decoration:none;white-space:nowrap;
      line-height:1.6;transition:.15s}
a.vbtn:hover{border-color:var(--royal-blue);background:var(--royal-blue);color:#fff}
.vbtn.off{color:var(--muted);opacity:.35;cursor:not-allowed}
.vbtn-i{font-size:12px;line-height:1}
th.act,td.act{width:1%;white-space:nowrap;text-align:center}
@media (max-width:1100px){.vbtn-t{display:none}}
@media print{th.act,td.act{display:none}}
.flash{background:#E7F7EE;color:#0F7A38;border-radius:12px;padding:11px 16px;font-weight:800;font-size:13px;margin-bottom:14px}
.flash.err{background:#FDECEC;color:var(--red)}
/* ═══════════════════════════════════════════════════════════
   المودالات — التوسيط والمقاس على `dialog` نفسه
   ═══════════════════════════════════════════════════════════

   ⚠️ **كان معتمد على كلاس `.dlg` اللي المستخدم لازم يفتكره.**
   `dialog .dlg` هي اللي كانت شايلة الـpadding والعرض، فأي مودال
   اتكتب فيه `<form>` من غير الكلاس كان بيطلع بلا حواف ولا عرض،
   ملزوق في ركن الشاشة. الستايل بقى على العنصر نفسه، والكلاس بقى
   زيادة مش شرط.

   ⚠️ **`margin:auto` + `inset:0` مطلوبين للتوسيط الرأسي.** المتصفح
   بيوسّط الـ`dialog` أفقياً بس افتراضياً، والرأسي بيسيبه لأعلى
   الشاشة — وده اللي كان بيخلّي المودال يطلع فوق ملزوق.

   ⚠️ **`max-height` + `overflow` على المحتوى مش على الـ`dialog`** —
   المودال الطويل (فورم فيه جدول) كان بيتقص من تحت والأزرار
   تختفي بره الشاشة من غير أي سكرول. */
dialog{
  border:none;border-radius:16px;padding:0;
  box-shadow:0 20px 60px rgba(0,0,0,.25);
  margin:auto;inset:0;
  max-width:96vw;max-height:92vh;
  overflow:visible;
  background:var(--card);color:var(--text);
  font-family:inherit;font-size:14px;
  direction:{{ $isRtl ? 'rtl' : 'ltr' }};
}
dialog::backdrop{background:rgba(10,10,10,.45)}
/* أي عنصر مباشر جوّه المودال بياخد الحواف والعرض — مش `.dlg` بس */
dialog>form,dialog>div,dialog>section,dialog .dlg{
  padding:20px 22px;
  width:min(620px,92vw);
  max-height:92vh;overflow-y:auto;
  font-family:inherit;
  direction:{{ $isRtl ? 'rtl' : 'ltr' }};
  box-sizing:border-box;
}
/* المودال العريض — للفورمات اللي فيها جدول */
dialog.wide>form,dialog.wide>div,dialog.wide .dlg{width:min(900px,94vw)}
dialog h3{font-size:16px;font-weight:900;margin-bottom:6px}
dialog h4{font-size:16px;font-weight:900;margin-bottom:14px}
/* ⚠️ صفوف الحقول جوّه المودال بتلف على الموبايل — `.frow` الأصلية
   بتفضل صف واحد وبتخرّج المحتوى بره الشاشة الضيقة */
dialog .frow{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:10px}
dialog .frow>*{flex:1 1 220px;min-width:0}
dialog .formbar{display:flex;align-items:center;gap:8px;margin-top:16px}
dialog .formbar-sp{flex:1}
/* ═══════════════════════════════════════════════════════════
   السيلكت القابل للبحث (١١ أغسطس ٢٠٢٦) — طبقة عامة
   ═══════════════════════════════════════════════════════════
   أي <select> فيه أكتر من 7 اختيارات بياخد زرار عرض + لوحة فيها
   خانة بحث (السكريبت آخر الصفحة). السيلكت الأصلي بيتخبى بس بيفضل
   في الفورم — هو اللي بيتبعت، واللوحة مجرد واجهة بتكتب فيه.

   ⚠️ اللوحة `position:fixed` مش absolute — نفس قرار قايمة الجرس:
   جوه المودالات `dialog>form` عنده overflow-y:auto وكان هيقص لوحة
   absolute لأي سيلكت قريب من آخر الفورم. الـfixed بيهرب من القص،
   ومفيش مشكلة top layer لأن اللوحة لسه ابن للـdialog في الـDOM.
   ⚠️ `.ssel-panel[hidden]` لازم قاعدة صريحة — الكلاس بـdisplay:flex
   بييجي بعد قاعدة [hidden] بتاعة المتصفح فكان بيغلبها واللوحة
   تفضل مفتوحة على طول. */
select.ssel-native{display:none!important}
.ssel{position:relative;display:inline-block;max-width:100%;vertical-align:middle}
.filters .ssel{width:100%}
.ssel-btn{display:flex;align-items:center;justify-content:space-between;gap:8px;width:100%;background:var(--card);border:1px solid var(--border);border-radius:10px;padding:9px 13px;font-family:inherit;font-size:13px;color:var(--text);cursor:pointer;text-align:start;transition:.15s}
.ssel-btn:focus{border-color:var(--royal-blue);box-shadow:0 0 0 3px rgba(18,57,155,.14);outline:none}
.ssel-btn:disabled{opacity:.55;cursor:default}
.ssel-btn.bad{border-color:var(--red)!important;background:#FDECEC!important}
.ssel-lbl{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ssel-arw{font-size:10px;color:var(--muted);flex-shrink:0}
.ssel-panel{position:fixed;z-index:650;background:var(--card);border:1px solid var(--border);border-radius:var(--r-md);box-shadow:var(--shadow-lift);padding:8px;display:flex;flex-direction:column;gap:6px}
.ssel-panel[hidden]{display:none}
.ssel-q{width:100%}
.ssel-list{max-height:260px;overflow-y:auto;overscroll-behavior:contain}
.ssel-grp{font-size:10.5px;font-weight:800;color:var(--muted);padding:7px 9px 3px;position:sticky;top:0;background:var(--card);z-index:1}
.ssel-opt{padding:7px 11px;border-radius:8px;cursor:pointer;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ssel-opt:hover{background:var(--card2)}
.ssel-opt.on{background:var(--blue-050);color:var(--royal-blue);font-weight:800}
.ssel-opt.dis{color:var(--muted);opacity:.5;cursor:default}
.ssel-none{padding:10px;text-align:center;color:var(--muted);font-size:12px}
@media print{.ssel-panel{display:none!important}}
.pag{display:flex;gap:6px;margin-top:14px;flex-wrap:wrap;font-size:12.5px}
/* ⚠️ قالب لارافيل simple-default بيرندر <nav><ul><li> — من غير التصفير
   ده اللينكات كانت بتظهر نقط ليستة مرصوصة عمودي (بلاغ ١٨/٨/٢٠٢٦).
   التصفير هنا بيصلّح كل صفحات السيستم مرة واحدة. */
.pag nav{display:flex;flex:1}
.pag ul{display:flex;gap:6px;flex-wrap:wrap;list-style:none;margin:0;padding:0;flex:1;justify-content:space-between}
.pag li{list-style:none}
.pag a,.pag span{display:inline-block;padding:6px 14px;border-radius:9px;border:1px solid var(--border);background:#fff;font-weight:700}
.pag a:hover{border-color:var(--royal-blue);color:var(--royal-blue)}
.pag .disabled span{opacity:.45}
.pag .on{background:var(--royal-blue);border-color:var(--royal-blue);color:#fff;font-weight:800}
::-webkit-scrollbar{width:9px;height:9px}
::-webkit-scrollbar-thumb{background:#CFCFD8;border-radius:8px}
::-webkit-scrollbar-thumb:hover{background:#B4B4C2}
/* سكرول بار السايدبار فاتح — على التدرج الغامق */
.sidebar::-webkit-scrollbar{width:6px}
.sidebar::-webkit-scrollbar-track{background:transparent}
.sidebar::-webkit-scrollbar-thumb{background:rgba(255,255,255,.26);border-radius:8px}
.sidebar::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,.42)}
.sidebar{scrollbar-width:thin;scrollbar-color:rgba(255,255,255,.28) transparent}
@media print{.sidebar{display:none}.card,.kpi{box-shadow:none}}
</style>
{{-- ═══ طي السايد منيو لأيقونات (طلب المالك ٢١/٨) ═══
     الحالة بتتقري قبل الرسم عشان مفيش فلاش، والكونتينر بيتوسع
     لوحده (فليكس). في وضع الأيقونات أي دوسة على مجموعة بتفتح
     المنيو الأول وبعدين المجموعة نفسها. --}}
<script>
  if (localStorage.getItem('pmxNavMini') === '1') {
    document.documentElement.classList.add('nav-mini');
  }

  function pmxNavToggle() {
    var mini = document.documentElement.classList.toggle('nav-mini');
    localStorage.setItem('pmxNavMini', mini ? '1' : '0');
  }

  document.addEventListener('click', function (e) {
    if (!document.documentElement.classList.contains('nav-mini')) return;
    if (e.target.closest && e.target.closest('.sidebar summary')) pmxNavToggle();
  });
</script>
<style>
.nav-toggle{
  align-self:flex-end;flex-shrink:0;
  width:30px;height:30px;border-radius:50%;
  background:rgba(255,255,255,.14);border:none;cursor:pointer;
  color:#fff;font-size:14px;line-height:1;font-family:inherit;
  display:flex;align-items:center;justify-content:center;
  margin-bottom:2px;transition:background .15s;
}
.nav-toggle:hover{background:rgba(255,255,255,.28)}
html.nav-mini .nav-toggle{align-self:center}
html.nav-mini .sidebar{width:64px;padding:14px 7px}
html.nav-mini .logo{padding:4px 0 10px;display:flex;justify-content:center}
html.nav-mini .logo .brandmark,
html.nav-mini .logo .sub{display:none}
html.nav-mini .logo::after{content:'⚡';font-size:20px;color:var(--brand-yellow)}
html.nav-mini .navgrp-acc>summary{justify-content:center;padding:11px 0}
html.nav-mini .navgrp-acc>summary .gt,
html.nav-mini .navgrp-acc>summary .cnt,
html.nav-mini .navgrp-acc>summary::after{display:none}
html.nav-mini .navgrp-acc>summary .gi{width:auto;font-size:17px}
html.nav-mini .navbody{display:none}
html.nav-mini .side-user{display:none}
</style>
</head>
<body>
@php
    use App\Support\Access;

    $u = auth()->user();
    $nav = Access::navFor($u);

    // ⚠️ **العدّاد بيتحسب بس لو لينكه ظاهر.** قبل كده التلات كويريات
    // كانوا بيتنفّذوا على كل صفحة لكل مستخدم — يعني المحاسب كان بيعدّ
    // طلبات الريفيل اللي هو أصلاً مش شايفها.
    $navCounts = [];

    $shown = collect($nav)->flatten(1)->pluck(4)->filter()->all();

    if (in_array('requests', $shown, true)) {
        $navCounts['requests'] = \App\Models\ClientRequest::whereIn('status', ['pending', 'review'])->count();
    }

    if (in_array('replenishments', $shown, true)) {
        $navCounts['replenishments'] = \App\Models\ReplenishmentRequest::where('status', 'pending')->count();
    }

    if (in_array('po_approvals', $shown, true)) {
        $navCounts['po_approvals'] = \App\Models\PurchaseOrder::where('approval_status', 'pending')->count();
    }

    if (in_array('dues', $shown, true)) {
    // ⚠️ كاش دقيقة: العدّاد ده كان كويري على كل صفحة لكل مستخدم.
        $navCounts['dues'] = cache()->remember('nav.open_dues', 60,
            fn () => \App\Models\ContractDue::where('status', 'due')->count());
    }

    // ═══ عدّادات جديدة (قرار المالك ٨ أغسطس ٢٠٢٦) ═══
    //
    // ⚠️ **نفس قاعدة الكسل**: الكويري بتتنفّذ بس لو اللينك ظاهر
    // للرول ده. المحاسب مايعدّش أوامر تجهيز، وأمين المخزن مايعدّش
    // طلبات عملاء.
    if (in_array('picks', $shown, true)) {
        // تجهيز الطلبات — اللي لسه مستني أو تحت التجهيز
        $navCounts['picks'] = \App\Models\PickOrder::whereIn('status', ['requested', 'picking'])->count();
    }

    if (in_array('attendance_review', $shown, true)) {
        // أيام الحضور اللي السيستم قفلها ومستنية اعتماد (2026-08-09)
        $navCounts['attendance_review'] = \App\Models\AttendanceDay::needsReview()->count();
    }

    if (in_array('transfers', $shown, true)) {
        // تحويلات مستنية استلام
        $navCounts['transfers'] = \App\Models\StockTransfer::where('status', 'sent')->count();
    }
@endphp
<div class="wrap">

  <aside class="sidebar">
    {{-- زرار طي المنيو لأيقونات (٢١/٨) — الحالة في localStorage --}}
    <button type="button" class="nav-toggle" onclick="pmxNavToggle()">☰</button>

    {{-- ⚠️ **مش `erp.overview` ثابتة.** أمين المخزن مالوش دعوة بيها،
         واللوجو موجود في كل صفحة — يعني كان أكتر عنصر بيتدَاس عليه
         بيوديه على 403. --}}
    <a class="logo" href="{{ route(\App\Support\Access::home($u)) }}">
      <img src="{{ asset('brand/logo/logo-h-white.svg') }}" alt="PROMAX" class="brandmark">
      <div class="sub">{{ $isRtl ? 'صناعات غذائية' : 'FOOD INDUSTRIES' }} <span>ERP</span></div>
    </a>

    {{-- ⚠️ **السايدبار بيترسم من `Access::navFor()` مش من HTML مكتوب
         بإيد.** لما كان مكتوب، كل اللينكات كانت بتبان لكل اللي داخل
         وكان لازم تفتكر تحط `@if` على كل واحد — وأول لينك تنساه بيبان
         لواحد المفروض مايشوفوش. دلوقتي نفس الدالة اللي بتحرس الراوت
         هي اللي بتقرر اللينك يبان ولا لأ، فمستحيل يتفرقوا. --}}
    {{-- ═══════════════════════════════════════════════════════════
         المنيو أكورديون — تاب واحد مفتوح بس (قرار المالك ٨/٨/٢٠٢٦)
         ═══════════════════════════════════════════════════════════

         ⚠️ **كل المجموعات كانت مفتوحة على طول** — ٤٠+ لينك في عمود
         واحد، والمستخدم بيسكرول يدوّر على شاشة يعرف مكانها. دلوقتي
         مجموعة واحدة مفتوحة، ودوس على تانية بيقفل اللي قبلها.

         ⚠️ **المجموعة اللي فيها الصفحة النشطة بتفتح لوحدها** — من
         غير كده المستخدم بيفتح صفحة ويلاقي المنيو مقفول ومش عارف
         هو فين.

         ⚠️ **العدّاد بيتجمّع على رأس المجموعة كمان** — لو مقفولة،
         الرقم اللي جواها لازم يفضل باين وإلا الأكورديون بيخبّي شغل
         مستني.

         ⚠️ `<details>` مش جافاسكربت: بيشتغل من غير سكربت، وبيفضل
         شغال لو السكربت وقع. القفل التلقائي للباقي بس هو اللي
         بالجافاسكربت. --}}
    @foreach ($nav as $group => $links)
        @php
            $groupActive = collect($links)->contains(fn ($l) => request()->routeIs($l[3]));
            $groupCount = collect($links)
                ->sum(fn ($l) => $l[4] ? ($navCounts[$l[4]] ?? 0) : 0);
        @endphp
        <details class="navgrp-acc" data-acc @if ($groupActive) open @endif>
            {{-- ⚠️ **الأيقونة من `Access::GROUP_ICONS`** — جنب الاسم
                 مش بدله. الاسم لوحده بيتقري، والأيقونة بتخلّي العين
                 تلاقي المجموعة من غير ما تقرا. --}}
            {{-- `title` للتولتيب في وضع الأيقونات (٢١/٨) --}}
            <summary class="{{ $groupActive ? 'on' : '' }}" title="{{ __($group) }}">
                <span class="gi">{{ \App\Support\Access::GROUP_ICONS[$group] ?? '•' }}</span>
                <span class="gt">{{ __($group) }}</span>
                @if ($groupCount > 0)
                    <span class="cnt">{{ $groupCount }}</span>
                @endif
            </summary>
            {{-- ⚠️ **`.navbody` مش لينكات سايبة** — هي اللي شايلة
                 الخلفية الغامقة والخط الجانبي اللي بيقولوا «دول جوّه
                 المجموعة اللي فوق». --}}
            <div class="navbody">
                @foreach ($links as [$route, $icon, $label, $pattern, $counter])
                    <a class="navlink {{ request()->routeIs($pattern) ? 'active' : '' }}"
                       href="{{ route($route) }}">
                        {{ $icon }} {{ __($label) }}
                        @if ($counter && ($navCounts[$counter] ?? 0) > 0)
                            <span class="cnt">{{ $navCounts[$counter] }}</span>
                        @endif
                    </a>
                @endforeach
            </div>
        </details>
    @endforeach

    <div class="side-user">
      <div class="nm">{{ $u?->name }}</div>
      <div class="rl">{{ $u?->roleLabel() }} @if($u?->code) • {{ $u->code }} @endif</div>

      {{-- تبديل اللغة — بيتحفظ على اليوزر فيفضل معاه على أي جهاز --}}
      <div class="langsw">
        @foreach (\App\Models\User::LOCALES as $code => $label)
          <form method="POST" action="{{ route('locale.switch', $code) }}" style="flex:1;display:flex">
            @csrf
            <button type="submit" class="{{ $locale === $code ? 'on' : '' }}"
                    aria-label="{{ $label }}" @if($locale === $code) aria-current="true" @endif>
              {{ $label }}
            </button>
          </form>
        @endforeach
      </div>

      <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit">{{ __('common.logout') }}</button></form>
    </div>
  </aside>

  <main class="main">
    <div class="topbar">
      <h1>@yield('title')</h1>
      <div class="meta">
        @yield('actions')

        {{-- ═══ شريط الاختصارات (طلب المالك ١١ أغسطس ٢٠٢٦) ═══
             أهم الصفحات في الهيدر على كل شاشة — من غير ما تفتح المنيو.
             ⚠️ **كل اختصار مفلتر بصلاحية الرول** (`Access::allows`) —
             محاسب مايشوفش اختصار للتصفية بتاعت غيره، وأمين المخزن
             مايشوفش شاشات الميدان. المفضّلة بتتحفظ في `localStorage`
             (لكل متصفح) فمفيش مايجريشن ولا عمود جديد. --}}
        @php
            $u2 = auth()->user();
            // [screen-key للصلاحية, route, أيقونة, ليبل]
            $shortcutDefs = [
                ['erp.overview', 'erp.overview', '🏠', __('nav.overview')],
                ['ops.open_visits', 'ops.open_visits', '🚪', __('nav.open_visits')],
                ['ops.live', 'ops.live', '🛰️', __('nav.live')],
                ['ops.vans', 'ops.vans', '🚐', __('nav.vans_board')],
                ['ops.pos', 'ops.pos', '🚚', __('nav.purchase_orders')],
                ['ops.requests', 'ops.requests', '✅', __('nav.client_requests')],
                ['ops.replenishments', 'ops.replenishments', '📦', __('nav.replenishments')],
                ['ops.handout', 'ops.handout', '📤', __('field.handout')],
                ['erp.collections', 'erp.collections', '💵', __('nav.field_collections')],
                ['erp.repclose', 'erp.repclose', '🤝', __('settle.title')],
                ['erp.clients', 'erp.clients', '👥', __('nav.clients')],
                // ⚠️ مدخل الديفيجنز (١٧/٨) — شاشة بلا لينك شاشة محدش هيفتحها
                ['erp.divisions', 'erp.divisions', '🗂️', __('client.divisions')],
                ['erp.setup.chains', 'erp.setup', '⚙️', __('client.setup_chains')],
                ['erp.client_locations', 'erp.client_locations', '📍', __('geo.confirm_locations')],
                // ⚠️ **مدخل للشاشة الجديدة** (١٧/٨) — الشاشة اللي
                // مالهاش لينك في المنيو شاشة محدش هيفتحها. نفس
                // الدرس اللي اتكرر النهاردة مرتين: عقد السلسلة
                // وتعديل اسم قايمة الأسعار، الاتنين باك إند كامل
                // بلا مدخل.
                ['erp.client_locations.credits', 'erp.client_locations', '🧭', __('geo.rep_credits')],
                ['wh.picks', 'wh.picks', '📋', __('nav.prep_orders')],
                ['erp.dues', 'erp.dues', '💰', __('nav.dues')],
            ];

            $shortcuts = [];
            foreach ($shortcutDefs as [$screen, $routeName, $icon, $label]) {
                if (\Illuminate\Support\Facades\Route::has($routeName)
                    && \App\Support\Access::allows($u2, $screen)) {
                    $shortcuts[] = [
                        'key' => $routeName,
                        'url' => route($routeName),
                        'icon' => $icon,
                        'label' => $label,
                        'active' => request()->routeIs($routeName),
                    ];
                }
            }
        @endphp

        @if ($shortcuts !== [])
            {{-- الشيبس المثبّتة بتترندر بالجافاسكربت من localStorage --}}
            <div class="qbar" id="qbar"></div>

            <details class="bell qsc">
                <summary title="{{ __('nav.shortcuts') }}">⚡</summary>
                <div class="bell-panel qmenu">
                    <div class="bell-head"><b>⚡ {{ __('nav.shortcuts') }}</b>
                        <span style="font-size:10.5px;color:var(--muted)">{{ __('nav.shortcuts_hint') }}</span>
                    </div>
                    @foreach ($shortcuts as $sc)
                        <div class="qrow {{ $sc['active'] ? 'on' : '' }}" data-key="{{ $sc['key'] }}">
                            <button type="button" class="qpin" title="{{ __('nav.pin') }}"
                                    onclick="qcTogglePin('{{ $sc['key'] }}', this)">☆</button>
                            <a class="qgo" href="{{ $sc['url'] }}">
                                <span class="qic">{{ $sc['icon'] }}</span>
                                <span>{{ $sc['label'] }}</span>
                            </a>
                        </div>
                    @endforeach
                </div>
            </details>

            {{-- بيانات الاختصارات للجافاسكربت — عبر json_encode مش الدايركتيف --}}
            @php
                $qcData = collect($shortcuts)->map(fn ($s) => [
                    'key' => $s['key'], 'url' => $s['url'], 'icon' => $s['icon'], 'label' => $s['label'],
                ])->values();
            @endphp
            <script>
            (function () {
                'use strict';
                var ALL = {!! json_encode($qcData, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) !!};
                var KEY = 'promax_shortcuts';

                function pins() {
                    try { return JSON.parse(localStorage.getItem(KEY) || '[]'); }
                    catch (e) { return []; }
                }
                function savePins(p) { localStorage.setItem(KEY, JSON.stringify(p)); }

                // رسم الشيبس المثبّتة في الهيدر
                function renderBar() {
                    var bar = document.getElementById('qbar');
                    if (!bar) return;
                    var p = pins();
                    var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g,
                        function (ch) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]; }); };
                    bar.innerHTML = p.map(function (k) {
                        var it = ALL.find(function (x) { return x.key === k; });
                        if (!it) return '';
                        return '<a class="qchip" href="' + esc(it.url) + '" title="' + esc(it.label) + '">' +
                            '<span>' + it.icon + '</span><span class="qchip-t">' + esc(it.label) + '</span></a>';
                    }).join('');
                }

                // نجوم القايمة تعكس الحالة الحالية
                function syncStars() {
                    var p = pins();
                    document.querySelectorAll('.qrow').forEach(function (row) {
                        var on = p.indexOf(row.dataset.key) !== -1;
                        var b = row.querySelector('.qpin');
                        if (b) { b.textContent = on ? '★' : '☆'; b.classList.toggle('on', on); }
                    });
                }

                window.qcTogglePin = function (key, btn) {
                    var p = pins();
                    var i = p.indexOf(key);
                    if (i === -1) p.push(key); else p.splice(i, 1);
                    savePins(p);
                    renderBar();
                    syncStars();
                };

                renderBar();
                syncStars();
            })();
            </script>
        @endif

        {{-- ═══ جرس الإشعارات (2026-08-09) — نفس صفوف الموبايل ═══
             ⚠️ استعلامين خفاف لكل صفحة (عدّاد + آخر 12) — مقبول.
             لو بقوا تقال، كاش دقيقة بمفتاح اليوزر. --}}
        @php
            $bellUnread = auth()->user()->appNotifications()->whereNull('read_at')->count();
            $bellItems = auth()->user()->appNotifications()->latest()->take(12)->get();
        @endphp
        <details class="bell">
          <summary title="{{ __('common.notifications') }}">
            🔔
            @if ($bellUnread > 0)
                <span class="bell-badge">{{ $bellUnread > 99 ? '99+' : $bellUnread }}</span>
            @endif
          </summary>
          <div class="bell-panel">
            <div class="bell-head">
              <b>{{ __('common.notifications') }}</b>
              @if ($bellUnread > 0)
                  <form method="POST" action="{{ route('notifications.read') }}">
                      @csrf
                      <button class="bell-clear">{{ __('common.mark_all_read') }}</button>
                  </form>
              @endif
            </div>
            @forelse ($bellItems as $note)
                <a class="bell-item {{ $note->read_at ? '' : 'unread' }}"
                   href="{{ route('notifications.go', $note) }}">
                    <span class="bell-dot {{ $note->is_good ? 'good' : 'bad' }}"></span>
                    {{-- ⚠️ **`dir="auto"`** (طلب المالك ١١/٨): الإشعار
                         العربي يترتب يمين-لشمال والإنجليزي شمال-ليمين
                         تلقائياً حسب أول حرف — بدل ما الاتنين يتفرضوا
                         باتجاه الصفحة ويطلع أحدهم متبعثر. --}}
                    <span class="bell-txt">
                        <b dir="auto">{{ $note->title }}</b>
                        @if ($note->body)<span dir="auto">{{ $note->body }}</span>@endif
                        <small>{{ $note->created_at->diffForHumans() }}</small>
                    </span>
                </a>
            @empty
                <div class="bell-empty">{{ __('common.no_notifications') }}</div>
            @endforelse
          </div>
        </details>
        <script>
        // ═══ تموضع قايمة الجرس — fixed على الفيوبورت (2026-08-09) ═══
        //
        // ⚠️ القايمة `position: fixed` فإحداثياتها بالنسبة للشاشة مش
        // للزرار — بنحسبها من مكان الزرار وقت الفتح، وبنحاذي حافة
        // البداية/النهاية حسب اتجاه الصفحة، مع تثبيتها جوه الشاشة
        // على الموبايل. وبتتقفل بالضغط بره أو Esc.
        (function () {
            const bell = document.querySelector('.bell');
            if (!bell) return;

            // ⚠️ **التموضع بقى CSS بحت** (١١/٨ مساءً) — القايمة absolute
            // على الجرس نفسه، فمفيش حساب جافاسكربت يطلّعها بره الشاشة.
            // الباقي هنا: قفل بالضغط بره أو Esc بس.
            document.addEventListener('click', (e) => {
                if (bell.open && !bell.contains(e.target)) bell.open = false;
            });
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') bell.open = false;
            });
        })();
        </script>
      </div>
    </div>

    {{-- ⚠️ **تحذير المايجريشن المعلّقة.** اتحط بعد ما شاشة حفظ عميل
         رمت `Unknown column 'manager_id'` في وش المستخدم: العمود كان
         في المايجريشن، والمايجريشن مااتشغلتش، ومحدش عرف غير من رسالة
         SQL خام. دلوقتي بتبان قبل ما حد يقع فيها.
         ⚠️ للأدمن بس — الرسالة دي فيها أمر terminal، ومالهاش لازمة
         لمندوب أو أمين مخزن. ومكاشة دقيقة عشان مانعملش استعلام في
         كل صفحة. --}}
    @if (auth()->user()?->isAdmin())
        @php
            // ⚠️ **بترجّع الأسماء مش العدد.** «فيه مايجريشن واحدة
            // مااتشغلتش» من غير اسمها بتخلّي اللي شغّل `migrate` من
            // شوية يفتكر إن البانر بايظ — وهو بيقول الحقيقة عن ملف
            // اتضاف بعد ما شغّلها. الاسم بيحسم السؤال في ثانية.
            $pendingMigrations = \Illuminate\Support\Facades\Cache::remember(
                'pending-migrations', 60,
                fn () => rescue(function () {
                    $migrator = app('migrator');
                    $files = array_keys($migrator->getMigrationFiles(database_path('migrations')));

                    return array_values(array_diff($files, $migrator->getRepository()->getRan()));
                }, [], false),
            );
        @endphp
        @if ($pendingMigrations !== [])
            <div class="flash err">
                ⚠️ {{ __('common.pending_migrations', ['count' => count($pendingMigrations)]) }}
                <code style="font-family:monospace;direction:ltr;display:inline-block">php artisan migrate</code>
                <div style="font-family:monospace;direction:ltr;font-size:11px;margin-top:6px;opacity:.85">
                    {{ implode(' · ', $pendingMigrations) }}
                </div>
            </div>
        @endif
    @endif

    @if (session('ok'))<div class="flash">{{ session('ok') }}</div>@endif
    @if ($errors->any())<div class="flash err">{{ $errors->first() }}</div>@endif

    @yield('content')
  </main>
</div>

<script>
// ألوان التشارتس — من باليت الهوية الرسمية (Brand Guidelines 2024).
// gold اسم قديم متسيب للتوافقية وبيوجّه على الأزرق الملكي.
const BRAND = {
  royal:'#12399B', purple:'#602D90', pink:'#D74297', yellow:'#FFF927',
  blue:'#2470E3',  violet:'#7D40D6', sky:'#82C7FF',  lilac:'#C4A6FF',
  green:'#16A34A', orange:'#EA8C1C', red:'#DC2626',
  grid:'#E4E4EA',  muted:'#6B6B7B',
  gold:'#12399B',
};
// الترتيب مهم: أول لونين هما الأساسيين، وبعدين التدرجات
const PALETTE = [BRAND.royal, BRAND.purple, BRAND.blue, BRAND.violet,
                 BRAND.pink, BRAND.sky, BRAND.lilac, BRAND.orange];
const GRID = BRAND.grid;
const IS_RTL = {{ $isRtl ? 'true' : 'false' }};
const CURRENCY = {!! \Illuminate\Support\Js::from(__('common.currency')) !!};

if (window.Chart) {
  Chart.defaults.font.family = IS_RTL ? 'Cairo' : 'Inter';
  Chart.defaults.font.size = 12;
  Chart.defaults.color = BRAND.muted;
  Chart.defaults.maintainAspectRatio = false;
  Chart.defaults.plugins.legend.rtl = IS_RTL;
  Chart.defaults.plugins.legend.labels.usePointStyle = true;
  Chart.defaults.plugins.legend.labels.boxWidth = 8;
  Chart.defaults.plugins.legend.labels.padding = 14;
  Chart.defaults.plugins.tooltip.rtl = IS_RTL;
  Chart.defaults.plugins.tooltip.backgroundColor = '#0A0A0A';
  Chart.defaults.plugins.tooltip.padding = 10;
  Chart.defaults.plugins.tooltip.cornerRadius = 8;
  Chart.defaults.plugins.tooltip.titleFont = { family:Chart.defaults.font.family, weight:'700' };
  Chart.defaults.plugins.tooltip.bodyFont = { family:Chart.defaults.font.family };
  Chart.defaults.plugins.tooltip.callbacks.label = (ctx) => {
    const v = ctx.parsed.y ?? ctx.parsed;
    return ' ' + (ctx.dataset.label ? ctx.dataset.label + ': ' : '') + Number(v).toLocaleString('en-US') + ' ' + CURRENCY;
  };
}
function shortNum(v){ if(Math.abs(v)>=1e6) return (v/1e6).toFixed(1)+'M'; if(Math.abs(v)>=1e3) return (v/1e3).toFixed(0)+'K'; return v; }
const AXES = { y:{ border:{display:false}, grid:{color:GRID}, ticks:{callback:shortNum} },
               x:{ border:{display:false}, grid:{display:false} } };
function openDlg(id){ document.getElementById(id).showModal(); }
function closeDlg(id){ document.getElementById(id).close(); }

/* ===== خرائط PROMAX (OpenStreetMap) ===== */
const MAP_PINS = {
  start:   BRAND.purple, check_in: BRAND.blue,   check_out: BRAND.red,
  sale:    BRAND.green,  deliver:  BRAND.royal,  refill:    BRAND.violet,
  request: BRAND.orange, client:   BRAND.royal,  default:   BRAND.muted,
};

/** دبوس ملوّن برقم اختياري */
function promaxPin(color, label) {
  return L.divIcon({
    className: '',
    html: `<div style="background:${color};width:26px;height:26px;border-radius:50% 50% 50% 0;
           transform:rotate(-45deg);border:2.5px solid #fff;
           box-shadow:0 2px 6px rgba(0,0,0,.35);display:grid;place-items:center">
           <span style="transform:rotate(45deg);color:#fff;font:800 11px Cairo,sans-serif">
           ${label ?? ''}</span></div>`,
    iconSize: [26, 26], iconAnchor: [13, 26], popupAnchor: [0, -26],
  });
}

/**
 * بيرسم خريطة في العنصر elId
 * points = [{lat, lng, title, subtitle, type, label}]
 * opts   = { route: true لرسم خط المسار }
 */
function promaxMap(elId, points, opts = {}) {
  const el = document.getElementById(elId);
  if (!el) return null;

  const pts = (points || []).filter(p => p.lat && p.lng);

  if (!pts.length) {
    el.innerHTML = '<div style="display:grid;place-items:center;height:100%;'
      + 'color:var(--muted);font-size:13px">{{ __('common.no_map_points') }}</div>';
    return null;
  }

  const map = L.map(elId, { scrollWheelZoom: false });
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap',
  }).addTo(map);

  const latlngs = [];
  pts.forEach((p, i) => {
    const color = MAP_PINS[p.type] || MAP_PINS.default;
    const label = p.label ?? (opts.route ? (i + 1) : '');
    const m = L.marker([p.lat, p.lng], { icon: promaxPin(color, label) }).addTo(map);

    m.bindPopup(`<div style="font:600 13px ${IS_RTL ? 'Cairo' : 'Inter'},sans-serif;direction:${IS_RTL ? 'rtl' : 'ltr'};text-align:start">
      ${p.title || ''}
      ${p.subtitle ? `<div style="font-weight:400;font-size:11.5px;color:#6B6B66;margin-top:3px">${p.subtitle}</div>` : ''}
    </div>`);

    latlngs.push([p.lat, p.lng]);
  });

  if (opts.route && latlngs.length > 1) {
    L.polyline(latlngs, { color: '#12399B', weight: 4, opacity: .85 }).addTo(map);
  }

  if (latlngs.length === 1) {
    map.setView(latlngs[0], 15);
  } else {
    map.fitBounds(L.latLngBounds(latlngs).pad(0.18));
  }

  // التمرير بالعجلة بعد أول كليك بس — عشان مايعطلش تمرير الصفحة
  map.on('click', () => map.scrollWheelZoom.enable());
  map.on('mouseout', () => map.scrollWheelZoom.disable());

  return map;
}

/* ═══════════════════════════════════════════════════════════════
   أدوات الجداول العامة (بوليش 2026-08-06) — بتشتغل على كل الشاشات:
   1) فريز: أي جدول أطول من 62vh حاويته بتتقفل والهيدر بيثبت.
   2) سورت: ضغطة على أي عمود بترتب صفوف الصفحة (رقمي/نصي أوتوماتيك).
   3) بحث سريع: جدول فيه أكتر من 12 صف من غير سيرش بياخد خانة فلترة.
   الاستثناءات: جداول المودالات، والجداول المعلّمة data-plain،
   والعمود اللي جواه لينك (سورت السيرفر بتاعه أولى).
   ⚠️ الترتيب هنا على صفوف الصفحة الحالية بس — القوايم المقسمة صفحات
   اللي محتاجة ترتيب على كل الداتا ليها سورت سيرفر خاص (زي العملاء).
   ═══════════════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  const T = {
    search: {!! json_encode(__('common.search'), JSON_UNESCAPED_UNICODE) !!},
    total:  {!! json_encode(__('common.total'), JSON_UNESCAPED_UNICODE) !!},
    rows:   {!! json_encode(__('common.rows_count'), JSON_UNESCAPED_UNICODE) !!},
    view:   {!! json_encode(__('common.view'), JSON_UNESCAPED_UNICODE) !!},
    all:    {!! json_encode(__('common.all'), JSON_UNESCAPED_UNICODE) !!},
    zoom:   {!! json_encode(__('common.close'), JSON_UNESCAPED_UNICODE) !!},
  };

  /* ══════════════════════════════════════════════════════════════
     زرار «عرض» التلقائي لصفوف الجداول  ·  ١٥ أغسطس ٢٠٢٦
     ══════════════════════════════════════════════════════════════

     طلب المالك: «أي حاجة في الجدول اعملي فيو ليها في تاب جديد أو
     يفتح على صفحتها».

     في السيستم ٢٠+ شاشة صفوفها `class="clickable"` مع
     `onclick="location.href='…'"` — أفوردانس مخفي (المستخدم لازم
     يخمّن إن الصف بيتدوس) وبيفتح في نفس التاب فبيضيّع الفلتر
     والسكرول. تعديل الـ٢٠ بليد بالإيد كان معناه ٢٠ فرصة لخطأ في
     عدّ الأعمدة، وأي شاشة جديدة تنسى الزرار من تاني.

     فالمصدر واحد: الصف نفسه. الفانكشن دي بتقرا الـURL اللي **موجود
     أصلاً** في الـonclick وتبني منه عمود أكشن حقيقي.

     ⚠️ **بتشتغل قبل أدوات الجدول** (الترتيب/البحث/الإجماليات) عشان
     دي بتقرا `headRow.cells.length` — لو زوّدنا عمود بعدها، صف
     الإجماليات يطلع ناقص خانة.

     ⚠️ الجدول اللي فيه `.act` أصلاً (البليد حاطط `partials/_view`
     بإيده) بيتساب زي ما هو — مافيش عمودين.

     ⚠️ `data-no-act` على الـtable بيوقّفها لو صفحة عايزة كده. */
  function addRowViewButtons(table, headRow) {
    if (table.querySelector('th.act, td.act')) return 0;
    if (table.hasAttribute('data-no-act')) return 0;

    const bodyRows = Array.from(table.querySelectorAll('tr'))
      .filter(r => r !== headRow && r.querySelector('td'));

    // الـURL من الـonclick: location.href='…' أو window.open('…')
    const urlOf = function (tr) {
      const raw = tr.getAttribute('onclick') || '';
      const m = raw.match(/(?:location\.href\s*=|window\.open\s*\()\s*(['"])(.*?)\1/);
      return m ? m[2] : null;
    };

    const linked = bodyRows.filter(r => r.classList.contains('clickable') && urlOf(r));
    if (linked.length === 0) return 0;

    const th = document.createElement('th');
    th.className = 'act';
    th.setAttribute('data-nosum', '');
    headRow.appendChild(th);

    bodyRows.forEach(function (tr) {
      // ⚠️ صف الحالة الفاضية بيمدّ خانته بدل ما ياخد خانة جديدة —
      // لو زوّدنا `td` كان هيبان عمود فاضي جنب رسالة «مفيش بيانات».
      const spanning = tr.querySelector('td[colspan]');
      if (spanning && tr.cells.length === 1) {
        spanning.colSpan = (parseInt(spanning.colSpan, 10) || 1) + 1;
        return;
      }

      const td = document.createElement('td');
      td.className = 'act';
      const url = urlOf(tr);

      if (url) {
        const a = document.createElement('a');
        a.className = 'vbtn';
        a.href = url;
        a.target = '_blank';
        a.rel = 'noopener';
        a.title = T.view;
        a.setAttribute('aria-label', T.view);
        // ⚠️ من غير دي الدوسة كانت هتفتح التاب الجديد **و** تنقل
        // التاب الحالي في نفس اللحظة (الصف لسه `clickable`).
        a.addEventListener('click', e => e.stopPropagation());
        a.innerHTML = '<span class="vbtn-i" aria-hidden="true">↗</span>'
                    + '<span class="vbtn-t"></span>';
        a.querySelector('.vbtn-t').textContent = T.view;
        td.appendChild(a);
      }

      tr.appendChild(td);
    });

    // صفوف الـtfoot لازم تكبر معاهم وإلا الجدول يتزحلق
    Array.from(table.tFoot ? table.tFoot.rows : []).forEach(function (tr) {
      const last = tr.cells[tr.cells.length - 1];
      const total = Array.from(tr.cells).reduce((n, c) => n + (c.colSpan || 1), 0);
      if (total < headRow.cells.length && last) {
        const td = document.createElement('td');
        td.className = 'act';
        tr.appendChild(td);
      }
    });

    return 1;
  }

  /* ═══════════════════════════════════════════════════════════════
     عدد صفوف الصفحة  ·  ١٧ أغسطس ٢٠٢٦
     ═══════════════════════════════════════════════════════════════
     كان ثابت `25` لكل جدول في السيستم. طلب المالك مقاسات مختلفة
     (٥٠/١٠٠/الكل) في شاشة التسعير — والحل مايبقاش خاص بشاشة واحدة،
     الجدول واحد في كل مكان.

     ⚠️ **الترتيب: اختيار المستخدم ← اللي البليد طالبه ← الافتراضي.**
     البليد بيحط `data-page="50"` على الجدول لما الشاشة طبيعتها
     تحتاج كده (التسعير: بتسعّر ٥٠ صنف في جلسة)، والمستخدم بيغلب
     على الاتنين واختياره بيتفكر.

     ⚠️ **الصفر = الكل.** مش `Infinity` — القيمة بتتخزن في
     localStorage كنص، و`Infinity` بترجع `null` من `JSON` وبتتقرا
     غلط. */
  const PAGE_OPTS = [25, 50, 100, 0];
  const PAGE_KEY = 'promax.pageSize';

  const savedPage = function () {
    try {
      const v = parseInt(localStorage.getItem(PAGE_KEY), 10);
      return PAGE_OPTS.includes(v) ? v : null;
    } catch (e) { return null; }
  };

  /* ═══════════════════════════════════════════════════════════════
     تكبير الصور  ·  ١٧ أغسطس ٢٠٢٦
     ═══════════════════════════════════════════════════════════════
     أي `<img data-zoom>` بيتفتح في مودال بالحجم الكامل.

     ⚠️ **مستمع واحد على `document` مش مستمع لكل صورة** — الجدول
     فيه ١٠٠ صورة، وربط مستمع لكل واحدة بيتكرر مع كل رندر.

     ⚠️ **`<dialog>` أصلي مش div** — بيقفل بـEsc لوحده وبياخد
     الفوكس، ومحتاجش أي كود إدارة طبقات. */
  const zoomImage = function (src, cap) {
    let dlg = document.getElementById('imgZoom');

    if (!dlg) {
      dlg = document.createElement('dialog');
      dlg.id = 'imgZoom';
      dlg.innerHTML = '<img alt=""><div class="zcap"></div>';
      /* الدوس على أي مكان بيقفل — الصورة نفسها مفيهاش أكشن تاني */
      dlg.addEventListener('click', () => dlg.close());
      document.body.appendChild(dlg);
    }

    dlg.querySelector('img').src = src;
    dlg.querySelector('.zcap').textContent = cap || '';
    dlg.showModal();
  };

  document.addEventListener('click', function (e) {
    const img = e.target.closest('img[data-zoom]');

    if (!img || !img.src) return;

    /* ⚠️ الصورة ممكن تكون جوّه صف `clickable` بيفتح صفحة — لازم
       نمنع الانتشار وإلا الدوسة بتكبّر وتنقل في نفس الوقت. */
    e.preventDefault();
    e.stopPropagation();
    zoomImage(img.src, img.getAttribute('data-zoom') || img.alt);
  });

  /* ⚠️ **تحويل نص الخلية لرقم** — بيشيل الفواصل والعملة والنسبة.
     بيرجع NaN للنص العادي، وده اللي بيفرّق العمود الرقمي عن غيره. */
  const toNum = function (s) {
    if (s === '' || s === '—' || s === '-') return NaN;
    const clean = s.replace(/[^\d.,\-]/g, '').replace(/,/g, '');
    return clean === '' || clean === '-' ? NaN : parseFloat(clean);
  };

  const fmtNum = function (n) {
    return Number(n).toLocaleString('en-US', { maximumFractionDigits: 2 });
  };

  /* ═══════════════════════════════════════════════════════════════
     رقم «نضيف» — النص **كله** رقم، مش «فيه أرقام» (إصلاح 2026-08-08)
     ═══════════════════════════════════════════════════════════════
     ⚠️ **ده كان بيطلّع أرقام خيالية في صف الإجماليات.** `toNum` بتشيل
     أي حاجة مش رقم وتقرا الباقي — فخلية «PCK-1009» بقت `-1009`،
     وخلية «08/08 01:42 AM» بقت `080801`. النتيجة اللي اتشافت فعلاً:
     عمود «أمر تجهيز» إجماليه `-90,468,234` وعمود «موعد الاستلام»
     إجماليه `2,316,035,992,645,388`.
     ⚠️ **والحل مش إضافة كلمات تانية للقايمة السودا.** القايمة دي
     بتفحص **عنوان** العمود، وعمرها ما هتلحق كل صيغة («موعد»،
     «أمر تجهيز»، «بوليصة»...). القرار لازم يتاخد من **المحتوى**:
     خلية واحدة فيها حرف أو `/` أو `:` = العمود ده مش رقم وخلاص. */
  const PURE_NUM = /^[+-]?[0-9][0-9,]*(\.[0-9]+)?$/;

  const pureNum = function (s) {
    /* ⚠️ **أول سطر بس.** كتير من خلايا PROMAX رقم كبير وتحته وصف
       صغير: «1,140» وتحتها «كرتونة 15 + علبة 5». لو قرينا الخلية
       كلها الوصف بيلوّثها والعمود يتشال من الإجماليات وهو أصلاً
       عمود كميات. الرقم دايماً في أول سطر والباقي شرح. */
    const t = String(s || '').split('\n').map(x => x.trim()).filter(Boolean)[0] || '';
    if (t === '' || t === '—' || t === '-' || t === '–') return NaN;
    /* الرمز اللي بعد الرقم مسموح (2,400 ج.م) — اللي قبله وجوّاه لأ */
    const bare = t.replace(/\s*(ج\.م|ج|جنيه|EGP|قطعة|وحدة)\s*$/u, '').trim();
    return PURE_NUM.test(bare) ? parseFloat(bare.replace(/,/g, '')) : NaN;
  };

  document.querySelectorAll('.tablewrap').forEach(function (wrap) {
    if (wrap.closest('dialog')) return;
    const table = wrap.querySelector('table');
    if (!table || table.hasAttribute('data-plain')) return;
    // ⚠️ **المستندات الرسمية بره أدوات الجداول** (١١ أغسطس ٢٠٢٦):
    // الفاتورة وورقة العهدة والمرتجع مستندات للطباعة مش قوايم
    // تفاعلية — بحث/ترتيب/فريز/صف إجماليات عليها بيحطّ خانة بحث
    // فوق الفاتورة ويحبسها في سكرول. `.doc` بيغطّيهم كلهم.
    if (table.closest('.doc') || table.classList.contains('doc-table')) return;

    const headRow = table.tHead ? table.tHead.rows[0] : table.querySelector('tr');
    if (!headRow || !headRow.querySelector('th')) return;

    // ⚠️ **قبل أي حاجة تانية.** الأدوات تحت بتقرا عدد الأعمدة مرة
    // واحدة (`cols`)، فزيادة عمود بعد كده بتكسّر صف الإجماليات.
    addRowViewButtons(table, headRow);

    const allRows = () => Array.from(table.querySelectorAll('tr'))
      .filter(r => r !== headRow && r.querySelector('td') && !r.closest('tfoot'));

    const cols = headRow.cells.length;
    const rows0 = allRows();

    /* ═══════════════════════════════════════════════════════════
       1) محاذاة الهيدر = محاذاة المحتوى
       ═══════════════════════════════════════════════════════════
       ⚠️ **ده كان أكبر باج بصري في السيستم.** العمود الرقمي خلاياه
       `.num` (وسط)، وهيدره `text-align:start` (يمين في العربي) —
       فالرقم في ناحية وعنوانه في الناحية التانية على عرض العمود
       كله. بنمشّي على كل عمود ونسأل خلاياه، والهيدر بياخد نفس
       الكلاس. القاعدة دي كمان بتمسك الأعمدة اللي البليد نسي يحط
       `.num` على خلاياها أصلاً. */
    const numericCol = [];

    for (let c = 0; c < cols; c++) {
      const cells = rows0.map(r => r.cells[c]).filter(Boolean);
      if (cells.length === 0) { numericCol[c] = false; continue; }

      /* العمود رقمي لو الخلايا معلّمة `.num`، **أو** أغلب قيمها أرقام
         ومفيش فيها لينكات/بادجات (دي أعمدة حالة مش أرقام) */
      const marked = cells.some(td => td.classList.contains('num'));
      const rich = cells.some(td => td.querySelector('a, .badge, img, input, select, button'));
      /* ⚠️ `pureNum` مش `toNum` — بالقديمة كان عمود «PCK-1009 / 2026-08-07»
         بيتحسب رقمي، فياخد توسيط و`direction:ltr` ويدخل الإجماليات */
      const nums = cells.filter(td => !isNaN(pureNum(td.textContent))).length;

      const isNum = marked || (!rich && nums >= cells.length * 0.8 && nums > 0);
      numericCol[c] = isNum;

      if (isNum) {
        headRow.cells[c].classList.add('num');
        cells.forEach(td => td.classList.add('num'));
      }

      /* ⚠️ النص الكامل في `title` — الخلية بتتقص بـellipsis والقص
         من غير tooltip معناه معلومة ضاعت من غير ما حد ياخد باله */
      cells.forEach(function (td) {
        if (!td.title && td.scrollWidth > td.clientWidth) td.title = td.textContent.trim();
      });
    }

    /* ═══ 2) الفريز — الهيدر ثابت فوق ═══ */
    if (!wrap.style.maxHeight && table.offsetHeight > window.innerHeight * .62) {
      wrap.style.maxHeight = '66vh';
      wrap.style.overflowY = 'auto';
    }
    if (wrap.style.maxHeight) {
      headRow.querySelectorAll('th').forEach(function (th) {
        th.style.position = 'sticky';
        th.style.top = '0';
        th.style.zIndex = '5';
      });
    }

    /* ⚠️ **وجود باجينيشن سيرفر بيغيّر كل اللي بعده.** الجدول ده
       الصفحة الواحدة منه جزء من النتيجة — فمينفعش نجمعه ولا نضيف
       عليه ترقيم تاني. */
    const card = wrap.closest('.card') || wrap.parentNode;
    /* ⚠️ **`.pag` كانت ناقصة من القايمة** (إصلاح ١٥ أغسطس ٢٠٢٦).
       `partials/_pagination` — الترقيم المستخدم في **كل** الشاشات
       المقسمة صفحات — بيرندر `<div class="pag">`، مش `.pagination`
       ولا `nav[role=navigation]`. فالفحص كان بيقول «مفيش باجينيشن
       سيرفر» على جداول مقسمة فعلاً، والنتيجة حاجتين غلط:
         • **صف إجماليات بيجمع الـ40 صف بتوع الصفحة الحالية بس**
           ويعرضهم كأنهم إجمالي الجدول — كسر مباشر لدوكترين الأرقام
           (شاشة العملاء كانت بتوري «Σ الإجمالي» تحت 40 صف من 685).
         • **ترقيم محلي فوق ترقيم السيرفر** — اللي بيدوس «التالي»
           بيروح لصفحة سيرفر ويلاقي الترقيم المحلي اتصفّر. */
    const serverPager = card && card.querySelector('.pag, .pagination, nav[role="navigation"]');

    /* ═══════════════════════════════════════════════════════════
       3) صف الإجماليات
       ═══════════════════════════════════════════════════════════
       ⚠️ **بيجمع كل الصفوف مش الظاهرة بس** — الجدول اللي فيه فلتر
       أو باجينيشن محلي، الإجمالي بيتحدث مع الفلتر (منطقي: انت
       بتشوف مجموع اللي فلترته) بس مابيتأثرش بالصفحة الحالية.
       ⚠️ وأعمدة زي «كود» و«رقم» و«سنة» بتتشال — مجموع الأكواد رقم
       مالوش أي معنى وبيلخبط اللي بيقرا. */
    const sumCols = [];

    for (let c = 0; c < cols; c++) {
      if (!numericCol[c]) continue;

      /* أ) أعمدة أرقامها نضيفة بس مجموعها مالوش معنى (كود، سنة، نسبة)
         ⚠️ التليفونات واللوكيشن اتضافوا (طلب المالك ١٠/٨): صفحة
         العملاء كانت بتجمع أرقام التليفونات في صف الإجماليات —
         «مش بنجمع تليفونات أكيد». وأي بليد يقدر يستثني عمود بإيده
         بـ`data-nosum` على الـth. */
      if (headRow.cells[c].hasAttribute('data-nosum')) continue;
      const head = (headRow.cells[c].textContent || '').trim().toLowerCase();
      if (/كود|code|رقم|no\.|#|سنة|year|نسبة|%|سعر الوحدة|تاريخ|date|موعد|معاد|ساعة|time|تليفون|موبايل|هاتف|phone|mobile|whats|واتس|لوكيشن|الموقع|location|إحداثيات/.test(head)) continue;

      /* ب) ⚠️ **الفحص الحقيقي: كل خلية لازم تكون رقم نضيف.** الفاضي
         و«—» محايدين (خانة مش متملية مش خطأ)، لكن أي خلية فيها حرف
         أو تاريخ أو كود بتشيل العمود كله من الإجماليات. عمود مالوش
         مجموع بيفضل **فاضي** في صف الـTotals — أحسن من رقم غلط. */
      let clean = 0, dirty = 0;
      rows0.forEach(function (r) {
        const td = r.cells[c];
        if (!td) return;
        const t = td.textContent.trim();
        if (t === '' || t === '—' || t === '-' || t === '–') return;
        if (isNaN(pureNum(t))) dirty++; else clean++;
      });

      if (dirty === 0 && clean > 0) sumCols.push(c);
    }

    let foot = null;

    /* ⚠️ **مابنلمسش `tfoot` مكتوب في البليد.** الصفحة اللي حاطة
       إجمالياتها بإيدها بتجيبها من السيرفر على **كل** الصفوف —
       ولو دهسنا عليها بمجموع الصفوف اللي في الـDOM، الرقم يقل
       ويختلف عن باقي الشاشات. ونفس السبب بيمنع التجميع لو
       الباجينيشن من السيرفر: احنا شايفين صفحة مش النتيجة كلها.
       (دوكترين الأرقام: مايتعرضش رقم مالوش نفس المصدر.) */
    if (sumCols.length && rows0.length > 1 && !table.tFoot && !serverPager) {
      foot = table.createTFoot();
      const tr = foot.insertRow();

      for (let c = 0; c < cols; c++) {
        const td = tr.insertCell();
        if (sumCols.includes(c)) td.className = 'num';
        else if (c === 0) td.textContent = 'Σ ' + T.total;
      }
    }

    /* ⚠️ **الإجمالي على المفلتر مش على الظاهر.** لو جمعنا الصفوف
       الظاهرة بس، الجدول اللي فيه باجينيشن كان هيقول إجمالي الصفحة
       — واللي بيقرا فاكره إجمالي الجدول كله. الفلتر بيغيّر الإجمالي
       (منطقي)، والصفحة لأ. */
    const refreshTotals = function () {
      if (!foot || !sumCols.length) return;
      const visible = filtered();
      const cells = foot.rows[0].cells;

      sumCols.forEach(function (c) {
        let sum = 0, any = false;
        visible.forEach(function (r) {
          const v = pureNum((r.cells[c] || {}).textContent || '');
          if (!isNaN(v)) { sum += v; any = true; }
        });
        if (cells[c]) cells[c].textContent = any ? fmtNum(sum) : '';
      });
    };

    /* ═══ 4) السورت ═══ */
    if (!table.querySelector('th.srt')) {
      let cur = -1, dir = 1;
      Array.from(headRow.cells).forEach(function (th, idx) {
        if (th.querySelector('a, input, button') || th.textContent.trim() === '') return;
        th.style.cursor = 'pointer';
        th.title = '⇅';
        th.addEventListener('click', function () {
          dir = (idx === cur) ? -dir : 1;
          cur = idx;
          const rows = allRows();
          const val = r => (r.cells[idx] ? r.cells[idx].textContent.trim() : '');
          rows.sort(function (a, b) {
            const x = val(a), y = val(b);
            if (numericCol[idx]) {
              const nx = pureNum(x), ny = pureNum(y);
              return dir * ((isNaN(nx) ? -Infinity : nx) - (isNaN(ny) ? -Infinity : ny));
            }
            return dir * x.localeCompare(y, ['ar', 'en']);
          });
          const parent = rows[0] && rows[0].parentNode;
          if (parent) rows.forEach(r => parent.appendChild(r));
          headRow.querySelectorAll('.gs-arw').forEach(s => s.remove());
          const arw = document.createElement('span');
          arw.className = 'gs-arw';
          arw.style.cssText = 'font-size:9px;margin-inline-start:4px;color:#fff';
          arw.textContent = dir === 1 ? '▲' : '▼';
          th.appendChild(arw);
          applyPage(1);
        });
      });
    }

    /* ═══════════════════════════════════════════════════════════
       5) الباجينيشن المحلي
       ═══════════════════════════════════════════════════════════
       ⚠️ **بس لو مفيش باجينيشن سيرفر.** الجدول اللي بيقسّم من
       لارافيل ليه لينكات صفحاته؛ لو حطينا واحد محلي فوقه، اللي
       بيدوس «التالي» بيروح لصفحة سيرفر وبيلاقي الترقيم المحلي
       اتصفّر — تجربة مكسورة. */
    let pager = null, page = 1;

    /* حجم الصفحة لهذا الجدول — اختيار المستخدم يغلب على طلب البليد */
    const askedPage = parseInt(table.dataset.page, 10);
    let PAGE = savedPage()
      ?? (PAGE_OPTS.includes(askedPage) ? askedPage : 25);

    const filtered = () => allRows().filter(r => r.dataset.hidden !== '1');

    const applyPage = function (p) {
      const rows = filtered();

      /* ⚠️ **بنخفي الكل الأول حتى من غير باجينيشن** — البحث لوحده
         محتاج يخفي اللي مش مطابق، ولو عرضنا المفلتر بس من غير ما
         نخفي الباقي كان الفلتر مابيعملش أي حاجة. */
      allRows().forEach(r => r.style.display = 'none');

      if (!pager) { rows.forEach(r => r.style.display = ''); refreshTotals(); return; }

      /* ⚠️ **الصفر = الكل** — بنستخدم عدد الصفوف كمقاس صفحة واحدة
         بدل ما نقسم على صفر. و`|| 1` عشان الجدول الفاضي. */
      const size = PAGE || rows.length || 1;
      const pages = Math.max(1, Math.ceil(rows.length / size));
      page = Math.min(Math.max(1, p), pages);

      rows.slice((page - 1) * size, page * size).forEach(r => r.style.display = '');

      pager.innerHTML = '';

      const info = document.createElement('span');
      info.className = 'gs-info';
      info.textContent = T.rows.replace(':n', rows.length);
      pager.appendChild(info);

      /* ═══ مقاس الصفحة ═══
         ⚠️ **جوّه الـpager عن قصد** — المستخدم بيدوّر على «الصفحة
         الجاية» ويلاقي «كام في الصفحة» جنبها. حطها فوق الجدول كان
         هيخلّيها تضيع وسط الفلاتر. */
      const sizeSel = document.createElement('select');
      sizeSel.className = 'gs-size';
      PAGE_OPTS.forEach(function (n) {
        const o = document.createElement('option');
        o.value = String(n);
        o.textContent = n === 0 ? T.all : String(n);
        if (n === PAGE) o.selected = true;
        sizeSel.appendChild(o);
      });
      sizeSel.addEventListener('change', function () {
        PAGE = parseInt(this.value, 10);
        try { localStorage.setItem(PAGE_KEY, String(PAGE)); } catch (e) { /* خاص/ممتلئ */ }
        applyPage(1);
      });
      pager.appendChild(sizeSel);

      const btn = function (label, target, on, dis) {
        const b = document.createElement('button');
        b.type = 'button';
        b.textContent = label;
        if (on) b.className = 'on';
        if (dis) b.disabled = true;
        else b.addEventListener('click', () => applyPage(target));
        pager.appendChild(b);
      };

      btn('‹', page - 1, false, page === 1);

      /* نافذة صفحات حوالين الحالية — 30 صفحة في سطر مالهاش لازمة */
      const from = Math.max(1, page - 2), to = Math.min(pages, page + 2);
      if (from > 1) btn('1', 1, page === 1, false);
      if (from > 2) pager.appendChild(document.createTextNode('…'));
      for (let i = from; i <= to; i++) btn(String(i), i, i === page, false);
      if (to < pages - 1) pager.appendChild(document.createTextNode('…'));
      if (to < pages) btn(String(pages), pages, page === pages, false);

      btn('›', page + 1, false, page === pages);

      refreshTotals();
    };

    /* ⚠️ **الشرط على أصغر مقاس مش على المقاس الحالي** (١٧/٨).
       كان `rows0.length > PAGE` — فلو المستخدم اختار «١٠٠» على جدول
       فيه ٣١ صف، الـpager مايتعملش خالص و**قايمة المقاسات تختفي
       معاه**، ومايبقاش فيه طريقة يرجع لـ٢٥ تاني. */
    if (!serverPager && rows0.length > PAGE_OPTS[0]) {
      pager = document.createElement('div');
      pager.className = 'gs-pager';
      wrap.parentNode.insertBefore(pager, wrap.nextSibling);
    }

    /* ═══ 6) البحث السريع ═══ */
    const hasSearch = card && (card.querySelector('.searchbar')
      || card.querySelector('input[type="text"], input[type="search"]'));

    if (!hasSearch && rows0.length > 12) {
      const inp = document.createElement('input');
      inp.type = 'search';
      inp.placeholder = '🔍 ' + T.search;
      inp.style.cssText = 'width:100%;max-width:320px;margin-bottom:8px;display:block';
      inp.addEventListener('input', function () {
        const q = inp.value.trim().toLowerCase();
        allRows().forEach(function (r) {
          r.dataset.hidden = (!q || r.textContent.toLowerCase().includes(q)) ? '0' : '1';
        });
        applyPage(1);
      });
      wrap.parentNode.insertBefore(inp, wrap);
    }

    applyPage(1);
  });
});

// ═══════════════════════════════════════════════════════════════
// المنيو الأكورديون — تاب واحد مفتوح بس (قرار المالك ٨/٨/٢٠٢٦)
// ═══════════════════════════════════════════════════════════════
//
// ⚠️ **الفتح بيقفل الباقي.** من غير ده الأكورديون بيبقى مجرد
// «طي اختياري» والمستخدم بيفتح الكل ويرجع لنفس العمود الطويل.
//
// ⚠️ **الحالة متخزنة في `localStorage`** — الصفحة بتتعاد مع كل
// ضغطة (مفيش SPA)، فمن غير التخزين المنيو كان هيرجع لوضعه
// الافتراضي كل مرة والمستخدم يفتح نفس المجموعة ٢٠ مرة في اليوم.
//
// ⚠️ **المجموعة النشطة بتغلب المخزّن** — لو المستخدم فتح صفحة من
// مجموعة تانية (من لينك أو بحث)، المفروض يلاقي نفسه.
(function () {
  var accs = document.querySelectorAll('details[data-acc]');
  if (!accs.length) return;

  var KEY = 'navOpenGroup';

  // اللي فيه الصفحة النشطة — `open` اتحطت من البليد
  var activeIdx = -1;

  accs.forEach(function (d, i) {
    if (d.querySelector('.navlink.active')) activeIdx = i;
  });

  if (activeIdx < 0) {
    var saved = parseInt(localStorage.getItem(KEY) || '-1', 10);

    accs.forEach(function (d, i) { d.open = (i === saved); });
  }

  accs.forEach(function (d, i) {
    d.addEventListener('toggle', function () {
      if (!d.open) return;

      localStorage.setItem(KEY, i);

      accs.forEach(function (o) { if (o !== d) o.open = false; });
    });
  });
})();

/* ═══════════════════════════════════════════════════════════════
   السيلكت القابل للبحث — طبقة عامة على كل السيستم (١١ أغسطس ٢٠٢٦)
   ═══════════════════════════════════════════════════════════════
   طلب المالك: «كل الدروب داون منيو تبقى دروب منيو وكمان فيه بحث».
   تحسين تدريجي فانيلا من غير أي مكتبة ومن غير لمس أي بليد:

   - بيتفعّل بس على سيلكت فيه **أكتر من 7 اختيارات** — تحسين سيلكت
     بـ3 اختيارات دوشة من غير فايدة. والمستبعدين: `multiple` ·
     `data-nosearch` · جوه `.doc` (المستندات المطبوعة).
   - **الاختيار بيبعت حدث change حقيقي bubbles** — فكل الفلاتر اللي
     عليها onchange="this.form.submit()" وuserRoleSync() وأشباههم
     شغالين زي ما هما من غير أي تعديل.
   - **خانة البحث من غير name** — عمرها ما بتتبعت مع الفورم، وEnter
     جواها بيختار أول نتيجة ظاهرة (preventDefault بيمنع الإرسال
     الضمني للفورم وقت الكتابة).
   - **مزامنة القيم المكتوبة برمجياً** (openUser/openGeo بيحطوا
     .value مباشرة من غير حدث) — تلات طبقات:
       1) تحديث العرض على أي حدث change حقيقي؛
       2) إعادة قراءة القيمة والاختيارات من السيلكت مع **كل فتح
          للوحة** (بيمسك كمان الاختيارات اللي اتغيرت ديناميكياً)؛
       3) لفّة حوالين openDlg() العامة: أي ديالوج بيتفتح بنحدّث
          عرض كل السيلكتات اللي جواه — وده اللي بيمسك تعيين
          .value المباشر في المودالات (وكمان disabled بتاع
          «الأدمن مايعدّلش رول نفسه»).
   - كيبورد: كتابة بتفلتر · Enter أول نتيجة · Escape بيقفل اللوحة
     بس (preventDefault عشان مايقفلش الـdialog اللي حواليها).
     والضغط بره بيقفل. */
(function () {
  'use strict';

  var Q_PH = {!! json_encode(__('common.search'), JSON_UNESCAPED_UNICODE) !!};
  var NONE_TXT = {!! json_encode(__('common.no_results'), JSON_UNESCAPED_UNICODE) !!};

  var current = null; // اللوحة المفتوحة — واحدة بس في أي لحظة

  function enhance(sel) {
    if (sel.dataset.sselDone === '1') return;
    if (sel.multiple || sel.size > 1) return;
    if ('nosearch' in sel.dataset) return;
    if (sel.closest('.doc')) return;
    if (sel.options.length <= 7) return;
    sel.dataset.sselDone = '1';

    var wrap = document.createElement('div');
    wrap.className = 'ssel';
    // العرض الصريح بينتقل للغلاف — المودالات كلها style="width:100%"
    // وفلاتر القوايم (العملاء وغيرها) بتستخدم min-width بالبكسل
    if (sel.style.width) wrap.style.width = sel.style.width;
    if (sel.style.minWidth) wrap.style.minWidth = sel.style.minWidth;

    var btn = document.createElement('button');
    btn.type = 'button'; // ⚠️ جوه فورم — من غيرها الزرار كان هيبعت الفورم
    btn.className = 'ssel-btn';
    btn.setAttribute('aria-haspopup', 'listbox');

    var lbl = document.createElement('span');
    lbl.className = 'ssel-lbl';
    var arw = document.createElement('span');
    arw.className = 'ssel-arw';
    arw.textContent = '▾';
    btn.appendChild(lbl);
    btn.appendChild(arw);

    var panel = document.createElement('div');
    panel.className = 'ssel-panel';
    panel.hidden = true;

    var q = document.createElement('input');
    q.type = 'search'; // بياخد ستايل خانات البحث الموحد — ومن غير name
    q.className = 'ssel-q';
    q.placeholder = '🔍 ' + Q_PH;
    q.autocomplete = 'off';

    var list = document.createElement('div');
    list.className = 'ssel-list';
    list.setAttribute('role', 'listbox');

    panel.appendChild(q);
    panel.appendChild(list);

    sel.after(wrap);
    wrap.appendChild(btn);
    wrap.appendChild(panel);
    sel.classList.add('ssel-native');

    var rows = [];
    var noneRow = null;

    // عرض الزرار = حالة السيلكت الحقيقية (القيمة + disabled + .bad)
    function refresh() {
      var o = sel.selectedIndex >= 0 ? sel.options[sel.selectedIndex] : null;
      lbl.textContent = o ? o.text : ' ';
      btn.disabled = sel.disabled;
      btn.classList.toggle('bad', sel.classList.contains('bad'));
    }

    function closePanel() {
      panel.hidden = true;
      if (current && current.wrap === wrap) current = null;
    }

    function pick(idx) {
      closePanel();
      sel.selectedIndex = idx;
      refresh();
      // ⚠️ حدث حقيقي bubbles — بيشغّل onchange المكتوب في البليدات
      //    (فلاتر this.form.submit() وغيرها) — ممكن يعمل submit فعلاً
      sel.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function addOpt(o) {
      // ⚠️⚠️ **الأوبشن المخفي مايتنسخش** (إصلاح ١٧/٨). فلترة
      // «المحافظة ← مناطقها» (`filterZones` في مودال اعتماد العميل
      // وفورم العميل) بتخبّي الأوبشنز بـ`hidden` على السيلكت
      // الأصلي — والبانل هنا كانت بتنسخ **الكل** وتتجاهلها، فتختار
      // «القاهرة» وتلاقي مناطق إسكندرية قدامك. القايمة بتتبني مع
      // كل فتح، فالفلترة بتتلقط تلقائياً.
      if (o.hidden) return;
      var d = document.createElement('div');
      d.className = 'ssel-opt' + (o.disabled ? ' dis' : '')
        + (o.index === sel.selectedIndex ? ' on' : '');
      d.setAttribute('role', 'option');
      d.textContent = o.text;
      d.dataset.txt = (o.text + ' ' + o.value).toLowerCase();
      if (!o.disabled) {
        (function (idx) {
          d.addEventListener('click', function () { pick(idx); });
        })(o.index);
      }
      list.appendChild(d);
      rows.push(d);
    }

    // القايمة بتتبني من جديد مع كل فتح — بتلقط أي تغيير برمجي
    // في القيمة أو الاختيارات حصل بعد التحسين. الـoptgroup بيتحول
    // لرأس مجموعة (سيلكت المناطق مجمّع بالمحافظة).
    function build() {
      list.innerHTML = '';
      rows = [];
      Array.prototype.forEach.call(sel.children, function (ch) {
        if (ch.tagName === 'OPTGROUP') {
          var g = document.createElement('div');
          g.className = 'ssel-grp';
          g.dataset.grp = '1';
          g.textContent = ch.label;
          list.appendChild(g);
          Array.prototype.forEach.call(ch.children, addOpt);
        } else if (ch.tagName === 'OPTION') {
          addOpt(ch);
        }
      });
      // ⚠️ رأس المجموعة اللي كل أولادها اتخبّوا بالفلترة بيتشال —
      // من غيرها «الإسكندرية» تفضل عنوان فاضي تحت «القاهرة» المختارة
      var kids = Array.prototype.slice.call(list.children);
      kids.forEach(function (k, i) {
        if (k.dataset.grp !== '1') return;
        var nxt = kids[i + 1];
        if (!nxt || nxt.dataset.grp === '1') list.removeChild(k);
      });

      noneRow = document.createElement('div');
      noneRow.className = 'ssel-none';
      noneRow.textContent = NONE_TXT;
      noneRow.hidden = true;
      list.appendChild(noneRow);
    }

    // فلترة substring على النص (عربي/إنجليزي) + القيمة.
    // رأس المجموعة بيتخبى لو كل اللي تحته اتخبى.
    function filter(raw) {
      var s = raw.trim().toLowerCase();
      var any = false;
      rows.forEach(function (d) {
        var hit = s === '' || d.dataset.txt.indexOf(s) !== -1;
        d.style.display = hit ? '' : 'none';
        if (hit) any = true;
      });
      var kids = Array.prototype.slice.call(list.children);
      kids.forEach(function (k, i) {
        if (k.dataset.grp !== '1') return;
        var vis = false;
        for (var j = i + 1; j < kids.length; j++) {
          if (kids[j].dataset.grp === '1') break;
          if (kids[j].classList.contains('ssel-opt')
              && kids[j].style.display !== 'none') { vis = true; break; }
        }
        k.style.display = vis ? '' : 'none';
      });
      noneRow.hidden = any;
    }

    // التموضع فيزيائي من مكان الزرار وقت الفتح (نفس أسلوب الجرس) —
    // مزنوق جوه الشاشة، وبيتقلب لفوق لو المساحة تحت مش كفاية.
    function place() {
      var r = btn.getBoundingClientRect();
      var w = Math.min(Math.max(r.width, 230), window.innerWidth - 16);
      panel.style.width = w + 'px';
      var left = IS_RTL ? r.right - w : r.left;
      left = Math.max(8, Math.min(left, window.innerWidth - w - 8));
      var h = panel.offsetHeight;
      var top = r.bottom + 4;
      if (top + h > window.innerHeight - 8 && r.top - h - 4 >= 8) top = r.top - h - 4;
      top = Math.max(8, Math.min(top, window.innerHeight - h - 8));
      panel.style.left = left + 'px';
      panel.style.top = top + 'px';
    }

    function openPanel() {
      if (sel.disabled) return;
      if (current) current.close();
      refresh();
      build();
      q.value = '';
      panel.hidden = false;
      filter('');
      place();
      current = { wrap: wrap, close: closePanel, place: place };
      q.focus();
    }

    btn.addEventListener('click', function () {
      if (panel.hidden) openPanel(); else closePanel();
    });

    q.addEventListener('input', function () { filter(q.value); });
    q.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault(); // ⚠️ يمنع الإرسال الضمني للفورم وقت الكتابة
        for (var i = 0; i < rows.length; i++) {
          if (rows[i].style.display !== 'none'
              && !rows[i].classList.contains('dis')) { rows[i].click(); return; }
        }
      } else if (e.key === 'Escape') {
        e.preventDefault(); // ⚠️ ومن غيرها Escape بيقفل الـdialog كمان
        e.stopPropagation();
        closePanel();
        btn.focus();
      }
    });

    sel.addEventListener('change', refresh);
    wrap.__sselRefresh = refresh;
    refresh();
  }

  document.addEventListener('DOMContentLoaded', function () {
    // ⚠️ اللسنر ده متسجل **بعد** أدوات الجداول — فحص «الكارت فيه
    //    خانة بحث؟» بتاعهم بيحصل قبل ما خانات البحث بتاعتنا تتولد.
    document.querySelectorAll('select').forEach(function (s) { enhance(s); });

    // طبقة المزامنة رقم 3: لفّة حوالين openDlg() العامة — أي ديالوج
    // بيتفتح بنحدّث عرض كل سيلكت متحسّن جواه (openUser/openGeo
    // بيحطوا .value مباشرة قبل openDlg ومفيش حدث change بيتبعت).
    if (typeof window.openDlg === 'function') {
      var origOpenDlg = window.openDlg;
      window.openDlg = function (id) {
        origOpenDlg(id);
        var dlg = document.getElementById(id);
        if (dlg) {
          dlg.querySelectorAll('.ssel').forEach(function (w) {
            if (w.__sselRefresh) w.__sselRefresh();
          });
        }
      };
    }

    document.addEventListener('click', function (e) {
      if (current && !current.wrap.contains(e.target)) current.close();
    });
    // اللوحة fixed — أي سكرول (حتى جوه كونتينر) بيغيّر مكان الزرار
    document.addEventListener('scroll', function () {
      if (current) current.place();
    }, true);
    window.addEventListener('resize', function () {
      if (current) current.place();
    });
  });
})();
</script>
@yield('scripts')
</body>
</html>
