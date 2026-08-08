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
                {{-- ⚠️ **الخانة بتفضل فاضية** (قرار المالك 2026-08-08).
                     كانت بتتملي بالمطلوب سلفاً — فالمحاسب المستعجل
                     بيدوس «قفل» على طول والتصفية بتقفل بصفر فرق مهما
                     كان اللي استلمه فعلاً. الرقم ده لازم يتكتب بإيد
                     اللي عدّ الفلوس، وده كل معنى التصفية.
                     و`autocomplete="off"` عشان المتصفح مايقترحش رقم
                     تصفية امبارح. --}}
                <input type="number" name="received" id="stReceived" step="0.01" min="0" required dir="ltr"
                       value="{{ old('received') }}" autocomplete="off"
                       placeholder="{{ __('settle.received_ph') }}"
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

{{-- ═══════════════════════════════════════════════════════════
     الفلوس دي لمين؟ — تفصيلة الكاش والآجل بالعميل
     ═══════════════════════════════════════════════════════════
     ⚠️ **السؤال ده بيتسأل في كل تصفية.** «الـ2,590 آجل دول على
     مين؟» — وقايمة 14 فاتورة مابتجاوبش، بينما 3 عملاء بأرقامهم
     بيجاوبوا في ثانية. --}}
<div class="grid2">
    @foreach ([
        ['rows' => $cashByClient, 'title' => __('settle.cash_sales'), 'icon' => '💵', 'cls' => 'pos'],
        ['rows' => $creditByClient, 'title' => __('settle.credit_sales'), 'icon' => '📄', 'cls' => 'mid'],
    ] as $box)
        <div class="card">
            <h3>{{ $box['icon'] }} {{ $box['title'] }}
                <span class="side">{{ __('settle.client_countable', ['count' => $box['rows']->count()]) }}</span></h3>
            <div class="tablewrap">
                <table>
                    <thead>
                    <tr>
                        <th>{{ __('client.client') }}</th>
                        <th>{{ __('settle.invoices') }}</th>
                        <th>{{ __('common.qty') }}</th>
                        <th>{{ __('common.total') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($box['rows'] as $r)
                        <tr class="clickable"
                            onclick="location.href='{{ route('erp.clients.show', $r['client']) }}'">
                            <td><b>{{ $r['client']?->fullName() ?? '—' }}</b></td>
                            <td class="num">{{ $r['count'] }}</td>
                            {{-- ⚠️ القطع مجموع `invoice_items.qty` — بالقطعة
                                 دايماً، لأن الفاتورة بتتخزن بالقطع مهما كانت
                                 الوحدة اللي المندوب كتب بيها --}}
                            <td class="num">{{ number_format($r['qty']) }}</td>
                            <td class="num {{ $box['cls'] }}">{{ number_format($r['total'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" style="text-align:center;color:var(--muted);padding:22px">
                            {{ __('settle.none') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach
</div>

{{-- ═══════════════════════════════════════════════════════════
     مطابقة العهدة — بالقطع مش بالفلوس
     ═══════════════════════════════════════════════════════════
     ⚠️ **التصفية كانت بتقفل الفلوس وتسيب البضاعة.** المحاسب بيستلم
     كاش ويمضي، والعربية فيها بضاعة محدش عدّها — فالعجز مابيظهرش غير
     في الجرد الشهري، وساعتها محدش يعرف حصل إمتى ولا مع مين. --}}
<div class="card">
    <h3>📦 {{ __('settle.goods_match') }}
        <span class="side">{{ __('settle.goods_formula') }}</span></h3>

    <div class="kpis">
        <div class="kpi"><div class="lbl">{{ __('settle.loaded') }}</div>
            <div class="val">{{ number_format($goods['assigned']) }}</div>
            <div class="sub2">{{ __('common.piece') }}</div></div>
        <div class="kpi"><div class="lbl">{{ __('settle.sold_cash') }}</div>
            <div class="val pos">{{ number_format($goods['cash_qty']) }}</div>
            <div class="sub2">{{ number_format($goods['cash_value'], 2) }}</div></div>
        <div class="kpi"><div class="lbl">{{ __('settle.sold_credit') }}</div>
            <div class="val mid">{{ number_format($goods['credit_qty']) }}</div>
            <div class="sub2">{{ number_format($goods['credit_value'], 2) }}</div></div>
        {{-- ⚠️ **حد ناقص كان بيبلع القطع** (تدقيق ٨/٨) — المسلَّم
             بأوامر التوريد بيخصم من العهدة من غير فاتورة. --}}
        <div class="kpi"><div class="lbl">{{ __('settle.delivered_pos') }}</div>
            <div class="val mid">{{ number_format($goods['po_qty']) }}</div></div>
        <div class="kpi"><div class="lbl">{{ __('settle.gifts') }}</div>
            <div class="val">{{ number_format($goods['gift_qty']) }}</div>
            <div class="sub2">{{ __('settle.gift_left') }}: {{ number_format($goods['gift_left_qty']) }}</div></div>
        <div class="kpi"><div class="lbl">{{ __('settle.returned_wh') }}</div>
            <div class="val">{{ number_format($goods['returned_wh_qty']) }}</div></div>
        <div class="kpi"><div class="lbl">{{ __('settle.still_on_van') }}</div>
            <div class="val" style="color:var(--primary)">{{ number_format($goods['remaining_qty']) }}</div></div>
        {{-- ⚠️ **بره المعادلة بقصد.** دي بضاعة العملاء اللي في العربية
             ومالهاش أصل في المحمَّل — لازم تتسلّم مع التصفية. --}}
        <div class="kpi"><div class="lbl">{{ __('settle.returned_in') }}</div>
            <div class="val" style="color:var(--purple-heart)">{{ number_format($goods['returned_qty']) }}</div>
            <div class="sub2">{{ __('settle.returned_in_hint') }}</div></div>
        {{-- ⚠️ **التالف منفصل** — بيتسلّم للمخزن لوحده ومابيرجعش للبيع --}}
        <div class="kpi"><div class="lbl">{{ __('field.return_damaged_units') }}</div>
            <div class="val {{ $goods['damaged_qty'] > 0 ? 'neg' : '' }}">{{ number_format($goods['damaged_qty']) }}</div></div>
        {{-- ⚠️ **الفرق مش خطأ حسابي.** فرق ≠ صفر معناه بضاعة خرجت من
             العربية من غير فاتورة ولا هدية ولا مرتجع — عجز حقيقي. --}}
        <div class="kpi">
            <div class="lbl">{{ __('settle.shortage') }}</div>
            <div class="val {{ $goods['diff_qty'] == 0 ? 'pos' : 'neg' }}">
                {{ $goods['diff_qty'] == 0 ? '0 ✓' : number_format($goods['diff_qty']) }}
            </div>
            @if ($goods['diff_qty'] != 0)
                <div class="sub2" style="color:var(--red)">{{ __('settle.shortage_hint') }}</div>
            @endif
        </div>
    </div>

    <div class="tablewrap">
        <table>
            <thead>
            <tr>
                <th>{{ __('stock.product') }}</th>
                <th>{{ __('settle.loaded') }}</th>
                <th>{{ __('settle.sold_cash') }}</th>
                <th>{{ __('settle.sold_credit') }}</th>
                <th>{{ __('settle.delivered_pos') }}</th>
                <th>{{ __('settle.gifts') }}</th>
                <th>{{ __('settle.returned_wh') }}</th>
                <th>{{ __('settle.still_on_van') }}</th>
                <th>{{ __('settle.returned_in') }}</th>
                <th>{{ __('field.return_damaged_units') }}</th>
                <th>{{ __('settle.shortage') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($goods['lines'] as $l)
                <tr>
                    <td><b>{{ $l['product']?->displayName() ?? '—' }}</b></td>
                    {{-- المحمَّل = عادي + هدايا --}}
                    <td class="num">{{ number_format($l['loaded']) }}
                        @if ($l['gift_assigned'] > 0)
                            <br><span style="font-size:10px;color:var(--muted)">{{ __('settle.of_which_gift') }} {{ number_format($l['gift_assigned']) }}</span>
                        @endif
                    </td>
                    <td class="num">{{ number_format($l['cash_qty']) }}
                        <br><span style="font-size:10px;color:var(--muted)">{{ number_format($l['cash_value'], 2) }}</span></td>
                    <td class="num">{{ number_format($l['credit_qty']) }}
                        <br><span style="font-size:10px;color:var(--muted)">{{ number_format($l['credit_value'], 2) }}</span></td>
                    <td class="num">{{ number_format($l['po_qty']) }}</td>
                    <td class="num">{{ number_format($l['gift_given']) }}
                        @if ($l['gift_left'] > 0)
                            <br><span style="font-size:10px;color:var(--muted)">+{{ number_format($l['gift_left']) }}</span>
                        @endif
                    </td>
                    <td class="num">{{ number_format($l['returned']) }}</td>
                    <td class="num" style="color:var(--primary);font-weight:900">{{ number_format($l['remaining']) }}</td>
                    <td class="num" style="color:var(--purple-heart)">{{ number_format($l['returned_in']) }}</td>
                    <td class="num {{ $l['damaged_in'] > 0 ? 'neg' : '' }}">{{ number_format($l['damaged_in']) }}</td>
                    <td class="num {{ $l['diff'] == 0 ? '' : 'neg' }}">
                        {{ $l['diff'] == 0 ? '—' : number_format($l['diff']) }}
                    </td>
                </tr>
            @empty
                <tr><td colspan="11" style="text-align:center;color:var(--muted);padding:26px">
                    {{ __('settle.no_custody') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
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

{{-- ═══ مستندات المرتجع (٨ أغسطس ٢٠٢٦) ═══
     ⚠️ **المحاسب بيستلم بضاعة مش بس فلوس.** السليم بيرجع للبيع
     والتالف بيتسلّم للمخزن لوحده — من غير الجدول ده المحاسب
     بيمضي على تصفية وهو مش عارف إيه اللي في العربية. --}}
@if ($returns->isNotEmpty())
    <div class="card">
        <h3>📥 {{ __('field.returns') }}
            <span class="side">
                {{ __('field.return_good_units') }}: {{ number_format($returns_good) }} ·
                {{ __('field.return_damaged_units') }}: {{ number_format($returns_damaged) }} ·
                {{ $fmt($returns_value) }}
            </span></h3>
        <div class="tablewrap st-tbl">
            <table>
                <tr>
                    <th style="text-align:start">{{ __('common.number') }}</th>
                    <th style="text-align:start">{{ __('client.client') }}</th>
                    <th>{{ __('field.return_policy') }}</th>
                    <th>{{ __('field.return_good_units') }}</th>
                    <th>{{ __('field.return_damaged_units') }}</th>
                    <th>{{ __('common.total') }}</th>
                </tr>
                @foreach ($returns as $r)
                    <tr>
                        <td style="text-align:start"><b>{{ $r->number }}</b></td>
                        <td style="text-align:start">{{ $r->client?->fullName() ?? '—' }}</td>
                        <td><span class="badge b-purple">{{ $r->policyLabel() }}</span></td>
                        <td class="num">{{ number_format($r->good_units) }}</td>
                        <td class="num {{ $r->damaged_units > 0 ? 'neg' : '' }}">
                            {{ number_format($r->damaged_units) }}</td>
                        <td class="num"><b>{{ $fmt($r->grand_total) }}</b></td>
                    </tr>
                @endforeach
            </table>
        </div>
    </div>
@endif

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
