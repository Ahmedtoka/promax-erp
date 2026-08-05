@extends('layouts.system')

{{--
    مستند أمر التوريد — الحسابات بتطبعه **نسختين** وتختمهم بعد
    الموافقة: نسخة بتمشي مع السواق للفرع، ونسخة بترجع مختومة
    من الفرع (شرط رابت وأمثالها: مفيش استلام من غير أمر مختوم).

    ⚠️ بنفس مفردات ستايل الورق (`_doc_style`): doc-head/doc-body/
    doc-parties/doc-table/doc-totals/doc-sign — أي هيكل تاني بيطلع
    بمسافات مكسورة على A4 (حصلت 2026-08-05).
--}}

@php
    $fmt = fn ($n) => number_format((float) $n, 2);
    $qtyTotal = (int) $po->items->sum('qty');
@endphp

@section('title', __('ops.po_doc').' '.$po->number)

@section('actions')
    <a class="btn" href="{{ route('ops.pos') }}">← {{ __('ops.purchase_orders') }}</a>
    <button class="btn gold" onclick="window.print()">🖨️ {{ __('ops.print') }}</button>
@endsection

@section('content')

<div class="doc has-bolt">
    <img class="bolt-mark lg" src="{{ asset('brand/bolt.svg') }}" alt="">

    <header class="doc-head">
        <div class="doc-brand">
            <img src="{{ asset('img/promax-logo.png') }}" alt="PROMAX" class="doc-logo">
            <div class="doc-corp">{{ __('ops.corp_name') }}</div>
        </div>
        <div class="doc-id">
            <div class="doc-kind">{{ __('ops.po_doc') }}</div>
            <div class="doc-no">{{ $po->number }}</div>
            <div class="doc-date">
                @if ($po->source){{ __('ops.po_source_no') }}: {{ $po->source }} · @endif
                {{ $po->created_at->format('Y-m-d — H:i') }}
            </div>
            @if ($po->approval_status)
                <span class="badge {{ $po->approvalClass() }}">{{ $po->approvalLabel() }}</span>
            @endif
        </div>
    </header>

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
                        <td class="num">{{ $fmt($it->price) }}</td>
                        <td class="num">{{ $fmt($it->total) }}</td>
                    </tr>
                @endforeach

                <tr>
                    <td colspan="3"><b>{{ __('common.total') }}</b></td>
                    <td class="num"><b>{{ number_format($qtyTotal) }}</b></td>
                    <td></td>
                    <td class="num"><b>{{ $fmt($po->total) }}</b></td>
                </tr>
            </table>
        </div>

        <div class="doc-totals">
            @if ((float) $po->tax_total > 0)
                <div class="row tax"><span>{{ __('ops.tax_line') }}</span><span>{{ $fmt($po->tax_total) }}</span></div>
            @endif
            <div class="row grand"><span>{{ __('ops.po_amount') }}</span><span>{{ $fmt($po->payable()) }}</span></div>
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

@endsection

@section('scripts')
@include('partials._doc_style')
@endsection
