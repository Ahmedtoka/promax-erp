@extends('layouts.system')

{{--
    محضر تصفية مندوب — مستند A4 بيتطبع ويتمضي (اتطوّر ١١ أغسطس ٢٠٢٦ مساءً).

    ⚠️ نفس نظام مستندات المنصة: partials._doc_style + ops._po_doc_style
    (الهيدر المضغوط بهوية الشركة من settings زي الفاتورة بالظبط).

    ⚠️ كل رقم هنا من اللقطة المجمدة لحظة القفل — أعمدة rep_settlements
    و collections_json و goods_json. ممنوع أي كويري حي: الورقة اتمضت
    بأرقام لحظتها، وفتحها بعد أسبوع لازم يطلع نفس الورقة.

    ⚠️ متعدد الصفحات بالترقيم الأصلي بتاع _doc_style: الجداول برأس
    thead بيتكرر فوق كل صفحة، والصف مابينقسمش، والتوقيعات كتلة واحدة.
--}}

@php
    $fmt = fn ($n) => number_format((float) $n, 2);
    $co = \App\Models\Setting::docHeader();

    // اللقطات المجمدة — المفاتيح القديمة ممكن تكون ناقصة في مستندات
    // أقدم من ٨ أغسطس، فكل قراية بـ ?? عشان الورقة ماتقعش
    $collections = collect($s->collections_json ?? []);
    $goods = collect($s->goods_json ?? []);
    $gSum = fn (string $k) => (int) $goods->sum(fn ($l) => (int) ($l[$k] ?? 0));
    $diffTotal = $gSum('diff');
    $retIn = $gSum('returned_in');
    $damaged = $gSum('damaged_in');
@endphp

@section('title', __('settle.doc_title').' '.$s->number)

@section('actions')
    <a class="btn" href="{{ route('erp.repclose') }}">← {{ __('settle.title') }}</a>
    <a class="btn" href="{{ route('erp.repclose.details', $s) }}">🔎 {{ __('settle.details_title') }}</a>
    <button class="btn gold" onclick="window.print()">🖨️ {{ __('ops.print') }}</button>
@endsection

@section('content')

@if (session('ok'))
    <div class="alert good" style="margin-bottom:12px"><span>✅</span><span>{{ session('ok') }}</span></div>
@endif

<div class="doc po-doc has-bolt">
    <img class="bolt-mark po-bolt" src="{{ asset('brand/bolt.svg') }}" alt="">

    {{-- ═══ الهيدر — هوية الشركة من settings زي الفاتورة ═══ --}}
    <header class="doc-head">
        <div class="po-brandrow">
            <img src="{{ asset('img/promax-logo.png') }}" alt="PROMAX" class="doc-logo">
            <div class="po-corp">
                <div class="po-corp-name">{{ $co['name'] ?: __('ops.corp_name') }}</div>
                @if ($co['tax_id'])
                    <div class="po-corp-line">{{ __('doc.tax_id') }}: <b>{{ $co['tax_id'] }}</b></div>
                @endif
                @if ($co['cr'])
                    <div class="po-corp-line">{{ __('doc.cr') }}: <b>{{ $co['cr'] }}</b></div>
                @endif
            </div>
        </div>
        <div class="doc-id">
            <div class="doc-no">{{ $s->number }}</div>
            <div class="doc-date">{{ __('doc.date') }}: <b>{{ $s->to_at->format('Y-m-d') }}</b></div>
            <div class="doc-date">{{ __('doc.time') }}: <b>{{ $s->to_at->format('h:i A') }}</b></div>
        </div>
    </header>

    <div class="po-title">{{ __('settle.doc_title') }}</div>

    <div class="doc-body">
        {{-- ═══ المندوب والنافذة — سطر الأطراف زي الفاتورة ═══ --}}
        <div class="po-parties">
            <div class="po-party">
                <span>{{ __('settle.rep') }}: <b>{{ $s->user?->displayName() ?? '—' }}</b></span>
                <span class="sep">·</span>
                <span>{{ __('common.code') }}: <b>{{ $s->user?->code ?? '—' }}</b></span>
            </div>
            <div class="po-party">
                <span>{{ __('settle.by') }}: <b>{{ $s->creator?->name ?? '—' }}</b></span>
            </div>
        </div>
        <div class="po-client-line">
            <span>{{ __('settle.window') }}:
                <b dir="ltr">{{ $s->from_at?->format('Y-m-d h:i A') ?? __('settle.since_start') }} ← {{ $s->to_at->format('Y-m-d h:i A') }}</b>
            </span>
            <span class="sep">·</span>
            <span>{{ __('settle.invoice_count', ['count' => $s->invoices_count]) }}</span>
        </div>

        {{-- ═══ جدول ملخص الفلوس — التسلسل بيقفل قدام اللي بيمضي ═══ --}}
        <div class="st-sec">💰 {{ __('settle.money_summary') }}</div>
        <table class="doc-table st-money">
            <thead>
                <tr>
                    <th style="text-align:start">{{ __('settle.money_summary') }}</th>
                    <th class="num">{{ __('common.currency') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>{{ __('settle.cash_sales') }}
                        <div class="s">{{ __('settle.cash_components') }}</div></td>
                    <td class="num"><b>{{ $fmt($s->cash_sales) }}</b></td>
                </tr>
                {{-- الآجل للعلم — مش نقدية، وسطر المكونات بيمنع سؤال
                     «ليه الرقم ده أكبر من شاشة فواتير المندوب؟» --}}
                <tr class="st-mut">
                    <td>{{ __('settle.credit_sales') }}
                        <div class="s">{{ __('settle.credit_components') }}</div></td>
                    <td class="num">{{ $fmt($s->credit_sales) }}</td>
                </tr>
                <tr>
                    <td>{{ __('settle.field_collections') }}</td>
                    <td class="num">{{ $fmt($s->cash_collections) }}</td>
                </tr>
                <tr>
                    <td>{{ __('settle.cash_refunds') }}</td>
                    <td class="num">({{ $fmt($s->cash_refunds) }})</td>
                </tr>
                <tr class="st-strong">
                    <td><b>{{ __('settle.expected') }}</b>
                        <div class="s">{{ __('settle.expected_hint') }}</div></td>
                    <td class="num"><b>{{ $fmt($s->expected) }}</b></td>
                </tr>
                <tr>
                    <td>{{ __('settle.prev_balance') }}</td>
                    <td class="num">{{ $fmt($s->prev_balance) }}</td>
                </tr>
                <tr class="st-strong">
                    <td><b>{{ __('settle.due_total') }}</b>
                        <div class="s">{{ __('settle.due_total_hint') }}</div></td>
                    <td class="num"><b>{{ $fmt((float) $s->expected + (float) $s->prev_balance) }}</b></td>
                </tr>
                <tr>
                    <td><b>{{ __('settle.received') }}</b></td>
                    <td class="num"><b>{{ $fmt($s->received) }}</b></td>
                </tr>
                <tr class="st-grand">
                    <td><b>{{ __('settle.final_balance') }} — {{ $s->balanceLabel() }}</b></td>
                    <td class="num"><b>{{ $fmt(abs((float) $s->balance)) }}</b></td>
                </tr>
            </tbody>
        </table>

        {{-- ═══ جدول التحصيلات — من اللقطة المجمدة بطرقها ومراجعها ═══ --}}
        @if ($collections->isNotEmpty())
            <div class="st-sec">🧾 {{ __('settle.collections_to_match') }}
                <span class="s">— {{ __('settle.collections_hint') }}</span></div>
            <table class="doc-table">
                <thead>
                    <tr>
                        <th style="text-align:start">{{ __('client.client') }}</th>
                        <th>{{ __('common.time') }}</th>
                        <th>{{ __('ops.method') }}</th>
                        <th>{{ __('ops.reference') }}</th>
                        <th class="num">{{ __('common.total') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($collections as $c)
                        <tr>
                            <td>{{ $c['client'] ?? '—' }}</td>
                            <td class="num" dir="ltr">{{ $c['at'] ?? '—' }}</td>
                            <td>
                                {{ $c['method_label'] ?? '—' }}
                                @if (($c['method'] ?? '') === 'cheque' && ! empty($c['cheque_due']))
                                    <div class="s">{{ $c['cheque_bank'] ?? '' }} · {{ $c['cheque_due'] }}</div>
                                @endif
                            </td>
                            <td class="num">{{ ($c['reference'] ?? '') ?: '—' }}</td>
                            <td class="num"><b>{{ $fmt($c['amount'] ?? 0) }}</b></td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="sum">
                        <td colspan="4"><b>{{ __('common.total') }}</b></td>
                        <td class="num"><b>{{ $fmt($collections->sum('amount')) }}</b></td>
                    </tr>
                </tfoot>
            </table>
        @endif

        {{-- ═══ مطابقة البضاعة — من goods_json، صف أحمر لو فيه عجز ═══ --}}
        @if ($goods->isNotEmpty())
            <div class="st-sec">📦 {{ __('settle.goods_match') }}
                <span class="s">— {{ __('settle.goods_formula') }}</span></div>
            <table class="doc-table st-goods">
                <thead>
                    <tr>
                        <th style="text-align:start">{{ __('stock.product') }}</th>
                        <th class="num">{{ __('settle.loaded') }}</th>
                        <th class="num">{{ __('settle.sold_cash') }}</th>
                        <th class="num">{{ __('settle.sold_credit') }}</th>
                        <th class="num">{{ __('settle.delivered_pos') }}</th>
                        <th class="num">{{ __('settle.gifts') }}</th>
                        <th class="num">{{ __('settle.returned_wh') }}</th>
                        {{-- حد التحويل لمندوب تاني (١٤/٨) --}}
                        <th class="num">{{ __('settle.transfer_out') }}</th>
                        <th class="num">{{ __('settle.still_on_van') }}</th>
                        <th class="num">{{ __('settle.shortage') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($goods as $l)
                        {{-- المفاتيح الجديدة بـ ?? 0 — مستندات ما قبل ٨/٨ ناقصاها --}}
                        <tr class="{{ (int) ($l['diff'] ?? 0) === 0 ? '' : 'st-short' }}">
                            <td style="text-align:start">{{ $l['name'] ?? '—' }}</td>
                            <td class="num"><b>{{ number_format((int) ($l['assigned'] ?? 0)) }}</b></td>
                            <td class="num">{{ number_format((int) ($l['cash_qty'] ?? 0)) }}</td>
                            <td class="num">{{ number_format((int) ($l['credit_qty'] ?? 0)) }}</td>
                            <td class="num">{{ number_format((int) ($l['po_qty'] ?? 0)) }}</td>
                            <td class="num">{{ number_format((int) ($l['gift'] ?? 0)) }}</td>
                            <td class="num">{{ number_format((int) ($l['returned_wh'] ?? 0)) }}</td>
                            <td class="num">{{ number_format((int) ($l['transfer_out'] ?? 0)) }}</td>
                            <td class="num">{{ number_format((int) ($l['remaining'] ?? 0)) }}</td>
                            <td class="num">
                                {{ (int) ($l['diff'] ?? 0) === 0 ? '—' : number_format((int) $l['diff']) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="sum">
                        <td style="text-align:start"><b>{{ __('common.total') }}</b></td>
                        <td class="num"><b>{{ number_format($gSum('assigned')) }}</b></td>
                        <td class="num"><b>{{ number_format($gSum('cash_qty')) }}</b></td>
                        <td class="num"><b>{{ number_format($gSum('credit_qty')) }}</b></td>
                        <td class="num"><b>{{ number_format($gSum('po_qty')) }}</b></td>
                        <td class="num"><b>{{ number_format($gSum('gift')) }}</b></td>
                        <td class="num"><b>{{ number_format($gSum('returned_wh')) }}</b></td>
                        <td class="num"><b>{{ number_format($gSum('transfer_out')) }}</b></td>
                        <td class="num"><b>{{ number_format($gSum('remaining')) }}</b></td>
                        <td class="num {{ $diffTotal === 0 ? '' : 'st-short-cell' }}">
                            <b>{{ $diffTotal === 0 ? '0 ✓' : number_format($diffTotal) }}</b>
                        </td>
                    </tr>
                </tfoot>
            </table>

            {{-- بضاعة العملاء المرتجعة — بره المعادلة، بتتسلّم مع التصفية --}}
            @if ($retIn > 0 || $damaged > 0)
                <table class="doc-table" style="margin-top:8px">
                    <thead>
                        <tr>
                            <th style="text-align:start">{{ __('settle.returned_in') }}</th>
                            <th class="num">{{ __('field.return_good_units') }}</th>
                            <th class="num">{{ __('field.return_damaged_units') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td style="text-align:start">{{ __('settle.returned_in_hint') }}</td>
                            <td class="num"><b>{{ number_format($retIn) }}</b></td>
                            <td class="num {{ $damaged > 0 ? 'st-short-cell' : '' }}"><b>{{ number_format($damaged) }}</b></td>
                        </tr>
                    </tbody>
                </table>
            @endif

            @if ($diffTotal !== 0)
                <div class="st-alert">
                    ⚠️ {{ __('settle.shortage') }}: {{ number_format($diffTotal) }}
                    {{ __('common.piece') }} — {{ __('settle.shortage_hint') }}
                </div>
            @endif
        @endif

        {{-- ═══ خانة الملاحظات — فاضية برضه: مكان الكتابة بالإيد ═══ --}}
        <div class="doc-note st-note">
            <b>{{ __('settle.note') }}:</b> {{ $s->note ?: '' }}
        </div>

        {{-- ═══ التوقيعات التلاتة — المندوب / الحسابات / الإدارة ═══ --}}
        <div class="doc-sign three">
            <div><span></span>{{ __('settle.sign_rep') }}</div>
            <div><span></span>{{ __('settle.sign_accountant') }}</div>
            <div><span></span>{{ __('settle.sign_management') }}</div>
        </div>
    </div>

    {{-- ═══ الفوتر — بيانات التواصل من settings زي الفاتورة ═══ --}}
    <footer class="doc-foot po-foot">
        <div class="ft-inline">
            <span>{{ $s->number }} · {{ __('settle.doc_title') }}</span>
            @if ($co['address'])<span>{{ $co['address'] }}</span>@endif
            @if ($co['phone'])<span dir="ltr">{{ $co['phone'] }}</span>@endif
            @if ($co['email'])<span dir="ltr">{{ $co['email'] }}</span>@endif
        </div>
    </footer>
</div>

@endsection

@section('scripts')
@include('partials._doc_style')
@include('ops._po_doc_style')
<style>
/* ═══ زيادات محضر التصفية على نظام المستندات المشترك ═══ */
.st-sec{position:relative;z-index:1;font-size:12px;font-weight:900;margin:16px 0 6px}
.st-sec .s{font-weight:400;color:var(--muted);font-size:10.5px}
.doc-body .doc-table{width:100%;margin-top:2px}
.st-money td{padding:8px}
.st-money .s{margin-top:2px}
.st-mut td{color:var(--muted)}
.st-strong td{border-top:1px solid var(--border);background:var(--card2)}
.st-grand td{border-top:2px solid var(--royal-blue);font-size:14px}
.doc-table tfoot tr.sum td{border-top:2px solid var(--ink);background:var(--card2)}
/* صف العجز أحمر — والمحاسب يشوفه قبل ما يمضي */
tr.st-short td{background:#FDECEC;color:var(--red)}
.st-short-cell{color:var(--red);font-weight:900}
.st-goods td,.st-goods th{font-size:10.5px}
.st-alert{
  position:relative;z-index:1;
  font-size:11.5px;margin-top:8px;color:var(--red);font-weight:800;
}
.st-note{min-height:52px}
@media print{
  .st-strong td,.st-grand td,tr.st-short td,.doc-table tfoot tr.sum td{
    -webkit-print-color-adjust:exact;print-color-adjust:exact;
  }
  .st-sec{break-after:avoid;page-break-after:avoid}
}
</style>
@endsection
