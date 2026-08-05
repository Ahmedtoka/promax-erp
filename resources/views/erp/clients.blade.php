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
    @foreach ($channels as $ch)
        <a class="kpi" style="text-decoration:none;color:inherit;{{ (int) ($filters['channel'] ?? 0) === $ch->id ? 'outline:2px solid var(--royal-blue)' : '' }}"
           href="{{ route('erp.clients', ['channel' => (int) ($filters['channel'] ?? 0) === $ch->id ? null : $ch->id]) }}">
            <div class="lbl">🎯 {{ $ch->displayName() }}</div>
            <div class="val">{{ $fmt($channelCounts[$ch->id] ?? 0) }}</div>
            <div class="sub2">{{ __('client.tap_to_filter') }}</div>
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
        <select name="contract" style="min-width:110px">
            <option value="">{{ __('client.contracts_all') }}</option>
            <option value="yes" @selected(($filters['contract'] ?? '') === 'yes')>{{ __('client.with_contract') }}</option>
            <option value="no" @selected(($filters['contract'] ?? '') === 'no')>{{ __('client.without_contract') }}</option>
        </select>
        <button class="btn gold" type="submit">{{ __('common.search') }}</button>
        <a class="btn" href="{{ route('erp.clients') }}">{{ __('common.clear') }}</a>
        <span class="badge b-gray">{{ __('client.client_countable', ['count' => $clients->total()]) }}</span>
    </form>

    {{-- الهيدر ثابت — الجدول طويل والأعمدة بتضيع وانت نازل --}}
    <div class="tablewrap" style="max-height:65vh;overflow-y:auto">
        <table>
            <thead style="position:sticky;top:0;z-index:5;background:var(--card,#fff);box-shadow:0 1px 0 var(--border)">
            <tr>
                <th>{{ __('client.client') }}</th><th>{{ __('common.status') }}</th><th>{{ __('client.channel') }}</th><th>{{ __('client.zone') }}</th><th>{{ __('client.category') }}</th>
                <th>{{ __('client.price_list') }}</th><th>{{ __('client.contract') }}</th><th class="num">{{ __('client.discount') }}</th>
                <th class="num">{{ __('client.purchases') }}</th><th class="num">{{ __('client.collected') }}</th><th class="num">{{ __('client.returns') }}</th><th class="num">{{ __('client.balance') }}</th><th class="num">{{ __('client.collection_rate') }}</th><th class="num">{{ __('client.last_payment') }}</th>
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
                            <span class="badge b-red">{{ __('enums.client_status.rejected') }}</span>
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
                    <td><span class="badge {{ $c->categoryClass() }}">{{ $c->categoryLabel() }}</span></td>
                    <td>
                        <span class="badge {{ $c->priceList() === 'new' ? 'b-blue' : 'b-gray' }}">{{ $c->priceListLabel() }}</span>
                    </td>
                    <td>
                        @php $ct = $c->liveContract(); @endphp
                        @if ($ct)
                            <span class="badge {{ $ct->statusClass() }}">{{ $ct->statusLabel() }}</span>
                            <br><span style="font-size:10px;color:var(--muted)">
                                {{ $ct->number }}@if ($ct->group_id) · {{ __('client.from_chain') }}@endif
                            </span>
                        @else
                            <span class="badge b-gray">{{ __('client.no_contract') }}</span>
                        @endif
                    </td>
                    <td class="num">
                        <b>{{ number_format($c->effectiveDiscount() * 100, 1) }}%</b>
                        <br><span style="font-size:10px;color:var(--muted)">{{ $c->discountSource() }}</span>
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
                <tr><td colspan="{{ $manager ? 15 : 14 }}" style="text-align:center;color:var(--muted);padding:24px">{{ __('client.no_clients') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @include('partials._pagination', ['p' => $clients])
</div>


@endsection
