@extends('layouts.system')

@section('title', __('stock.pick_orders'))

@php
    $fmt = fn ($n) => number_format((float) $n);
    // ⚠️ **أمين المخزن لازم يشوف الأزرار دي — دي شغله.** كانت
    // `isManager()` وهو مش منهم، فالراوتس اتديتله والأزرار اتخبّت
    // عنه: مخزن للقراية بس.
    $manager = auth()->user()->canWorkWarehouse();

    $statusFilter = (string) ($filters['status'] ?? '');
    $repFilter = (string) ($filters['rep'] ?? '');
    $whFilter = (string) ($filters['warehouse'] ?? '');

    // ترتيب الحالات في الفلتر — نفس ترتيب الفلو في الموديل
    $statusOptions = [
        'open' => __('stock.open_only'),
        'requested' => __('stock.pick_status_requested'),
        'picking' => __('stock.pick_status_picking'),
        'ready' => __('stock.pick_status_ready'),
        'handed' => __('stock.pick_status_handed'),
        'cancelled' => __('stock.pick_status_cancelled'),
    ];
@endphp

@section('actions')
    <a class="btn" href="{{ route('wh.index') }}">🏭 {{ __('stock.warehouse_overview') }}</a>
    @if ($manager)
        <a class="btn gold" href="{{ route('wh.picks.create') }}">+ {{ __('stock.new_pick_order') }}</a>
    @endif
@endsection

@section('content')

<div class="card">
    <h3>🧺 {{ __('stock.pick_orders') }}
        <span class="side">{{ __('stock.pick_open_count', ['count' => $openCount]) }}</span></h3>

    <form class="searchbar" method="GET">
        <select name="status">
            <option value="">{{ __('stock.all_statuses') }}</option>
            @foreach ($statusOptions as $k => $lbl)
                <option value="{{ $k }}" @selected($statusFilter === $k)>{{ $lbl }}</option>
            @endforeach
        </select>
        <select name="rep">
            <option value="">{{ __('ops.all_reps') }}</option>
            @foreach ($reps as $r)
                <option value="{{ $r->id }}" @selected($repFilter === (string) $r->id)>{{ $r->name }}</option>
            @endforeach
        </select>
        <select name="warehouse">
            <option value="">{{ __('stock.all_warehouses') }}</option>
            @foreach ($warehouses as $w)
                <option value="{{ $w->id }}" @selected($whFilter === (string) $w->id)>{{ $w->displayName() }}</option>
            @endforeach
        </select>
        <button class="btn gold" type="submit">{{ __('common.search') }}</button>
        <a class="btn" href="{{ route('wh.picks') }}">{{ __('common.clear') }}</a>
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('stock.pick_order') }}</th>
                <th>{{ __('stock.warehouse') }}</th>
                <th>{{ __('ops.rep') }}</th>
                <th>{{ __('stock.pick_purpose') }}</th>
                <th>{{ __('stock.qty_requested') }}</th>
                <th>{{ __('stock.qty_picked') }}</th>
                <th>{{ __('stock.qty_received_col') }}</th>
                <th>{{ __('common.status') }}</th>
                <th></th>
            </tr>
            @forelse ($orders as $o)
                <tr class="clickable" onclick="location.href='{{ route('wh.picks.show', $o) }}'">
                    <td class="num"><b>{{ $o->number }}</b>
                        <br><span style="font-size:10.5px;color:var(--muted)">{{ $o->created_at?->format('Y-m-d') ?? '—' }}</span>
                    </td>
                    <td>{{ $o->warehouse?->displayName() ?? '—' }}</td>
                    <td>{{ $o->rep?->name ?? '—' }}</td>
                    <td>
                        @if ($o->purpose)
                            <span class="badge b-purple">{{ $o->purposeLabel() }}</span>
                        @else
                            <span class="badge b-gray">—</span>
                        @endif
                    </td>
                    <td class="num">{{ $fmt($o->qtyRequested()) }}</td>
                    <td class="num pos">{{ $fmt($o->qtyPicked()) }}</td>
                    <td class="num">{{ $fmt($o->qtyReceived()) }}</td>
                    <td><span class="badge {{ $o->statusClass() }}">{{ $o->statusLabel() }}</span></td>
                    <td>
                        @if ($o->has_variance)
                            <span class="badge b-red">⚠️ {{ __('stock.variance') }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" style="text-align:center;color:var(--muted);padding:28px">
                    {{ __('stock.no_picks') }}
                </td></tr>
            @endforelse
        </table>
    </div>
    <div class="pag">{{ $orders->links('pagination::simple-default') }}</div>
</div>

@endsection
