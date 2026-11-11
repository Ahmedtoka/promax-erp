@extends('layouts.system')

{{--
    متابعة خطوط السير (١١/١١/٢٠٢٦) — نفس «متابعة أسبوع المحتملين» للعملاء:

    لكل مندوب: مخطط (المستحق بالتردد) ← اتزار (زيارة مقفولة من نفس
    المندوب في نفس اليوم) ← فات (أحمر) ← بره الخطة. وتحت كل مندوب
    الأسبوع يوم بيوم من السبت للجمعة. الحساب في `RoutePlannerController::follow`.
--}}

@section('title', __('journey.wk_title'))

@section('actions')
    <a class="btn" href="{{ route('ops.portfolio') }}">← {{ __('nav.leads_portfolio') }}</a>
    <a class="btn gold" href="{{ route('ops.journeys', array_filter(['rep' => $only, 'week' => $start->toDateString()])) }}">
        📅 {{ __('journey.week_btn') }}</a>
@endsection

@section('content')

@include('partials._module_tabs', ['module' => 'clients'])

<div class="card" style="margin-bottom:14px;padding:12px 16px">
    <form method="GET" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
        <label class="fl wide"><span>{{ __('ui.l_rep') }}</span>
            <select name="rep" onchange="this.form.submit()">
                <option value="">{{ __('journey.wk_all_reps') }}</option>
                @foreach ($reps as $r)
                    <option value="{{ $r->id }}" @selected($only === $r->id)>{{ $r->displayName() }} — {{ $r->roleLabel() }}</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('journey.wk_week') }}</span>
            <input type="date" name="week" value="{{ $start->toDateString() }}" onchange="this.form.submit()"></label>
        <a class="btn sm" href="{{ route('ops.journeys.week', array_filter(['rep' => $only, 'week' => $start->copy()->subWeek()->toDateString()])) }}">→</a>
        <a class="btn sm" href="{{ route('ops.journeys.week', array_filter(['rep' => $only, 'week' => $start->copy()->addWeek()->toDateString()])) }}">←</a>
        <span style="font-size:12px;color:var(--muted)" dir="ltr">{{ $start->format('d/m') }} — {{ $days->last()->format('d/m') }}</span>
    </form>
</div>

@if ($cards->isEmpty())
    <div class="card"><div class="empty">{{ __('journey.wk_empty') }}</div></div>
@else
    {{-- ═══ ملخص المناديب — جدول (إكسيل) ═══ --}}
    <div class="card" style="margin-bottom:14px">
        <table id="wkSummary">
            <thead><tr>
                <th>{{ __('ui.l_rep') }}</th><th>{{ __('journey.wk_planned') }}</th>
                <th>{{ __('journey.wk_done') }}</th><th>{{ __('journey.wk_missed') }}</th>
                <th>{{ __('journey.wk_off') }}</th><th>{{ __('journey.wk_pct') }}</th>
            </tr></thead>
            <tbody>
                @foreach ($cards as $c)
                    <tr>
                        <td>{{ $c['rep']->displayName() }}</td><td>{{ $c['planned'] }}</td>
                        <td>{{ $c['done'] }}</td><td>{{ $c['missed'] }}</td>
                        <td>{{ $c['off'] }}</td><td>{{ $c['pct'] }}%</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

@foreach ($cards as $c)
<div class="card" style="margin-bottom:14px">
    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:10px">
        @include('partials._avatar', ['u' => $c['rep'], 'size' => 32])
        <a href="{{ route('ops.journeys', ['rep' => $c['rep']->id, 'week' => $start->toDateString()]) }}"><b style="font-size:13.5px">{{ $c['rep']->displayName() }}</b></a>
        <span class="badge b-blue">📅 {{ $c['planned'] }} {{ __('journey.wk_planned') }}</span>
        <span class="badge b-green">✓ {{ $c['done'] }} {{ __('journey.wk_done') }}</span>
        @if ($c['missed'] > 0)
            <span class="badge b-red">⚠️ {{ $c['missed'] }} {{ __('journey.wk_missed') }}</span>
        @endif
        @if ($c['off'] > 0)
            <span class="badge b-purple">➕ {{ $c['off'] }} {{ __('journey.wk_off') }}</span>
        @endif
        <span class="badge b-gray">{{ $c['pct'] }}%</span>
    </div>

    <div class="lw-grid">
        @foreach ($days as $d)
            @php $g = $c['grid'][$d->toDateString()]; @endphp
            <div class="lw-day @if($d->isToday()) today @endif">
                <div style="font-size:10.5px;font-weight:900;margin-bottom:6px;display:flex;gap:4px;align-items:center">
                    <a href="{{ route('ops.rep_day', ['user' => $c['rep']->id, 'date' => $d->toDateString()]) }}" style="color:inherit">
                        {{ __('journey.day_'.$d->dayOfWeek) }} <span dir="ltr">{{ $d->format('d/m') }}</span></a>
                    @if ($g['off'] > 0)<span class="badge b-purple" style="font-size:9px">+{{ $g['off'] }}</span>@endif
                </div>
                @forelse ($g['items'] as $it)
                    <a href="{{ route('erp.clients.show', $it['cid']) }}" class="lw-item {{ $it['state'] }}"
                       title="{{ __('journey.st_'.$it['state']) }}">
                        {{ ['done' => '✓', 'missed' => '✗', 'in_visit' => '⏳', 'pending' => '•'][$it['state']] }} {{ $it['name'] }}
                    </a>
                @empty
                    <div style="font-size:10px;color:var(--muted)">—</div>
                @endforelse
            </div>
        @endforeach
    </div>
</div>
@endforeach

<div class="dash-hint">{{ __('journey.wk_hint') }}</div>

@endsection

@section('scripts')
<style>
.lw-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:8px}
.lw-day{background:var(--card2,#F7F7FA);border:1px solid var(--border);border-radius:10px;padding:8px;min-width:0}
.lw-day.today{border-color:var(--royal-blue,#12399B);background:#F2F6FF}
.lw-item{display:block;font-size:10.5px;padding:4px 7px;border-radius:7px;margin-bottom:4px;
    text-decoration:none;color:inherit;background:#fff;border:1px solid var(--border);
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.lw-item.done{background:#E7F7EE;border-color:#BBE5CB;color:#0F7A38;font-weight:700}
.lw-item.missed{background:#FDECEC;border-color:#F3C7C7;color:#B00020;font-weight:700}
.lw-item.in_visit{background:#FFF6E0;border-color:#F0D48A;color:#8A6D00;font-weight:700}
@media (max-width:1100px){.lw-grid{grid-template-columns:repeat(4,1fr)}}
</style>
@endsection
