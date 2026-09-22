@extends('layouts.system')

{{--
    مراجعة الشيفتات اللي السيستم قفلها (2026-08-08).

    ⚠️ الصفحة دي هي اللي بتخلي «القفل التلقائي» عادل: السيستم قفل
    على آخر ثانية في اليوم لأنه مش عارف، والمدير هو اللي بيحدد
    الساعات الحقيقية. السجل الخام ظاهر تحت كل صف كدليل.
--}}

@section('title', __('hr.review'))

@section('actions')
    <a class="btn" href="{{ route('erp.attendance') }}">📊 {{ __('hr.today_board') }}</a>
    <a class="btn" href="{{ route('erp.attendance.log') }}">📋 {{ __('hr.log') }}</a>
@endsection

{{-- (٢٢/٩) يوم فات وفضل مفتوح مابيعدّش لايف لحد دلوقتي — بيتعرض بدقايقه المسجّلة (نفس حساب سجل الحضور) --}}
@php $wl = fn ($d) => \App\Models\AttendanceDay::hhmm($d->date->toDateString() < today()->toDateString() ? (int) $d->worked_minutes : $d->liveMinutes()); @endphp

@section('content')

@if (session('ok'))
    <div class="alert good" style="margin-bottom:12px"><span>✅</span><span>{{ session('ok') }}</span></div>
@endif

@if ($errors->any())
    <div class="alert bad" style="margin-bottom:12px"><span>⚠️</span><span>{{ $errors->first() }}</span></div>
@endif

{{-- فلتر «من — إلى» (٩/٩/٢٠٢٦) على يوم الحضور — نفس عمود شاشة السجل --}}
<form method="GET" class="searchbar" data-noprint>
    @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue(), 'auto' => true])
    <button class="btn gold" type="submit">{{ __('common.filter') }}</button>
    <a class="btn" href="{{ route('erp.attendance.review') }}">{{ __('common.clear') }}</a>
</form>

@if ($rows->isEmpty())
    <div class="card"><div class="empty">{{ __('hr.no_review') }}</div></div>
@else
    <div class="alert warn" style="margin-bottom:12px">
        <span>⏰</span><span>{{ __('hr.review_count', ['n' => $rows->count()]) }}</span>
    </div>

    @foreach ($rows as $d)
        <div class="card">
            <h3>
                {{ $d->user?->displayName() ?? '—' }}
                <span class="side" dir="ltr">{{ $d->date->format('Y-m-d') }}</span>
            </h3>

            <div class="frow" style="align-items:flex-end">
                <div>
                    <label class="f">{{ __('hr.computed') }}</label>
                    <div dir="ltr" style="font-size:19px;font-weight:900">{{ $wl($d) }}</div>
                    <div class="side" style="font-size:11px">
                        {{ __('hr.breaks') }}: {{ \App\Models\AttendanceDay::hhmm($d->break_minutes) }}
                        · {{ __('hr.punches_count', ['n' => $d->punches->count()]) }}
                    </div>
                </div>

                <form method="POST" action="{{ route('erp.attendance.approve', $d) }}"
                      style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
                    @csrf
                    <div>
                        <label class="f">{{ __('hr.approved_minutes') }}</label>
                        <input type="text" name="hours" dir="ltr" placeholder="{{ $wl($d) }}"
                               pattern="\d{1,2}:[0-5]\d" style="width:110px;text-align:center;font-weight:800">
                        <div class="side" style="font-size:10.5px">{{ __('hr.approved_hint') }}</div>
                    </div>
                    <div>
                        <label class="f">{{ __('hr.approve_note') }}</label>
                        <input type="text" name="note" maxlength="300" style="width:260px">
                    </div>
                    <button class="btn primary" type="submit">✅ {{ __('hr.approve') }}</button>
                </form>
            </div>

            {{-- السجل الخام — الدليل اللي المدير بيقرر على أساسه --}}
            <div style="margin-top:12px;border-top:1px solid var(--line);padding-top:10px">
                <div class="side" style="font-size:11px;margin-bottom:6px">{{ __('hr.timeline') }}</div>
                <div style="display:flex;flex-wrap:wrap;gap:7px">
                    @foreach ($d->punches as $p)
                        <span class="pill" style="border-color:{{ $p->color() }};color:{{ $p->color() }}">
                            {{ $p->icon() }} {{ $p->typeLabel() }}
                            <span dir="ltr">{{ $p->at->format('h:i A') }}</span>
                            @if ($p->auto)<em style="font-size:10px">({{ __('hr.auto_closed_mark') }})</em>@endif
                            @if ($p->forced_by)<em style="font-size:10px">({{ __('hr.forced_out_note', ['by' => $p->forcedBy?->displayName() ?? '—']) }})</em>@endif
                        </span>
                    @endforeach
                </div>
            </div>
        </div>
    @endforeach
@endif

@endsection
