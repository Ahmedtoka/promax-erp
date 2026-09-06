@extends('layouts.system')

@section('title', $u->displayName())

{{--
    ═══════════════════════════════════════════════════════════════
    كارت المندوب — كل حاجة عن شخص ميداني واحد (١٥ أغسطس ٢٠٢٦)
    ═══════════════════════════════════════════════════════════════

    بلاغ المالك: «باع 156 قطعة وأنا مش لاقي باعهم فين» — فكل رقم في
    جدول العهدة بقى زرار بيفتح مستنداته: المحمَّل يوري أوامر التجهيز
    والتحويلات اللي جابته، والمباع يوري الفواتير **وتسليمات أوامر
    التوريد** (دي اللي كانت مخفية)، والباقي يوري الباتشات وقيمتها،
    والهدايا توري اللوج اللي كان مالوش شاشة.

    الشاشة شغالة لكل الرولز الميدانية (`User::FIELD_WORK_ROLES`):
    البروموتر مالوش فواتير، والسواق أوامر مش فواتير، والمدير عنده
    الاتنين + فريقه — والأقسام بتختفي بدل ما تقف فاضية بلا معنى.

    الأوقات كلها h:i A بتوقيت القاهرة صراحةً، والفلوس بمنزلتين.
--}}

@php
    $me = auth()->user();

    $fm = fn ($n) => number_format((float) $n);
    $fm2 = fn ($n) => number_format((float) $n, 2);
    $hia = fn ($d) => $d?->copy()->timezone('Africa/Cairo')->format('h:i A');
    $dtm = fn ($d) => $d?->copy()->timezone('Africa/Cairo')->format('d/m h:i A');
    $ymd = fn ($d) => $d?->copy()->timezone('Africa/Cairo')->format('Y-m-d');

    $cur = __('common.currency');
    $lists = \App\Support\CustodyValue::lists();
    $T = $drill['totals'];

    // ⚠️ **الأزرار متحرسة بمفاتيح أكشناتها** — زرار بيودّي لـ403 أسوأ
    // من زرار مش موجود. القفل على `act.field.decide` (راوته
    // `ops.rep.close`)، والتصحيح على `act.custody.adjust`.
    $canHandout = \App\Support\Access::action($me, 'act.custody.handout');
    $canTransfer = \App\Support\Access::action($me, 'act.wh.van_transfer');
    $canClose = \App\Support\Access::action($me, 'act.field.decide');
    $canAdjust = $custody && $custody->status === 'open'
        && \App\Support\Access::action($me, 'act.custody.adjust');

    $seeVisits = \App\Support\Access::allows($me, 'ops.visits');
    $seeTracking = \App\Support\Access::allows($me, 'ops.tracking');
    $seeDay = \App\Support\Access::allows($me, 'ops.rep_day');
    $seeInvoices = \App\Support\Access::allows($me, 'ops.invoices');
    $seeSettle = \App\Support\Access::allows($me, 'erp.repclose.show');
    $seePicks = \App\Support\Access::allows($me, 'wh.picks.show');
    $seeTransferDoc = \App\Support\Access::allows($me, 'wh.transfers.print');

    // ═══ تدرّج الرول (degradation) — القسم بيبان لو الرول بيعمله أو
    //     لو فيه صفوف فعلاً. المندوب اللي مالوش فواتير خالص مايشوفش
    //     كارت فواتير فاضي، لكن السيلز اللي ماباعش النهارده يشوفه
    //     بحالة «مفيش» عشان يعرف إن الرقم صفر مش مخفي.
    $isPromoter = $u->isPromoter();
    $showInvoices = ! $isPromoter && ($u->isSalesAgent() || $u->isManager() || $invoices->isNotEmpty());
    $showPos = $u->isDriver() || $u->isManager() || $pos->isNotEmpty();
    $showColl = ! $isPromoter || $collections->isNotEmpty();
    $showReturns = ! $isPromoter || $returns->isNotEmpty();
    $showSettle = (! $isPromoter || $settlements->isNotEmpty()) && $seeSettle;
    $showMerch = $isPromoter || $merch->isNotEmpty();

    // ═══ الحضور دلوقتي — من آخر بانش (نفس ترتيب `lastPunch()`) ═══
    $lastPunch = $att?->punches->sortBy([['at', 'asc'], ['id', 'asc']])->last();
    $attState = match ($lastPunch?->type) {
        \App\Models\AttendancePunch::IN, \App\Models\AttendancePunch::BACK => 'working',
        \App\Models\AttendancePunch::BREAK => 'break',
        default => 'off',
    };
    $attClass = ['working' => 'b-green', 'break' => 'b-orange', 'off' => 'b-gray'][$attState];

    // ═══ الفلوس الموحّدة (عقيدة ١١/٨): فواتيره + أوامره المسلَّمة ═══
    $salesTotal = round((float) ($invAgg->grand ?? 0) + (float) ($poAgg->grand ?? 0), 2);
    $collTotal = round((float) ($collAgg->total ?? 0), 2);
    $collCash = round((float) ($collAgg->cash ?? 0), 2);
    $visitsDone = (int) ($visitAgg->done ?? 0);
    $visitsAll = (int) ($visitAgg->n ?? 0);
    $clientsSeen = (int) ($visitAgg->clients ?? 0);
    $clientsMissed = max($myClients - $clientsSeen, 0);
    $avgInvoice = ((int) ($invAgg->n ?? 0)) > 0
        ? round((float) $invAgg->grand / (int) $invAgg->n, 2)
        : 0.0;

    // نسبة التصريف — المخلَّص من المحمَّل (نفس معادلة عهد المناديب)
    $drainPct = $T['loaded'] > 0
        ? (int) round(($T['loaded'] - $T['remaining'] - $T['gift_left']) / $T['loaded'] * 100)
        : 0;

    $lastSettle = $settlements->first();

    $qs = ['from' => $from, 'to' => $to];
@endphp

@section('actions')
    <a class="btn" href="{{ route('ops.dashboard') }}">← {{ __('ops.dashboard') }}</a>
    @if ($canHandout)
        {{-- التحميل بقى من فلو تسليم العهدة الرسمي — مش ديالوج مباشر --}}
        <a class="btn gold" href="{{ route('ops.handout') }}">📤 {{ __('field.handout') }}</a>
    @endif
    @if ($canTransfer && $custody && $custody->status === 'open')
        <a class="btn" href="{{ route('wh.transfers.van') }}?rep={{ $u->id }}">🔄 {{ __('stock.van_transfer_short') }}</a>
    @endif
    @if ($canAdjust)
        <button class="btn" type="button" onclick="openDlg('dlgAdjust')">🛠️ {{ __('field.custody_adjust') }}</button>
    @endif
    {{-- ═══ تفريغ العربية (٢٨/٨) — أدمن بس. نفس نتيجة كتابة صفر في
         كل سطر في التصحيح الإداري، بس بضغطة وباختيار مصير البضاعة --}}
    @if ($custody && $custody->status === 'open' && $me?->role === 'admin')
        <button class="btn" type="button" onclick="openDlg('dlgClearCustody')">
            🧹 {{ __('field.clear_btn') }}
        </button>
    @endif
    @if ($seeDay)
        <a class="btn" href="{{ route('ops.rep_day', $u) }}">🗓️ {{ __('ops.rc_a_day') }}</a>
    @endif
    @if ($seeTracking)
        <a class="btn" href="{{ route('ops.tracking', ['user' => $u->id, 'date' => $to]) }}">🛰️ {{ __('ops.rc_a_tracking') }}</a>
    @endif
    @if ($seeSettle)
        <a class="btn" href="{{ route('erp.repclose.show', $u) }}">🧮 {{ __('ops.rc_a_settle') }}</a>
    @endif
    @if ($canClose && $custody && $custody->status === 'open')
        <form method="POST" action="{{ route('ops.rep.close', $u) }}" style="display:inline"
              onsubmit="return confirm({{ \Illuminate\Support\Js::from(__('ops.confirm_close_van')) }})">
            @csrf
            <button class="btn red" type="submit">{{ __('ops.close_van_stock') }}</button>
        </form>
    @endif
@endsection

@section('content')

<style>
/* زرار الرقم الكليك-إبل — شكل الرقم زي ما هو، بس بيقول «أنا بفتح».
   ⚠️ ممنوع أي تعبير بليد جوه بلوك الستايل — بيهرّب الكوتيشن ويلغي القاعدة. */
.lnk{background:none;border:0;padding:0;font:inherit;color:inherit;cursor:pointer;
     text-decoration:underline dotted;text-underline-offset:3px}
.lnk:hover{color:var(--royal-blue, #12399B)}

/* ═══ هيدر الكارت + علامة الصاعقة ═══
   الصورة `position:absolute` فمابتاخدش مساحة في التدفق خالص —
   وده اللي كان بيعمل الفراغ الضخم لما كانت `img` عادية بحجمها
   الطبيعي. والكارت `overflow:hidden` عشان الصاعقة ماتخرجش بره حدوده. */
.rc-hero{position:relative;overflow:hidden}
.rc-hero > *:not(.rc-bolt){position:relative;z-index:1}
.rc-bolt{
  position:absolute;inset-inline-end:18px;top:50%;
  transform:translateY(-50%) rotate(-9deg);
  height:120px;width:auto;opacity:.06;pointer-events:none;user-select:none;z-index:0;
}
@media (max-width:760px){.rc-bolt{display:none}}

/* رقم المستند جنب شارة المصدر — PCK / PO / TRF */
.src-ref{display:inline-block;font-size:10px;font-weight:800;color:var(--muted);
         letter-spacing:.3px;margin-inline-start:4px;text-decoration:none}
a.src-ref{color:var(--royal-blue, #12399B);text-decoration:underline dotted;text-underline-offset:2px}
a.src-ref:hover{text-decoration-style:solid}

{{-- ⚠️ ستايل `.vbtn` **مش هنا** — اتنقل لـ`layouts/system` لما
     الزرار اتوسّع لصفحة التصفية كمان. أي صفحة تانية تستخدم
     `partials._view` تلاقيه شغال من غير ما تنسخ CSS. --}}
</style>

{{-- ═══════════════════ ١. الهيدر ═══════════════════ --}}
{{-- ⚠️ **ستايل الصاعقة inline هنا عن قصد** (إصلاح ١٥/٨): كلاسات
     `.has-bolt`/`.bolt-mark` معرّفة في `partials/_doc_style` — وده
     بيتحمّل في المستندات المطبوعة بس. الصفحة دي مش مستند، فالصورة
     كانت بتنزل بحجمها الطبيعي (٤١٦px) وتدفع كل المحتوى تحتها وتسيب
     فراغ ضخم في الهيدر (بلاغ المالك). --}}
<div class="card rc-hero" style="margin-bottom:14px">
    <img class="rc-bolt" src="{{ asset('brand/bolt.svg') }}" alt="" aria-hidden="true">
    <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
        @include('partials._avatar', ['u' => $u, 'size' => 64])
        <div style="flex:1;min-width:220px">
            <div style="font-size:20px;font-weight:900">{{ $u->displayName() }}
                <span class="badge b-blue">{{ $u->roleLabel() }}</span>
                @if ($u->active)
                    <span class="badge b-green">{{ __('ops.rc_active') }}</span>
                @else
                    <span class="badge b-red">{{ __('ops.rc_inactive') }}</span>
                @endif
            </div>
            <div style="font-size:12px;color:var(--muted);display:flex;gap:4px 14px;flex-wrap:wrap;margin-top:6px">
                <span>🆔 {{ $u->code ?: '—' }}</span>
                <span>📍 {{ $u->zone?->displayName() ?? __('ops.delivery_run') }}</span>
                <span>🏢 {{ $u->branch?->displayName() ?? '—' }}</span>
                <span>👔 {{ $u->teamManager?->displayName() ?? '—' }}</span>
                <span dir="ltr">📞 {{ $u->phone ?: '—' }}</span>
                @if ($custody?->vehicle)
                    <span>🚐 {{ $custody->vehicle->plate ?? $custody->vehicle->id }}</span>
                @endif
            </div>
        </div>
        <div style="text-align:center">
            <div style="font-size:11px;color:var(--muted);font-weight:800">{{ __('ops.rc_att_now') }}</div>
            <div style="margin-top:5px"><span class="badge {{ $attClass }}">{{ __('ops.rc_att_'.$attState) }}</span></div>
            <div style="font-size:11px;color:var(--muted);margin-top:5px" dir="ltr">
                @if ($att?->first_in_at)
                    {{ $hia($att->first_in_at) }}
                    @if ($att->last_out_at)
                        → {{ $hia($att->last_out_at) }}
                    @endif
                    · {{ $att->workedLabel() }}
                @else
                    —
                @endif
            </div>
        </div>
    </div>

    @if ($openVisit)
        <div class="alert info" style="margin-top:12px">
            <span>📍</span>
            <span>{{ __('ops.rc_open_visit', [
                'client' => $openVisit->client?->displayName() ?? '—',
                'time' => $hia($openVisit->checked_in_at) ?: '—',
            ]) }}</span>
        </div>
    @endif
</div>

{{-- ═══════════════════ ٢. الفلتر + الـKPIs ═══════════════════ --}}
<form class="searchbar" method="GET">
    <div>
        <label class="f">{{ __('ops.vb_from') }}</label>
        <input type="date" name="from" value="{{ $from }}">
    </div>
    <div>
        <label class="f">{{ __('ops.vb_to') }}</label>
        <input type="date" name="to" value="{{ $to }}">
    </div>
    <button class="btn gold" type="submit">{{ __('common.filter') }}</button>
    <a class="btn" href="{{ route('ops.rep', $u) }}">{{ __('common.clear') }}</a>
    <span class="badge b-gray">{{ __('ops.rc_range_hint') }}</span>
</form>

<div class="kpis" style="margin-bottom:14px">
    <div class="kpi">
        <div class="lbl">📦 {{ __('ops.van_stock_left') }}</div>
        <div class="val">{{ $fm($T['remaining']) }}</div>
        <div class="sub2">@include('partials._list_values', ['totals' => $custodyValues])</div>
    </div>
    <div class="kpi">
        <div class="lbl">💰 {{ __('ops.rc_k_sales') }}</div>
        <div class="val pos">{{ $fm2($salesTotal) }} {{ $cur }}</div>
        <div class="sub2">{{ __('ops.rc_k_sales_sub', [
            'inv' => $fm2($invAgg->grand ?? 0),
            'po' => $fm2($poAgg->grand ?? 0),
        ]) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">💵 {{ __('ops.rc_k_collect') }}</div>
        <div class="val">{{ $fm2($collTotal) }} {{ $cur }}</div>
        <div class="sub2">{{ __('ops.rc_k_collect_sub', [
            'cash' => $fm2($collCash),
            'other' => $fm2(round($collTotal - $collCash, 2)),
        ]) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">↩️ {{ __('ops.rc_k_returns') }}</div>
        <div class="val {{ (float) ($retAgg->grand ?? 0) > 0 ? 'neg' : '' }}">{{ $fm2($retAgg->grand ?? 0) }} {{ $cur }}</div>
        <div class="sub2">{{ __('ops.rc_k_returns_sub', [
            'good' => (int) ($retAgg->good ?? 0),
            'damaged' => (int) ($retAgg->damaged ?? 0),
        ]) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">🚪 {{ __('ops.visits') }}</div>
        <div class="val">{{ $visitsDone }}/{{ $visitsAll }}</div>
        <div class="sub2">{{ __('ops.rc_k_plan', ['done' => $plan['done'], 'planned' => $plan['planned']]) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">🛣️ {{ __('ops.rc_k_km') }}</div>
        <div class="val">{{ $fm2($km) }}</div>
        <div class="sub2">{{ __('ops.rc_k_km_hint') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">🧮 {{ __('ops.rc_k_balance') }}</div>
        <div class="val {{ (float) ($lastSettle->balance ?? 0) > 0 ? 'neg' : 'pos' }}">
            {{ $fm2($lastSettle->balance ?? 0) }} {{ $cur }}</div>
        <div class="sub2">{{ $lastSettle ? $dtm($lastSettle->to_at) : __('ops.rc_s_none') }}</div>
    </div>
</div>

{{-- ═══════════════════ ٣. العهدة + الدريل داون ═══════════════════ --}}
@if ($custody)
<div class="card">
    <h3>📦 {{ __('ops.van_stock') }}
        <span class="side">{{ __('ops.rc_click_hint') }}</span>
    </h3>

    <div style="display:flex;gap:6px 14px;flex-wrap:wrap;font-size:11.5px;color:var(--muted);margin-bottom:10px">
        <span><b>{{ $custody->status === 'open' ? __('ops.open') : __('ops.closed') }}</b></span>
        <span>· {{ __('ops.rc_custody_window', [
            'from' => $dtm($drill['from']) ?: '—',
            'to' => $dtm($drill['to']) ?: '—',
        ]) }}</span>
        <span>· {{ $custody->warehouse?->displayName() ?? '—' }}</span>
        <span>· {{ __('ops.rc_drain', ['pct' => $drainPct]) }}</span>
        <span>· {{ __('ops.rc_live_hint') }}</span>
    </div>

    {{-- ═══ شريط المطابقة — المعادلة اللي لازم تقفل ═══ --}}
    @php $balanced = $T['diff'] === 0 && $T['sold_gap'] === 0; @endphp
    <div class="alert {{ $balanced ? 'good' : 'warn' }}" style="margin-bottom:12px">
        <span>{{ $balanced ? '✅' : '⚠️' }}</span>
        <span>
            <b>{{ __('ops.rc_equation') }}</b>
            <div style="margin-top:4px;font-size:12px" dir="auto">
                {{ __('ops.loaded') }} <b>{{ $fm($T['loaded']) }}</b>
                = {{ __('field.sold') }} <b>{{ $fm($T['sold']) }}</b>
                + {{ __('ops.rc_c_gifts') }} <b>{{ $fm($T['gift_given']) }}</b>
                + {{ __('ops.rc_c_returned') }} <b>{{ $fm($T['returned']) }}</b>
                + {{ __('ops.rc_c_moved') }} <b>{{ $fm($T['transferred_out']) }}</b>
                + {{ __('ops.remaining') }} <b>{{ $fm($T['remaining']) }}</b>
                + {{ __('ops.rc_c_gift_left') }} <b>{{ $fm($T['gift_left']) }}</b>
                @if ($T['diff'] !== 0)
                    <span class="badge b-red">{{ __('ops.rc_recon_bad', ['n' => $fm($T['diff'])]) }}</span>
                @endif
            </div>
            <div style="margin-top:4px;font-size:12px">
                {{ __('field.sold') }} <b>{{ $fm($T['sold']) }}</b>
                = 🧾 {{ __('ops.rc_c_by_invoice') }} <b>{{ $fm($T['inv_qty']) }}</b>
                + 🚚 {{ __('ops.rc_c_by_po') }} <b>{{ $fm($T['po_qty']) }}</b>
                @if ($T['sold_gap'] !== 0)
                    <span class="badge b-red">{{ __('ops.rc_sold_gap', ['n' => $fm($T['sold_gap'])]) }}</span>
                @endif
            </div>
            @if ($T['sold_gap'] !== 0)
                <div style="margin-top:4px;font-size:11.5px;color:var(--muted)">{{ __('ops.rc_sold_gap_hint') }}</div>
            @endif
        </span>
    </div>

    <div class="tablewrap">
        <table>
            <thead>
            <tr>
                <th>{{ __('common.code') }}</th>
                <th style="text-align:start">{{ __('stock.item') }}</th>
                <th data-nosum>{{ __('stock.source') }}</th>
                <th>{{ __('ops.loaded') }}</th>
                <th>{{ __('field.sold') }}</th>
                <th>{{ __('ops.rc_c_gifts') }}</th>
                <th>{{ __('ops.rc_c_returned') }}</th>
                <th>{{ __('ops.rc_c_moved') }}</th>
                <th>{{ __('ops.remaining') }}</th>
                @foreach ($lists as $L)
                    <th>{{ __('ops.remaining_value') }}
                        <div style="font-size:9.5px;font-weight:600;color:var(--muted)">{{ $L->displayName() }}</div>
                    </th>
                @endforeach
                <th data-nosum>{{ __('ops.rc_c_diff') }}</th>
                <th class="act" data-nosum></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($drill['rows'] as $r)
                @php $nm = ($r['product']?->displayName() ?? '#'.$r['pid']); @endphp
                <tr>
                    <td class="num">{{ $r['product']?->code ?? '—' }}</td>
                    <td style="text-align:start">
                        <b>{{ $nm }}</b>
                        <div style="font-size:10.5px;color:var(--muted)">{{ $r['product']?->unitLabel() }}</div>
                    </td>
                    <td>
                        @foreach ($r['sources'] as $s)
                            <span class="badge {{ $s['class'] }}">{{ $s['label'] }} · {{ $fm($s['qty']) }}</span>
                            {{-- رقم المستند اللي جاب البضاعة — إذن التسليم
                                 PCK أو أمر التوريد PO أو التحويل TRF، وكل
                                 واحد بيفتح ورقته. --}}
                            @foreach ($s['refs'] as $text => $url)
                                @if ($url)
                                    <a href="{{ $url }}" target="_blank" rel="noopener"
                                       class="src-ref" dir="ltr">{{ $text }}</a>
                                @else
                                    <span class="src-ref" dir="ltr">{{ $text }}</span>
                                @endif
                            @endforeach
                        @endforeach
                    </td>
                    <td class="num">
                        <button class="lnk" type="button"
                                onclick="repDrill('loaded', {{ $r['pid'] }}, {{ \Illuminate\Support\Js::from($nm) }})">
                            <b>{{ $fm($r['assigned']) }}</b></button>
                        @if ($r['gift_assigned'] > 0)
                            <div style="font-size:10px;color:var(--muted)">🎁 {{ $fm($r['gift_assigned']) }}</div>
                        @endif
                    </td>
                    <td class="num">
                        <button class="lnk" type="button"
                                onclick="repDrill('sold', {{ $r['pid'] }}, {{ \Illuminate\Support\Js::from($nm) }})">
                            <b style="color:var(--royal-blue, #12399B)">{{ $fm($r['sold']) }}</b></button>
                        <div style="font-size:10px;color:var(--muted)">
                            🧾 {{ $fm($r['inv_qty']) }} · 🚚 {{ $fm($r['po_qty']) }}</div>
                    </td>
                    <td class="num">
                        <button class="lnk" type="button"
                                onclick="repDrill('gifts', {{ $r['pid'] }}, {{ \Illuminate\Support\Js::from($nm) }})">
                            {{ $fm($r['gift_given']) }}</button>
                        @if ($r['gift_left'] > 0)
                            <div style="font-size:10px;color:var(--orange, #EA8C1C)">
                                {{ __('ops.rc_c_gift_left') }} {{ $fm($r['gift_left']) }}</div>
                        @endif
                    </td>
                    <td class="num">
                        <button class="lnk" type="button"
                                onclick="repDrill('rwh', {{ $r['pid'] }}, {{ \Illuminate\Support\Js::from($nm) }})">
                            {{ $fm($r['returned']) }}</button>
                    </td>
                    <td class="num">
                        <button class="lnk" type="button"
                                onclick="repDrill('moved', {{ $r['pid'] }}, {{ \Illuminate\Support\Js::from($nm) }})">
                            {{ $fm($r['transferred_out']) }}</button>
                    </td>
                    <td class="num pos">
                        <button class="lnk" type="button"
                                onclick="repDrill('left', {{ $r['pid'] }}, {{ \Illuminate\Support\Js::from($nm) }})">
                            <b>{{ $fm($r['remaining']) }}</b></button>
                        @if ($bd = $r['product']?->packBreakdown((int) $r['remaining']))
                            <div style="font-size:10px;color:var(--muted);white-space:nowrap">{{ $bd }}</div>
                        @endif
                    </td>
                    @foreach ($lists as $L)
                        @php $px = \App\Support\CustodyValue::priceIn($L, $r['product']); @endphp
                        <td class="num"><b>{{ $fm2($r['values'][$L->id] ?? 0) }}</b>
                            <div style="font-size:10px;color:var(--muted)" dir="ltr">× {{ $fm2($px) }}</div>
                        </td>
                    @endforeach
                    <td class="num">
                        @if ($r['diff'] === 0 && $r['sold_gap'] === 0)
                            <span class="badge b-green">✓</span>
                        @else
                            <span class="badge b-red">{{ $fm($r['diff'] !== 0 ? $r['diff'] : $r['sold_gap']) }}</span>
                        @endif
                    </td>
                    <td class="act">@include('partials._view', [
                        'url' => route('erp.products.show', $r['pid']),
                        'label' => __('stock.product'),
                    ])</td>
                </tr>
            @empty
                <tr><td colspan="{{ 11 + $lists->count() }}" style="text-align:center;color:var(--muted);padding:24px">
                    {{ __('ops.rc_no_items') }}
                </td></tr>
            @endforelse
            </tbody>
            <tfoot>
            <tr>
                <td></td>
                <td style="text-align:start"><b>Σ {{ __('common.total') }}</b></td>
                <td></td>
                <td class="num">
                    <button class="lnk" type="button"
                            onclick="repDrill('loaded', 'all', {{ \Illuminate\Support\Js::from(__('common.total')) }})">
                        <b>{{ $fm($T['assigned']) }}</b></button>
                </td>
                <td class="num">
                    <button class="lnk" type="button"
                            onclick="repDrill('sold', 'all', {{ \Illuminate\Support\Js::from(__('common.total')) }})">
                        <b>{{ $fm($T['sold']) }}</b></button>
                </td>
                <td class="num">
                    <button class="lnk" type="button"
                            onclick="repDrill('gifts', 'all', {{ \Illuminate\Support\Js::from(__('common.total')) }})">
                        <b>{{ $fm($T['gift_given']) }}</b></button>
                </td>
                <td class="num">
                    <button class="lnk" type="button"
                            onclick="repDrill('rwh', 'all', {{ \Illuminate\Support\Js::from(__('common.total')) }})">
                        <b>{{ $fm($T['returned']) }}</b></button>
                </td>
                <td class="num">
                    <button class="lnk" type="button"
                            onclick="repDrill('moved', 'all', {{ \Illuminate\Support\Js::from(__('common.total')) }})">
                        <b>{{ $fm($T['transferred_out']) }}</b></button>
                </td>
                <td class="num">
                    <button class="lnk" type="button"
                            onclick="repDrill('left', 'all', {{ \Illuminate\Support\Js::from(__('common.total')) }})">
                        <b>{{ $fm($T['remaining']) }}</b></button>
                </td>
                @foreach ($lists as $L)
                    <td class="num"><b>{{ $fm2($custodyValues[$L->id]['total'] ?? 0) }}</b></td>
                @endforeach
                <td></td>
            </tr>
            </tfoot>
        </table>
    </div>

    {{-- ═══ بضاعة العملاء في العربية — بره المعادلة بقصد ═══ --}}
    @if ($T['returned_in'] > 0 || $T['damaged_in'] > 0)
        <div class="alert" style="margin-top:12px">
            <span>📥</span>
            <span>{{ __('ops.rc_client_goods', [
                'good' => $fm($T['returned_in']),
                'damaged' => $fm($T['damaged_in']),
            ]) }}
                <button class="lnk" type="button"
                        onclick="repDrill('rin', 'all', {{ \Illuminate\Support\Js::from(__('ops.rc_d_rin')) }})">
                    <b>{{ __('ops.rc_show_docs') }}</b></button>
            </span>
        </div>
    @endif
</div>
@else
    <div class="card">
        <h3>📦 {{ __('ops.van_stock') }}</h3>
        <div style="text-align:center;color:var(--muted);padding:24px">{{ __('ops.rc_no_custody') }}</div>
    </div>
@endif

{{-- ═══════════════════ ٤. الحركة ═══════════════════ --}}
<div class="grid2">

    @if ($showInvoices)
    <div class="card">
        <h3>🧾 {{ __('ops.rc_m_invoices') }}
            <span class="side">{{ __('ops.rc_count_value', [
                'n' => (int) ($invAgg->n ?? 0), 'v' => $fm2($invAgg->grand ?? 0),
            ]) }}</span>
        </h3>
        <div class="tablewrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('ops.invoice') }}</th>
                    <th style="text-align:start">{{ __('client.client') }}</th>
                    <th data-nosum>{{ __('ops.payment') }}</th>
                    <th>{{ __('common.total') }}</th>
                    <th data-nosum>{{ __('common.time') }}</th>
                    <th class="act" data-nosum></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($invoices as $inv)
                    <tr class="clickable" onclick="location.href='{{ route('ops.invoice', $inv) }}'">
                        <td><b>{{ $inv->number }}</b></td>
                        <td style="text-align:start">{{ $inv->client?->displayName() ?? '—' }}</td>
                        <td><span class="badge {{ $inv->payment === 'cash' ? 'b-green' : 'b-orange' }}">{{ $inv->paymentLabel() }}</span></td>
                        <td class="num pos">{{ $fm2($inv->grand_total) }}</td>
                        <td class="num" dir="ltr">{{ $dtm($inv->created_at) }}</td>
                        <td class="act">@include('partials._view', ['url' => route('ops.invoice', $inv)])</td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:20px">{{ __('ops.no_invoices') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($seeInvoices)
            <div style="margin-top:8px">
                <a class="btn sm" href="{{ route('ops.invoices', $qs + ['user' => $u->id]) }}">{{ __('ops.rc_all') }}</a>
            </div>
        @endif
    </div>
    @endif

    @if ($showPos)
    <div class="card">
        <h3>🚚 {{ __('ops.rc_m_pos') }}
            <span class="side">{{ __('ops.rc_count_value', [
                'n' => (int) ($poAgg->n ?? 0), 'v' => $fm2($poAgg->grand ?? 0),
            ]) }}</span>
        </h3>
        <div class="tablewrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('ops.purchase_order') }}</th>
                    <th style="text-align:start">{{ __('client.client') }}</th>
                    <th>{{ __('ops.rc_d_qty') }}</th>
                    <th>{{ __('common.total') }}</th>
                    <th data-nosum>{{ __('common.time') }}</th>
                    <th class="act" data-nosum></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($pos as $po)
                    <tr class="clickable" onclick="location.href='{{ route('ops.pos.show', $po) }}'">
                        <td><b>{{ $po->number }}</b></td>
                        <td style="text-align:start">{{ $po->client?->displayName() ?? '—' }}</td>
                        <td class="num">{{ $fm($po->deliveredQtyTotal()) }}</td>
                        <td class="num pos">{{ $fm2($po->grand_total) }}</td>
                        <td class="num" dir="ltr">{{ $dtm($po->delivered_at) }}</td>
                        <td class="act">@include('partials._view', ['url' => route('ops.pos.show', $po)])</td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:20px">{{ __('ops.rc_none') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div style="margin-top:8px">
            <a class="btn sm" href="{{ route('ops.pos') }}">{{ __('ops.rc_all') }}</a>
        </div>
    </div>
    @endif

    @if ($showColl)
    <div class="card">
        <h3>💵 {{ __('ops.rc_m_collections') }}
            <span class="side">{{ __('ops.rc_count_value', [
                'n' => (int) ($collAgg->n ?? 0), 'v' => $fm2($collTotal),
            ]) }}</span>
        </h3>
        <div class="tablewrap">
            <table>
                <thead>
                <tr>
                    <th style="text-align:start">{{ __('client.client') }}</th>
                    <th data-nosum>{{ __('ops.rc_d_method') }}</th>
                    <th>{{ __('common.amount') }}</th>
                    <th data-nosum>{{ __('ops.rc_d_ref') }}</th>
                    <th data-nosum>{{ __('common.time') }}</th>
                    <th class="act" data-nosum></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($collections as $t)
                    <tr>
                        <td style="text-align:start">
                            @if ($t->client_id)
                                <a href="{{ route('erp.clients.show', $t->client_id) }}">{{ $t->client?->displayName() ?? '—' }}</a>
                            @else
                                —
                            @endif
                        </td>
                        <td><span class="badge {{ $t->method === 'cash' ? 'b-green' : 'b-blue' }}">{{ $t->methodLabel() ?? '—' }}</span></td>
                        <td class="num pos">{{ $fm2($t->credit) }}</td>
                        <td class="num" dir="ltr">{{ $t->reference ?: '—' }}</td>
                        <td class="num" dir="ltr">{{ $dtm($t->created_at) }}</td>
                        {{-- التحصيل قيد في كشف الحساب مالوش ورقة مستقلة —
                             فالعرض بيفتح كارت العميل اللي القيد عليه. --}}
                        <td class="act">@include('partials._view', [
                            'url' => $t->client_id ? route('erp.clients.show', $t->client_id) : null,
                            'label' => __('client.client'),
                        ])</td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:20px">{{ __('ops.rc_none') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @endif

    @if ($showReturns)
    <div class="card">
        <h3>↩️ {{ __('ops.rc_m_returns') }}
            <span class="side">{{ __('ops.rc_count_value', [
                'n' => (int) ($retAgg->n ?? 0), 'v' => $fm2($retAgg->grand ?? 0),
            ]) }}</span>
        </h3>
        <div class="tablewrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('ops.rc_d_doc') }}</th>
                    <th style="text-align:start">{{ __('client.client') }}</th>
                    <th>{{ __('ops.rc_c_good') }}</th>
                    <th>{{ __('ops.rc_c_damaged') }}</th>
                    <th>{{ __('common.total') }}</th>
                    <th data-nosum>{{ __('common.time') }}</th>
                    <th class="act" data-nosum></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($returns as $d)
                    <tr class="clickable" onclick="location.href='{{ route('ops.returns.show', $d) }}'">
                        <td><b>{{ $d->number }}</b></td>
                        <td style="text-align:start">{{ $d->client?->displayName() ?? '—' }}</td>
                        <td class="num">{{ $fm($d->good_units) }}</td>
                        <td class="num neg">{{ $fm($d->damaged_units) }}</td>
                        <td class="num">{{ $fm2($d->grand_total) }}</td>
                        <td class="num" dir="ltr">{{ $dtm($d->created_at) }}</td>
                        <td class="act">@include('partials._view', ['url' => route('ops.returns.show', $d)])</td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:20px">{{ __('ops.rc_none') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @endif

    <div class="card">
        <h3>🔄 {{ __('ops.rc_m_transfers') }}</h3>
        <div class="tablewrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('ops.rc_d_doc') }}</th>
                    <th data-nosum>{{ __('ops.rc_d_dir') }}</th>
                    <th style="text-align:start">{{ __('ops.rc_d_party') }}</th>
                    <th>{{ __('ops.rc_d_qty') }}</th>
                    <th style="text-align:start" data-nosum>{{ __('stock.transfer_reason') }}</th>
                    <th data-nosum>{{ __('common.time') }}</th>
                    <th class="act" data-nosum></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($transfers as $t)
                    @php $out = (int) $t->from_user_id === (int) $u->id; @endphp
                    <tr @if ($seeTransferDoc) class="clickable" onclick="location.href='{{ route('wh.transfers.print', $t) }}'" @endif>
                        <td><b>{{ $t->number }}</b></td>
                        <td><span class="badge {{ $out ? 'b-red' : 'b-green' }}">{{ $out ? __('ops.rc_d_out') : __('ops.rc_d_in') }}</span></td>
                        <td style="text-align:start">
                            {{ $out
                                ? ($t->kindKey() === 'rep_wh'
                                    ? ($t->toWarehouse?->displayName() ?? '—')
                                    : ($t->toUser?->displayName() ?? '—'))
                                : ($t->fromUser?->displayName() ?? $t->fromWarehouse?->displayName() ?? '—') }}
                        </td>
                        <td class="num">{{ $fm($t->qtySent()) }}</td>
                        <td style="text-align:start;font-size:11.5px;color:var(--muted)">{{ $t->reason ?: '—' }}</td>
                        <td class="num" dir="ltr">{{ $dtm($t->created_at) }}</td>
                        {{-- ⚠️ ورقة التحويل محكومة بالصلاحية زي ما هي —
                             الزرار بيتعرض مطفي لغير المصرّح مش بيختفي. --}}
                        <td class="act">@include('partials._view', [
                            'url' => $seeTransferDoc ? route('wh.transfers.print', $t) : null,
                        ])</td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:20px">{{ __('ops.rc_none') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>📦 {{ __('ops.rc_m_goods') }}</h3>
        <div class="tablewrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('ops.rc_d_doc') }}</th>
                    <th style="text-align:start">{{ __('client.client') }}</th>
                    <th data-nosum>{{ __('common.status') }}</th>
                    <th>{{ __('ops.rc_d_qty') }}</th>
                    <th data-nosum>{{ __('common.time') }}</th>
                    <th class="act" data-nosum></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($goods as $g)
                    <tr>
                        <td><b>{{ $g->number }}</b>
                            @if ($g->purchaseOrder)
                                <div style="font-size:10px;color:var(--muted)" dir="ltr">{{ $g->purchaseOrder->number }}</div>
                            @endif
                        </td>
                        <td style="text-align:start">{{ $g->client?->displayName() ?? '—' }}</td>
                        <td><span class="badge {{ $g->statusClass() }}">{{ $g->statusLabel() }}</span></td>
                        <td class="num">{{ $fm($g->qtyTotal()) }}</td>
                        <td class="num" dir="ltr">{{ $dtm($g->created_at) }}</td>
                        {{-- ⚠️ طلب البضاعة **مالوش صفحة مستقلة** — الفلو
                             بيحوّله أمر توريد، والأمر ده هو الورقة. فلو
                             لسه ماتحوّلش، الزرار بيفضل مطفي بدل ما نبعت
                             المستخدم لليست عامة مش دي اللي هو عايزها. --}}
                        <td class="act">@include('partials._view', [
                            'url' => $g->purchaseOrder ? route('ops.pos.show', $g->purchase_order_id) : null,
                            'label' => __('ops.purchase_order'),
                        ])</td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:20px">{{ __('ops.rc_none') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div style="margin-top:8px">
            <a class="btn sm" href="{{ route('ops.replenishments') }}">{{ __('ops.rc_all') }}</a>
        </div>
    </div>

</div>

{{-- ═══════════════════ ٥. الميدان ═══════════════════ --}}
<div class="grid2">
    <div class="card">
        <h3>🚪 {{ __('ops.rc_f_visits') }}
            <span class="side">{{ $visitsDone }}/{{ $visitsAll }}</span>
        </h3>
        <div class="tablewrap">
            <table>
                <thead>
                <tr>
                    <th style="text-align:start">{{ __('client.client') }}</th>
                    <th data-nosum>{{ __('ops.check_in') }}</th>
                    <th data-nosum>{{ __('ops.vb_duration') }}</th>
                    <th data-nosum style="text-align:start">{{ __('ops.vb_outcome') }}</th>
                    <th class="act" data-nosum></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($visits as $v)
                    @php $o = $outcomes[$v->id] ?? \App\Support\VisitOutcomes::blank(); @endphp
                    <tr>
                        <td style="text-align:start">
                            @if ($v->client_id)
                                <a href="{{ route('erp.clients.show', $v->client_id) }}"><b>{{ $v->client?->displayName() ?? '—' }}</b></a>
                            @else
                                <b>—</b>
                            @endif
                        </td>
                        <td class="num" dir="ltr">{{ $dtm($v->checked_in_at) ?: '—' }}</td>
                        <td class="num">{{ $v->minutes() !== null ? __('ops.minutes', ['count' => $v->minutes()]) : '—' }}</td>
                        <td style="text-align:start;white-space:normal">
                            @foreach ($o['invoices'] as $iv)
                                <a class="badge b-green" style="text-decoration:none"
                                   href="{{ route('ops.invoice', $iv->id) }}">🧾 {{ $iv->number }} · {{ $fm2($iv->grand_total) }}</a>
                            @endforeach
                            @if ($o['coll_count'] > 0)
                                <span class="badge b-blue">💵 {{ $fm2($o['coll_total']) }}</span>
                            @endif
                            @if ($o['ret_count'] > 0)
                                <span class="badge b-red">↩️ {{ $fm2($o['ret_total']) }}</span>
                            @endif
                            @if ($o['gift_count'] > 0)
                                <span class="badge b-gold">🎁 {{ $o['gift_qty'] }}</span>
                            @endif
                            @if ($o['goods_count'] > 0)
                                <span class="badge b-orange">📦 {{ $o['goods_count'] }}</span>
                            @endif
                            @foreach ($o['photos']->take(4) as $ph)
                                <a href="{{ $ph->url() }}" target="_blank" rel="noopener">
                                    <img src="{{ $ph->url() }}" alt="" loading="lazy"
                                         style="width:38px;height:38px;object-fit:cover;border-radius:7px;border:1px solid var(--border);vertical-align:middle">
                                </a>
                            @endforeach
                            @if (! $o['any'])
                                <span class="badge b-gray">{{ __('ops.vb_nothing') }}</span>
                            @endif
                        </td>
                        {{-- ⚠️ الزيارة مالهاش صفحة مستقلة، وبورد الزيارات
                             **مافيهوش فلتر عميل** (`VisitBoardController`
                             بيقرا user/zone/has_* بس) — فلينك ليه كان
                             هيرمي البارامتر بصمت ويفتح الليست كلها.
                             كارت العميل هو الوجهة الصادقة. --}}
                        <td class="act">@include('partials._view', [
                            'url' => $v->client_id ? route('erp.clients.show', $v->client_id) : null,
                            'label' => __('client.client'),
                        ])</td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:20px">{{ __('ops.no_visits') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($seeVisits)
            <div style="margin-top:8px">
                <a class="btn sm" href="{{ route('ops.visits', $qs + ['user' => $u->id]) }}">{{ __('ops.vb_see_all') }}</a>
            </div>
        @endif
    </div>

    <div class="card">
        <h3>🛰️ {{ __('ops.rc_f_timeline') }}
            <span class="side">{{ __('ops.rc_f_last10') }}</span>
        </h3>
        <div class="alerts" style="max-height:380px;overflow-y:auto">
            @forelse ($events as $e)
                @php $cls = match ($e->type) {
                    'sale', 'deliver' => 'good',
                    'check_in', 'start' => 'info',
                    'request', 'custody_transfer', 'custody_adjust' => 'warn',
                    default => '',
                }; @endphp
                <div class="alert {{ $cls }}">
                    <div><b dir="ltr">{{ $hia($e->happened_at) }}</b> — {{ $e->title }}
                        @if ($e->subtitle)
                            <span style="color:var(--muted)"> • {{ $e->subtitle }}</span>
                        @endif
                        @if ($e->lat === null || $e->lng === null)
                            <span title="{{ __('ops.rc_f_nogps') }}">📵</span>
                        @endif
                    </div>
                </div>
            @empty
                <div style="text-align:center;color:var(--muted);padding:20px">{{ __('ops.no_activity') }}</div>
            @endforelse
        </div>
        @if ($seeTracking)
            <div style="margin-top:8px">
                <a class="btn sm" href="{{ route('ops.tracking', ['user' => $u->id, 'date' => $to]) }}">{{ __('ops.rc_f_route') }}</a>
            </div>
        @endif
    </div>
</div>

@if ($showMerch)
<div class="card">
    <h3>🛒 {{ __('ops.rc_f_merch') }}</h3>
    <div class="tablewrap">
        <table>
            <thead>
            <tr>
                <th style="text-align:start">{{ __('client.client') }}</th>
                <th data-nosum>{{ __('ops.check_in') }}</th>
                <th>{{ __('ops.rc_f_moved') }}</th>
                <th>{{ __('ops.rc_f_oos') }}</th>
                <th data-nosum>{{ __('field.shelf_photos') }}</th>
                <th class="act" data-nosum></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($merch as $mv)
                <tr>
                    <td style="text-align:start">{{ $mv->client?->displayName() ?? '—' }}</td>
                    <td class="num" dir="ltr">{{ $dtm($mv->checked_in_at ?? $mv->created_at) }}</td>
                    <td class="num">{{ $fm($mv->movedTotal()) }}</td>
                    <td class="num {{ $mv->outOfStockCount() > 0 ? 'neg' : '' }}">{{ $fm($mv->outOfStockCount()) }}</td>
                    <td>
                        @if ($mv->photoBeforeUrl())
                            <a href="{{ $mv->photoBeforeUrl() }}" target="_blank" rel="noopener">
                                <img src="{{ $mv->photoBeforeUrl() }}" alt="" loading="lazy"
                                     style="width:38px;height:38px;object-fit:cover;border-radius:7px;border:1px solid var(--border)"></a>
                        @endif
                        @if ($mv->photoAfterUrl())
                            <a href="{{ $mv->photoAfterUrl() }}" target="_blank" rel="noopener">
                                <img src="{{ $mv->photoAfterUrl() }}" alt="" loading="lazy"
                                     style="width:38px;height:38px;object-fit:cover;border-radius:7px;border:1px solid var(--border)"></a>
                        @endif
                    </td>
                    <td class="act">@include('partials._view', [
                        'url' => $mv->client_id ? route('erp.clients.show', $mv->client_id) : null,
                        'label' => __('client.client'),
                    ])</td>
                </tr>
            @empty
                <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:20px">{{ __('ops.rc_none') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div style="margin-top:8px">
        <a class="btn sm" href="{{ route('ops.merch') }}">{{ __('ops.rc_all') }}</a>
    </div>
</div>
@endif

{{-- ═══════════════════ ٦. الأداء ═══════════════════ --}}
<div class="card">
    <h3>🏆 {{ __('ops.rc_p_title') }}
        <span class="side">{{ __('ops.rc_p_month', ['m' => $perfMonth]) }}</span>
    </h3>
    <div class="kpis">
        <div class="kpi">
            <div class="lbl">🎯 {{ __('ops.rc_p_target') }}</div>
            <div class="val">{{ $fm2($perf['target']->money_target ?? 0) }} {{ $cur }}</div>
            <div class="sub2">{{ __('ops.rc_p_achieved') }}: {{ $fm2($perf['net_sales']) }} {{ $cur }}</div>
        </div>
        <div class="kpi">
            <div class="lbl">📈 {{ __('ops.rc_p_pct') }}</div>
            <div class="val {{ $perf['money_pct'] >= 100 ? 'pos' : ($perf['money_pct'] >= 60 ? 'mid' : 'neg') }}">
                {{ number_format((float) $perf['money_pct'], 1) }}%</div>
            <div class="sub2">{{ __('ops.rc_p_commission') }}: {{ $fm2($perf['commission']) }} {{ $cur }}</div>
        </div>
        <div class="kpi">
            <div class="lbl">📤 {{ __('ops.rc_p_drain') }}</div>
            <div class="val">{{ $drainPct }}%</div>
            <div class="sub2">{{ __('ops.rc_p_drain_hint') }}</div>
        </div>
        <div class="kpi">
            <div class="lbl">🧾 {{ __('ops.rc_p_avg_invoice') }}</div>
            <div class="val">{{ $fm2($avgInvoice) }} {{ $cur }}</div>
            <div class="sub2">{{ __('ops.rc_count_value', [
                'n' => (int) ($invAgg->n ?? 0), 'v' => $fm2($invAgg->grand ?? 0),
            ]) }}</div>
        </div>
        <div class="kpi">
            <div class="lbl">🏬 {{ __('ops.rc_p_clients_seen') }}</div>
            <div class="val">{{ $fm($clientsSeen) }}</div>
            <div class="sub2">{{ __('ops.rc_p_clients_missed', ['n' => $fm($clientsMissed)]) }}</div>
        </div>
        <div class="kpi">
            <div class="lbl">⭐ {{ __('ops.rc_p_points') }}</div>
            <div class="val">{{ $fm($perf['points']) }}</div>
            <div class="sub2">{{ __('ops.rc_p_new_clients', ['n' => $perf['new_clients']]) }}</div>
        </div>
    </div>
</div>

{{-- ═══════════════════ ٧. التصفيات + الفريق ═══════════════════ --}}
<div class="grid2">
    @if ($showSettle)
    <div class="card">
        <h3>🧮 {{ __('ops.rc_s_title') }}</h3>
        <div class="tablewrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('ops.rc_d_doc') }}</th>
                    <th data-nosum>{{ __('ops.rc_s_window') }}</th>
                    <th>{{ __('ops.rc_s_expected') }}</th>
                    <th>{{ __('ops.rc_s_received') }}</th>
                    <th>{{ __('ops.rc_s_balance') }}</th>
                    <th class="act" data-nosum></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($settlements as $s)
                    <tr class="clickable" onclick="location.href='{{ route('erp.repclose.doc', $s) }}'">
                        <td><b>{{ $s->number }}</b></td>
                        <td class="num" dir="ltr">{{ $ymd($s->from_at) }} → {{ $ymd($s->to_at) }}</td>
                        <td class="num">{{ $fm2($s->expected) }}</td>
                        <td class="num pos">{{ $fm2($s->received) }}</td>
                        <td class="num {{ (float) $s->balance > 0 ? 'neg' : 'pos' }}">{{ $fm2($s->balance) }}</td>
                        <td class="act">@include('partials._view', ['url' => route('erp.repclose.doc', $s)])</td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:20px">{{ __('ops.rc_s_none') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @endif

    @if ($team->isNotEmpty())
    <div class="card">
        <h3>👥 {{ __('ops.rc_team') }} <span class="side">{{ $team->count() }}</span></h3>
        <div class="tablewrap">
            <table>
                <thead>
                <tr>
                    <th style="text-align:start">{{ __('ops.rep') }}</th>
                    <th data-nosum>{{ __('team.role') }}</th>
                    <th data-nosum>{{ __('client.zone') }}</th>
                    <th class="act" data-nosum></th>
                </tr>
                </thead>
                <tbody>
                @foreach ($team as $m)
                    <tr class="clickable" onclick="location.href='{{ route('ops.rep', $m) }}'">
                        <td style="text-align:start">
                            <div style="display:flex;gap:8px;align-items:center">
                                @include('partials._avatar', ['u' => $m, 'size' => 28])
                                <b>{{ $m->displayName() }}</b>
                            </div>
                        </td>
                        <td>{{ $m->roleLabel() }}</td>
                        <td>{{ $m->zone?->displayName() ?? '—' }}</td>
                        <td class="act">@include('partials._view', ['url' => route('ops.rep', $m)])</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif
</div>

{{-- ═══════════════════ مودالات الدريل داون ═══════════════════
     مودال لكل نوع، وجواه **كل** الصفوف بمفتاح `data-pid` — الفلترة
     في الفرونت. مفيش راوت جديد ومفيش AJAX: الصفحة بتيجي بكل حاجة
     في كويريز مجمّعة، فالدوسة بتفتح فوراً من غير أي نداء تاني.
     ⚠️ جداول جوه `dialog` بتتخطّى مولّد صف الإجماليات في اللاي أوت. --}}

{{-- المحمَّل --}}
<dialog id="dlgD_loaded" class="wide">
    <div class="dlg">
        <h4>📥 {{ __('ops.rc_d_loaded') }} — <span data-title></span></h4>
        <div data-sec>
            <div class="tablewrap" style="max-height:58vh;overflow:auto">
                <table>
                    <thead>
                    <tr>
                        <th>{{ __('ops.rc_d_doc') }}</th>
                        <th data-nosum>{{ __('stock.source') }}</th>
                        <th data-nosum>{{ __('common.time') }}</th>
                        <th>{{ __('ops.rc_d_qty') }}</th>
                        <th>{{ __('ops.rc_c_gifts') }}</th>
                        <th data-nosum>{{ __('stock.batch') }}</th>
                        <th style="text-align:start" data-nosum>{{ __('ops.rc_d_from') }}</th>
                        <th class="act" data-nosum></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($drill['loaded'] as $r)
                        @php
                            // ورقة الصف: إذن التجهيز أو مستند التحويل،
                            // كل واحد بصلاحيته. الشارة القديمة «المستندات»
                            // اتشالت — بقى زرار العرض الموحّد في آخر الصف.
                            $rowDoc = match (true) {
                                $r['kind'] === 'pick' && $seePicks => route('wh.picks.show', $r['id']),
                                $r['kind'] === 'transfer' && $seeTransferDoc => route('wh.transfers.print', $r['id']),
                                default => null,
                            };
                        @endphp
                        <tr data-pid="{{ $r['pid'] }}">
                            <td><b>{{ $r['doc'] }}</b>
                                @if ($r['ref'])
                                    <div style="font-size:10px;color:var(--muted)" dir="ltr">{{ $r['ref'] }}</div>
                                @endif
                            </td>
                            <td><span class="badge {{ \App\Models\CustodyItem::SOURCES[$r['source']] ?? 'b-gray' }}">{{ __('stock.src_'.$r['source']) }}</span></td>
                            <td class="num" dir="ltr">{{ $dtm($r['at']) }}</td>
                            <td class="num"><b>{{ $fm($r['qty']) }}</b></td>
                            <td class="num">{{ $r['gift'] > 0 ? $fm($r['gift']) : '—' }}</td>
                            <td class="num" dir="ltr">{{ $r['batch'] ?: '—' }}
                                @if ($r['expires'])
                                    <div style="font-size:10px;color:var(--muted)">{{ $ymd($r['expires']) }}</div>
                                @endif
                            </td>
                            <td style="text-align:start">{{ $r['place'] ?: '—' }}
                                @if ($r['by'])
                                    <div style="font-size:10px;color:var(--muted)">{{ $r['by'] }}</div>
                                @endif
                            </td>
                            <td class="act">@include('partials._view', ['url' => $rowDoc])</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        <div data-empty style="display:none;text-align:center;color:var(--muted);padding:24px">{{ __('ops.rc_none') }}</div>
        <div class="formbar"><span class="formbar-sp"></span>
            <button class="btn" type="button" onclick="closeDlg('dlgD_loaded')">{{ __('common.close') }}</button>
        </div>
    </div>
</dialog>

{{-- المباع — فواتير + تسليمات أوامر توريد (الفصل اللي المالك سأل عنه) --}}
<dialog id="dlgD_sold" class="wide">
    <div class="dlg">
        <h4>💰 {{ __('ops.rc_d_sold') }} — <span data-title></span></h4>
        <div class="alert info" style="margin-bottom:10px">
            <span>ℹ️</span><span>{{ __('ops.rc_d_sold_hint') }}</span>
        </div>

        <div data-sec>
            <div style="font-weight:800;font-size:12.5px;margin:6px 0">🧾 {{ __('ops.rc_c_by_invoice') }}</div>
            <div class="tablewrap" style="max-height:32vh;overflow:auto">
                <table>
                    <thead>
                    <tr>
                        <th>{{ __('ops.invoice') }}</th>
                        <th style="text-align:start">{{ __('client.client') }}</th>
                        <th data-nosum>{{ __('ops.payment') }}</th>
                        <th data-nosum>{{ __('common.time') }}</th>
                        <th>{{ __('ops.rc_d_qty') }}</th>
                        <th data-nosum>{{ __('ops.rc_d_price') }}</th>
                        <th>{{ __('common.total') }}</th>
                        <th class="act" data-nosum></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($drill['sold_inv'] as $r)
                        <tr data-pid="{{ $r['pid'] }}">
                            <td><a href="{{ route('ops.invoice', $r['id']) }}"><b>{{ $r['doc'] }}</b></a></td>
                            <td style="text-align:start">
                                @if ($r['client_id'])
                                    <a href="{{ route('erp.clients.show', $r['client_id']) }}">{{ $r['client'] }}</a>
                                @else
                                    {{ $r['client'] ?: '—' }}
                                @endif
                            </td>
                            <td><span class="badge {{ $r['cash'] ? 'b-green' : 'b-orange' }}">{{ $r['cash'] ? __('enums.payment.cash') : __('enums.payment.credit') }}</span></td>
                            <td class="num" dir="ltr">{{ $dtm($r['at']) }}</td>
                            <td class="num"><b>{{ $fm($r['qty']) }}</b></td>
                            <td class="num">{{ $fm2($r['price']) }}</td>
                            <td class="num pos">{{ $fm2($r['total']) }}</td>
                            <td class="act">@include('partials._view', ['url' => route('ops.invoice', $r['id'])])</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div data-sec>
            <div style="font-weight:800;font-size:12.5px;margin:12px 0 6px">🚚 {{ __('ops.rc_c_by_po') }}</div>
            <div class="tablewrap" style="max-height:32vh;overflow:auto">
                <table>
                    <thead>
                    <tr>
                        <th>{{ __('ops.purchase_order') }}</th>
                        <th style="text-align:start">{{ __('client.client') }}</th>
                        <th data-nosum>{{ __('common.time') }}</th>
                        <th>{{ __('ops.rc_d_delivered') }}</th>
                        <th data-nosum>{{ __('ops.rc_d_ordered') }}</th>
                        <th data-nosum>{{ __('ops.rc_d_price') }}</th>
                        <th>{{ __('common.total') }}</th>
                        <th class="act" data-nosum></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($drill['sold_po'] as $r)
                        <tr data-pid="{{ $r['pid'] }}">
                            <td><a href="{{ route('ops.pos.show', $r['id']) }}"><b>{{ $r['doc'] }}</b></a></td>
                            <td style="text-align:start">
                                @if ($r['client_id'])
                                    <a href="{{ route('erp.clients.show', $r['client_id']) }}">{{ $r['client'] }}</a>
                                @else
                                    {{ $r['client'] ?: '—' }}
                                @endif
                            </td>
                            <td class="num" dir="ltr">{{ $dtm($r['at']) }}</td>
                            <td class="num"><b>{{ $fm($r['qty']) }}</b></td>
                            <td class="num">{{ $fm($r['ordered']) }}</td>
                            <td class="num">{{ $fm2($r['price']) }}</td>
                            <td class="num pos">{{ $fm2($r['total']) }}</td>
                            <td class="act">@include('partials._view', ['url' => route('ops.pos.show', $r['id'])])</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div data-empty style="display:none;text-align:center;color:var(--muted);padding:24px">{{ __('ops.rc_none') }}</div>
        <div class="formbar"><span class="formbar-sp"></span>
            <button class="btn" type="button" onclick="closeDlg('dlgD_sold')">{{ __('common.close') }}</button>
        </div>
    </div>
</dialog>

{{-- المتبقي — الباتشات وقيمتها --}}
<dialog id="dlgD_left" class="wide">
    <div class="dlg">
        <h4>📦 {{ __('ops.rc_d_left') }} — <span data-title></span></h4>
        <div data-sec>
            <div class="tablewrap" style="max-height:58vh;overflow:auto">
                <table>
                    <thead>
                    <tr>
                        <th data-nosum>{{ __('stock.batch') }}</th>
                        <th data-nosum>{{ __('stock.expiry') }}</th>
                        <th data-nosum>{{ __('stock.source') }}</th>
                        <th>{{ __('ops.remaining') }}</th>
                        @foreach ($lists as $L)
                            <th>{{ $L->displayName() }}</th>
                        @endforeach
                        <th class="act" data-nosum></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($drill['batches'] as $r)
                        <tr data-pid="{{ $r['pid'] }}">
                            <td class="num" dir="ltr">{{ $r['batch'] }}</td>
                            <td class="num" dir="ltr">{{ $r['expires'] ? $ymd($r['expires']) : '—' }}
                                @if ($r['days'] !== null)
                                    <div style="font-size:10px;color:var(--muted)">{{ __('ops.rc_d_days', ['n' => $r['days']]) }}</div>
                                @endif
                            </td>
                            <td><span class="badge {{ \App\Models\CustodyItem::SOURCES[$r['source']] ?? 'b-gray' }}">{{ __('stock.src_'.$r['source']) }}</span>
                                @if ($r['source_ref'])
                                    <div style="font-size:10px;color:var(--muted)" dir="ltr">{{ $r['source_ref'] }}</div>
                                @endif
                            </td>
                            <td class="num"><b>{{ $fm($r['qty']) }}</b></td>
                            @foreach ($lists as $L)
                                <td class="num">{{ $fm2($r['values'][$L->id] ?? 0) }}</td>
                            @endforeach
                            {{-- الباتش مالوش صفحة — كارت الصنف فيه حركته
                                 وباتشاته وأسعاره، وده اللي المستخدم بيدوّر
                                 عليه وهو واقف على صف باتش. --}}
                            <td class="act">@include('partials._view', [
                                'url' => route('erp.products.show', $r['pid']),
                                'label' => __('stock.product'),
                            ])</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        <div data-empty style="display:none;text-align:center;color:var(--muted);padding:24px">{{ __('ops.rc_none') }}</div>
        <div class="formbar"><span class="formbar-sp"></span>
            <button class="btn" type="button" onclick="closeDlg('dlgD_left')">{{ __('common.close') }}</button>
        </div>
    </div>
</dialog>

{{-- الهدايا --}}
<dialog id="dlgD_gifts" class="wide">
    <div class="dlg">
        <h4>🎁 {{ __('ops.rc_d_gifts') }} — <span data-title></span></h4>
        <div data-sec>
            <div class="tablewrap" style="max-height:58vh;overflow:auto">
                <table>
                    <thead>
                    <tr>
                        <th data-nosum>{{ __('common.time') }}</th>
                        <th style="text-align:start">{{ __('ops.rc_d_recipient') }}</th>
                        <th>{{ __('ops.rc_d_qty') }}</th>
                        <th style="text-align:start" data-nosum>{{ __('stock.transfer_reason') }}</th>
                        <th class="act" data-nosum></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($drill['gifts'] as $r)
                        <tr data-pid="{{ $r['pid'] }}">
                            <td class="num" dir="ltr">{{ $dtm($r['at']) }}</td>
                            <td style="text-align:start">
                                @if ($r['client_id'])
                                    <a href="{{ route('erp.clients.show', $r['client_id']) }}">{{ $r['client'] }}</a>
                                @else
                                    {{ $r['client'] ?: '—' }}
                                @endif
                            </td>
                            <td class="num"><b>{{ $fm($r['qty']) }}</b></td>
                            <td style="text-align:start;font-size:11.5px;color:var(--muted)">
                                {{ $r['reason'] ?: '—' }}
                                @if ($r['note'])
                                    <div>{{ $r['note'] }}</div>
                                @endif
                            </td>
                            <td class="act">@include('partials._view', [
                                'url' => $r['client_id'] ? route('erp.clients.show', $r['client_id']) : null,
                                'label' => __('client.client'),
                            ])</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        <div data-empty style="display:none;text-align:center;color:var(--muted);padding:24px">{{ __('ops.rc_no_gifts') }}</div>
        <div class="formbar"><span class="formbar-sp"></span>
            <button class="btn" type="button" onclick="closeDlg('dlgD_gifts')">{{ __('common.close') }}</button>
        </div>
    </div>
</dialog>

{{-- مرجّع للمخزن --}}
<dialog id="dlgD_rwh" class="wide">
    <div class="dlg">
        <h4>🏭 {{ __('ops.rc_d_rwh') }} — <span data-title></span></h4>
        @include('ops._rep_transfer_rows', ['rows' => $drill['returns_wh'], 'seeDoc' => $seeTransferDoc])
        <div data-empty style="display:none;text-align:center;color:var(--muted);padding:24px">{{ __('ops.rc_none') }}</div>
        <div class="formbar"><span class="formbar-sp"></span>
            <button class="btn" type="button" onclick="closeDlg('dlgD_rwh')">{{ __('common.close') }}</button>
        </div>
    </div>
</dialog>

{{-- التحويلات (رايح وجاي) --}}
<dialog id="dlgD_moved" class="wide">
    <div class="dlg">
        <h4>🔄 {{ __('ops.rc_d_moved') }} — <span data-title></span></h4>
        @include('ops._rep_transfer_rows', ['rows' => $drill['transfers'], 'seeDoc' => $seeTransferDoc])
        <div data-empty style="display:none;text-align:center;color:var(--muted);padding:24px">{{ __('ops.rc_none') }}</div>
        <div class="formbar"><span class="formbar-sp"></span>
            <button class="btn" type="button" onclick="closeDlg('dlgD_moved')">{{ __('common.close') }}</button>
        </div>
    </div>
</dialog>

{{-- مرتجع داخل العربية / تالف --}}
<dialog id="dlgD_rin" class="wide">
    <div class="dlg">
        <h4>📥 {{ __('ops.rc_d_rin') }} — <span data-title></span></h4>
        <div data-sec>
            <div class="tablewrap" style="max-height:58vh;overflow:auto">
                <table>
                    <thead>
                    <tr>
                        <th>{{ __('ops.rc_d_doc') }}</th>
                        <th style="text-align:start">{{ __('client.client') }}</th>
                        <th data-nosum>{{ __('common.time') }}</th>
                        <th data-nosum>{{ __('ops.rc_d_condition') }}</th>
                        <th>{{ __('ops.rc_d_qty') }}</th>
                        <th>{{ __('common.total') }}</th>
                        <th class="act" data-nosum></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($drill['returns_in'] as $r)
                        <tr data-pid="{{ $r['pid'] }}">
                            <td><a href="{{ route('ops.returns.show', $r['id']) }}"><b>{{ $r['doc'] }}</b></a></td>
                            <td style="text-align:start">
                                @if ($r['client_id'])
                                    <a href="{{ route('erp.clients.show', $r['client_id']) }}">{{ $r['client'] }}</a>
                                @else
                                    {{ $r['client'] ?: '—' }}
                                @endif
                            </td>
                            <td class="num" dir="ltr">{{ $dtm($r['at']) }}</td>
                            <td><span class="badge {{ $r['condition'] === 'damaged' ? 'b-red' : 'b-green' }}">
                                {{ $r['condition'] === 'damaged' ? __('ops.rc_c_damaged') : __('ops.rc_c_good') }}</span></td>
                            <td class="num"><b>{{ $fm($r['qty']) }}</b></td>
                            <td class="num">{{ $fm2($r['total']) }}</td>
                            <td class="act">@include('partials._view', ['url' => route('ops.returns.show', $r['id'])])</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        <div data-empty style="display:none;text-align:center;color:var(--muted);padding:24px">{{ __('ops.rc_none') }}</div>
        <div class="formbar"><span class="formbar-sp"></span>
            <button class="btn" type="button" onclick="closeDlg('dlgD_rin')">{{ __('common.close') }}</button>
        </div>
    </div>
</dialog>

{{-- ═══ تصحيح إداري للعهدة (١٢ أغسطس ٢٠٢٦) — «التحميل اتسجّل غلط» ═══
     أرقام مستهدفة مش فروق: الأدمن بيكتب المحمَّل الصح، والسيرفر بيظبط
     العهدة والأرفف مع بعض (Custody::adjustTo) — الزيادة بأمر تجهيز
     حقيقي بيتسلّم فوراً، والنقص بيرجع لرف باتشه. --}}
@php
    if ($canAdjust) {
        // صف لكل صنف (البنود بالباتش بتتجمّع) — الأرضية = المتصرّف فعلاً
        $adjRows = $custody->items->groupBy('product_id')->map(fn ($g) => [
            'product' => $g->first()->product,
            'assigned' => (int) $g->sum('assigned'),
            'floor' => (int) $g->sum('sold') + (int) $g->sum('returned') + (int) $g->sum('transferred_out'),
            'gift' => (int) $g->sum('gift_assigned'),
            'gift_floor' => (int) $g->sum('gift_given'),
        ])->values();
    }
@endphp
{{-- ═══ تفريغ العربية (٢٨ أغسطس ٢٠٢٦) ═══
     الاختيار هنا مش تفصيلة شكلية — هو الفرق بين حركة مخزون سليمة
     وبين بضاعة بتختفي من السيستم. الافتراضي **ترجع المخزن** عن قصد --}}
@if ($custody && $custody->status === 'open' && $me?->role === 'admin')
    @php
        // المتبقي الفعلي في العربية — نفس معادلة remaining() للبنود كلها
        $clrUnits = $custody->items->sum(fn ($i) => $i->remaining()) + $custody->items->sum(fn ($i) => $i->giftLeft());
    @endphp
    <dialog id="dlgClearCustody">
        <form class="dlg" method="POST" action="{{ route('ops.rep.clear', $u) }}"
              style="width:min(560px,94vw)"
              onsubmit="return confirm(@js(__('field.clear_confirm')))">
            @csrf
            <h4>🧹 {{ __('field.clear_title') }} — {{ $u->displayName() }}</h4>

            <div class="alert warn" style="margin-bottom:12px">
                <span>⚠️</span><span>{{ __('field.clear_hint', ['units' => number_format($clrUnits)]) }}</span>
            </div>

            <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:12px">
                <label style="display:flex;gap:9px;align-items:flex-start;padding:10px 12px;
                              border:1px solid var(--border);border-radius:10px;cursor:pointer">
                    <input type="radio" name="mode" value="return" checked style="margin-top:3px">
                    <span>
                        <b>📦 {{ __('field.clear_mode_return') }}</b>
                        <div style="font-size:11.5px;color:var(--muted);margin-top:2px">
                            {{ __('field.clear_mode_return_hint') }}
                        </div>
                    </span>
                </label>
                <label style="display:flex;gap:9px;align-items:flex-start;padding:10px 12px;
                              border:1px solid #FCA5A5;background:#FEF2F2;border-radius:10px;cursor:pointer">
                    <input type="radio" name="mode" value="wipe" style="margin-top:3px">
                    <span>
                        <b style="color:#B91C1C">🧪 {{ __('field.clear_mode_wipe') }}</b>
                        <div style="font-size:11.5px;color:#B91C1C;margin-top:2px">
                            {{ __('field.clear_mode_wipe_hint') }}
                        </div>
                    </span>
                </label>
            </div>

            <div style="margin-bottom:12px">
                <label class="f">{{ __('field.custody_adjust_reason') }} <b class="req-star">*</b></label>
                <input type="text" name="reason" required maxlength="300" style="width:100%"
                       placeholder="{{ __('field.clear_reason_ph') }}">
            </div>

            <div style="display:flex;gap:8px;justify-content:flex-end">
                <button class="btn" type="button" onclick="closeDlg('dlgClearCustody')">{{ __('common.cancel') }}</button>
                <button class="btn gold" type="submit">🧹 {{ __('field.clear_apply') }}</button>
            </div>
        </form>
    </dialog>
@endif

@if ($canAdjust)
    <dialog id="dlgAdjust" class="wide">
        <form class="dlg" method="POST" action="{{ route('ops.rep.adjust', $u) }}"
              style="width:min(760px,96vw);max-height:88vh;overflow-y:auto">
            @csrf
            <h4>🛠️ {{ __('field.custody_adjust') }} — {{ $u->displayName() }}</h4>

            <div class="alert warn" style="margin-bottom:12px">
                <span>⚠️</span><span>{{ __('field.custody_adjust_hint') }}</span>
            </div>

            <div style="margin-bottom:12px">
                <label class="f">{{ __('field.custody_adjust_reason') }} <b class="req-star">*</b></label>
                <input type="text" name="reason" required maxlength="300" style="width:100%"
                       placeholder="{{ __('field.custody_adjust_reason_ph') }}">
            </div>

            {{-- منتقي إضافة صنف مش في العهدة — نفس الليست المشتركة --}}
            @php
                $adjCatalog = $products->map(fn ($p) => [
                    'id' => $p->id, 'code' => $p->code,
                    'name' => $p->displayName(), 'name_ar' => $p->name,
                    'name_en' => $p->name_en, 'image' => $p->imageSrc(),
                ])->values()->all();

                // ═══ أسعار كل الأصناف بقايمة المندوب (١٢/٨) — للقيمة اللايف ═══
                // عرض فقط: الديالوج بيوري «الرقم اللي بتكتبه = قيمة كام»
                // والسيرفر مابيستلمش منها حاجة. json_encode هنا في بلوك
                // بي‌اتش‌بي مش دايركتيف — قاعدة البليد المعروفة.
                $cadjPrices = $products->mapWithKeys(fn ($p) => [
                    $p->id => round(\App\Support\CustodyValue::priceIn($repList, $p), 2),
                ])->all();
                $cadjPricesJson = json_encode($cadjPrices, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
            @endphp
            <label class="f">{{ __('stock.pick_add_item') }}</label>
            @include('partials._item_picker', [
                'id' => 'cadj',
                'catalog' => $adjCatalog,
                'onPick' => 'custodyAdjAdd',
            ])

            <div class="tablewrap" style="margin-top:12px;max-height:46vh;overflow-y:auto;border:1px solid var(--border);border-radius:10px">
                <table>
                    <thead>
                        <tr>
                            <th style="text-align:start">{{ __('stock.item') }}</th>
                            <th>{{ __('field.custody_adjust_loaded') }}</th>
                            {{-- الأرضية معروضة قصاد كل صنف — الحارس في السيرفر برضه --}}
                            <th>{{ __('field.custody_adjust_floor') }}</th>
                            <th>{{ __('field.custody_adjust_new') }}</th>
                            <th>{{ __('field.custody_adjust_gift_new') }}</th>
                            {{-- القيمة لايف بقايمة المندوب — عرض فقط (١٢/٨) --}}
                            <th data-nosum>{{ __('field.handout_value') }}
                                <div style="font-size:9.5px;font-weight:600;color:var(--muted)">{{ $repList?->displayName() }}</div>
                            </th>
                        </tr>
                    </thead>
                    <tbody id="cadjRows">
                        @foreach ($adjRows as $r)
                            @php $p = $r['product']; @endphp
                            {{-- صنف اتمسح من الكتالوج؟ مفيش مفتاح نبعته — نتخطى --}}
                            @continue($p === null)
                            <tr data-pid="{{ $p->id }}">
                                <td style="text-align:start">
                                    <b>{{ $p->displayName() }}</b>
                                    <div style="font-size:10.5px;color:var(--muted)">{{ $p->code }}</div>
                                </td>
                                <td class="num">{{ $r['assigned'] }}
                                    @if ($r['gift'] > 0)
                                        <div style="font-size:10px;color:var(--muted)">🎁 {{ $r['gift'] }}</div>
                                    @endif
                                </td>
                                <td class="num" style="color:var(--muted)">{{ $r['floor'] }}
                                    @if ($r['gift_floor'] > 0)
                                        <div style="font-size:10px">🎁 {{ $r['gift_floor'] }}</div>
                                    @endif
                                </td>
                                <td>
                                    {{-- ⚠️ رسالة الأرضية بالعربي (٦/٩) — رسالة المتصفح
                                         الإنجليزي كانت بتبان «إيرور» مش مفهوم --}}
                                    <input type="number" name="assigned[{{ $p->id }}]" min="{{ $r['floor'] }}" step="1"
                                           value="{{ $r['assigned'] }}" style="width:92px"
                                           oninvalid="cadjMinMsg(this)"
                                           oninput="this.setCustomValidity(''); cadjSync({{ $p->id }})">
                                </td>
                                <td>
                                    <input type="number" name="gift[{{ $p->id }}]" min="{{ $r['gift_floor'] }}" step="1"
                                           value="{{ $r['gift'] }}" style="width:82px"
                                           oninvalid="cadjMinMsg(this)"
                                           oninput="this.setCustomValidity('')">
                                </td>
                                @php $adjPrice = (float) ($cadjPrices[$p->id] ?? 0); @endphp
                                <td class="num">
                                    <b id="cadjV{{ $p->id }}" dir="ltr">{{ number_format($r['assigned'] * $adjPrice, 2) }}</b>
                                    <div style="font-size:10px;color:var(--muted)" dir="ltr">× {{ number_format($adjPrice, 2) }}</div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- إجمالي القيمة الجديدة لايف — استرشادي، التصفية بالقطع --}}
            <div style="display:flex;justify-content:flex-end;align-items:center;gap:6px;margin-top:10px;font-size:12.5px;color:var(--muted)">
                {{ __('field.custody_adjust_total_value') }} ({{ $repList?->displayName() ?? '—' }}):
                <b id="cadjTotal" dir="ltr" style="color:var(--royal-blue, #12399B);font-size:14px">0.00</b>
            </div>

            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
                <button class="btn" type="button" onclick="closeDlg('dlgAdjust')">{{ __('common.cancel') }}</button>
                <button class="btn gold" type="submit">{{ __('common.save') }}</button>
            </div>
        </form>
    </dialog>
@endif

@endsection

@section('scripts')
<script>
(function () {
    'use strict';

    /* ═══ فتح مودال الدريل داون مفلتر على صنف واحد (أو الكل) ═══
       كل الصفوف موجودة في الصفحة أصلاً — الفلترة عرض بس، فمفيش
       نداء سيرفر ولا انتظار. */
    window.repDrill = function (kind, pid, title) {
        var dlg = document.getElementById('dlgD_' + kind);
        if (!dlg) { return; }

        var t = dlg.querySelector('[data-title]');
        if (t) { t.textContent = title || ''; }

        var total = 0;

        dlg.querySelectorAll('[data-sec]').forEach(function (sec) {
            var n = 0;

            sec.querySelectorAll('tr[data-pid]').forEach(function (row) {
                var show = (pid === 'all') || (row.getAttribute('data-pid') === String(pid));
                row.style.display = show ? '' : 'none';
                if (show) { n++; }
            });

            sec.style.display = n ? '' : 'none';
            total += n;
        });

        var empty = dlg.querySelector('[data-empty]');
        if (empty) { empty.style.display = total ? 'none' : ''; }

        openDlg('dlgD_' + kind);
    };
})();
</script>
@if ($canAdjust)
<script>
(function () {
    'use strict';

    // ═══ أسعار قايمة المندوب — القيمة لايف وانت بتكتب (١٢/٨) ═══
    // ⚠️ عرض فقط: السيرفر مابيستلمش أي سعر من الديالوج ده.
    const CADJ_PRICES = {!! $cadjPricesJson !!};

    const money = n => Number(n || 0).toLocaleString(undefined, {
        minimumFractionDigits: 2, maximumFractionDigits: 2
    });

    // رسالة الأرضية بالعربي (٦/٩) — بدل تولتيب المتصفح الإنجليزي:
    // الحد الأدنى = المتصرّف بمستندات (مباع + مرتجع + متحوّل)، ولو
    // العربية فيها أقل فده عجز مكانه التصفية مش هنا
    window.cadjMinMsg = function (el) {
        el.setCustomValidity(@js(__('field.custody_adjust_min_js')).replace(':floor', el.min));
    };

    // قيمة صف = المحمَّل الجديد × سعر قايمة المندوب — والإجمالي بعده
    window.cadjSync = function (id) {
        const q = document.querySelector('#cadjRows input[name="assigned[' + id + ']"]');
        const cell = document.getElementById('cadjV' + id);
        if (q && cell) {
            cell.textContent = money(Number(q.value || 0) * (CADJ_PRICES[id] || 0));
        }
        cadjTotal();
    };

    function cadjTotal() {
        let total = 0;
        document.querySelectorAll('#cadjRows input[name^="assigned"]').forEach(q => {
            const m = q.name.match(/\d+/);
            if (m) total += Number(q.value || 0) * (CADJ_PRICES[m[0]] || 0);
        });
        const el = document.getElementById('cadjTotal');
        if (el) el.textContent = money(total);
    }

    // صنف جديد من الليست — محمَّل حالي 0 وأرضية 0
    window.custodyAdjAdd = function (id) {
        const prod = (window.PICKER_CADJ || []).find(p => p.id === id);
        if (!prod) return;

        const existing = document.querySelector('#cadjRows tr[data-pid="' + id + '"]');
        if (existing) {
            const q = existing.querySelector('input[name^="assigned"]');
            if (q) { q.focus(); }
        } else {
            const tr = document.createElement('tr');
            tr.setAttribute('data-pid', id);
            tr.innerHTML =
                '<td style="text-align:start"><b>' + (prod.name || '') + '</b>' +
                '<div style="font-size:10.5px;color:var(--muted)">' + (prod.code || '') + '</div></td>' +
                '<td class="num">0</td>' +
                '<td class="num" style="color:var(--muted)">0</td>' +
                '<td><input type="number" name="assigned[' + id + ']" min="0" step="1" value="1" style="width:92px" oninput="cadjSync(' + id + ')"></td>' +
                '<td><input type="number" name="gift[' + id + ']" min="0" step="1" value="0" style="width:82px"></td>' +
                '<td class="num"><b id="cadjV' + id + '" dir="ltr">' + money(CADJ_PRICES[id] || 0) + '</b>' +
                '<div style="font-size:10px;color:var(--muted)" dir="ltr">× ' + money(CADJ_PRICES[id] || 0) + '</div></td>';
            document.getElementById('cadjRows').appendChild(tr);
        }
        cadjTotal();
        window.cadjPickerReset();
    };

    cadjTotal();

    // جاي من بورد العربيات بزرار «تعديل العهدة»؟ افتح الديالوج على طول
    if (new URLSearchParams(location.search).get('adjust') === '1') {
        openDlg('dlgAdjust');
    }
})();
</script>
@endif
@endsection
