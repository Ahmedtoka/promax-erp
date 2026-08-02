@extends('layouts.system')

@section('title', __('supplier.purchase_orders'))

@php
    $money = fn ($n) => number_format((float) $n, 2);
    $manager = auth()->user()->canDecideOps();
@endphp

@section('actions')
    {{-- أمين المخزن بيشوف أوامر الشراء بس — كشوف الموردين فلوس مش له --}}
    @if (\App\Support\Access::allows(auth()->user(), 'erp.suppliers'))
        <a class="btn" href="{{ route('erp.suppliers') }}">🏭 {{ __('supplier.suppliers') }}</a>
    @endif
    @if ($manager)
        <a class="btn gold" href="{{ route('erp.purchasing.new') }}">+ {{ __('supplier.new_order') }}</a>
    @endif
@endsection

@section('content')

<div class="card">
    <h3>📥 {{ __('supplier.purchase_orders') }}
        <span class="side">{{ __('supplier.open_countable', ['count' => $openCount]) }}</span>
    </h3>

    <form class="searchbar" method="GET">
        <select name="supplier" onchange="this.form.submit()">
            <option value="">— {{ __('supplier.all_suppliers') }} —</option>
            @foreach ($suppliers as $sup)
                <option value="{{ $sup->id }}" @selected((int) ($filters['supplier'] ?? 0) === $sup->id)>{{ $sup->displayName() }}</option>
            @endforeach
        </select>
        <select name="status" onchange="this.form.submit()">
            <option value="">{{ __('client.status_all') }}</option>
            @foreach (['open', 'received', 'closed', 'cancelled'] as $st)
                <option value="{{ $st }}" @selected(($filters['status'] ?? '') === $st)>{{ __('supplier.status_'.$st) }}</option>
            @endforeach
        </select>
        <a class="btn" href="{{ route('erp.purchasing') }}">{{ __('common.clear') }}</a>
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('common.number') }}</th>
                <th>{{ __('supplier.supplier') }}</th>
                <th>{{ __('stock.warehouse') }}</th>
                <th>{{ __('supplier.ordered_on') }}</th>
                <th>{{ __('supplier.expected_on') }}</th>
                <th class="num">{{ __('common.total') }}</th>
                <th>{{ __('common.status') }}</th>
            </tr>
            @forelse ($orders as $o)
                <tr class="clickable" onclick="location.href='{{ route('erp.purchasing.show', $o) }}'">
                    <td class="num"><b>{{ $o->number }}</b></td>
                    <td><b>{{ $o->supplier->displayName() }}</b></td>
                    <td class="s">{{ $o->warehouse->displayName() }}</td>
                    <td class="num s">{{ $o->ordered_on->format('Y-m-d') }}</td>
                    <td class="num s {{ $o->isOpen() && $o->expected_on?->isPast() ? 'neg' : '' }}">
                        {{ $o->expected_on?->format('Y-m-d') ?? '—' }}
                    </td>
                    <td class="num">{{ $money($o->total) }}</td>
                    <td><span class="badge {{ $o->statusClass() }}">{{ $o->statusLabel() }}</span></td>
                </tr>
            @empty
                <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:28px">
                    {{ __('supplier.no_orders') }}
                </td></tr>
            @endforelse
        </table>
    </div>
    <div class="pag">{{ $orders->links('pagination::simple-default') }}</div>
</div>

@endsection
