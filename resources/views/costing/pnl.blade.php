@extends('layouts.system')

@section('title', __('costing.pnl_title'))

@section('content')
@php
    $m2 = fn ($v) => number_format((float) $v, 2);
    $cls = fn (float $v) => $v < -0.005 ? 'neg' : ($v > 0.005 ? 'pos' : '');
    $c = $pnl['company'];
    $w = $pnl['warnings'];
    $altLabel = __('costing.method_'.$pnl['alt']);
    // صفوف الجدول بالترتيب المتداخل: [level, depth, row, parentKey]
    $rows = [['company', 0, $c, null], ['factory', 1, $pnl['factory'], null]];
    foreach ($pnl['factory']['children'] ?? [] as $l) { $rows[] = ['line', 2, $l, 'factory']; }
    foreach ($pnl['branches'] as $b) {
        $rows[] = ['branch', 1, $b, null];
        foreach ($b['children'] ?? [] as $f) {
            $rows[] = ['family', 2, $f, $b['key']];
            foreach ($f['children'] ?? [] as $p) { $rows[] = ['product', 3, $p, $b['key'].'|'.$f['key']]; }
        }
    }
    $rows[] = ['company_center', 1, $pnl['company_center'], null];
@endphp

<div class="kpis">
    <div class="kpi"><div class="lbl">{{ __('costing.kpi_revenue') }}</div><div class="val num">{{ $m2($c['revenue']) }}</div><div class="sub2">{{ $pnl['from'] }} → {{ $pnl['to'] }}</div></div>
    <div class="kpi {{ $cls($c['gross']) }}"><div class="lbl">{{ __('costing.kpi_gross') }}</div><div class="val num">{{ $m2($c['gross']) }}</div><div class="sub2">{{ __('costing.method_'.$pnl['method']) }}</div></div>
    <div class="kpi {{ $cls($c['net']) }}"><div class="lbl">{{ __('costing.kpi_net') }}</div><div class="val num">{{ $m2($c['net']) }}</div></div>
</div>

@if ($w['zero_cost'] > 0 || $w['no_transfer_price'] > 0 || abs($w['lines_without_items']) >= 0.01 || ! $pnl['reconciles'] || $pnl['factory']['provisional'] !== [])
    <div class="alert warn">
        @if ($w['zero_cost'] > 0)<div>⚠️ {{ __('costing.warn_zero_cost', ['n' => $w['zero_cost']]) }}</div>@endif
        @if ($w['no_transfer_price'] > 0)<div>⚠️ {{ __('costing.warn_no_tp', ['n' => $w['no_transfer_price']]) }}</div>@endif
        @if (abs($w['lines_without_items']) >= 0.01)<div>⚠️ {{ __('costing.warn_without_items') }} <b class="num">{{ $m2($w['lines_without_items']) }}</b></div>@endif
        @if ($pnl['factory']['provisional'] !== [])<div>⏳ {{ __('costing.warn_provisional', ['months' => implode('، ', $pnl['factory']['provisional'])]) }}</div>@endif
        @if (! $pnl['reconciles'])<div>❗ {{ __('costing.warn_not_reconciled') }}</div>@endif
    </div>
@endif

<div class="card">
    <h3>📊 {{ __('costing.pnl_title') }}</h3>
    <div class="s" style="color:var(--muted);margin-bottom:8px">{{ __('costing.pnl_hint') }}</div>

    <form class="searchbar" method="GET" data-noprint>
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue()])
        <label class="fl"><span>{{ __('costing.col_method') }}</span>
            <select name="method">
                @foreach (\App\Services\Costing\CostingSettings::METHODS as $opt)
                    <option value="{{ $opt }}" @selected($pnl['method'] === $opt)>{{ __('costing.method_'.$opt) }}</option>
                @endforeach
            </select>
        </label>
        <button class="btn gold">{{ __('common.search') }}</button>
        <a class="btn" href="{{ route('costing.pnl') }}">{{ __('common.clear') }}</a>
        <a class="btn sm green" href="{{ request()->fullUrlWithQuery(['export' => 1]) }}">⬇ {{ __('ui.export_all') }}</a>
    </form>

    <div class="tablewrap">
        <table id="pnlTable">
            <tr>
                <th data-nosum>{{ __('costing.col_name') }}</th>
                <th class="num" data-nosum>{{ __('costing.col_revenue') }}</th>
                <th class="num" data-nosum>{{ __('costing.col_cost') }}</th>
                <th class="num" data-nosum>{{ __('costing.col_cost_alt_m', ['m' => $altLabel]) }}</th>
                <th class="num" data-nosum>{{ __('costing.col_gross') }}</th>
                <th class="num" data-nosum>{{ __('costing.col_expenses') }}</th>
                <th class="num" data-nosum>{{ __('costing.col_net') }}</th>
            </tr>
            @foreach ($rows as [$level, $depth, $r, $parent])
                @php
                    $key = $parent === null ? $r['key'] : $parent.'|'.$r['key'];
                    $has = ! empty($r['children']);
                    $bold = $depth <= 1;
                @endphp
                <tr data-key="{{ $key }}" @if ($parent !== null) data-parent="{{ $parent }}" hidden @endif
                    @if ($level === 'company') style="background:var(--soft, #f4f6fb)" @endif>
                    <td style="padding-inline-start:{{ 8 + $depth * 18 }}px">
                        @if ($has)<button type="button" class="btn sm" data-toggle="{{ $key }}" aria-expanded="false">▸</button>@endif
                        @if ($bold)<b>{{ $r['label'] }}</b>@else{{ $r['label'] }}@endif
                        <span class="badge b-gray">{{ __('costing.level_'.$level) }}</span>
                        @if ($level === 'factory' && $pnl['factory']['provisional'] !== [])<span class="badge b-orange">{{ __('costing.provisional') }}</span>@endif
                    </td>
                    <td class="num">{{ $m2($r['revenue']) }}</td>
                    <td class="num">{{ $m2($r['cost']) }}</td>
                    <td class="num" style="color:var(--muted)">{{ $m2($r['cost_alt']) }}</td>
                    <td class="num">{{ $m2($r['gross']) }}</td>
                    <td class="num">{{ $m2($r['expenses']) }}</td>
                    <td class="num {{ $cls($r['net']) }}">@if ($bold)<b>{{ $m2($r['net']) }}</b>@else{{ $m2($r['net']) }}@endif</td>
                </tr>
            @endforeach
            <tr>
                <td style="padding-inline-start:26px">{{ __('costing.pnl_elimination') }} <span class="badge b-purple">{{ __('costing.level_elimination') }}</span>
                    <div class="s" style="color:var(--muted)">{{ __('costing.pnl_elimination_hint') }}</div></td>
                <td colspan="5"></td>
                <td class="num {{ $cls(-$pnl['elimination']) }}"><b>{{ $m2(-$pnl['elimination']) }}</b></td>
            </tr>
        </table>
    </div>
    <div class="s" style="color:var(--muted);margin-top:8px">{{ __('costing.pnl_months_note') }}
        @if ($pnl['months'] !== [])<span class="num">({{ implode(' · ', $pnl['months']) }})</span>@endif
    </div>
</div>

<script>
document.querySelectorAll('#pnlTable [data-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var key = btn.getAttribute('data-toggle');
        var open = btn.getAttribute('aria-expanded') !== 'true';
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        btn.textContent = open ? '▾' : '▸';
        document.querySelectorAll('#pnlTable tr[data-parent]').forEach(function (tr) {
            var p = tr.getAttribute('data-parent');
            if (p === key) { tr.hidden = ! open; }
            else if (! open && p.indexOf(key + '|') === 0) {
                tr.hidden = true;
                var b = tr.querySelector('[data-toggle]');
                if (b) { b.setAttribute('aria-expanded', 'false'); b.textContent = '▸'; }
            }
        });
    });
});
</script>
@endsection
