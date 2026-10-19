@extends('layouts.system')

@section('title', __('mfg.variance_title'))

@section('content')
@php
    $num = fn ($v, $dp = 3) => rtrim(rtrim(number_format((float) $v, $dp, '.', ''), '0'), '.') ?: '0';
    $vClass = fn (float $v) => $v > 0.005 ? 'color:var(--red)' : ($v < -0.005 ? 'color:var(--green)' : '');
    $base = array_filter(['from' => $range->fromValue(), 'to' => $range->toValue(), 'product_id' => $productId]);
@endphp
<div class="card">
    <h3>📉 {{ __('mfg.variance_title') }}
        <span class="side">{{ __('ui.rows_n', ['n' => $rows->count()]) }}</span>
    </h3>
    <div class="s" style="color:var(--muted);margin-bottom:8px">{{ __('mfg.variance_hint') }}</div>

    {{-- فلتر «من — إلى» على `produced_on` + المنتج --}}
    <form class="searchbar" method="GET" data-noprint>
        <label class="fl"><span>{{ __('mfg.col_product') }}</span>
        <select name="product_id">
            <option value="">{{ __('mfg.all_products') }}</option>
            @foreach ($products as $p)
                <option value="{{ $p->id }}" @selected($productId === $p->id)>{{ $p->code }} — {{ $p->displayName() }}</option>
            @endforeach
        </select></label>
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue()])
        <button class="btn gold">{{ __('common.search') }}</button>
        <a class="btn" href="{{ route('mfg.variance') }}">{{ __('common.clear') }}</a>
        <a class="btn sm green" href="{{ request()->fullUrlWithQuery(['export' => 1, 'month' => null]) }}">⬇ {{ __('ui.export_all') }}</a>
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                <th data-nosum>{{ __('mfg.col_month') }}</th>
                <th>{{ __('mfg.col_product') }}</th>
                <th class="num">{{ __('mfg.col_orders') }}</th>
                <th class="num">{{ __('mfg.col_planned_qty') }}</th>
                <th class="num">{{ __('mfg.col_output_qty') }}</th>
                <th class="num">{{ __('mfg.col_std_material_cost') }}</th>
                <th class="num">{{ __('mfg.col_material_cost') }}</th>
                <th class="num">{{ __('mfg.col_variance_value') }}</th>
                <th class="num" data-nosum>{{ __('mfg.col_waste_pct') }}</th>
            </tr>
            @forelse ($rows as $r)
                @php($open = $drill && $drill['month'] === $r['month'] && $productId === $r['product']->id)
                <tr @if ($open) style="background:var(--blue-050)" @endif>
                    <td class="num"><a href="{{ route('mfg.variance', array_merge($base, ['product_id' => $r['product']->id, 'month' => $r['month']])) }}">{{ $r['month'] }}</a></td>
                    <td><a href="{{ route('mfg.variance', array_merge($base, ['product_id' => $r['product']->id, 'month' => $r['month']])) }}">{{ $r['product']->code }} — {{ $r['product']->displayName() }}</a></td>
                    <td class="num">{{ number_format($r['orders']) }}</td>
                    <td class="num">{{ number_format($r['planned_qty']) }}</td>
                    <td class="num">{{ number_format($r['output_qty']) }}</td>
                    <td class="num">{{ number_format($r['std_value'], 2) }}</td>
                    <td class="num">{{ number_format($r['actual_value'], 2) }}</td>
                    <td class="num" style="{{ $vClass($r['variance_value']) }}"><b>{{ number_format($r['variance_value'], 2) }}</b></td>
                    <td class="num">{{ $r['waste_pct'] !== null ? number_format($r['waste_pct'], 2).'%' : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="9" style="text-align:center;color:var(--muted);padding:28px">{{ __('mfg.no_variance') }}</td></tr>
            @endforelse
        </table>
    </div>
</div>

{{-- التفصيل بالخامة لصف (منتج + شهر) — Variance::lines بنفس الفلتر --}}
@if ($drill && $drill['product'])
    <div class="card">
        <h3>🧾 {{ __('mfg.variance_lines_title', ['product' => $drill['product']->displayName(), 'month' => $drill['month']]) }}
            <span class="side"><a class="btn sm" href="{{ route('mfg.variance', $base) }}" data-noprint>{{ __('mfg.variance_close_drill') }}</a></span>
        </h3>
        <div class="tablewrap">
            <table>
                <tr>
                    <th>{{ __('mfg.col_material') }}</th>
                    <th class="num">{{ __('mfg.col_base_qty') }}</th>
                    <th class="num">{{ __('mfg.col_std_qty') }}</th>
                    <th class="num">{{ __('mfg.col_actual_qty') }}</th>
                    <th class="num">{{ __('mfg.col_variance_qty') }}</th>
                    <th class="num">{{ __('mfg.col_std_cost') }}</th>
                    <th class="num">{{ __('mfg.col_actual_cost') }}</th>
                    <th class="num">{{ __('mfg.col_variance_value') }}</th>
                    <th class="num" data-nosum>{{ __('mfg.col_waste_pct') }}</th>
                </tr>
                @forelse ($drill['lines'] as $l)
                    <tr>
                        <td>{{ $l['material']->code }} — {{ $l['material']->displayName() }}</td>
                        <td class="num">{{ $num($l['base_qty']) }}</td>
                        <td class="num">{{ $num($l['std_qty']) }}</td>
                        <td class="num">{{ $num($l['actual_qty']) }} {{ $l['material']->unitLabel() }}</td>
                        <td class="num" style="{{ $vClass($l['variance_qty']) }}">{{ $num($l['variance_qty']) }}</td>
                        <td class="num">{{ number_format($l['std_value'], 2) }}</td>
                        <td class="num">{{ number_format($l['actual_value'], 2) }}</td>
                        <td class="num" style="{{ $vClass($l['variance_value']) }}"><b>{{ number_format($l['variance_value'], 2) }}</b></td>
                        <td class="num">{{ $l['waste_pct'] !== null ? number_format($l['waste_pct'], 2).'%' : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" style="text-align:center;color:var(--muted);padding:18px">{{ __('mfg.no_variance_lines') }}</td></tr>
                @endforelse
            </table>
        </div>
    </div>
@endif
@endsection
