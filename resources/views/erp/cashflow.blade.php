@extends('layouts.system')

{{--
    الكاش المتوقع (١٥ سبتمبر ٢٠٢٦) — كالندر استحقاق مديونية العملاء الآجل.
    «لو كل عميل سدّد في ميعاده، هيدخل كام وإمتى؟» — الأرقام من
    App\Services\CashForecast (نفس توزيع aging/overdue على كارت العميل).
--}}

@php
    $fmt = fn ($n) => number_format((float) $n, 2);
    $fmt0 = fn ($n) => number_format((float) $n, 0);
    $user = auth()->user();
    $canInvoice = \App\Support\Access::allows($user, 'ops.invoice');
    $days = $data['days'];
    $overdueTotal = $data['overdue']['total'];
    $windowTotal = round((float) $data['rows']->sum('open'), 2);
    $cashBy = round($overdueTotal + $windowTotal, 2);

    // ═══ التراكمي يوم بيوم — يبدأ من المتأخر (بيدخل «فوراً») ═══
    $cum = [];
    $run = $overdueTotal;
    $cursor = $range->from->copy();
    while ($cursor->lte($range->to)) {
        $k = $cursor->toDateString();
        $run += $days[$k]['total'] ?? 0;
        $cum[$k] = round($run, 2);
        $cursor->addDay();
    }
    $nDays = max(1, count($cum));
    $maxCum = max(1.0, (float) max($cum ?: [0]));

    // ═══ المنحنى SVG — نقطة لكل يوم، بلا مكتبات ═══
    $W = 900; $H = 220; $padL = 70; $padR = 24; $padT = 18; $padB = 34;
    $plotW = $W - $padL - $padR; $plotH = $H - $padT - $padB;
    $pts = [];
    $i = 0;
    foreach ($cum as $k => $v) {
        $x = $padL + ($nDays === 1 ? $plotW / 2 : $plotW * $i / ($nDays - 1));
        $y = $padT + $plotH - $plotH * ($v / $maxCum);
        $pts[] = [round($x, 1), round($y, 1), $k, $v];
        $i++;
    }
    $line = implode(' ', array_map(fn ($p) => $p[0].','.$p[1], $pts));
    $area = $pts ? ($pts[0][0].','.($padT + $plotH).' '.$line.' '.end($pts)[0].','.($padT + $plotH)) : '';
    $ticks = [];
    for ($t = 0; $t <= 4; $t++) {
        $ticks[] = ['y' => round($padT + $plotH - $plotH * $t / 4, 1), 'v' => $maxCum * $t / 4];
    }
    // علامات المحور الأفقي — ٦ تواريخ على الأكثر
    $xLabels = [];
    if ($pts) {
        $step = max(1, (int) ceil($nDays / 6));
        foreach ($pts as $idx => $p) {
            if ($idx % $step === 0 || $idx === $nDays - 1) {
                $xLabels[] = $p;
            }
        }
    }

    // صفوف الكالندر مجمّعة بالتاريخ — للديالوج
    $byDate = $data['rows']->groupBy(fn ($r) => $r['due']->toDateString())->map(fn ($g) => $g->map(fn ($r) => [
        'client' => $r['client'], 'client_id' => $r['client_id'], 'rep' => $r['rep'], 'doc' => $r['doc'],
        'kind' => __('cashflow.kind_'.$r['kind']), 'date' => $r['date']->toDateString(),
        'open' => $fmt($r['open']), 'terms' => $r['terms'] === null ? '' : $r['terms'].' '.__('cashflow.days_'.$r['basis']),
    ])->values())->all();
    $payload = json_encode($byDate, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
    $clientUrl = route('erp.clients.show', ['client' => '__ID__']);

    $weekdays = ['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri'];
    $today = today()->toDateString();
    $q = fn (array $extra) => url()->current().'?'.http_build_query(array_merge(request()->except('page', 'export'), $extra));
@endphp

@section('title', __('cashflow.title'))

@section('actions')
    <a class="btn" href="{{ $q(['export' => 1]) }}">📊 {{ __('ops.inv_excel') }}</a>
    <button class="btn" type="button" onclick="window.print()" title="{{ __('ops.pdf_hint') }}">📄 {{ __('ops.save_pdf') }}</button>
@endsection

@section('content')
<style>
    .cf-horizon{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:0 0 14px}
    .cf-horizon a{display:block;border:1px solid var(--line,#e3e6ee);border-radius:10px;padding:10px 14px;text-decoration:none;color:inherit;background:#fff}
    .cf-horizon a.on{border-color:#12399B;box-shadow:0 0 0 2px rgba(18,57,155,.12)}
    .cf-horizon .h{font-size:12px;color:#667}
    .cf-horizon .v{font-weight:800;font-size:20px;direction:ltr;font-variant-numeric:tabular-nums}
    .cf-months{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px}
    .cf-month h4{margin:0 0 8px;font-size:14px}
    .cf-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:3px}
    .cf-grid .wd{font-size:11px;color:#778;text-align:center;padding:2px 0}
    .cf-cell{min-height:58px;border:1px solid #e6e8ef;border-radius:7px;padding:4px 6px;font-size:11.5px;background:#fff;position:relative;text-align:start}
    .cf-cell .d{color:#667;font-size:11px}
    .cf-cell .a{font-weight:800;direction:ltr;font-variant-numeric:tabular-nums;font-size:12.5px;margin-top:2px;color:#12399B}
    .cf-cell .c{color:#889;font-size:10.5px}
    .cf-cell.has{background:#eef3ff;border-color:#c9d6f5;cursor:pointer}
    .cf-cell.has:hover{border-color:#12399B}
    .cf-cell.dim{opacity:.38}
    .cf-cell.today{outline:2px solid #D74297;outline-offset:-2px}
    .cf-cell.blank{border-color:transparent;background:transparent}
    .cf-curve{width:100%;height:auto;display:block}
    .cf-curve text{font-size:11px;fill:#556}
    .cf-note{font-size:12.5px;color:#667;margin:6px 0 0}
    .cf-dlg{min-width:min(720px,92vw)}
    .cf-dlg table{width:100%}
    @media print{.cf-cell.has{background:#eef3ff !important}.cf-months{grid-template-columns:repeat(2,1fr)}}
</style>

{{-- ═══ الأرقام الكبيرة ═══ --}}
<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ __('cashflow.kpi_cash_by', ['date' => $range->to->toDateString()]) }}</div>
        <div class="val">{{ $fmt($cashBy) }}</div>
        <div class="sub2">{{ __('cashflow.kpi_cash_by_sub') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('cashflow.kpi_window') }}</div>
        <div class="val">{{ $fmt($windowTotal) }}</div>
        <div class="sub2">{{ __('cashflow.kpi_window_sub', ['n' => $data['rows']->count(), 'from' => $range->from->toDateString(), 'to' => $range->to->toDateString()]) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('cashflow.kpi_overdue') }}</div>
        <div class="val neg">{{ $fmt($overdueTotal) }}</div>
        <div class="sub2">{{ __('cashflow.kpi_overdue_sub', ['n' => $data['overdue']['count']]) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('cashflow.kpi_later') }}</div>
        <div class="val">{{ $fmt($data['later']['total']) }}</div>
        <div class="sub2">{{ __('cashflow.kpi_later_sub', ['n' => $data['later']['count'], 'to' => $range->to->toDateString()]) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('cashflow.kpi_no_terms') }}</div>
        <div class="val mid">{{ $fmt($data['no_terms']['total']) }}</div>
        <div class="sub2">{{ __('cashflow.kpi_no_terms_sub', ['n' => $data['no_terms']['count']]) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('cashflow.kpi_total_open') }}</div>
        <div class="val">{{ $fmt($data['total_open']) }}</div>
        <div class="sub2">{{ __('cashflow.kpi_total_open_sub') }}</div>
    </div>
</div>

<div class="card">
    <h3>📆 {{ __('cashflow.title') }} <span class="side">{{ __('cashflow.sub') }}</span></h3>

    {{-- ═══ الأفق السريع: 15 / 30 / 60 / 90 يوم ═══ --}}
    <div class="cf-note" style="margin:0 0 6px">{{ __('cashflow.horizon') }} <small>({{ __('cashflow.horizon_incl_overdue') }})</small></div>
    <div class="cf-horizon" data-noprint>
        @foreach ([15, 30, 60, 90] as $h)
            @php $on = $range->from->isToday() && (int) $range->from->diffInDays($range->to) === $h; @endphp
            <a class="{{ $on ? 'on' : '' }}" href="{{ $q(['from' => $today, 'to' => today()->addDays($h)->toDateString()]) }}">
                <div class="h">{{ __('cashflow.horizon_days', ['n' => $h]) }}</div>
                <div class="v">{{ $fmt0($data['horizon'][$h]) }}</div>
            </a>
        @endforeach
    </div>

    {{-- ═══ الفلاتر ═══ --}}
    <form method="GET" class="frow" style="margin-bottom:12px" data-noprint>
        <div><label class="f">{{ __('common.from') }}</label><input type="date" name="from" value="{{ $range->fromValue() }}" min="{{ $today }}" onchange="this.form.submit()"></div>
        <div><label class="f">{{ __('common.to') }}</label><input type="date" name="to" value="{{ $range->toValue() }}" onchange="this.form.submit()"></div>
        @if ($reps->count())
            <div>
                <label class="f">{{ __('cashflow.rep') }}</label>
                <select name="rep" onchange="this.form.submit()">
                    <option value="">{{ __('common.all') }}</option>
                    @foreach ($reps as $r)
                        <option value="{{ $r->id }}" @selected($repId === $r->id)>{{ $r->displayName() }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        @if ($managers->count())
            <div>
                <label class="f">{{ __('cashflow.manager') }}</label>
                <select name="manager" onchange="this.form.submit()">
                    <option value="">{{ __('common.all') }}</option>
                    @foreach ($managers as $m)
                        <option value="{{ $m->id }}" @selected($managerId === $m->id)>{{ $m->displayName() }}</option>
                    @endforeach
                </select>
            </div>
        @endif
    </form>

    {{-- ═══ المنحنى التراكمي ═══ --}}
    <h4 style="margin:6px 0 2px">{{ __('cashflow.curve') }}</h4>
    <div class="cf-note" style="margin:0 0 6px">{{ __('cashflow.curve_hint') }}</div>
    <svg class="cf-curve" viewBox="0 0 {{ $W }} {{ $H }}" role="img" aria-label="{{ __('cashflow.curve') }}">
        @foreach ($ticks as $tk)
            <line x1="{{ $padL }}" x2="{{ $W - $padR }}" y1="{{ $tk['y'] }}" y2="{{ $tk['y'] }}" stroke="#e6e8ef" stroke-width="1"/>
            <text x="{{ $padL - 8 }}" y="{{ $tk['y'] + 4 }}" text-anchor="end" direction="ltr">{{ $fmt0($tk['v']) }}</text>
        @endforeach
        @if ($area)
            <polygon points="{{ $area }}" fill="#12399B" fill-opacity=".10"/>
            <polyline points="{{ $line }}" fill="none" stroke="#12399B" stroke-width="2.5" stroke-linejoin="round"/>
            @php $last = end($pts); @endphp
            <circle cx="{{ $last[0] }}" cy="{{ $last[1] }}" r="4.5" fill="#D74297"/>
            <text x="{{ min($last[0], $W - $padR - 60) }}" y="{{ max($padT + 10, $last[1] - 10) }}" text-anchor="middle" direction="ltr" style="font-weight:800;fill:#12399B">{{ $fmt0($last[3]) }}</text>
        @endif
        @foreach ($xLabels as $p)
            <text x="{{ $p[0] }}" y="{{ $H - 10 }}" text-anchor="middle" direction="ltr">{{ \Illuminate\Support\Carbon::parse($p[2])->format('d/m') }}</text>
        @endforeach
    </svg>

    {{-- ═══ الكالندر ═══ --}}
    <h4 style="margin:16px 0 2px">{{ __('cashflow.calendar') }}</h4>
    <div class="cf-note" style="margin:0 0 8px">{{ __('cashflow.calendar_hint') }}</div>
    <div class="cf-months">
        @foreach ($months as $m)
            @php
                $first = $m->copy()->startOfMonth();
                $last = $m->copy()->endOfMonth();
                // السبت أول الأسبوع (مصر) — Carbon: السبت = 6
                $lead = ($first->dayOfWeek + 1) % 7;
            @endphp
            <div class="cf-month">
                <h4>{{ $first->translatedFormat('F Y') }}</h4>
                <div class="cf-grid">
                    @foreach ($weekdays as $wd)
                        <div class="wd">{{ __('cashflow.weekday_'.$wd) }}</div>
                    @endforeach
                    @for ($b = 0; $b < $lead; $b++)
                        <div class="cf-cell blank"></div>
                    @endfor
                    @for ($d = $first->copy(); $d->lte($last); $d->addDay())
                        @php
                            $k = $d->toDateString();
                            $cell = $days[$k] ?? null;
                            $inside = $d->between($range->from, $range->to);
                            $cls = trim(($cell ? 'has' : '').' '.(! $inside ? 'dim' : '').' '.($k === $today ? 'today' : ''));
                        @endphp
                        <div class="cf-cell {{ $cls }}" @if ($cell) data-day="{{ $k }}" onclick="cfOpenDay(this.dataset.day)" title="{{ __('cashflow.cum') }}: {{ $fmt($cum[$k] ?? 0) }}" @elseif (! $inside) title="{{ __('cashflow.outside') }}" @endif>
                            <div class="d">{{ $d->day }}</div>
                            @if ($cell)
                                <div class="a">{{ $fmt0($cell['total']) }}</div>
                                <div class="c">{{ $cell['count'] }} {{ __('ops.entries') }}</div>
                            @endif
                        </div>
                    @endfor
                </div>
            </div>
        @endforeach
    </div>
    <div class="cf-note">{{ __('cashflow.disclaimer') }}</div>
</div>

{{-- ═══ الجدول: المستحق في الفترة ═══ --}}
<div class="card">
    <h3>🗓 {{ __('cashflow.table_due') }} <span class="side">{{ $data['rows']->count() }} {{ __('ops.entries') }}</span></h3>
    <div class="tablewrap rpt-wrap">
        <table>
            <thead><tr>
                <th>{{ __('cashflow.col_due') }}</th><th>{{ __('cashflow.col_client') }}</th><th>{{ __('cashflow.col_rep') }}</th>
                <th>{{ __('cashflow.col_channel') }}</th><th>{{ __('cashflow.col_doc') }}</th><th>{{ __('cashflow.col_doc_date') }}</th>
                <th class="num">{{ __('cashflow.col_debit') }}</th><th class="num">{{ __('cashflow.col_open') }}</th><th data-nosum>{{ __('cashflow.col_terms') }}</th>
            </tr></thead>
            <tbody>
            @forelse ($data['rows'] as $r)
                <tr>
                    <td class="num">{{ $r['due']->toDateString() }}</td>
                    <td><a href="{{ route('erp.clients.show', $r['client_id']) }}">{{ $r['client'] }}</a></td>
                    <td>{{ $r['rep'] ?? '—' }}</td>
                    <td>{{ $r['channel'] ?? '—' }}</td>
                    <td>
                        @if ($canInvoice && $r['invoice_id'])
                            <a href="{{ route('ops.invoice', $r['invoice_id']) }}">{{ $r['doc'] ?: __('cashflow.kind_'.$r['kind']) }}</a>
                        @else
                            {{ $r['doc'] ?: __('cashflow.kind_'.$r['kind']) }}
                        @endif
                    </td>
                    <td class="num">{{ $r['date']->toDateString() }}</td>
                    <td class="num">{{ $fmt($r['debit']) }}</td>
                    <td class="num pos">{{ $fmt($r['open']) }}</td>
                    <td>{{ $r['terms'] }} {{ __('cashflow.days_'.$r['basis']) }}</td>
                </tr>
            @empty
                <tr><td colspan="9" class="empty">{{ __('cashflow.empty') }}</td></tr>
            @endforelse
            </tbody>
            @if ($data['rows']->count())
                <tfoot><tr><th colspan="7">{{ __('cashflow.total') }}</th><th class="num">{{ $fmt($windowTotal) }}</th><th></th></tr></tfoot>
            @endif
        </table>
    </div>
</div>

{{-- ═══ المتأخر ═══ --}}
<div class="card">
    <h3>⏰ {{ __('cashflow.table_overdue') }} <span class="side neg">{{ $fmt($overdueTotal) }} · {{ $data['overdue']['count'] }} {{ __('ops.entries') }}</span></h3>
    <div class="tablewrap rpt-wrap">
        <table>
            <thead><tr>
                <th>{{ __('cashflow.col_due') }}</th><th class="num">{{ __('cashflow.col_days_late') }}</th><th>{{ __('cashflow.col_client') }}</th><th>{{ __('cashflow.col_rep') }}</th>
                <th>{{ __('cashflow.col_doc') }}</th><th>{{ __('cashflow.col_doc_date') }}</th><th class="num">{{ __('cashflow.col_open') }}</th>
            </tr></thead>
            <tbody>
            @forelse ($data['overdue']['rows'] as $r)
                <tr>
                    <td class="num">{{ $r['due']->toDateString() }}</td>
                    <td class="num neg">{{ $r['days_late'] }}</td>
                    <td><a href="{{ route('erp.clients.show', $r['client_id']) }}">{{ $r['client'] }}</a></td>
                    <td>{{ $r['rep'] ?? '—' }}</td>
                    <td>{{ $r['doc'] ?: __('cashflow.kind_'.$r['kind']) }}</td>
                    <td class="num">{{ $r['date']->toDateString() }}</td>
                    <td class="num neg">{{ $fmt($r['open']) }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty">—</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- ═══ بلا شروط ═══ --}}
@if ($data['no_terms']['count'])
<div class="card">
    <h3>❔ {{ __('cashflow.table_no_terms') }} <span class="side mid">{{ $fmt($data['no_terms']['total']) }}</span></h3>
    <div class="tablewrap rpt-wrap">
        <table>
            <thead><tr>
                <th>{{ __('cashflow.col_client') }}</th><th>{{ __('cashflow.col_rep') }}</th><th>{{ __('cashflow.col_channel') }}</th>
                <th>{{ __('cashflow.col_doc') }}</th><th>{{ __('cashflow.col_doc_date') }}</th><th class="num">{{ __('cashflow.col_open') }}</th><th data-nosum></th>
            </tr></thead>
            <tbody>
            @foreach ($data['no_terms']['rows'] as $r)
                <tr>
                    <td><a href="{{ route('erp.clients.show', $r['client_id']) }}">{{ $r['client'] }}</a></td>
                    <td>{{ $r['rep'] ?? '—' }}</td>
                    <td>{{ $r['channel'] ?? '—' }}</td>
                    <td>{{ $r['doc'] ?: __('cashflow.kind_'.$r['kind']) }}</td>
                    <td class="num">{{ $r['date']->toDateString() }}</td>
                    <td class="num">{{ $fmt($r['open']) }}</td>
                    <td data-noprint><a class="btn sm" href="{{ route('erp.clients.show', $r['client_id']) }}">{{ __('cashflow.set_terms') }}</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

{{-- ═══ ديالوج اليوم ═══ --}}
<dialog id="cfDay" class="cf-dlg">
    <h3 id="cfDayTitle" style="margin-top:0"></h3>
    <div class="tablewrap">
        <table>
            <thead><tr><th>{{ __('cashflow.col_client') }}</th><th>{{ __('cashflow.col_rep') }}</th><th>{{ __('cashflow.col_doc') }}</th><th>{{ __('cashflow.col_doc_date') }}</th><th class="num">{{ __('cashflow.col_open') }}</th><th>{{ __('cashflow.col_terms') }}</th></tr></thead>
            <tbody id="cfDayBody"></tbody>
        </table>
    </div>
    <div style="text-align:end;margin-top:10px"><button class="btn" type="button" onclick="closeDlg('cfDay')">✕</button></div>
</dialog>

<template id="cfDayData">{!! $payload !!}</template>
<script>
(function () {
    var data = JSON.parse(document.getElementById('cfDayData').innerHTML || '{}');
    var clientUrl = @json($clientUrl);
    var titleTpl = @json(__('cashflow.day_title', ['date' => '__D__']));
    var empty = @json(__('cashflow.day_empty'));
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
    window.cfOpenDay = function (day) {
        var rows = data[day] || [];
        document.getElementById('cfDayTitle').textContent = titleTpl.replace('__D__', day);
        var body = document.getElementById('cfDayBody'), html = '';
        if (!rows.length) { html = '<tr><td colspan="6" class="empty">' + esc(empty) + '</td></tr>'; }
        rows.forEach(function (r) {
            html += '<tr><td><a href="' + clientUrl.replace('__ID__', r.client_id) + '">' + esc(r.client) + '</a></td>'
                + '<td>' + esc(r.rep || '—') + '</td><td>' + esc(r.doc || r.kind) + '</td>'
                + '<td class="num">' + esc(r.date) + '</td><td class="num pos">' + esc(r.open) + '</td><td>' + esc(r.terms) + '</td></tr>';
        });
        body.innerHTML = html;
        openDlg('cfDay');
    };
})();
</script>
@endsection
