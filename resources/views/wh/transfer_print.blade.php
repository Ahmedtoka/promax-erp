@extends('layouts.system')

{{--
    ═══════════════════════════════════════════════════════════════
    ورق التحويل — إذن صرف ومحضر استلام
    ═══════════════════════════════════════════════════════════════

    ⚠️ **ورقة واحدة بوضعين مش ورقتين.** الترويسة والأطراف والبنود
    والإمضاءات واحدة في الاتنين؛ الفرق عمود «المستلم» والفرق
    والإمضاء التالت. ورقتين منفصلتين معناهم إن أي تعديل (رقم
    ضريبي، عنوان، صياغة تعهّد) لازم يتعمل مرتين — والمرة اللي
    بتتنسى بتخلّي ورقتين من نفس الشركة شكلهم مختلف.

    `$mode`:
      issue   — إذن صرف، بيتطبع ساعة الإرسال
      receipt — محضر استلام، بيتطبع ساعة الاستلام
--}}

@php
    $fmt = fn ($n) => number_format((float) $n);
    $isReceipt = $mode === 'receipt';
    $short = $t->qtySent() - $t->qtyReceived();

    // ═══ التحويل الميداني (١٤/٨) — نفس الورقة بأطراف مختلفة ═══
    // الطرف المرسل مندوب مش مخزن، وفيه عمود «المصدر» عشان اللي بيمضي
    // يعرف القطع دي بتاعة عهدة عادية ولا أمر توريد ولا تحويل سابق.
    $isVan = $t->isVan();
@endphp

@section('title', ($isReceipt ? __('stock.receipt_note') : __('stock.issue_note')).' '.$t->number)

@section('actions')
    <a class="btn" href="{{ route('wh.transfers') }}">← {{ __('stock.transfers') }}</a>
    @if ($isReceipt)
        <a class="btn" href="{{ route('wh.transfers.print', $t) }}">{{ __('stock.issue_note') }}</a>
    @elseif ($t->status === 'received' && ! $isVan)
        <a class="btn" href="{{ route('wh.transfers.receipt_print', $t) }}">{{ __('stock.receipt_note') }}</a>
    @endif
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
            <div class="doc-kind">
                {{ $isVan
                    ? __('stock.van_transfer')
                    : ($isReceipt ? __('stock.receipt_note') : __('stock.issue_note')) }}
            </div>
            <div class="doc-no">{{ $t->number }}</div>
            <div class="doc-date">
                {{ $isReceipt
                    ? ($t->received_on?->format('Y-m-d') ?? '—')
                    : ($t->sent_on?->format('Y-m-d') ?? '—') }}
            </div>
            <span class="badge {{ $t->kindClass() }}">{{ $t->kindArrow() }} {{ $t->kindLabel() }}</span>
            <span class="badge {{ $t->statusClass() }}">{{ $t->statusLabel() }}</span>
        </div>
    </header>

    <div class="doc-body">
        <div class="doc-parties">
            <div>
                <div class="k">{{ $isVan ? __('stock.from_rep') : __('stock.from_warehouse') }}</div>
                <div class="v">{{ $t->fromLabel() }}</div>
                <div class="s">{{ __('stock.transfer_created_by') }}:
                    {{ $t->creator?->displayName() ?? $t->sender?->displayName() ?? '—' }}</div>
            </div>
            <div>
                <div class="k">{{ $t->kindKey() === 'rep_rep' ? __('stock.to_rep') : __('stock.to_warehouse') }}</div>
                <div class="v">{{ $t->toLabel() }}</div>
                @if ($isReceipt)
                    <div class="s">{{ __('stock.received_by') }}: {{ $t->receiver?->displayName() ?? '—' }}</div>
                @endif
            </div>
            <div>
                <div class="k">{{ $isVan ? __('stock.kind') : __('stock.carrier') }}</div>
                <div class="v">{{ $isVan ? $t->kindLabel() : ($t->carrier_name ?: '—') }}</div>
                <div class="s">{{ __('stock.sent_on') }}: {{ $t->sent_on?->format('Y-m-d') ?? '—' }}</div>
            </div>
        </div>

        {{-- ⚠️⚠️ **السبب على الورقة نفسها.** المالك طلبه إجباري، والمستند
             اللي بيسحب بضاعة من عربية مندوب من غير سبب مكتوب قدام اللي
             بيمضي = توقيع على المجهول. --}}
        @if ($t->reason)
            <div class="doc-note" style="border-color:var(--royal-blue, #12399B)">
                <b>{{ __('stock.transfer_reason') }}:</b> {{ $t->reason }}
            </div>
        @endif

        <div class="tablewrap">
            <table class="doc-table">
                <tr>
                    <th>#</th>
                    <th>{{ __('stock.item') }}</th>
                    <th>{{ __('stock.batch_no') }}</th>
                    <th>{{ __('stock.produced_on') }}</th>
                    <th>{{ __('stock.expires_on') }}</th>
                    {{-- مصدر البضاعة — «بتاعة عهدة عادية ولا أمر توريد» --}}
                    @if ($isVan)
                        <th>{{ __('stock.source') }}</th>
                    @endif
                    <th class="num">{{ __('stock.qty_sent') }}</th>
                    @if ($isReceipt)
                        <th class="num">{{ __('stock.qty_received') }}</th>
                        <th class="num">{{ __('stock.variance') }}</th>
                    @endif
                </tr>
                @foreach ($t->items as $i => $it)
                    @php $v = $isReceipt ? ((int) $it->qty_received - (int) $it->qty_sent) : 0; @endphp
                    <tr>
                        <td class="num">{{ $i + 1 }}</td>
                        <td>
                            <b>{{ $it->product?->displayName() ?? '—' }}</b>
                            <div class="s">{{ $it->product?->code }} · {{ $it->product?->unitLabel() }}</div>
                        </td>
                        <td class="num">{{ $it->batch_no }}</td>
                        <td class="num">{{ $it->produced_on?->format('Y-m-d') ?? '—' }}</td>
                        <td class="num">{{ $it->expires_on?->format('Y-m-d') ?? '—' }}</td>
                        @if ($isVan)
                            <td>{{ $it->sourceLabel() ?? __('stock.src_legacy') }}
                                @if ($it->sourceRefLabel())
                                    <div class="s" dir="ltr">{{ $it->sourceRefLabel() }}</div>
                                @endif
                            </td>
                        @endif
                        <td class="num"><b>{{ $fmt($it->qty_sent) }}</b></td>
                        @if ($isReceipt)
                            <td class="num"><b>{{ $fmt($it->qty_received) }}</b></td>
                            <td class="num doc-var {{ $v < 0 ? 'short' : 'ok' }}">
                                {{ $v === 0 ? '—' : $fmt($v) }}
                            </td>
                        @endif
                    </tr>
                @endforeach
                <tr>
                    {{-- ⚠️ عمود «المصدر» بيزوّد الـcolspan للتحويل الميداني --}}
                    <td colspan="{{ $isVan ? 6 : 5 }}"><b>{{ __('common.total') }}</b></td>
                    <td class="num"><b>{{ $fmt($t->qtySent()) }}</b></td>
                    @if ($isReceipt)
                        <td class="num"><b>{{ $fmt($t->qtyReceived()) }}</b></td>
                        <td class="num doc-var {{ $short > 0 ? 'short' : 'ok' }}">
                            <b>{{ $short === 0 ? '—' : $fmt(-$short) }}</b>
                        </td>
                    @endif
                </tr>
            </table>
        </div>

        {{-- ⚠️ **العجز لازم يتكتب بالنص على الورقة.** الرقم في خانة
             لوحده حد بيعدّي عليه؛ الجملة الصريحة هي اللي بتخلّي
             اللي بيمضي يقرا إن فيه بضاعة ناقصة قبل ما يوقّع. --}}
        @if ($isReceipt && $short > 0)
            <div class="doc-note" style="border-color:var(--red);color:var(--red)">
                <b>{{ __('stock.shortage_notice', [
                    'qty' => $fmt($short),
                    'warehouse' => $t->fromWarehouse?->displayName() ?? '—',
                ]) }}</b>
            </div>
        @endif

        @if ($t->notes)
            <div class="doc-note">{{ __('common.notes') }}: {{ $t->notes }}</div>
        @endif

        <div class="doc-note">
            {{ $isVan
                ? __('stock.van_issue_pledge')
                : ($isReceipt ? __('stock.receipt_pledge') : __('stock.issue_pledge')) }}
        </div>

        {{-- ⚠️ **المندوب بيمضي على اللي طلع من عربيته.** المستند ده هو
             الإثبات الوحيد إن البضاعة خرجت بموافقته — من غير إمضاؤه،
             أي فرق في التصفية بعدها بيبقى كلمته ضد رقم في شاشة. --}}
        <div class="doc-sign three">
            @if ($isVan)
                <div><span></span>{{ __('stock.sign_from_rep') }}</div>
                <div><span></span>{{ $t->kindKey() === 'rep_rep' ? __('stock.sign_to_rep') : __('stock.sign_keeper') }}</div>
                <div><span></span>{{ __('stock.transfer_created_by') }}</div>
            @else
                <div><span></span>{{ __('stock.sign_sender') }}</div>
                <div><span></span>{{ __('stock.sign_carrier') }}</div>
                @if ($isReceipt)
                    <div><span></span>{{ __('stock.sign_receiver') }}</div>
                @endif
            @endif
        </div>
    </div>

    <footer class="doc-foot">
        <span>PROMAX FOOD INDUSTRIES</span>
        <span>{{ $t->number }} · {{ $isReceipt ? __('stock.receipt_note') : __('stock.issue_note') }}</span>
    </footer>
</div>

@endsection

@section('scripts')
@include('partials._doc_style')
@endsection
