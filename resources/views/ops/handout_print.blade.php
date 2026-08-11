@extends('layouts.system')

{{--
    ورقة تسليم العهدة — للإمضاء.

    ⚠️ **البضاعة خرجت من المخزن قبل ما المندوب يدوس استلام على
    الأبلكيشن.** الورقة دي هي الإثبات الوحيد في المسافة دي، وعشان
    كده الشاشة بتروح عليها على طول بعد التسليم.
--}}

@php
    $fmt = fn ($n) => number_format((float) $n);
    $gift = (int) $o->items->sum('gift_qty');
    // ⚠️ في مرحلة الطلب (قبل تأكيد التجهيز) qty_picked لسه صفر —
    // الورقة بتتطبع بالمطلوب عشان المخزن يجهّز عليها
    $totalQty = (int) $o->items->sum(fn ($it) => (int) ($it->qty_picked ?: $it->qty_requested));
    $sale = $totalQty - $gift;
@endphp

@section('title', __('field.handout_note').' '.$o->number)

@section('actions')
    <a class="btn" href="{{ route('ops.handout') }}">← {{ __('field.handout') }}</a>
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
            <div class="doc-kind">{{ __('field.handout_note') }}</div>
            <div class="doc-no">{{ $o->number }}</div>
            <div class="doc-date">{{ ($o->issued_at ?? $o->created_at)->format('Y-m-d — h:i A') }}</div>
            <span class="badge {{ $o->status === 'handed' ? 'b-green' : 'b-orange' }}">
                {{ $o->status === 'handed' ? __('field.received') : __('field.awaiting_receipt') }}
            </span>
        </div>
    </header>

    <div class="doc-body">
        <div class="doc-parties">
            <div>
                <div class="k">{{ __('ops.rep') }}</div>
                <div class="v">{{ $o->rep?->displayName() ?? '—' }}</div>
                <div class="s">{{ $o->rep?->code }} · {{ $o->rep?->roleLabel() }}</div>
            </div>
            <div>
                <div class="k">{{ __('stock.warehouse') }}</div>
                <div class="v">{{ $o->warehouse?->displayName() ?? '—' }}</div>
                <div class="s">{{ __('field.issued_by') }}: {{ $o->picker?->displayName() ?? '—' }}</div>
            </div>
            <div>
                <div class="k">{{ __('field.carrier_note') }}</div>
                <div class="v">{{ $o->carrier_note ?: '—' }}</div>
                <div class="s">{{ __('field.issued_at') }}: {{ $o->issued_at?->format('Y-m-d h:i A') ?? '—' }}</div>
            </div>
        </div>

        <div class="tablewrap">
            <table class="doc-table">
                <thead>
                <tr>
                    <th>#</th>
                    <th>{{ __('stock.item') }}</th>
                    <th>{{ __('stock.batch_no') }}</th>
                    <th>{{ __('stock.expires_on') }}</th>
                    <th class="num">{{ __('field.qty_sale') }}</th>
                    <th class="num">{{ __('field.qty_gift') }}</th>
                    <th class="num">{{ __('common.total') }}</th>
                </tr>
                </thead>
                <tbody>

                @foreach ($o->items as $i => $it)
                    @php
                        $g = (int) ($it->gift_qty ?? 0);
                        // المطلوب لو التجهيز لسه ماتأكدش
                        $picked = (int) (($it->qty_picked ?? 0) ?: $it->qty_requested);
                    @endphp
                    <tr>
                        <td class="num">{{ $i + 1 }}</td>
                        <td>
                            <b>{{ $it->product?->displayName() ?? '—' }}</b>
                            <div class="s">{{ $it->product?->code }} · {{ $it->product?->unitLabel() }}</div>
                        </td>
                        <td class="num">{{ $it->batch?->batch_no ?? '—' }}</td>
                        <td class="num">{{ $it->batch?->expires_on?->format('Y-m-d') ?? '—' }}</td>
                        <td class="num">{{ $fmt(max($picked - $g, 0)) }}</td>
                        <td class="num">{{ $g > 0 ? $fmt($g) : '—' }}</td>
                        <td class="num"><b>{{ $fmt($picked) }}</b></td>
                    </tr>
                @endforeach

                </tbody>
                <tfoot>
                <tr>
                    <td colspan="4"><b>{{ __('common.total') }}</b></td>
                    <td class="num"><b>{{ $fmt($sale) }}</b></td>
                    <td class="num"><b>{{ $gift > 0 ? $fmt($gift) : '—' }}</b></td>
                    <td class="num"><b>{{ $fmt($sale + $gift) }}</b></td>
                </tr>
                </tfoot>
            </table>
        </div>

        {{-- ⚠️ **الهدايا لازم تتكتب بالنص على الورقة.** الرقم في عمود
             لوحده حد بيعدّي عليه؛ الجملة الصريحة بتخلّي اللي بيمضي
             يعرف إنه مسؤول عن عينات مجانية لازم يقول اداها لمين. --}}
        @if ($gift > 0)
            <div class="doc-note" style="border-color:var(--purple);color:var(--purple-500)">
                <b>🎁 {{ __('field.gift_notice', ['qty' => $fmt($gift)]) }}</b>
            </div>
        @endif

        <div class="doc-note">{{ __('field.handout_pledge') }}</div>

        <div class="doc-sign three">
            <div><span></span>{{ __('field.sign_issuer') }}</div>
            <div><span></span>{{ __('field.sign_rep') }}</div>
        </div>
    </div>

    <footer class="doc-foot">
        <span>PROMAX FOOD INDUSTRIES</span>
        <span>{{ $o->number }} · {{ __('field.handout_note') }}</span>
    </footer>
</div>

@endsection

@section('scripts')
@include('partials._doc_style')

{{-- ⚠️ **الطباعة أوتوماتيك بعد الإنشاء بس** (قرار المالك 2026-08-04):
     جاي من «إرسال للتجهيز» → بريفيو الطباعة بيفتح لوحده، وبعد الطباعة
     أو الإلغاء بيرجع لصفحة تسليم العهدة. إعادة الطباعة من الهيستوري
     بتفتح الصفحة عادي من غير أوتوماتيك — عشان مايتطبعش ورق بالغلط. --}}
@if (session('ok'))
<script>
window.addEventListener('load', () => setTimeout(() => window.print(), 400));
// onafterprint بيشتغل بعد «طباعة» وبعد «إلغاء» الاتنين
window.onafterprint = () => { location.href = @json(route('ops.handout')); };
</script>
@endif
@endsection
