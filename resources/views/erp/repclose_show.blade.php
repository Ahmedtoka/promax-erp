@extends('layouts.system')

{{--
    تصفية مندوب واحد — إعادة تصميم كاملة (2026-08-12، طلب المالك):
    «مفيش رقم اتعمل ميكونش في السامري فوق، وبعدها التصفية،
    وبعدها تفصيلة كل بوكس».

    1) سامري شامل: كل رقم طلعته النافذة — الفلوس بتفصيلة
       فواتير/أوامر (الخلطة اللي لخبطت مرتين)، التحصيلات نقدي/مستندات،
       المرتجعات، مطابقة عهدة مصغرة، والمعادلة الكبيرة اللي بتنتهي
       بالإجمالي المطلوب — الرقم اللي المحاسب هيقبضه.
       كروت الصفر بتفضل ظاهرة (مطفية) — مفيش رقم مش موجود.
    2) قفل التصفية — نفس الفورم ونفس عقد الجافاسكربت (stDiff).
    3) تفصيلة كل بوكس — أكورديون مفتوح: بالعميل، الأوامر، الفواتير،
       التحصيلات، المرتجعات، ومطابقة العهدة الكاملة.
--}}

@php
    $fmt = fn ($n) => number_format((float) $n, 2);

    $poRows = $po_rows ?? collect();
    $custody = $custody ?? null;
    $custodyOpen = $custody !== null && $custody->status !== 'closed';

    $invCashCount = $invoices->where('payment', 'cash')->count();
    $invCreditCount = $invoices->where('payment', '!=', 'cash')->count();
    $poDeliveredValue = round((float) $poRows->sum(fn ($p) => $p->deliveredValue()), 2);

    $collCashCount = $collection_rows->where('method', \App\Models\Transaction::METHOD_CASH)->count();
    $collOtherCount = $collection_rows->count() - $collCashCount;

    $soldQty = $goods['cash_qty'] + $goods['credit_qty'];
    $spentQty = $soldQty + $goods['po_qty'] + $goods['gift_qty'];
    $vanLeftQty = $goods['remaining_qty'] + $goods['gift_left_qty'];
    // ⚠️ **حدّ التحويل لمندوب تاني** (١٤/٨) — `?? 0` عشان التصفيات
    // المقفولة القديمة (لقطة `goods_json` قبل العمود) ماترميش مفتاح ناقص
    $transferOutQty = (int) ($goods['transfer_out_qty'] ?? 0);

    // ═══ قيمة الباقي في العربية بكل قايمة مفعّلة (طلب المالك ١٢/٨) ═══
    // ⚠️ **عرض فقط** — معادلة التصفية ومطابقة العهدة بالقطع زي ما هي.
    // القيمة من `CustodyValue` (نفس مصدر Pricing — price_list_items)،
    // والباقي من غير الهدايا (الهدايا مش بضاعة بيع). القوايم بتتحمّل
    // مرة واحدة — مش كويري لكل صنف.
    $vanValues = \App\Support\CustodyValue::totals(
        collect($goods['lines'])->map(fn ($l) => [
            'product' => $l['product'],
            'qty' => (int) $l['remaining'],
        ]),
    );
    // عمود «قيمة الباقي» في الجدول بقايمة المندوب المعتمدة —
    // السواق بالقديمة والسيلز بالجديدة (نفس قاعدة كل الشاشات)
    $repPriceList = \App\Support\CustodyValue::listForRep($rep);
@endphp

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

{{-- ═══════════════════════════════════════════════════════════
     1) السامري الشامل — كل رقم طلعته النافذة
     ═══════════════════════════════════════════════════════════ --}}
<div class="st-sec">📋 {{ __('settle.sec_summary') }}</div>

<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ __('settle.rep') }}</div>
        <div class="val" style="font-size:17px">{{ $rep->displayName() }}</div>
        <div class="sub2">
            {{ __('settle.window') }}:
            {{ $from_at ? __('settle.since_last').' '.$from_at->format('Y-m-d h:i A') : __('settle.since_start') }}
            ← {{ __('settle.now') }}
        </div>
    </div>
    <div class="kpi">
        <div class="lbl">🚚 {{ __('settle.custody_state') }}</div>
        <div class="val" style="font-size:17px">
            @if ($custody === null)
                <span class="badge b-gray">{{ __('settle.custody_none') }}</span>
            @elseif ($custodyOpen)
                <span class="badge b-green">{{ __('settle.custody_open') }}</span>
            @else
                <span class="badge b-gray">{{ __('settle.custody_closed') }}</span>
            @endif
        </div>
        <div class="sub2">{{ __('settle.still_on_van') }}: {{ number_format($goods['remaining_qty']) }} {{ __('common.piece') }}</div>
        {{-- قيمة الباقي بكل قايمة — استرشادي، التصفية بالقطع (١٢/٨) --}}
        <div class="sub2">@include('partials._list_values', ['totals' => $vanValues])</div>
    </div>
</div>

<div class="st-sec">💰 {{ __('settle.sec_money') }}</div>

<div class="kpis">
    <div class="kpi {{ (float) $cash_sales == 0.0 ? 'st-zero' : '' }}">
        <div class="lbl">💵 {{ __('settle.cash_sales') }}</div>
        <div class="val">{{ $fmt($cash_sales) }}</div>
        {{-- التفصيلة اللي لخبطت مرتين: الرقم = فواتير + أوامر توريد --}}
        <div class="sub2">{{ __('settle.split_line', ['inv' => $fmt($inv_cash), 'po' => $fmt($po_cash)]) }}</div>
        <div class="sub2">{{ __('settle.invoice_count', ['count' => $invCashCount]) }}</div>
    </div>
    <div class="kpi {{ (float) $credit_sales == 0.0 ? 'st-zero' : '' }}">
        <div class="lbl">📒 {{ __('settle.credit_sales') }}</div>
        <div class="val" style="color:var(--muted)">{{ $fmt($credit_sales) }}</div>
        <div class="sub2">{{ __('settle.split_line', ['inv' => $fmt($inv_credit), 'po' => $fmt($po_credit)]) }}</div>
        <div class="sub2">{{ __('settle.invoice_count', ['count' => $invCreditCount]) }}</div>
    </div>
    <div class="kpi {{ (float) $cash_collections == 0.0 ? 'st-zero' : '' }}">
        <div class="lbl">🧾 {{ __('settle.field_collections') }}</div>
        <div class="val pos">{{ $fmt($cash_collections) }}</div>
        <div class="sub2">{{ __('settle.in_expected') }} · {{ __('settle.entry_count', ['count' => $collCashCount]) }}</div>
    </div>
    <div class="kpi {{ (float) $other_collections_value == 0.0 ? 'st-zero' : '' }}">
        <div class="lbl">📄 {{ __('settle.noncash_collections') }}</div>
        <div class="val" style="color:var(--blue, #2470E3)">{{ $fmt($other_collections_value) }}</div>
        <div class="sub2">{{ __('settle.noncash_docs_hint') }} · {{ __('settle.doc_count', ['count' => $collOtherCount]) }}</div>
    </div>
    <div class="kpi {{ (float) $cash_refunds == 0.0 ? 'st-zero' : '' }}">
        <div class="lbl">↩️ {{ __('settle.cash_refunds') }}</div>
        <div class="val mid">{{ $fmt($cash_refunds) }}</div>
        <div class="sub2">{{ __('settle.entry_count', ['count' => $refundRows->count()]) }}</div>
    </div>
    <div class="kpi {{ (float) $returns_value == 0.0 ? 'st-zero' : '' }}">
        <div class="lbl">📥 {{ __('settle.returns_value_lbl') }}</div>
        <div class="val" style="color:var(--purple-heart, #602D90)">{{ $fmt($returns_value) }}</div>
        <div class="sub2">
            {{ __('settle.good_damaged', ['good' => number_format($returns_good), 'damaged' => number_format($returns_damaged)]) }}
            · {{ __('settle.doc_count', ['count' => $returns->count()]) }}
        </div>
    </div>
</div>

<div class="st-sec">📦 {{ __('settle.sec_goods') }}</div>

<div class="kpis">
    <div class="kpi {{ $goods['assigned'] == 0 ? 'st-zero' : '' }}">
        <div class="lbl">{{ __('settle.loaded') }}</div>
        <div class="val">{{ number_format($goods['assigned']) }}</div>
        <div class="sub2">{{ __('settle.of_which_gift') }} {{ number_format($goods['gift_assigned']) }}</div>
    </div>
    <div class="kpi {{ $spentQty == 0 ? 'st-zero' : '' }}">
        <div class="lbl">{{ __('settle.spent') }}</div>
        <div class="val">{{ number_format($spentQty) }}</div>
        <div class="sub2">{{ __('settle.spent_split', ['sold' => number_format($soldQty), 'po' => number_format($goods['po_qty']), 'gift' => number_format($goods['gift_qty'])]) }}</div>
    </div>
    <div class="kpi {{ ($goods['returned_wh_qty'] + $transferOutQty) == 0 ? 'st-zero' : '' }}">
        <div class="lbl">{{ __('settle.returned_wh') }}</div>
        <div class="val">{{ number_format($goods['returned_wh_qty']) }}</div>
        {{-- حدّ مستقل في المعادلة (١٤/٨): اتحوّل لعربية زميل بمستند
             تحويل — مش مباع ومش راجع المخزن، وبيتحاسب عليه هو --}}
        <div class="sub2">{{ __('settle.transfer_out') }}: {{ number_format($transferOutQty) }}</div>
    </div>
    <div class="kpi {{ $vanLeftQty == 0 ? 'st-zero' : '' }}">
        <div class="lbl">{{ __('settle.still_on_van') }}</div>
        <div class="val" style="color:var(--primary)">{{ number_format($vanLeftQty) }}</div>
        <div class="sub2">{{ __('settle.gift_left') }}: {{ number_format($goods['gift_left_qty']) }}</div>
        {{-- القيمة بكل قايمة — عرض فقط (١٢/٨) --}}
        <div class="sub2">@include('partials._list_values', ['totals' => $vanValues])</div>
    </div>
    {{-- بره المعادلة بقصد — بضاعة العملاء اللي بتتسلّم مع التصفية --}}
    <div class="kpi {{ ($goods['returned_qty'] + $goods['damaged_qty']) == 0 ? 'st-zero' : '' }}">
        <div class="lbl">{{ __('settle.returned_in') }}</div>
        <div class="val" style="color:var(--purple-heart, #602D90)">{{ number_format($goods['returned_qty']) }}</div>
        <div class="sub2">
            {{ __('settle.returned_in_hint') }} ·
            {{ __('field.return_damaged_units') }}: {{ number_format($goods['damaged_qty']) }}
        </div>
    </div>
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

{{-- المعادلة الكبيرة — بتنتهي بالرقم اللي المحاسب هيقبضه --}}
<div class="card">
    <h3>🧮 {{ __('settle.due_total') }}
        <span class="side">{{ __('settle.expected_full') }}</span></h3>
    <div class="st-eq">
        <div class="st-term"><span class="t">{{ __('settle.cash_sales') }}</span><span class="v">{{ $fmt($cash_sales) }}</span></div>
        <span class="st-op">+</span>
        <div class="st-term"><span class="t">{{ __('settle.field_collections') }}</span><span class="v">{{ $fmt($cash_collections) }}</span></div>
        <span class="st-op">−</span>
        <div class="st-term"><span class="t">{{ __('settle.cash_refunds') }}</span><span class="v">{{ $fmt($cash_refunds) }}</span></div>
        <span class="st-op">=</span>
        <div class="st-term hi"><span class="t">{{ __('settle.expected') }}</span><span class="v">{{ $fmt($expected) }}</span></div>
        <span class="st-op">+</span>
        <div class="st-term"><span class="t">{{ __('settle.prev_balance') }}</span><span class="v">{{ $fmt($prev_balance) }}</span></div>
        <span class="st-op">=</span>
        <div class="st-term due"><span class="t">{{ __('settle.due_total') }}</span><span class="v">{{ $fmt($due_total) }}</span></div>
    </div>
    <div class="sub2" style="font-size:11px;color:var(--muted)">💡 {{ __('settle.due_note') }}</div>
</div>

{{-- ═══════════════════════════════════════════════════════════
     2) قفل التصفية — المستلم بإيد اللي عدّ الفلوس والفرق لايف
     ═══════════════════════════════════════════════════════════ --}}
<div class="card">
    <h3>🤝 {{ __('settle.close_btn') }}
        <span class="side">{{ __('settle.due_total') }}: {{ $fmt($due_total) }}</span></h3>
    <form method="POST" action="{{ route('erp.repclose.store', $rep) }}"
          onsubmit="return confirm(@js(__('settle.close_confirm')))">
        @csrf
        <div class="frow">
            <div>
                <label class="f">{{ __('settle.received') }} <b class="req-star">*</b></label>
                {{-- الخانة بتفضل فاضية (قرار المالك 2026-08-08).
                     كانت بتتملي بالمطلوب سلفاً — فالمحاسب المستعجل
                     بيدوس «قفل» على طول والتصفية بتقفل بصفر فرق مهما
                     كان اللي استلمه فعلاً. الرقم ده لازم يتكتب بإيد
                     اللي عدّ الفلوس، وده كل معنى التصفية.
                     والاوتوكومبليت مقفول عشان المتصفح مايقترحش رقم
                     تصفية امبارح. --}}
                <input type="number" name="received" id="stReceived" step="0.01" min="0" required dir="ltr"
                       value="{{ old('received') }}" autocomplete="off"
                       placeholder="{{ __('settle.received_ph') }}"
                       style="width:100%;font-weight:900;font-size:20px;text-align:center;padding:10px 12px"
                       oninput="stDiff()">
                @error('received')
                    <div class="errline">{{ $message }}</div>
                @enderror
            </div>
            <div>
                <label class="f">{{ __('settle.balance') }}</label>
                <div id="stBalance" style="padding:11px 13px;border:1px solid var(--border);border-radius:10px;
                     font-weight:900;text-align:center;font-size:16px">—</div>
            </div>
            <div style="flex:2">
                <label class="f">{{ __('settle.note') }}</label>
                <input type="text" name="note" maxlength="500" style="width:100%" value="{{ old('note') }}">
            </div>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:12px;flex-wrap:wrap">
            {{-- قفل العهدة مع التصفية — مش متعلّم افتراضياً (قرار
                 المالك ١١/٨): الفلو الطبيعي إن العربية تبات بباقي
                 البضاعة وتكمل بكرة؛ القفل النهائي كل فين وفين بس --}}
            <label class="st-opt">
                <input type="checkbox" name="close_custody" value="1" style="width:16px;height:16px;margin-top:2px">
                <span>
                    <b>🔒 {{ __('settle.close_custody_too') }}</b>
                    <small>{{ __('settle.close_custody_hint') }}</small>
                </span>
            </label>
            <button class="btn gold" type="submit" style="font-size:14px;padding:10px 22px">🤝 {{ __('settle.close_btn') }}</button>
        </div>
    </form>
</div>

{{-- ═══════════════════════════════════════════════════════════
     3) تفصيلة كل بوكس — أكورديون مفتوح، كل هيدر بعدّه وإجماليه
     ═══════════════════════════════════════════════════════════ --}}
<div class="st-sec">📂 {{ __('settle.sec_details') }}</div>

{{-- 3.1 — الفلوس دي لمين؟ كاش وآجل بالعميل (فواتير بس — الأوامر
     ليها البوكس اللي بعده، وده سبب أي فرق عن كروت السامري) --}}
<details class="stx" open>
    <summary>💵 {{ __('settle.sales_by_client') }}
        <span class="sside">
            {{ __('settle.cash_invoices') }} {{ $fmt($inv_cash) }} ·
            {{ __('settle.credit_invoices') }} {{ $fmt($inv_credit) }}
        </span>
    </summary>
    <div class="stx-in">
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
                                    {{-- القطع مجموع بنود الفاتورة — بالقطعة دايماً،
                                         مهما كانت الوحدة اللي المندوب كتب بيها --}}
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
    </div>
</details>

{{-- 3.2 — أوامر التوريد المسلَّمة: تفصيلة الآجل الناقصة (١١/٨).
     الكاش والآجل في السامري بيجمعوا الفواتير + الأوامر دي --}}
<details class="stx" open>
    <summary>🚚 {{ __('settle.po_delivered') }}
        <span class="sside">
            {{ __('settle.order_count', ['count' => $poRows->count()]) }} · {{ $fmt($poDeliveredValue) }}
        </span>
    </summary>
    <div class="stx-in">
        <div class="card">
            <h3>🚚 {{ __('settle.po_delivered') }}
                <span class="side">{{ __('settle.po_delivered_hint') }}</span></h3>
            <div class="tablewrap">
                <table>
                    <thead>
                    <tr>
                        <th style="text-align:start">{{ __('ops.order') }}</th>
                        <th>{{ __('client.client') }}</th>
                        <th>{{ __('settle.window_to') }}</th>
                        <th>{{ __('common.qty') }}</th>
                        <th>{{ __('ops.payment') }}</th>
                        <th>{{ __('common.total') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($poRows as $po)
                        <tr>
                            <td style="text-align:start"><b>{{ $po->number }}</b></td>
                            <td>{{ $po->client?->fullName() ?? '—' }}</td>
                            <td class="num" style="font-size:11px" dir="ltr">{{ $po->delivered_at?->format('m-d h:i A') ?? '—' }}</td>
                            <td class="num">{{ number_format((int) $po->items->sum('delivered_qty')) }}</td>
                            @php $poCashClient = $po->client?->paymentTerms() === 'cash'; @endphp
                            <td>
                                <span class="badge {{ $poCashClient ? 'b-green' : 'b-orange' }}">
                                    {{ $poCashClient ? __('enums.payment.cash') : __('enums.payment.credit') }}
                                </span>
                            </td>
                            <td class="num mid">{{ number_format((float) $po->deliveredValue(), 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:22px">
                            {{ __('settle.none') }}</td></tr>
                    @endforelse
                    </tbody>
                    @if ($poRows->isNotEmpty())
                        <tfoot>
                        <tr>
                            <td colspan="5" style="text-align:start"><b>Σ {{ __('common.total') }}</b></td>
                            <td class="num"><b>{{ number_format($poDeliveredValue, 2) }}</b></td>
                        </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
            <div class="side" style="font-size:11px;margin-top:8px">
                {{ __('settle.po_split', ['cash' => number_format($po_cash ?? 0, 2), 'credit' => number_format($po_credit ?? 0, 2)]) }}
            </div>
        </div>
    </div>
</details>

{{-- 3.3 — الفواتير للمطابقة: المحاسب بيراجعها مع المندوب ورقة ورقة --}}
<details class="stx" open>
    <summary>🧾 {{ __('settle.invoices_to_match') }}
        <span class="sside">{{ __('settle.invoice_count', ['count' => $invoices->count()]) }}</span>
    </summary>
    <div class="stx-in">
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
                                <td class="num" style="font-size:11px">{{ $inv->created_at->format('m-d h:i A') }}</td>
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
    </div>
</details>

{{-- 3.4 — تحصيلات الفترة: الكاش داخل «المتوقع» فوق، وغير الكاش
     تسليم مستندات — المحاسب بيستلم الشيك ويطابق التحويل على الصورة --}}
<details class="stx" open>
    <summary>💳 {{ __('settle.collections_to_match') }}
        <span class="sside">
            {{ __('settle.field_collections') }} {{ $fmt($cash_collections) }} ·
            {{ __('settle.noncash_collections') }} {{ $fmt($other_collections_value) }}
        </span>
    </summary>
    <div class="stx-in">
        <div class="card">
            <h3>💳 {{ __('settle.collections_to_match') }}
                <span class="side">{{ __('settle.collections_hint') }}</span></h3>
            <div class="tablewrap st-tbl">
                <table>
                    <tr>
                        <th style="text-align:start">{{ __('client.client') }}</th>
                        <th>{{ __('common.date') }}</th>
                        <th>{{ __('ops.method') }}</th>
                        {{-- المرجع ممكن يكون رقم صافي — مجموعه مالوش معنى --}}
                        <th data-nosum>{{ __('ops.reference') }}</th>
                        <th>{{ __('settle.proof') }}</th>
                        <th>{{ __('common.total') }}</th>
                    </tr>
                    @forelse ($collection_rows as $t)
                        <tr>
                            <td style="text-align:start">{{ $t->client?->fullName() ?? '—' }}</td>
                            <td class="num" style="font-size:11px">{{ $t->created_at->format('m-d h:i A') }}</td>
                            <td>
                                <span class="badge {{ $t->method === 'cash' ? 'b-green' : 'b-blue' }}">
                                    {{ $t->methodLabel() ?? '—' }}</span>
                                @if ($t->method === 'cheque' && $t->cheque_due)
                                    <div style="font-size:10px;color:var(--muted)">
                                        {{ $t->cheque_bank }} · {{ $t->cheque_due->format('Y-m-d') }}</div>
                                @endif
                            </td>
                            <td class="num" style="font-size:11px">{{ $t->reference ?: '—' }}</td>
                            <td>
                                @if ($t->proofUrl())
                                    <a class="btn sm" href="{{ $t->proofUrl() }}" target="_blank">📷</a>
                                @else
                                    <span style="color:var(--muted)">—</span>
                                @endif
                            </td>
                            <td class="num pos"><b>{{ $fmt($t->credit) }}</b></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:22px">
                            {{ __('settle.none') }}</td></tr>
                    @endforelse
                </table>
            </div>
        </div>
    </div>
</details>

{{-- 3.5 — مرتجعات الفترة: مستندات المرتجع (سليم/تالف/قيمة)
     + مرتجعات الكاش اللي اتردّت نقدي وبتتخصم من المتوقع --}}
<details class="stx" open>
    <summary>📥 {{ __('field.returns') }}
        <span class="sside">
            {{ __('settle.doc_count', ['count' => $returns->count()]) }} · {{ $fmt($returns_value) }} ·
            {{ __('settle.cash_refunds') }} {{ $fmt($cash_refunds) }}
        </span>
    </summary>
    <div class="stx-in">
        {{-- المحاسب بيستلم بضاعة مش بس فلوس: السليم بيرجع للبيع
             والتالف بيتسلّم للمخزن لوحده --}}
        <div class="card">
            <h3>📥 {{ __('field.returns') }}
                <span class="side">
                    {{ __('settle.good_damaged', ['good' => number_format($returns_good), 'damaged' => number_format($returns_damaged)]) }}
                    · {{ $fmt($returns_value) }}
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
                    @forelse ($returns as $r)
                        <tr>
                            <td style="text-align:start"><b>{{ $r->number }}</b></td>
                            <td style="text-align:start">{{ $r->client?->fullName() ?? '—' }}</td>
                            <td><span class="badge b-purple">{{ $r->policyLabel() }}</span></td>
                            <td class="num">{{ number_format($r->good_units) }}</td>
                            <td class="num {{ $r->damaged_units > 0 ? 'neg' : '' }}">
                                {{ number_format($r->damaged_units) }}</td>
                            <td class="num"><b>{{ $fmt($r->grand_total) }}</b></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:22px">
                            {{ __('settle.none') }}</td></tr>
                    @endforelse
                </table>
            </div>
        </div>

        <div class="card">
            <h3>↩️ {{ __('settle.refunds_to_match') }}
                <span class="side">{{ $fmt($cash_refunds) }}</span></h3>
            <div class="tablewrap st-tbl">
                <table>
                    <tr>
                        <th style="text-align:start">{{ __('client.client') }}</th>
                        <th>{{ __('common.date') }}</th>
                        <th>{{ __('common.total') }}</th>
                    </tr>
                    @forelse ($refundRows as $t)
                        <tr>
                            <td style="text-align:start">{{ $t->client?->fullName() ?? '—' }}</td>
                            <td class="num" style="font-size:11px">{{ $t->created_at->format('m-d h:i A') }}</td>
                            <td class="num neg"><b>{{ $fmt($t->debit) }}</b></td>
                        </tr>
                    @empty
                        <tr><td colspan="3" style="text-align:center;color:var(--muted);padding:22px">
                            {{ __('settle.none') }}</td></tr>
                    @endforelse
                </table>
            </div>
        </div>
    </div>
</details>

{{-- 3.6 — مطابقة العهدة الكاملة — بالقطع مش بالفلوس.
     التصفية كانت بتقفل الفلوس وتسيب البضاعة: المحاسب بيستلم كاش
     ويمضي، والعربية فيها بضاعة محدش عدّها — فالعجز مابيظهرش غير
     في الجرد الشهري، وساعتها محدش يعرف حصل إمتى ولا مع مين --}}
<details class="stx" open>
    <summary>📦 {{ __('settle.goods_match') }}
        <span class="sside {{ $goods['diff_qty'] == 0 ? 'pos' : 'neg' }}">
            {{ __('settle.shortage') }}: {{ $goods['diff_qty'] == 0 ? '0 ✓' : number_format($goods['diff_qty']) }}
        </span>
    </summary>
    <div class="stx-in">
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
                {{-- حد ناقص كان بيبلع القطع (تدقيق ٨/٨) — المسلَّم
                     بأوامر التوريد بيخصم من العهدة من غير فاتورة --}}
                <div class="kpi"><div class="lbl">{{ __('settle.delivered_pos') }}</div>
                    <div class="val mid">{{ number_format($goods['po_qty']) }}</div></div>
                <div class="kpi"><div class="lbl">{{ __('settle.gifts') }}</div>
                    <div class="val">{{ number_format($goods['gift_qty']) }}</div>
                    <div class="sub2">{{ __('settle.gift_left') }}: {{ number_format($goods['gift_left_qty']) }}</div></div>
                <div class="kpi"><div class="lbl">{{ __('settle.returned_wh') }}</div>
                    <div class="val">{{ number_format($goods['returned_wh_qty']) }}</div></div>
                {{-- حد التحويل لمندوب تاني (١٤/٨) — جوه المعادلة --}}
                <div class="kpi"><div class="lbl">{{ __('settle.transfer_out') }}</div>
                    <div class="val">{{ number_format($transferOutQty) }}</div></div>
                <div class="kpi"><div class="lbl">{{ __('settle.still_on_van') }}</div>
                    <div class="val" style="color:var(--primary)">{{ number_format($goods['remaining_qty']) }}</div>
                    {{-- القيمة بكل قايمة — عرض فقط، المعادلة بالقطع (١٢/٨) --}}
                    <div class="sub2">@include('partials._list_values', ['totals' => $vanValues])</div></div>
                {{-- بره المعادلة بقصد: بضاعة العملاء اللي في العربية
                     ومالهاش أصل في المحمَّل — لازم تتسلّم مع التصفية --}}
                <div class="kpi"><div class="lbl">{{ __('settle.returned_in') }}</div>
                    <div class="val" style="color:var(--purple-heart)">{{ number_format($goods['returned_qty']) }}</div>
                    <div class="sub2">{{ __('settle.returned_in_hint') }}</div></div>
                {{-- التالف منفصل — بيتسلّم للمخزن لوحده ومابيرجعش للبيع --}}
                <div class="kpi"><div class="lbl">{{ __('field.return_damaged_units') }}</div>
                    <div class="val {{ $goods['damaged_qty'] > 0 ? 'neg' : '' }}">{{ number_format($goods['damaged_qty']) }}</div></div>
                {{-- الفرق مش خطأ حسابي: فرق ≠ صفر معناه بضاعة خرجت من
                     العربية من غير فاتورة ولا هدية ولا مرتجع — عجز حقيقي --}}
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
                        <th>{{ __('settle.transfer_out') }}</th>
                        <th>{{ __('settle.still_on_van') }}</th>
                        {{-- قيمة الباقي بقايمة المندوب — عرض فقط (١٢/٨).
                             خلية رقم نضيف فالفوتر الأوتوماتيك بيجمعها --}}
                        <th>{{ __('ops.remaining_value') }}
                            <div style="font-size:9.5px;font-weight:600;color:var(--muted)">{{ $repPriceList?->displayName() ?? '—' }}</div>
                        </th>
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
                            <td class="num">{{ number_format($l['transfer_out'] ?? 0) }}</td>
                            <td class="num" style="color:var(--primary);font-weight:900">{{ number_format($l['remaining']) }}</td>
                            @php $lRemVal = (int) $l['remaining'] * \App\Support\CustodyValue::priceIn($repPriceList, $l['product']); @endphp
                            <td class="num">{{ $l['remaining'] > 0 ? number_format($lRemVal, 2) : '—' }}</td>
                            <td class="num" style="color:var(--purple-heart)">{{ number_format($l['returned_in']) }}</td>
                            <td class="num {{ $l['damaged_in'] > 0 ? 'neg' : '' }}">{{ number_format($l['damaged_in']) }}</td>
                            <td class="num {{ $l['diff'] == 0 ? '' : 'neg' }}">
                                {{ $l['diff'] == 0 ? '—' : number_format($l['diff']) }}
                            </td>
                        </tr>
                    @empty
                        {{-- ⚠️ زوّدت عمود «محوَّل لمندوب»؟ الـcolspan اتحدّث معاه --}}
                        <tr><td colspan="13" style="text-align:center;color:var(--muted);padding:26px">
                            {{ __('settle.no_custody') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</details>

@endsection

@section('scripts')
<style>
.st-tbl th, .st-tbl td { text-align: center; vertical-align: middle; }

/* عناوين الأقسام المصغرة — سامري / بضاعة / تفصيلة */
.st-sec{display:flex;align-items:center;gap:8px;font-size:12.5px;font-weight:800;color:var(--muted);margin:2px 0 10px}
.st-sec::after{content:"";flex:1;height:1px;background:var(--border)}

/* كارت صفر — ظاهر بس مطفي: مفيش رقم مش موجود */
.kpi.st-zero{opacity:.6}
.kpi.st-zero .val{color:var(--muted)}

/* المعادلة الكبيرة */
.st-eq{display:flex;flex-wrap:wrap;align-items:stretch;gap:8px;margin-bottom:8px}
.st-term{border:1px solid var(--border);background:var(--card2);border-radius:10px;padding:8px 14px;text-align:center;min-width:105px}
.st-term .t{display:block;font-size:10.5px;font-weight:700;color:var(--muted);margin-bottom:2px}
.st-term .v{display:block;font-size:15px;font-weight:900;font-variant-numeric:tabular-nums;direction:ltr}
.st-term.hi{border-color:var(--primary)}
.st-term.hi .v{color:var(--primary)}
.st-op{align-self:center;font-size:17px;font-weight:900;color:var(--muted);padding:0 2px}
.st-term.due{background:var(--brand-gradient);border:none;padding:10px 22px}
.st-term.due .t{color:rgba(255,255,255,.85)}
.st-term.due .v{color:#fff;font-size:22px}

/* صف أوبشن قفل العهدة */
.st-opt{display:flex;align-items:flex-start;gap:10px;border:1px dashed var(--border);border-radius:10px;padding:10px 12px;cursor:pointer;max-width:620px;font-size:12.5px}
.st-opt small{display:block;font-weight:400;color:var(--muted);font-size:11px;margin-top:2px}

/* أكورديون التفصيلة */
.stx{margin-bottom:14px}
.stx>summary{background:var(--card);border:1px solid var(--border);border-radius:var(--r-md);box-shadow:var(--shadow);padding:12px 16px;font-weight:800;font-size:13.5px;cursor:pointer;display:flex;align-items:center;gap:8px;list-style:none}
.stx>summary::-webkit-details-marker{display:none}
.stx .sside{margin-inline-start:auto;font-size:11.5px;font-weight:700;color:var(--muted)}
.stx .sside.pos{color:var(--green)}
.stx .sside.neg{color:var(--red)}
.stx>summary::after{content:"▾";color:var(--muted);margin-inline-start:8px;transition:transform .15s}
.stx[open]>summary::after{transform:rotate(180deg)}
.stx>.stx-in{padding-top:10px}
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
