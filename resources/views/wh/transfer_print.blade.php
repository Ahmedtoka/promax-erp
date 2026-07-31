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
@endphp

@section('title', ($isReceipt ? __('stock.receipt_note') : __('stock.issue_note')).' '.$t->number)

@section('actions')
    <a class="btn" href="{{ route('wh.transfers') }}">← {{ __('stock.transfers') }}</a>
    @if ($isReceipt)
        <a class="btn" href="{{ route('wh.transfers.print', $t) }}">{{ __('stock.issue_note') }}</a>
    @elseif ($t->status === 'received')
        <a class="btn" href="{{ route('wh.transfers.receipt_print', $t) }}">{{ __('stock.receipt_note') }}</a>
    @endif
    <button class="btn gold" onclick="window.print()">🖨️ {{ __('ops.print') }}</button>
@endsection

@section('content')

<div class="doc has-bolt">
    <img class="bolt-mark lg" src="{{ asset('brand/bolt.svg') }}" alt="">

    <header class="doc-head">
        <div class="doc-brand">
            <img src="{{ asset('brand/logo/logo-h-white.svg') }}" alt="PROMAX" class="doc-logo">
            <div class="doc-corp">{{ __('ops.corp_name') }}</div>
        </div>
        <div class="doc-id">
            <div class="doc-kind">{{ $isReceipt ? __('stock.receipt_note') : __('stock.issue_note') }}</div>
            <div class="doc-no">{{ $t->number }}</div>
            <div class="doc-date">
                {{ $isReceipt
                    ? ($t->received_on?->format('Y-m-d') ?? '—')
                    : ($t->sent_on?->format('Y-m-d') ?? '—') }}
            </div>
            <span class="badge {{ $t->statusClass() }}">{{ $t->statusLabel() }}</span>
        </div>
    </header>

    <div class="doc-body">
        <div class="doc-parties">
            <div>
                <div class="k">{{ __('stock.from_warehouse') }}</div>
                <div class="v">{{ $t->fromWarehouse?->displayName() ?? '—' }}</div>
                <div class="s">{{ __('stock.sent_by') }}: {{ $t->sender?->displayName() ?? '—' }}</div>
            </div>
            <div>
                <div class="k">{{ __('stock.to_warehouse') }}</div>
                <div class="v">{{ $t->toWarehouse?->displayName() ?? '—' }}</div>
                @if ($isReceipt)
                    <div class="s">{{ __('stock.received_by') }}: {{ $t->receiver?->displayName() ?? '—' }}</div>
                @endif
            </div>
            <div>
                <div class="k">{{ __('stock.carrier') }}</div>
                <div class="v">{{ $t->carrier_name ?: '—' }}</div>
                <div class="s">{{ __('stock.sent_on') }}: {{ $t->sent_on?->format('Y-m-d') ?? '—' }}</div>
            </div>
        </div>

        <div class="tablewrap">
            <table class="doc-table">
                <tr>
                    <th>#</th>
                    <th>{{ __('stock.item') }}</th>
                    <th>{{ __('stock.batch_no') }}</th>
                    <th>{{ __('stock.produced_on') }}</th>
                    <th>{{ __('stock.expires_on') }}</th>
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
                    <td colspan="5"><b>{{ __('common.total') }}</b></td>
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

        <div class="doc-note">{{ $isReceipt ? __('stock.receipt_pledge') : __('stock.issue_pledge') }}</div>

        <div class="doc-sign three">
            <div><span></span>{{ __('stock.sign_sender') }}</div>
            <div><span></span>{{ __('stock.sign_carrier') }}</div>
            @if ($isReceipt)
                <div><span></span>{{ __('stock.sign_receiver') }}</div>
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
