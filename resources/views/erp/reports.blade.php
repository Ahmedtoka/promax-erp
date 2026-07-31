@extends('layouts.system')

@section('title', __('report.reports'))

@php
    $fmt = fn ($n) => number_format((float) $n);
    $agingTotal = array_sum($aging) ?: 1;
    $tabs = [
        'aging' => '⏳ '.__('report.aging'),
        'returns' => '↩️ '.__('report.returns'),
        'rebates' => '🏷️ '.__('report.discounts_settlements'),
        'ck' => '🏪 '.__('report.network_of', ['name' => 'Circle K']),
        'risk' => '⚠️ '.__('report.risk'),
        'credit' => '🔵 '.__('report.credit_balances'),
    ];
    $agingLabels = [
        'a30' => __('report.days_0_30'),
        'a60' => __('report.days_31_60'),
        'a90' => __('report.days_61_90'),
        'a180' => __('report.days_91_180'),
        'a180p' => __('report.days_180_plus'),
    ];
@endphp

@section('content')

<div class="card" style="padding:10px 12px">
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        @foreach ($tabs as $k => $lbl)
            <a class="btn {{ $tab === $k ? 'gold' : '' }}" href="{{ route('erp.reports', ['tab' => $k]) }}">{{ $lbl }}</a>
        @endforeach
    </div>
</div>

@if ($tab === 'aging')
    <div class="kpis">
        @foreach ($agingLabels as $k => $lbl)
            <div class="kpi">
                <div class="lbl">{{ $lbl }}</div>
                <div class="val {{ in_array($k, ['a180','a180p']) ? 'neg' : ($k === 'a30' ? 'pos' : 'mid') }}">{{ $fmt($aging[$k]) }}</div>
                <div class="sub2">{{ number_format($aging[$k] / $agingTotal * 100, 1) }}% {{ __('report.of_outstanding') }}</div>
            </div>
        @endforeach
    </div>
    <div class="card">
        <h3>{{ __('report.top_debtors', ['count' => $topDebt->count()]) }}</h3>
        <div class="tablewrap">
            <table>
                <tr><th>{{ __('client.client') }}</th><th>{{ __('client.category') }}</th><th>{{ __('client.balance') }}</th><th>{{ __('client.overdue') }}</th><th>≤30</th><th>31-60</th><th>61-90</th><th>91-180</th><th>&gt;180</th></tr>
                @foreach ($topDebt as $c)
                    @php $ag = $c->aging(); $od = $c->overdue(); @endphp
                    <tr class="clickable" onclick="location.href='{{ route('erp.clients.show', $c) }}'">
                        <td><b>{{ $c->displayName() }}</b></td>
                        <td><span class="badge {{ $c->categoryClass() }}">{{ $c->categoryLabel() }}</span></td>
                        <td class="num neg">{{ $fmt($c->balance) }}</td>
                        {{-- ⚠️ الرصيد مش المتأخر. العميل بشروط 60 يوم ممكن
                             يبقى عليه مليون ومتأخره صفر — والمتابعة لازم
                             تشوف الرقمين عشان تلاحق الصح. --}}
                        <td class="num">
                            @if (! $od['has_terms'])
                                <span style="color:var(--muted)">—</span>
                            @elseif ($od['amount'] > 0)
                                <b class="neg">{{ $fmt($od['amount']) }}</b>
                                <br><span style="font-size:10px;color:var(--muted)">{{ __('client.overdue_by_days', ['days' => $od['days']]) }}</span>
                            @else
                                <span class="pos">0</span>
                            @endif
                        </td>
                        <td class="num">{{ $fmt($ag['a30']) }}</td>
                        <td class="num">{{ $fmt($ag['a60']) }}</td>
                        <td class="num">{{ $fmt($ag['a90']) }}</td>
                        <td class="num mid">{{ $fmt($ag['a180']) }}</td>
                        <td class="num neg">{{ $fmt($ag['a180p']) }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    </div>

@elseif ($tab === 'returns')
    <div class="card">
        <h3>↩️ {{ __('report.returns') }} <span class="side">{{ $returns->count() }} {{ __('client.client_count') }}</span></h3>
        <div class="tablewrap">
            <table>
                <tr><th>{{ __('client.client') }}</th><th>{{ __('client.returns') }}</th><th>{{ __('client.purchases') }}</th><th>{{ __('client.returns') }} %</th><th>{{ __('client.balance') }}</th></tr>
                @foreach ($returns as $c)
                    @php $pct = $c->purchases > 0 ? $c->returns / $c->purchases * 100 : 0; @endphp
                    <tr class="clickable" onclick="location.href='{{ route('erp.clients.show', $c) }}'">
                        <td><b>{{ $c->displayName() }}</b></td>
                        <td class="num mid">{{ $fmt($c->returns) }}</td>
                        <td class="num">{{ $fmt($c->purchases) }}</td>
                        <td class="num {{ $pct > 5 ? 'neg' : '' }}">{{ number_format($pct, 2) }}%</td>
                        <td class="num {{ $c->balance > 0 ? 'neg' : 'pos' }}">{{ $fmt($c->balance) }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    </div>

@elseif ($tab === 'rebates')
    <div class="card">
        <h3>🏷️ {{ __('report.trade_discounts_settlements') }} <span class="side">{{ $rebates->count() }} {{ __('client.client_count') }}</span></h3>
        <div class="tablewrap">
            <table>
                <tr><th>{{ __('client.client') }}</th><th>{{ __('client.discounts') }}</th><th>{{ __('client.settlements') }}</th><th>{{ __('common.total') }}</th><th>{{ __('client.purchases') }}</th><th>% {{ __('report.of_which') }}</th></tr>
                @foreach ($rebates as $c)
                    @php $tot = $c->rebates + $c->settlements; @endphp
                    <tr class="clickable" onclick="location.href='{{ route('erp.clients.show', $c) }}'">
                        <td><b>{{ $c->displayName() }}</b></td>
                        <td class="num">{{ $fmt($c->rebates) }}</td>
                        <td class="num">{{ $fmt($c->settlements) }}</td>
                        <td class="num mid">{{ $fmt($tot) }}</td>
                        <td class="num">{{ $fmt($c->purchases) }}</td>
                        <td class="num">{{ $c->purchases > 0 ? number_format($tot / $c->purchases * 100, 2) : '0.00' }}%</td>
                    </tr>
                @endforeach
            </table>
        </div>
    </div>

@elseif ($tab === 'ck')
    @php
        $ckPurch = $circleK->sum('purchases'); $ckColl = $circleK->sum('collections'); $ckBal = $circleK->sum('balance');
    @endphp
    <div class="kpis">
        <div class="kpi"><div class="lbl">{{ __('client.branches') }} — Circle K</div><div class="val">{{ $circleK->count() }}</div><div class="sub2">{{ __('report.one_umbrella') }}</div></div>
        <div class="kpi"><div class="lbl">{{ __('report.network_purchases') }}</div><div class="val" style="color:var(--primary)">{{ $fmt($ckPurch) }}</div></div>
        <div class="kpi"><div class="lbl">{{ __('client.collected') }}</div><div class="val pos">{{ $fmt($ckColl) }}</div><div class="sub2">{{ number_format($ckColl / max($ckPurch, 1) * 100, 1) }}%</div></div>
        <div class="kpi"><div class="lbl">{{ __('report.network_balance') }}</div><div class="val {{ $ckBal > 0 ? 'neg' : 'pos' }}">{{ $fmt($ckBal) }}</div></div>
    </div>
    <div class="card">
        <h3>🏪 {{ __('client.branches') }}</h3>
        <div class="tablewrap">
            <table>
                <tr><th>{{ __('client.branch') }}</th><th>{{ __('client.category') }}</th><th>{{ __('client.zone') }}</th><th>{{ __('client.purchases') }}</th><th>{{ __('client.collected') }}</th><th>{{ __('client.balance') }}</th><th>{{ __('report.collection_rate') }} %</th></tr>
                @foreach ($circleK as $c)
                    <tr class="clickable" onclick="location.href='{{ route('erp.clients.show', $c) }}'">
                        <td><b>{{ $c->displayName() }}</b></td>
                        <td><span class="badge {{ $c->categoryClass() }}">{{ $c->categoryLabel() }}</span></td>
                        <td style="color:var(--muted)">{{ $c->zone?->displayName() ?? '—' }}</td>
                        <td class="num">{{ $fmt($c->purchases) }}</td>
                        <td class="num pos">{{ $fmt($c->collections) }}</td>
                        <td class="num {{ $c->balance > 0 ? 'neg' : 'pos' }}">{{ $fmt($c->balance) }}</td>
                        <td class="num">{{ number_format($c->collectionRate() * 100, 1) }}%</td>
                    </tr>
                @endforeach
            </table>
        </div>
    </div>

@elseif ($tab === 'risk')
    <div class="card">
        <h3>⚠️ {{ __('report.high_risk') }} <span class="side">{{ __('client.balance') }} &gt; 50 {{ __('report.thousand') }} • {{ __('report.collection_rate') }} &lt; 50%</span></h3>
        <div class="tablewrap">
            <table>
                <tr><th>{{ __('client.client') }}</th><th>{{ __('client.category') }}</th><th>{{ __('client.balance') }}</th><th>{{ __('report.collection_rate') }} %</th><th>{{ __('client.last_payment') }}</th><th>{{ __('report.contract') }}</th></tr>
                @foreach ($risk as $c)
                    <tr class="clickable" onclick="location.href='{{ route('erp.clients.show', $c) }}'">
                        <td><b>{{ $c->displayName() }}</b></td>
                        <td><span class="badge {{ $c->categoryClass() }}">{{ $c->categoryLabel() }}</span></td>
                        <td class="num neg">{{ $fmt($c->balance) }}</td>
                        <td class="num">{{ number_format($c->collectionRate() * 100, 1) }}%</td>
                        <td class="num">{{ $c->last_payment_at?->format('Y-m-d') ?? '—' }}</td>
                        <td>{!! $c->hasContract()
                            ? '<span class="badge b-green">'.e(__('report.yes')).'</span>'
                            : '<span class="badge b-red">'.e(__('report.no')).'</span>' !!}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    </div>

@else
    <div class="card">
        <h3>🔵 {{ __('report.clients_in_credit') }} <span class="side">{{ $credit->count() }} {{ __('client.client_count') }}</span></h3>
        <div class="tablewrap">
            <table>
                <tr><th>{{ __('client.client') }}</th><th>{{ __('report.credit_balance') }}</th><th>{{ __('client.purchases') }}</th><th>{{ __('client.collected') }}</th><th>{{ __('client.last_activity') }}</th></tr>
                @foreach ($credit as $c)
                    <tr class="clickable" onclick="location.href='{{ route('erp.clients.show', $c) }}'">
                        <td><b>{{ $c->displayName() }}</b></td>
                        <td class="num pos">{{ $fmt(abs($c->balance)) }}</td>
                        <td class="num">{{ $fmt($c->purchases) }}</td>
                        <td class="num">{{ $fmt($c->collections) }}</td>
                        <td class="num">{{ $c->last_activity_at?->format('Y-m-d') ?? '—' }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    </div>
@endif

@endsection
