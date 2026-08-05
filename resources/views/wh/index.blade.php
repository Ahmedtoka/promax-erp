@extends('layouts.system')

@section('title', __('stock.warehouse_overview'))

@php
    $fmt = fn ($n) => number_format((float) $n);
    $awaiting = (int) $pending->sum(fn ($b) => $b->unshelvedQty());
@endphp

@section('actions')
    <a class="btn" href="{{ route('wh.receipts', ['warehouse' => $warehouse->id]) }}">📥 {{ __('stock.goods_receipts') }}</a>
    <a class="btn" href="{{ route('wh.locations', ['warehouse' => $warehouse->id]) }}">🗄️ {{ __('stock.locations') }}</a>
    <a class="btn gold" href="{{ route('wh.expiry', ['warehouse' => $warehouse->id]) }}">⏳ {{ __('stock.expiry_report') }}</a>
@endsection

@section('content')

@if ($warehouses->count() > 1)
    <div class="searchbar">
        <span style="font-size:11.5px;font-weight:800;color:var(--muted)">{{ __('stock.warehouses') }}</span>
        @foreach ($warehouses as $w)
            <a class="btn {{ $w->id === $warehouse->id ? 'gold' : '' }}"
               href="{{ route('wh.index', ['warehouse' => $w->id]) }}">
                {{ $w->displayName() }} <span style="font-size:10.5px;color:var(--muted)">{{ $w->typeLabel() }}</span>
            </a>
        @endforeach
    </div>
@endif

<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ __('stock.total_in_wh') }}</div>
        <div class="val">{{ $fmt($stockUnits) }}</div>
        <div class="sub2">{{ $warehouse->displayName() }} — {{ $warehouse->typeLabel() }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('stock.available_units') }}</div>
        <div class="val pos">{{ $fmt($availableUnits) }}</div>
        <div class="sub2">{{ __('stock.available_hint') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('stock.shelf_count') }}</div>
        <div class="val">{{ $fmt($locationCount) }}</div>
        <div class="sub2">{{ __('stock.shelf_map') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('stock.awaiting_putaway') }}</div>
        <div class="val {{ $awaiting > 0 ? 'mid' : 'pos' }}">{{ $fmt($awaiting) }}</div>
        <div class="sub2">{{ __('stock.batch_countable', ['count' => $pending->count()]) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('stock.expiry_warn') }}</div>
        <div class="val {{ $expiring->count() > 0 ? 'mid' : 'pos' }}">{{ $fmt($expiring->count()) }}</div>
        <div class="sub2">{{ __('stock.expiring_soon_count', ['count' => $expiring->count()]) }}</div>
    </div>
</div>

@if ($expired->isNotEmpty())
    <div class="alert" style="margin-bottom:14px">
        <span>⛔</span>
        <span>
            {{ __('stock.expired_count', ['count' => $expired->count()]) }}
            <a href="{{ route('wh.expiry', ['warehouse' => $warehouse->id]) }}"
               style="font-weight:800;color:var(--red);margin-inline-start:6px">{{ __('stock.view_all') }}</a>
        </span>
    </div>
@endif

<div class="card">
    <h3>📦 {{ __('stock.awaiting_putaway') }}
        <span class="side">{{ __('stock.awaiting_putaway_hint') }}</span></h3>

    @if (session('ok'))
        <div class="alert good" style="margin-bottom:12px"><span>✅</span><span>{{ session('ok') }}</span></div>
    @endif
    @if ($errors->any())
        <div class="alert" style="margin-bottom:12px;flex-direction:column;align-items:stretch;gap:4px">
            @foreach ($errors->all() as $msg)
                <div class="errline" style="margin:0">{{ $msg }}</div>
            @endforeach
        </div>
    @endif

    @if ($pending->isEmpty())
        <div class="alert good"><span>✅</span><span>{{ __('stock.all_shelved') }}</span></div>
    @else
        @php $canPutaway = \App\Support\Access::action(auth()->user(), 'act.wh.putaway'); @endphp

        <form method="POST" action="{{ route('wh.putaway.bulk') }}" id="paForm">
            @csrf

            {{-- بحث + كود رف موحّد + الترصيف الجماعي --}}
            <div class="searchbar">
                <input type="search" id="paSearch" placeholder="🔍 {{ __('stock.search_item') }}"
                       oninput="paFilter()" style="flex:1;min-width:220px">
                @if ($canPutaway)
                    <input type="text" name="location_code" list="locCodes"
                           placeholder="{{ __('stock.shelf_for_all') }}" style="width:150px">
                    <button class="btn gold" type="submit" id="paBtn" disabled>
                        📥 {{ __('stock.putaway_selected') }} (<span id="paCount">0</span>)
                    </button>
                @endif
            </div>

            <div class="tablewrap" style="max-height:60vh;overflow-y:auto">
                <table>
                    <thead style="position:sticky;top:0;z-index:5;background:var(--card,#fff);box-shadow:0 1px 0 var(--border)">
                        <tr>
                            <th style="width:34px">
                                @if ($canPutaway)
                                    <input type="checkbox" onchange="paToggleAll(this)">
                                @endif
                            </th>
                            <th>{{ __('stock.item') }}</th>
                            <th style="width:105px">{{ __('stock.batch_no') }}</th>
                            <th style="width:100px">{{ __('stock.expires_on') }}</th>
                            <th style="width:110px">{{ __('stock.expiry') }}</th>
                            <th class="num" style="width:90px">{{ __('stock.unshelved') }}</th>
                            <th class="num" style="width:95px">{{ __('common.qty') }}</th>
                            <th style="width:115px">{{ __('stock.shelf_code') }}</th>
                            <th style="width:150px">{{ __('stock.goods_receipt') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pending as $b)
                            @php $left = $b->unshelvedQty(); @endphp
                            <tr class="paRow"
                                data-s="{{ mb_strtolower(($b->product?->displayName() ?? '').' '.($b->product?->code ?? '').' '.$b->batch_no) }}">
                                <td>
                                    @if ($canPutaway)
                                        <input type="checkbox" class="paBox" name="checked[]"
                                               value="{{ $b->id }}" onchange="paSync()">
                                    @endif
                                </td>
                                {{-- الصورة جوه خانة الصنف — نفس نمط باقي السيستم --}}
                                <td>
                                    <div style="display:flex;gap:10px;align-items:center">
                                        @if ($b->product?->imageSrc())
                                            <img src="{{ $b->product->imageSrc() }}"
                                                 style="width:56px;height:56px;object-fit:contain;border-radius:10px;border:1px solid var(--border);background:#fff;flex-shrink:0">
                                        @else
                                            <div style="width:56px;height:56px;border-radius:10px;border:1px dashed var(--border);display:flex;align-items:center;justify-content:center;color:var(--muted);flex-shrink:0">📦</div>
                                        @endif
                                        <div>
                                            <b>{{ $b->product?->displayName() ?? __('stock.product_hash', ['id' => $b->product_id]) }}</b>
                                            @if ($b->product)
                                                <div style="font-size:10.5px;color:var(--muted)">{{ $b->product->code }} • {{ $b->product->unitLabel() }}</div>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="num"><b>{{ $b->batch_no }}</b></td>
                                <td class="num">{{ $b->expires_on?->format('Y-m-d') ?? '—' }}</td>
                                <td><span class="badge {{ $b->expiryClass() }}">{{ $b->expiryLabel() }}</span></td>
                                <td class="num mid"><b>{{ $fmt($left) }}</b>
                                    @if ($bd = $b->product?->packBreakdown($left))
                                        <div style="font-size:10px;color:var(--muted)">{{ $bd }}</div>
                                    @endif
                                </td>
                                @if ($canPutaway)
                                    <td class="num">
                                        <input type="number" name="rows[{{ $b->id }}][qty]"
                                               min="1" max="{{ $left }}" value="{{ $left }}" style="width:100%">
                                    </td>
                                    <td>
                                        <input type="text" name="rows[{{ $b->id }}][code]" list="locCodes"
                                               placeholder="{{ __('stock.shelf_code') }}" style="width:100%">
                                    </td>
                                    <td>
                                        <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                                            {{-- ترصيف السطر ده لوحده — بيبعت only فبيتجاهل التعليم --}}
                                            <button class="btn sm gold" type="submit"
                                                    name="only" value="{{ $b->id }}">{{ __('stock.put_away') }}</button>
                                            @if ($b->receipt)
                                                <a class="badge b-blue" style="text-decoration:none"
                                                   href="{{ route('wh.receipt', $b->receipt) }}">{{ $b->receipt->number }}</a>
                                            @endif
                                        </div>
                                    </td>
                                @else
                                    <td class="num">—</td>
                                    <td>—</td>
                                    <td>
                                        @if ($b->receipt)
                                            <a class="badge b-blue" style="text-decoration:none"
                                               href="{{ route('wh.receipt', $b->receipt) }}">{{ $b->receipt->number }}</a>
                                        @else
                                            —
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </form>

        <datalist id="locCodes">
            @foreach ($locationCodes as $code)
                <option value="{{ $code }}">
            @endforeach
        </datalist>
    @endif
</div>

<div class="card">
    <h3>🔁 {{ __('stock.incoming_transfers') }}
        <span class="side"><a href="{{ route('wh.transfers') }}">{{ __('stock.view_all') }}</a></span></h3>
    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('stock.transfer') }}</th>
                <th>{{ __('stock.from_warehouse') }}</th>
                <th>{{ __('stock.sent_on') }}</th>
                <th>{{ __('stock.qty_sent') }}</th>
                <th>{{ __('common.status') }}</th>
                <th></th>
            </tr>
            @forelse ($incoming as $t)
                <tr>
                    <td class="num"><b>{{ $t->number }}</b></td>
                    <td>{{ $t->fromWarehouse?->displayName() ?? '—' }}</td>
                    <td class="num">{{ $t->sent_on?->format('Y-m-d') ?? '—' }}</td>
                    <td class="num">{{ $fmt($t->qtySent()) }}</td>
                    <td><span class="badge {{ $t->statusClass() }}">{{ $t->statusLabel() }}</span></td>
                    <td><a class="btn sm" href="{{ route('wh.transfers') }}">{{ __('stock.receive_transfer') }}</a></td>
                </tr>
            @empty
                <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:24px">
                    {{ __('common.no_results') }}
                </td></tr>
            @endforelse
        </table>
    </div>
</div>

<div class="card">
    <h3>📥 {{ __('stock.latest_receipts') }}
        <span class="side"><a href="{{ route('wh.receipts', ['warehouse' => $warehouse->id]) }}">{{ __('stock.view_all') }}</a></span></h3>
    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('stock.receipt_number') }}</th>
                <th>{{ __('stock.received_on') }}</th>
                <th>{{ __('stock.supplier') }}</th>
                <th>{{ __('stock.batches') }}</th>
                <th>{{ __('stock.total_units') }}</th>
                <th>{{ __('common.status') }}</th>
            </tr>
            @forelse ($receipts as $r)
                <tr class="clickable" onclick="location.href='{{ route('wh.receipt', $r) }}'">
                    <td class="num"><b>{{ $r->number }}</b></td>
                    <td class="num">{{ $r->received_on?->format('Y-m-d') ?? '—' }}</td>
                    <td>{{ $r->supplier ?: '—' }}</td>
                    <td class="num">{{ $r->batches->count() }}</td>
                    <td class="num">{{ $fmt($r->totalQty()) }}</td>
                    <td>
                        @if ($r->isFullyShelved())
                            <span class="badge b-green">{{ __('stock.fully_shelved') }}</span>
                        @else
                            <span class="badge b-orange">{{ __('stock.partly_shelved') }} — {{ $fmt($r->unshelvedQty()) }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:24px">
                    {{ __('stock.no_receipts') }}
                </td></tr>
            @endforelse
        </table>
    </div>
</div>

@endsection

@section('scripts')
<script>
/** بحث لحظي في جدول الترصيف — بالاسم أو الكود أو رقم الباتش */
function paFilter() {
    const q = (document.getElementById('paSearch')?.value || '').trim().toLowerCase();

    document.querySelectorAll('.paRow').forEach(r => {
        r.style.display = !q || r.dataset.s.includes(q) ? '' : 'none';
    });
}

/** «علّم على الكل» بيعلّم على الظاهر بعد البحث بس */
function paToggleAll(box) {
    document.querySelectorAll('.paRow').forEach(r => {
        if (r.style.display !== 'none') {
            const b = r.querySelector('.paBox');
            if (b) b.checked = box.checked;
        }
    });
    paSync();
}

function paSync() {
    const n = document.querySelectorAll('.paBox:checked').length;
    const btn = document.getElementById('paBtn');
    const cnt = document.getElementById('paCount');

    if (cnt) cnt.textContent = n;
    if (btn) btn.disabled = n === 0;
}

paSync();
</script>
@endsection
