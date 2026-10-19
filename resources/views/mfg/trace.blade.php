@extends('layouts.system')

@section('title', __('mfg.trace_title'))

@section('content')
@php
    $num = fn ($v, $dp = 3) => rtrim(rtrim(number_format((float) $v, $dp, '.', ''), '0'), '.') ?: '0';
    $kindBadge = ['finished' => 'b-green', 'raw' => 'b-orange', 'packaging' => 'b-blue'];
    $canOrder = \App\Support\Access::allows(auth()->user(), 'mfg.orders.show');
@endphp
<div class="card">
    <h3>🔎 {{ __('mfg.trace_title') }}
        @if ($term !== '')<span class="side">{{ __('ui.rows_n', ['n' => $results->count()]) }}</span>@endif
    </h3>
    <div class="s" style="color:var(--muted);margin-bottom:8px">{{ __('mfg.trace_hint') }}</div>

    {{-- البحث برقم الباتش + «من — إلى» على تاريخ الاستلام --}}
    <form class="searchbar" method="GET" data-noprint>
        <label class="fl"><span>{{ __('mfg.trace_search') }}</span>
            <input type="search" name="q" value="{{ $term }}" placeholder="{{ __('mfg.trace_placeholder') }}" maxlength="80" autofocus>
        </label>
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue()])
        <button class="btn gold">{{ __('common.search') }}</button>
        <a class="btn" href="{{ route('mfg.trace') }}">{{ __('common.clear') }}</a>
        @if ($term !== '')
            <a class="btn sm green" href="{{ request()->fullUrlWithQuery(['export' => 1]) }}">⬇ {{ __('ui.export_all') }}</a>
        @endif
    </form>
    <div class="s" style="color:var(--muted)">{{ __('mfg.trace_range_hint') }}</div>
</div>

@if ($term !== '' && $results->isEmpty())
    <div class="card">
        <div style="text-align:center;color:var(--muted);padding:28px">{{ __('mfg.trace_no_match', ['q' => $term]) }}</div>
    </div>
@endif

@foreach ($results as $res)
    @php($b = $res['batch'])
    <div class="card">
        <h3>
            <span class="num">{{ $b->batch_no }}</span> — {{ $b->product?->displayName() ?? '—' }}
            <span class="badge {{ $kindBadge[$res['kind']] ?? 'b-gray' }}">{{ $b->product?->kindLabel() }}</span>
            <span class="side s">{{ __('mfg.trace_twins') }}: {{ $res['twins']->map(fn ($t) => $t->warehouse?->displayName())->filter()->unique()->implode(' · ') }}</span>
        </h3>

        @if ($res['kind'] === \App\Models\Product::KIND_FINISHED)
            <h4>{{ __('mfg.trace_raw_lots_title') }}</h4>
            <div class="tablewrap">
                <table>
                    <tr>
                        <th data-nosum>{{ __('mfg.col_raw_batch') }}</th>
                        <th>{{ __('mfg.col_material') }}</th>
                        <th class="num">{{ __('mfg.col_qty') }}</th>
                        <th class="num">{{ __('mfg.col_value') }}</th>
                    </tr>
                    @forelse ($res['raw'] as $l)
                        <tr>
                            <td class="num">{{ $l['batch']->batch_no }}</td>
                            <td>{{ $l['product']?->displayName() ?? '—' }}</td>
                            <td class="num">{{ $num($l['qty']) }} {{ $l['product']?->unitLabel() }}</td>
                            <td class="num">{{ number_format($l['value'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" style="text-align:center;color:var(--muted);padding:18px">{{ __('mfg.no_raw_lots') }}</td></tr>
                    @endforelse
                </table>
            </div>
            @include('mfg._trace_recipients', ['recipients' => $res['recipients'], 'vans' => $res['vans']])
        @else
            <h4>{{ __('mfg.trace_finished_title') }}</h4>
            @forelse ($res['finished'] as $f)
                @php($o = $f['order'])
                <div style="border-top:1px solid var(--border);padding-top:10px;margin-top:10px">
                    <div class="tablewrap">
                        <table>
                            <tr>
                                <th data-nosum>{{ __('mfg.col_number') }}</th>
                                <th data-nosum>{{ __('mfg.col_fg_batch') }}</th>
                                <th>{{ __('mfg.col_product') }}</th>
                                <th data-nosum>{{ __('mfg.col_produced_on') }}</th>
                                <th class="num" data-nosum>{{ __('mfg.col_qty_used') }}</th>
                            </tr>
                            <tr>
                                <td class="num"><b>@if ($canOrder)<a href="{{ route('mfg.orders.show', $o) }}">{{ $o->number }}</a>@else{{ $o->number }}@endif</b></td>
                                <td class="num">{{ $o->fgBatch?->batch_no ?? $o->batch_no }}</td>
                                <td>{{ $o->product?->displayName() ?? '—' }}</td>
                                <td class="num s">{{ $o->produced_on?->format('Y-m-d') ?? '—' }}</td>
                                <td class="num">{{ $num($o->lot_qty) }} {{ $b->product?->unitLabel() }}</td>
                            </tr>
                        </table>
                    </div>
                    @if ($o->fgBatch)
                        @include('mfg._trace_recipients', ['recipients' => $f['recipients'], 'vans' => $f['vans']])
                    @else
                        <div class="s" style="color:var(--muted);padding:8px 0">{{ __('mfg.fg_not_closed') }}</div>
                    @endif
                </div>
            @empty
                <div style="text-align:center;color:var(--muted);padding:18px">{{ __('mfg.no_finished_for_lot') }}</div>
            @endforelse
        @endif
    </div>
@endforeach
@endsection
