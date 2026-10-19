@extends('layouts.system')

{{--
    تسوية البنك (Lane 4، L4-D6) — كشف حساب بنك برصيد أول وآخر الفترة،
    والمحاسب بيعلّم على سطور حساب البنك في الدفتر من شاشة الكشف.
    المسوّى = رصيد أول الكشف + المتعلّم عليه · الفرق = رصيد آخره − المسوّى.
--}}

@section('title', __('cash.recon'))

@php
    $fmt = fn ($n) => number_format((float) $n, 2);
    $canPost = \App\Support\Access::action(auth()->user(), 'act.gl.post');
    $activeBanks = $banks->where('active', true);
@endphp

@section('actions')
    <a class="btn sm green" href="{{ request()->fullUrlWithQuery(['export' => 1, 'page' => null]) }}">⬇ {{ __('ui.export_all') }}</a>
    <a class="btn sm" href="{{ route('gl.safes') }}">🏦 {{ __('cash.safes') }}</a>
    @if ($canPost)
        <button class="btn gold" type="button" onclick="openDlg('dlgRecon')">＋ {{ __('cash.recon_new') }}</button>
    @endif
@endsection

@section('content')

@if ($errors->any())
    <div class="alert bad" style="margin-bottom:12px"><span>⛔</span><span>{{ $errors->first() }}</span></div>
@endif

<div class="kpis">
    <a class="kpi" style="grid-column:span 2" href="{{ request()->fullUrlWithQuery(['status' => request('status') === 'open' ? null : 'open', 'page' => null]) }}#recon-table" title="{{ __('ui.click_to_filter') }}">
        <div class="lbl">{{ __('cash.recon_status_open') }}</div>
        <div class="val">{{ number_format($kpis['open']) }}</div>
        <div class="sub2">{{ __('cash.recon_open_sub') }}</div>
    </a>
    <a class="kpi" style="grid-column:span 2" href="{{ request()->fullUrlWithQuery(['status' => request('status') === 'closed' ? null : 'closed', 'page' => null]) }}#recon-table" title="{{ __('ui.click_to_filter') }}">
        <div class="lbl">{{ __('cash.recon_status_closed') }}</div>
        <div class="val">{{ number_format($kpis['closed']) }}</div>
        <div class="sub2">{{ __('cash.recon_closed_sub') }}</div>
    </a>
</div>

<div class="card" id="recon-table">
    <h3>🧮 {{ __('cash.recon') }} <span class="side">{{ __('cash.recon_sub') }}</span></h3>

    <form method="GET" class="searchbar" data-noprint>
        <label class="fl"><span>{{ __('cash.bank') }}</span>
            <select name="safe_id" onchange="this.form.submit()">
                <option value="">{{ __('cash.all_banks') }}</option>
                @foreach ($banks as $b)
                    <option value="{{ $b->id }}" @selected((string) request('safe_id') === (string) $b->id)>{{ $b->displayName() }} ({{ $b->code }})</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('cash.count_status') }}</span>
            <select name="status" onchange="this.form.submit()">
                <option value="">{{ __('cash.all_statuses') }}</option>
                @foreach (\App\Models\BankStatement::STATUSES as $st)
                    <option value="{{ $st }}" @selected(request('status') === $st)>{{ __('cash.recon_status_'.$st) }}</option>
                @endforeach
            </select></label>
        {{-- ⚠️ `all => false`: الفاضي = الشهر الحالي (`DateRange` month) — على تاريخ الكشف --}}
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue(), 'auto' => true, 'all' => false])
        <button class="btn gold" type="submit">{{ __('common.filter') }}</button>
        <a class="btn" href="{{ route('gl.recon') }}">{{ __('common.clear') }}</a>
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                <th data-nosum>{{ __('cash.recon_number') }}</th>
                <th style="text-align:start">{{ __('cash.bank') }}</th>
                <th>{{ __('common.date') }}</th>
                <th data-nosum>{{ __('cash.recon_period') }}</th>
                <th class="num" data-nosum>{{ __('cash.recon_opening') }}</th>
                <th class="num" data-nosum>{{ __('cash.recon_closing') }}</th>
                <th class="num" data-nosum>{{ __('cash.recon_cleared') }}</th>
                <th class="num" data-nosum>{{ __('cash.difference') }}</th>
                <th>{{ __('cash.count_status') }}</th>
                <th></th>
            </tr>
            @forelse ($rows as $s)
                <tr>
                    <td><b>{{ $s->number }}</b></td>
                    <td style="text-align:start">{{ $s->safe?->displayName() ?? '—' }}
                        <div style="font-size:10px;color:var(--muted)">{{ $s->safe?->code }}</div>
                    </td>
                    <td class="num" style="font-size:11px">{{ $s->date?->format('Y-m-d') }}</td>
                    <td class="num" style="font-size:11px" dir="ltr">{{ $s->date_from?->format('Y-m-d') }} → {{ $s->date_to?->format('Y-m-d') }}</td>
                    <td class="num">{{ $fmt($s->opening_balance) }}</td>
                    <td class="num">{{ $fmt($s->closing_balance) }}</td>
                    <td class="num">{{ $fmt($s->cleared) }}</td>
                    <td class="num"><b class="{{ $s->difference != 0 ? 'neg' : 'pos' }}">{{ $fmt($s->difference) }}</b></td>
                    <td><span class="badge {{ $s->status === 'closed' ? 'b-green' : 'b-blue' }}">{{ __('cash.recon_status_'.$s->status) }}</span></td>
                    <td><a class="btn sm" href="{{ route('gl.recon.show', $s) }}">{{ __('cash.recon_open_btn') }}</a></td>
                </tr>
            @empty
                <tr><td colspan="10" style="color:var(--muted);font-size:12px">{{ __('cash.recon_none') }}</td></tr>
            @endforelse
        </table>
    </div>

    @include('partials._pagination', ['p' => $rows])
</div>

{{-- ═══════════ كشف جديد — الديالوج جوه content عن قصد ═══════════ --}}
@if ($canPost)
<dialog id="dlgRecon">
    <form class="dlg" method="POST" action="{{ route('gl.recon.store') }}">
        @csrf
        <h4>🧮 {{ __('cash.recon_new') }}</h4>

        <div>
            <label class="f">{{ __('cash.bank') }} <b class="req-star">*</b></label>
            <select name="safe_id" required style="width:100%">
                <option value="">{{ __('cash.choose_bank') }}</option>
                @foreach ($activeBanks as $b)
                    <option value="{{ $b->id }}" @selected(old('safe_id') == $b->id)>{{ $b->displayName() }} ({{ $b->code }})</option>
                @endforeach
            </select>
        </div>

        <div class="frow" style="margin-top:10px">
            <div>
                <label class="f">{{ __('cash.recon_date') }} <b class="req-star">*</b></label>
                <input type="date" name="date" required value="{{ old('date', today()->toDateString()) }}" style="width:100%">
            </div>
            <div>
                <label class="f">{{ __('cash.recon_from') }} <b class="req-star">*</b></label>
                <input type="date" name="date_from" required value="{{ old('date_from', today()->startOfMonth()->toDateString()) }}" style="width:100%">
            </div>
            <div>
                <label class="f">{{ __('cash.recon_to') }} <b class="req-star">*</b></label>
                <input type="date" name="date_to" required value="{{ old('date_to', today()->toDateString()) }}" style="width:100%">
            </div>
        </div>

        <div class="frow" style="margin-top:10px">
            <div>
                <label class="f">{{ __('cash.recon_opening') }} <b class="req-star">*</b></label>
                <input type="number" name="opening_balance" required step="0.01" dir="ltr" value="{{ old('opening_balance', '0') }}" style="width:100%">
            </div>
            <div>
                <label class="f">{{ __('cash.recon_closing') }} <b class="req-star">*</b></label>
                <input type="number" name="closing_balance" required step="0.01" dir="ltr" value="{{ old('closing_balance') }}" style="width:100%">
            </div>
        </div>
        <div style="font-size:10.5px;color:var(--muted);margin-top:4px">{{ __('cash.recon_balances_hint') }}</div>

        <div style="margin-top:10px">
            <label class="f">{{ __('cash.count_note') }}</label>
            <input type="text" name="note" maxlength="250" value="{{ old('note') }}" style="width:100%">
        </div>

        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgRecon')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>
@endif

@endsection
