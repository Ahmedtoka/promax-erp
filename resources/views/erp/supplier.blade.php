@extends('layouts.system')

@section('title', $s->displayName())

@php
    $money = fn ($n) => number_format((float) $n, 2);
    $manager = auth()->user()->canDecideOps();
    // ⚠️ المحاسب بيدفع — نفس فلسفة تحصيل العملاء
    $canPay = auth()->user()->canWorkMoney();
@endphp

@section('actions')
    <a class="btn" href="{{ route('erp.suppliers') }}">← {{ __('supplier.all_suppliers') }}</a>
    @if ($manager)
        <button class="btn" onclick="openDlg('dlgEditSup')">✎ {{ __('common.edit') }}</button>
        <button class="btn" onclick="openDlg('dlgOpening')">{{ __('client.opening_balance') }}</button>
    @endif
    @if ($canPay)
        <button class="btn green" onclick="openDlg('dlgPay')">+ {{ __('supplier.pay') }}</button>
    @endif
@endsection

@section('content')

<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ __('supplier.balance') }}</div>
        {{-- موجب = علينا له --}}
        <div class="val {{ (float) $s->balance > 0 ? 'neg' : 'pos' }}">{{ $money($s->balance) }}</div>
        <div class="sub2">{{ (float) $s->balance > 0 ? __('supplier.we_owe') : __('supplier.settled') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('supplier.purchase_orders') }}</div>
        {{-- ⚠️ عدادات من الكنترولر — العلاقة مقصوصة على آخر 20 --}}
        <div class="val">{{ $orderCount }}</div>
        <div class="sub2">{{ $openCount }} {{ __('supplier.status_open') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('supplier.payment_days') }}</div>
        <div class="val">{{ $s->payment_days ?? '—' }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('common.phone') }}</div>
        <div class="val" style="font-size:16px" dir="ltr">{{ $s->phone ?: '—' }}</div>
        <div class="sub2">{{ $s->contact_person }}</div>
    </div>
</div>

@if ($errors->any())
    <div class="card"><div class="alert" style="flex-direction:column;align-items:stretch;gap:4px">
        @foreach ($errors->all() as $msg)
            <div class="errline" style="margin:0">{{ $msg }}</div>
        @endforeach
    </div></div>
@endif

<div class="grid2">
    {{-- ═══════════ كشف الحساب ═══════════ --}}
    <div class="card">
        <h3>📒 {{ __('supplier.statement') }}</h3>
        <div class="tablewrap">
            <table>
                <tr>
                    <th>{{ __('common.date') }}</th>
                    <th>{{ __('supplier.txn_kind') }}</th>
                    <th>{{ __('common.notes') }}</th>
                    <th class="num">{{ __('supplier.debit') }}</th>
                    <th class="num">{{ __('supplier.credit') }}</th>
                </tr>
                @forelse ($txns as $t)
                    <tr>
                        <td class="num s">{{ $t->date->format('Y-m-d') }}</td>
                        <td><span class="badge {{ $t->kind === 'payment' ? 'b-green' : ($t->kind === 'invoice' ? 'b-orange' : 'b-gray') }}">{{ $t->kindLabel() }}</span></td>
                        <td style="font-size:11.5px;color:var(--muted)">{{ $t->memo ?: '—' }}</td>
                        <td class="num pos">{{ (float) $t->debit > 0 ? $money($t->debit) : '—' }}</td>
                        <td class="num neg">{{ (float) $t->credit > 0 ? $money($t->credit) : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:24px">{{ __('supplier.no_txns') }}</td></tr>
                @endforelse
            </table>
        </div>
        <div class="pag">{{ $txns->links('pagination::simple-default') }}</div>
    </div>

    {{-- ═══════════ أوامر الشراء ═══════════ --}}
    <div class="card">
        <h3>📥 {{ __('supplier.purchase_orders') }}
            @if ($manager)
                <span class="side"><a class="btn sm" href="{{ route('erp.purchasing.new') }}?supplier={{ $s->id }}">+ {{ __('supplier.new_order') }}</a></span>
            @endif
        </h3>
        <div class="tablewrap">
            <table>
                <tr>
                    <th>{{ __('common.number') }}</th>
                    <th>{{ __('common.date') }}</th>
                    <th class="num">{{ __('common.total') }}</th>
                    <th>{{ __('common.status') }}</th>
                </tr>
                @forelse ($s->orders as $o)
                    <tr class="clickable" onclick="location.href='{{ route('erp.purchasing.show', $o) }}'">
                        <td class="num"><b>{{ $o->number }}</b></td>
                        <td class="num s">{{ $o->ordered_on->format('Y-m-d') }}</td>
                        <td class="num">{{ $money($o->total) }}</td>
                        <td><span class="badge {{ $o->statusClass() }}">{{ $o->statusLabel() }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="4" style="text-align:center;color:var(--muted);padding:24px">{{ __('supplier.no_orders') }}</td></tr>
                @endforelse
            </table>
        </div>
    </div>
</div>

<div class="grid2">
    {{-- ═══════════ الفواتير ═══════════ --}}
    <div class="card">
        <h3>🧾 {{ __('supplier.invoices') }}</h3>
        <div class="tablewrap">
            <table>
                <tr>
                    <th>{{ __('common.number') }}</th>
                    <th>{{ __('supplier.supplier_ref') }}</th>
                    <th>{{ __('common.date') }}</th>
                    <th>{{ __('supplier.due_on') }}</th>
                    <th class="num">{{ __('common.total') }}</th>
                </tr>
                @forelse ($invoices as $inv)
                    <tr>
                        <td class="num"><b>{{ $inv->number }}</b></td>
                        <td class="num s">{{ $inv->supplier_ref ?: '—' }}</td>
                        <td class="num s">{{ $inv->invoice_date->format('Y-m-d') }}</td>
                        <td class="num s {{ $inv->due_on && $inv->due_on->isPast() ? 'neg' : '' }}">
                            {{ $inv->due_on?->format('Y-m-d') ?? '—' }}
                        </td>
                        <td class="num">{{ $money($inv->total) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:24px">{{ __('supplier.no_invoices') }}</td></tr>
                @endforelse
            </table>
        </div>
    </div>

    {{-- ═══════════ الدفعات ═══════════ --}}
    <div class="card">
        <h3>💸 {{ __('supplier.payments') }}</h3>
        <div class="tablewrap">
            <table>
                <tr>
                    <th>{{ __('common.number') }}</th>
                    <th>{{ __('common.date') }}</th>
                    <th>{{ __('supplier.method') }}</th>
                    <th class="num">{{ __('common.amount') }}</th>
                </tr>
                @forelse ($payments as $p)
                    <tr>
                        <td class="num"><b>{{ $p->number }}</b></td>
                        <td class="num s">{{ $p->paid_on->format('Y-m-d') }}</td>
                        <td><span class="badge b-gray">{{ $p->methodLabel() }}</span></td>
                        <td class="num pos">{{ $money($p->amount) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" style="text-align:center;color:var(--muted);padding:24px">{{ __('supplier.no_payments') }}</td></tr>
                @endforelse
            </table>
        </div>
    </div>
</div>

{{-- ═══════════ المودالات ═══════════ --}}
@if ($manager)
<dialog id="dlgEditSup">
    <form class="dlg" method="POST" action="{{ route('erp.suppliers.update', $s) }}">
        @csrf @method('PUT')
        <h4>✎ {{ $s->displayName() }}</h4>
        @include('erp._supplier_fields', ['s' => $s])
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgEditSup')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>

<dialog id="dlgOpening">
    <form class="dlg" method="POST" action="{{ route('erp.suppliers.opening', $s) }}">
        @csrf
        <h4>{{ __('client.opening_balance') }}</h4>
        <div class="alert info" style="margin-bottom:10px"><span>💡</span><span>{{ __('supplier.opening_hint') }}</span></div>
        <div class="frow">
            <div>
                <label class="f">{{ __('common.amount') }} *</label>
                <input type="number" name="amount" step="0.01" required style="width:100%"
                       value="{{ old('amount') }}">
            </div>
            <div>
                <label class="f">{{ __('common.date') }} *</label>
                <input type="date" name="date" required style="width:100%"
                       value="{{ old('date', today()->toDateString()) }}">
            </div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgOpening')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>
@endif

@if ($canPay)
<dialog id="dlgPay">
    <form class="dlg" method="POST" action="{{ route('erp.suppliers.pay', $s) }}">
        @csrf
        <h4>💸 {{ __('supplier.pay_to', ['name' => $s->displayName()]) }}</h4>
        <div class="frow">
            <div>
                <label class="f">{{ __('common.amount') }} *</label>
                <input type="number" name="amount" step="0.01" min="0.01" required style="width:100%"
                       value="{{ old('amount') }}">
            </div>
            <div>
                <label class="f">{{ __('common.date') }} *</label>
                <input type="date" name="paid_on" required style="width:100%"
                       value="{{ old('paid_on', today()->toDateString()) }}">
            </div>
        </div>
        <div class="frow" style="margin-top:10px">
            <div>
                <label class="f">{{ __('supplier.method') }} *</label>
                <select name="method" required style="width:100%">
                    @foreach (\App\Models\SupplierPayment::METHODS as $m)
                        <option value="{{ $m }}" @selected(old('method') === $m)>{{ __('supplier.method_'.$m) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="f">{{ __('supplier.reference') }}</label>
                <input type="text" name="reference" maxlength="80" style="width:100%"
                       value="{{ old('reference') }}" placeholder="{{ __('supplier.reference_ph') }}">
            </div>
        </div>
        <div style="margin-top:10px">
            <label class="f">{{ __('common.notes') }}</label>
            <textarea name="notes" rows="2" style="width:100%">{{ old('notes') }}</textarea>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgPay')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>
@endif

@endsection
