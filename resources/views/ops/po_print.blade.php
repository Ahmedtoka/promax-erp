@extends('layouts.system')

{{--
    مستند أمر التوريد — الحسابات بتطبعه **نسختين** وتختمهم بعد
    الموافقة: نسخة بتمشي مع السواق للفرع، ونسخة بترجع مختومة
    من الفرع (شرط رابت وأمثالها: مفيش استلام من غير أمر مختوم).
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
            @if ($po->source)
                <div class="doc-date">{{ __('ops.po_source_no') }}: {{ $po->source }}</div>
            @endif
            <div class="doc-date">{{ $po->created_at->format('Y-m-d — H:i') }}</div>
        </div>
    </header>

    <div class="frow" style="margin:14px 0">
        <div>
            <div style="font-size:11px;color:var(--muted)">{{ __('ops.branch_client') }}</div>
            <b>{{ $po->client?->fullName() ?? '—' }}</b>
            <div style="font-size:11px;color:var(--muted)">{{ $po->client?->address ?: '' }}</div>
        </div>
        <div>
            <div style="font-size:11px;color:var(--muted)">{{ __('ops.rep') }}</div>
            <b>{{ $po->courier?->name ?? '—' }}</b>
        </div>
        <div>
            <div style="font-size:11px;color:var(--muted)">{{ __('stock.warehouse') }}</div>
            <b>{{ $po->warehouse?->displayName() ?? '—' }}</b>
        </div>
        <div>
            <div style="font-size:11px;color:var(--muted)">{{ __('ops.due_at') }}</div>
            <b>{{ $po->due_at?->format('Y-m-d H:i') ?? ($po->due_date?->format('Y-m-d') ?? '—') }}</b>
        </div>
    </div>

    <div class="tablewrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>{{ __('stock.barcode') }}</th>
                    <th>{{ __('stock.item') }}</th>
                    <th class="num">{{ __('common.qty') }}</th>
                    <th>{{ __('stock.pack_tiers') }}</th>
                    <th class="num">{{ __('ops.price') }}</th>
                    <th class="num">{{ __('common.total') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($po->items as $i => $it)
                    <tr>
                        <td class="num">{{ $i + 1 }}</td>
                        <td class="num">{{ $it->product?->barcode ?? '—' }}</td>
                        <td><b>{{ $it->product?->displayName() ?? '—' }}</b></td>
                        <td class="num"><b>{{ number_format($it->qty) }}</b></td>
                        <td style="font-size:10.5px;color:var(--muted)">{{ $it->product?->packBreakdown((int) $it->qty) ?: '—' }}</td>
                        <td class="num">{{ $fmt($it->price) }}</td>
                        <td class="num">{{ $fmt($it->total) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3"><b>{{ __('common.total') }}</b></td>
                    <td class="num"><b>{{ number_format($qtyTotal) }}</b></td>
                    <td></td>
                    <td></td>
                    <td class="num"><b>{{ $fmt($po->total) }}</b></td>
                </tr>
                @if ((float) $po->tax_total > 0)
                    <tr>
                        <td colspan="6">{{ __('ops.tax_line') }}</td>
                        <td class="num">{{ $fmt($po->tax_total) }}</td>
                    </tr>
                @endif
                <tr>
                    <td colspan="6"><b>{{ __('ops.po_amount') }}</b></td>
                    <td class="num"><b>{{ $fmt($po->payable()) }}</b></td>
                </tr>
            </tfoot>
        </table>
    </div>

    {{-- خانات الختم والإمضاء — نسخة السواق ونسخة بترجع مختومة من الفرع --}}
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:18px;margin-top:34px;text-align:center;font-size:12px">
        <div>
            <div style="border-top:1.5px solid var(--ink);padding-top:8px;font-weight:800">{{ __('ops.stamp_accounting') }}</div>
        </div>
        <div>
            <div style="border-top:1.5px solid var(--ink);padding-top:8px;font-weight:800">{{ __('ops.stamp_warehouse') }}</div>
        </div>
        <div>
            <div style="border-top:1.5px solid var(--ink);padding-top:8px;font-weight:800">{{ __('ops.stamp_branch') }}</div>
        </div>
    </div>
</div>

@endsection
