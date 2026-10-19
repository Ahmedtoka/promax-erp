@extends('layouts.system')

@section('title', __('mfg.specs_title'))

@section('content')
@php
    $badge = ['draft' => 'b-gray', 'under_review' => 'b-orange', 'approved' => 'b-green', 'archived' => 'b-purple'];
@endphp
<div class="card">
    <h3>📄 {{ __('mfg.specs_title') }}
        <span class="side">{{ __('ui.rows_n', ['n' => $specs->total()]) }}</span>
    </h3>

    {{-- فلتر «من — إلى» على `updated_at` — آخر تعديل للمواصفة --}}
    <form class="searchbar" method="GET" data-noprint>
        <label class="fl"><span>{{ __('ui.l_status') }}</span>
        <select name="status">
            <option value="">{{ __('mfg.all_statuses') }}</option>
            @foreach (\App\Models\ProductSpec::STATUSES as $st)
                <option value="{{ $st }}" @selected(($filters['status'] ?? '') === $st)>{{ __('mfg.spec_status_'.$st) }}</option>
            @endforeach
        </select></label>
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue()])
        <button class="btn gold">{{ __('common.search') }}</button>
        <a class="btn" href="{{ route('mfg.specs') }}">{{ __('common.clear') }}</a>
        <a class="btn sm green" href="{{ request()->fullUrlWithQuery(['export' => 1, 'page' => null]) }}">⬇ {{ __('ui.export_all') }}</a>
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('mfg.col_product') }}</th>
                <th class="num" data-nosum>{{ __('mfg.col_version') }}</th>
                <th>{{ __('mfg.col_status') }}</th>
                <th class="num" data-nosum>{{ __('mfg.col_net_weight') }}</th>
                <th class="num" data-nosum>{{ __('mfg.col_shelf_life') }}</th>
                <th data-nosum>{{ __('mfg.col_updated_at') }}</th>
            </tr>
            @forelse ($specs as $s)
                <tr>
                    <td>{{ $s->product?->displayName() ?? '—' }}</td>
                    <td class="num">{{ $s->version }}</td>
                    <td><span class="badge {{ $badge[$s->status] ?? 'b-gray' }}">{{ $s->statusLabel() }}</span></td>
                    <td class="num">{{ $s->net_weight_g !== null ? rtrim(rtrim((string) $s->net_weight_g, '0'), '.') : '—' }}</td>
                    <td class="num">{{ $s->shelf_life_days ?? '—' }}</td>
                    <td class="num s">{{ $s->updated_at?->format('Y-m-d H:i') ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:28px">{{ __('mfg.no_specs') }}</td></tr>
            @endforelse
        </table>
    </div>
    @include('partials._pagination', ['p' => $specs])
</div>
@endsection
