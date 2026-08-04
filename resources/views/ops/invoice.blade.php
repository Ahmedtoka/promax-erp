@extends('layouts.system')

@section('title', __('ops.invoice').' '.$inv->number)

@php
    $fmt = fn ($n) => number_format((float) $n, 2);
    $isRtl = app()->getLocale() === 'ar';
@endphp

@section('actions')
    <a class="btn" href="{{ route('ops.invoices') }}">← {{ __('ops.all_invoices') }}</a>
    <button class="btn gold" onclick="window.print()">🖨️ {{ __('ops.print') }}</button>
@endsection

@section('content')

{{-- ═══ المستند: بالهوية الرسمية، ومصمم للطباعة على A4 ═══ --}}
<div class="doc has-bolt">
    {{-- العلامة المائية — الصاعقة، عنصر البراند الأساسي --}}
    <img class="bolt-mark lg" src="{{ asset('brand/bolt.svg') }}" alt="">

    {{-- الترويسة --}}
    <header class="doc-head">
        <div class="doc-brand">
            <img src="{{ asset('img/promax-logo.png') }}" alt="PROMAX" class="doc-logo">
            <div class="doc-corp">{{ __('ops.corp_name') }}</div>
            {{-- الرقم الضريبي للبائع — بند إجباري في الفاتورة الضريبية --}}
            @if ($inv->hasTax() && $companyTaxId)
                <div class="doc-corp">{{ __('tax.tax_id') }}: {{ $companyTaxId }}</div>
            @endif
        </div>
        <div class="doc-id">
            <div class="doc-kind">{{ $inv->hasTax() ? __('tax.tax_invoice') : __('ops.sales_invoice') }}</div>
            <div class="doc-no">{{ $inv->number }}</div>
            <div class="doc-date">{{ $inv->created_at->format('Y-m-d — H:i') }}</div>
            <span class="badge {{ $inv->payment === 'cash' ? 'b-green' : 'b-orange' }}">{{ $inv->paymentLabel() }}</span>
        </div>
    </header>

    <div class="doc-body">
        {{-- بيانات الأطراف --}}
        <div class="doc-parties">
            <div>
                <div class="k">{{ __('client.client') }}</div>
                <div class="v">{{ $inv->client->displayName() }}</div>
                <div class="s">{{ $inv->client->address }}</div>
                @if ($inv->client->phone)<div class="s">{{ $inv->client->phone }}</div>@endif
                {{-- الرقم الضريبي للمستلم — إجباري لو الفاتورة ضريبية --}}
                @if ($inv->hasTax() && $inv->client->tax_id)
                    <div class="s">{{ __('tax.tax_id') }}: {{ $inv->client->tax_id }}</div>
                @endif
            </div>
            <div>
                <div class="k">{{ __('ops.rep') }}</div>
                <div class="v">{{ $inv->user->displayName() }}</div>
                <div class="s">{{ $inv->user->code }}</div>
            </div>
            <div>
                <div class="k">{{ __('ops.visit') }}</div>
                <div class="v">{{ $inv->visit?->checked_in_at?->format('H:i') ?? '—' }}</div>
                <div class="s">
                    {{ $inv->visit?->minutes() ? __('ops.minutes', ['count' => $inv->visit->minutes()]) : __('ops.in_progress') }}
                </div>
            </div>
        </div>

        {{-- البنود --}}
        <div class="tablewrap">
            <table class="doc-table">
                <tr>
                    <th style="width:34px">#</th>
                    <th>{{ __('stock.item') }}</th>
                    <th>{{ __('stock.batch_no') }}</th>
                    <th>{{ __('stock.unit') }}</th>
                    <th>{{ __('common.qty') }}</th>
                    <th>{{ __('common.price') }}</th>
                    <th>{{ __('common.total') }}</th>
                    @if ($inv->hasTax())
                        <th>{{ __('tax.tax') }}</th>
                    @endif
                </tr>
                @foreach ($inv->items as $i => $it)
                    <tr>
                        <td class="num">{{ $i + 1 }}</td>
                        <td>
                            <b>{{ $it->product->displayName() }}</b>
                            <br><span class="s">{{ $it->product->code }}</span>
                        </td>
                        <td class="num s">
                            {{ $it->batchLabel() }}
                            @if ($it->expiryLabel())<br><span class="s">{{ $it->expiryLabel() }}</span>@endif
                        </td>
                        <td class="s">{{ $it->product->unitLabel() }}</td>
                        <td class="num">{{ $it->qty }}</td>
                        <td class="num">{{ $fmt($it->price) }}</td>
                        <td class="num"><b>{{ $fmt($it->total) }}</b></td>
                        @if ($inv->hasTax())
                            <td class="num s">
                                @if ((float) $it->tax > 0)
                                    {{ $fmt($it->tax) }}
                                    <br><span class="s">{{ round($it->tax_rate * 100, 2) }}%</span>
                                @else
                                    {{ __('tax.exempt') }}
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
            </table>
        </div>

        {{-- الإجماليات --}}
        <div class="doc-totals">
            <div class="row">
                <span>{{ __('common.subtotal') }}</span><b class="num">{{ $fmt($inv->subtotal) }}</b>
            </div>
            @if ($inv->discount > 0)
                <div class="row disc">
                    <span>{{ $inv->discountSourceLabel() }} {{ round($inv->discount_pct * 100) }}%</span>
                    <b class="num">− {{ $fmt($inv->discount) }}</b>
                </div>
            @endif

            {{-- ⚠️ الفاتورة الضريبية لازم توري التلاتة منفصلين: الصافي
                 والضريبة والمستحق. دمجهم في رقم واحد بيخلّي الفاتورة
                 غير مقبولة قانوناً. --}}
            @if ($inv->hasTax())
                <div class="row">
                    <span>{{ __('tax.net_before_tax') }}</span>
                    <b class="num">{{ $fmt($inv->total) }}</b>
                </div>
                <div class="row tax">
                    <span>{{ __('tax.vat') }} {{ $taxRateLabel }}</span>
                    <b class="num">+ {{ $fmt($inv->tax_total) }}</b>
                </div>
            @endif

            <div class="row grand">
                <span>{{ $inv->hasTax() ? __('tax.total_due') : __('common.total') }}</span>
                <span class="num">{{ $fmt($inv->payable()) }} {{ __('common.currency') }}</span>
            </div>
        </div>

        {{-- التوقيعات — بتبان في الطباعة بس --}}
        <div class="doc-sign">
            <div><span></span>{{ __('ops.rep') }}</div>
            <div><span></span>{{ __('client.client') }}</div>
        </div>
    </div>

    <footer class="doc-foot">
        <span>PROMAX FOOD INDUSTRIES</span>
        <span>{{ $inv->number }}</span>
    </footer>
</div>

@endsection

@section('scripts')
{{-- ⚠️ ستايل المستند اتنقل لـpartial مشترك — كان مكتوب هنا بس،
     وأول ورقة تانية (إذن صرف، محضر استلام) كانت هتنسخه 80 سطر. --}}
@include('partials._doc_style')
@endsection
