@extends('layouts.system')

{{--
    صفحة الشحنة — أمين المخزن المستقبِل بيستلم منها.

    ⚠️ **صفحة مش مودال.** الاستلام كان مودال في جدول التحويلات،
    يعني الصفحة كانت بتطبع مودال لكل شحنة مفتوحة، وأمين المخزن
    المستقبِل مالوش لينك يوصل بيه لشحنته أصلاً — كان لازم يدوّر
    عليها في جدول كل التحويلات. ودلوقتي الإشعار بيوديه هنا مباشرةً.

    المتغيرات: `$t`
--}}

@php
    $fmt = fn ($n) => number_format((float) $n);
    $u = auth()->user();

    // ⚠️ الزرار بيبان للي بيقدر يستلم فعلاً: الاستلام محمي بـ
    // `role:admin,manager,warehouse_keeper` **و**بحارس المخزن.
    // زرار بيرمي 403 أسوأ من زرار مش موجود.
    $mine = $u->isAdmin() || $u->isManager()
        || ($u->isWarehouseKeeper() && (int) $u->warehouse_id === (int) $t->to_warehouse_id);
    $canReceive = $t->isOpen() && $mine;
@endphp

@section('title', $t->number)

@section('actions')
    <a class="btn" href="{{ route('wh.transfers') }}">← {{ __('stock.transfers') }}</a>
    <a class="btn" href="{{ route('wh.transfers.print', $t) }}">🖨️ {{ __('stock.issue_note') }}</a>
    @if ($t->status === 'received')
        <a class="btn gold" href="{{ route('wh.transfers.receipt_print', $t) }}">🖨️ {{ __('stock.receipt_note') }}</a>
    @endif
@endsection

@section('content')

<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ __('stock.transfer') }}</div>
        <div class="val">{{ $t->number }}</div>
        <div class="sub2"><span class="badge {{ $t->statusClass() }}">{{ $t->statusLabel() }}</span></div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('stock.from_warehouse') }}</div>
        <div class="val" style="font-size:16px">{{ $t->fromWarehouse?->displayName() ?? '—' }}</div>
        <div class="sub2">{{ __('stock.sent_by') }}: {{ $t->sender?->displayName() ?? '—' }}
            · {{ $t->sent_on?->format('Y-m-d') ?? '—' }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('stock.to_warehouse') }}</div>
        <div class="val" style="font-size:16px">{{ $t->toWarehouse?->displayName() ?? '—' }}</div>
        @if ($t->carrier_name)<div class="sub2">{{ __('stock.carrier') }}: {{ $t->carrier_name }}</div>@endif
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('stock.qty_sent') }}</div>
        <div class="val">{{ $fmt($t->qtySent()) }}</div>
        @if ($t->status === 'received')
            <div class="sub2">
                {{ __('stock.qty_received') }} {{ $fmt($t->qtyReceived()) }}
                @if ($t->qtyShort() > 0)
                    · <span class="neg">{{ __('stock.shortage') }} {{ $fmt($t->qtyShort()) }}</span>
                @endif
            </div>
        @endif
    </div>
</div>

@if ($canReceive)
    {{-- ⚠️ التنبيه ده مش زينة: الاستلام بينزّل بضاعة في المخزن
         وبيثبّت العجز على المخزن المرسل. اللي بيدوس لازم يعرف إن
         الرقم اللي بيكتبه هو اللي هيتحاسب عليه حد. --}}
    <div class="alert warn" style="margin-bottom:14px">
        <span>⚠️</span><span>{{ __('stock.receive_warning') }}</span>
    </div>
@endif

<div class="card">
    <h3>📦 {{ __('stock.transfer_items') }}</h3>

    <form method="POST" action="{{ route('wh.transfers.receive', $t) }}">
        @csrf
        @if ($errors->any())
            <div class="alert" style="margin-bottom:12px;flex-direction:column;align-items:stretch;gap:4px">
                @foreach ($errors->all() as $msg)
                    <div class="errline" style="margin:0">{{ $msg }}</div>
                @endforeach
            </div>
        @endif

        <div class="tablewrap">
            <table>
                <tr>
                    <th>{{ __('stock.item') }}</th>
                    <th>{{ __('stock.batch_no') }}</th>
                    <th>{{ __('stock.produced_on') }}</th>
                    <th>{{ __('stock.expires_on') }}</th>
                    <th class="num">{{ __('stock.qty_sent') }}</th>
                    <th class="num">{{ __('stock.qty_received') }}</th>
                    <th class="num">{{ __('stock.variance') }}</th>
                </tr>
                @foreach ($t->items as $it)
                    @php $v = (int) ($it->qty_received ?? $it->qty_sent) - (int) $it->qty_sent; @endphp
                    <tr>
                        <td>
                            <b>{{ $it->product?->displayName() ?? '—' }}</b>
                            <div style="font-size:10.5px;color:var(--muted)">
                                {{ $it->product?->code }} · {{ $it->product?->unitLabel() }}
                            </div>
                        </td>
                        <td class="num">{{ $it->batch_no }}</td>
                        <td class="num">
                            @if ($canReceive)
                                {{-- ⚠️ **قابل للتعديل عن قصد.** الورقة اللي على
                                     الكرتونة هي الحقيقة؛ اللي بعت ممكن يكون كتب
                                     التاريخ غلط، والتاريخ الغلط معناه صلاحية غلط
                                     وترتيب FEFO غلط لكل مرة الباتش ده يخرج بعدها. --}}
                                <input type="date" name="produced[{{ $it->id }}]" style="width:150px"
                                       value="{{ old('produced.'.$it->id, $it->produced_on?->toDateString()) }}">
                            @else
                                {{ $it->produced_on?->format('Y-m-d') ?? '—' }}
                            @endif
                        </td>
                        <td class="num" style="color:var(--muted)">{{ $it->expires_on?->format('Y-m-d') ?? '—' }}</td>
                        <td class="num"><b>{{ $fmt($it->qty_sent) }}</b></td>
                        <td class="num">
                            @if ($canReceive)
                                <input type="number" min="0" max="{{ (int) $it->qty_sent }}" style="width:100px"
                                       name="received[{{ $it->id }}]"
                                       value="{{ old('received.'.$it->id, (int) $it->qty_sent) }}"
                                       data-sent="{{ (int) $it->qty_sent }}" data-item="{{ $it->id }}"
                                       oninput="syncVar({{ $it->id }})">
                            @else
                                {{ $t->status === 'received' ? $fmt($it->qty_received) : '—' }}
                            @endif
                        </td>
                        <td class="num" id="var{{ $it->id }}">
                            @if ($canReceive || $t->status === 'received')
                                <b class="{{ $v < 0 ? 'neg' : '' }}">{{ $v === 0 ? '—' : $fmt($v) }}</b>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>

        @if ($canReceive)
            <div style="margin:14px 0">
                <label class="f">{{ __('common.notes') }}</label>
                <textarea name="notes" rows="2" style="width:100%">{{ old('notes') }}</textarea>
            </div>

            <div style="display:flex;gap:8px;justify-content:flex-end">
                <button class="btn gold" type="submit">📥 {{ __('stock.receive_transfer') }}</button>
            </div>
        @endif
    </form>

    @if ($t->notes && ! $canReceive)
        <div style="margin-top:12px;font-size:12px;color:var(--muted)">
            {{ __('common.notes') }}: {{ $t->notes }}
        </div>
    @endif
</div>

@if ($t->status === 'received' && $t->goods_receipt_id)
    <div class="card">
        <h3>📥 {{ __('stock.goods_receipt') }}</h3>
        <a class="btn" href="{{ route('wh.receipt', $t->goods_receipt_id) }}">
            {{ __('stock.goods_receipt') }} ←
        </a>
    </div>
@endif

@endsection

@section('scripts')
<script>
/** الفرق بيتحدّث وانت بتكتب — قبل ما تدوس استلام مش بعده. */
function syncVar(id) {
    const input = document.querySelector('[data-item="' + id + '"]');
    const cell = document.getElementById('var' + id);

    if (!input || !cell) return;

    const diff = Number(input.value || 0) - Number(input.dataset.sent || 0);

    cell.innerHTML = '<b class="' + (diff < 0 ? 'neg' : '') + '">'
        + (diff === 0 ? '—' : diff.toLocaleString()) + '</b>';
}
</script>
@endsection
