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

        {{-- ═══════════════════════════════════════════════════════
             مطابقة العهدة على الورقة الممضية (٨ أغسطس ٢٠٢٦)
             ═══════════════════════════════════════════════════════

             ⚠️ **`goods_json` كانت بتتخزن من ٦ أغسطس ومحدش بيقراها.**
             المحضر كان فيه الفلوس بس — فالمحاسب والمندوب بيمضوا على
             ورقة مافيهاش المحمَّل ولا الباقي ولا العجز، والبضاعة اللي
             ناقصة مالهاش أي إثبات موقّع.

             ⚠️ **من اللقطة مش من العهدة الحية** — دي أرقام لحظة
             التوقيع. قراية العهدة دلوقتي كانت هتوري أرقام النهارده
             على ورقة اتمضت من أسبوع. --}}
        @php
            $goods = collect($s->goods_json ?? []);

            // ⚠️ الأرقام من نفس اللقطة عشان الجدول والمجاميع مايفرقوش
            $sum = fn (string $k) => (int) $goods->sum(fn ($l) => (int) ($l[$k] ?? 0));
            $diffTotal = $sum('diff');
        @endphp

        @if ($goods->isNotEmpty())
            <div style="font-size:12px;font-weight:900;margin:16px 0 6px">
                {{ __('settle.goods_match') }}
                <span style="font-weight:400;color:var(--muted);font-size:10.5px">
                    — {{ __('settle.goods_formula') }}</span>
            </div>

            <div class="tablewrap">
                <table class="doc-table">
                    <tr>
                        <th style="text-align:start">{{ __('stock.product') }}</th>
                        <th class="num">{{ __('settle.loaded') }}</th>
                        <th class="num">{{ __('settle.sold_cash') }}</th>
                        <th class="num">{{ __('settle.sold_credit') }}</th>
                        <th class="num">{{ __('settle.delivered_pos') }}</th>
                        <th class="num">{{ __('settle.gifts') }}</th>
                        <th class="num">{{ __('settle.returned_wh') }}</th>
                        <th class="num">{{ __('settle.still_on_van') }}</th>
                        <th class="num">{{ __('settle.shortage') }}</th>
                    </tr>
                    @foreach ($goods as $l)
                        <tr>
                            <td style="text-align:start">{{ $l['name'] ?? '—' }}</td>
                            <td class="num"><b>{{ number_format((int) ($l['assigned'] ?? 0)) }}</b></td>
                            <td class="num">{{ number_format((int) ($l['cash_qty'] ?? 0)) }}</td>
                            <td class="num">{{ number_format((int) ($l['credit_qty'] ?? 0)) }}</td>
                            {{-- ⚠️ الحدود دي اتضافت للّقطة في ٨ أغسطس — المستندات
                                 الأقدم مافيهاش، و`?? 0` بيخلّيها تتطبع صفر بدل
                                 ما الورقة تقع بـ«Undefined array key». --}}
                            <td class="num">{{ number_format((int) ($l['po_qty'] ?? 0)) }}</td>
                            <td class="num">{{ number_format((int) ($l['gift'] ?? 0)) }}</td>
                            <td class="num">{{ number_format((int) ($l['returned_wh'] ?? 0)) }}</td>
                            <td class="num">{{ number_format((int) ($l['remaining'] ?? 0)) }}</td>
                            <td class="num {{ (int) ($l['diff'] ?? 0) === 0 ? '' : 'neg' }}">
                                {{ (int) ($l['diff'] ?? 0) === 0 ? '—' : number_format((int) $l['diff']) }}
                            </td>
                        </tr>
                    @endforeach
                    <tr>
                        <td style="text-align:start"><b>{{ __('common.total') }}</b></td>
                        <td class="num"><b>{{ number_format($sum('assigned')) }}</b></td>
                        <td class="num"><b>{{ number_format($sum('cash_qty')) }}</b></td>
                        <td class="num"><b>{{ number_format($sum('credit_qty')) }}</b></td>
                        <td class="num"><b>{{ number_format($sum('po_qty')) }}</b></td>
                        <td class="num"><b>{{ number_format($sum('gift')) }}</b></td>
                        <td class="num"><b>{{ number_format($sum('returned_wh')) }}</b></td>
                        <td class="num"><b>{{ number_format($sum('remaining')) }}</b></td>
                        <td class="num {{ $diffTotal === 0 ? 'pos' : 'neg' }}">
                            <b>{{ $diffTotal === 0 ? '0 ✓' : number_format($diffTotal) }}</b>
                        </td>
                    </tr>
                </table>
            </div>

            {{-- ⚠️ **بضاعة العملاء بره المعادلة** — مالهاش أصل في
                 المحمَّل، وهي بتتسلّم للمحاسب مع التصفية. لازم تبان
                 على الورقة عشان الاستلام يكون موثّق. --}}
            @php
                $retIn = $sum('returned_in');
                $damaged = $sum('damaged_in');
            @endphp

            @if ($retIn > 0 || $damaged > 0)
                <div class="tablewrap" style="margin-top:10px">
                    <table class="doc-table">
                        <tr>
                            <th style="text-align:start">{{ __('settle.returned_in') }}</th>
                            <th class="num">{{ __('field.return_good_units') }}</th>
                            <th class="num">{{ __('field.return_damaged_units') }}</th>
                        </tr>
                        <tr>
                            <td style="text-align:start">{{ __('settle.returned_in_hint') }}</td>
                            <td class="num"><b>{{ number_format($retIn) }}</b></td>
                            <td class="num {{ $damaged > 0 ? 'neg' : '' }}"><b>{{ number_format($damaged) }}</b></td>
                        </tr>
                    </table>
                </div>
            @endif

            @if ($diffTotal !== 0)
                <div style="font-size:11.5px;margin-top:8px;color:var(--red);font-weight:800">
                    ⚠️ {{ __('settle.shortage') }}: {{ number_format($diffTotal) }}
                    {{ __('common.piece') }} — {{ __('settle.shortage_hint') }}
                </div>
            @endif
        @endif

        @if ($s->note)
            <div style="font-size:12px;margin-top:8px"><b>{{ __('settle.note') }}:</b> {{ $s->note }}</div>
        @endif

        {{-- ⚠️ **تلات إمضاءات مش اتنين** — المندوب بيسلّم بضاعة كمان
             مش فلوس بس، وأمين المخزن هو اللي بيستلمها. الورقة اللي
             فيها جدول بضاعة وإمضاءين بس مابتثبتش مين استلم الرجيع. --}}
        <div class="doc-sign {{ $goods->isNotEmpty() ? 'three' : '' }}">
            <div><span></span>{{ __('settle.sign_rep') }}</div>
            <div><span></span>{{ __('settle.sign_accountant') }}</div>
            @if ($goods->isNotEmpty())
                <div><span></span>{{ __('settle.sign_warehouse') }}</div>
            @endif
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
