@extends('layouts.system')

@section('title', __('stock.warehouses'))

@php
    use App\Models\Warehouse;

    $fmt = fn ($n) => number_format((float) $n);
    $manager = auth()->user()->canDecideOps();
@endphp

@section('actions')
    @if ($manager)
        <button class="btn gold" onclick="openDlg('dlgNewW')">+ {{ __('stock.new_warehouse') }}</button>
    @endif
@endsection

@section('content')

<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ __('stock.warehouses') }}</div>
        <div class="val">{{ $warehouses->count() }}</div>
        <div class="sub2">{{ $warehouses->where('active', true)->count() }} {{ __('common.active') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('stock.total_units') }}</div>
        <div class="val">{{ $fmt($warehouses->sum('qty_total')) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('stock.hold') }}</div>
        <div class="val mid">{{ $fmt($warehouses->sum('hold_total')) }}</div>
    </div>
</div>

<div class="card">
    <h3>{{ __('stock.warehouses') }}</h3>
    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('common.code') }}</th><th>{{ __('stock.warehouse') }}</th>
                <th>{{ __('stock.type') }}</th><th>{{ __('stock.keeper') }}</th>
                <th>{{ __('stock.skus') }}</th><th>{{ __('stock.qty') }}</th><th>{{ __('stock.hold') }}</th>
                <th>{{ __('common.status') }}</th>
                @if ($manager)<th></th>@endif
                <th></th>
            </tr>
            @forelse ($warehouses as $w)
                @php
                    // ⚠️ **`@json` بمصفوفة بيكسّر بارسر البليد.** بنجهّزها
                    // هنا ونحقنها بـ`{!! !!}` — وفلاجز الـHEX ضرورية
                    // لأن الـJSON بيقع جوه `onclick='...'`.
                    $wJson = json_encode([
                        'id' => $w->id, 'code' => $w->code,
                        'name' => $w->name, 'name_en' => $w->name_en,
                        'type' => $w->type, 'address' => $w->address,
                        'manager_id' => $w->manager_id, 'active' => (bool) $w->active,
                    ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
                @endphp
                <tr>
                    <td class="num"><b>{{ $w->code }}</b></td>
                    <td>
                        <b>{{ $w->displayName() }}</b>
                        @if ($w->address)
                            <br><span style="font-size:10.5px;color:var(--muted)">{{ $w->address }}</span>
                        @endif
                    </td>
                    <td>
                        <span class="badge {{ $w->type === Warehouse::TYPE_FACTORY ? 'b-purple' : 'b-blue' }}">
                            {{ __('stock.type_'.$w->type) }}
                        </span>
                    </td>
                    <td style="color:var(--muted)">{{ $w->manager?->displayName() ?? '—' }}</td>
                    <td class="num">{{ $fmt($w->sku_count) }}</td>
                    <td class="num"><b>{{ $fmt($w->qty_total) }}</b></td>
                    <td class="num mid">{{ $fmt($w->hold_total) }}</td>
                    <td>
                        @if ($w->active)
                            <span class="badge b-green">{{ __('common.active') }}</span>
                        @else
                            <span class="badge b-gray">{{ __('stock.inactive') }}</span>
                        @endif
                    </td>
                    @if ($manager)
                        <td><button class="btn sm" onclick='editW({!! $wJson !!})'>{{ __('common.edit') }}</button></td>
                    @endif
                    <td>
                        <a class="btn sm gold" href="{{ route('erp.warehouses.stock', $w) }}">
                            {{ __('stock.edit_stock') }} ←
                        </a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ $manager ? 10 : 9 }}" style="text-align:center;color:var(--muted);padding:28px">
                    {{ __('stock.no_warehouses') }}
                </td></tr>
            @endforelse
        </table>
    </div>
</div>

@if ($manager)
    @include('erp._warehouse_form', ['w' => null, 'managers' => $managers])
    @include('erp._warehouse_form', ['w' => 'edit', 'managers' => $managers])
@endif

@endsection

@section('scripts')
<script>
/**
 * ⚠️ **مودال واحد بيتملى بالجافاسكربت** بدل مودال لكل مخزن.
 * الصفحة فيها مخزنين دلوقتي بس ممكن تبقى 20 — و20 مودال في الصفحة
 * معناهم 20 نسخة من نفس الفورم في الـHTML.
 */
function editW(w) {
    document.getElementById('edWForm').action = '{{ url('erp/warehouses') }}/' + w.id;
    document.getElementById('edWCode').value = w.code;
    document.getElementById('edWName').value = w.name;
    document.getElementById('edWNameEn').value = w.name_en || '';
    document.getElementById('edWType').value = w.type;
    document.getElementById('edWAddress').value = w.address || '';
    document.getElementById('edWManager').value = w.manager_id || '';
    document.getElementById('edWActive').checked = w.active;
    openDlg('dlgEditW');
}
</script>
@endsection
