@extends('layouts.system')

@section('title', $o->number)

@php
    $money = fn ($n) => number_format((float) $n, 2);
    $fmt = fn ($n) => number_format((float) $n);
    $manager = auth()->user()->canDecideOps();
    // أمين المخزن بيستلم — ده شغله
    $canReceive = auth()->user()->canWorkWarehouse() && $o->isOpen();
    $outstanding = $o->outstanding();
@endphp

@section('actions')
    <a class="btn" href="{{ route('erp.purchasing') }}">← {{ __('supplier.purchase_orders') }}</a>
    @if ($o->isPendingApproval() && \App\Support\Access::action(auth()->user(), 'act.purchasing.approve'))
        <form method="POST" action="{{ route('erp.purchasing.approve', $o) }}" style="display:inline">
            @csrf
            <button class="btn green" type="submit">{{ __('supplier.approve_order') }}</button>
        </form>
    @endif
    @if ($manager && $o->isOpen())
        @if ($o->items->sum('received_qty') === 0)
            <form method="POST" action="{{ route('erp.purchasing.cancel', $o) }}" style="display:inline"
                  onsubmit="return confirm(CANCEL_CONFIRM)">
                @csrf
                <button class="btn red" type="submit">{{ __('supplier.cancel_order') }}</button>
            </form>
        @else
            <form method="POST" action="{{ route('erp.purchasing.close', $o) }}" style="display:inline"
                  onsubmit="return confirm(CLOSE_CONFIRM)">
                @csrf
                <button class="btn" type="submit">{{ __('supplier.close_order') }}</button>
            </form>
        @endif
    @endif
@endsection

@section('content')

<div class="card">
    <h3>📥 {{ $o->number }}
        <span class="side">
            @if (\App\Support\Access::allows(auth()->user(), 'erp.suppliers'))
                <a href="{{ route('erp.suppliers.show', $o->supplier) }}"><b>{{ $o->supplier->displayName() }}</b></a>
            @else
                <b>{{ $o->supplier->displayName() }}</b>
            @endif
            → <a href="{{ route('erp.warehouses.stock', $o->warehouse) }}">{{ $o->warehouse->displayName() }}</a>
        </span>
    </h3>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <span class="badge {{ $o->statusClass() }}">{{ $o->statusLabel() }}</span>
        <span class="badge b-gray">{{ __('supplier.ordered_on') }}: {{ $o->ordered_on->format('Y-m-d') }}</span>
        @if ($o->expected_on)
            <span class="badge {{ $o->isOpen() && $o->expected_on->isPast() ? 'b-red' : 'b-blue' }}">
                {{ __('supplier.expected_on') }}: {{ $o->expected_on->format('Y-m-d') }}
            </span>
        @endif
        <span class="badge b-gold">{{ __('common.total') }}: {{ $money($o->total) }}</span>
        @if ($o->purchaseRequest)
            @if (\App\Support\Access::allows(auth()->user(), 'erp.purchase_requests.show'))
                <a class="badge b-blue" href="{{ route('erp.purchase_requests.show', $o->purchaseRequest) }}">📝 {{ __('supplier.from_request', ['number' => $o->purchaseRequest->number]) }}</a>
            @else
                <span class="badge b-blue">📝 {{ __('supplier.from_request', ['number' => $o->purchaseRequest->number]) }}</span>
            @endif
        @endif
    </div>
    @if ($o->notes)
        <div style="font-size:12px;color:var(--muted);margin-top:8px">{{ $o->notes }}</div>
    @endif
</div>

@if ($o->approval_status && $reasonTexts !== [])
    <div class="card">
        <h3>{{ $o->isPendingApproval() ? __('supplier.approval_needed') : __('supplier.approval_reasons') }}
            <span class="badge {{ $o->isPendingApproval() ? 'b-orange' : ($o->approval_status === 'approved' ? 'b-green' : 'b-red') }}">{{ __('supplier.approval_reasons') }}</span>
        </h3>
        <ul style="margin:0;padding-inline-start:18px;font-size:13px">
            @foreach ($reasonTexts as $text)
                <li>{{ $text }}</li>
            @endforeach
        </ul>
        @if ($o->approval_status !== 'pending' && $o->approved_at)
            <div style="font-size:12px;color:var(--muted);margin-top:6px">{{ __($o->approval_status === 'rejected' ? 'supplier.rejected_by_on' : 'supplier.approved_by_on', ['name' => $approverName ?? '', 'at' => $o->approved_at->format('Y-m-d H:i')]) }}</div>
        @endif
        @if ($o->isPendingApproval() && \App\Support\Access::action(auth()->user(), 'act.purchasing.approve'))
            <form method="POST" action="{{ route('erp.purchasing.reject', $o) }}" style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap">
                @csrf
                <input type="text" name="note" maxlength="500" placeholder="{{ __('supplier.reject_note') }}" style="flex:1;min-width:180px">
                <button class="btn red" type="submit">{{ __('supplier.reject_order') }}</button>
            </form>
        @endif
    </div>
@endif

@if ($errors->any())
    <div class="card"><div class="alert" style="flex-direction:column;align-items:stretch;gap:4px">
        @foreach ($errors->all() as $msg)
            <div class="errline" style="margin:0">{{ $msg }}</div>
        @endforeach
    </div></div>
@endif

{{-- ═══════════ البنود + الاستلام ═══════════ --}}
<div class="card">
    <h3>{{ __('supplier.order_lines') }}
        @if ($canReceive && $outstanding !== [])
            <span class="side">{{ __('supplier.receive_hint') }}</span>
        @endif
    </h3>

    @if ($canReceive && $outstanding !== [])
    <form method="POST" action="{{ route('erp.purchasing.receive', $o) }}" enctype="multipart/form-data">
        @csrf
    @endif

        <div class="tablewrap">
            <table>
                <tr>
                    <th>{{ __('stock.product') }}</th>
                    <th class="num">{{ __('supplier.qty_ordered') }}</th>
                    <th class="num" data-nosum>{{ __('supplier.unit_cost') }}</th>
                    <th class="num">{{ __('supplier.qty_received') }}</th>
                    <th class="num">{{ __('supplier.qty_rejected') }}</th>
                    <th class="num">{{ __('supplier.qty_left') }}</th>
                    @if ($canReceive && $outstanding !== [])
                        <th style="width:110px">{{ __('supplier.receive_now') }}</th>
                        <th style="width:130px">{{ __('stock.batch_no') }}</th>
                        <th style="width:140px" data-nosum>{{ __('stock.produced_on') }}</th>
                        <th style="width:140px" data-nosum>{{ __('stock.expires_on') }}</th>
                        <th style="width:110px" data-nosum>{{ __('supplier.qc') }}</th>
                        <th style="width:160px" data-nosum>{{ __('supplier.qc_note') }}</th>
                        <th style="width:150px" data-nosum>{{ __('supplier.qc_photo') }}</th>
                    @endif
                </tr>
                @foreach ($o->items as $it)
                    @php $left = max(0, $it->qty - $it->received_qty); @endphp
                    <tr>
                        <td><a href="{{ route('erp.products.show', $it->product) }}"><b>{{ $it->product->displayName() }}</b></a>
                            <span class="s" style="color:var(--muted)">· {{ $it->product->code }}</span></td>
                        <td class="num">{{ $fmt($it->qty) }}</td>
                        <td class="num">{{ $money($it->unit_cost) }}</td>
                        <td class="num pos">{{ $fmt($it->received_qty) }}</td>
                        <td class="num {{ $it->rejected_qty > 0 ? 'neg' : '' }}">{{ $fmt($it->rejected_qty) }}</td>
                        <td class="num {{ $left > 0 ? 'mid' : 'pos' }}">{{ $fmt($left) }}</td>
                        @if ($canReceive && $outstanding !== [])
                            @if ($left > 0)
                                <td><input type="number" name="lines[{{ $it->product_id }}][qty]"
                                           min="0" max="{{ $left }}" step="1"
                                           style="width:100%;text-align:center"></td>
                                <td><input type="text" name="lines[{{ $it->product_id }}][batch_no]"
                                           maxlength="60" dir="ltr" style="width:100%"
                                           placeholder="{{ __('supplier.batch_ph') }}"></td>
                                <td><input type="date" name="lines[{{ $it->product_id }}][produced_on]" style="width:100%"></td>
                                <td><input type="date" name="lines[{{ $it->product_id }}][expires_on]" style="width:100%"></td>
                                <td><select name="lines[{{ $it->product_id }}][qc]" style="width:100%">
                                    <option value="pass">{{ __('supplier.qc_pass') }}</option>
                                    <option value="fail">{{ __('supplier.qc_fail') }}</option>
                                </select></td>
                                <td><input type="text" name="lines[{{ $it->product_id }}][qc_note]" maxlength="500" style="width:100%"></td>
                                <td><input type="file" name="lines[{{ $it->product_id }}][qc_photo]" accept="image/*" style="width:100%"></td>
                            @else
                                <td colspan="7" style="text-align:center;color:var(--muted)">✓</td>
                            @endif
                        @endif
                    </tr>
                @endforeach
            </table>
        </div>

    @if ($canReceive && $outstanding !== [])
        {{-- ⚠️ الانتهاء لو اتساب فاضي بيتحسب من الإنتاج + مدة صلاحية الصنف --}}
        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px">
            <span style="font-size:11.5px;color:var(--muted)">{{ __('supplier.expiry_auto_hint') }}<br>{{ __('supplier.raw_fields_hint') }}<br>{{ __('supplier.qc_hint') }}</span>
            <button class="btn gold" type="submit">📦 {{ __('supplier.receive_now') }}</button>
        </div>
    </form>
    @endif
</div>

@if ($o->returns->isNotEmpty() && \App\Support\Access::allows(auth()->user(), 'erp.purchasing.returns'))
<div class="card">
    <h3>↩️ {{ __('supplier.supplier_returns') }} <span class="side">{{ $o->returns->count() }}</span></h3>
    <div class="tablewrap">
        <table>
            <tr><th>{{ __('common.number') }}</th><th data-nosum>{{ __('supplier.returned_on') }}</th><th class="num">{{ __('common.total') }}</th></tr>
            @foreach ($o->returns as $ret)
                <tr class="clickable" onclick="location.href='{{ route('erp.purchasing.returns.show', $ret) }}'">
                    <td class="num"><a href="{{ route('erp.purchasing.returns.show', $ret) }}"><b>{{ $ret->number }}</b></a></td>
                    <td class="num s">{{ $ret->returned_on->format('Y-m-d') }}</td>
                    <td class="num">{{ $money($ret->total) }}</td>
                </tr>
            @endforeach
        </table>
    </div>
</div>
@endif

<div class="grid2">
    {{-- ═══════════ أذون الاستلام ═══════════ --}}
    <div class="card">
        <h3>📄 {{ __('nav.receipts') }} <span class="side">{{ $o->receipts->count() }}</span></h3>
        <div class="tablewrap">
            <table>
                <tr><th>{{ __('common.number') }}</th><th data-nosum>{{ __('common.date') }}</th></tr>
                @forelse ($o->receipts as $r)
                    <tr class="clickable" onclick="location.href='{{ route('wh.receipt', $r) }}'">
                        <td class="num"><a href="{{ route('wh.receipt', $r) }}"><b>{{ $r->number }}</b></a></td>
                        <td class="num s">{{ $r->received_on->format('Y-m-d') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="2" style="text-align:center;color:var(--muted);padding:20px">{{ __('supplier.no_receipts') }}</td></tr>
                @endforelse
            </table>
        </div>
    </div>

    {{-- ═══════════ الفوترة ═══════════ --}}
    <div class="card">
        <h3>🧾 {{ __('supplier.invoices') }}
            @if ($manager && $o->items->sum('received_qty') > 0)
                <span class="side"><button class="btn sm" type="button" onclick="openDlg('dlgInvoice')">+ {{ __('supplier.record_invoice') }}</button></span>
            @endif
        </h3>
        <div class="tablewrap">
            <table>
                <tr><th>{{ __('common.number') }}</th><th>{{ __('supplier.supplier_ref') }}</th><th class="num">{{ __('common.total') }}</th></tr>
                @forelse ($o->invoices as $inv)
                    <tr>
                        <td class="num"><b>{{ $inv->number }}</b></td>
                        <td class="num s">{{ $inv->supplier_ref ?: '—' }}</td>
                        <td class="num">{{ $money($inv->total) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" style="text-align:center;color:var(--muted);padding:20px">
                        {{ __('supplier.not_invoiced_yet') }}
                    </td></tr>
                @endforelse
            </table>
        </div>
    </div>
</div>

@if ($manager)
<dialog id="dlgInvoice">
    <form class="dlg" method="POST" action="{{ route('erp.purchasing.invoice', $o) }}">
        @csrf
        <h4>🧾 {{ __('supplier.record_invoice') }}</h4>
        {{-- الفاتورة بتتحسب بالمستلَم فعلاً × سعر الأمر — مش بمدخل حر --}}
        <div class="alert info" style="margin-bottom:10px"><span>💡</span><span>{{ __('supplier.invoice_hint') }}</span></div>
        <div class="frow">
            <div>
                <label class="f">{{ __('supplier.supplier_ref') }}</label>
                <input type="text" name="supplier_ref" maxlength="60" dir="ltr" style="width:100%"
                       value="{{ old('supplier_ref') }}">
            </div>
            <div>
                <label class="f">{{ __('common.date') }} *</label>
                <input type="date" name="invoice_date" required style="width:100%"
                       value="{{ old('invoice_date', today()->toDateString()) }}">
            </div>
        </div>
        <div style="margin-top:10px">
            <label class="f">{{ __('tax.tax') }}</label>
            <input type="number" name="tax" step="0.01" min="0" style="width:100%" value="{{ old('tax', 0) }}">
        </div>
        <div style="margin-top:10px">
            <label class="f">{{ __('common.notes') }}</label>
            <textarea name="notes" rows="2" style="width:100%">{{ old('notes') }}</textarea>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgInvoice')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>
@endif

@endsection

@section('scripts')
<script>
const CANCEL_CONFIRM = @js(__('supplier.cancel_confirm'));
const CLOSE_CONFIRM = @js(__('supplier.close_confirm'));
</script>
@endsection
