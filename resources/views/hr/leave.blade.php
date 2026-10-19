@extends('layouts.system')

{{--
    الإجازات (Lane 7 — المهمة ٣): رصيدي + طلباتي + طلب جديد، وتاب «مستني
    موافقتي» للمدير المباشر (الأدمن والموارد: كل المعلّق).
    ⚠️ الحارس على السيرفر — زرار القرار هنا راحة بس.
--}}

@section('title', __('hrm.leave'))

@php
    $exportQuery = array_merge(['tab' => $tab], $range->query(), ['export' => 1]);
    $isApprovals = $tab === 'approvals';
@endphp

@section('actions')
    <a class="btn sm green" href="{{ route('hr.leave', $exportQuery) }}">⬇ {{ __('ui.export_all') }}</a>
    @if ($canBalances)
        <a class="btn sm" href="{{ route('hr.leave.balances') }}">📒 {{ __('hrm.leave_balances') }}</a>
    @endif
    @if ($employee)
        <button class="btn green" type="button" onclick="openDlg('dlgLeave')">＋ {{ __('hrm.leave_new') }}</button>
    @endif
@endsection

@section('content')

@if ($employee)
    <div class="kpis">
        @foreach ($balances as $b)
            <div class="kpi">
                <div class="lbl">{{ $b['label'] }}</div>
                @if (\App\Services\Hr\Leave::checked($b['type']))
                    <div class="val num">{{ \App\Services\Hr\Leave::fmt($b['remaining']) }}</div>
                    <div class="sub2">{{ __('hrm.leave_of', ['used' => \App\Services\Hr\Leave::fmt($b['used']), 'entitled' => \App\Services\Hr\Leave::fmt($b['entitled'])]) }}</div>
                @else
                    <div class="val">∞</div>
                    <div class="sub2">{{ __('hrm.leave_unlimited', ['used' => \App\Services\Hr\Leave::fmt($b['used'])]) }}</div>
                @endif
            </div>
        @endforeach
    </div>
@else
    <div class="card"><p style="color:var(--muted);font-size:12.5px;margin:0">{{ __('hrm.leave_no_record_hint') }}</p></div>
@endif

<div class="card" id="leave-table">
    <h3>🌴 {{ __('hrm.leave') }} <span class="side">{{ __('hrm.leave_sub') }}</span></h3>

    <div style="display:flex;gap:6px;margin-bottom:10px" data-noprint>
        <a class="btn sm {{ $isApprovals ? '' : 'green' }}" href="{{ route('hr.leave', $range->query()) }}">{{ __('hrm.leave_mine') }}</a>
        <a class="btn sm {{ $isApprovals ? 'green' : '' }}" href="{{ route('hr.leave', array_merge(['tab' => 'approvals'], $range->query())) }}">
            {{ __('hrm.leave_approvals') }} @if ($pendingCount > 0)<span class="badge b-orange">{{ $pendingCount }}</span>@endif</a>
    </div>

    <form method="GET" class="searchbar" data-noprint>
        <input type="hidden" name="tab" value="{{ $tab }}">
        {{-- الفترة على تاريخ بداية الإجازة — الفاضي = الكل --}}
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue()])
        <button class="btn green" type="submit">{{ __('common.filter') }}</button>
        <a class="btn" href="{{ route('hr.leave', ['tab' => $tab]) }}">{{ __('common.clear') }}</a>
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                @if ($isApprovals)<th style="text-align:start">{{ __('hr.employee') }}</th>@endif
                <th>{{ __('hrm.leave_type') }}</th>
                <th>{{ __('hrm.leave_from') }}</th>
                <th>{{ __('hrm.leave_to') }}</th>
                <th class="num">{{ __('hrm.leave_days') }}</th>
                <th style="text-align:start">{{ __('hrm.leave_note') }}</th>
                <th>{{ __('hrm.status') }}</th>
                <th data-noprint></th>
            </tr>
            @forelse ($rows as $r)
                <tr>
                    @if ($isApprovals)<td style="text-align:start">{{ $r->employee?->displayName() ?? '—' }}</td>@endif
                    <td>{{ $r->typeLabel() }}</td>
                    <td class="num" style="font-size:11px">{{ $r->from->format('Y-m-d') }}</td>
                    <td class="num" style="font-size:11px">{{ $r->to->format('Y-m-d') }}</td>
                    <td class="num">{{ \App\Services\Hr\Leave::fmt((float) $r->days) }}</td>
                    <td style="text-align:start;font-size:11.5px">{{ $r->note ?: '—' }}
                        @if ($r->decision_note)<div style="color:var(--muted);font-size:10.5px">↳ {{ $r->decision_note }}</div>@endif</td>
                    <td><span class="badge {{ $r->statusBadge() }}">{{ $r->statusLabel() }}</span></td>
                    <td data-noprint style="white-space:nowrap">
                        @if ($r->status === 'pending' && $isApprovals)
                            <form method="POST" action="{{ route('hr.leave.decide', $r) }}" style="display:inline-flex;gap:4px">
                                @csrf
                                <input type="text" name="note" maxlength="300" placeholder="{{ __('hrm.leave_decision_note') }}" style="width:130px">
                                <button class="btn sm green" name="decision" value="approved">✓ {{ __('hrm.leave_approve') }}</button>
                                <button class="btn sm red" name="decision" value="rejected">✕ {{ __('hrm.leave_reject') }}</button>
                            </form>
                        @elseif ($r->status === 'pending')
                            <form method="POST" action="{{ route('hr.leave.cancel', $r) }}" style="display:inline"
                                  onsubmit="return confirm(@js(__('hrm.leave_cancel_confirm')))">
                                @csrf
                                <button class="btn sm red">{{ __('hrm.leave_cancel') }}</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ $isApprovals ? 8 : 7 }}" style="color:var(--muted);font-size:12px">{{ __('hrm.leave_no_rows') }}</td></tr>
            @endforelse
        </table>
    </div>

    @include('partials._pagination', ['p' => $rows])
</div>

{{-- ═══════════ طلب إجازة جديد — الديالوج جوه content عن قصد ═══════════ --}}
@if ($employee)
<dialog id="dlgLeave">
    <form class="dlg" method="POST" action="{{ route('hr.leave.store') }}">
        @csrf
        <h4>🌴 {{ __('hrm.leave_new') }}</h4>
        <label class="fl"><span>{{ __('hrm.leave_type') }}</span>
            <select name="type" required>
                @foreach (\App\Models\LeaveRequest::TYPES as $t)
                    <option value="{{ $t }}" @selected(old('type') === $t)>{{ __('hrm.leave_type_'.$t) }}</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('hrm.leave_from') }}</span>
            <input type="date" name="from" value="{{ old('from') }}" required></label>
        <label class="fl"><span>{{ __('hrm.leave_to') }}</span>
            <input type="date" name="to" value="{{ old('to') }}" required></label>
        <label class="fl wide"><span>{{ __('hrm.leave_note') }}</span>
            <input type="text" name="note" maxlength="300" value="{{ old('note') }}"></label>
        <p style="color:var(--muted);font-size:11px;margin:8px 0 0">{{ __('hrm.leave_weekend_hint') }}</p>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgLeave')">{{ __('common.cancel') }}</button>
            <button class="btn green" type="submit">{{ __('hrm.leave_send') }}</button>
        </div>
    </form>
</dialog>
@endif

@endsection
