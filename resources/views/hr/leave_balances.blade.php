@extends('layouts.system')

{{--
    أرصدة الإجازات (Lane 7 — المهمة ٣) — سطر لكل موظف نشط، عمود لكل نوع.
    الخانة من غير صف = الديفولت من الإعدادات (`hr_leave_*`) لحد ما يتعمل
    الافتتاحي. ⚠️ الفلتر بالسنة مش بالفترة — الرصيد سنوي ومالوش تاريخ عمل.
--}}

@section('title', __('hrm.leave_balances'))

@php
    $fmt = fn ($n) => \App\Services\Hr\Leave::fmt((float) $n);
    $exportQuery = array_merge(request()->only(['q']), ['year' => $year, 'export' => 1]);
@endphp

@section('actions')
    <a class="btn sm green" href="{{ route('hr.leave.balances', $exportQuery) }}">⬇ {{ __('ui.export_all') }}</a>
    @if ($canEdit && $missing > 0)
        <form method="POST" action="{{ route('hr.leave.balances.seed') }}" style="display:inline">
            @csrf
            <input type="hidden" name="year" value="{{ $year }}">
            <button class="btn sm">＋ {{ __('hrm.leave_seed_btn', ['n' => $missing]) }}</button>
        </form>
    @endif
@endsection

@section('content')

<div class="card">
    <h3>📒 {{ __('hrm.leave_balances') }} {{ $year }} <span class="side">{{ __('hrm.leave_balances_sub') }}</span></h3>

    <form method="GET" class="searchbar" data-noprint>
        <label class="fl wide"><span>{{ __('common.search') }}</span>
            <input type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('hrm.search_ph') }}"></label>
        <label class="fl"><span>{{ __('hrm.leave_year') }}</span>
            <input type="number" name="year" value="{{ $year }}" min="2000" max="2100" style="width:90px"></label>
        <button class="btn green" type="submit">{{ __('common.filter') }}</button>
        <a class="btn" href="{{ route('hr.leave.balances') }}">{{ __('common.clear') }}</a>
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                <th data-nosum>{{ __('common.code') }}</th>
                <th style="text-align:start">{{ __('hr.employee') }}</th>
                @foreach (\App\Models\LeaveRequest::TYPES as $t)
                    <th>{{ __('hrm.leave_type_'.$t) }}<div style="font-size:10px;color:var(--muted)">{{ __('hrm.leave_used') }} / {{ __('hrm.leave_entitled') }}</div></th>
                @endforeach
            </tr>
            @forelse ($employees as $e)
                <tr>
                    <td><b dir="ltr">{{ $e->code }}</b></td>
                    <td style="text-align:start">{{ $e->displayName() }}</td>
                    @foreach (\App\Models\LeaveRequest::TYPES as $t)
                        @php $c = $grid[$e->id][$t]; @endphp
                        <td class="num">
                            {{ $fmt($c['used']) }} / {{ $fmt($c['entitled']) }}
                            @if ($c['row'] === null)<span class="badge b-gray" title="{{ __('hrm.leave_default_hint') }}">∗</span>@endif
                            @if ($canEdit && $c['row'] !== null)
                                <form method="POST" action="{{ route('hr.leave.balances.update', $c['row']) }}" style="display:inline-flex;gap:3px" data-noprint>
                                    @csrf
                                    <input type="number" name="entitled" step="0.5" min="0" max="365" value="{{ $fmt($c['entitled']) }}" style="width:58px" aria-label="{{ __('hrm.leave_entitled') }}">
                                    <button class="btn sm" title="{{ __('common.save') }}">✓</button>
                                </form>
                            @endif
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="6" style="color:var(--muted);font-size:12px">{{ __('hrm.no_rows') }}</td></tr>
            @endforelse
        </table>
    </div>
</div>

@endsection
