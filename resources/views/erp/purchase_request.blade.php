@extends('layouts.system')

@section('title', $r->number)

@php
    $user = auth()->user();
    $money = fn ($n) => number_format((float) $n, 2);
    $canCreate = \App\Support\Access::action($user, 'act.purchase_requests.create');
    $canQuote = \App\Support\Access::action($user, 'act.purchase_requests.quote') && $r->isActive();
    $canApprove = \App\Support\Access::action($user, 'act.purchase_quotes.approve') && $r->isActive();
    // الإلغاء: صاحب الطلب أو الإدارة
    $canCancel = $canCreate && $r->isActive()
        && ($r->requested_by === $user->id || in_array($user->role, ['admin', 'manager'], true));
@endphp

@section('actions')
    <a class="btn" href="{{ route('erp.purchase_requests') }}">← {{ __('supplier.purchase_requests') }}</a>
    @if ($canQuote)
        <button class="btn gold" onclick="openDlg('dlgNewQuote')">+ {{ __('supplier.add_quote') }}</button>
    @endif
    @if ($canCancel)
        <form method="POST" action="{{ route('erp.purchase_requests.cancel', $r) }}" style="display:inline"
              onsubmit="return confirm(@js(__('supplier.pr_cancel_confirm')))">
            @csrf
            <button class="btn red" type="submit">{{ __('supplier.pr_cancel') }}</button>
        </form>
    @endif
@endsection

@section('content')

<div class="card">
    <h3>📝 {{ $r->number }}
        <span class="badge {{ $r->statusClass() }}">{{ $r->statusLabel() }}</span>
    </h3>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
        <span class="badge b-gray">{{ $r->product?->displayName() }} ({{ $r->product?->kindLabel() }})</span>
        <span class="badge b-gold">{{ __('common.qty') }}: {{ number_format($r->qty) }}</span>
        @if ($r->needed_by)
            <span class="badge b-blue">{{ __('supplier.needed_by') }}: {{ $r->needed_by->format('Y-m-d') }}</span>
        @endif
        <span class="badge b-gray">{{ __('supplier.requested_by') }}: {{ $r->requester?->name ?? '—' }}</span>
        @if ($r->costCenter)
            <span class="badge b-gray">{{ __('supplier.cost_center') }}: {{ $r->costCenter->displayName() }}</span>
        @endif
        @if ($r->order && \App\Support\Access::allows($user, 'erp.purchasing.show'))
            <a class="badge b-green" href="{{ route('erp.purchasing.show', $r->order) }}">🧺 {{ $r->order->number }}</a>
        @endif
    </div>
    @if ($r->notes)<p style="color:var(--muted)">{{ $r->notes }}</p>@endif
</div>

<div class="card">
    <h3>⚖️ {{ __('supplier.quotes_comparison') }}</h3>
    @if ($quotes->isEmpty())
        <p style="color:var(--muted);text-align:center;padding:20px">{{ __('supplier.no_quotes') }}</p>
    @else
        <div class="tablewrap">
            {{-- عمود لكل عرض: المورد في الرأس وبعده الصفوف --}}
            <table>
                <tr>
                    <th data-nosum></th>
                    @foreach ($quotes as $q)
                        <th data-nosum style="text-align:center">
                            {{ $q->supplier?->displayName() ?? '—' }}
                            @if ($q->status === 'approved')<span class="badge b-green">{{ __('supplier.quote_status_approved') }}</span>@endif
                            @if ($q->status === 'rejected')<span class="badge b-gray">{{ __('supplier.quote_status_rejected') }}</span>@endif
                        </th>
                    @endforeach
                </tr>
                <tr>
                    <td>{{ __('supplier.unit_price') }}</td>
                    @foreach ($quotes as $q)
                        <td class="num" style="text-align:center">
                            <b>{{ $money($q->unit_price) }}</b>
                            @if ((float) $q->unit_price === (float) $cheapest)<br><span class="badge b-green">{{ __('supplier.cheapest') }}</span>@endif
                        </td>
                    @endforeach
                </tr>
                <tr>
                    <td>{{ __('supplier.line_total') }}</td>
                    @foreach ($quotes as $q)
                        <td class="num" style="text-align:center">{{ $money($r->qty * (float) $q->unit_price) }}</td>
                    @endforeach
                </tr>
                <tr>
                    <td>{{ __('supplier.lead_days') }}</td>
                    @foreach ($quotes as $q)
                        <td class="num" style="text-align:center">
                            {{ $q->lead_days !== null ? $q->lead_days : '—' }}
                            @if ($q->lead_days !== null && (int) $q->lead_days === (int) $fastest)<br><span class="badge b-blue">{{ __('supplier.fastest') }}</span>@endif
                        </td>
                    @endforeach
                </tr>
                <tr>
                    <td>{{ __('supplier.payment_terms') }}</td>
                    @foreach ($quotes as $q)
                        <td style="text-align:center">{{ $q->payment_terms ?: '—' }}</td>
                    @endforeach
                </tr>
                <tr>
                    <td>{{ __('supplier.quote_attachment') }}</td>
                    @foreach ($quotes as $q)
                        <td style="text-align:center">
                            @if ($q->attachment_path)
                                <a class="badge b-blue" target="_blank" rel="noopener" href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($q->attachment_path) }}">📎</a>
                            @else — @endif
                        </td>
                    @endforeach
                </tr>
                <tr>
                    <td>{{ __('common.notes') }}</td>
                    @foreach ($quotes as $q)
                        <td class="s" style="text-align:center">{{ $q->notes ?: '—' }}</td>
                    @endforeach
                </tr>
                @if ($canApprove)
                <tr>
                    <td></td>
                    @foreach ($quotes as $q)
                        <td style="text-align:center">
                            <button class="btn green sm" type="button" onclick="openDlg('dlgApprove{{ $q->id }}')">✔ {{ __('supplier.approve_quote') }}</button>
                        </td>
                    @endforeach
                </tr>
                @endif
            </table>
        </div>
    @endif
</div>

@if ($canQuote)
<dialog id="dlgNewQuote">
    <form class="dlg" method="POST" action="{{ route('erp.purchase_requests.quotes.store', $r) }}" enctype="multipart/form-data">
        @csrf
        <h4>+ {{ __('supplier.add_quote') }}</h4>
        <label class="f">{{ __('supplier.supplier') }} *</label>
        <select name="supplier_id" required style="width:100%">
            <option value="">—</option>
            @foreach ($suppliers as $sup)
                <option value="{{ $sup->id }}" @selected((int) old('supplier_id') === $sup->id)>{{ $sup->displayName() }}</option>
            @endforeach
        </select>
        <div class="frow">
            <div>
                <label class="f">{{ __('supplier.unit_price') }} *</label>
                <input type="number" name="unit_price" step="0.01" min="0" required style="width:100%" value="{{ old('unit_price') }}">
            </div>
            <div>
                <label class="f">{{ __('supplier.lead_days') }}</label>
                <input type="number" name="lead_days" step="1" min="0" style="width:100%" value="{{ old('lead_days') }}">
            </div>
        </div>
        <label class="f">{{ __('supplier.payment_terms') }}</label>
        <input type="text" name="payment_terms" maxlength="120" style="width:100%" value="{{ old('payment_terms') }}">
        <label class="f">{{ __('supplier.quote_attachment') }}</label>
        <input type="file" name="attachment" accept=".jpg,.jpeg,.png,.webp,.pdf" style="width:100%">
        <label class="f">{{ __('common.notes') }}</label>
        <textarea name="notes" rows="2" style="width:100%">{{ old('notes') }}</textarea>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgNewQuote')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>
@if ($errors->any() && old('unit_price') !== null)
<script>document.addEventListener("DOMContentLoaded", function(){ var d = document.getElementById("dlgNewQuote"); if (d) d.showModal(); });</script>
@endif
@endif

@if ($canApprove)
@foreach ($quotes as $q)
<dialog id="dlgApprove{{ $q->id }}">
    <form class="dlg" method="POST" action="{{ route('erp.purchase_quotes.approve', $q) }}">
        @csrf
        <h4>✔ {{ __('supplier.approve_quote') }} — {{ $q->supplier?->displayName() }}</h4>
        <p style="color:var(--muted);font-size:12.5px">{{ __('supplier.approve_quote_hint') }}</p>
        <label class="f">{{ __('stock.warehouse') }} *</label>
        <select name="warehouse_id" required style="width:100%">
            <option value="">—</option>
            @foreach ($warehouses as $w)
                <option value="{{ $w->id }}">{{ $w->displayName() }}</option>
            @endforeach
        </select>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgApprove{{ $q->id }}')">{{ __('common.cancel') }}</button>
            <button class="btn green" type="submit">{{ __('supplier.approve_quote') }}</button>
        </div>
    </form>
</dialog>
@endforeach
@endif

@endsection
