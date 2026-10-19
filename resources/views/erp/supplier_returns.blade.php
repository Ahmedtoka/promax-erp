@extends('layouts.system')

@section('title', __('supplier.supplier_returns'))

@php
    $money = fn ($n) => number_format((float) $n, 2);
@endphp

@section('actions')
    <a class="btn" href="{{ route('erp.purchasing') }}">← {{ __('supplier.purchase_orders') }}</a>
@endsection

@section('content')

<div class="card">
    <h3>↩️ {{ __('supplier.supplier_returns') }}
        <span class="side">{{ __('supplier.return_total') }}: {{ $money($returnsTotal) }}</span>
    </h3>

    <form class="searchbar" method="GET">
        <label class="fl wide"><span>{{ __('ui.l_supplier') }}</span>
        <select name="supplier" onchange="this.form.submit()">
            <option value="">{{ __('supplier.all_suppliers') }}</option>
            @foreach ($suppliers as $sup)
                <option value="{{ $sup->id }}" @selected((int) ($filters['supplier'] ?? 0) === $sup->id)>{{ $sup->displayName() }}</option>
            @endforeach
        </select></label>
        {{-- فلتر «من — إلى» على `returned_on` --}}
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue(), 'auto' => true])
        <a class="btn" href="{{ route('erp.purchasing.returns') }}">{{ __('common.clear') }}</a>
        <a class="btn sm green" href="{{ request()->fullUrlWithQuery(['export' => 1, 'page' => null]) }}">⬇ {{ __('ui.export_all') }}</a>
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('common.number') }}</th>
                <th>{{ __('supplier.supplier') }}</th>
                <th>{{ __('supplier.purchase_orders') }}</th>
                <th>{{ __('supplier.return_receipt') }}</th>
                <th data-nosum>{{ __('supplier.returned_on') }}</th>
                <th class="num">{{ __('common.total') }}</th>
            </tr>
            @forelse ($returns as $r)
                <tr class="clickable" onclick="location.href='{{ route('erp.purchasing.returns.show', $r) }}'">
                    <td class="num"><a href="{{ route('erp.purchasing.returns.show', $r) }}"><b>{{ $r->number }}</b></a></td>
                    <td>{{ $r->supplier?->displayName() ?? '—' }}</td>
                    <td class="num s">{{ $r->order?->number ?? '—' }}</td>
                    <td class="num s">{{ $r->receipt?->number ?? '—' }}</td>
                    <td class="num s">{{ $r->returned_on->format('Y-m-d') }}</td>
                    <td class="num">{{ $money($r->total) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:28px">
                    {{ __('supplier.no_returns') }}
                </td></tr>
            @endforelse
        </table>
    </div>
    @include('partials._pagination', ['p' => $returns])
</div>

@endsection
