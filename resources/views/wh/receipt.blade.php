@extends('layouts.system')

@section('title', __('stock.goods_receipt').' '.$receipt->number)

@php
    $fmt = fn ($n) => number_format((float) $n);
    // ⚠️ **أمين المخزن لازم يشوف الأزرار دي — دي شغله.** كانت
    // `isManager()` وهو مش منهم، فالراوتس اتديتله والأزرار اتخبّت
    // عنه: مخزن للقراية بس.
    $manager = auth()->user()->canWorkWarehouse();
    $totalQty = $receipt->totalQty();
    $unshelved = $receipt->unshelvedQty();
    $shelved = max($totalQty - $unshelved, 0);
@endphp

@section('actions')
    <a class="btn" href="{{ route('wh.receipts', ['warehouse' => $receipt->warehouse_id]) }}">← {{ __('stock.goods_receipts') }}</a>
    {{-- باك أب كامل — يترفع تاني من شاشة الاستيراد (نوع «المخزون») كرصيد أول مدة --}}
    <a class="btn" href="{{ route('wh.receipt.export', $receipt) }}">⬇️ {{ __('stock.export_receipt') }}</a>
    <a class="btn" href="{{ route('wh.locations', ['warehouse' => $receipt->warehouse_id]) }}">🗄️ {{ __('stock.shelf_map') }}</a>
@endsection

@section('content')

<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ __('stock.receipt_number') }}</div>
        <div class="val">{{ $receipt->number }}</div>
        <div class="sub2">{{ __('stock.received_on') }}: {{ $receipt->received_on?->format('Y-m-d') ?? '—' }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('stock.warehouse') }}</div>
        <div class="val" style="font-size:17px">{{ $receipt->warehouse?->displayName() ?? '—' }}</div>
        <div class="sub2">{{ $receipt->warehouse?->typeLabel() }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('stock.supplier') }}</div>
        <div class="val" style="font-size:17px">
            {{ $receipt->sourceWarehouse?->displayName() ?? ($receipt->supplier ?: '—') }}
        </div>
        <div class="sub2">
            {{ __('stock.reference') }}: {{ $receipt->reference ?: '—' }}
            @if ($receipt->creator) • {{ __('stock.created_by') }}: {{ $receipt->creator->name }} @endif
        </div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('stock.total_units') }}</div>
        <div class="val">{{ $fmt($totalQty) }}</div>
        <div class="sub2">{{ __('stock.batch_countable', ['count' => $receipt->batches->count()]) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('stock.shelved') }}</div>
        <div class="val pos">{{ $fmt($shelved) }}</div>
        <div class="sub2">{{ __('stock.unshelved') }}: {{ $fmt($unshelved) }}</div>
    </div>
</div>

@if ($unshelved > 0)
    <div class="alert warn" style="margin-bottom:14px"><span>📦</span><span>{{ __('stock.awaiting_putaway_hint') }}</span></div>
@else
    <div class="alert good" style="margin-bottom:14px"><span>✅</span><span>{{ __('stock.all_shelved') }}</span></div>
@endif

@if ($receipt->notes)
    <div class="card"><h3>{{ __('common.notes') }}</h3>
        <div style="white-space:pre-line;font-size:13px;line-height:1.8">{{ $receipt->notes }}</div>
    </div>
@endif

<div class="card">
    <h3>📦 {{ __('stock.receipt_lines') }}
        <span class="side">{{ __('stock.put_away_hint') }}</span></h3>
    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('stock.item') }}</th>
                <th>{{ __('stock.batch_no') }}</th>
                <th>{{ __('stock.produced_on') }}</th>
                <th>{{ __('stock.expires_on') }}</th>
                <th>{{ __('stock.expiry') }}</th>
                <th>{{ __('stock.qty_received') }}</th>
                <th>{{ __('stock.shelved') }}</th>
                <th>{{ __('stock.unshelved') }}</th>
                <th>{{ __('stock.locations') }}</th>
                @if ($manager)<th></th>@endif
            </tr>
            @forelse ($receipt->batches as $b)
                @php $left = $b->unshelvedQty(); @endphp
                <tr>
                    <td>
                        <b>{{ $b->product?->displayName() ?? __('stock.product_hash', ['id' => $b->product_id]) }}</b>
                        @if ($b->product)
                            <br><span style="font-size:10.5px;color:var(--muted)">{{ $b->product->code }} • {{ $b->product->unitLabel() }}</span>
                        @endif
                    </td>
                    <td class="num"><b>{{ $b->batch_no }}</b></td>
                    <td class="num">{{ $b->produced_on?->format('Y-m-d') ?? '—' }}</td>
                    <td class="num">{{ $b->expires_on?->format('Y-m-d') ?? '—' }}</td>
                    <td><span class="badge {{ $b->expiryClass() }}">{{ $b->expiryLabel() }}</span></td>
                    <td class="num">{{ $fmt($b->qty_received) }}</td>
                    <td class="num pos">{{ $fmt($b->shelvedQty()) }}</td>
                    <td class="num {{ $left > 0 ? 'mid' : '' }}"><b>{{ $fmt($left) }}</b></td>
                    <td style="font-size:11.5px;color:var(--muted);white-space:normal;max-width:240px">{{ $b->locationCodes() }}</td>
                    @if ($manager)
                        <td>
                            <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                                @if ($left > 0)
                                    <button class="btn sm gold" type="button" onclick="openDlg('dlgPut{{ $b->id }}')">
                                        {{ __('stock.put_away') }}
                                    </button>
                                @else
                                    <span class="badge b-green">{{ __('stock.fully_shelved') }}</span>
                                @endif
                                {{-- تعديل التواريخ/الرقم/التكلفة — الكميات من الجرد بس --}}
                                <button class="btn sm" type="button" onclick="openDlg('dlgEditB{{ $b->id }}')"
                                        title="{{ __('stock.edit_batch') }}">✎</button>
                            </div>
                        </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $manager ? 10 : 9 }}" style="text-align:center;color:var(--muted);padding:28px">
                    {{ __('stock.no_batches') }}
                </td></tr>
            @endforelse
        </table>
    </div>
</div>

{{-- أكواد الأرفف — قائمة واحدة بتستخدمها كل الديالوجات --}}
<datalist id="whLocCodes">
    @foreach ($locations as $loc)
        <option value="{{ $loc->code }}">{{ $loc->is_pick_face ? __('stock.pick_face') : __('stock.location') }}</option>
    @endforeach
</datalist>

@if ($manager)
    {{-- ═══ تعديل الباتش: رقم + تواريخ + تكلفة (2026-08-05) ═══ --}}
    @foreach ($receipt->batches as $b)
        <dialog id="dlgEditB{{ $b->id }}">
            <form class="dlg" method="POST" action="{{ route('wh.batch.update', $b) }}">
                @csrf
                <h4>✎ {{ __('stock.edit_batch') }} — {{ $b->product?->displayName() }}</h4>
                <div class="alert info" style="margin-bottom:12px">
                    <span>ℹ️</span><span>{{ __('stock.batch_edit_hint') }}</span>
                </div>
                <div class="frow">
                    <div>
                        <label class="f">{{ __('stock.batch_no') }}</label>
                        <input type="text" name="batch_no" required maxlength="40"
                               value="{{ $b->batch_no }}" style="width:100%">
                    </div>
                    <div>
                        <label class="f">{{ __('stock.produced_on') }}</label>
                        <input type="date" name="produced_on"
                               value="{{ $b->produced_on?->format('Y-m-d') }}" style="width:100%">
                    </div>
                    <div>
                        <label class="f">{{ __('stock.expires_on') }} <b class="req-star">*</b></label>
                        <input type="date" name="expires_on" required
                               value="{{ $b->expires_on?->format('Y-m-d') }}" style="width:100%">
                    </div>
                    <div>
                        <label class="f">{{ __('stock.cost') }}</label>
                        <input type="number" name="cost" min="0" step="0.01"
                               value="{{ (float) $b->cost }}" style="width:100%">
                    </div>
                </div>
                <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
                    <button class="btn" type="button" onclick="closeDlg('dlgEditB{{ $b->id }}')">{{ __('common.cancel') }}</button>
                    <button class="btn gold" type="submit">{{ __('common.save') }}</button>
                </div>
            </form>
        </dialog>
    @endforeach

    @foreach ($receipt->batches as $b)
        @if ($b->unshelvedQty() > 0)
            <dialog id="dlgPut{{ $b->id }}">
                <form class="dlg" method="POST" action="{{ route('wh.putaway', $b) }}">
                    @csrf
                    <h4>{{ __('stock.put_away') }} — {{ $b->product?->displayName() }} / {{ $b->batch_no }}</h4>
                    <div class="alert info" style="margin-bottom:12px">
                        <span>🏷️</span><span>{{ __('stock.put_away_hint') }}</span>
                    </div>
                    <div class="frow">
                        <div>
                            <label class="f">{{ __('stock.location') }}</label>
                            <input type="text" name="location_code" list="whLocCodes" required autofocus
                                   autocomplete="off" maxlength="20" placeholder="A03"
                                   style="width:100%;text-transform:uppercase"
                                   oninput="this.value = this.value.toUpperCase()">
                        </div>
                        <div>
                            <label class="f">{{ __('common.qty') }}</label>
                            <input type="number" name="qty" min="1" step="1" max="{{ $b->unshelvedQty() }}"
                                   value="{{ $b->unshelvedQty() }}" required style="width:100%">
                        </div>
                    </div>
                    <div style="font-size:11px;color:var(--muted)">
                        {{ __('stock.unshelved') }}: {{ $fmt($b->unshelvedQty()) }} •
                        {{ __('stock.expiry') }}: {{ $b->expiryLabel() }}
                    </div>
                    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
                        <button class="btn" type="button" onclick="closeDlg('dlgPut{{ $b->id }}')">{{ __('common.cancel') }}</button>
                        <button class="btn gold" type="submit">{{ __('stock.put_away') }}</button>
                    </div>
                </form>
            </dialog>
        @endif
    @endforeach
@endif

@endsection
