{{--
    ٩ — التشغيل الفعلي (الخط ٩، المهمة ٩ — D13): زرار التشغيل (أدمن، بعد
    موافقة المعاينة)، وشريط تقدّم حقيقي بيقرا `gl.golive.run.progress` كل
    ١٫٥ ثانية طول ما الطلب شغّال، وبعد النجاح روابط التقارير.
--}}
@php
    $phaseLabels = [];
    foreach ($phases as $ph) {
        $phaseLabels[$ph] = __('golive.s9_phase_'.$ph);
    }
    $runCfg = json_encode([
        'url' => route('gl.golive.run.progress'),
        'phases' => $phaseLabels,
        'confirm' => __('golive.s9_run_confirm'),
        'running' => __('golive.s9_running'),
        'live' => $progress['status'] === 'running',
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
    $pct = $progress['total'] > 0 ? (int) round(100 * $progress['done'] / $progress['total']) : 0;
@endphp

<div class="alert info" style="margin-bottom:10px"><span>🚀</span><span>{{ $note }}</span></div>
<p style="font-size:12.5px;color:var(--muted);margin:0 0 10px">{{ __('golive.s9_run_hint') }}</p>

@if ($canAdmin && $canRun)
    <form method="POST" action="{{ route('gl.golive.run') }}" id="golive-run-form" style="margin-bottom:10px">
        @csrf
        <button class="btn green" type="submit" id="golive-run-btn">🚀 {{ __('golive.s9_run') }}</button>
    </form>
@endif

<div id="golive-progress" style="margin:8px 0 12px;{{ $progress['status'] === 'running' ? '' : 'display:none' }}">
    <div style="display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:4px">
        <span id="golive-phase">{{ $phaseLabels[$progress['phase']] ?? $progress['phase'] }}</span>
        <span class="num" id="golive-pct">{{ $pct }}%</span>
    </div>
    <div style="height:10px;border-radius:6px;background:var(--card2);overflow:hidden">
        <div id="golive-bar" style="height:100%;width:{{ $pct }}%;background:var(--brand-gradient,#12399B);transition:width .4s"></div>
    </div>
    <div id="golive-msg" style="font-size:12px;color:var(--muted);margin-top:4px">{{ __('golive.s9_running') }}</div>
</div>

@if ($last)
    <h4 style="margin:12px 0 6px">{{ __('golive.s9_last') }}</h4>
    <div class="kpis">
        <div class="kpi"><div class="lbl">{{ __('golive.s8_status') }}</div>
            <div class="val"><span class="badge {{ $last->status === 'ok' ? 'b-green' : 'b-red' }}">{{ __('golive.s8_'.$last->status) }}</span></div>
            <div class="sub2 num">#{{ $last->id }} · {{ $last->created_at?->format('Y-m-d H:i') }}</div></div>
        @if ($last->status === 'ok')
            <div class="kpi"><div class="lbl">{{ __('golive.col_created') }}</div><div class="val num">{{ $last->summary['created'] ?? 0 }}</div>
                <div class="sub2 num">{{ number_format((float) ($last->summary['created_amount'] ?? 0), 2) }}</div></div>
            <div class="kpi"><div class="lbl">{{ __('golive.col_changed') }}</div><div class="val num">{{ $last->summary['changed'] ?? 0 }}</div>
                <div class="sub2 num">{{ number_format((float) ($last->summary['changed_delta'] ?? 0), 2) }}</div></div>
            <div class="kpi"><div class="lbl">{{ __('golive.col_deleted') }}</div><div class="val num">{{ $last->summary['deleted'] ?? 0 }}</div>
                <div class="sub2 num">{{ number_format((float) ($last->summary['deleted_amount'] ?? 0), 2) }}</div></div>
        @endif
    </div>
    @if ($last->status !== 'ok' && $last->error)
        <div class="alert" style="margin-top:8px"><span>⛔</span><span>{{ $last->error }}</span></div>
    @endif
    @if ($last->status === 'ok')
        <h4 style="margin:12px 0 6px">{{ __('golive.s9_reports') }}</h4>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <a class="btn" href="{{ route('gl.trial_balance') }}">⚖️ {{ __('golive.s9_trial_balance') }}</a>
            <a class="btn" href="{{ route('gl.balance_sheet') }}">🏛️ {{ __('golive.s9_balance_sheet') }}</a>
            <a class="btn" href="{{ route('gl.income') }}">📈 {{ __('golive.s9_income') }}</a>
            <a class="btn" href="{{ route('costing.pnl') }}">🧮 {{ __('golive.s9_pnl') }}</a>
        </div>
    @endif
@endif

<script>
(function () {
    var cfg = {!! $runCfg !!};
    var box = document.getElementById('golive-progress');
    var bar = document.getElementById('golive-bar');
    var pct = document.getElementById('golive-pct');
    var phase = document.getElementById('golive-phase');
    var msg = document.getElementById('golive-msg');
    var timer = null;

    function paint(p) {
        var total = p.total > 0 ? p.total : 1;
        var v = Math.max(0, Math.min(100, Math.round(100 * p.done / total)));
        bar.style.width = v + '%';
        pct.textContent = v + '%';
        phase.textContent = cfg.phases[p.phase] || p.phase;
        msg.textContent = p.status === 'running' ? cfg.running : (p.message || '');
    }

    function poll() {
        fetch(cfg.url, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'})
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (p) {
                if (!p) { return; }
                paint(p);
                if (p.status !== 'running' && timer && !cfg.submitted) {
                    clearInterval(timer);
                    timer = null;
                }
            })
            .catch(function () {});
    }

    function start() {
        box.style.display = '';
        if (!timer) { timer = setInterval(poll, 1500); }
        poll();
    }

    var form = document.getElementById('golive-run-form');
    if (form) {
        form.addEventListener('submit', function (e) {
            if (!window.confirm(cfg.confirm)) { e.preventDefault(); return; }
            cfg.submitted = true;
            document.getElementById('golive-run-btn').disabled = true;
            paint({phase: 'start', done: 0, total: 4, status: 'running', message: ''});
            start();
        });
    }
    if (cfg.live) { start(); }
})();
</script>
