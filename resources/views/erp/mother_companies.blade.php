@extends('layouts.system')

@section('title', __('client.mother_companies'))

@php
    $fmt = fn ($n) => number_format((float) $n);
    $canEdit = \App\Support\Access::action(auth()->user(), 'act.org.structure');
    $shown = $stats->toBase()->only($companies->pluck('id')->all());
@endphp

@section('actions')
    <a class="btn" href="{{ route('erp.mother_companies.export', array_filter($filters)) }}">⬇ {{ __('client.mother_export') }}</a>
    @if ($canEdit)
        <button class="btn gold" onclick="openDlg('dlgNewM')">+ {{ __('client.mother_new') }}</button>
    @endif
@endsection

@section('content')

<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ __('client.mother_companies') }}</div>
        <div class="val">{{ $companies->count() }}</div>
        <div class="sub2">{{ __('client.mother_chain_count', ['count' => $companies->sum('groups_count')]) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('client.branch_count') }}</div>
        <div class="val">{{ $fmt($shown->sum('branches')) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('client.balance') }}</div>
        <div class="val {{ $shown->sum('balance') > 0 ? 'neg' : 'pos' }}">{{ $fmt($shown->sum('balance')) }} {{ __('common.currency') }}</div>
    </div>
    <a class="kpi" href="{{ route('erp.groups') }}">
        <div class="lbl">{{ __('client.mother_unlinked') }}</div>
        <div class="val">{{ $unlinked }}</div>
    </a>
</div>

<div class="card">
    <form class="searchbar" method="GET">
        <label class="fl grow"><span>{{ __('ui.l_search') }}</span>
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="🔍 {{ __('client.mother_search') }}"></label>
        <button class="btn gold" type="submit">{{ __('common.search') }}</button>
        <a class="btn" href="{{ route('erp.mother_companies') }}">{{ __('common.clear') }}</a>
    </form>

    <div class="tablewrap">
        <table>
            <thead>
            <tr>
                <th>{{ __('client.mother_company') }}</th>
                <th>{{ __('client.mother_chains') }}</th>
                <th>{{ __('client.branch_count') }}</th>
                <th>{{ __('client.purchases') }}</th>
                <th>{{ __('client.collected') }}</th>
                <th>{{ __('client.returns') }}</th>
                <th>{{ __('client.balance') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($companies as $m)
                @php $s = $stats->get($m->id); @endphp
                <tr class="clickable" onclick="location.href='{{ route('erp.mother_companies.show', $m) }}'">
                    <td>
                        <a href="{{ route('erp.mother_companies.show', $m) }}" onclick="event.stopPropagation()"><b>{{ $m->displayName() }}</b></a>
                        @if (! $m->active)<span class="badge b-gray">{{ __('client.suspended') }}</span>@endif
                        <br><span style="font-size:10.5px;color:var(--muted)">{{ $m->code }}</span>
                    </td>
                    <td class="num"><b>{{ $m->groups_count }}</b></td>
                    <td class="num">{{ $fmt($s->branches ?? 0) }}</td>
                    <td class="num">{{ $fmt($s->purchases ?? 0) }}</td>
                    <td class="num pos">{{ $fmt($s->collections ?? 0) }}</td>
                    <td class="num mid">{{ $fmt($s->returns ?? 0) }}</td>
                    <td class="num {{ ($s->balance ?? 0) > 0 ? 'neg' : 'pos' }}">{{ $fmt($s->balance ?? 0) }}</td>
                </tr>
            @empty
                <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:28px">{{ __('client.mother_none') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@if ($canEdit)
<dialog id="dlgNewM">
    <form class="dlg" method="POST" action="{{ route('erp.mother_companies.store') }}">
        @csrf
        <h4>{{ __('client.mother_new') }}</h4>
        <div class="frow">
            <div>
                <label class="f">{{ __('client.mother_name_en') }}</label>
                <input type="text" name="name_en" dir="ltr" maxlength="120" style="width:100%" value="{{ old('name_en') }}">
            </div>
            <div>
                <label class="f">{{ __('client.mother_name_ar') }} <b class="req-star">*</b></label>
                <input type="text" name="name" required maxlength="120" style="width:100%"
                       class="{{ $errors->has('name') ? 'bad' : '' }}" value="{{ old('name') }}">
                @error('name')<div class="errline">{{ $message }}</div>@enderror
            </div>
        </div>
        <div><label class="f">{{ __('common.notes') }}</label><textarea name="notes" rows="2" style="width:100%">{{ old('notes') }}</textarea></div>
        <div class="alert info" style="margin:12px 0">
            <span>ℹ️</span><span>{{ __('client.mother_is_a_grouping') }}</span>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end">
            <button class="btn" type="button" onclick="closeDlg('dlgNewM')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.create') }}</button>
        </div>
    </form>
</dialog>
@if ($errors->has('name'))
    <script>document.addEventListener('DOMContentLoaded', () => openDlg('dlgNewM'));</script>
@endif
@endif

@endsection
