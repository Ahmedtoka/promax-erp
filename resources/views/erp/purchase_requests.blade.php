@extends('layouts.system')

@section('title', __('supplier.purchase_requests'))

@php
    $canCreate = \App\Support\Access::action(auth()->user(), 'act.purchase_requests.create');
@endphp

@section('actions')
    <a class="btn" href="{{ route('erp.purchasing') }}">🧺 {{ __('supplier.purchase_orders') }}</a>
    @if ($canCreate)
        <button class="btn gold" onclick="openDlg('dlgNewRequest')">+ {{ __('supplier.new_request') }}</button>
    @endif
@endsection

@section('content')

<div class="card">
    <h3>📝 {{ __('supplier.purchase_requests') }}</h3>

    <form class="searchbar" method="GET">
        <label class="fl wide"><span>{{ __('stock.product') }}</span>
        <select name="product" onchange="this.form.submit()">
            <option value="">{{ __('supplier.all_products') }}</option>
            @foreach ($products as $p)
                <option value="{{ $p->id }}" @selected((int) ($filters['product'] ?? 0) === $p->id)>{{ $p->code }} · {{ $p->displayName() }}</option>
            @endforeach
        </select></label>
        <label class="fl"><span>{{ __('ui.l_status') }}</span>
        <select name="status" onchange="this.form.submit()">
            <option value="">{{ __('client.status_all') }}</option>
            @foreach (\App\Models\PurchaseRequest::STATUSES as $st)
                <option value="{{ $st }}" @selected(($filters['status'] ?? '') === $st)>{{ __('supplier.pr_status_'.$st) }}</option>
            @endforeach
        </select></label>
        {{-- فلتر «من — إلى» على تاريخ الطلب --}}
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue(), 'auto' => true])
        <a class="btn" href="{{ route('erp.purchase_requests') }}">{{ __('common.clear') }}</a>
        <a class="btn sm green" href="{{ request()->fullUrlWithQuery(['export' => 1, 'page' => null]) }}">⬇ {{ __('ui.export_all') }}</a>
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('common.number') }}</th>
                <th data-nosum>{{ __('common.date') }}</th>
                <th>{{ __('stock.product') }}</th>
                <th class="num">{{ __('common.qty') }}</th>
                <th data-nosum>{{ __('supplier.needed_by') }}</th>
                <th>{{ __('supplier.requested_by') }}</th>
                <th class="num">{{ __('supplier.quotes_count') }}</th>
                <th>{{ __('common.status') }}</th>
            </tr>
            @forelse ($requests as $r)
                <tr class="clickable" onclick="location.href='{{ route('erp.purchase_requests.show', $r) }}'">
                    <td class="num"><a href="{{ route('erp.purchase_requests.show', $r) }}"><b>{{ $r->number }}</b></a></td>
                    <td class="num s">{{ $r->created_at->format('Y-m-d') }}</td>
                    <td>{{ $r->product?->displayName() ?? '—' }} <span class="s" style="color:var(--muted)">{{ $r->product?->kindLabel() }}</span></td>
                    <td class="num">{{ number_format($r->qty) }}</td>
                    <td class="num s">{{ $r->needed_by?->format('Y-m-d') ?? '—' }}</td>
                    <td class="s">{{ $r->requester?->name ?? '—' }}</td>
                    <td class="num">{{ $r->quotes_count }}</td>
                    <td><span class="badge {{ $r->statusClass() }}">{{ $r->statusLabel() }}</span></td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:28px">
                    {{ __('supplier.no_requests') }}
                </td></tr>
            @endforelse
        </table>
    </div>
    @include('partials._pagination', ['p' => $requests])
</div>

@if ($canCreate)
<dialog id="dlgNewRequest">
    <form class="dlg" method="POST" action="{{ route('erp.purchase_requests.store') }}">
        @csrf
        <h4>+ {{ __('supplier.new_request') }}</h4>
        <label class="f">{{ __('stock.product') }} *</label>
        <select name="product_id" required style="width:100%">
            <option value="">—</option>
            @foreach ($products as $p)
                <option value="{{ $p->id }}" @selected((int) old('product_id') === $p->id)>{{ $p->code }} · {{ $p->displayName() }} ({{ $p->kindLabel() }})</option>
            @endforeach
        </select>
        <div class="frow">
            <div>
                <label class="f">{{ __('common.qty') }} *</label>
                <input type="number" name="qty" min="1" step="1" required style="width:100%" value="{{ old('qty') }}">
            </div>
            <div>
                <label class="f">{{ __('supplier.needed_by') }}</label>
                <input type="date" name="needed_by" style="width:100%" value="{{ old('needed_by') }}">
            </div>
        </div>
        <label class="f">{{ __('supplier.cost_center') }}</label>
        <select name="cost_center_id" style="width:100%">
            <option value="">—</option>
            @foreach ($costCenters as $cc)
                <option value="{{ $cc->id }}" @selected((int) old('cost_center_id') === $cc->id)>{{ $cc->code }} · {{ $cc->displayName() }}</option>
            @endforeach
        </select>
        <label class="f">{{ __('common.notes') }}</label>
        <textarea name="notes" rows="2" style="width:100%">{{ old('notes') }}</textarea>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgNewRequest')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>
@if ($errors->any() && old('product_id') !== null)
<script>document.addEventListener("DOMContentLoaded", function(){ var d = document.getElementById("dlgNewRequest"); if (d) d.showModal(); });</script>
@endif
@endif

@endsection
