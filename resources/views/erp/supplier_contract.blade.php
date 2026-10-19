@extends('layouts.system')

@section('title', $c->number)

@php
    $canManage = \App\Support\Access::action(auth()->user(), 'act.supplier_contracts.manage');
@endphp

@section('actions')
    <a class="btn" href="{{ route('erp.supplier_contracts') }}">← {{ __('supplier.contracts') }}</a>
    @if ($canManage)
        <button class="btn" onclick="openDlg('dlgEditContract')">✎ {{ __('common.edit') }}</button>
    @endif
@endsection

@section('content')

<div class="card">
    <h3>📜 {{ $c->number }}
        <span class="badge {{ $c->statusClass() }}">{{ $c->statusLabel() }}</span>
    </h3>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
        @if ($c->supplier)
            <a class="badge b-gray" href="{{ route('erp.suppliers.show', $c->supplier) }}">{{ $c->supplier->displayName() }}</a>
        @endif
        <span class="badge b-gray">{{ __('supplier.contract_starts') }}: {{ $c->starts_on->format('Y-m-d') }}</span>
        <span class="badge b-gray">{{ __('supplier.contract_ends') }}: {{ $c->ends_on->format('Y-m-d') }}</span>
        @if ($c->attachment_path)
            <a class="badge b-blue" target="_blank" rel="noopener" href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($c->attachment_path) }}">📎 {{ __('supplier.contract_attachment') }}</a>
        @endif
    </div>
    @if ($c->notes)<p style="color:var(--muted)">{{ $c->notes }}</p>@endif
</div>

<div class="card">
    <h3>🏷️ {{ __('supplier.contract_items') }}</h3>
    <p style="color:var(--muted);font-size:12.5px">{{ __('supplier.contract_items_hint') }}</p>
    <form method="POST" action="{{ route('erp.supplier_contracts.items', $c) }}">
        @csrf
        <div class="tablewrap">
            <table>
                <tr>
                    <th>{{ __('common.code') }}</th>
                    <th>{{ __('stock.product') }}</th>
                    <th>{{ __('stock.product_kind') }}</th>
                    <th class="num" data-nosum>{{ __('supplier.contract_price') }}</th>
                </tr>
                @foreach ($products as $p)
                    <tr>
                        <td class="num">{{ $p->code }}</td>
                        <td>{{ $p->displayName() }}</td>
                        <td class="s">{{ $p->kindLabel() }}</td>
                        <td class="num">
                            @if ($canManage)
                                <input type="number" step="0.01" min="0" name="price[{{ $p->id }}]" style="width:110px"
                                       value="{{ isset($prices[$p->id]) ? number_format((float) $prices[$p->id], 2, '.', '') : '' }}">
                            @else
                                {{ isset($prices[$p->id]) ? number_format((float) $prices[$p->id], 2) : '—' }}
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>
        @if ($canManage)
            <div style="margin-top:12px"><button class="btn gold" type="submit">{{ __('common.save') }}</button></div>
        @endif
    </form>
</div>

@if ($canManage)
<dialog id="dlgEditContract">
    <form class="dlg" method="POST" action="{{ route('erp.supplier_contracts.update', $c) }}" enctype="multipart/form-data">
        @csrf @method('PUT')
        <h4>✎ {{ $c->number }}</h4>
        @include('erp._supplier_contract_fields', ['c' => $c])
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgEditContract')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>
@endif

@endsection
