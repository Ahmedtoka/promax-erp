@extends('layouts.system')

@section('title', __('supplier.new_order'))

@php
    $money = fn ($n) => number_format((float) $n, 2);
    $preSupplier = (int) request('supplier');
@endphp

@section('actions')
    <a class="btn" href="{{ route('erp.purchasing') }}">← {{ __('supplier.purchase_orders') }}</a>
@endsection

@section('content')

@if ($errors->any())
    <div class="card"><div class="alert" style="flex-direction:column;align-items:stretch;gap:4px">
        @foreach ($errors->all() as $msg)
            <div class="errline" style="margin:0">{{ $msg }}</div>
        @endforeach
    </div></div>
@endif

<form method="POST" action="{{ route('erp.purchasing.store') }}">
    @csrf

    <div class="card">
        <h3>📥 {{ __('supplier.new_order') }}</h3>
        <div class="frow">
            <div>
                <label class="f">{{ __('supplier.supplier') }} *</label>
                <select name="supplier_id" required style="width:100%">
                    <option value="">— {{ __('common.pick') }} —</option>
                    @foreach ($suppliers as $sup)
                        <option value="{{ $sup->id }}" @selected((int) old('supplier_id', $preSupplier) === $sup->id)>
                            {{ $sup->displayName() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="f">{{ __('supplier.deliver_to') }} *</label>
                <select name="warehouse_id" required style="width:100%">
                    @foreach ($warehouses as $w)
                        <option value="{{ $w->id }}" @selected((int) old('warehouse_id') === $w->id)>{{ $w->displayName() }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="frow" style="margin-top:10px">
            <div>
                <label class="f">{{ __('supplier.ordered_on') }} *</label>
                <input type="date" name="ordered_on" required style="width:100%"
                       value="{{ old('ordered_on', today()->toDateString()) }}">
            </div>
            <div>
                <label class="f">{{ __('supplier.expected_on') }}</label>
                <input type="date" name="expected_on" style="width:100%" value="{{ old('expected_on') }}">
            </div>
        </div>
        <div style="margin-top:10px">
            <label class="f">{{ __('common.notes') }}</label>
            <textarea name="notes" rows="2" style="width:100%">{{ old('notes') }}</textarea>
        </div>
    </div>

    <div class="card">
        <h3>{{ __('supplier.order_lines') }} <span class="side">{{ __('supplier.order_lines_hint') }}</span></h3>
        <div class="tablewrap">
            <table>
                <tr>
                    <th>{{ __('common.code') }}</th>
                    <th>{{ __('stock.product') }}</th>
                    <th class="num">{{ __('supplier.last_cost') }}</th>
                    <th style="width:120px">{{ __('common.qty') }}</th>
                    <th style="width:140px">{{ __('supplier.unit_cost') }}</th>
                </tr>
                @foreach ($products as $p)
                    <tr>
                        <td class="num">{{ $p->code }}</td>
                        <td><b>{{ $p->displayName() }}</b>
                            <span class="s" style="color:var(--muted)">· {{ $p->unitLabel() }}</span></td>
                        {{-- التكلفة القياسية استرشادية — سعر الشراء بيتفاوض كل مرة --}}
                        <td class="num s" style="color:var(--muted)">{{ $money($p->cost) }}</td>
                        <td><input type="number" name="qty[{{ $p->id }}]" min="0" step="1"
                                   value="{{ old('qty.'.$p->id) }}"
                                   style="width:100%;text-align:center" class="lineQty" oninput="totalUp()"></td>
                        <td><input type="number" name="cost[{{ $p->id }}]" min="0" step="0.01"
                                   value="{{ old('cost.'.$p->id, $p->cost) }}"
                                   style="width:100%;text-align:center" class="lineCost" oninput="totalUp()"></td>
                    </tr>
                @endforeach
            </table>
        </div>

        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:14px">
            <span style="font-size:13px;font-weight:800">{{ __('common.total') }}:
                <span id="grandTotal" class="num">0.00</span> {{ __('common.currency') }}</span>
            <button class="btn gold" type="submit">{{ __('supplier.create_order') }}</button>
        </div>
    </div>
</form>

@endsection

@section('scripts')
<script>
function totalUp() {
    let total = 0;
    document.querySelectorAll('.lineQty').forEach(function (q, i) {
        const cost = document.querySelectorAll('.lineCost')[i];
        total += (parseInt(q.value) || 0) * (parseFloat(cost.value) || 0);
    });
    document.getElementById('grandTotal').textContent =
        total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
totalUp();
</script>
@endsection
