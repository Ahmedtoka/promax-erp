@extends('layouts.system')

{{--
    فاتورة المبيعات / الفاتورة الضريبية — بنفس تركيبة أمر التوريد
    (قرار المالك ١١ أغسطس ٢٠٢٦): هيدر مضغوط، سطر الأطراف (العميل
    والمندوب والزيارة)، ١٥ صنف في الصفحة، وتمبلت صفحات إضافية عشان
    الفاتورة الكبيرة تتطبع على صفحتين تلاتة. الستايل من `_po_doc_style`.

    ⚠️ التجميعة من قيم الفاتورة المخزّنة (`subtotal/discount/total/
    tax_total/payable`) — دي مستند قانوني، الأرقام مصدرها الفاتورة
    نفسها مش إعادة حساب من السطور.
--}}

@section('title', __('ops.invoice').' '.$inv->number)

@php
    $fmtDoc = fn ($n) => number_format((float) $n, 2);
    $co = \App\Models\Setting::docHeader();
    $hasTax = $inv->hasTax();

    // عدد الأعمدة — بيفرق مع/بلا عمود الضريبة (للصف الفاضي وصف الإجمالي)
    $colsDoc = $hasTax ? 8 : 7;
    $qtyTotalDoc = (int) $inv->items->sum('qty');

    // ═══ تقسيم السطور على ورقات A4 — نفس منطق أمر التوريد ═══
    $rowsLastDoc = 15;
    $rowsFullDoc = 20;
    $pagesDoc = [];
    $restDoc = $inv->items->values();
    while ($restDoc->count() > $rowsLastDoc) {
        $takeDoc = min($rowsFullDoc, $restDoc->count() - 1);
        $pagesDoc[] = $restDoc->slice(0, $takeDoc)->values();
        $restDoc = $restDoc->slice($takeDoc)->values();
    }
    $pagesDoc[] = $restDoc;
    $pageCountDoc = count($pagesDoc);
    $padRows = max(0, 15 - $restDoc->count());
    $rowStartDoc = 0;

    $docKind = $hasTax ? __('tax.tax_invoice') : __('ops.sales_invoice');
@endphp

@section('actions')
    <a class="btn" href="{{ route('ops.invoices') }}">← {{ __('ops.all_invoices') }}</a>
    <button class="btn gold" onclick="window.print()">🖨️ {{ __('ops.print') }}</button>
@endsection

@section('content')

@foreach ($pagesDoc as $pageItemsDoc)
@php $isLastDoc = $loop->last; @endphp
<div class="doc po-doc has-bolt{{ $isLastDoc ? '' : ' po-cont' }}">
    <img class="bolt-mark po-bolt" src="{{ asset('brand/bolt.svg') }}" alt="">

    {{-- ═══ الهيدر المضغوط — بيتكرر على كل ورقة ═══ --}}
    <header class="doc-head">
        <div class="po-brandrow">
            <img src="{{ asset('img/promax-logo.png') }}" alt="PROMAX" class="doc-logo">
            <div class="po-corp">
                <div class="po-corp-name">{{ $co['name'] ?: __('ops.corp_name') }}</div>
                @if ($hasTax && $co['tax_id'])
                    <div class="po-corp-line">{{ __('doc.tax_id') }}: <b>{{ $co['tax_id'] }}</b></div>
                @endif
                @if ($co['cr'])
                    <div class="po-corp-line">{{ __('doc.cr') }}: <b>{{ $co['cr'] }}</b></div>
                @endif
            </div>
        </div>

        <div class="doc-id">
            <div class="doc-no">{{ $inv->number }}</div>
            <div class="doc-date">{{ __('doc.date') }}:
                <b>{{ $inv->created_at?->format('Y-m-d') ?? '—' }}</b></div>
            <div class="doc-date">{{ __('doc.time') }}:
                <b>{{ $inv->created_at?->format('h:i A') ?? '—' }}</b></div>
            @if ($pageCountDoc > 1)
                <div class="doc-date po-pageno">{{ __('doc.page_of', ['p' => $loop->iteration, 't' => $pageCountDoc]) }}</div>
            @endif
        </div>
    </header>

    {{-- العنوان الكبير — فاتورة ضريبية / مبيعات --}}
    <div class="po-title">{{ $docKind }}
        <span class="badge {{ $inv->payment === 'cash' ? 'b-green' : 'b-orange' }}"
              style="font-size:11px;vertical-align:middle;margin-inline-start:8px">{{ $inv->paymentLabel() }}</span>
    </div>

    <div class="doc-body">
        {{-- ═══ سطر الأطراف: المندوب + الزيارة (زي أمر التوريد) ═══ --}}
        <div class="po-parties">
            <div class="po-party">
                <span>{{ __('ops.rep') }}: <b>{{ $inv->user?->displayName() ?? '—' }}</b></span>
                <span class="sep">·</span>
                <span>{{ __('common.code') }}: <b>{{ $inv->user?->code ?? '—' }}</b></span>
            </div>
            <div class="po-party">
                <span>{{ __('ops.visit') }}: <b>{{ $inv->visit?->checked_in_at?->format('Y-m-d h:i A') ?? '—' }}</b></span>
                <span class="sep">·</span>
                <span>{{ $inv->visit?->minutes() ? __('ops.minutes', ['count' => $inv->visit->minutes()]) : __('ops.in_progress') }}</span>
            </div>
        </div>

        {{-- سطر العميل الكامل بالعرض — الاسم والعنوان والتليفون --}}
        <div class="po-client-line">
            <span>{{ __('client.client') }}: <b>{{ $inv->client?->displayName() ?? '—' }}</b></span>
            @if ($inv->client?->address)
                <span class="sep">·</span>
                <span>{{ __('doc.address') }}: {{ $inv->client->address }}</span>
            @endif
            @if ($inv->client?->phone)
                <span class="sep">·</span>
                <span dir="ltr">{{ $inv->client->phone }}</span>
            @endif
            @if ($hasTax && $inv->client?->tax_id)
                <span class="sep">·</span>
                <span>{{ __('doc.tax_id') }}: {{ $inv->client->tax_id }}</span>
            @endif
        </div>

        {{-- ═══ الجدول — من غير سكرول، رأسه بيتكرر على كل ورقة ═══ --}}
        <table class="doc-table po-table">
            <tr>
                <th class="c-no">{{ __('doc.line_no') }}</th>
                <th class="c-bar">{{ __('stock.barcode') }}</th>
                <th class="c-item">{{ __('stock.item') }}</th>
                <th class="c-qty">{{ __('stock.unit') }}</th>
                <th class="num c-qty">{{ __('common.qty') }}</th>
                <th class="num">{{ __('common.price') }}</th>
                <th class="num">{{ __('common.total') }}</th>
                @if ($hasTax)<th class="num">{{ __('tax.tax') }}</th>@endif
            </tr>

            @foreach ($pageItemsDoc as $iDoc => $it)
                <tr>
                    <td class="num">{{ $rowStartDoc + $iDoc + 1 }}</td>
                    <td class="num bar">{{ $it->product?->barcode ?? '—' }}</td>
                    <td>
                        <b>{{ $it->product?->displayName() ?? '—' }}</b>
                        <div class="s">{{ $it->product?->code }}
                            @if ($it->batchLabel() !== '—') · {{ $it->batchLabel() }}@endif
                        </div>
                    </td>
                    <td class="s">{{ $it->product?->unitLabel() ?? '—' }}</td>
                    <td class="num"><b>{{ number_format($it->qty) }}</b></td>
                    <td class="num">{{ $fmtDoc($it->price) }}</td>
                    <td class="num"><b>{{ $fmtDoc($it->total) }}</b></td>
                    @if ($hasTax)
                        <td class="num s">
                            @if ((float) $it->tax > 0)
                                {{ $fmtDoc($it->tax) }}
                                <div class="s">{{ rtrim(rtrim(number_format((float) $it->tax_rate * 100, 2), '0'), '.') }}%</div>
                            @else
                                {{ __('tax.exempt') }}
                            @endif
                        </td>
                    @endif
                </tr>
            @endforeach

            @if ($isLastDoc)
                @for ($r = 0; $r < $padRows; $r++)
                    <tr class="pad">@for ($c = 0; $c < $colsDoc; $c++)<td>@if ($c === 0)&nbsp;@endif</td>@endfor</tr>
                @endfor

                <tr class="sum">
                    <td colspan="4"><b>{{ __('common.total') }}</b></td>
                    <td class="num"><b>{{ number_format($qtyTotalDoc) }}</b></td>
                    <td></td>
                    <td class="num"><b>{{ $fmtDoc($inv->total) }}</b></td>
                    @if ($hasTax)<td class="num"><b>{{ $fmtDoc($inv->tax_total) }}</b></td>@endif
                </tr>
            @endif
        </table>

        @if ($isLastDoc)
            {{-- ═══ التجميعة — قيم الفاتورة المخزّنة (مستند قانوني) ═══ --}}
            <div class="po-summary">
                <div class="doc-totals" style="margin-inline-start:auto">
                    <div class="row"><span>{{ __('common.subtotal') }}</span><span>{{ $fmtDoc($inv->subtotal) }}</span></div>

                    @if ($inv->discount > 0)
                        <div class="row disc">
                            <span>{{ $inv->discountSourceLabel() }} {{ rtrim(rtrim(number_format($inv->discount_pct * 100, 2), '0'), '.') }}%</span>
                            <span>− {{ $fmtDoc($inv->discount) }}</span>
                        </div>
                    @endif

                    @if ($hasTax)
                        <div class="row net"><span>{{ __('doc.net_before_tax') }}</span><span>{{ $fmtDoc($inv->total) }}</span></div>
                        <div class="row tax"><span>{{ __('tax.vat') }} {{ $taxRateLabel }}</span><span>+ {{ $fmtDoc($inv->tax_total) }}</span></div>
                    @endif

                    <div class="row grand">
                        <span>{{ $hasTax ? __('tax.total_due') : __('common.total') }}</span>
                        <span>{{ $fmtDoc($inv->payable()) }}</span>
                    </div>
                </div>
            </div>

            {{-- خانات الإمضاء — طباعة بس --}}
            <div class="doc-sign">
                <div><span></span>{{ __('ops.rep') }}</div>
                <div><span></span>{{ __('client.client') }}</div>
            </div>
        @endif
    </div>

    @if ($isLastDoc)
        {{-- ═══ الفوتر: العنوان والتليفون والإيميل بس ═══ --}}
        <footer class="doc-foot po-foot">
            <div class="ft-inline">
                @if ($co['address'])<span>{{ $co['address'] }}</span>@endif
                @if ($co['phone'])<span dir="ltr">{{ $co['phone'] }}</span>@endif
                @if ($co['email'])<span dir="ltr">{{ $co['email'] }}</span>@endif
            </div>
        </footer>
    @endif
</div>
@php $rowStartDoc += $pageItemsDoc->count(); @endphp
@endforeach

@endsection

@section('scripts')
@include('partials._doc_style')
@include('ops._po_doc_style')
@endsection
