@extends('layouts.system')

@section('title', __('stock.new_pick_order'))

@php
    $fmt = fn ($n) => number_format((float) $n);

    $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP;
    $msgNoItems = json_encode(__('stock.pick_no_items'), $jsonFlags);

    $purposeOptions = [
        \App\Models\PickOrder::PURPOSE_VAN_LOAD => __('stock.pick_purpose_van_load'),
        \App\Models\PickOrder::PURPOSE_CUSTOMER_PO => __('stock.pick_purpose_customer_po'),
        \App\Models\PickOrder::PURPOSE_REPLENISHMENT => __('stock.pick_purpose_replenishment'),
    ];

    $inStock = $products->filter(fn ($row) => (int) $row['available'] > 0)->count();
@endphp

@section('actions')
    <a class="btn" href="{{ route('wh.picks') }}">← {{ __('stock.pick_orders') }}</a>
    @if ($warehouse)
        <a class="btn" href="{{ route('wh.locations', ['warehouse' => $warehouse->id]) }}">🗄️ {{ __('stock.shelf_map') }}</a>
    @endif
@endsection

@section('content')

@if ($warehouses->count() > 1)
    <div class="searchbar">
        <span style="font-size:11.5px;font-weight:800;color:var(--muted)">{{ __('stock.warehouses') }}</span>
        @foreach ($warehouses as $w)
            <a class="btn {{ $warehouse && $w->id === $warehouse->id ? 'gold' : '' }}"
               href="{{ route('wh.picks.create', ['warehouse' => $w->id]) }}">{{ $w->displayName() }}</a>
        @endforeach
    </div>
@endif

@if ($warehouse === null)
    <div class="card"><div class="alert warn"><span>🏭</span><span>{{ __('stock.no_warehouse') }}</span></div></div>
@else

<form method="POST" action="{{ route('wh.picks.store') }}" onsubmit="return pkBeforeSubmit()">
    @csrf
    <input type="hidden" name="warehouse_id" value="{{ $warehouse->id }}">

    <div class="card">
        <h3>🧺 {{ __('stock.new_pick_order') }}
            <span class="side">{{ $warehouse->displayName() }} — {{ $warehouse->typeLabel() }}</span></h3>

        <div class="frow">
            <div>
                <label class="f">{{ __('ops.rep') }}</label>
                <select name="assigned_to" required style="width:100%">
                    <option value="">— {{ __('stock.choose_rep') }} —</option>
                    @foreach ($reps as $r)
                        <option value="{{ $r->id }}" @selected((string) old('assigned_to') === (string) $r->id)>
                            {{ $r->name }} — {{ $r->roleLabel() }}@if ($r->zone) • {{ $r->zone->displayName() }}@endif
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="f">{{ __('stock.pick_purpose') }}</label>
                <select name="purpose" style="width:100%">
                    @foreach ($purposeOptions as $k => $lbl)
                        <option value="{{ $k }}" @selected(old('purpose', \App\Models\PickOrder::PURPOSE_VAN_LOAD) === $k)>{{ $lbl }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="f">{{ __('stock.pick_needed_on') }} <span style="font-weight:400">({{ __('common.optional') }})</span></label>
                <input type="date" name="needed_on" value="{{ old('needed_on') }}" style="width:100%">
            </div>
        </div>

        <div>
            <label class="f">{{ __('common.notes') }} <span style="font-weight:400">({{ __('common.optional') }})</span></label>
            <textarea name="notes" rows="2" style="width:100%">{{ old('notes') }}</textarea>
        </div>

        <div class="alert info" style="margin-top:12px">
            <span>ℹ️</span><span>{{ __('stock.fefo_note') }}</span>
        </div>
    </div>

    <div class="card">
        <h3>📦 {{ __('stock.item') }}
            <span class="side">{{ __('stock.total_units') }}: <b id="pkTotal" class="num">0</b></span></h3>

        <div class="searchbar">
            <input type="text" id="pkSearch" placeholder="🔍 {{ __('stock.search_item') }}"
                   autocomplete="off" oninput="pkFilter()"
                   onkeydown="if (event.key === 'Enter') { event.preventDefault(); }">
            <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;font-weight:700">
                <input type="checkbox" id="pkOnlyStock" onchange="pkFilter()"> {{ __('stock.only_with_stock') }}
            </label>
            <span style="font-size:11.5px;color:var(--muted)">
                {{ __('stock.available_now') }}: {{ $fmt($inStock) }} / {{ $fmt($products->count()) }}
            </span>
        </div>

        <div class="tablewrap">
            <table id="pkTbl">
                <tr>
                    <th>{{ __('common.code') }}</th>
                    <th>{{ __('stock.item') }}</th>
                    <th>{{ __('stock.unit') }}</th>
                    <th>{{ __('stock.available_now') }}</th>
                    <th>{{ __('stock.batch_no') }}</th>
                    <th>{{ __('stock.next_expiry') }}</th>
                    <th>{{ __('common.qty') }}</th>
                </tr>
                @forelse ($products as $row)
                    @php
                        $p = $row['model'];
                        $avail = (int) $row['available'];
                        $days = $row['days_left'];
                        $expiryText = $days === null
                            ? '—'
                            : ($days < 0
                                ? __('stock.expired_ago', ['days' => abs((int) $days)])
                                : __('stock.days_left', ['days' => (int) $days]));
                    @endphp
                    <tr data-txt="{{ $p->code }} {{ $p->displayName() }}" data-avail="{{ $avail }}">
                        <td class="num">{{ $p->code }}</td>
                        <td><b>{{ $p->displayName() }}</b></td>
                        <td style="color:var(--muted)">{{ $p->unitLabel() }}</td>
                        <td class="num {{ $avail > 0 ? 'pos' : 'neg' }}"><b>{{ $fmt($avail) }}</b></td>
                        <td class="num">{{ $row['next_batch'] ?? '—' }}</td>
                        <td>
                            @if ($row['next_expiry'])
                                <span class="num" style="font-size:11.5px">{{ $row['next_expiry'] }}</span>
                                <span class="badge {{ $row['expiry_class'] }}">{{ $expiryText }}</span>
                            @else
                                <span class="badge b-gray">—</span>
                            @endif
                        </td>
                        <td>
                            @if ($avail > 0)
                                <input type="number" name="qty[{{ $p->id }}]" min="0" step="1" max="{{ $avail }}"
                                       value="{{ old('qty.'.$p->id) }}" placeholder="0"
                                       style="width:92px">
                            @else
                                <input type="number" name="qty[{{ $p->id }}]" min="0" step="1" max="0"
                                       value="" disabled style="width:92px">
                                <span class="badge b-gray">{{ __('stock.no_stock_for_product') }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:28px">
                        {{ __('common.no_results') }}
                    </td></tr>
                @endforelse
            </table>
        </div>

        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <a class="btn" href="{{ route('wh.picks') }}">{{ __('common.cancel') }}</a>
            <button class="btn gold" type="submit">{{ __('common.create') }}</button>
        </div>
    </div>
</form>

@endif

@endsection

@section('scripts')
@if ($warehouse !== null)
<script>
function pkFilter() {
    var box = document.getElementById('pkSearch');
    var only = document.getElementById('pkOnlyStock');
    var q = box ? box.value.trim().toLowerCase() : '';
    var onlyStock = only ? only.checked : false;

    document.querySelectorAll('#pkTbl tr[data-txt]').forEach(function (tr) {
        var okText = !q || tr.dataset.txt.toLowerCase().indexOf(q) !== -1;
        var okStock = !onlyStock || Number(tr.dataset.avail) > 0;
        tr.style.display = (okText && okStock) ? '' : 'none';
    });
}

function pkSum() {
    var total = 0;
    document.querySelectorAll('#pkTbl input[type=number]').forEach(function (el) {
        if (el.disabled) { return; }
        var v = parseInt(el.value, 10);
        if (!isNaN(v) && v > 0) { total += v; }
    });
    return total;
}

function pkTotal() {
    document.getElementById('pkTotal').textContent = pkSum().toLocaleString('en-US');
}

function pkBeforeSubmit() {
    if (pkSum() <= 0) {
        alert({!! $msgNoItems !!});
        return false;
    }
    return true;
}

document.addEventListener('DOMContentLoaded', function () {
    var tbl = document.getElementById('pkTbl');
    if (tbl) { tbl.addEventListener('input', pkTotal); }
    pkTotal();
    pkFilter();
});
</script>
@endif
@endsection
