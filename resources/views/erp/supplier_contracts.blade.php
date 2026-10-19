@extends('layouts.system')

@section('title', __('supplier.contracts'))

@php
    $canManage = \App\Support\Access::action(auth()->user(), 'act.supplier_contracts.manage');
@endphp

@section('actions')
    <a class="btn" href="{{ route('erp.suppliers') }}">🏭 {{ __('supplier.suppliers') }}</a>
    @if ($canManage)
        <button class="btn gold" onclick="openDlg('dlgNewContract')">+ {{ __('supplier.new_contract') }}</button>
    @endif
@endsection

@section('content')

<div class="card">
    <h3>📜 {{ __('supplier.contracts') }}</h3>

    <form class="searchbar" method="GET">
        <label class="fl wide"><span>{{ __('ui.l_supplier') }}</span>
        <select name="supplier" onchange="this.form.submit()">
            <option value="">{{ __('supplier.all_suppliers') }}</option>
            @foreach ($suppliers as $sup)
                <option value="{{ $sup->id }}" @selected((int) ($filters['supplier'] ?? 0) === $sup->id)>{{ $sup->displayName() }}</option>
            @endforeach
        </select></label>
        <label class="fl"><span>{{ __('ui.l_status') }}</span>
        <select name="status" onchange="this.form.submit()">
            <option value="">{{ __('client.status_all') }}</option>
            @foreach (['live', 'expired', 'future', 'inactive'] as $st)
                <option value="{{ $st }}" @selected(($filters['status'] ?? '') === $st)>{{ __('supplier.contract_status_'.$st) }}</option>
            @endforeach
        </select></label>
        {{-- فلتر «من — إلى» على تاريخ بداية العقد --}}
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue(), 'auto' => true])
        <a class="btn" href="{{ route('erp.supplier_contracts') }}">{{ __('common.clear') }}</a>
        <a class="btn sm green" href="{{ request()->fullUrlWithQuery(['export' => 1, 'page' => null]) }}">⬇ {{ __('ui.export_all') }}</a>
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('common.number') }}</th>
                <th>{{ __('supplier.supplier') }}</th>
                <th data-nosum>{{ __('supplier.contract_starts') }}</th>
                <th data-nosum>{{ __('supplier.contract_ends') }}</th>
                <th class="num">{{ __('supplier.contract_items') }}</th>
                <th>{{ __('common.status') }}</th>
            </tr>
            @forelse ($contracts as $c)
                <tr class="clickable" onclick="location.href='{{ route('erp.supplier_contracts.show', $c) }}'">
                    <td class="num"><a href="{{ route('erp.supplier_contracts.show', $c) }}"><b>{{ $c->number }}</b></a></td>
                    <td>@if ($c->supplier)<a href="{{ route('erp.suppliers.show', $c->supplier) }}" onclick="event.stopPropagation()"><b>{{ $c->supplier->displayName() }}</b></a>@else — @endif</td>
                    <td class="num s">{{ $c->starts_on->format('Y-m-d') }}</td>
                    <td class="num s">{{ $c->ends_on->format('Y-m-d') }}</td>
                    <td class="num">{{ $c->items_count }}</td>
                    <td><span class="badge {{ $c->statusClass() }}">{{ $c->statusLabel() }}</span></td>
                </tr>
            @empty
                <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:28px">
                    {{ __('supplier.no_contracts') }}
                </td></tr>
            @endforelse
        </table>
    </div>
    @include('partials._pagination', ['p' => $contracts])
</div>

@if ($canManage)
<dialog id="dlgNewContract">
    <form class="dlg" method="POST" action="{{ route('erp.supplier_contracts.store') }}" enctype="multipart/form-data">
        @csrf
        <h4>+ {{ __('supplier.new_contract') }}</h4>
        <label class="f">{{ __('supplier.supplier') }} *</label>
        <select name="supplier_id" required style="width:100%">
            <option value="">—</option>
            @foreach ($suppliers as $sup)
                <option value="{{ $sup->id }}" @selected((int) old('supplier_id') === $sup->id)>{{ $sup->displayName() }}</option>
            @endforeach
        </select>
        @include('erp._supplier_contract_fields', ['c' => null])
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgNewContract')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>
@endif

@endsection
