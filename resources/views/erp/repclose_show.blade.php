@extends('layouts.system')

{{--
    تصفية مندوب واحد — المطابقة قدام المحاسب (2026-08-06):
    فواتير الفترة المفتوحة بالتفصيل (كاش/آجل) + مرتجعات الكاش
    ← النقدية المتوقعة + الرصيد السابق = الإجمالي المطلوب
    ← المحاسب يكتب المستلم ويقفل — والفرق بيترحّل دائن/مدين.
--}}

@php $fmt = fn ($n) => number_format((float) $n, 2); @endphp

@section('title', __('settle.title').' — '.$rep->displayName())

@section('actions')
    <a class="btn" href="{{ route('erp.repclose') }}">← {{ __('settle.title') }}</a>
@endsection

@section('content')

@if ($errors->any())
    <div class="alert" style="margin-bottom:12px;flex-direction:column;align-items:stretch;gap:4px">
        @foreach ($errors->all() as $msg)
            <div class="errline" style="margin:0">{{ $msg }}</div>
        @endforeach
    </div>
@endif

<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ __('settle.rep') }}</div>
        <div class="val" style="font-size:17px">{{ $rep->displayName() }}</div>
        <div class="sub2">
            {{ $from_at ? __('settle.since_last').' '.$from_at->format('Y-m-d H:i') : __('settle.since_start') }}
        </div>
    </div>
    <div class="kpi">
        <div class="lbl">💵 {{ __('settle.cash_sales') }}</div>
        <div class="val">{{ $fmt($cash_sales) }}</div>
        <div class="sub2">{{ __('settle.invoice_count', ['count' => $invoices->where('payment', 'cash')->count()]) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">📒 {{ __('settle.credit_sales') }}</div>
        <div class="val" style="color:var(--muted)">{{ $fmt($credit_sales) }}</div>
        <div class="sub2">{{ __('settle.invoice_count', ['count' => $invoices->where('payment', '!=', 'cash')->count()]) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">↩️ {{ __('settle.cash_refunds') }}</div>
        <div class="val mid">{{ $fmt($cash_refunds) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">💰 {{ __('settle.due_total') }}</div>
        <div class="val pos" style="font-size:22px">{{ $fmt($due_total) }}</div>
        <div class="sub2">{{ __('settle.expected') }} {{ $fmt($expected) }} + {{ __('settle.prev_balance') }} {{ $fmt($prev_balance) }}</div>
    </div>
</div>

{{-- ═══ فورم القفل — المستلم متملي بالمطلوب والفرق بيبان لايف ═══ --}}
<div class="card">
    <h3>🤝 {{ __('settle.close_btn') }}</h3>
    <form method="POST" action="{{ route('erp.repclose.store', $rep) }}"
          onsubmit="return confirm(@js(__('settle.close_confirm')))">
        @csrf
        <div class="frow">
            <div>
                <label class="f">{{ __('settle.received') }} <b class="req-star">*</b></label>
                <input type="number" name="received" id="stReceived" step="0.01" min="0" required dir="ltr"
                       value="{{ old('received', $due_total > 0 ? $due_total : 0) }}"
                       style="width:100%;font-weight:900;font-size:16px;text-align:center"
                       oninput="stDiff()">
            </div>
            <div>
                <label class="f">{{ __('settle.balance') }}</label>
                <div id="stBalance" style="padding:9px 13px;border:1px solid var(--border);border-radius:10px;
                     font-weight:900;text-align:center;font-size:15px">—</div>
            </div>
            <div style="flex:2">
                <label class="f">{{ __('settle.note') }}</label>
                <input type="text" name="note" maxlength="500" style="width:100%" value="{{ old('note') }}">
            </div>
        </div>
        <div style="display:flex;justify-content:flex-end;margin-top:12px">
            <button class="btn gold" type="submit">🤝 {{ __('settle.close_btn') }}</button>
        </div>
    </form>
</div>

{{-- ═══ الفواتير للمطابقة — المحاسب بيراجعها مع المندوب ورقة ورقة ═══ --}}
<div class="card">
    <h3>🧾 {{ __('settle.invoices_to_match') }}
        <span class="side">{{ __('settle.invoice_count', ['count' => $invoices->count()]) }}</span></h3>
    <div class="tablewrap st-tbl" style="max-height:52vh;overflow-y:auto">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th style="text-align:start">{{ __('client.client') }}</th>
                    <th>{{ __('common.date') }}</th>
                    <th>{{ __('ops.payment') }}</th>
                    <th>{{ __('common.total') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($invoices as $inv)
                    <tr>
                        <td class="num"><b>{{ $inv->number }}</b></td>
                        <td style="text-align:start">{{ $inv->client?->fullName() ?? '—' }}</td>
                        <td class="num" style="font-size:11px">{{ $inv->created_at->format('m-d H:i') }}</td>
                        <td>
                            @if ($inv->payment === 'cash')
                                <span class="badge b-green">{{ __('settle.payment_cash') }}</span>
                            @else
                                <span class="badge b-orange">{{ __('settle.payment_credit') }}</span>
                            @endif
                        </td>
                        {{-- بالإجمالي — نفس اللي العميل دفعه (عقيدة الليدجر) --}}
                        <td class="num"><b>{{ $fmt($inv->grand_total) }}</b></td>
                        <td><a class="btn sm" href="{{ route('ops.invoice', $inv) }}" target="_blank">👁️</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:24px">{{ __('settle.no_open') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if ($refundRows->isNotEmpty())
    <div class="card">
        <h3>↩️ {{ __('settle.refunds_to_match') }}</h3>
        <div class="tablewrap st-tbl">
            <table>
                <tr>
                    <th style="text-align:start">{{ __('client.client') }}</th>
                    <th>{{ __('common.date') }}</th>
                    <th>{{ __('common.total') }}</th>
                </tr>
                @foreach ($refundRows as $t)
                    <tr>
                        <td style="text-align:start">{{ $t->client?->fullName() ?? '—' }}</td>
                        <td class="num" style="font-size:11px">{{ $t->created_at->format('m-d H:i') }}</td>
                        <td class="num neg"><b>{{ $fmt($t->debit) }}</b></td>
                    </tr>
                @endforeach
            </table>
        </div>
    </div>
@endif

@endsection

@section('scripts')
<style>
.st-tbl th, .st-tbl td { text-align: center; vertical-align: middle; }
</style>
<script>
const ST_DUE = {{ (float) $due_total }};
const ST_OWES = @json(__('settle.rep_owes'));
const ST_CREDIT = @json(__('settle.rep_credit'));
const ST_ZERO = @json(__('settle.settled_zero'));

/** الفرق لايف: المطلوب − المستلم = الرصيد المترحّل */
function stDiff() {
    const received = Number(document.getElementById('stReceived').value || 0);
    const bal = Math.round((ST_DUE - received) * 100) / 100;
    const el = document.getElementById('stBalance');

    if (bal > 0) {
        el.textContent = ST_OWES + ' ' + bal.toLocaleString(undefined, { minimumFractionDigits: 2 });
        el.style.color = 'var(--red, #B00020)';
    } else if (bal < 0) {
        el.textContent = ST_CREDIT + ' ' + Math.abs(bal).toLocaleString(undefined, { minimumFractionDigits: 2 });
        el.style.color = 'var(--green, #1B7A3D)';
    } else {
        el.textContent = ST_ZERO + ' ✓';
        el.style.color = 'var(--green, #1B7A3D)';
    }
}

stDiff();
</script>
@endsection
