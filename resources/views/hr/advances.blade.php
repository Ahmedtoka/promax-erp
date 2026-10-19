@extends('layouts.system')

{{--
    السلف والجزاءات (Lane 7 — المهمة ٥). تابين: سلف / جزاءات، والفلتر على التاريخ.
    ⚠️ الأزرار راحة بس — الحارس على السيرفر (role + نطاق الموظف + الخزنة النشطة).
--}}

@section('title', __('hrm.advances'))

@php
    $isPen = $tab === 'penalties';
    $fmt = fn ($n) => number_format((float) $n, 2);
    $exportQuery = array_merge(['tab' => $tab], $range->query(), ['export' => 1]);
    $badge = ['open' => 'b-orange', 'closed' => 'b-green', 'void' => 'b-gray'];
@endphp

@section('actions')
    <a class="btn sm green" href="{{ route('hr.advances', $exportQuery) }}">⬇ {{ __('ui.export_all') }}</a>
    @if ($canEdit)
        @if ($isPen)
            <button class="btn green" type="button" onclick="openDlg('dlgPenalty')">＋ {{ __('hrm.pen_new') }}</button>
        @else
            <button class="btn green" type="button" onclick="openDlg('dlgAdvance')">＋ {{ __('hrm.adv_new') }}</button>
        @endif
    @endif
@endsection

@section('content')

<div class="card" id="adv-table">
    <h3>💵 {{ __('hrm.advances') }} <span class="side">{{ __('hrm.advances_sub') }}</span></h3>

    <div style="display:flex;gap:6px;margin-bottom:10px" data-noprint>
        <a class="btn sm {{ $isPen ? '' : 'green' }}" href="{{ route('hr.advances', $range->query()) }}">{{ __('hrm.advances') }}</a>
        <a class="btn sm {{ $isPen ? 'green' : '' }}" href="{{ route('hr.advances', array_merge(['tab' => 'penalties'], $range->query())) }}">{{ __('hrm.penalties') }}</a>
    </div>

    <form method="GET" class="searchbar" data-noprint>
        <input type="hidden" name="tab" value="{{ $tab }}">
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue()])
        <button class="btn green" type="submit">{{ __('common.filter') }}</button>
        <a class="btn" href="{{ route('hr.advances', ['tab' => $tab]) }}">{{ __('common.clear') }}</a>
    </form>

    <div class="tablewrap">
        @if ($isPen)
            <table>
                <tr>
                    <th>{{ __('common.date') }}</th>
                    <th style="text-align:start">{{ __('hr.employee') }}</th>
                    <th class="num">{{ __('common.amount') }}</th>
                    <th style="text-align:start">{{ __('hrm.pen_reason') }}</th>
                    <th>{{ __('hrm.status') }}</th>
                    @if ($canEdit)<th data-noprint></th>@endif
                </tr>
                @forelse ($rows as $p)
                    <tr>
                        <td class="num" style="font-size:11px">{{ $p->date->format('Y-m-d') }}</td>
                        <td style="text-align:start">{{ $p->employee?->displayName() ?? '—' }}</td>
                        <td class="num">{{ $fmt($p->amount) }}</td>
                        <td style="text-align:start;font-size:11.5px">{{ $p->reason }}</td>
                        <td><span class="badge {{ $p->payslip_id ? 'b-green' : 'b-orange' }}">{{ $p->payslip_id ? __('hrm.pen_deducted') : __('hrm.pen_pending') }}</span></td>
                        @if ($canEdit)
                            <td data-noprint>
                                @if ($p->payslip_id === null)
                                    <form method="POST" action="{{ route('hr.penalties.delete', $p) }}" style="display:inline"
                                          onsubmit="return confirm(@js(__('hrm.pen_delete_confirm')))">
                                        @csrf
                                        <button class="btn sm red">{{ __('hrm.pen_delete') }}</button>
                                    </form>
                                @endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $canEdit ? 6 : 5 }}" style="color:var(--muted);font-size:12px">{{ __('hrm.pen_no_rows') }}</td></tr>
                @endforelse
            </table>
        @else
            <table>
                <tr>
                    <th data-nosum>{{ __('common.code') }}</th>
                    <th>{{ __('common.date') }}</th>
                    <th style="text-align:start">{{ __('hr.employee') }}</th>
                    <th class="num">{{ __('common.amount') }}</th>
                    <th class="num">{{ __('hrm.adv_installments') }}</th>
                    <th class="num">{{ __('hrm.adv_installment_amount') }}</th>
                    <th>{{ __('hrm.adv_start') }}</th>
                    <th class="num">{{ __('hrm.adv_recovered') }}</th>
                    <th class="num">{{ __('hrm.adv_remaining') }}</th>
                    <th style="text-align:start">{{ __('hrm.adv_safe') }}</th>
                    <th>{{ __('hrm.status') }}</th>
                    @if ($canEdit)<th data-noprint></th>@endif
                </tr>
                @forelse ($rows as $a)
                    <tr>
                        <td class="num">{{ $a->number }}</td>
                        <td class="num" style="font-size:11px">{{ $a->date->format('Y-m-d') }}</td>
                        <td style="text-align:start">{{ $a->employee?->displayName() ?? '—' }}</td>
                        <td class="num">{{ $fmt($a->amount) }}</td>
                        <td class="num">{{ $a->installments }}</td>
                        <td class="num">{{ $fmt($a->installment_amount) }}</td>
                        <td class="num" style="font-size:11px">{{ $a->start_period }}</td>
                        <td class="num">{{ $fmt($a->recovered) }}</td>
                        <td class="num">{{ $fmt($a->remaining()) }}</td>
                        <td style="text-align:start;font-size:11.5px">{{ $a->safe?->displayName() ?? '—' }}</td>
                        <td><span class="badge {{ $badge[$a->status] ?? 'b-gray' }}">{{ __('hrm.adv_status_'.$a->status) }}</span></td>
                        @if ($canEdit)
                            <td data-noprint>
                                @if ($a->status === 'open' && (float) $a->recovered == 0.0)
                                    <form method="POST" action="{{ route('hr.advances.void', $a) }}" style="display:inline"
                                          onsubmit="return confirm(@js(__('hrm.adv_void_confirm')))">
                                        @csrf
                                        <button class="btn sm red">{{ __('hrm.adv_void') }}</button>
                                    </form>
                                @endif
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $canEdit ? 12 : 11 }}" style="color:var(--muted);font-size:12px">{{ __('hrm.adv_no_rows') }}</td></tr>
                @endforelse
            </table>
        @endif
    </div>

    @include('partials._pagination', ['p' => $rows])
</div>

{{-- ═══════════ الديالوجات جوه content عن قصد ═══════════ --}}
@if ($canEdit)
<dialog id="dlgAdvance">
    <form class="dlg" method="POST" action="{{ route('hr.advances.store') }}">
        @csrf
        <h4>💵 {{ __('hrm.adv_new') }}</h4>
        <label class="fl wide"><span>{{ __('hr.employee') }}</span>
            <select name="employee_id" required>
                <option value="">—</option>
                @foreach ($employees as $e)
                    <option value="{{ $e->id }}" @selected((string) old('employee_id') === (string) $e->id)>{{ $e->code }} · {{ $e->displayName() }}</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('common.date') }}</span>
            <input type="date" name="date" value="{{ old('date', now()->toDateString()) }}" required></label>
        <label class="fl"><span>{{ __('common.amount') }}</span>
            <input type="number" name="amount" step="0.01" min="0.01" value="{{ old('amount') }}" required></label>
        <label class="fl"><span>{{ __('hrm.adv_installments') }}</span>
            <input type="number" name="installments" step="1" min="1" max="120" value="{{ old('installments', 1) }}" required></label>
        <label class="fl"><span>{{ __('hrm.adv_start') }}</span>
            <input type="month" name="start_period" value="{{ old('start_period', now()->addMonth()->format('Y-m')) }}" required></label>
        <label class="fl wide"><span>{{ __('hrm.adv_safe') }}</span>
            <select name="safe_id" required>
                <option value="">—</option>
                @foreach ($safes as $s)
                    <option value="{{ $s->id }}" @selected((string) old('safe_id') === (string) $s->id)>{{ $s->code }} · {{ $s->displayName() }}</option>
                @endforeach
            </select></label>
        <label class="fl wide"><span>{{ __('hrm.adv_note') }}</span>
            <input type="text" name="note" maxlength="300" value="{{ old('note') }}"></label>
        <p style="color:var(--muted);font-size:11px;margin:8px 0 0">{{ __('hrm.adv_split_hint') }}</p>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgAdvance')">{{ __('common.cancel') }}</button>
            <button class="btn green" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>

<dialog id="dlgPenalty">
    <form class="dlg" method="POST" action="{{ route('hr.penalties.store') }}">
        @csrf
        <h4>⚠️ {{ __('hrm.pen_new') }}</h4>
        <label class="fl wide"><span>{{ __('hr.employee') }}</span>
            <select name="employee_id" required>
                <option value="">—</option>
                @foreach ($employees as $e)
                    <option value="{{ $e->id }}">{{ $e->code }} · {{ $e->displayName() }}</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('common.date') }}</span>
            <input type="date" name="date" value="{{ now()->toDateString() }}" required></label>
        <label class="fl"><span>{{ __('common.amount') }}</span>
            <input type="number" name="amount" step="0.01" min="0.01" required></label>
        <label class="fl wide"><span>{{ __('hrm.pen_reason') }}</span>
            <input type="text" name="reason" maxlength="200" required></label>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgPenalty')">{{ __('common.cancel') }}</button>
            <button class="btn green" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>
@endif

@endsection
