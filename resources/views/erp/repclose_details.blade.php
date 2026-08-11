@extends('layouts.system')

{{--
    تفاصيل تصفية مقفولة — «الرقم ده جاي منين؟» (١١ أغسطس ٢٠٢٦ مساءً)

    ⚠️ السبب حالة حقيقية: محضر مريم RS-1001 آجل 31,767 وشاشة مبيعاتها
    29,045 — والفرق 2,722 هو آجل أوامر التوريد المسلَّمة اللي المحضر
    بيحسبه وشاشات الفواتير مابتعرضهوش. الشاشة دي بتفكك كل رقم لمكوناته.

    - إعادة البناء الحية بنفس كويريز openFigures بس بحدود النافذة
      المخزنة (from_at → to_at) — مش «لحد دلوقتي».
    - التحصيلات من اللقطة المجمدة collections_json (المرجع القانوني)،
      والحي فولباك للتصفيات اللي اتقفلت قبل ما اللقطة تتخزن.
    - لو الحي ≠ اللقطة (فاتورة اتعدلت بعد القفل) بيطلع تحذير برتقالي
      بالقيمتين — اللقطة هي الرقم المعتمد على الورقة الممضية.
--}}

@php $fmt = fn ($n) => number_format((float) $n, 2); @endphp

@section('title', __('settle.details_title').' — '.$s->number)

@section('actions')
    <a class="btn" href="{{ route('erp.repclose') }}">← {{ __('settle.title') }}</a>
    <a class="btn gold" href="{{ route('erp.repclose.doc', $s) }}">🖨️ {{ __('settle.doc_title') }}</a>
@endsection

@section('content')

{{-- ═══ الكروت: أرقام اللقطة المعتمدة — مش إعادة البناء ═══ --}}
<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ __('settle.rep') }} · <b>{{ $s->number }}</b></div>
        <div class="val" style="font-size:17px">{{ $rep->displayName() }}</div>
        <div class="sub2" dir="ltr">
            {{ $s->from_at?->format('m-d h:i A') ?? __('settle.since_start') }} ← {{ $s->to_at->format('m-d h:i A') }}
        </div>
    </div>
    <div class="kpi">
        <div class="lbl">💰 {{ __('settle.expected') }}</div>
        <div class="val">{{ $fmt($s->expected) }}</div>
        <div class="sub2">{{ __('settle.expected_hint') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">🤝 {{ __('settle.received') }}</div>
        <div class="val pos">{{ $fmt($s->received) }}</div>
        <div class="sub2">{{ __('settle.by') }}: {{ $s->creator?->name ?? '—' }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">⚖️ {{ __('settle.balance') }}</div>
        <div class="val {{ (float) $s->balance > 0 ? 'neg' : ((float) $s->balance < 0 ? 'pos' : '') }}">
            {{ $fmt(abs((float) $s->balance)) }}
        </div>
        <div class="sub2">{{ $s->balanceLabel() }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">📦 {{ __('settle.goods_match') }}</div>
        <div class="val {{ $goodsDiff === 0 ? 'pos' : 'neg' }}">
            {{ $goodsDiff === 0 ? '0 ✓' : number_format($goodsDiff) }}
        </div>
        <div class="sub2">{{ __('settle.shortage') }}</div>
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════════
     شريط المطابقة — إجابة «31,767 ولا 29,045؟» بمعادلة صريحة
     ═══════════════════════════════════════════════════════════ --}}
<div class="card">
    <h3>🔎 {{ __('settle.recon_title') }}
        <span class="side">{{ __('settle.recon_hint') }}</span></h3>

    <div class="st-recon">
        <div class="st-eq">
            💵 {{ __('settle.recon_cash', [
                'a' => $fmt($f['inv_cash']),
                'b' => $fmt($f['po_cash']),
                'total' => $fmt($f['cash_sales']),
            ]) }}
        </div>
        <div class="st-eq mid">
            📒 {{ __('settle.recon_credit', [
                'a' => $fmt($f['inv_credit']),
                'b' => $fmt($f['po_credit']),
                'total' => $fmt($f['credit_sales']),
            ]) }}
        </div>
    </div>

    {{-- اللقطة ≠ إعادة البناء: فاتورة اتعدلت أو اتمسحت بعد القفل --}}
    @if ($checks->isNotEmpty())
        <div class="st-warn">
            <div style="font-weight:900;margin-bottom:6px">⚠️ {{ __('settle.snapshot_mismatch') }}</div>
            <table data-plain style="width:auto;min-width:340px">
                <thead>
                    <tr>
                        <th style="text-align:start"></th>
                        <th class="num">{{ __('settle.stored_at_close') }}</th>
                        <th class="num">{{ __('settle.live_rebuild') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($checks as $c)
                        <tr>
                            <td style="text-align:start"><b>{{ $c['label'] }}</b></td>
                            <td class="num"><b>{{ $fmt($c['stored']) }}</b></td>
                            <td class="num" style="color:var(--orange)">{{ $fmt($c['live']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

{{-- ═══ فواتير النافذة — كاش وآجل جنب بعض ═══ --}}
<div class="grid2">
    @foreach ([
        ['rows' => $f['invoices']->where('payment', 'cash')->values(),
         'title' => __('settle.cash_invoices'), 'icon' => '💵', 'cls' => 'pos', 'sub' => $f['inv_cash']],
        ['rows' => $f['invoices']->where('payment', '!=', 'cash')->values(),
         'title' => __('settle.credit_invoices'), 'icon' => '📄', 'cls' => 'mid', 'sub' => $f['inv_credit']],
    ] as $box)
        <div class="card">
            <h3>{{ $box['icon'] }} {{ $box['title'] }}
                <span class="side">{{ __('settle.invoice_count', ['count' => $box['rows']->count()]) }}</span></h3>
            <div class="tablewrap st-tbl" style="max-height:46vh;overflow-y:auto">
                <table data-plain>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th style="text-align:start">{{ __('client.client') }}</th>
                            <th>{{ __('common.time') }}</th>
                            <th>{{ __('common.total') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($box['rows'] as $inv)
                            <tr class="clickable" onclick="window.open('{{ route('ops.invoice', $inv) }}', '_blank')">
                                <td class="num"><b>{{ $inv->number }}</b></td>
                                <td style="text-align:start">{{ $inv->client?->fullName() ?? '—' }}</td>
                                <td class="num" style="font-size:11px" dir="ltr">{{ $inv->created_at->format('m-d h:i A') }}</td>
                                {{-- بالإجمالي شامل الضريبة — نفس عقيدة الليدجر --}}
                                <td class="num {{ $box['cls'] }}"><b>{{ $fmt($inv->grand_total) }}</b></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" style="text-align:center;color:var(--muted);padding:22px">
                                {{ __('settle.none') }}</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr class="st-sum">
                            <td colspan="3" style="text-align:start"><b>{{ __('common.total') }}</b></td>
                            <td class="num"><b>{{ $fmt($box['sub']) }}</b></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    @endforeach
</div>

{{-- ═══ أوامر التوريد المسلَّمة — الحد اللي بيكمل معادلة الآجل ═══
     القيمة والتقسيمة كاش/آجل من القيود (مصدر الحقيقة للفلوس)،
     بنفس حدود النافذة اللي المجاميع فوق اتحسبت بيها. --}}
<div class="card">
    <h3>🚚 {{ __('settle.po_in_window') }}
        <span class="side">{{ __('settle.po_split', [
            'cash' => $fmt($f['po_cash']), 'credit' => $fmt($f['po_credit']),
        ]) }}</span></h3>
    <div class="tablewrap st-tbl">
        <table data-plain>
            <thead>
                <tr>
                    <th style="text-align:start">{{ __('ops.order') }}</th>
                    <th>{{ __('client.client') }}</th>
                    <th>{{ __('common.time') }}</th>
                    <th>{{ __('common.total') }}</th>
                    <th>{{ __('settle.payment_cash') }}</th>
                    <th>{{ __('settle.payment_credit') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($f['po_rows'] as $po)
                    @php
                        $poVal = round((float) ($poSaleBy[$po->id] ?? 0), 2);
                        $poCashRow = round((float) ($poCashBy[$po->id] ?? 0), 2);
                        $poCreditRow = round(max(0, $poVal - $poCashRow), 2);
                    @endphp
                    <tr>
                        <td style="text-align:start"><b>{{ $po->number }}</b></td>
                        <td>{{ $po->client?->fullName() ?? '—' }}</td>
                        <td class="num" style="font-size:11px" dir="ltr">{{ $po->delivered_at?->format('m-d h:i A') ?? '—' }}</td>
                        <td class="num"><b>{{ $fmt($poVal) }}</b></td>
                        <td class="num pos">{{ $poCashRow > 0 ? $fmt($poCashRow) : '—' }}</td>
                        <td class="num mid">{{ $poCreditRow > 0 ? $fmt($poCreditRow) : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:22px">
                        {{ __('settle.none') }}</td></tr>
                @endforelse
            </tbody>
            @if ($f['po_rows']->isNotEmpty())
                <tfoot>
                    <tr class="st-sum">
                        <td colspan="3" style="text-align:start"><b>{{ __('common.total') }}</b></td>
                        <td class="num"><b>{{ $fmt($f['po_cash'] + $f['po_credit']) }}</b></td>
                        <td class="num"><b>{{ $fmt($f['po_cash']) }}</b></td>
                        <td class="num"><b>{{ $fmt($f['po_credit']) }}</b></td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</div>

{{-- ═══ التحصيلات — من اللقطة المجمدة (المرجع)، والحي فولباك بس ═══ --}}
<div class="card">
    <h3>🧾 {{ __('settle.collections_to_match') }}
        <span class="side">
            {{ $collectionsFromSnapshot ? __('settle.collections_snapshot_hint') : __('settle.collections_live_hint') }}
        </span></h3>
    <div class="tablewrap st-tbl">
        <table data-plain>
            <thead>
                <tr>
                    <th style="text-align:start">{{ __('client.client') }}</th>
                    <th>{{ __('common.time') }}</th>
                    <th>{{ __('ops.method') }}</th>
                    <th>{{ __('ops.reference') }}</th>
                    <th>{{ __('common.total') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($collections as $c)
                    <tr>
                        <td style="text-align:start">{{ $c['client'] ?? '—' }}</td>
                        <td class="num" style="font-size:11px" dir="ltr">{{ $c['at'] ?? '—' }}</td>
                        <td>
                            <span class="badge {{ ($c['method'] ?? '') === 'cash' ? 'b-green' : 'b-blue' }}">
                                {{ $c['method_label'] ?? '—' }}</span>
                            @if (($c['method'] ?? '') === 'cheque' && ! empty($c['cheque_due']))
                                <div style="font-size:10px;color:var(--muted)">
                                    {{ $c['cheque_bank'] ?? '' }} · {{ $c['cheque_due'] }}</div>
                            @endif
                        </td>
                        <td class="num" style="font-size:11px">{{ ($c['reference'] ?? '') ?: '—' }}</td>
                        <td class="num pos"><b>{{ $fmt($c['amount'] ?? 0) }}</b></td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:22px">
                        {{ __('settle.none') }}</td></tr>
                @endforelse
            </tbody>
            @if ($collections->isNotEmpty())
                <tfoot>
                    <tr class="st-sum">
                        <td colspan="4" style="text-align:start"><b>{{ __('common.total') }}</b></td>
                        <td class="num"><b>{{ $fmt($collections->sum('amount')) }}</b></td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</div>

{{-- ═══ مرتجعات النافذة + مرتجعات الكاش اللي اتردّت نقدي ═══ --}}
<div class="grid2">
    <div class="card">
        <h3>📥 {{ __('settle.returns_window') }}
            <span class="side">
                {{ __('field.return_good_units') }}: {{ number_format($f['returns_good']) }} ·
                {{ __('field.return_damaged_units') }}: {{ number_format($f['returns_damaged']) }}
            </span></h3>
        <div class="tablewrap st-tbl">
            <table data-plain>
                <thead>
                    <tr>
                        <th style="text-align:start">{{ __('common.number') }}</th>
                        <th>{{ __('client.client') }}</th>
                        <th>{{ __('field.return_policy') }}</th>
                        <th>{{ __('common.total') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($f['returns'] as $r)
                        <tr>
                            <td style="text-align:start"><b>{{ $r->number }}</b></td>
                            <td>{{ $r->client?->fullName() ?? '—' }}</td>
                            <td><span class="badge b-purple">{{ $r->policyLabel() }}</span></td>
                            <td class="num"><b>{{ $fmt($r->grand_total) }}</b></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" style="text-align:center;color:var(--muted);padding:22px">
                            {{ __('settle.none') }}</td></tr>
                    @endforelse
                </tbody>
                @if ($f['returns']->isNotEmpty())
                    <tfoot>
                        <tr class="st-sum">
                            <td colspan="3" style="text-align:start"><b>{{ __('common.total') }}</b></td>
                            <td class="num"><b>{{ $fmt($f['returns_value']) }}</b></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>

    <div class="card">
        <h3>↩️ {{ __('settle.cash_refunds') }}
            <span class="side">{{ $fmt($f['cash_refunds']) }}</span></h3>
        <div class="tablewrap st-tbl">
            <table data-plain>
                <thead>
                    <tr>
                        <th style="text-align:start">{{ __('client.client') }}</th>
                        <th>{{ __('common.time') }}</th>
                        <th>{{ __('common.total') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($f['refund_rows'] as $t)
                        <tr>
                            <td style="text-align:start">{{ $t->client?->fullName() ?? '—' }}</td>
                            <td class="num" style="font-size:11px" dir="ltr">{{ $t->created_at->format('m-d h:i A') }}</td>
                            <td class="num neg"><b>{{ $fmt($t->debit) }}</b></td>
                        </tr>
                    @empty
                        <tr><td colspan="3" style="text-align:center;color:var(--muted);padding:22px">
                            {{ __('settle.none') }}</td></tr>
                    @endforelse
                </tbody>
                @if ($f['refund_rows']->isNotEmpty())
                    <tfoot>
                        <tr class="st-sum">
                            <td colspan="2" style="text-align:start"><b>{{ __('common.total') }}</b></td>
                            <td class="num"><b>{{ $fmt($f['cash_refunds']) }}</b></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>
</div>

@endsection

@section('scripts')
<style>
.st-tbl th, .st-tbl td { text-align: center; vertical-align: middle; }
.st-tbl tfoot .st-sum td { border-top: 2px solid var(--ink); background: var(--card2); }
.st-recon { display: flex; flex-direction: column; gap: 8px; }
.st-eq {
    padding: 11px 14px; border-radius: 10px; font-weight: 800; font-size: 13.5px;
    background: var(--blue-050, #E8F1FF); color: var(--royal-blue, #12399B);
    border-inline-start: 4px solid var(--royal-blue, #12399B);
}
.st-eq.mid {
    background: var(--purple-050, #F2ECFF); color: var(--purple-heart, #602D90);
    border-inline-start-color: var(--purple-heart, #602D90);
}
.st-warn {
    margin-top: 12px; padding: 12px 14px; border-radius: 10px; font-size: 12.5px;
    background: #FEF3E7; border: 1px solid var(--orange, #EA8C1C); color: var(--ink);
}
.st-warn table th, .st-warn table td { padding: 5px 12px; }
</style>
@endsection
