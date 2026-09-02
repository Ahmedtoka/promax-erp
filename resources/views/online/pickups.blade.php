@extends('layouts.system')

@section('title', __('online.pickups_title'))

@php $money = fn ($v) => number_format((float) $v, 2); @endphp

@section('content')

@if (session('ok'))
    <div class="alert good" style="margin-bottom:12px">{{ session('ok') }}</div>
@endif

<div class="card">
    <h3>📋 {{ __('online.pickups_title') }}</h3>
    <div class="dash-hint" style="margin-bottom:10px">{{ __('online.pickups_hint') }}</div>

    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('online.pickup_no') }}</th>
                <th>{{ __('common.date') }}</th>
                <th>{{ __('online.courier') }}</th>
                <th class="num" data-nosum>{{ __('online.orders_count') }}</th>
                <th class="num" data-nosum>{{ __('online.pieces') }}</th>
                <th class="num">{{ __('online.amount') }}</th>
                <th class="num">{{ __('online.collected') }}</th>
                <th class="num">{{ __('online.remaining') }}</th>
                <th>{{ __('common.status') }}</th>
            </tr>
            @forelse ($pickups as $p)
                @php $t = $p->totals(); @endphp
                <tr>
                    <td class="num s">
                        <a href="{{ route('online.pickup', $p) }}" style="font-weight:900;color:var(--royal-blue)">
                            {{ $p->number }}</a>
                    </td>
                    <td class="s">{{ $p->date->format('Y-m-d') }}</td>
                    <td>{{ $p->courier?->name ?: '—' }}</td>
                    <td class="num">{{ $t['orders'] }}</td>
                    <td class="num">{{ $t['pieces'] }}</td>
                    <td class="num">{{ $money($t['amount']) }}</td>
                    <td class="num pos">{{ $money($t['collected']) }}</td>
                    <td class="num {{ $t['remaining'] > 0 ? 'neg' : '' }}">{{ $money($t['remaining']) }}</td>
                    <td>
                        @if ($t['remaining'] <= 0)
                            <span class="badge b-green">{{ __('online.settled') }}</span>
                        @else
                            <span class="badge b-orange">{{ __('online.open') }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" style="text-align:center;color:var(--muted);padding:28px">
                    {{ __('online.pickups_empty') }}
                </td></tr>
            @endforelse
        </table>
    </div>

    @include('partials._pagination', ['p' => $pickups])
</div>

@endsection
