@extends('layouts.system')

@section('title', __('kpi.title'))

{{-- ═══ حاسبة العمولات والـKPI (٢٣ أغسطس ٢٠٢٦) ═══
     ترجمة شاشية لشيتات Dashboard + Rep/Manager/Director_Calculator:
     لكل قناة: جدول المناديب (تحقيق/درجة/معامل/أساسية/حافز/إجمالي)
     + صفّي المدير والمدير العام + كروت الإجماليات + فحوصات النموذج. --}}

@php
    $fmt = fn ($n) => number_format((float) $n);
    $f2 = fn ($n) => number_format((float) $n, 2);
    $pct = fn ($n, $d = 1) => number_format((float) $n * 100, $d).'%';
@endphp

@section('actions')
    @if (auth()->user()?->role === 'admin')
        <a class="btn" href="{{ route('erp.kpi.setup') }}">⚙️ {{ __('kpi.setup_btn') }}</a>
    @endif
    <a class="btn gold" href="{{ route('erp.kpi', ['period' => $period, 'export' => 1]) }}">⬇️ {{ __('rpt.export') }}</a>
@endsection

@section('content')

{{-- ═══ اختيار الشهر + فحوصات النموذج ═══ --}}
<div class="card" style="padding:12px 14px">
    <form method="GET" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
        <div>
            <label class="f">{{ __('kpi.period') }}</label>
            <input type="month" name="period" value="{{ $period }}">
        </div>
        <button class="btn gold" type="submit">🔍 {{ __('rpt.apply') }}</button>
        <div style="margin-inline-start:auto;display:flex;gap:6px;flex-wrap:wrap">
            @foreach ($checks as $c)
                <span class="badge {{ $c['pass'] ? 'b-green' : 'b-red' }}"
                      title="{{ __('kpi.check_'.$c['key']) }}{{ isset($c['channel']) ? ' — '.$c['channel'] : '' }}">
                    {{ $c['pass'] ? '✓' : '✗' }} {{ __('kpi.check_'.$c['key']) }}
                    @if (isset($c['channel'])) · {{ $c['channel'] }} @endif
                </span>
            @endforeach
        </div>
    </form>
    <div class="s" style="color:var(--muted);margin-top:6px">{{ __('kpi.calc_hint') }}</div>
</div>

@php
    $allReps = collect($result['channels'])->flatMap(fn ($c) => $c['reps']);
    $totColl = $allReps->sum(fn ($r) => $r['data']['collections']);
    $totRep = $allReps->sum('final');
    $totMgr = collect($result['channels'])->sum(fn ($c) => $c['manager']['final']);
    $totDir = collect($result['channels'])->sum(fn ($c) => $c['director']['final']);
@endphp

{{-- ═══ النتائج الرئيسية — صف Dashboard ═══ --}}
<div class="kpis">
    <div class="kpi"><div class="lbl">💰 {{ __('kpi.total_collections') }}</div>
        <div class="val">{{ $fmt($totColl) }}</div>
        <div class="sub2">{{ __('kpi.h_collections') }}</div></div>
    <div class="kpi"><div class="lbl">🧑‍💼 {{ __('kpi.rep_due') }}</div>
        <div class="val pos">{{ $f2($totRep) }}</div></div>
    <div class="kpi"><div class="lbl">👔 {{ __('kpi.manager_due') }}</div>
        <div class="val pos">{{ $f2($totMgr) }}</div></div>
    <div class="kpi"><div class="lbl">🎖️ {{ __('kpi.director_due') }}</div>
        <div class="val pos">{{ $f2($totDir) }}</div></div>
    <div class="kpi"><div class="lbl">Σ {{ __('kpi.grand_due') }}</div>
        <div class="val pos"><b>{{ $f2($totRep + $totMgr + $totDir) }}</b></div>
        <div class="sub2">{{ $totColl > 0 ? $pct(($totRep + $totMgr + $totDir) / $totColl, 2) : '0%' }} {{ __('kpi.of_collections') }}</div></div>
</div>

{{-- ═══ المدخلات اليدوية الشهرية ═══ --}}
<div class="card">
    <h3>✍️ {{ __('kpi.inputs_title') }} <span class="side">{{ __('kpi.inputs_sub') }}</span></h3>
    <form method="POST" action="{{ route('erp.kpi.inputs') }}">
        @csrf
        <input type="hidden" name="period" value="{{ $period }}">
        <div class="tablewrap">
            <table>
                <thead><tr>
                    <th>{{ __('kpi.c_channel') }}</th><th>{{ __('kpi.c_role') }}</th>
                    <th>{{ __('kpi.forecast') }}</th>
                    <th>{{ __('kpi.new_target') }}</th>
                    <th>{{ __('kpi.reporting') }}</th>
                </tr></thead>
                <tbody>
                @php $ri = 0; @endphp
                @foreach ($result['channels'] as $c)
                    @foreach (['manager' => $c['manager'], 'director' => $c['director']] as $role => $row)
                        <tr>
                            <td>{{ $c['channel']->displayName() }}</td>
                            <td><span class="badge {{ $role === 'manager' ? 'b-purple' : 'b-blue' }}">{{ __('kpi.role_'.$role) }}</span></td>
                            <td class="num">
                                <input type="hidden" name="rows[{{ $ri }}][role]" value="{{ $role }}">
                                <input type="hidden" name="rows[{{ $ri }}][kpi_channel_id]" value="{{ $c['channel']->id }}">
                                <input type="number" step="0.01" min="0" name="rows[{{ $ri }}][forecast]"
                                       value="{{ $row['input']->forecast ?: '' }}" style="width:140px" dir="ltr">
                            </td>
                            <td class="num"><input type="number" min="0" name="rows[{{ $ri }}][new_target]"
                                       value="{{ $row['input']->new_target ?: '' }}" style="width:90px" dir="ltr"></td>
                            <td class="num"><input type="number" step="0.01" min="0" max="1" name="rows[{{ $ri }}][reporting]"
                                       value="{{ $row['input']->reporting }}" style="width:90px" dir="ltr"></td>
                        </tr>
                        @php $ri++; @endphp
                    @endforeach
                @endforeach
                </tbody>
            </table>
        </div>
        <button class="btn gold" type="submit" style="margin-top:10px">💾 {{ __('common.save') }}</button>
    </form>
</div>

{{-- ═══ قناة قناة ═══ --}}
@foreach ($result['channels'] as $c)
    @php $ch = $c['channel']; @endphp
    <div class="card">
        <h3>🎯 {{ $ch->displayName() }}
            <span class="side">
                {{ __('kpi.ch_summary', [
                    'gate' => $fmt($ch->rep_gate),
                    'cost' => number_format($ch->maxBaseCost() * 100, 2),
                ]) }}
            </span>
        </h3>

        {{-- المناديب --}}
        <div class="tablewrap kpi-wrap">
            <table>
                <thead><tr>
                    <th style="text-align:start">{{ __('kpi.c_name') }}</th>
                    <th class="num">{{ __('kpi.c_collections') }}</th>
                    <th class="num">{{ __('kpi.c_ach') }}</th>
                    <th>{{ __('kpi.c_gate') }}</th>
                    <th class="num">{{ __('kpi.c_score') }}</th>
                    <th class="num">{{ __('kpi.c_base_rate') }}</th>
                    <th class="num">{{ __('kpi.c_base') }}</th>
                    <th class="num">{{ __('kpi.c_mult') }}</th>
                    <th class="num">{{ __('kpi.c_after') }}</th>
                    <th class="num">{{ __('kpi.c_kpi') }}</th>
                    <th class="num">{{ __('kpi.c_final') }}</th>
                    <th class="num">{{ __('kpi.c_actual') }}</th>
                </tr></thead>
                <tbody>
                @forelse ($c['reps'] as $r)
                    <tr class="kpi-row" onclick="kpiDetail({{ json_encode([
                        'name' => $r['rep']->displayName(),
                        'ratios' => collect($r['ratios'])->map(fn ($v) => $v === null ? null : round((float) $v, 4)),
                        'points' => collect($r['points'])->map(fn ($v) => round((float) $v, 2)),
                        'metrics' => $result['rep_metrics']->mapWithKeys(fn ($m) => [$m->key => [
                            'name' => $m->displayName(), 'weight' => (float) $m->weight,
                            'target' => $m->targetFor($ch->id), 'dir' => $m->direction]]),
                    ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) }})">
                        <td style="text-align:start"><b>{{ $r['rep']->displayName() }}</b>
                            <div class="s" style="color:var(--muted)">{{ $r['rep']->roleLabel() }}</div></td>
                        <td class="num" dir="ltr">{{ $fmt($r['data']['collections']) }}</td>
                        <td class="num" dir="ltr">{{ $pct($r['achievement']) }}</td>
                        <td><span class="badge {{ $r['cleared'] ? 'b-green' : 'b-red' }}">
                            {{ $r['cleared'] ? __('kpi.cleared') : __('kpi.missed') }}</span></td>
                        <td class="num"><b class="{{ $r['score'] >= $result['policy']['min_score'] ? 'pos' : ($r['score'] < 50 ? 'neg' : 'mid') }}"
                            >{{ $r['score'] }}</b><span class="s" style="color:var(--muted)">/100</span></td>
                        <td class="num" dir="ltr">{{ $pct($r['base_rate'], 2) }}</td>
                        <td class="num" dir="ltr">{{ $f2($r['base_value']) }}</td>
                        <td class="num" dir="ltr">×{{ $r['multiplier'] }}</td>
                        <td class="num" dir="ltr">{{ $f2($r['after_perf']) }}</td>
                        <td class="num" dir="ltr">{{ $r['eligible'] ? $f2($r['kpi_earned']) : '0.00' }}
                            @unless ($r['eligible'])<span class="badge b-gray" style="font-size:9px">{{ __('kpi.not_eligible') }}</span>@endunless</td>
                        <td class="num pos" dir="ltr"><b>{{ $f2($r['final']) }}</b></td>
                        <td class="num" dir="ltr">{{ $pct($r['actual_rate'], 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="12" style="text-align:center;color:var(--muted);padding:20px">{{ __('kpi.no_reps') }}</td></tr>
                @endforelse
                </tbody>
                {{-- المدير والمدير العام ورؤوس الإجماليات --}}
                <tfoot>
                @foreach (['manager' => $c['manager'], 'director' => $c['director']] as $role => $r)
                    <tr class="kpi-leader">
                        <td style="text-align:start">
                            <span class="badge {{ $role === 'manager' ? 'b-purple' : 'b-blue' }}">{{ __('kpi.role_'.$role) }}</span>
                            <b>{{ $role === 'manager' ? ($ch->manager?->displayName() ?? '—') : __('kpi.role_director') }}</b>
                        </td>
                        <td class="num" dir="ltr">{{ $fmt($r['collections']) }}</td>
                        <td class="num" dir="ltr">{{ $pct($r['achievement']) }}</td>
                        <td><span class="badge {{ $r['cleared'] ? 'b-green' : 'b-red' }}">
                            {{ $r['cleared'] ? __('kpi.cleared') : __('kpi.missed') }}</span></td>
                        <td class="num"><b>{{ $r['score'] }}</b><span class="s" style="color:var(--muted)">/100</span></td>
                        <td class="num" dir="ltr">{{ $pct($r['base_rate'], 2) }}</td>
                        <td class="num" dir="ltr">{{ $f2($r['base_value']) }}</td>
                        <td class="num" dir="ltr">×{{ $r['multiplier'] }}</td>
                        <td class="num" dir="ltr">{{ $f2($r['after_perf']) }}</td>
                        <td class="num" dir="ltr">{{ $f2($r['kpi_earned']) }}</td>
                        <td class="num pos" dir="ltr"><b>{{ $f2($r['final']) }}</b></td>
                        <td class="num" dir="ltr">{{ $pct($r['actual_rate'], 2) }}</td>
                    </tr>
                @endforeach
                </tfoot>
            </table>
        </div>
        <div class="dash-hint">{{ __('kpi.row_hint') }}</div>
    </div>
@endforeach

{{-- ═══ مودال تفاصيل مؤشرات مندوب ═══ --}}
<dialog id="dlgKpi">
    <div class="dlg" style="max-width:560px">
        <h4 id="kdTitle">—</h4>
        <div class="tablewrap" style="max-height:400px;overflow:auto;margin-top:8px">
            <table>
                <thead><tr>
                    <th style="text-align:start">{{ __('kpi.c_metric') }}</th>
                    <th class="num">{{ __('kpi.c_value') }}</th>
                    <th class="num">{{ __('kpi.c_target') }}</th>
                    <th class="num">{{ __('kpi.c_weight') }}</th>
                    <th class="num">{{ __('kpi.c_points') }}</th>
                </tr></thead>
                <tbody id="kdBody"></tbody>
            </table>
        </div>
        <div style="display:flex;justify-content:flex-end;margin-top:12px">
            <button class="btn" type="button" onclick="closeDlg('dlgKpi')">{{ __('common.close') }}</button>
        </div>
    </div>
</dialog>

@endsection

@section('scripts')
<style>
.kpi-wrap{max-height:60vh;overflow:auto}
.kpi-wrap thead th{position:sticky;top:0;z-index:3;background:var(--royal-blue);color:#fff}
.kpi-row{cursor:pointer}
.kpi-row:hover td{background:var(--blue-050)}
.kpi-leader td{background:var(--card2);font-weight:800;border-top:2px solid var(--royal-blue)}
</style>
<script>
    // تفاصيل مؤشرات الصف — القيمة/المستهدف/الوزن/النقاط لكل KPI
    function kpiDetail(d) {
        document.getElementById('kdTitle').textContent = '📊 ' + d.name;
        const body = document.getElementById('kdBody');

        body.innerHTML = Object.keys(d.metrics).map(function (k) {
            const m = d.metrics[k];
            const v = d.ratios[k];
            const p = d.points[k] || 0;
            const full = p >= m.weight - 0.01;

            return '<tr>' +
                '<td style="text-align:start">' + m.name +
                (m.dir === 'lower' ? ' <span class="badge b-gray" style="font-size:9px">↓</span>' : '') + '</td>' +
                '<td class="num" dir="ltr">' + (v === null ? '—' : (+v).toFixed(3)) + '</td>' +
                '<td class="num" dir="ltr">' + m.target + '</td>' +
                '<td class="num">' + m.weight + '</td>' +
                '<td class="num"><b class="' + (full ? 'pos' : (p < m.weight / 2 ? 'neg' : 'mid')) + '">' +
                p.toFixed(1) + '</b></td></tr>';
        }).join('');

        openDlg('dlgKpi');
    }
</script>
@endsection
