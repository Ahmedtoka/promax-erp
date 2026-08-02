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
        <a class="btn gold" href="{{ route('erp.clients.new') }}">+ {{ __('client.new_client') }}</a>
    @endif
@endsection

@section('content')

<div class="kpis">
    @foreach (Client::CATEGORIES as $key => [$label, $cls])
        @if ($key !== 'internal')
            <div class="kpi">
                <div class="lbl">{{ __('enums.category.'.$key) }}</div>
                <div class="val">{{ $catCounts[$key] ?? 0 }}</div>
                <div class="sub2"><a href="{{ route('erp.clients', ['cat' => $key]) }}" style="color:var(--blue);font-weight:700">{{ __('common.show_them') }} ←</a></div>
            </div>
        @endif
    @endforeach
</div>

<div class="card">
    {{-- ملحوظة: الفلاتر هنا لازم تطابق اللي ErpController::clients() بيقراه بالظبط --}}
    <form class="searchbar" method="GET">
        <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="🔍 {{ __('client.search_client') }}">
        <select name="cat">
            <option value="">{{ __('client.all_categories') }}</option>
            @foreach (Client::CATEGORIES as $k => $v)
                <option value="{{ $k }}" @selected(($filters['cat'] ?? '') === $k)>{{ __('enums.category.'.$k) }}</option>
            @endforeach
        </select>
        <select name="channel">
            <option value="">{{ __('client.all_channels') }}</option>
            @foreach ($channels as $ch)
                <option value="{{ $ch->id }}" @selected((int) ($filters['channel'] ?? 0) === $ch->id)>
                    {{ $ch->displayName() }} ({{ $channelCounts[$ch->id] ?? 0 }})
                </option>
            @endforeach
        </select>
        <select name="sub">
            <option value="">{{ __('client.all_segments') }}</option>
            @foreach (\App\Models\Channel::SUB_CHANNELS as $k => $lbl)
                <option value="{{ $k }}" @selected(($filters['sub'] ?? '') === $k)>{{ __('enums.sub_channel.'.$k) }}</option>
            @endforeach
        </select>
        <select name="zone">
            <option value="">{{ __('client.all_zones') }}</option>
            @foreach ($zones as $z)
                <option value="{{ $z->id }}" @selected((int) ($filters['zone'] ?? 0) === $z->id)>{{ $z->displayName() }}</option>
            @endforeach
        </select>
        <select name="contract">
            <option value="">{{ __('client.contracts_all') }}</option>
            <option value="yes" @selected(($filters['contract'] ?? '') === 'yes')>{{ __('client.with_contract') }}</option>
            <option value="no" @selected(($filters['contract'] ?? '') === 'no')>{{ __('client.without_contract') }}</option>
        </select>
        <select name="status">
            <option value="">{{ __('client.status_all') }} ({{ array_sum($statusCounts) }})</option>
            <option value="active" @selected(($filters['status'] ?? '') === 'active')>{{ __('client.status_active') }} ({{ $statusCounts['active'] ?? 0 }})</option>
            <option value="pending" @selected(($filters['status'] ?? '') === 'pending')>{{ __('client.status_waiting') }} ({{ $statusCounts['pending'] ?? 0 }})</option>
        </select>
        <button class="btn gold" type="submit">{{ __('common.search') }}</button>
        <a class="btn" href="{{ route('erp.clients') }}">{{ __('common.clear') }}</a>
        <span class="badge b-gray">{{ __('client.client_countable', ['count' => $clients->total()]) }}</span>
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('client.client') }}</th><th>{{ __('client.channel') }}</th><th>{{ __('client.zone') }}</th><th>{{ __('client.category') }}</th>
                <th>{{ __('client.price_list') }}</th><th>{{ __('client.contract') }}</th><th>{{ __('client.discount') }}</th>
                <th>{{ __('client.purchases') }}</th><th>{{ __('client.collected') }}</th><th>{{ __('client.returns') }}</th><th>{{ __('client.balance') }}</th><th>{{ __('client.collection_rate') }}</th><th>{{ __('client.last_payment') }}</th>
                @if ($manager)<th></th>@endif
            </tr>
            @forelse ($clients as $c)
                <tr class="clickable" onclick="location.href='{{ route('erp.clients.show', $c) }}'">
                    <td>
                        <b>{{ $c->displayName() }}</b>
                        {{-- ⚠️ الشارة على المستني بس — 455 شارة خضرا زحمة
                             بتغرق الاستثناء اللي الشارة اتعملت توريه. --}}
                        @if ($c->status !== 'active')
                            <span class="badge b-orange" style="font-size:9.5px">{{ __('client.status_waiting') }}</span>
                        @endif
                        <br><span style="font-size:10.5px;color:var(--muted)">{{ $c->code }}</span>
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
                <tr><td colspan="{{ $manager ? 14 : 13 }}" style="text-align:center;color:var(--muted);padding:24px">{{ __('client.no_clients') }}</td></tr>
            @endforelse
        </table>
    </div>

    <div class="pag">{{ $clients->links('pagination::simple-default') }}</div>
</div>


@endsection
