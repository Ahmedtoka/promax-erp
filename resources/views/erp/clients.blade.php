@extends('layouts.system')

@section('title', __('client.clients'))

@php
    use App\Models\Client;
    $fmt = fn ($n) => number_format((float) $n);
    $manager = auth()->user()->isManager();
@endphp

@section('actions')
    @if ($manager)
        {{-- ⚠️ صفحة مستقلة مش مودال — الفلو بقى 3 مراحل وفيه رفع ملف،
             والمودال بارتفاع ثابت كان بيخبّي نص الحقول تحت الشاشة. --}}
        @if (\App\Support\Access::action(auth()->user(), 'act.clients.create'))<a class="btn gold" href="{{ route('erp.clients.new') }}">+ {{ __('client.new_client') }}</a>@endif
    @endif
@endsection

@section('content')

{{-- KPIs بمعنى (2026-08-05): كام عميل، كام سلسلة، كام في كل قناة،
     ومين عليه فلوس ومين ليه — وكل كارت فلتر بضغطة.
     (٢٢/٩) الكروت اتقسمت تلات مجموعات بعنوان: عدد المحفظة · الأرصدة دلوقتي · أرقام الفترة —
     عشان نطاق كل رقم يبان، وكل كارت تحته معادلته بأرقامه. --}}
@php
    $allN = array_sum($statusCounts);
    $pctOf = fn ($a, $b) => (float) $b > 0 ? number_format((float) $a / (float) $b * 100, 1) : '0.0';
    // الصافي من الرقمين المقرّبين اللي على الكارتين — عشان المعادلة المكتوبة تقفل بالجنيه (٢٢/٩)
    $netRecv = round($kpi['debt_sum']) - round($kpi['credit_sum']);
    $kHead = 'font-size:12px;font-weight:700;color:var(--muted);margin:2px 2px 8px';
@endphp
<div style="{{ $kHead }}">👥 {{ __('uia.kgrp_portfolio') }}</div>
<div class="kpis">
    <a class="kpi" href="{{ route('erp.clients') }}">
        <div class="lbl">👥 {{ __('client.clients') }}</div>
        <div class="val">{{ $fmt($allN) }}</div>
        <div class="sub2"><span dir="ltr">{{ $fmt($allN) }} =</span>
            {{ __('client.status_active') }} <b>{{ $fmt($statusCounts['active'] ?? 0) }}</b>
            + {{ __('client.status_waiting') }} <b>{{ $fmt($statusCounts['pending'] ?? 0) }}</b>
            @if (($statusCounts['rejected'] ?? 0) > 0)+ {{ __('client.status_rejected') }} <b>{{ $fmt($statusCounts['rejected']) }}</b>@endif</div>
    </a>
    <a class="kpi" href="{{ route('erp.groups') }}">
        <div class="lbl">🏬 {{ __('nav.chains') }}</div>
        <div class="val">{{ $fmt($kpi['chains']) }}</div>
        <div class="sub2">{{ __('client.chains_hint') }}</div>
    </a>
    {{-- الرقم الشامل الأول وبعدين الفرعي (قرار المالك 2026-08-06):
         الكبير = الكيانات (سلاسل + مستقلين) — أونلاين فيها رابت بس
         يبقى 1، والفرعي = الفروع (13). الفرع مش عميل تجاري مستقل. --}}
    @foreach ($channels as $ch)
        @php
            $chainsN = $chainsByChannel[$ch->id] ?? 0;
            $indepN = $indepByChannel[$ch->id] ?? 0;
            $entities = $chainsN + $indepN;
            $branchesN = $channelCounts[$ch->id] ?? 0;
        @endphp
        <a @class(['kpi', 'on' => (int) ($filters['channel'] ?? 0) === $ch->id])
           href="{{ route('erp.clients', ['channel' => (int) ($filters['channel'] ?? 0) === $ch->id ? null : $ch->id]) }}">
            <div class="lbl">🎯 {{ $ch->displayName() }}</div>
            <div class="val">{{ $fmt($entities) }}</div>
            <div class="sub2">{{ __('uia.eq_channel_entities', ['t' => $fmt($entities), 'c' => $fmt($chainsN), 'i' => $fmt($indepN)]) }}
                · {{ __('client.branch_countable', ['count' => $branchesN]) }}</div>
        </a>
    @endforeach
    {{-- الحالة التجارية بضغطة (١٥ أغسطس ٢٠٢٦): مين متعاقد، مين واخد
         خصم، ومين مالوش مدير حساب — تلاتتهم كانوا مدفونين في الأعمدة --}}
    <a @class(['kpi', 'on' => ($filters['contract'] ?? '') === 'yes'])
       href="{{ route('erp.clients', ['contract' => ($filters['contract'] ?? '') === 'yes' ? null : 'yes']) }}">
        <div class="lbl">📄 {{ __('client.kpi_live_contract') }}</div>
        <div class="val">{{ $fmt($kpi['live_contract']) }}</div>
        <div class="sub2">{{ __('uia.eq_live_contract') }} <span dir="ltr">{{ $pctOf($kpi['live_contract'], $allN) }}% = {{ $fmt($kpi['live_contract']) }} ÷ {{ $fmt($allN) }}</span></div>
    </a>
    <a @class(['kpi', 'on' => ($filters['disc'] ?? '') === 'yes'])
       href="{{ route('erp.clients', ['disc' => ($filters['disc'] ?? '') === 'yes' ? null : 'yes']) }}">
        <div class="lbl">🏷️ {{ __('client.kpi_discounted') }}</div>
        <div class="val">{{ $fmt($kpi['discounted']) }}</div>
        <div class="sub2">{{ __('uia.eq_discounted') }} <span dir="ltr">{{ $pctOf($kpi['discounted'], $allN) }}% = {{ $fmt($kpi['discounted']) }} ÷ {{ $fmt($allN) }}</span></div>
    </a>
    <a @class(['kpi', 'on' => ($filters['manager'] ?? '') === 'none'])
       href="{{ route('erp.clients', ['manager' => ($filters['manager'] ?? '') === 'none' ? null : 'none']) }}">
        <div class="lbl">🙍 {{ __('client.kpi_no_manager') }}</div>
        <div class="val {{ $kpi['no_manager'] > 0 ? 'mid' : '' }}">{{ $fmt($kpi['no_manager']) }}</div>
        <div class="sub2">{{ __('uia.eq_no_manager') }} <span dir="ltr">{{ $pctOf($kpi['no_manager'], $allN) }}% = {{ $fmt($kpi['no_manager']) }} ÷ {{ $fmt($allN) }}</span></div>
    </a>
</div>

{{-- الكارتين دول بقوا فلتر «حالة الرصيد» مرتّب بالرصيد (٢٢/٩) — الرقم من غير قايمته مالوش لازمة --}}
<div style="{{ $kHead }}">💰 {{ __('uia.kgrp_balances') }}</div>
<div class="kpis">
    <a @class(['kpi', 'on' => ($filters['bal'] ?? '') === 'debt'])
       href="{{ route('erp.clients', ($filters['bal'] ?? '') === 'debt' ? [] : ['bal' => 'debt', 'sort' => 'balance', 'dir' => 'desc']) }}">
        <div class="lbl">💸 {{ __('client.owe_us') }}</div>
        <div class="val neg">{{ $fmt($kpi['debt_sum']) }}</div>
        <div class="sub2">{{ __('uia.eq_debt', ['n' => $fmt($kpi['debt_n'])]) }}</div>
    </a>
    <a @class(['kpi', 'on' => ($filters['bal'] ?? '') === 'credit'])
       href="{{ route('erp.clients', ($filters['bal'] ?? '') === 'credit' ? [] : ['bal' => 'credit', 'sort' => 'balance', 'dir' => 'asc']) }}">
        <div class="lbl">💰 {{ __('client.credit_balance') }}</div>
        <div class="val pos">{{ $fmt($kpi['credit_sum']) }}</div>
        <div class="sub2">{{ __('uia.eq_credit', ['n' => $fmt($kpi['credit_n'])]) }}</div>
    </a>
    {{-- الصافي (٢٢/٩): اللي لينا فعلاً بعد ما نشيل اللي علينا — من نفس رقمي الكارتين --}}
    <a class="kpi" href="{{ route('erp.clients', ['sort' => 'balance', 'dir' => 'desc']) }}#clientsTable">
        <div class="lbl">🧮 {{ __('uia.net_receivable') }}</div>
        <div class="val {{ $netRecv > 0 ? 'neg' : 'pos' }}">{{ $fmt($netRecv) }}</div>
        <div class="sub2"><span dir="ltr">{{ $fmt($netRecv) }} = {{ $fmt($kpi['debt_sum']) }} − {{ $fmt($kpi['credit_sum']) }}</span> {{ __('uia.eq_net_receivable') }}</div>
    </a>
</div>

{{-- كروت المبيعات (٢٠ سبتمبر ٢٠٢٦): صف مستقل عن كروت العدّ اللي فوق —
     ده بيتغيّر مع كل فلتر ومع الفترة، ودول ثابتين. نطاق واحد في الصف. --}}
@php
    $periodOn = ! $range->isOpen();
    $periodLabel = $periodOn ? trim(($range->fromValue() ?: '…').' → '.($range->toValue() ?: '…')) : __('client.period_all');
    // من الرقمين المقرّبين المعروضين — عشان المعادلة تحت الكارت تقفل بالجنيه (٢٢/٩)
    $net = round((float) $salesKpi->s) - round((float) $salesKpi->r);
    // كروت المبيعات بترتّب نفس القايمة (بنفس الفلاتر) على الرقم اللي في الكارت (٢٢/٩)
    $kSort = fn (string $col, array $extra = []) => request()->fullUrlWithQuery(
        ['sort' => $col, 'dir' => 'desc', 'page' => null, 'export' => null] + $extra);
@endphp
<div style="{{ $kHead }}">🧾 {{ __('uia.kgrp_period') }} · <span class="num" dir="ltr">{{ $periodLabel }}</span></div>
<div class="kpis">
    <a @class(['kpi', 'on' => $periodOn]) href="{{ $kSort('purchases') }}#clientsTable">
        <div class="lbl">🧾 {{ __('client.sales_kpi_sales') }}</div>
        <div class="val num">{{ $fmt($salesKpi->s) }}</div>
        <div class="sub2">{{ __('uia.eq_period_sales') }}@if ($periodOn) • <b class="num">{{ $fmt($salesKpi->d) }}</b> {{ __('client.sales_kpi_docs') }} @endif</div>
    </a>
    <a @class(['kpi', 'on' => ($filters['flag'] ?? '') === 'buyers'])
       href="{{ $kSort('purchases', ['flag' => ($filters['flag'] ?? '') === 'buyers' ? null : 'buyers']) }}#clientsTable">
        <div class="lbl">🛒 {{ __('client.sales_kpi_buyers') }}</div>
        <div class="val num">{{ $fmt($salesKpi->buyers) }}</div>
        <div class="sub2">{{ __('uia.eq_buyers') }} <span dir="ltr">{{ $pctOf($salesKpi->buyers, $salesKpi->n) }}% = {{ $fmt($salesKpi->buyers) }} ÷ {{ $fmt($salesKpi->n) }}</span></div>
    </a>
    <a class="kpi" href="{{ $kSort('returns') }}#clientsTable">
        <div class="lbl">↩️ {{ __('client.sales_kpi_returns') }}</div>
        <div class="val num mid">{{ $fmt($salesKpi->r) }}</div>
        <div class="sub2">{{ __('uia.eq_return_rate') }} <span dir="ltr">{{ $pctOf($salesKpi->r, $salesKpi->s) }}% = {{ $fmt($salesKpi->r) }} ÷ {{ $fmt($salesKpi->s) }}</span></div>
    </a>
    <a class="kpi" href="{{ $kSort('purchases') }}#clientsTable">
        <div class="lbl">✅ {{ __('client.sales_kpi_net') }}</div>
        <div class="val num {{ $net < 0 ? 'neg' : '' }}">{{ $fmt($net) }}</div>
        <div class="sub2"><span dir="ltr">{{ $fmt($net) }} = {{ $fmt($salesKpi->s) }} − {{ $fmt($salesKpi->r) }}</span> {{ __('uia.eq_net_words') }}</div>
    </a>
    <a class="kpi" href="{{ $kSort('collections') }}#clientsTable">
        <div class="lbl">💵 {{ __('client.sales_kpi_collected') }}</div>
        <div class="val num pos">{{ $fmt($salesKpi->c) }}</div>
        <div class="sub2">{{ __('uia.eq_coll_rate') }} <span dir="ltr">{{ $pctOf($salesKpi->c, $salesKpi->s) }}% = {{ $fmt($salesKpi->c) }} ÷ {{ $fmt($salesKpi->s) }}</span></div>
    </a>
</div>

<div class="card" id="clientsTable">
    {{-- ملحوظة: الفلاتر هنا لازم تطابق اللي ErpController::clients() بيقراه بالظبط
         الترتيب (2026-08-05): بحث ← الحالة ← القناة ← القسم ← التصنيف
         ← المحافظة ← الزون ← العقود --}}
    <form class="searchbar" method="GET">
        {{-- كل خانة بعنوانها فوقها، والفترة آخر حاجة قبل الزراير (٢٢/٩) --}}
        <label class="fl grow"><span>{{ __('ui.l_search') }}</span>
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="🔍 {{ __('client.search_client') }}"></label>
        <label class="fl"><span>{{ __('ui.l_status') }}</span>
        <select name="status">
            <option value="">{{ __('client.status_all') }} ({{ array_sum($statusCounts) }})</option>
            <option value="active" @selected(($filters['status'] ?? '') === 'active')>{{ __('client.status_active') }} ({{ $statusCounts['active'] ?? 0 }})</option>
            <option value="pending" @selected(($filters['status'] ?? '') === 'pending')>{{ __('client.status_waiting') }} ({{ $statusCounts['pending'] ?? 0 }})</option>
        </select></label>
        <label class="fl"><span>{{ __('ui.l_channel') }}</span>
        <select name="channel">
            <option value="">{{ __('client.all_channels') }}</option>
            @foreach ($channels as $ch)
                <option value="{{ $ch->id }}" @selected((int) ($filters['channel'] ?? 0) === $ch->id)>
                    {{ $ch->displayName() }} ({{ $channelCounts[$ch->id] ?? 0 }})
                </option>
            @endforeach
        </select></label>
        <label class="fl"><span>{{ __('uia.l_segment') }}</span>
        <select name="sub">
            <option value="">{{ __('client.all_segments') }}</option>
            @foreach (\App\Models\Channel::SUB_CHANNELS as $k => $lbl)
                <option value="{{ $k }}" @selected(($filters['sub'] ?? '') === $k)>{{ __('enums.sub_channel.'.$k) }}</option>
            @endforeach
        </select></label>
        <label class="fl"><span>{{ __('ui.l_category') }}</span>
        <select name="cat">
            <option value="">{{ __('client.all_categories') }}</option>
            @foreach (Client::CATEGORIES as $k => $v)
                <option value="{{ $k }}" @selected(($filters['cat'] ?? '') === $k)>{{ __('enums.category.'.$k) }}</option>
            @endforeach
        </select></label>
        <label class="fl"><span>{{ __('ui.l_gov') }}</span>
        <select name="gov">
            <option value="">{{ __('ui.all_of', ['x' => __('uia.x_govs')]) }}</option>
            @foreach (\App\Support\Governorates::options() as $gk => $gLabel)
                <option value="{{ $gk }}" @selected(($filters['gov'] ?? '') === $gk)>{{ $gLabel }}</option>
            @endforeach
        </select></label>
        <label class="fl"><span>{{ __('ui.l_zone') }}</span>
        @include('partials._zone_select', [
            'zones' => $zones,
            'name' => 'zone',
            'selected' => $filters['zone'] ?? null,
            'placeholder' => __('client.all_zones'),
        ])</label>
        {{-- ⚠️ «منتهي» أوبشن مستقل — قبل كده كان مندمج في «بدون عقد»
             فالعميل اللي محتاج تجديد بيضيع وسط اللي عمرهم ما تعاقدوا --}}
        <label class="fl"><span>{{ __('ui.l_contract') }}</span>
        <select name="contract">
            <option value="">{{ __('client.contracts_all') }}</option>
            <option value="yes" @selected(($filters['contract'] ?? '') === 'yes')>{{ __('client.contract_active') }}</option>
            <option value="expired" @selected(($filters['contract'] ?? '') === 'expired')>{{ __('client.contract_expired') }}</option>
            <option value="no" @selected(($filters['contract'] ?? '') === 'no')>{{ __('client.without_contract') }}</option>
        </select></label>
        <label class="fl"><span>{{ __('client.discount') }}</span>
        <select name="disc">
            <option value="">{{ __('client.discount_all') }}</option>
            <option value="yes" @selected(($filters['disc'] ?? '') === 'yes')>{{ __('client.discount_has') }}</option>
            <option value="no" @selected(($filters['disc'] ?? '') === 'no')>{{ __('client.discount_none') }}</option>
            <option value="custom" @selected(($filters['disc'] ?? '') === 'custom')>{{ __('client.discount_custom_only') }}</option>
        </select></label>
        {{-- ⚠️ المدير بيشوف نفسه بس في القايمة دي — الكنترولر بيبنيها --}}
        <label class="fl"><span>{{ __('client.channel_manager') }}</span>
        <select name="manager">
            <option value="">{{ __('client.managers_all') }}</option>
            <option value="none" @selected(($filters['manager'] ?? '') === 'none')>{{ __('client.no_manager') }}</option>
            @foreach ($managerOptions as $m)
                <option value="{{ $m->id }}" @selected(($filters['manager'] ?? '') === (string) $m->id)>{{ $m->displayName() }}</option>
            @endforeach
        </select></label>
        <label class="fl"><span>{{ __('uia.l_followup') }}</span>
        <select name="flag">
            <option value="">{{ __('client.assignment_all') }}</option>
            <option value="norep" @selected(($filters['flag'] ?? '') === 'norep')>{{ __('client.no_rep') }}</option>
            <option value="buyers" @selected(($filters['flag'] ?? '') === 'buyers')>{{ __('uia.flag_buyers') }}</option>
            <option value="indep" @selected(($filters['flag'] ?? '') === 'indep')>{{ __('client.independent_clients') }}</option>
        </select></label>
        <label class="fl"><span>{{ __('uia.l_balance_state') }}</span>
        <select name="bal">
            <option value="">{{ __('uia.bal_all') }}</option>
            <option value="debt" @selected(($filters['bal'] ?? '') === 'debt')>{{ __('client.owe_us') }}</option>
            <option value="credit" @selected(($filters['bal'] ?? '') === 'credit')>{{ __('client.credit_balance') }}</option>
        </select></label>
        {{-- ريفرنس التحصيل (رقم التحويل/الشيك) → العملاء اللي عليهم قيد بالريفرنس ده (٢٢/٩) --}}
        <label class="fl"><span>{{ __('uia.l_pay_ref') }}</span>
            <input type="text" name="ref" value="{{ $filters['ref'] ?? '' }}" dir="ltr" placeholder="{{ __('uia.pay_ref_ph') }}"></label>
        {{-- الفترة: بتغيّر أرقام المشتريات/التحصيل/المرتجعات والكروت والتصدير --}}
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue()])
        <button class="btn gold" type="submit">{{ __('common.search') }}</button>
        <a class="btn" href="{{ route('erp.clients') }}">{{ __('common.clear') }}</a>
        <a class="btn green" href="{{ request()->fullUrlWithQuery(['export' => 'summary', 'page' => null]) }}">⬇ {{ __('client.export_sales_summary') }}</a>
        <a class="btn" href="{{ request()->fullUrlWithQuery(['export' => 'full', 'page' => null]) }}">⬇ {{ __('client.export_sales_full') }}</a>
        <span class="badge b-gray">{{ __('client.client_countable', ['count' => $clients->total()]) }}</span>
    </form>

    @if ($periodOn)
        <div class="alert info" style="margin:10px 0">{{ __('client.period_note', ['period' => $periodLabel]) }}</div>
    @endif
    @if (($filters['ref'] ?? '') !== '')
        <div class="alert info" style="margin:10px 0">🔎 {{ __('uia.ref_banner', ['ref' => $filters['ref']]) }}</div>
    @endif
    @php
        // مع فلتر الريفرنس، كارت العميل بيفتح على الكشف متفلتر بنفس الريفرنس
        $showUrl = fn ($c) => ($filters['ref'] ?? '') !== ''
            ? route('erp.clients.show', ['client' => $c, 'ref' => $filters['ref']]).'#statement'
            : route('erp.clients.show', $c);
    @endphp

    {{-- الهيدر ثابت — الجدول طويل والأعمدة بتضيع وانت نازل --}}
    <div class="tablewrap" style="max-height:65vh;overflow-y:auto">
        <table>
            {{-- سورت من السيرفر (2026-08-06): العمود لينك بيحافظ على كل
                 الفلاتر، وأول ضغطة على الأرقام تنازلي وبعدين بتتقلب --}}
            @php
                // ⚠️⚠️ **العمود الفاضي جنب الخصم** (بلاغ المالك ١٥/٨/٢٠٢٦):
                // العمود المرتَّب حالياً كان بياخد `color:var(--primary)`،
                // وترويسة الجدول نفسها `background:var(--primary)` (قرار
                // ٨/٨) — يعني **أزرق ملكي على أزرق ملكي**. والافتراضي
                // `sort=purchases`، فعمود «المشتريات» كان بيبان **فاضي
                // خالص** على كل فتحة للصفحة، هو والسهم.
                // الأصفر هو لون الـactive على الغامق في دليل الهوية.
                $thSort = function ($key, $label, $numericDefault = true) use ($sort, $dir) {
                    $active = $sort === $key;
                    $nextDir = $active ? ($dir === 'desc' ? 'asc' : 'desc') : ($numericDefault ? 'desc' : 'asc');
                    $url = request()->fullUrlWithQuery(['sort' => $key, 'dir' => $nextDir, 'page' => null]);
                    $arrow = $active ? ($dir === 'desc' ? ' ▼' : ' ▲') : '';

                    return '<a href="'.$url.'" style="color:'.($active ? '#FFF927' : 'inherit').';text-decoration:none;white-space:nowrap">'
                        .e($label).$arrow.'</a>';
                };
            @endphp
            <thead>
            <tr>
                <th>{!! $thSort('name', __('client.client'), false) !!}</th>
                <th>{!! $thSort('status', __('common.status'), false) !!}</th>
                <th>{{ __('client.channel') }}</th><th>{{ __('client.zone') }}</th>
                {{-- ⚠️ `data-nosum` — العمود ده صور وأسماء، ومجموعه
                     في فوتر الجدول العام مالوش أي معنى --}}
                <th data-nosum>{{ __('client.channel_manager') }}</th>
                <th>{!! $thSort('category', __('client.category'), false) !!}</th>
                <th>{{ __('client.price_list') }}</th><th>{{ __('client.contract') }}</th>
                <th class="num" data-nosum>{!! $thSort('discount', __('client.discount')) !!}</th>
                <th class="num">{!! $thSort('purchases', __('client.purchases')) !!}</th>
                <th class="num">{!! $thSort('collections', __('client.collected')) !!}</th>
                <th class="num">{!! $thSort('returns', __('client.returns')) !!}</th>
                <th class="num">{!! $thSort('balance', __('client.balance')) !!}</th>
                <th class="num" data-nosum>{{ __('client.collection_rate') }}</th>
                <th class="num" data-nosum>{!! $thSort('last_payment_at', __('client.last_payment')) !!}</th>
                @if ($manager)<th></th>@endif
            </tr>
            </thead>
            <tbody>
            @forelse ($clients as $c)
                <tr class="clickable" onclick="location.href='{{ $showUrl($c) }}'">
                    <td><a href="{{ $showUrl($c) }}" onclick="event.stopPropagation()"><b>{{ $c->fullName() }}</b></a><br><span style="font-size:10.5px;color:var(--muted)">{{ $c->code }}</span></td>
                    <td>
                        @if ($c->status === 'active')
                            <span class="badge b-green">{{ __('client.status_active') }}</span>
                        @elseif ($c->status === 'rejected')
                            <span class="badge b-red">{{ __('client.status_rejected') }}</span>
                        @else
                            <span class="badge b-orange">{{ __('client.status_waiting') }}</span>
                        @endif
                    </td>
                    <td>
                        @if ($c->channel)
                            <span class="badge {{ $c->channel->badgeClass() }}">{{ $c->channel->displayName() }}</span>
                            @if ($c->sub_channel)
                                <br><span style="font-size:10px;color:var(--muted)">{{ $c->subChannelLabel() }}</span>
                            @endif
                        @else — @endif
                    </td>
                    <td style="color:var(--muted)">{{ $c->zone?->displayName() ?? '—' }}</td>
                    {{-- مدير القناة — المسؤول التجاري عن الحساب، غير
                         المندوب اللي بيتغيّر مع خط السير --}}
                    <td>
                        @if ($c->manager)
                            <span style="display:inline-flex;align-items:center;gap:6px">
                                @include('partials._avatar', ['u' => $c->manager, 'size' => 24])
                                <a href="{{ route('ops.rep', $c->manager) }}" onclick="event.stopPropagation()" style="font-size:12px">{{ $c->manager->displayName() }}</a>
                            </span>
                        @else
                            <span style="color:var(--muted)">—</span>
                        @endif
                    </td>
                    <td><span class="badge {{ $c->categoryClass() }}">{{ $c->categoryLabel() }}</span></td>
                    <td>
                        <span class="badge {{ $c->priceList() === 'new' ? 'b-blue' : 'b-gray' }}">{{ $c->priceListLabel() }}</span>
                    </td>
                    {{-- ⚠️ **«منتهي» ≠ «بدون عقد»** (بلاغ المالك ١٥/٨).
                         `liveContract()` بترجّع null للاتنين، فالعميل
                         اللي عقده خلص كان بيبان زي اللي عمره ما تعاقد
                         ومحدش بياخد باله من التجديد. `contractState()`
                         بتفرّق، و`anyContract()` بتجيب الصف المنتهي. --}}
                    <td>
                        @php
                            $state = $c->contractState();
                            $ct = $state === 'live' ? $c->liveContract() : $c->anyContract();
                        @endphp
                        @if ($state === 'live')
                            <span class="badge {{ $ct->statusClass() }}">{{ $ct->statusLabel() }}</span>
                            <br><span style="font-size:10px;color:var(--muted)">
                                {{-- ⚠️ **مسافة بين `@endif` واللي بعده إجبارية** (إصلاح ١٥/٨):
                                     ريجيكس بليد بيبدأ بـ`\B@` — يعني الدايركتيف اللي
                                     قبله حرف مابيتشافش. `@endif@if(...)` كان بيترجم
                                     الـ`@endif` بس ويسيب الـ`@if` نص عادي، فالـ`@endif`
                                     بتاعه يتحسب زيادة و`@elseif` اللي تحت تبقى يتيمة —
                                     «syntax error, unexpected token elseif» على اللايف. --}}
                                <a href="{{ route('erp.contracts.show', $ct) }}" onclick="event.stopPropagation()">{{ $ct->number }}</a> @if ($ct->ends_at) · {{ $ct->ends_at->format('Y-m-d') }} @endif
                                @if ($ct->group_id) · {{ __('client.from_chain') }} @endif
                            </span>
                        @elseif ($state === 'expired')
                            <span class="badge b-red">{{ __('client.contract_expired') }}</span>
                            <br><span style="font-size:10px;color:var(--muted)">
                                <a href="{{ route('erp.contracts.show', $ct) }}" onclick="event.stopPropagation()">{{ $ct->number }}</a> @if ($ct->ends_at) · {{ $ct->ends_at->format('Y-m-d') }} @endif
                            </span>
                        @elseif ($state === 'inactive')
                            <span class="badge b-orange">{{ __('client.contract_inactive') }}</span>
                            <br><a href="{{ route('erp.contracts.show', $ct) }}" onclick="event.stopPropagation()" style="font-size:10px">{{ $ct->number }}</a>
                        @else
                            <span class="badge b-gray">{{ __('client.no_contract') }}</span>
                        @endif
                    </td>
                    {{-- الخصم = النسبة + **مصدرها**. الاتنين من
                         `effectiveDiscount()`/`discountSource()` — ممنوع
                         أي حساب هنا (دوكترين التسعير). --}}
                    <td class="num" data-nosum>
                        @php
                            $src = $c->discountSourceKey();
                            $srcClass = ['contract' => 'b-purple', 'custom_discount' => 'b-blue'][$src] ?? 'b-gray';
                        @endphp
                        <b>{{ number_format($c->effectiveDiscount() * 100, 1) }}%</b>
                        <br><span class="badge {{ $srcClass }}" style="font-size:9.5px">{{ $c->discountSource() }}</span>
                    </td>
                    <td class="num">{{ $fmt($c->p_sales) }}</td>
                    <td class="num pos">{{ $fmt($c->p_coll) }}</td>
                    <td class="num mid">{{ $fmt($c->p_ret) }}</td>
                    <td class="num {{ $c->balance > 0 ? 'neg' : 'pos' }}">{{ $fmt($c->balance) }}</td>
                    {{-- مع الفترة: نسبة تحصيل الفترة نفسها، مش المجمّعة جنب أرقام فترة --}}
                    <td class="num">{{ number_format(($periodOn ? ((float) $c->p_sales > 0 ? (float) $c->p_coll / (float) $c->p_sales : 0) : $c->collectionRate()) * 100, 1) }}%</td>
                    <td class="num">{{ $c->last_payment_at?->format('Y-m-d') ?? '—' }}</td>
                    @if ($manager)
                        {{-- ⚠️ `stopPropagation` — الصف كله كليكابل، ومن غيرها
                             الضغط على الزرار بيروح لكارت العميل مش للاستنساخ. --}}
                        <td onclick="event.stopPropagation()">
                            <a class="btn sm" href="{{ route('erp.clients.clone', $c) }}"
                               title="{{ __('client.clone_client') }}">⧉</a>
                        </td>
                    @endif
                </tr>
            @empty
                {{-- ⚠️ زوّدت عمود؟ حدّث الرقمين دول (١٥/٨: +مدير القناة) --}}
                <tr><td colspan="{{ $manager ? 16 : 15 }}" style="text-align:center;color:var(--muted);padding:24px">{{ __('client.no_clients') }}</td></tr>
            @endforelse
            </tbody>
            {{-- القايمة صفحات — الإجمالي من السيرفر على **كل** النتيجة المفلترة
                 (نفس كويري الكروت والتصدير) مش على الأربعين المعروضين (٢٢/٩) --}}
            @if ($clients->total() > 0)
                <tfoot><tr>
                    <td colspan="9"><b>Σ {{ __('common.total') }}</b> — {{ __('client.client_countable', ['count' => $clients->total()]) }}</td>
                    <td class="num"><b>{{ $fmt($salesKpi->s) }}</b></td>
                    <td class="num pos"><b>{{ $fmt($salesKpi->c) }}</b></td>
                    <td class="num mid"><b>{{ $fmt($salesKpi->r) }}</b></td>
                    <td class="num"><b>{{ $fmt($salesKpi->b) }}</b></td>
                    <td class="num">{{ number_format(((float) $salesKpi->s > 0 ? (float) $salesKpi->c / (float) $salesKpi->s : 0) * 100, 1) }}%</td>
                    <td colspan="{{ $manager ? 2 : 1 }}"></td>
                </tr></tfoot>
            @endif
        </table>
    </div>

    @include('partials._pagination', ['p' => $clients])
</div>


@endsection
