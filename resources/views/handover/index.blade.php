@extends('layouts.system')

@section('title', __('handover.title'))

@section('actions')
    @if (\App\Support\Access::allows(auth()->user(), 'handover.index.log'))
        <a class="btn" href="{{ route('handover.index.log') }}">📋 {{ __('handover.log') }}</a>
    @endif
    {{-- تصدير سيرفري — كلمة export في اللينك بتخفي زرار إكسيل الشاشة --}}
    @if (\App\Support\Access::allows(auth()->user(), 'handover.index.export'))
        <a class="btn sm green" href="{{ route('handover.index.export') }}">⬇ {{ __('ui.export_all') }}</a>
    @endif
@endsection

@section('content')
<style>
.ho-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px}
.ho-card{display:block;color:inherit;text-decoration:none;margin:0}
.ho-card:hover{border-color:var(--royal-blue)}
.ho-bar{height:8px;border-radius:99px;background:var(--card2);overflow:hidden;margin:10px 0 6px}
.ho-bar > span{display:block;height:100%;background:linear-gradient(135deg, #12399B 0%, #602D90 100%)}
.ho-row{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-top:8px}
.ho-pct{font-size:22px;font-weight:800;font-variant-numeric:tabular-nums}
.ho-meta{font-size:12px;color:var(--muted)}
.ho-late{color:var(--red);font-weight:700}
</style>

<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ __('handover.overall') }}</div>
        <div class="val num">{{ $overall['pct'] }}%</div>
        <div class="sub2">{{ __('handover.marked_of', ['marked' => $overall['marked'], 'total' => $overall['total']]) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('handover.col_module') }}</div>
        <div class="val num">{{ $modules->count() }}</div>
        <div class="sub2">{{ __('handover.complete_n', ['n' => $modules->where('progress.pct', 100)->count()]) }}</div>
    </div>
</div>

<div class="card">
    <h3>🎓 {{ __('handover.title') }} <span class="side">{{ __('handover.intro') }}</span></h3>

    @if ($modules->isEmpty())
        <div style="text-align:center;color:var(--muted);padding:28px">{{ __('handover.no_modules') }}</div>
    @else
        <div class="ho-grid">
            @foreach ($modules as $m)
                @php
                    $p = $m->progress;
                    $late = $m->deadline && $m->deadline->lt(today()) && $p['pct'] < 100;
                @endphp
                <a class="card ho-card" href="{{ route('handover.index.show', $m) }}">
                    <div style="display:flex;justify-content:space-between;gap:8px;align-items:baseline">
                        <b>{{ \App\Support\Access::GROUP_ICONS[$m->nav_group_key ?? ''] ?? '•' }} {{ $m->displayName() }}</b>
                        <span class="ho-pct">{{ $p['pct'] }}%</span>
                    </div>
                    <div class="ho-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $p['pct'] }}"
                         aria-label="{{ $m->displayName() }}"><span style="width:{{ $p['pct'] }}%"></span></div>
                    <div class="ho-meta">{{ __('handover.marked_of', ['marked' => $p['marked'], 'total' => $p['total']]) }}</div>
                    <div class="ho-row">
                        <span class="badge {{ ['pending' => 'b-gray', 'in_progress' => 'b-orange', 'done' => 'b-green'][$m->test_status] ?? 'b-gray' }}">
                            {{ __('handover.test_status') }}: {{ __('handover.test_'.$m->test_status) }}</span>
                        <span class="badge {{ ['no' => 'b-gray', 'partial' => 'b-orange', 'yes' => 'b-green'][$m->live_status] ?? 'b-gray' }}">
                            {{ __('handover.live_'.$m->live_status) }}</span>
                    </div>
                    <div class="ho-meta" style="margin-top:6px">
                        {{ __('handover.deadline') }}:
                        @if ($m->deadline)
                            <span class="num {{ $late ? 'ho-late' : '' }}">{{ $m->deadline->format('Y-m-d') }}</span>
                            @if ($late) <span class="badge b-red">{{ __('handover.overdue') }}</span> @endif
                        @else
                            {{ __('handover.no_deadline') }}
                        @endif
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>
@endsection
