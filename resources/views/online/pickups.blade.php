@extends('layouts.system')

@section('title', __('online.pickups_title'))

@php $money = fn ($v) => number_format((float) $v, 2); @endphp

@section('content')

@if (session('ok'))
    <div class="alert good" style="margin-bottom:12px">{{ session('ok') }}</div>
@endif

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:8px">
        <h3 style="margin:0">📋 {{ __('online.pickups_title') }}</h3>
        {{-- بحث شامل: رقم أوردر / اسم عميل / موبايل → البيك ابات اللي فيها --}}
        <form method="GET" class="searchbar" style="margin:0;align-items:flex-end">
            <label class="fl wide"><span>{{ __('ui.l_search') }}</span>
                <input type="text" name="search" value="{{ $search }}" placeholder="{{ __('online.pu_search_ph') }}"></label>
            {{-- «من — إلى» (٩/٩/٢٠٢٦) على تاريخ شيت البيك اب --}}
            @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue()])
            <button class="btn gold" type="submit">{{ __('common.search') }}</button>
            @if ($search !== '' || ! $range->isOpen())
                <a class="btn" href="{{ route('online.pickups') }}">{{ __('common.clear') }}</a>
            @endif
            {{-- القايمة صفحات — ده بينزّل كل النتيجة المفلترة (٢٢/٩) --}}
            <a class="btn sm green" href="{{ request()->fullUrlWithQuery(['export' => 1, 'page' => null]) }}">⬇ {{ __('ui.export_all') }}</a>
        </form>
    </div>
    <div class="dash-hint" style="margin-bottom:10px">{{ __('online.pickups_hint2') }}</div>

    {{-- هيدر مثبت — نفس نمط مركز التقارير --}}
    <div class="tablewrap frz" style="max-height:68vh;overflow:auto">
        <table>
            <tr>
                <th>{{ __('online.pickup_no') }}</th>
                <th data-nosum>{{ __('common.date') }}</th>
                <th>{{ __('online.courier') }}</th>
                <th>{{ __('online.by_user') }}</th>
                <th class="num" data-nosum>{{ __('online.orders_count') }}</th>
                <th class="num" data-nosum>{{ __('online.pieces') }}</th>
                <th class="num">{{ __('online.goods_amount') }}</th>
                <th class="num">{{ __('online.shipping') }}</th>
                <th class="num">{{ __('common.total') }}</th>
                <th class="num">{{ __('online.collected') }}</th>
                <th class="num">{{ __('online.remaining') }}</th>
                <th>{{ __('common.status') }}</th>
                <th></th>
            </tr>
            @forelse ($pickups as $p)
                @php $t = $p->totals(); @endphp
                <tr class="clickable" onclick="location.href='{{ route('online.pickup', $p) }}'">
                    <td class="num s">
                        <a href="{{ route('online.pickup', $p) }}" style="font-weight:900;color:var(--royal-blue)">{{ $p->number }}</a>
                    </td>
                    <td class="s">{{ $p->date->format('Y-m-d') }}</td>
                    <td>{{ $p->courier?->name ?: '—' }}</td>
                    <td class="s">{{ $p->creator?->displayName() ?: '—' }}</td>
                    <td class="num">{{ $t['orders'] }}</td>
                    <td class="num">{{ $t['pieces'] }}</td>
                    <td class="num">{{ $money($t['goods']) }}</td>
                    <td class="num">{{ $money($t['ship']) }}</td>
                    <td class="num"><b>{{ $money($t['amount']) }}</b></td>
                    <td class="num pos">{{ $money($t['collected']) }}</td>
                    <td class="num {{ $t['remaining'] > 0 ? 'neg' : '' }}">{{ $money($t['remaining']) }}</td>
                    <td>
                        @if ($t['remaining'] <= 0)
                            <span class="badge b-green">{{ __('online.settled') }}</span>
                        @else
                            <span class="badge b-orange">{{ __('online.open') }}</span>
                        @endif
                    </td>
                    <td class="num" onclick="event.stopPropagation()">
                        <a class="btn sm" href="{{ route('online.pickup.excel', $p) }}"
                           title="{{ __('online.excel_btn') }}">📊</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="13" style="text-align:center;color:var(--muted);padding:28px">
                    {{ $search !== '' ? __('online.pu_search_none') : __('online.pickups_empty') }}
                </td></tr>
            @endforelse
            {{-- إجمالي كل النتيجة المفلترة من السيرفر — مش الصفحة (٢٢/٩) --}}
            @if ($pickups->total() > 0)
                <tfoot><tr>
                    <td colspan="4"><b>{{ __('common.total') }}</b> <span class="s" style="color:var(--muted)">({{ __('ui.rows_n', ['n' => $pickups->total()]) }})</span></td>
                    <td class="num"><b>{{ number_format($sum['orders']) }}</b></td>
                    <td class="num"><b>{{ number_format($sum['pieces']) }}</b></td>
                    <td class="num"><b>{{ $money($sum['goods']) }}</b></td>
                    <td class="num"><b>{{ $money($sum['ship']) }}</b></td>
                    <td class="num"><b>{{ $money($sum['amount']) }}</b></td>
                    <td class="num"><b>{{ $money($sum['collected']) }}</b></td>
                    <td class="num"><b>{{ $money($sum['remaining']) }}</b></td>
                    <td colspan="2"></td>
                </tr></tfoot>
            @endif
        </table>
    </div>

    @include('partials._pagination', ['p' => $pickups])
</div>

@endsection

@section('scripts')
<style>
    /* الهيدر المثبت */
    .frz th{position:sticky;top:0;z-index:2}
</style>
@endsection
