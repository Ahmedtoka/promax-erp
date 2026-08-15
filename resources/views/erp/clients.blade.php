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
     ومين عليه فلوس ومين ليه — وكل كارت فلتر بضغطة --}}
<div class="kpis">
    <a class="kpi" href="{{ route('erp.clients') }}" style="text-decoration:none;color:inherit">
        <div class="lbl">👥 {{ __('client.clients') }}</div>
        <div class="val">{{ $fmt(array_sum($statusCounts)) }}</div>
        <div class="sub2">{{ __('client.status_active') }} <b>{{ $fmt($statusCounts['active'] ?? 0) }}</b>
            • {{ __('client.status_waiting') }} <b>{{ $fmt($statusCounts['pending'] ?? 0) }}</b></div>
    </a>
    <a class="kpi" href="{{ route('erp.groups') }}" style="text-decoration:none;color:inherit">
        <div class="lbl">🏬 {{ __('nav.chains') }}</div>
        <div class="val">{{ $fmt($kpi['chains']) }}</div>
        <div class="sub2">{{ __('client.chains_hint') }}</div>
    </a>
    {{-- الرقم الشامل الأول وبعدين الفرعي (قرار المالك 2026-08-06):
         الكبير = الكيانات (سلاسل + مستقلين) — أونلاين فيها رابت بس
         يبقى 1، والفرعي = الفروع (13). الفرع مش عميل تجاري مستقل. --}}
    @foreach ($channels as $ch)
        @php
            $entities = ($chainsByChannel[$ch->id] ?? 0) + ($indepByChannel[$ch->id] ?? 0);
            $branchesN = $channelCounts[$ch->id] ?? 0;
        @endphp
        <a class="kpi" style="text-decoration:none;color:inherit;{{ (int) ($filters['channel'] ?? 0) === $ch->id ? 'outline:2px solid var(--royal-blue)' : '' }}"
           href="{{ route('erp.clients', ['channel' => (int) ($filters['channel'] ?? 0) === $ch->id ? null : $ch->id]) }}">
            <div class="lbl">🎯 {{ $ch->displayName() }}</div>
            <div class="val">{{ $fmt($entities) }}</div>
            <div class="sub2">{{ __('client.branch_countable', ['count' => $branchesN]) }} • {{ __('client.tap_to_filter') }}</div>
        </a>
    @endforeach
    <div class="kpi">
        <div class="lbl">💸 {{ __('client.owe_us') }}</div>
        <div class="val neg">{{ $fmt($kpi['debt_sum']) }}</div>
        <div class="sub2">{{ __('client.client_countable', ['count' => $kpi['debt_n']]) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">💰 {{ __('client.credit_balance') }}</div>
        <div class="val pos">{{ $fmt($kpi['credit_sum']) }}</div>
        <div class="sub2">{{ __('client.client_countable', ['count' => $kpi['credit_n']]) }}</div>
    </div>
    {{-- الحالة التجارية بضغطة (١٥ أغسطس ٢٠٢٦): مين متعاقد، مين واخد
         خصم، ومين مالوش مدير حساب — تلاتتهم كانوا مدفونين في الأعمدة --}}
    <a class="kpi" style="text-decoration:none;color:inherit;{{ ($filters['contract'] ?? '') === 'yes' ? 'outline:2px solid var(--royal-blue)' : '' }}"
       href="{{ route('erp.clients', ['contract' => ($filters['contract'] ?? '') === 'yes' ? null : 'yes']) }}">
        <div class="lbl">📄 {{ __('client.kpi_live_contract') }}</div>
        <div class="val">{{ $fmt($kpi['live_contract']) }}</div>
        <div class="sub2">{{ __('client.tap_to_filter') }}</div>
    </a>
    <a class="kpi" style="text-decoration:none;color:inherit;{{ ($filters['disc'] ?? '') === 'yes' ? 'outline:2px solid var(--royal-blue)' : '' }}"
       href="{{ route('erp.clients', ['disc' => ($filters['disc'] ?? '') === 'yes' ? null : 'yes']) }}">
        <div class="lbl">🏷️ {{ __('client.kpi_discounted') }}</div>
        <div class="val">{{ $fmt($kpi['discounted']) }}</div>
        <div class="sub2">{{ __('client.tap_to_filter') }}</div>
    </a>
    <a class="kpi" style="text-decoration:none;color:inherit;{{ ($filters['manager'] ?? '') === 'none' ? 'outline:2px solid var(--royal-blue)' : '' }}"
       href="{{ route('erp.clients', ['manager' => ($filters['manager'] ?? '') === 'none' ? null : 'none']) }}">
        <div class="lbl">🙍 {{ __('client.kpi_no_manager') }}</div>
        <div class="val {{ $kpi['no_manager'] > 0 ? 'mid' : '' }}">{{ $fmt($kpi['no_manager']) }}</div>
        <div class="sub2">{{ __('client.tap_to_filter') }}</div>
    </a>
</div>

<div class="card">
    {{-- ملحوظة: الفلاتر هنا لازم تطابق اللي ErpController::clients() بيقراه بالظبط
         الترتيب (2026-08-05): بحث ← الحالة ← القناة ← القسم ← التصنيف
         ← المحافظة ← الزون ← العقود --}}
    <form class="searchbar" method="GET">
        <input type="text" name="q" value="{{ $filters['q'] ?? '' }}"
               placeholder="🔍 {{ __('client.search_client') }}" style="flex:1;min-width:220px">
        <select name="status" style="min-width:120px">
            <option value="">{{ __('client.status_all') }} ({{ array_sum($statusCounts) }})</option>
            <option value="active" @selected(($filters['status'] ?? '') === 'active')>{{ __('client.status_active') }} ({{ $statusCounts['active'] ?? 0 }})</option>
            <option value="pending" @selected(($filters['status'] ?? '') === 'pending')>{{ __('client.status_waiting') }} ({{ $statusCounts['pending'] ?? 0 }})</option>
        </select>
        <select name="channel" style="min-width:140px">
            <option value="">{{ __('client.all_channels') }}</option>
            @foreach ($channels as $ch)
                <option value="{{ $ch->id }}" @selected((int) ($filters['channel'] ?? 0) === $ch->id)>
                    {{ $ch->displayName() }} ({{ $channelCounts[$ch->id] ?? 0 }})
                </option>
            @endforeach
        </select>
        <select name="sub" style="min-width:130px">
            <option value="">{{ __('client.all_segments') }}</option>
            @foreach (\App\Models\Channel::SUB_CHANNELS as $k => $lbl)
                <option value="{{ $k }}" @selected(($filters['sub'] ?? '') === $k)>{{ __('enums.sub_channel.'.$k) }}</option>
            @endforeach
        </select>
        <select name="cat" style="min-width:130px">
            <option value="">{{ __('client.all_categories') }}</option>
            @foreach (Client::CATEGORIES as $k => $v)
                <option value="{{ $k }}" @selected(($filters['cat'] ?? '') === $k)>{{ __('enums.category.'.$k) }}</option>
            @endforeach
        </select>
        <select name="gov" style="min-width:130px">
            <option value="">{{ __('geo.governorate') }}: {{ __('common.all') }}</option>
            @foreach (\App\Support\Governorates::options() as $gk => $gLabel)
                <option value="{{ $gk }}" @selected(($filters['gov'] ?? '') === $gk)>{{ $gLabel }}</option>
            @endforeach
        </select>
        @include('partials._zone_select', [
            'zones' => $zones,
            'name' => 'zone',
            'selected' => $filters['zone'] ?? null,
            'placeholder' => __('client.all_zones'),
        ])
        {{-- ⚠️ «منتهي» أوبشن مستقل — قبل كده كان مندمج في «بدون عقد»
             فالعميل اللي محتاج تجديد بيضيع وسط اللي عمرهم ما تعاقدوا --}}
        <select name="contract" style="min-width:120px">
            <option value="">{{ __('client.contracts_all') }}</option>
            <option value="yes" @selected(($filters['contract'] ?? '') === 'yes')>{{ __('client.contract_active') }}</option>
            <option value="expired" @selected(($filters['contract'] ?? '') === 'expired')>{{ __('client.contract_expired') }}</option>
            <option value="no" @selected(($filters['contract'] ?? '') === 'no')>{{ __('client.without_contract') }}</option>
        </select>
        <select name="disc" style="min-width:130px">
            <option value="">{{ __('client.discount_all') }}</option>
            <option value="yes" @selected(($filters['disc'] ?? '') === 'yes')>{{ __('client.discount_has') }}</option>
            <option value="no" @selected(($filters['disc'] ?? '') === 'no')>{{ __('client.discount_none') }}</option>
            <option value="custom" @selected(($filters['disc'] ?? '') === 'custom')>{{ __('client.discount_custom_only') }}</option>
        </select>
        {{-- ⚠️ المدير بيشوف نفسه بس في القايمة دي — الكنترولر بيبنيها --}}
        <select name="manager" style="min-width:150px">
            <option value="">{{ __('client.managers_all') }}</option>
            <option value="none" @selected(($filters['manager'] ?? '') === 'none')>{{ __('client.no_manager') }}</option>
            @foreach ($managerOptions as $m)
                <option value="{{ $m->id }}" @selected(($filters['manager'] ?? '') === (string) $m->id)>{{ $m->displayName() }}</option>
            @endforeach
        </select>
        <select name="flag" style="min-width:130px">
            <option value="">{{ __('client.assignment_all') }}</option>
            <option value="norep" @selected(($filters['flag'] ?? '') === 'norep')>{{ __('client.no_rep') }}</option>
        </select>
        <button class="btn gold" type="submit">{{ __('common.search') }}</button>
        <a class="btn" href="{{ route('erp.clients') }}">{{ __('common.clear') }}</a>
        <span class="badge b-gray">{{ __('client.client_countable', ['count' => $clients->total()]) }}</span>
    </form>

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
                <th class="num">{{ __('client.collection_rate') }}</th>
                <th class="num">{!! $thSort('last_payment_at', __('client.last_payment')) !!}</th>
                @if ($manager)<th></th>@endif
            </tr>
            </thead>
            <tbody>
            @forelse ($clients as $c)
                <tr class="clickable" onclick="location.href='{{ route('erp.clients.show', $c) }}'">
                    <td><b>{{ $c->fullName() }}</b><br><span style="font-size:10.5px;color:var(--muted)">{{ $c->code }}</span></td>
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
                                <span style="font-size:12px">{{ $c->manager->displayName() }}</span>
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
                                {{ $ct->number }}@if ($ct->ends_at) · {{ $ct->ends_at->format('Y-m-d') }} @endif
                                @if ($ct->group_id) · {{ __('client.from_chain') }} @endif
                            </span>
                        @elseif ($state === 'expired')
                            <span class="badge b-red">{{ __('client.contract_expired') }}</span>
                            <br><span style="font-size:10px;color:var(--muted)">
                                {{ $ct->number }}@if ($ct->ends_at) · {{ $ct->ends_at->format('Y-m-d') }}@endif
                            </span>
                        @elseif ($state === 'inactive')
                            <span class="badge b-orange">{{ __('client.contract_inactive') }}</span>
                            <br><span style="font-size:10px;color:var(--muted)">{{ $ct->number }}</span>
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
                    <td class="num">{{ $fmt($c->purchases) }}</td>
                    <td class="num pos">{{ $fmt($c->collections) }}</td>
                    <td class="num mid">{{ $fmt($c->returns) }}</td>
                    <td class="num {{ $c->balance > 0 ? 'neg' : 'pos' }}">{{ $fmt($c->balance) }}</td>
                    <td class="num">{{ number_format($c->collectionRate() * 100, 1) }}%</td>
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
        </table>
    </div>

    @include('partials._pagination', ['p' => $clients])
</div>


@endsection
