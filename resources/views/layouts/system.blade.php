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
.navgrp{font-size:10px;color:rgba(255,255,255,.5);font-weight:800;padding:12px 10px 5px;letter-spacing:.6px}
.navlink{display:flex;align-items:center;gap:9px;padding:8.5px 12px;border-radius:var(--r-sm);font-weight:600;font-size:13px;color:rgba(255,255,255,.82);margin-bottom:2px;transition:.15s}
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

.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:16px}
.kpi{background:var(--card);border:1px solid var(--border);border-radius:var(--r-md);padding:15px 16px;box-shadow:var(--shadow);position:relative;overflow:hidden}
.kpi::before{content:"";position:absolute;inset-block-start:0;inset-inline-start:0;width:100%;height:3px;background:var(--brand-gradient);opacity:.85}
.kpi .lbl{color:var(--muted);font-size:11.5px;font-weight:700}
.kpi .val{font-size:22px;font-weight:900;margin-top:4px;letter-spacing:-.5px}
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
.errline{color:var(--red);font-size:11px;font-weight:800;margin-top:5px;line-height:1.6}
.req-star{color:var(--red);font-weight:900}
.alert.info{border-inline-start-color:var(--blue)}
.alert.good{border-inline-start-color:var(--green)}
table{width:100%;border-collapse:collapse;font-size:13px}
th{color:var(--muted);font-weight:800;text-align:start;padding:10px 8px;border-bottom:1.5px solid var(--border);white-space:nowrap;background:var(--card2)}
td{padding:9px 8px;border-bottom:1px solid var(--border);white-space:nowrap}
tr:last-child td{border-bottom:none}
tr.clickable{cursor:pointer;transition:.12s}
tr.clickable:hover{background:var(--card2)}
.tablewrap{overflow-x:auto}
.num{font-variant-numeric:tabular-nums;direction:ltr;text-align:left}
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
input[type=text],input[type=number],input[type=date],input[type=password],select,textarea{background:var(--card);border:1px solid var(--border);color:var(--text);border-radius:10px;padding:9px 13px;font-family:inherit;font-size:13px;outline:none;transition:.15s}
input:focus,select:focus,textarea:focus{border-color:var(--royal-blue);box-shadow:0 0 0 3px rgba(18,57,155,.14)}
.searchbar input[type=text]{flex:1;min-width:200px}
label.f{display:block;font-size:11.5px;font-weight:800;margin-bottom:5px;color:var(--muted)}
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
.flash{background:#E7F7EE;color:#0F7A38;border-radius:12px;padding:11px 16px;font-weight:800;font-size:13px;margin-bottom:14px}
.flash.err{background:#FDECEC;color:var(--red)}
dialog{border:none;border-radius:16px;padding:0;box-shadow:0 20px 60px rgba(0,0,0,.25);max-width:96vw}
dialog::backdrop{background:rgba(10,10,10,.45)}
dialog .dlg{padding:20px 22px;width:min(560px,92vw);font-family:inherit;direction:{{ $isRtl ? 'rtl' : 'ltr' }}}
dialog h4{font-size:16px;font-weight:900;margin-bottom:14px}
.pag{display:flex;gap:6px;margin-top:14px;flex-wrap:wrap;font-size:12.5px}
.pag a,.pag span{padding:6px 11px;border-radius:9px;border:1px solid var(--border);background:#fff}
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

    if (in_array('dues', $shown, true)) {
    // ⚠️ كاش دقيقة: العدّاد ده كان كويري على كل صفحة لكل مستخدم.
        $navCounts['dues'] = cache()->remember('nav.open_dues', 60,
            fn () => \App\Models\ContractDue::where('status', 'due')->count());
    }
@endphp
<div class="wrap">

  <aside class="sidebar">
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
    @foreach ($nav as $group => $links)
        <div class="navgrp">{{ __($group) }}</div>
        @foreach ($links as [$route, $icon, $label, $pattern, $counter])
            <a class="navlink {{ request()->routeIs($pattern) ? 'active' : '' }}"
               href="{{ route($route) }}">
                {{ $icon }} {{ __($label) }}
                @if ($counter && ($navCounts[$counter] ?? 0) > 0)
                    <span class="cnt">{{ $navCounts[$counter] }}</span>
                @endif
            </a>
        @endforeach
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
      <div class="meta">@yield('actions')</div>
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
</script>
@yield('scripts')
</body>
</html>
