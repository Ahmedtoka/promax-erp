@extends('layouts.system')

@section('title', __('review.tab_data'))

@php
    $items = \App\Models\ReviewCheck::DATA_ITEMS;
    $tab = fn ($s) => route('erp.review.data', array_filter($filters + ['show' => $s]));
@endphp

@section('content')
@php $can = fn ($r) => \App\Support\Access::allows(auth()->user(), $r); @endphp

@include('erp.review._tabs')

<div class="kpis">
    @foreach (['pending' => '⏳', 'issue' => '❌', 'done' => '✅', 'all' => '📋'] as $k => $ico)
        <a class="kpi {{ $show === $k ? 'on' : '' }}" href="{{ $tab($k) }}">
            <div class="lbl">{{ $ico }} {{ __('review.show_'.$k) }}</div>
            <div class="val">{{ $counts[$k] }}</div>
        </a>
    @endforeach
</div>

<div class="card">
    <form class="searchbar" method="GET">
        <input type="hidden" name="show" value="{{ $show }}">
        <label class="fl grow"><span>{{ __('ui.l_search') }}</span>
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="🔍 {{ __('client.find_client') }}"></label>
        <label class="fl"><span>{{ __('review.filter_chain') }}</span>
            <select name="group"><option value="">{{ __('review.all') }}</option>
                @foreach ($groups as $g)<option value="{{ $g->id }}" @selected((int) ($filters['group'] ?? 0) === $g->id)>{{ $g->displayName() }}</option>@endforeach
            </select></label>
        <label class="fl"><span>{{ __('review.filter_mother') }}</span>
            <select name="mother"><option value="">{{ __('review.all') }}</option>
                @foreach ($mothers as $m)<option value="{{ $m->id }}" @selected((int) ($filters['mother'] ?? 0) === $m->id)>{{ $m->displayName() }}</option>@endforeach
            </select></label>
        <button class="btn gold" type="submit">{{ __('common.search') }}</button>
        <a class="btn" href="{{ route('erp.review.data') }}">{{ __('common.clear') }}</a>
    </form>

    <div class="tablewrap">
        <table id="rvData">
            <thead><tr>
                <th>{{ __('review.item_name') }}</th>
                <th>{{ __('review.item_address') }}</th>
                <th>{{ __('review.item_chain') }}</th>
                <th>{{ __('review.item_mother') }}</th>
                <th>{{ __('review.item_cr') }}</th>
                <th>{{ __('review.item_price_list') }}</th>
                <th data-nosum></th>
            </tr></thead>
            <tbody>
            @forelse ($clients as $c)
                @php
                    $ch = $checks[$c->id] ?? [];
                    $states = collect($items)->map(fn ($i) => ($ch[$i] ?? null)?->status);
                    $rowCls = $states->contains('issue') ? 'rv-issue' : ($states->every(fn ($s) => $s === 'ok') ? 'rv-done' : '');
                    $cell = fn ($item) => view('erp.review._mark', ['type' => 'client', 'id' => $c->id, 'item' => $item, 'check' => $ch[$item] ?? null])->render();
                @endphp
                <tr data-row="client-{{ $c->id }}" class="{{ $rowCls }}">
                    <td>
                        <a class="rv-val rv-edit" href="{{ route('erp.clients.show', $c) }}" target="_blank" rel="noopener">{{ $c->displayName() }}</a>
                        <div class="rv-sub" dir="auto">{{ $c->code }}@if ($c->name_en && $c->name_en !== $c->name) · {{ $c->name_en }}@endif</div>
                        {!! $cell('name') !!}
                    </td>
                    <td><div class="rv-val" style="font-weight:600">{{ $c->displayAddress() ?: __('review.none') }}</div>
                        <div class="rv-sub">{{ $c->zone?->displayName() }}</div>{!! $cell('address') !!}</td>
                    <td>
                        @if ($c->group)
                            <a class="rv-val rv-edit" href="{{ route('erp.groups.show', $c->group) }}" target="_blank" rel="noopener">{{ $c->group->displayName() }}</a>
                        @else <span class="rv-sub">{{ __('review.solo') }}</span> @endif
                        {!! $cell('chain') !!}
                    </td>
                    <td><div class="rv-val">{{ $c->group?->motherCompany?->displayName() ?? __('review.none') }}</div>{!! $cell('mother') !!}</td>
                    <td><div class="rv-val" dir="ltr">{{ $c->commercial_register ?: __('review.none') }}</div>{!! $cell('cr') !!}</td>
                    <td><div class="rv-val">{{ $lists[$c->id] ?? __('review.none') }}</div>{!! $cell('price_list') !!}</td>
                    <td style="white-space:nowrap">
                        <button type="button" class="btn sm" style="background:#16A34A;color:#fff;border-color:#16A34A"
                                onclick="rvAll('client', {{ $c->id }}, @js($items))">{{ __('review.all_ok') }}</button>
                        <div style="margin-top:6px;display:flex;gap:4px">
                            @if ($can('erp.clients.edit'))
                                <a class="btn sm rv-edit" href="{{ route('erp.clients.edit', $c) }}" target="_blank" rel="noopener">{{ __('review.edit_client') }}</a>
                            @endif
                            @if ($c->group)
                                <a class="btn sm rv-edit" href="{{ route('erp.groups.show', $c->group) }}" target="_blank" rel="noopener">{{ __('review.edit_chain') }}</a>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:28px">{{ __('review.no_rows') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div style="margin-top:12px">{{ $clients->links() }}</div>
</div>

@endsection
