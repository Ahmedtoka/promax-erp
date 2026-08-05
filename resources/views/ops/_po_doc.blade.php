{{--
    جسم مستند أمر التوريد — مشترك بين الطباعة الفردية (po_print)
    والمجمعة (po_print_batch). بياخد `$po` محمّل بعلاقاته.
    الستايل بتاعه في `ops/_po_doc_style` — لازم يتضمّن مرة في الصفحة.
--}}

@php
    $fmtDoc = fn ($n) => number_format((float) $n, 2);
    $qtyTotalDoc = (int) $po->items->sum('qty');
@endphp

<div class="doc po-doc has-bolt">
    <img class="bolt-mark po-bolt" src="{{ asset('brand/bolt.svg') }}" alt="">

    <header class="doc-head">
        <div class="doc-brand">
            <img src="{{ asset('img/promax-logo.png') }}" alt="PROMAX" class="doc-logo">
            <div class="doc-corp">{{ __('ops.corp_name') }}</div>
        </div>
        <div class="doc-id">
            <div class="doc-no">{{ $po->number }}</div>
            @if ($po->source)
                <div class="doc-date">{{ __('ops.po_source_no') }}: <b>{{ $po->source }}</b></div>
            @endif
            <div class="doc-date">{{ $po->created_at->format('Y-m-d — H:i') }}</div>
        </div>
    </header>

    {{-- العنوان الكبير في نص الورقة --}}
    <div class="po-title">{{ __('ops.po_doc') }}</div>

    <div class="doc-body">
        <div class="doc-parties">
            <div>
                <div class="k">{{ __('ops.branch_client') }}</div>
                <div class="v">{{ $po->client?->fullName() ?? '—' }}</div>
                <div class="s">{{ $po->client?->address ?: '—' }}</div>
            </div>
            <div>
                <div class="k">{{ __('ops.rep') }}</div>
                <div class="v">{{ $po->courier?->name ?? '—' }}</div>
                <div class="s">{{ __('ops.by') }}: {{ $po->creator?->name ?? '—' }}</div>
            </div>
            <div>
                <div class="k">{{ __('stock.warehouse') }}</div>
                <div class="v">{{ $po->warehouse?->displayName() ?? '—' }}</div>
                <div class="s">{{ __('ops.due_at') }}:
                    {{ $po->due_at?->format('Y-m-d H:i') ?? ($po->due_date?->format('Y-m-d') ?? '—') }}</div>
            </div>
        </div>

        <div class="tablewrap">
            <table class="doc-table">
                <tr>
                    <th>#</th>
                    <th>{{ __('stock.barcode') }}</th>
                    <th>{{ __('stock.item') }}</th>
                    <th class="num">{{ __('common.qty') }}</th>
                    <th class="num">{{ __('ops.price') }}</th>
                    <th class="num">{{ __('common.total') }}</th>
                </tr>

                @foreach ($po->items as $i => $it)
                    <tr>
                        <td class="num">{{ $i + 1 }}</td>
                        <td class="num">{{ $it->product?->barcode ?? '—' }}</td>
                        <td>
                            <b>{{ $it->product?->displayName() ?? '—' }}</b>
                            @if ($bd = $it->product?->packBreakdown((int) $it->qty))
                                <div class="s">{{ $bd }}</div>
                            @endif
                        </td>
                        <td class="num"><b>{{ number_format($it->qty) }}</b></td>
                        <td class="num">{{ $fmtDoc($it->price) }}</td>
                        <td class="num">{{ $fmtDoc($it->total) }}</td>
                    </tr>
                @endforeach

                <tr>
                    <td colspan="3"><b>{{ __('common.total') }}</b></td>
                    <td class="num"><b>{{ number_format($qtyTotalDoc) }}</b></td>
                    <td></td>
                    <td class="num"><b>{{ $fmtDoc($po->total) }}</b></td>
                </tr>
            </table>
        </div>

        <div class="doc-totals">
            @if ((float) $po->tax_total > 0)
                <div class="row"><span>{{ __('ops.tax_line') }}</span><span>{{ $fmtDoc($po->tax_total) }}</span></div>
            @endif
            <div class="row grand"><span>{{ __('ops.po_amount') }}</span><span>{{ $fmtDoc($po->payable()) }}</span></div>
        </div>

        {{-- خانات الختم — بتظهر في الطباعة بس (doc-sign) --}}
        <div class="doc-sign three">
            <div><span></span>{{ __('ops.stamp_accounting') }}</div>
            <div><span></span>{{ __('ops.stamp_warehouse') }}</div>
            <div><span></span>{{ __('ops.stamp_branch') }}</div>
        </div>
    </div>

    <footer class="doc-foot">
        <span>PROMAX FOOD INDUSTRIES</span>
        <span>{{ $po->number }}@if ($po->source) · {{ $po->source }}@endif · {{ __('ops.po_doc') }}</span>
    </footer>
</div>
