@extends('layouts.system')

@section('title', __('stock.pick_order').' '.$o->number)

@php
    $fmt = fn ($n) => number_format((float) $n);
    // ⚠️ **أمين المخزن لازم يشوف الأزرار دي — دي شغله.** كانت
    // `isManager()` وهو مش منهم، فالراوتس اتديتله والأزرار اتخبّت
    // عنه: مخزن للقراية بس.
    $manager = auth()->user()->canWorkWarehouse();

    $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP;
    $confirmCancel = json_encode(__('stock.confirm_cancel_pick'), $jsonFlags);

    $requested = $o->qtyRequested();
    $picked = $o->qtyPicked();
    $received = $o->qtyReceived();

    $canStart = $o->status === 'requested';
    $canReady = $o->canPick();
    // ⚠️ الإلغاء قرار مدير (`role:admin,manager` في الراوت) — من غير
    // فحص الرول أمين المخزن كان بيشوف الزرار وياخد 403.
    $canCancel = $o->status !== 'handed' && auth()->user()->canDecideOps();

    // المصدر — أمر توريد لعميل أو طلب ريفيل
    $sourceLabel = null;
    $sourceUrl = null;
    if ($o->purchase_order_id) {
        $sourceLabel = __('ops.purchase_order').' '.($o->purchaseOrder?->number ?? '#'.$o->purchase_order_id);
        if ($o->purchaseOrder?->client) {
            $sourceLabel .= ' — '.$o->purchaseOrder->client->displayName();
        }
        $sourceUrl = route('ops.pos');
    } elseif ($o->replenishment_request_id) {
        $sourceLabel = __('ops.request').' '.($o->replenishmentRequest?->number ?? '#'.$o->replenishment_request_id);
        if ($o->replenishmentRequest?->client) {
            $sourceLabel .= ' — '.$o->replenishmentRequest->client->displayName();
        }
        $sourceUrl = route('ops.replenishments');
    }

    $varianceNotes = $o->items->filter(fn ($i) => filled($i->variance_note));
@endphp

@section('actions')
    <a class="btn" href="{{ route('wh.picks') }}">← {{ __('stock.pick_orders') }}</a>
    @if ($o->warehouse)
        <a class="btn" href="{{ route('wh.locations', ['warehouse' => $o->warehouse_id]) }}">🗄️ {{ __('stock.shelf_map') }}</a>
    @endif
    @if ($manager)
        @if ($canStart)
            <form method="POST" action="{{ route('wh.picks.start', $o) }}" style="display:inline">
                @csrf
                <button class="btn" type="submit">▶️ {{ __('stock.start_picking') }}</button>
            </form>
        @endif
        @if ($canReady)
            <button class="btn gold" type="button" onclick="openDlg('dlgReady')">✅ {{ __('stock.mark_ready') }}</button>
        @endif
        @if ($canCancel)
            <form method="POST" action="{{ route('wh.picks.cancel', $o) }}" style="display:inline"
                  onsubmit='return confirm({!! $confirmCancel !!})'>
                @csrf
                <button class="btn red" type="submit">{{ __('stock.cancel_pick_order') }}</button>
            </form>
        @endif
    @endif
@endsection

@section('content')

<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ __('stock.pick_order') }}</div>
        <div class="val">{{ $o->number }}</div>
        <div class="sub2">{{ $o->created_at?->format('Y-m-d H:i') ?? '—' }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('common.status') }}</div>
        <div class="val" style="font-size:17px">
            <span class="badge {{ $o->statusClass() }}">{{ $o->statusLabel() }}</span>
        </div>
        <div class="sub2">
            {{ __('stock.pick_purpose') }}:
            @if ($o->purpose) {{ $o->purposeLabel() }} @else — @endif
        </div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('stock.warehouse') }}</div>
        <div class="val" style="font-size:17px">{{ $o->warehouse?->displayName() ?? '—' }}</div>
        <div class="sub2">{{ $o->warehouse?->typeLabel() ?? '—' }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('ops.rep') }}</div>
        <div class="val" style="font-size:17px">{{ $o->rep?->name ?? '—' }}</div>
        <div class="sub2">{{ $o->rep?->roleLabel() ?? '—' }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('stock.qty_requested') }}</div>
        <div class="val">{{ $fmt($requested) }}</div>
        <div class="sub2">{{ __('stock.units') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('stock.qty_picked') }}</div>
        <div class="val pos">{{ $fmt($picked) }}</div>
        <div class="sub2">{{ __('stock.qty_received_col') }}: {{ $fmt($received) }}</div>
    </div>
</div>

@if ($o->has_variance)
    <div class="alert" style="margin-bottom:14px;flex-direction:column;gap:6px">
        <div><b>⚠️ {{ __('stock.variance') }}</b> —
            {{ __('stock.pick_variance_summary', ['picked' => $fmt($picked), 'received' => $fmt($received)]) }}</div>
        @foreach ($varianceNotes as $vn)
            <div style="font-size:12px;color:var(--muted)">
                {{ $vn->product?->displayName() ?? __('stock.product_hash', ['id' => $vn->product_id]) }} —
                {{ __('stock.variance_note') }}: {{ $vn->variance_note }}
            </div>
        @endforeach
    </div>
@endif

@if ($o->status === 'handed')
    <div class="alert good" style="margin-bottom:14px">
        <span>✅</span>
        <span>
            {{ __('stock.pick_status_handed') }} —
            {{ __('stock.pick_handed_at') }}: {{ $o->handed_at?->format('Y-m-d H:i') ?? '—' }}
            @if ($o->rep)
                • <a href="{{ route('ops.rep', $o->rep) }}" style="font-weight:800;color:var(--primary)">
                    {{ __('ops.rep_card') }}
                </a>
            @endif
        </span>
    </div>
@endif

<div class="card">
    <h3>🧾 {{ __('common.details') }}</h3>
    <div class="frow" style="margin-bottom:0">
        @if ($o->requester)
            <div>
                <label class="f">{{ __('stock.pick_requested_by') }}</label>
                <div>{{ $o->requester->name }}</div>
            </div>
        @endif
        @if ($o->picker)
            <div>
                <label class="f">{{ __('stock.pick_picked_by') }}</label>
                <div>{{ $o->picker->name }}</div>
            </div>
        @endif
        @if ($o->needed_on)
            <div>
                <label class="f">{{ __('stock.pick_needed_on') }}</label>
                <div class="num">{{ $o->needed_on->format('Y-m-d') }}</div>
            </div>
        @endif
        @if ($o->ready_at)
            <div>
                <label class="f">{{ __('stock.pick_ready_at') }}</label>
                <div class="num">{{ $o->ready_at->format('Y-m-d H:i') }}</div>
            </div>
        @endif
        @if ($o->handed_at)
            <div>
                <label class="f">{{ __('stock.pick_handed_at') }}</label>
                <div class="num">{{ $o->handed_at->format('Y-m-d H:i') }}</div>
            </div>
        @endif
        @if ($sourceLabel)
            <div>
                <label class="f">{{ __('stock.pick_source') }}</label>
                <div><a href="{{ $sourceUrl }}" style="font-weight:800;color:var(--primary)">{{ $sourceLabel }}</a></div>
            </div>
        @endif
    </div>

    @if ($o->notes)
        <div style="margin-top:12px">
            <label class="f">{{ __('common.notes') }}</label>
            <div style="white-space:pre-line;font-size:13px;line-height:1.8">{{ $o->notes }}</div>
        </div>
    @endif
</div>

<div class="card">
    <h3>🧺 {{ __('stock.pick_list') }}
        <span class="side">{{ __('stock.pick_list_hint') }}</span></h3>
    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('stock.item') }}</th>
                <th>{{ __('stock.batch_no') }}</th>
                <th>{{ __('stock.expires_on') }}</th>
                <th>{{ __('stock.expiry') }}</th>
                <th>{{ __('stock.location') }}</th>
                <th>{{ __('stock.qty_requested') }}</th>
                <th>{{ __('stock.qty_picked') }}</th>
                <th>{{ __('stock.qty_received_col') }}</th>
            </tr>
            @forelse ($o->items as $item)
                <tr>
                    <td>
                        <b>{{ $item->product?->displayName() ?? __('stock.product_hash', ['id' => $item->product_id]) }}</b>
                        @if ($item->product)
                            <br><span style="font-size:10.5px;color:var(--muted)">{{ $item->product->code }} • {{ $item->product->unitLabel() }}</span>
                        @endif
                    </td>
                    <td class="num">{{ $item->batchNo() }}</td>
                    <td class="num">{{ $item->expiresOn() ?? '—' }}</td>
                    <td>
                        @if ($item->batch)
                            <span class="badge {{ $item->batch->expiryClass() }}">{{ $item->batch->expiryLabel() }}</span>
                        @else
                            <span class="badge b-gray">—</span>
                        @endif
                    </td>
                    <td><b style="font-size:19px;letter-spacing:.5px">{{ $item->locationCode() }}</b></td>
                    <td class="num"><b>{{ $fmt($item->qty_requested) }}</b></td>
                    <td class="num pos">{{ $fmt($item->qty_picked) }}</td>
                    <td class="num {{ $item->hasVariance() ? 'neg' : '' }}">
                        {{ $item->qty_received === null ? '—' : $fmt($item->qty_received) }}
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:28px">
                    {{ __('common.no_results') }}
                </td></tr>
            @endforelse
        </table>
    </div>
</div>

@if ($manager && $canReady)
    <dialog id="dlgReady">
        <form class="dlg" method="POST" action="{{ route('wh.picks.ready', $o) }}" style="width:min(720px,96vw)">
            @csrf
            <h4>{{ __('stock.mark_ready') }} — {{ $o->number }}</h4>

            <div class="alert warn" style="margin-bottom:12px">
                <span>⚠️</span><span>{{ __('stock.pick_ready_warning') }}</span>
            </div>
            <div class="alert info" style="margin-bottom:12px">
                <span>ℹ️</span><span>{{ __('stock.pick_list_hint') }}</span>
            </div>

            <div class="tablewrap" style="max-height:44vh;overflow-y:auto;border:1px solid var(--border);border-radius:10px">
                <table>
                    <tr>
                        <th>{{ __('stock.item') }}</th>
                        <th>{{ __('stock.batch_no') }}</th>
                        <th>{{ __('stock.location') }}</th>
                        <th>{{ __('stock.qty_requested') }}</th>
                        <th>{{ __('stock.qty_picked') }}</th>
                    </tr>
                    @foreach ($o->items as $item)
                        <tr>
                            <td>{{ $item->product?->displayName() ?? __('stock.product_hash', ['id' => $item->product_id]) }}</td>
                            <td class="num">{{ $item->batchNo() }}</td>
                            <td><b style="font-size:15px">{{ $item->locationCode() }}</b></td>
                            <td class="num">{{ $fmt($item->qty_requested) }}</td>
                            <td>
                                <input type="number" name="picked[{{ $item->id }}]" min="0" step="1"
                                       max="{{ (int) $item->qty_requested }}"
                                       value="{{ (int) $item->qty_requested }}" required style="width:96px">
                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>

            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
                <button class="btn" type="button" onclick="closeDlg('dlgReady')">{{ __('common.cancel') }}</button>
                <button class="btn gold" type="submit">{{ __('stock.mark_ready') }}</button>
            </div>
        </form>
    </dialog>
@endif

@endsection
