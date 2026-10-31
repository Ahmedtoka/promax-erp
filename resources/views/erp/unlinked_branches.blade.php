@extends('layouts.system')

@section('title', __('client.unlinked_title'))

@php
    $fmt = fn ($n) => number_format((float) $n);
    $url = fn (?string $tab) => route('erp.groups.unlinked', array_filter(['show' => $tab, 'q' => $search]));
@endphp

@section('actions')
    <a class="btn" href="{{ route('erp.mother_companies') }}">🏢 {{ __('client.mother_companies') }}</a>
    <a class="btn" href="{{ route('erp.groups') }}">🏬 {{ __('client.chains') }}</a>
@endsection

@section('content')

<div class="kpis">
    <a class="kpi" href="{{ $url(null) }}">
        <div class="lbl">{{ __('client.unlinked_all') }}</div>
        <div class="val">{{ $counts['all'] }}</div>
    </a>
    <a class="kpi" href="{{ $url('main') }}">
        <div class="lbl">{{ __('client.unlinked_main') }}</div>
        <div class="val">{{ $counts['main'] }}</div>
        <div class="sub2">{{ __('client.unlinked_main_hint') }}</div>
    </a>
    <a class="kpi" href="{{ $url('none') }}">
        <div class="lbl">{{ __('client.unlinked_none') }}</div>
        <div class="val">{{ $counts['none'] }}</div>
    </a>
</div>

<div class="card">
    <form class="searchbar" method="GET">
        @if ($show !== 'all')<input type="hidden" name="show" value="{{ $show }}">@endif
        <label class="fl grow"><span>{{ __('ui.l_search') }}</span>
            <input type="text" name="q" value="{{ $search }}" placeholder="🔍 {{ __('client.find_client') }}"></label>
        <button class="btn gold" type="submit">{{ __('common.search') }}</button>
        <a class="btn" href="{{ route('erp.groups.unlinked') }}">{{ __('common.clear') }}</a>
        <span class="badge b-gray">{{ $clients->count() }}</span>
    </form>

    <div class="tablewrap" style="max-height:66vh;overflow-y:auto">
        <table>
            <thead>
            <tr>
                <th>{{ __('common.code') }}</th>
                <th>{{ __('client.client') }}</th>
                <th>{{ __('client.chain') }}</th>
                <th>{{ __('client.zone') }}</th>
                <th>{{ __('client.purchases') }}</th>
                <th>{{ __('client.balance') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($clients as $c)
                <tr>
                    <td>{{ $c->code }}</td>
                    <td><a href="{{ route('erp.clients.show', $c) }}"><b>{{ $c->displayName() }}</b></a></td>
                    <td>
                        @if ($c->group)
                            <a class="badge b-orange" href="{{ route('erp.groups.show', $c->group) }}">{{ $c->group->displayName() }}</a>
                        @else
                            <span class="badge b-gray">{{ __('client.no_chain') }}</span>
                        @endif
                    </td>
                    <td>{{ $c->zone?->displayName() ?? '—' }}</td>
                    <td class="num">{{ $fmt($c->purchases) }}</td>
                    <td class="num {{ $c->balance > 0 ? 'neg' : 'pos' }}">{{ $fmt($c->balance) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:28px">{{ __('client.unlinked_empty') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@endsection
