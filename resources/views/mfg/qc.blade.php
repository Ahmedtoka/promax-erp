@extends('layouts.system')

@section('title', __('mfg.qc_title'))

@section('content')
@php
    $badge = ['quarantine' => 'b-orange', 'released' => 'b-green', 'rejected' => 'b-red'];
@endphp
<div class="card">
    <h3>🔬 {{ __('mfg.qc_title') }}
        <span class="side">{{ __('ui.rows_n', ['n' => $orders->total()]) }}</span>
    </h3>

    {{-- فلتر «من — إلى» على `produced_on` — تاريخ إنتاج الباتش --}}
    <form class="searchbar" method="GET" data-noprint>
        <label class="fl"><span>{{ __('ui.l_status') }}</span>
        <select name="qc_status">
            <option value="">{{ __('mfg.all_statuses') }}</option>
            @foreach (\App\Models\ProductionOrder::QC as $st)
                <option value="{{ $st }}" @selected(($filters['qc_status'] ?? '') === $st)>{{ __('mfg.qc_status_'.$st) }}</option>
            @endforeach
        </select></label>
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue()])
        <button class="btn gold">{{ __('common.search') }}</button>
        <a class="btn" href="{{ route('mfg.qc') }}">{{ __('common.clear') }}</a>
        <a class="btn sm green" href="{{ request()->fullUrlWithQuery(['export' => 1, 'page' => null]) }}">⬇ {{ __('ui.export_all') }}</a>
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                <th data-nosum>{{ __('mfg.col_number') }}</th>
                <th>{{ __('mfg.col_product') }}</th>
                <th data-nosum>{{ __('mfg.col_batch_no') }}</th>
                <th data-nosum>{{ __('mfg.col_produced_on') }}</th>
                <th data-nosum>{{ __('mfg.col_expires_on') }}</th>
                <th class="num">{{ __('mfg.col_output_qty') }}</th>
                <th>{{ __('mfg.col_qc_status') }}</th>
                <th data-nosum>{{ __('mfg.col_qc_at') }}</th>
            </tr>
            @forelse ($orders as $o)
                <tr>
                    <td class="num"><b>{{ $o->number }}</b></td>
                    <td>{{ $o->product?->displayName() ?? '—' }}</td>
                    <td class="num">{{ $o->batch_no }}</td>
                    <td class="num s">{{ $o->produced_on?->format('Y-m-d') ?? '—' }}</td>
                    <td class="num s">{{ $o->expires_on?->format('Y-m-d') ?? '—' }}</td>
                    <td class="num">{{ $o->output_qty !== null ? number_format($o->output_qty) : '—' }}</td>
                    <td><span class="badge {{ $badge[$o->qc_status] ?? 'b-gray' }}">{{ $o->qcLabel() }}</span></td>
                    <td class="num s">{{ $o->qc_at?->format('Y-m-d H:i') ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:28px">{{ __('mfg.no_qc') }}</td></tr>
            @endforelse
        </table>
    </div>
    @include('partials._pagination', ['p' => $orders])
</div>
@endsection
