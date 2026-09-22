@extends('layouts.system')

@section('title', __('nav.overview'))

{{-- ═══ الداشبورد V2 (٢٣ أغسطس ٢٠٢٦ — «تطوير خبير») ═══
     هيدر فلاتر بالتدرج الرسمي والصاعقة · كروت KPI بهوية البراند
     وفواصل واضحة بين الأرقام الصغيرة · التوريدات · العهد والميدان ·
     فليفار بار المنتجات بباليت النكهات · المناطق والمحافظات تحت.
     كل رقم لينكابل + سطر شرح. CSS/SVG صافي — صفر مكتبات. --}}

@php
    $fmt = fn ($n) => number_format((float) $n);
    $rq = array_filter(['from' => $from, 'to' => $to, 'user_id' => $repId]);
    $rpt = fn (string $key, array $extra = []) => route('erp.reports.show', array_merge(['key' => $key], $rq, $extra));
    // (٢٢/٩) لينكات الصفوف لصفحات السجلات نفسها — بنفس فترة الداشبورد
    $per = ['from' => $from, 'to' => $to];
    $palette = ['#12399B', '#602D90', '#D74297', '#16A34A', '#B86E00', '#0E7490', '#B00020', '#64748B'];
    // باليت النكهات من الجايد لاين — لفليفار بار المنتجات
    $flavours = ['#FFA600', '#BF2917', '#693300', '#82C7FF', '#FFC796', '#FFF759', '#C9EBFF', '#FFF0E3'];
    $bolt = asset('brand/bolt.svg');
@endphp

@section('content')

{{-- ═══ هيدر الفلاتر — باند بالتدرج الرسمي على عرض الديف كله ═══ --}}
<div class="dash-head has-bolt">
    <img class="bolt-mark" src="{{ $bolt }}" alt="" style="opacity:.13">
    <div class="dash-head-top">
        <div>
            <b style="font-size:17px">⚡ {{ __('dash.head_title') }}</b>
            <div class="s" style="opacity:.85">{{ __('dash.filters_hint') }}</div>
        </div>
        <div class="s" style="opacity:.85;white-space:nowrap">
            {{-- dir=ltr — تواريخ لاتينية جوه سطر RTL بتتعكس من غيرها --}}
            📅 <span dir="ltr">{{ $from }} → {{ $to }}</span>
        </div>
    </div>
    <form method="GET" class="dash-filters">
        @if ($managers->isNotEmpty())
            <div class="df grow">
                <label>👔 {{ __('dash.f_manager') }}</label>
                <select name="manager_id" onchange="this.form.submit()">
                    <option value="">{{ __('ui.all_of', ['x' => __('uic.managers')]) }}</option>
                    @foreach ($managers as $m)
                        <option value="{{ $m->id }}" @selected($mgrId === $m->id)>{{ $m->displayName() }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div class="df grow">
            <label>🧑‍💼 {{ __('rpt.f_rep') }}</label>
            <select name="user_id">
                <option value="">{{ __('ops.all_reps') }}</option>
                @foreach ($reps as $r)
                    <option value="{{ $r->id }}" @selected($repId === $r->id)>{{ $r->displayName() }}</option>
                @endforeach
            </select>
        </div>
        {{-- الفترة آخر الفلاتر زي كل الشاشات — والفاضي هنا = الشهر الحالي مش كل الفترات --}}
        @include('partials._range', ['from' => $from, 'to' => $to, 'all' => false])
        <div class="df dfbtns">
            <button class="dash-btn main" type="submit">🔍 {{ __('rpt.apply') }}</button>
            <a class="dash-btn" href="{{ route('erp.overview') }}">↺ {{ __('common.clear') }}</a>
        </div>
    </form>
</div>

{{-- ═══ الصف الأول: معادلة الفلوس (٢٦/٨ — طلب المالك) ═══
     مبيعات − تحصيل − مرتجعات = صافي حركة المديونية — بإشارات ظاهرة
     بين البوكسات، وكل بوكس مقسوم بمصادره: كاش/آجل/توريدات،
     وكاش فواتير/ميداني/توريدات، وBilled/Unbilled. --}}
@php
    $salesAll = (float) $inv->g + (float) $posDelivered->g;
    $billedAll = (float) $inv->billed_g + (float) $posDelivered->billed_g;
    // (٢٢/٩) + القيود التانية (رد نقدية/تسويات) — فالصافي = Σ مدين − Σ دائن للفترة
    $netMove = $salesAll - (float) $coll - (float) $rets->g + (float) $otherNet;
    // (٢٢/٩) طلب المالك: كل رقم كبير مكتوب تحته معادلته بأرقامه. الأجزاء بتتقرّب
    // لأقرب جنيه، وفرق التقريب (جنيه) بيتحط على أكبر جزء عشان السطر يقفل بالظبط.
    $fit = function (float $total, array $parts): array {
        $r = array_map(fn ($v) => (int) round($v), $parts);
        $diff = (int) round($total) - array_sum($r);
        if ($diff !== 0 && $r) {
            $k = array_search(max($r), $r, true);
            $r[$k] += $diff;
        }

        return $r;
    };
    // سطر المعادلة LTR: علامة اتجاه بعد كل كلمة عربي — من غيرها الأرقام بتتشقلب حوالين الكلام
    $eq = fn (string $key, array $p = []) => preg_replace('/(\p{Arabic}+(?:[ \/]\p{Arabic}+)*)/u', "$1".html_entity_decode('&lrm;'), __($key, $p));
    $sPay = $fit($salesAll, ["cash" => $inv->cash_g, "credit" => $inv->g - $inv->cash_g, "po" => $posDelivered->g]);
    $sBill = $fit($salesAll, ["billed" => $billedAll, "unbilled" => $salesAll - $billedAll]);
    $cParts = $fit((float) $coll, $collSplit);
    // القيود التانية = اللي يقفل المعادلة بعد التقريب (الفرق جنيه بالكتير)
    $otherShown = (int) round($netMove) - ((int) round($salesAll) - (int) round($coll) - (int) round($rets->g));
@endphp
<div class="dash-eqrow">
    {{-- ⚠️ (٢٢/٩) الكارت كان بيفتح «الفواتير تفصيلي» اللي إجماليه الفواتير بس
         (من غير التوريدات) — رقم غير اللي على الكارت. «المبيعات بالعميل» إجماليه
         هو نفس الرقم بالظبط: فواتير + توريدات اتسلمت. --}}
    <a class="kpi dash-link has-bolt" href="{{ $rpt('sales_by_client') }}">
        <img class="bolt-mark" src="{{ $bolt }}" alt="">
        <div class="val pos big">{{ $fmt($salesAll) }}</div>
        <div class="lbl"><span class="kic">💰</span> {{ __('dash.k_sales') }}</div>
        {{-- الأرقام الفرعية في مربعات صغيرة (٢٦/٨) — أوضح من سطر متلزق --}}
        <div class="kmini">
            <span><b>{{ $fmt($sPay['cash']) }}</b><i>💵 {{ __('dash.eq_cash') }}</i></span>
            <span><b>{{ $fmt($sPay['credit']) }}</b><i>🕐 {{ __('dash.eq_credit') }}</i></span>
            <span><b>{{ $fmt($sPay['po']) }}</b><i>🚚 {{ __('dash.eq_pos') }}</i></span>
        </div>
        <div class="kmini">
            <span class="on"><b>{{ $fmt($sBill['billed']) }}</b><i>🧾 {{ __('dash.billed') }}</i></span>
            <span><b>{{ $fmt($sBill['unbilled']) }}</b><i>📄 {{ __('dash.unbilled') }}</i></span>
            <span><b>{{ $fmt($openPos) }}</b><i>⏳ {{ __('rpt.k_open') }}</i></span>
        </div>
        {{-- (٢٢/٩) المعادلة بالأرقام — التقسيمتين كل واحدة لوحدها بتقفل على رقم الكارت --}}
        <div class="sub2 eqline"><span dir="ltr">{{ $eq('uic.eq_sales_pay', ['t' => $fmt($salesAll), 'a' => $fmt($sPay['cash']), 'b' => $fmt($sPay['credit']), 'c' => $fmt($sPay['po'])]) }}</span></div>
        <div class="sub2 eqline"><span dir="ltr">{{ $eq('uic.eq_sales_bill', ['t' => $fmt($salesAll), 'a' => $fmt($sBill['billed']), 'b' => $fmt($sBill['unbilled'])]) }}</span></div>
        <div class="dash-hint">{{ __('dash.h_eq_sales') }} {{ __('uic.open_pos_note', ['n' => $fmt($openPos)]) }}</div>
    </a>
    <span class="eqop">−</span>
    <a class="kpi dash-link has-bolt" href="{{ $rpt('collections') }}">
        <img class="bolt-mark" src="{{ $bolt }}" alt="">
        <div class="val pos big">{{ $fmt($coll) }}</div>
        <div class="lbl"><span class="kic">🤲</span> {{ __('dash.k_coll') }}</div>
        <div class="kmini">
            <span><b>{{ $fmt($cParts['invoice']) }}</b><i>💵 {{ __('dash.coll_cash') }}</i></span>
            <span><b>{{ $fmt($cParts['visit']) }}</b><i>🚪 {{ __('dash.coll_field') }}</i></span>
            <span><b>{{ $fmt($cParts['po']) }}</b><i>🚚 {{ __('dash.coll_pos') }}</i></span>
            {{-- ⚠️ (٢٢/٩) تحصيل المكتب/الحسابات كان محسوب في الرقم ومش ظاهر — المربعات ماكانتش بتجمع على الكارت --}}
            <span><b>{{ $fmt($cParts['other']) }}</b><i>🏦 {{ __('uic.coll_office') }}</i></span>
        </div>
        <div class="kmini">
            <span><b>{{ $salesAll > 0 ? number_format($coll / $salesAll * 100, 1) : 0 }}%</b><i>📈 {{ __('dash.of_sales') }}</i></span>
        </div>
        <div class="sub2 eqline"><span dir="ltr">{{ $eq('uic.eq_coll', ['t' => $fmt($coll), 'a' => $fmt($cParts['invoice']), 'b' => $fmt($cParts['visit']), 'c' => $fmt($cParts['po']), 'd' => $fmt($cParts['other'])]) }}</span></div>
        <div class="sub2 eqline"><span dir="ltr">{{ $eq('uic.eq_coll_pct', ['p' => $salesAll > 0 ? number_format($coll / $salesAll * 100, 1) : 0, 'a' => $fmt($coll), 'b' => $fmt($salesAll)]) }}</span></div>
        <div class="dash-hint">{{ __('uic.h_coll2') }}</div>
    </a>
    <span class="eqop">−</span>
    <a class="kpi dash-link has-bolt" href="{{ $rpt('returns_docs') }}">
        <img class="bolt-mark" src="{{ $bolt }}" alt="">
        <div class="val neg big">{{ $fmt($rets->g) }}</div>
        <div class="lbl"><span class="kic">↩️</span> {{ __('dash.k_rets') }}</div>
        <div class="kmini">
            <span><b>{{ $fmt($rets->n) }}</b><i>🧾 {{ __('dash.rets_n') }}</i></span>
        </div>
        <div class="sub2 eqline"><span dir="ltr">{{ $eq('uic.eq_rets', ['t' => $fmt($rets->g), 'n' => $fmt($rets->n), 'p' => $salesAll > 0 ? number_format($rets->g / $salesAll * 100, 1) : 0, 's' => $fmt($salesAll)]) }}</span></div>
        <div class="dash-hint">{{ __('dash.h_rets') }}</div>
    </a>
    <span class="eqop">=</span>
    <a class="kpi dash-link has-bolt" href="{{ $rpt('debts') }}">
        <img class="bolt-mark" src="{{ $bolt }}" alt="">
        <div class="val big {{ $netMove > 0 ? 'mid' : 'pos' }}">{{ $netMove > 0 ? '+' : '' }}{{ $fmt($netMove) }}</div>
        <div class="lbl"><span class="kic">🧮</span> {{ __('dash.eq_net') }}</div>
        <div class="kmini">
            <span class="on"><b>{{ $fmt($debt->g) }}</b><i>⏳ {{ __('dash.eq_debt_now') }}</i></span>
            <span><b>{{ $fmt($debt->n) }}</b><i>👥 {{ __('rpt.k_clients') }}</i></span>
        </div>
        @if ($otherShown !== 0)
            {{-- الجزء اللي مش ظاهر في التلات كروت اللي قبله — عشان المعادلة تقفل قدام العين --}}
            <div class="kmini">
                <span><b>{{ $otherShown > 0 ? '+' : '' }}{{ $fmt($otherShown) }}</b><i>🔁 {{ __('uic.eq_other') }}</i></span>
            </div>
        @endif
        <div class="sub2 eqline"><span dir="ltr">{{ $eq('uic.eq_net', ['t' => ($netMove > 0 ? '+' : '').$fmt($netMove), 'a' => $fmt($salesAll), 'b' => $fmt($coll), 'c' => $fmt($rets->g), 'd' => ($otherShown < 0 ? '− ' : '+ ').$fmt(abs($otherShown))]) }}</span></div>
        <div class="dash-hint">{{ __('uic.h_eq_net2', ['g' => $fmt($debt->g), 'n' => $fmt($debt->n)]) }}</div>
    </a>
</div>

{{-- ═══ الصف التاني: الميدان والعهد ═══ --}}
<div class="kpis dash-kpis">
    @if (\App\Support\Access::allows(auth()->user(), 'ops.vans'))
    <a class="kpi dash-link has-bolt" href="{{ route('ops.vans') }}">
    @else
    <div class="kpi has-bolt">
    @endif
        <img class="bolt-mark" src="{{ $bolt }}" alt="">
        <div class="val big">{{ $fmt($street->val) }}</div>
        <div class="lbl"><span class="kic">🚐</span> {{ __('dash.k_street') }}</div>
        <div class="kmini">
            <span><b>{{ $fmt($street->vans) }}</b><i>🚐 {{ __('dash.vans_open') }}</i></span>
            <span><b>{{ $fmt($street->units) }}</b><i>📦 {{ __('dash.units') }}</i></span>
        </div>
        <div class="sub2 eqline">{{ __('uic.eq_street', ['v' => $fmt($street->val), 'u' => $fmt($street->units), 'n' => $fmt($street->vans)]) }}</div>
        <div class="dash-hint">{{ __('dash.h_street') }}</div>
    @if (\App\Support\Access::allows(auth()->user(), 'ops.vans'))</a>@else</div>@endif
    <a class="kpi dash-link has-bolt" href="{{ $rpt('visits_log') }}">
        <img class="bolt-mark" src="{{ $bolt }}" alt="">
        <div class="val big">{{ $fmt($visitsN) }}</div>
        <div class="lbl"><span class="kic">🚪</span> {{ __('dash.k_field') }}</div>
        <div class="kmini">
            <span><b>{{ $fmt($giftsQ) }}</b><i>🎁 {{ __('rpt.k_gifts') }}</i></span>
            <span><b>{{ $fmt($rets->n) }}</b><i>↩️ {{ __('dash.docs') }}</i></span>
        </div>
        <div class="sub2 eqline">{{ __('uic.eq_field', ['n' => $fmt($visitsN)]) }}</div>
        <div class="dash-hint">{{ __('dash.h_visits') }}</div>
    </a>
    <a class="kpi dash-link has-bolt" href="{{ $rpt('new_clients') }}">
        <img class="bolt-mark" src="{{ $bolt }}" alt="">
        <div class="val big">{{ $fmt($newClientsN) }}</div>
        <div class="lbl"><span class="kic">✨</span> {{ __('rpt.new_clients') }}</div>
        <div class="kmini">
            <span><b>{{ $fmt($openRequests) }}</b><i>📋 {{ __('dash.pending_req') }}</i></span>
        </div>
        <div class="sub2 eqline">{{ __('uic.eq_new') }}</div>
        <div class="dash-hint">{{ __('dash.h_new') }}</div>
    </a>
    @if (\App\Support\Access::allows(auth()->user(), 'erp.stock'))
    <a class="kpi dash-link has-bolt" href="{{ route('erp.stock') }}">
    @else
    <div class="kpi has-bolt">
    @endif
        <img class="bolt-mark" src="{{ $bolt }}" alt="">
        <div class="val big">{{ $fmt($stockValue) }}</div>
        <div class="lbl"><span class="kic">🏭</span> {{ __('dash.k_stock') }}</div>
        <div class="kmini">
            <span><b>💵</b><i>{{ __('dash.h_stock_short') }}</i></span>
        </div>
        <div class="sub2 eqline">{{ __('uic.eq_stock', ['v' => $fmt($stockValue)]) }}</div>
        <div class="dash-hint">{{ __('dash.h_stock') }}</div>
    @if (\App\Support\Access::allows(auth()->user(), 'erp.stock'))</a>@else</div>@endif
</div>

{{-- ═══ مهامي (٢٦/٨) — متابعة إدارة المهام من الرئيسية ═══ --}}
@if (($myTasks['open'] ?? 0) > 0 || ($myTasks['waiting'] ?? 0) > 0)
<div class="card" style="margin-bottom:14px">
    <h3 style="margin:0 0 10px;display:flex;align-items:center;gap:8px">📋 {{ __('tasks.my_widget') }}
        @if ($myTasks['late'] > 0)
            <span class="badge b-red">{{ $myTasks['late'] }} {{ __('tasks.late') }}</span>
        @endif
        @if ($myTasks['waiting'] > 0)
            <span class="badge b-orange">{{ $myTasks['waiting'] }} {{ __('tasks.k_waiting') }}</span>
        @endif
        <a class="btn sm" style="margin-inline-start:auto" href="{{ route('erp.tasks') }}">{{ __('tasks.go_all') }} ←</a>
    </h3>
    <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-start">
        {{-- مهامي المفتوحة --}}
        <div style="flex:1;min-width:260px">
            @forelse ($myTasks['list'] as $t)
                <a href="{{ route('erp.tasks.show', $t) }}" class="dtk-row">
                    <span class="dtk-dot" style="background:{{ $t->isLate() ? '#DC2626' : ($t->priority === 'urgent' ? '#B96C0A' : '#12399B') }}"></span>
                    <b style="flex:1;min-width:0;font-size:12.5px">{{ $t->title }}</b>
                    @if ($t->deadline)
                        <span dir="ltr" style="font-size:10.5px;{{ $t->isLate() ? 'color:#DC2626;font-weight:800' : 'color:var(--muted)' }}">
                            🗓 {{ $t->deadline->format('d/m H:i') }}</span>
                    @endif
                </a>
            @empty
                <div style="font-size:12px;color:var(--muted);padding:6px">{{ __('tasks.none') }} 👌</div>
            @endforelse
        </div>
        {{-- مستنية اعتمادي — بزرار سريع --}}
        @if ($myTasks['decide']->isNotEmpty())
            <div style="flex:1;min-width:260px;background:#FFF8EC;border:1px solid #F3E4C2;border-radius:12px;padding:10px 12px">
                <div style="font-size:11.5px;font-weight:900;margin-bottom:6px">⏳ {{ __('tasks.k_waiting') }}</div>
                @foreach ($myTasks['decide'] as $t)
                    <a href="{{ route('erp.tasks.show', $t) }}" class="dtk-row">
                        <b style="flex:1;min-width:0;font-size:12.5px">{{ $t->title }}</b>
                        <span style="font-size:10.5px;color:var(--muted)">👤 {{ $t->assignee?->displayName() }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
    <div class="dash-hint" style="margin-top:8px">{{ __('tasks.h_widget') }}</div>
</div>
@endif

{{-- ═══ المبيعات مقابل التحصيل + دونات القنوات ═══ --}}
<div class="dash-grid2">
    <div class="card">
        <h3>📈 {{ __('dash.chart_flow') }}
            <span class="side">{{ $daily ? __('dash.by_day') : __('dash.by_month') }}</span></h3>
        @php $maxV = max(1, collect($series)->flatMap(fn ($s) => [$s['sales'], $s['coll']])->max()); @endphp
        <div class="dash-bars {{ count($series) > 16 ? 'dense' : '' }}">
            @foreach ($series as $k => $s)
                @php
                    // العمود الشهري بيفتح شهره هو (مقصوص على فترة الداشبورد) مش الفترة كلها
                    $kA = $daily ? $k : max($from, $k.'-01');
                    $kB = $daily ? $k : min($to, \Illuminate\Support\Carbon::parse($k.'-01')->endOfMonth()->toDateString());
                @endphp
                <a class="dash-bcol" href="{{ $rpt('sales_by_client', ['from' => $kA, 'to' => $kB]) }}"
                   title="{{ $k }} — {{ __('dash.k_sales') }}: {{ $fmt($s['sales']) }} · {{ __('dash.k_coll') }}: {{ $fmt($s['coll']) }}">
                    <span class="b sales" style="height:{{ round($s['sales'] / $maxV * 100) }}%"></span>
                    <span class="b coll" style="height:{{ round($s['coll'] / $maxV * 100) }}%"></span>
                    <i>{{ $daily ? \Illuminate\Support\Carbon::parse($k)->format('d') : \Illuminate\Support\Carbon::parse($k.'-01')->format('m/y') }}</i>
                </a>
            @endforeach
        </div>
        <div class="dash-legend">
            <span><i style="background:var(--royal-blue)"></i> {{ __('dash.k_sales') }}</span>
            <span><i style="background:#16A34A"></i> {{ __('dash.k_coll') }}</span>
        </div>
        <div class="dash-hint">{{ __('dash.h_flow') }}</div>
    </div>

    <div class="card">
        <h3>🎯 {{ __('rpt.sales_by_channel') }}</h3>
        @php
            $chTotal = max(1, $byChannel->sum('v'));
            $deg = 0; $stops = [];
            foreach ($byChannel as $i => $ch) {
                $slice = $ch->v / $chTotal * 360;
                $stops[] = $palette[$i % count($palette)].' '.round($deg).'deg '.round($deg + $slice).'deg';
                $deg += $slice;
            }
        @endphp
        <div class="dash-donutwrap">
            <div class="dash-donut" style="background:conic-gradient({{ $stops ? implode(',', $stops) : 'var(--border) 0deg 360deg' }})">
                <div class="hole"><b>{{ $fmt($byChannel->sum('v')) }}</b><i>{{ __('rpt.k_grand') }}</i></div>
            </div>
            <div class="dash-dlegend">
                @forelse ($byChannel as $i => $ch)
                    <a href="{{ $rpt('sales_by_client', ['channel_id' => $ch->cid]) }}">
                        <i style="background:{{ $palette[$i % count($palette)] }}"></i>
                        <span>{{ $ch->cname }}</span>
                        <b>{{ number_format($ch->v / $chTotal * 100, 1) }}%</b>
                    </a>
                @empty
                    <div class="s" style="color:var(--muted)">{{ __('rpt.no_rows') }}</div>
                @endforelse
            </div>
        </div>
        <div class="sub2 eqline">{{ __('uic.eq_channels', ['t' => $fmt($byChannel->sum('v'))]) }}</div>
        <div class="dash-hint">{{ __('dash.h_channels') }}</div>
    </div>
</div>

{{-- ═══ فليفار بار المنتجات — بباليت النكهات من الجايد لاين ═══ --}}
<div class="card" style="margin-top:14px">
    <h3>🍫 {{ __('dash.chart_products') }}
        <a class="side" href="{{ $rpt('sales_by_product') }}">{{ __('dash.full_report') }} ←</a></h3>
    @php $prodMax = max(1, $topProducts->max('v') ?? 1); @endphp
    <div class="dash-flav">
        @forelse ($topProducts as $i => $p)
            <a class="dash-frow" href="{{ route('erp.products.show', ['product' => $p->pid] + $per) }}">
                <span class="rank">{{ $i + 1 }}</span>
                <span class="nm">{{ app()->getLocale() === 'ar' ? ($p->pname ?: $p->pname_en) : ($p->pname_en ?: $p->pname) }}</span>
                <span class="tr">
                    <span class="fill" style="width:{{ max(3, round($p->v / $prodMax * 100)) }}%;background:{{ $flavours[$i % count($flavours)] }}"></span>
                </span>
                <span class="fnums">
                    <b>{{ $fmt($p->q) }}</b> <i>{{ __('dash.pieces') }}</i>
                    <i class="ksep"></i>
                    <b class="pos">{{ $fmt($p->v) }}</b> <i>{{ __('common.currency') }}</i>
                </span>
            </a>
        @empty
            <div class="s" style="color:var(--muted);text-align:center;padding:16px">{{ __('rpt.no_rows') }}</div>
        @endforelse
    </div>
    <div class="dash-hint">{{ __('dash.h_products') }}</div>
</div>

{{-- ═══ العائلات + التصنيفات + الأعمار ═══ --}}
<div class="dash-grid3">
    <div class="card">
        <h3>🧬 {{ __('dash.chart_families') }}</h3>
        @php $famMax = max(1, $byFamily->max('v') ?? 1); @endphp
        <div class="dash-hbars">
            @forelse ($byFamily->take(7) as $f)
                <a class="dash-hrow" href="{{ $rpt('sales_by_product') }}">
                    <span class="nm">{{ \App\Models\ProductFamily::label($f->family) }}</span>
                    <span class="tr"><span class="fill" style="width:{{ round($f->v / $famMax * 100) }}%"></span></span>
                    <b>{{ $fmt($f->v) }}</b>
                </a>
            @empty
                <div class="s" style="color:var(--muted)">{{ __('rpt.no_rows') }}</div>
            @endforelse
        </div>
        <div class="sub2 eqline">{{ __('uic.eq_lines_pre_tax', ['t' => $fmt($byFamily->sum('v'))]) }}</div>
        <div class="dash-hint">{{ __('dash.h_families') }}</div>
    </div>

    <div class="card">
        <h3>🏷️ {{ __('dash.chart_cats') }}</h3>
        @php
            $catTotal = max(1, array_sum($catCounts));
            $catColors = ['danger' => '#B00020', 'watch' => '#B86E00', 'grow' => '#12399B', 'ok' => '#16A34A', 'idle' => '#64748B', 'internal' => '#0E7490', 'credit' => '#602D90'];
            $deg2 = 0; $stops2 = [];
            foreach ($catCounts as $cat => $n) {
                $slice = $n / $catTotal * 360;
                $stops2[] = ($catColors[$cat] ?? '#64748B').' '.round($deg2).'deg '.round($deg2 + $slice).'deg';
                $deg2 += $slice;
            }
        @endphp
        <div class="dash-donutwrap">
            <div class="dash-donut sm" style="background:conic-gradient({{ $stops2 ? implode(',', $stops2) : 'var(--border) 0deg 360deg' }})">
                <div class="hole"><b>{{ $fmt($catTotal) }}</b><i>{{ __('rpt.k_clients') }}</i></div>
            </div>
            <div class="dash-dlegend">
                @foreach ($catCounts as $cat => $n)
                    <a href="{{ route('erp.clients', ['cat' => $cat]) }}">
                        <i style="background:{{ $catColors[$cat] ?? '#64748B' }}"></i>
                        <span>{{ __('enums.category.'.$cat) }}</span>
                        <b>{{ $fmt($n) }}</b>
                    </a>
                @endforeach
            </div>
        </div>
        <div class="dash-hint">{{ __('dash.h_cats') }}</div>
    </div>

    <div class="card">
        <h3>⏳ {{ __('dash.chart_aging') }}</h3>
        @php
            $agTotal = max(1, array_sum($aging));
            $agLabels = ['a30' => '≤30', 'a60' => '31-60', 'a90' => '61-90', 'a180' => '91-180', 'a180p' => '+180'];
            $agColors = ['a30' => '#16A34A', 'a60' => '#12399B', 'a90' => '#B86E00', 'a180' => '#D74297', 'a180p' => '#B00020'];
        @endphp
        <a href="{{ route('erp.reports', ['tab' => 'aging']) }}" class="dash-stack">
            @foreach ($aging as $k => $v)
                @if ($v > 0)
                    <span style="width:{{ max(2, round($v / $agTotal * 100)) }}%;background:{{ $agColors[$k] }}"
                          title="{{ $agLabels[$k] }} {{ __('dash.days') }}: {{ $fmt($v) }}"></span>
                @endif
            @endforeach
        </a>
        <div class="dash-dlegend" style="margin-top:10px">
            @foreach ($aging as $k => $v)
                <a href="{{ route('erp.reports', ['tab' => 'aging']) }}">
                    <i style="background:{{ $agColors[$k] }}"></i>
                    <span>{{ $agLabels[$k] }} {{ __('dash.days') }}</span>
                    <b>{{ $fmt($v) }}</b>
                </a>
            @endforeach
        </div>
        {{-- (٢٢/٩) الشرايح بتجمع على المديونية القائمة اللي في كارت الصافي فوق --}}
        <div class="sub2 eqline"><span dir="ltr">{{ $eq('uic.eq_aging', ['t' => $fmt(array_sum($aging))]) }}</span></div>
        <div class="dash-hint">{{ __('dash.h_aging') }}</div>
    </div>
</div>

{{-- ═══ المناطق والمحافظات — الجغرافيا تحت ═══ --}}
<div class="dash-grid2" style="grid-template-columns:1fr 1fr">
    <div class="card">
        <h3>📍 {{ __('dash.chart_zones') }}</h3>
        @php $zMax = max(1, $byZone->max('v') ?? 1); @endphp
        <div class="dash-hbars">
            @forelse ($byZone as $z)
                <a class="dash-hrow" href="{{ route('erp.clients', ['zone' => $z->zid]) }}">
                    <span class="nm">{{ app()->getLocale() === 'ar' ? ($z->zname ?: $z->zname_en) : ($z->zname_en ?: $z->zname) }}</span>
                    <span class="tr"><span class="fill" style="width:{{ round($z->v / $zMax * 100) }}%"></span></span>
                    <span class="fnums"><b class="pos">{{ $fmt($z->v) }}</b><i class="ksep"></i><b>{{ $fmt($z->nc) }}</b> <i>{{ __('rpt.k_clients') }}</i></span>
                </a>
            @empty
                <div class="s" style="color:var(--muted)">{{ __('rpt.no_rows') }}</div>
            @endforelse
        </div>
        <div class="dash-hint">{{ __('dash.h_zones') }}</div>
    </div>

    <div class="card">
        <h3>🗺️ {{ __('dash.chart_govs') }}</h3>
        @php $gMax = max(1, $byGov->max('v') ?? 1); @endphp
        <div class="dash-hbars">
            @forelse ($byGov as $g)
                <a class="dash-hrow" href="{{ route('erp.clients', ['gov' => $g->gov]) }}">
                    <span class="nm">{{ \App\Support\Governorates::label($g->gov) }}</span>
                    <span class="tr"><span class="fill alt" style="width:{{ round($g->v / $gMax * 100) }}%"></span></span>
                    <span class="fnums"><b class="pos">{{ $fmt($g->v) }}</b><i class="ksep"></i><b>{{ $fmt($g->nc) }}</b> <i>{{ __('rpt.k_clients') }}</i></span>
                </a>
            @empty
                <div class="s" style="color:var(--muted)">{{ __('rpt.no_rows') }}</div>
            @endforelse
        </div>
        <div class="dash-hint">{{ __('dash.h_govs') }}</div>
    </div>
</div>

{{-- ═══ أفضل المناديب + أكبر العملاء ═══ --}}
<div class="dash-grid2">
    <div class="card">
        <h3>🧑‍💼 {{ __('dash.top_reps') }}
            <a class="side" href="{{ $rpt('reps_overview') }}">{{ __('dash.full_report') }} ←</a></h3>
        @php $repMax = max(1, $topReps->max('v') ?? 1); @endphp
        <div class="dash-hbars">
            @forelse ($topReps as $r)
                <a class="dash-hrow" href="{{ route('ops.rep', ['user' => $r->user_id] + $per) }}">
                    <span class="nm">{{ $r->rep?->displayName() ?? '—' }}</span>
                    <span class="tr"><span class="fill alt" style="width:{{ round($r->v / $repMax * 100) }}%"></span></span>
                    <span class="fnums"><b class="pos">{{ $fmt($r->v) }}</b><i class="ksep"></i><b>{{ $fmt($r->n) }}</b> <i>{{ __('rpt.k_count') }}</i></span>
                </a>
            @empty
                <div class="s" style="color:var(--muted)">{{ __('rpt.no_rows') }}</div>
            @endforelse
        </div>
        <div class="dash-hint">{{ __('dash.h_top_reps') }}</div>
    </div>

    <div class="card">
        <h3>🏆 {{ __('dash.top_clients') }}
            <a class="side" href="{{ $rpt('sales_by_client') }}">{{ __('dash.full_report') }} ←</a></h3>
        <div class="tablewrap" style="max-height:340px;overflow:auto">
            <table>
                <thead><tr>
                    <th style="text-align:start">{{ __('rpt.c_client') }}</th>
                    <th>{{ __('client.channel') }}</th>
                    <th class="num">{{ __('rpt.k_count') }}</th>
                    <th class="num">{{ __('rpt.k_grand') }}</th>
                    <th class="num" data-nosum>{{ __('rpt.k_balance') }}</th>
                </tr></thead>
                <tbody>
                @forelse ($topClients as $tc)
                    @php $c = $topClientRows->get($tc->client_id); @endphp
                    <tr class="clickable" onclick="location.href='{{ $c ? route('erp.clients.show', ['client' => $c->id] + $per) : '#' }}'">
                        <td style="text-align:start">
                            @if ($c)
                                <a href="{{ route('erp.clients.show', ['client' => $c->id] + $per) }}" onclick="event.stopPropagation()"><b>{{ $c->fullName() }}</b></a>
                            @else — @endif
                        </td>
                        <td><span class="badge b-purple">{{ $c?->channel?->displayName() ?? '—' }}</span></td>
                        <td class="num">{{ $fmt($tc->n) }}</td>
                        <td class="num pos"><b>{{ $fmt($tc->v) }}</b></td>
                        <td class="num {{ ($c?->balance ?? 0) > 0 ? 'mid' : '' }}">{{ $fmt($c?->balance ?? 0) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:20px">{{ __('rpt.no_rows') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="dash-hint">{{ __('dash.h_top_clients') }}</div>
    </div>
</div>

{{-- ═══ اختصارات سريعة ═══ --}}
<div class="dash-quick">
    <a class="dash-qbtn" href="{{ $rpt('inactive_clients') }}">😴 {{ __('rpt.inactive_clients') }}</a>
    {{-- ⚠️ الشاشة اللايف وبورد المناديب `role:admin,manager` — المحاسب ومدير الفرع كانوا شايفين الزرارين وبيترفضوا (زحف ٨/٩) --}}
    @if (\App\Support\Access::allows(auth()->user(), 'ops.live'))<a class="dash-qbtn" href="{{ route('ops.live') }}">📡 {{ __('dash.q_live') }}</a>@endif
    @if (\App\Support\Access::allows(auth()->user(), 'ops.rep_board'))<a class="dash-qbtn" href="{{ route('ops.rep_board') }}">📊 {{ __('dash.q_rep_board') }}</a>@endif
    <a class="dash-qbtn main" href="{{ route('erp.reports.hub') }}">📑 {{ __('rpt.hub_title') }}</a>
</div>

@endsection

@section('scripts')
<style>
/* ═══ الداشبورد V2 (٢٣/٨) — هوية البراند، CSS صافي ═══ */

/* هيدر الفلاتر — التدرج الرسمي 135° على عرض الديف */
.dash-head{
  position:relative;overflow:hidden;border-radius:16px;
  background:linear-gradient(135deg,#12399B 0%,#602D90 100%);
  color:#fff;padding:16px 18px;margin-bottom:14px;
}
.dash-head .bolt-mark{position:absolute;inset-inline-end:-30px;top:-40px;width:220px;transform:rotate(-9deg);pointer-events:none}
.dash-head-top{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:12px;position:relative}
.dash-filters{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;position:relative}
/* (٢٢/٩) فلتر الفترة الموحّد جوه الهيدر الغامق — نفس شكل خانات `.df` */
.dash-filters .rng .fl>span{color:#fff;opacity:.85;font-size:10.5px}
.dash-filters .rng input{border:1px solid rgba(255,255,255,.35);background:rgba(255,255,255,.14);color:#fff;border-radius:9px;padding:7px 10px;font-family:inherit;font-size:12.5px}
.dash-filters .rng input:focus{outline:none;border-color:#FFF927;background:rgba(255,255,255,.22)}
.dash-filters .rng-q a{background:rgba(255,255,255,.12);border-color:rgba(255,255,255,.35);color:#fff}
.dash-filters .rng-q a:hover{background:rgba(255,255,255,.25);color:#fff;border-color:#fff}
.dash-filters .rng-q a.on{background:#FFF927;border-color:#FFF927;color:#12399B}
.df{display:flex;flex-direction:column;gap:4px}
/* ⚠️ البوزيشنز (٢٣/٨): السيلكت كان بياخد نص الشاشة والزراير بتطير
   لسطر لوحدها — مقاسات منضبطة: التواريخ ثابتة، السيلكتات مرنة بحد
   أقصى، والزراير مزنوقة في آخر الصف بـmargin أوتوماتيك */
.df input[type=date]{width:150px}
.df.grow{flex:1 1 190px;min-width:170px;max-width:300px}
.df label{font-size:10.5px;font-weight:700;opacity:.85}
.df input,.df select{
  border:1px solid rgba(255,255,255,.35);background:rgba(255,255,255,.14);
  color:#fff;border-radius:9px;padding:7px 10px;font-family:inherit;font-size:12.5px;width:100%;
}
.df select option{color:var(--ink)}
.df input:focus,.df select:focus{outline:none;border-color:#FFF927;background:rgba(255,255,255,.22)}
.dfbtns{flex-direction:row;gap:8px;margin-inline-start:auto}
.dash-btn{
  display:inline-flex;align-items:center;gap:6px;border-radius:9px;padding:8px 16px;
  font-size:12.5px;font-weight:800;text-decoration:none;cursor:pointer;font-family:inherit;
  border:1px solid rgba(255,255,255,.4);background:transparent;color:#fff;white-space:nowrap;
}
.dash-btn.main{background:#FFF927;border-color:#FFF927;color:#12399B}
.dash-btn:hover{background:rgba(255,255,255,.15)}
.dash-btn.main:hover{background:#fff}

/* كروت KPI — صاعقة خلفية + أيقونة + أرقام كبيرة + فواصل
   ⚠️ البوزيشنز (٢٣/٨): الكروت flex عمودي بارتفاع متساوي، والشرح
   مزنوق تحت بـmargin-top:auto — كان بيتقص عند حافة الكارت */
.dash-kpis{align-items:stretch}
.dash-kpis .kpi{
  position:relative;overflow:hidden;
  display:flex;flex-direction:column;gap:6px;min-height:148px;
}
/* ═══ صف المعادلة (٢٦/٨): مبيعات − تحصيل − مرتجعات = صافي الحركة ═══
   ⚠️ الرقم فوق واسمه تحته — والبوكس مضغوط: الصاعقة absolute صغيرة
   (من غير سيلكتور الـdash-eqrow كانت بترندر بحجمها وتنفخ البوكس) */
.dash-eqrow{display:flex;gap:6px;align-items:stretch;flex-wrap:wrap;margin-bottom:14px}
.dash-eqrow .kpi{flex:1;min-width:225px;position:relative;overflow:hidden;
  display:flex;flex-direction:column;gap:4px;padding:14px 16px}
.dash-eqrow .sub2{font-size:10.5px}
/* (٢٢/٩) سطر المعادلة تحت كل رقم — أرقام متساوية العرض وبيلفّ لو طويل */
.kpi .sub2.eqline,.card .sub2.eqline{display:block;font-size:10.5px;line-height:1.7;color:var(--ink,#1B1B29);
  font-variant-numeric:tabular-nums;margin-top:3px;position:relative}
.eqline span[dir=ltr]{unicode-bidi:isolate;display:inline-block}
.dash-eqrow .dash-hint{font-size:9.5px;margin-top:auto}
/* صفوف ويدجت «مهامي» (٢٦/٨) */
.dtk-row{display:flex;align-items:center;gap:8px;padding:7px 10px;border-radius:10px;
    text-decoration:none;color:inherit;border:1px solid transparent}
.dtk-row:hover{background:var(--card2,#F7F7FA);border-color:var(--border)}
.dtk-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
/* بوكس المبيعات أعرض من الباقي — فيه صفين مربعات (٢٦/٨) */
.dash-eqrow .kpi:first-child{flex:1.6;min-width:300px}
/* ⚠️ المربعات الصغيرة جوه البوكس: الرقم فوق واسمه تحته — بدل سطر sub2 المتلزق */
.kmini{display:flex;gap:6px;flex-wrap:wrap}
.kmini > span{flex:1;min-width:72px;background:#F4F5FA;border:1px solid var(--border,#E5E7F0);
    border-radius:10px;padding:6px 4px;display:flex;flex-direction:column;align-items:center;gap:1px}
.kmini > span.on{background:#EEF1FB;border-color:#C9D2F0}
.kmini b{font-size:14px;font-weight:900;letter-spacing:-.3px;font-variant-numeric:tabular-nums;color:var(--ink,#1B1B29)}
.kmini i{font-style:normal;font-size:9.5px;font-weight:700;color:var(--muted);white-space:nowrap}
.eqop{align-self:center;font-size:24px;font-weight:900;color:var(--royal-blue,#12399B);
  flex-shrink:0;padding:0 1px;opacity:.75}
.dash-kpis .bolt-mark,.dash-eqrow .bolt-mark{position:absolute;inset-inline-end:-18px;bottom:-22px;width:110px;opacity:.05;transform:rotate(-9deg);pointer-events:none;color:#12399B}
.kic{
  display:inline-grid;place-items:center;width:26px;height:26px;border-radius:8px;flex-shrink:0;
  background:linear-gradient(135deg,rgba(18,57,155,.12),rgba(96,45,144,.12));font-size:14px;
}
.dash-kpis .lbl,.dash-eqrow .lbl{display:flex;align-items:center;gap:7px;font-weight:800;font-size:12px;color:var(--muted)}
.dash-kpis .val.big,.dash-eqrow .val.big{font-size:27px;letter-spacing:-.5px;font-variant-numeric:tabular-nums;line-height:1.1}
.dash-kpis .sub2{
  display:flex;align-items:center;column-gap:8px;row-gap:4px;flex-wrap:wrap;
  font-variant-numeric:tabular-nums;
}
.dash-kpis .sub2 span{white-space:nowrap}
.dash-kpis .dash-hint{margin-top:auto;position:relative}
/* الفاصل الشيك بين الأرقام الصغيرة */
.ksep{display:inline-block;width:1px;height:12px;background:var(--border);flex-shrink:0;vertical-align:middle;margin:0 2px}

.dash-link{display:block;text-decoration:none;color:inherit;transition:box-shadow .15s,border-color .15s,transform .15s}
.dash-link:hover{border-color:var(--royal-blue);box-shadow:0 6px 18px rgba(18,57,155,.14);transform:translateY(-1px)}
.dash-hint{font-size:10.5px;color:var(--muted);margin-top:7px;line-height:1.6}
.dash-grid2{display:grid;grid-template-columns:1.35fr 1fr;gap:14px;margin-top:14px}
.dash-grid3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-top:14px}
@media (max-width:1100px){.dash-grid2,.dash-grid3{grid-template-columns:1fr}}

/* أعمدة المبيعات/التحصيل */
.dash-bars{display:flex;align-items:flex-end;gap:4px;height:190px;padding-top:8px}
.dash-bcol{flex:1;display:flex;align-items:flex-end;justify-content:center;gap:2px;height:100%;position:relative;text-decoration:none;border-radius:6px}
.dash-bcol:hover{background:var(--blue-050)}
.dash-bcol .b{width:38%;min-height:2px;border-radius:4px 4px 0 0}
.dash-bcol .b.sales{background:linear-gradient(180deg,#2470E3,#12399B)}
.dash-bcol .b.coll{background:linear-gradient(180deg,#22C55E,#16A34A)}
.dash-bcol i{position:absolute;bottom:-16px;font-size:8.5px;color:var(--muted);font-style:normal;left:50%;transform:translateX(-50%)}
/* فترة طويلة = أعمدة كتير — نخفي لِيبل يوم ورا يوم عشان مايتراكبوش */
.dash-bars.dense .dash-bcol:nth-child(even) i{display:none}
.dash-legend{display:flex;gap:16px;margin-top:24px;font-size:11px;color:var(--muted)}
.dash-legend i{display:inline-block;width:10px;height:10px;border-radius:3px;margin-inline-end:4px}

/* الدونات */
.dash-donutwrap{display:flex;gap:16px;align-items:center;flex-wrap:wrap}
.dash-donut{width:150px;height:150px;border-radius:50%;position:relative;flex-shrink:0;box-shadow:inset 0 0 0 1px var(--border)}
.dash-donut.sm{width:130px;height:130px}
.dash-donut .hole{position:absolute;inset:22%;background:var(--card);border-radius:50%;display:flex;flex-direction:column;align-items:center;justify-content:center}
.dash-donut .hole b{font-size:13px;font-variant-numeric:tabular-nums}
.dash-donut .hole i{font-style:normal;font-size:9.5px;color:var(--muted)}
/* ⚠️ البوزيشنز (٢٣/٨): max-width — النسب كانت بتطير لآخر الكارت
   بعيد عن أساميها لأن الليجيند كان واخد العرض الفاضي كله */
.dash-dlegend{flex:1;min-width:150px;max-width:230px;display:flex;flex-direction:column;gap:5px}
.dash-dlegend a{display:flex;align-items:center;gap:7px;font-size:11.5px;text-decoration:none;color:var(--ink);padding:3px 6px;border-radius:7px}
.dash-dlegend a:hover{background:var(--blue-050)}
.dash-dlegend i{width:10px;height:10px;border-radius:3px;flex-shrink:0}
.dash-dlegend span{flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dash-dlegend b{font-size:11px;font-variant-numeric:tabular-nums;white-space:nowrap}

/* البارات الأفقية */
.dash-hbars{display:flex;flex-direction:column;gap:7px}
.dash-hrow{display:flex;align-items:center;gap:9px;text-decoration:none;color:var(--ink);padding:3px 5px;border-radius:8px}
.dash-hrow:hover{background:var(--blue-050)}
.dash-hrow .nm{width:120px;font-size:11.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
/* ⚠️ flex + justify start (٢٣/٨) — الفيلات كانت بترسي على جهات
   مختلفة في RTL؛ كده كلها بتبدأ من بداية السطر دايماً */
.dash-hrow .tr{flex:1;height:14px;background:var(--card2);border-radius:7px;overflow:hidden;display:flex;justify-content:flex-start}
.dash-hrow .fill{display:block;height:100%;background:linear-gradient(90deg,var(--royal-blue),#602D90);border-radius:7px;flex-shrink:0}
.dash-hrow .fill.alt{background:linear-gradient(90deg,#602D90,#D74297)}
.dash-hrow b{font-size:11.5px;font-variant-numeric:tabular-nums}
.fnums{display:flex;align-items:center;gap:5px;min-width:150px;justify-content:flex-end;font-size:11px}
.fnums i{font-style:normal;color:var(--muted);font-size:10px}

/* فليفار بار المنتجات */
.dash-flav{display:flex;flex-direction:column;gap:8px}
.dash-frow{display:flex;align-items:center;gap:10px;text-decoration:none;color:var(--ink);padding:4px 6px;border-radius:9px}
.dash-frow:hover{background:var(--blue-050)}
.dash-frow .rank{
  width:22px;height:22px;border-radius:7px;display:grid;place-items:center;flex-shrink:0;
  background:linear-gradient(135deg,#12399B,#602D90);color:#fff;font-size:11px;font-weight:900;
}
.dash-frow .nm{width:210px;font-size:12px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dash-frow .tr{flex:1;height:18px;background:var(--card2);border-radius:9px;overflow:hidden;display:flex;justify-content:flex-start}
.dash-frow .fill{display:block;height:100%;border-radius:9px;box-shadow:inset 0 0 0 1px rgba(0,0,0,.06);flex-shrink:0}
.dash-frow .fnums{min-width:180px;flex-shrink:0}

/* شريط الأعمار المكدس */
.dash-stack{display:flex;height:26px;border-radius:9px;overflow:hidden;text-decoration:none}
.dash-stack span{display:block;height:100%}

/* اختصارات تحت */
.dash-quick{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
.dash-qbtn{
  flex:1;min-width:170px;text-align:center;text-decoration:none;font-weight:800;font-size:13px;
  border:1px solid var(--border);border-radius:12px;padding:13px;background:var(--card);color:var(--ink);
  transition:box-shadow .15s,border-color .15s;
}
.dash-qbtn:hover{border-color:var(--royal-blue);box-shadow:0 4px 14px rgba(18,57,155,.12)}
.dash-qbtn.main{background:linear-gradient(135deg,#12399B,#602D90);color:#fff;border:none}
</style>
@endsection
