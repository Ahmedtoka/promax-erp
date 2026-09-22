@extends('layouts.system')

@section('title', __('online.accounts_title'))

@php $money = fn ($v) => number_format((float) $v, 2); @endphp

@section('content')

<div class="card" style="margin-bottom:12px">
    <h3>🧮 {{ __('online.accounts_title') }}</h3>
    <div class="dash-hint">{{ __('online.accounts_hint') }}</div>
</div>

{{-- ═══ صف ١: الفلوس — كل بوكس تحته سطر بيشرحه ═══ --}}
{{-- كل بوكس بيودّي على القايمة اللي بتعدّ رقمه: بره ← التحصيل، والباقي ← الأوردرات بالحالة (٢٢/٩) --}}
{{-- الكروت بماركب الكروت الموحّد (lbl/val/sub2) — كانت b/span/small فالرقم والعنوان بيتلخبطوا على سطر واحد.
     وكل رقم مركّب تحته معادلته بأجزاء من نفس كويري التجميع (٢٢/٩) --}}
@php $n = fn ($s) => (int) ($counts[$s]->n ?? 0); @endphp
<div class="kpis">
    <a class="kpi" href="{{ route('online.collections') }}">
        <div class="lbl">{{ __('online.k_outstanding') }}</div>
        <div class="val neg">{{ $money($sum->outstanding) }}</div>
        <div class="sub2">{{ __('online.h_outstanding') }}<br>
            <span dir="ltr">{{ $money($sum->outstanding) }} = {{ __('uid.oa_goods') }} {{ $money($sum->out_goods) }} − {{ __('uid.oa_ret') }} {{ $money($sum->out_returned) }} − {{ __('uid.oa_coll') }} {{ $money($sum->out_collected) }}</span>
            <br>{{ __('uid.oa_shipped_n', ['n' => $n('shipped')]) }}</div>
    </a>
    <a class="kpi" href="{{ route('online.orders', ['status' => 'completed']) }}">
        <div class="lbl">{{ __('online.k_collected') }}</div>
        <div class="val pos">{{ $money($sum->collected) }}</div>
        <div class="sub2">{{ __('online.h_collected') }} — {{ __('uid.oa_all_time') }}</div>
    </a>
    <a class="kpi" href="{{ route('online.orders', ['status' => 'returned']) }}">
        <div class="lbl">{{ __('online.k_returned') }}</div>
        <div class="val">{{ $money($sum->returned_amount) }}</div>
        <div class="sub2">{{ __('online.h_returned') }} — {{ __('uid.oa_all_time') }}</div>
    </a>
    <a class="kpi" href="#onByStatus">
        <div class="lbl">{{ __('online.k_shipping') }}</div>
        <div class="val">{{ $money($sum->shipping_sum) }}</div>
        <div class="sub2">{{ __('online.h_shipping') }}<br>{{ __('uid.oa_live_n', ['n' => $n('ready') + $n('shipped') + $n('completed')]) }}</div>
    </a>
    <a class="kpi" href="#onByStatus">
        <div class="lbl">{{ __('online.k_cost') }}</div>
        <div class="val">{{ $money($sum->cost_sum) }}</div>
        <div class="sub2">{{ __('online.h_cost') }}<br>{{ __('uid.oa_live_n', ['n' => $n('ready') + $n('shipped') + $n('completed')]) }}
            @if ((float) $sum->cost_sum <= 0 && (float) $sum->live_amount > 0)<br><span class="neg">⚠ {{ __('uid.oa_cost_zero') }}</span>@endif</div>
    </a>
    <a class="kpi" href="{{ route('online.orders', ['status' => 'completed']) }}">
        <div class="lbl">{{ __('online.k_margin') }}</div>
        <div class="val {{ ($sum->completed_amount - $sum->completed_cost) >= 0 ? 'pos' : 'neg' }}">{{ $money($sum->completed_amount - $sum->completed_cost) }}</div>
        <div class="sub2">{{ __('uid.oa_margin_how', ['n' => $n('completed')]) }}<br>
            <span dir="ltr">{{ $money($sum->completed_amount - $sum->completed_cost) }} = {{ $money($sum->completed_amount) }} − {{ $money($sum->completed_cost) }}</span></div>
    </a>
</div>

{{-- ═══ صف ٢: الأوردرات بالحالة ═══ --}}
<div class="card" id="onByStatus" style="margin-bottom:12px">
    <h3>📦 {{ __('online.by_status') }}</h3>
    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('common.status') }}</th>
                <th class="num">{{ __('online.orders_count') }}</th>
                <th class="num">{{ __('online.amount') }}</th>
            </tr>
            @foreach ($statuses as $s)
                <tr>
                    <td>
                        <a href="{{ route('online.orders', ['status' => $s]) }}">
                            <span class="badge {{ \App\Models\OnlineOrder::STATUSES[$s] }}">{{ __('online.status_'.$s) }}</span>
                        </a>
                    </td>
                    <td class="num"><a href="{{ route('online.orders', ['status' => $s]) }}">{{ $counts[$s]->n ?? 0 }}</a></td>
                    <td class="num">{{ $money($counts[$s]->v ?? 0) }}</td>
                </tr>
            @endforeach
        </table>
    </div>
</div>

{{-- ═══ صف ٣: البيك ابات المفتوحة — اللي لسه ماتصفتش ═══ --}}
<div class="card">
    <h3>📋 {{ __('online.open_pickups') }}</h3>
    <div class="dash-hint" style="margin-bottom:8px">{{ __('online.open_pickups_hint') }}</div>
    {{-- «من — إلى» (٩/٩/٢٠٢٦) على تاريخ البيك اب — للجدول ده بس، السامريهات فوق أرصدة حية --}}
    <form method="GET" class="searchbar" style="margin-bottom:12px" data-noprint>
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue(), 'auto' => true])
    </form>
    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('online.pickup_no') }}</th>
                <th data-nosum>{{ __('common.date') }}</th>
                <th class="num">{{ __('online.amount') }}</th>
                <th class="num">{{ __('online.collected') }}</th>
                <th class="num">{{ __('online.remaining') }}</th>
            </tr>
            @forelse ($openPickups as $p)
                @php $t = $p->totals(); @endphp
                <tr>
                    <td class="num s">
                        <a href="{{ route('online.pickup', $p) }}"
                           style="font-weight:900;color:var(--royal-blue)">{{ $p->number }}</a>
                    </td>
                    <td class="s">{{ $p->date->format('Y-m-d') }}</td>
                    <td class="num">{{ $money($t['amount']) }}</td>
                    <td class="num pos">{{ $money($t['collected']) }}</td>
                    <td class="num neg">{{ $money($t['remaining']) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:20px">
                    {{ __('online.all_settled') }}
                </td></tr>
            @endforelse
        </table>
    </div>
</div>

@endsection
