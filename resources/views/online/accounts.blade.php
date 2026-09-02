@extends('layouts.system')

@section('title', __('online.accounts_title'))

@php $money = fn ($v) => number_format((float) $v, 2); @endphp

@section('content')

<div class="card" style="margin-bottom:12px">
    <h3>🧮 {{ __('online.accounts_title') }}</h3>
    <div class="dash-hint">{{ __('online.accounts_hint') }}</div>
</div>

{{-- ═══ صف ١: الفلوس — كل بوكس تحته سطر بيشرحه ═══ --}}
<div class="kpis">
    <div class="kpi">
        <b class="num neg">{{ $money($sum->outstanding) }}</b>
        <span>{{ __('online.k_outstanding') }}</span>
        <small style="font-size:10px;color:var(--muted)">{{ __('online.h_outstanding') }}</small>
    </div>
    <div class="kpi">
        <b class="num pos">{{ $money($sum->collected) }}</b>
        <span>{{ __('online.k_collected') }}</span>
        <small style="font-size:10px;color:var(--muted)">{{ __('online.h_collected') }}</small>
    </div>
    <div class="kpi">
        <b class="num">{{ $money($sum->returned_amount) }}</b>
        <span>{{ __('online.k_returned') }}</span>
        <small style="font-size:10px;color:var(--muted)">{{ __('online.h_returned') }}</small>
    </div>
    <div class="kpi">
        <b class="num">{{ $money($sum->shipping_sum) }}</b>
        <span>{{ __('online.k_shipping') }}</span>
        <small style="font-size:10px;color:var(--muted)">{{ __('online.h_shipping') }}</small>
    </div>
    <div class="kpi">
        <b class="num">{{ $money($sum->cost_sum) }}</b>
        <span>{{ __('online.k_cost') }}</span>
        <small style="font-size:10px;color:var(--muted)">{{ __('online.h_cost') }}</small>
    </div>
    <div class="kpi">
        <b class="num {{ ($sum->completed_amount - $sum->completed_cost) >= 0 ? 'pos' : 'neg' }}">
            {{ $money($sum->completed_amount - $sum->completed_cost) }}</b>
        <span>{{ __('online.k_margin') }}</span>
        <small style="font-size:10px;color:var(--muted)">{{ __('online.h_margin') }}</small>
    </div>
</div>

{{-- ═══ صف ٢: الأوردرات بالحالة ═══ --}}
<div class="card" style="margin-bottom:12px">
    <h3>📦 {{ __('online.by_status') }}</h3>
    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('common.status') }}</th>
                <th class="num" data-nosum>{{ __('online.orders_count') }}</th>
                <th class="num">{{ __('online.amount') }}</th>
            </tr>
            @foreach ($statuses as $s)
                <tr>
                    <td>
                        <a href="{{ route('online.orders', ['status' => $s]) }}">
                            <span class="badge {{ \App\Models\OnlineOrder::STATUSES[$s] }}">{{ __('online.status_'.$s) }}</span>
                        </a>
                    </td>
                    <td class="num">{{ $counts[$s]->n ?? 0 }}</td>
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
    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('online.pickup_no') }}</th>
                <th>{{ __('common.date') }}</th>
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
