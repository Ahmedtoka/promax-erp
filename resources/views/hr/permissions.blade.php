@extends('layouts.system')

{{--
    الأذونات بالساعة (٧/١١/٢٠٢٦): تأخير الصبح · انصراف بدري · خروج في النص.
    الاعتماد على السيرفر (`isManagedBy`) — الزرار بيبان بس للي يقدر.
--}}

@section('title', __('fp.perm_title'))

@php
    $badge = ['pending' => 'b-orange', 'approved' => 'b-green', 'rejected' => 'b-red', 'cancelled' => 'b-gray'];
    $me = auth()->user();
@endphp

@section('actions')
    <a class="btn sm green" href="{{ route('hr.permissions', array_merge(request()->only('status'), $range->query(), ['export' => 1])) }}">⬇ {{ __('ui.export_all') }}</a>
@endsection

@section('content')

<div class="card">
    <h3>⏱️ {{ __('fp.perm_new') }} <span class="side">{{ __('fp.perm_sub') }}</span></h3>
    <form method="POST" action="{{ route('hr.permissions.store') }}" class="searchbar" data-noprint>
        @csrf
        <label class="fl wide"><span>{{ __('hr.employee') }}</span>
            <select name="employee_id" required>
                @foreach ($employees as $e)<option value="{{ $e->id }}" @selected(old('employee_id') == $e->id)>{{ $e->code }} · {{ $e->displayName() }}</option>@endforeach
            </select></label>
        <label class="fl"><span>{{ __('hr.date') }}</span><input type="date" name="date" value="{{ old('date', today()->toDateString()) }}" required></label>
        <label class="fl"><span>{{ __('fp.perm_kind') }}</span>
            <select name="kind">@foreach (\App\Models\HrPermission::KINDS as $k)<option value="{{ $k }}">{{ __('fp.perm_kind_'.$k) }}</option>@endforeach</select></label>
        <label class="fl"><span>{{ __('fp.perm_minutes') }}</span><input type="number" name="minutes" min="5" max="480" step="5" value="{{ old('minutes', 60) }}" required></label>
        <label class="fl wide"><span>{{ __('fp.perm_reason') }}</span><input type="text" name="reason" maxlength="300" value="{{ old('reason') }}"></label>
        <button class="btn green" type="submit">➕ {{ __('fp.perm_new') }}</button>
    </form>
</div>

<div class="card">
    <h3>📋 {{ __('fp.perm_title') }}</h3>
    <form method="GET" class="searchbar" data-noprint>
        <label class="fl"><span>{{ __('fp.status') }}</span>
            <select name="status" onchange="this.form.submit()">
                <option value="">{{ __('fp.all') }}</option>
                @foreach (\App\Models\HrPermission::STATUSES as $st)<option value="{{ $st }}" @selected(request('status') === $st)>{{ __('fp.perm_status_'.$st) }}</option>@endforeach
            </select></label>
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue(), 'all' => false])
        <button class="btn green" type="submit">{{ __('common.filter') }}</button>
        <a class="btn" href="{{ route('hr.permissions') }}">{{ __('common.clear') }}</a>
    </form>

    <div class="tablewrap"><table>
        <tr><th>{{ __('hr.date') }}</th><th style="text-align:start">{{ __('hr.employee') }}</th><th>{{ __('fp.perm_kind') }}</th><th>{{ __('fp.perm_minutes') }}</th>
            <th style="text-align:start">{{ __('fp.perm_reason') }}</th><th>{{ __('fp.status') }}</th><th>{{ __('fp.perm_decided_by') }}</th><th></th></tr>
        @forelse ($rows as $p)
            @php
                $canDecide = $p->status === 'pending' && $p->employee?->isManagedBy($me) && (int) $p->employee->user_id !== $me->id;
                $canCancel = $p->status === 'pending' && ((int) $p->requested_by === $me->id || $p->employee?->isManagedBy($me));
            @endphp
            <tr>
                <td class="num" dir="ltr">{{ $p->date->format('Y-m-d') }}</td>
                <td style="text-align:start">{{ $p->employee?->code }} · {{ $p->employee?->displayName() }}</td>
                <td>{{ $p->kindLabel() }}</td>
                <td class="num">{{ $p->minutes }}</td>
                <td style="text-align:start">{{ $p->reason }}</td>
                <td><span class="badge {{ $badge[$p->status] ?? 'b-gray' }}">{{ $p->statusLabel() }}</span></td>
                <td>{{ $p->decider?->name ?? '—' }}</td>
                <td data-noprint style="white-space:nowrap">
                    @if ($canDecide)
                        @foreach (['approved' => 'perm_approve', 'rejected' => 'perm_reject'] as $dec => $lbl)
                            <form method="POST" action="{{ route('hr.permissions.decide', $p) }}" style="display:inline">
                                @csrf<input type="hidden" name="decision" value="{{ $dec }}">
                                <button class="btn sm {{ $dec === 'approved' ? 'green' : '' }}" type="submit">{{ __('fp.'.$lbl) }}</button>
                            </form>
                        @endforeach
                    @endif
                    @if ($canCancel)
                        <form method="POST" action="{{ route('hr.permissions.decide', $p) }}" style="display:inline">
                            @csrf<input type="hidden" name="decision" value="cancelled">
                            <button class="btn sm" type="submit">{{ __('fp.perm_cancel') }}</button>
                        </form>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="8" class="muted">{{ __('fp.perm_none') }}</td></tr>
        @endforelse
    </table></div>
    {{ $rows->links() }}
</div>
@endsection
