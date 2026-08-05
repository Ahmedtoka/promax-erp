@extends('layouts.system')

{{--
    محضر تصفية مندوب — بيتطبع ويتمضي من المندوب والحسابات (2026-08-06).
    نفس نظام مستندات السيستم (partials._doc_style).
--}}

@php $fmt = fn ($n) => number_format((float) $n, 2); @endphp

@section('title', __('settle.doc_title').' '.$s->number)

@section('actions')
    <a class="btn" href="{{ route('erp.repclose') }}">← {{ __('settle.title') }}</a>
    <button class="btn gold" onclick="window.print()">🖨️ {{ __('ops.print') }}</button>
@endsection

@section('content')

@if (session('ok'))
    <div class="alert good" style="margin-bottom:12px"><span>✅</span><span>{{ session('ok') }}</span></div>
@endif

<div class="doc has-bolt">
    <img class="bolt-mark" src="{{ asset('brand/bolt.svg') }}" alt="">

    <header class="doc-head">
        <div class="doc-brand">
            <img src="{{ asset('img/promax-logo.png') }}" alt="PROMAX" class="doc-logo">
            <div class="doc-corp">{{ __('ops.corp_name') }}</div>
        </div>
        <div class="doc-id">
            <div class="doc-no">{{ $s->number }}</div>
            <div class="doc-date">{{ __('settle.doc_title') }}</div>
            <div class="doc-date">{{ $s->to_at->format('Y-m-d — H:i') }}</div>
        </div>
    </header>

    <div class="doc-body">
        <div class="doc-parties">
            <div>
                <div class="k">{{ __('settle.rep') }}</div>
                <div class="v">{{ $s->user?->displayName() ?? '—' }}</div>
                <div class="s">{{ $s->user?->code }}</div>
            </div>
            <div>
                <div class="k">{{ __('settle.open_window') }}</div>
                <div class="v" dir="ltr" style="font-size:13px">
                    {{ $s->from_at?->format('Y-m-d H:i') ?? '—' }} → {{ $s->to_at->format('Y-m-d H:i') }}
                </div>
                <div class="s">{{ __('settle.invoice_count', ['count' => $s->invoices_count]) }}</div>
            </div>
            <div>
                <div class="k">{{ __('settle.by') }}</div>
                <div class="v">{{ $s->creator?->name ?? '—' }}</div>
            </div>
        </div>

        <div class="tablewrap">
            <table class="doc-table">
                <tr><th>{{ __('common.total') }}</th><th class="num">{{ __('common.currency') }}</th></tr>
                <tr><td>{{ __('settle.cash_sales') }}</td><td class="num"><b>{{ $fmt($s->cash_sales) }}</b></td></tr>
                <tr><td>{{ __('settle.cash_refunds') }}</td><td class="num">({{ $fmt($s->cash_refunds) }})</td></tr>
                <tr><td><b>{{ __('settle.expected') }}</b></td><td class="num"><b>{{ $fmt($s->expected) }}</b></td></tr>
                <tr><td>{{ __('settle.prev_balance') }}</td><td class="num">{{ $fmt($s->prev_balance) }}</td></tr>
                <tr><td><b>{{ __('settle.due_total') }}</b></td><td class="num"><b>{{ $fmt((float) $s->expected + (float) $s->prev_balance) }}</b></td></tr>
                <tr><td><b>{{ __('settle.received') }}</b></td><td class="num"><b>{{ $fmt($s->received) }}</b></td></tr>
                <tr>
                    <td><b>{{ __('settle.balance') }} — {{ $s->balanceLabel() }}</b></td>
                    <td class="num"><b>{{ $fmt(abs((float) $s->balance)) }}</b></td>
                </tr>
                {{-- الآجل للعلم — مش نقدية --}}
                <tr><td style="color:var(--muted)">{{ __('settle.credit_sales') }}</td>
                    <td class="num" style="color:var(--muted)">{{ $fmt($s->credit_sales) }}</td></tr>
            </table>
        </div>

        @if ($s->note)
            <div style="font-size:12px;margin-top:8px"><b>{{ __('settle.note') }}:</b> {{ $s->note }}</div>
        @endif

        <div class="doc-sign">
            <div><span></span>{{ __('settle.sign_rep') }}</div>
            <div><span></span>{{ __('settle.sign_accountant') }}</div>
        </div>
    </div>

    <footer class="doc-foot">
        <span>PROMAX FOOD INDUSTRIES</span>
        <span>{{ $s->number }} · {{ __('settle.doc_title') }}</span>
    </footer>
</div>

@endsection

@section('scripts')
@include('partials._doc_style')
@endsection
