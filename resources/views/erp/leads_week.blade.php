@extends('layouts.system')

{{--
    متابعة أسبوع المحتملين (سكشن المحتملين ٢٦/٨) — عين المدير:

    لكل مندوب: اتجدوله كام ← راح فعلاً كام (تأكيد البيانات في نفس
    اليوم هو الإثبات — أوتوماتيك من الميدان) ← فايتله كام (أحمر) ←
    كسب كام. وتحت كل مندوب تفصيلة اليوم بيوم: الليد باسمه وحالته —
    الفايت من غير زيارة صف أحمر. «الحركة جمب بحركة».
--}}

@section('title', __('lead.week_title'))

@section('actions')
    <a class="btn" href="{{ route('erp.leads') }}">← {{ __('lead.page') }}</a>
    <a class="btn gold" href="{{ route('erp.leads.planner', ['week' => $start->toDateString()]) }}">
        📅 {{ __('lead.planner_title') }}</a>
@endsection

@section('content')

{{-- ═══ الأسبوع ═══ --}}
<div class="card" style="margin-bottom:14px;padding:12px 16px">
    <form method="GET" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
        <div style="flex:0 1 190px">
            <label class="f">{{ __('lead.week_of') }}</label>
            <input type="date" name="week" value="{{ $start->toDateString() }}" onchange="this.form.submit()">
        </div>
        <a class="btn" href="{{ route('erp.leads.week', ['week' => $start->copy()->subWeek()->toDateString()]) }}">→</a>
        <a class="btn" href="{{ route('erp.leads.week', ['week' => $start->copy()->addWeek()->toDateString()]) }}">←</a>
        <span style="font-size:12px;color:var(--muted)" dir="ltr">
            {{ $start->format('d/m') }} — {{ $days->last()->format('d/m') }}</span>
    </form>
</div>

@if ($rows->isEmpty())
    <div class="card"><div class="empty">{{ __('lead.week_empty') }}</div></div>
@endif

@foreach ($rows as $row)
<div class="card" style="margin-bottom:14px">
    {{-- ═══ سطر المندوب — الأرقام الأربعة ═══ --}}
    <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin-bottom:10px">
        @include('partials._avatar', ['u' => $row['user'], 'size' => 34])
        <b style="font-size:14px">{{ $row['user']->name }}</b>
        <span class="badge b-blue">📅 {{ $row['planned'] }} {{ __('lead.w_planned') }}</span>
        <span class="badge b-green">✓ {{ $row['visited'] }} {{ __('lead.w_visited') }}</span>
        @if ($row['missed'] > 0)
            <span class="badge b-red">⚠️ {{ $row['missed'] }} {{ __('lead.w_missed') }}</span>
        @endif
        @if ($row['won'] > 0)
            <span class="badge b-purple">🏆 {{ $row['won'] }} {{ __('lead.w_won') }}</span>
        @endif
    </div>

    {{-- ═══ اليوم بيوم ═══ --}}
    <div class="lw-grid">
        @foreach ($days as $d)
            @php $dayPlans = $row['byDay'][$d->toDateString()] ?? collect(); @endphp
            <div class="lw-day @if($d->isToday()) today @endif">
                <div style="font-size:10.5px;font-weight:900;margin-bottom:6px">
                    {{ __('lead.wd_'.$d->dayOfWeek) }} <span dir="ltr">{{ $d->format('d/m') }}</span></div>
                @forelse ($dayPlans as $p)
                    @php
                        $ok = $visitedFn($p);
                        $late = $p->plan_date->lt(today()) && ! $ok;
                    @endphp
                    <a href="{{ route('erp.leads', ['search' => $p->lead->number]) }}"
                       class="lw-item {{ $ok ? 'ok' : ($late ? 'late' : '') }}">
                        {{ $ok ? '✓' : ($late ? '✗' : '•') }} {{ $p->lead->displayName() }}
                        @if ($p->lead->status === 'won')<span>🏆</span>@endif
                    </a>
                @empty
                    <div style="font-size:10px;color:var(--muted)">—</div>
                @endforelse
            </div>
        @endforeach
    </div>
</div>
@endforeach

<div class="dash-hint">{{ __('lead.week_hint') }}</div>

@endsection

@section('scripts')
<style>
.lw-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:8px}
.lw-day{background:var(--card2,#F7F7FA);border:1px solid var(--border);border-radius:10px;padding:8px}
.lw-day.today{border-color:var(--royal-blue,#12399B);background:#F2F6FF}
.lw-item{display:block;font-size:10.5px;padding:4px 7px;border-radius:7px;margin-bottom:4px;
    text-decoration:none;color:inherit;background:#fff;border:1px solid var(--border)}
.lw-item.ok{background:#E7F7EE;border-color:#BBE5CB;color:#0F7A38;font-weight:700}
.lw-item.late{background:#FDECEC;border-color:#F3C7C7;color:#B00020;font-weight:700}
@media (max-width:1100px){.lw-grid{grid-template-columns:repeat(4,1fr)}}
</style>
@endsection
