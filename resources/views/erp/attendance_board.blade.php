@extends('layouts.system')

{{--
    بورد الحضور — مين شغال دلوقتي (2026-08-08 · إعادة تصميم ٩/٨).

    ⚠️ الصفحة بتبدأ من **كل الموظفين النشطين** مش من جدول الحضور —
    السؤال الأهم هو «مين مش شغال»، واللي مسجّلش حضور مالوش صف أصلاً.

    ⚠️ **كروت الأونلاين فوق الجدول** (طلب المالك ٩/٨): الشغالين
    دلوقتي قدام عينك — جه امتى وبقاله قد إيه — والعدّاد بيعدّ لايف
    بالجافاسكربت، والصفحة بتترفرش كل دقيقة (لو التاب ظاهر) عشان
    اللي عمل بصمة جديدة يبان من غير F5.
--}}

@section('title', __('hr.attendance'))

@section('actions')
    <a class="btn" href="{{ route('erp.attendance.log') }}">📋 {{ __('hr.log') }}</a>
    <a class="btn {{ $needsReview ? 'red' : '' }}" href="{{ route('erp.attendance.review') }}">
        ⏰ {{ __('hr.review') }}@if ($needsReview) ({{ $needsReview }})@endif
    </a>
@endsection

@section('content')

{{-- ═══ الفلتر + السامري ═══ --}}
<form method="GET" class="filters" style="margin-bottom:14px">
    <div style="flex:0 1 210px">
        <label class="f">{{ __('hr.date') }}</label>
        <input type="date" name="date" value="{{ $date }}" onchange="this.form.submit()">
    </div>
    <div style="flex:1"></div>
    <div style="flex:0 0 auto;font-size:11px;color:var(--muted);align-self:center">
        🔄 {{ __('hr.auto_refresh') }}
    </div>
</form>

{{-- ⚠️ الكروت **كليكبل** (طلب المالك ٩/٨): الضغطة بتفلتر الجدول
     على الحالة دي، والضغط على الكارت النشط تاني بيشيل الفلتر. --}}
@php $kpiUrl = fn ($s) => route('erp.attendance', array_filter(['date' => $date, 'state' => $state === $s ? null : $s])); @endphp
<div class="kpis" style="margin-bottom:14px">
    <a class="kpi {{ $state === 'working' ? 'on' : '' }}" href="{{ $kpiUrl('working') }}">
        <div class="lbl">🟢 {{ __('hr.working_now') }}</div><div class="val" style="color:#16A34A">{{ $working }}</div></a>
    <a class="kpi {{ $state === 'break' ? 'on' : '' }}" href="{{ $kpiUrl('break') }}">
        <div class="lbl">⏸️ {{ __('hr.on_break_now') }}</div><div class="val" style="color:#B86E00">{{ $onBreak }}</div></a>
    <a class="kpi {{ $state === 'done' ? 'on' : '' }}" href="{{ $kpiUrl('done') }}">
        <div class="lbl">✅ {{ __('hr.done_today') }}</div><div class="val">{{ $done }}</div></a>
    <a class="kpi {{ $state === 'off' ? 'on' : '' }}" href="{{ $kpiUrl('off') }}">
        <div class="lbl">⚪ {{ __('hr.not_in_yet') }}</div><div class="val" style="color:#B00020">{{ $notIn }}</div></a>
</div>

{{-- ═══ الأونلاين دلوقتي — كروت (طلب المالك ٩/٨) ═══ --}}
@php $online = $rows->filter(fn ($r) => in_array($r['state'], ['working', 'break'], true))->values(); @endphp
<div class="card">
    <h3>🟢 {{ __('hr.online_now') }} <span class="side">{{ $online->count() }}</span></h3>

    @if ($online->isEmpty())
        <div class="empty">{{ __('hr.nobody_online') }}</div>
    @else
        <div class="on-grid">
            @foreach ($online as $r)
                <div class="on-card {{ $r['state'] === 'break' ? 'is-break' : '' }}">
                    <div class="on-top">
                        <div>
                            <div class="on-name">{{ $r['user']->displayName() }}</div>
                            <div class="on-role">{{ $r['user']->roleLabel() }} · <span dir="ltr">{{ $r['user']->code }}</span></div>
                        </div>
                        @if ($r['state'] === 'break')
                            <span class="pill warn">⏸️ {{ __('hr.state_break') }}</span>
                        @else
                            <span class="pill good"><span class="on-dot"></span> {{ __('hr.online') }}</span>
                        @endif
                    </div>
                    <div class="on-stats">
                        <div>
                            <div class="k">{{ __('hr.in_since') }}</div>
                            <div class="v" dir="ltr">{{ $r['in']?->format('h:i A') ?? '—' }}</div>
                        </div>
                        <div>
                            <div class="k">{{ __('hr.working_for') }}</div>
                            {{-- العدّاد بيعدّ لايف بالدقيقة — البداية من رقم
                                 السيرفر (بيطرح البريكات) والجافاسكربت بتكمّل --}}
                            <div class="v" dir="ltr"
                                 @if ($r['state'] === 'working') data-tick="{{ $r['day']?->liveMinutes() ?? 0 }}" @endif
                            >{{ $r['worked'] }}</div>
                        </div>
                        @if (($r['breaks'] ?? '—') !== '—' && ($r['day']?->break_minutes ?? 0) > 0)
                            <div>
                                <div class="k">{{ __('hr.breaks') }}</div>
                                <div class="v" dir="ltr">{{ $r['breaks'] }}</div>
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>

{{-- ═══ كل الفريق — الجدول ═══ --}}
<div class="card">
    <h3>🧑‍💼 {{ __('hr.all_team') }}
        <span class="side">{{ $filtered->count() }}@if ($state) / {{ $rows->count() }}@endif</span>
        @if ($state)
            <a class="btn sm" href="{{ route('erp.attendance', ['date' => $date]) }}" style="margin-inline-start:8px">✕ {{ __('common.clear') }}</a>
        @endif
    </h3>
    <div class="tablewrap">
    <table class="att-tbl">
        <thead>
            <tr>
                <th style="text-align:start">{{ __('hr.employee') }}</th>
                <th>{{ __('hr.role') }}</th>
                <th>{{ __('hr.state') }}</th>
                <th>{{ __('hr.first_in') }}</th>
                <th>{{ __('hr.last_out') }}</th>
                <th>{{ __('hr.worked') }}</th>
                <th>{{ __('hr.breaks') }}</th>
                <th>{{ __('hr.sessions') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($filtered as $r)
                <tr>
                    <td>
                        <strong>{{ $r['user']->displayName() }}</strong>
                        <div class="side" style="font-size:11px" dir="ltr">{{ $r['user']->code }}</div>
                    </td>
                    <td>{{ $r['user']->roleLabel() }}</td>
                    <td>
                        @if ($r['state'] === 'working')
                            <span class="pill good">🟢 {{ __('hr.state_working') }}</span>
                        @elseif ($r['state'] === 'break')
                            <span class="pill warn">⏸️ {{ __('hr.state_break') }}</span>
                        @elseif (($r['day']?->sessions ?? 0) > 0)
                            <span class="pill">✅ {{ __('hr.done_today') }}</span>
                        @else
                            <span class="pill" style="opacity:.6">⚪ {{ __('hr.not_in_yet') }}</span>
                        @endif

                        @if ($r['status'] === \App\Models\AttendanceDay::STATUS_AUTO)
                            <span class="pill red" style="font-size:10px">{{ __('hr.auto_closed_mark') }}</span>
                        @endif
                    </td>
                    <td dir="ltr">{{ $r['in']?->format('h:i A') ?? '—' }}</td>
                    <td dir="ltr">{{ $r['out']?->format('h:i A') ?? '—' }}</td>
                    <td dir="ltr" style="font-weight:800">{{ $r['worked'] }}</td>
                    <td dir="ltr">{{ $r['breaks'] }}</td>
                    <td>{{ $r['day']?->sessions ?? 0 }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    </div>
</div>

@endsection

@section('scripts')
<style>
/* ═══ كروت الأونلاين ═══ */
.on-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px}
.on-card{
  border:1px solid var(--border);border-radius:var(--r-md);
  padding:12px 14px;background:var(--card);
  border-inline-start:4px solid var(--green);
}
.on-card.is-break{border-inline-start-color:var(--orange)}
.on-top{display:flex;justify-content:space-between;align-items:flex-start;gap:8px}
.on-name{font-weight:900;font-size:13.5px}
.on-role{font-size:10.5px;color:var(--muted);margin-top:2px}
.on-dot{
  display:inline-block;width:7px;height:7px;border-radius:99px;
  background:#16A34A;margin-inline-end:4px;vertical-align:1px;
  animation:onPulse 1.6s ease-in-out infinite;
}
@keyframes onPulse{0%,100%{opacity:1}50%{opacity:.35}}
.on-stats{display:flex;gap:18px;margin-top:11px}
.on-stats .k{font-size:9.5px;color:var(--muted);font-weight:700;letter-spacing:.3px}
.on-stats .v{font-size:14px;font-weight:900;margin-top:2px;font-variant-numeric:tabular-nums}
.st-tbl th, .st-tbl td { text-align:center }
</style>
<script>
// «بقاله شغال» بيعدّ قدامك بالدقيقة — البداية من رقم السيرفر
// (اللي طارح البريكات)، والزيادة وقت شاشة بس، فأدق رقم دايماً
// بييجي مع الريفريش.
(function () {
    const started = Date.now();
    const cells = document.querySelectorAll('[data-tick]');

    function paint() {
        const extra = Math.floor((Date.now() - started) / 60000);
        cells.forEach(function (c) {
            const m = parseInt(c.dataset.tick, 10) + extra;
            c.textContent = Math.floor(m / 60) + ':' + String(m % 60).padStart(2, '0');
        });
    }
    paint();
    setInterval(paint, 30000);

    // ريفريش كل دقيقة لو التاب ظاهر — بصمة جديدة تبان من غير F5
    setInterval(function () {
        if (!document.hidden) window.location.reload();
    }, 60000);
})();
</script>
@endsection
