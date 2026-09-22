@extends('layouts.system')

@section('title', __('online.orders_title'))

@php $money = fn ($v) => number_format((float) $v, 2); @endphp

@section('content')

@if (session('ok'))
    <div class="alert good" style="margin-bottom:12px">{{ session('ok') }}</div>
@endif

<div class="card" style="padding:10px 12px;margin-bottom:12px">
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        {{-- شيبس الحالة بتحافظ على «من/إلى» (٩/٩/٢٠٢٦) — العدادات بتعدّ جوه الفترة --}}
        @php $keep = $range->query(); @endphp
        <a class="btn {{ ! ($filters['status'] ?? null) ? 'gold' : '' }}"
           href="{{ route('online.orders', $keep) }}">{{ __('common.all') }}</a>
        @foreach (array_keys(\App\Models\OnlineOrder::STATUSES) as $k)
            <a class="btn {{ ($filters['status'] ?? '') === $k ? 'gold' : '' }}"
               href="{{ route('online.orders', ['status' => $k] + $keep) }}">
                {{ __('online.status_'.$k) }} <b>({{ $counts[$k] ?? 0 }})</b></a>
        @endforeach

        <form method="GET" class="searchbar" style="margin:0;margin-inline-start:auto;align-items:flex-end">
            @if ($filters['status'] ?? null)
                <input type="hidden" name="status" value="{{ $filters['status'] }}">
            @endif
            <label class="fl"><span>{{ __('ui.l_search') }}</span>
                <input type="text" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="🔎 {{ __('common.search') }}"></label>
            {{-- «من — إلى» على تاريخ الأوردر في شوبيفاي --}}
            @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue(), 'auto' => true])
            <button class="btn gold" type="submit">{{ __('common.search') }}</button>
            {{-- القايمة صفحات — ده بينزّل كل النتيجة المفلترة (٢٢/٩) --}}
            <a class="btn sm green" href="{{ request()->fullUrlWithQuery(['export' => 1, 'page' => null]) }}">⬇ {{ __('ui.export_all') }}</a>
        </form>
    </div>
</div>

<div class="card">
    <h3>🛒 {{ __('online.orders_title') }}</h3>
    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('online.shopify_no') }}</th>
                <th>{{ __('common.name') }}</th>
                <th>{{ __('common.phone') }}</th>
                <th>{{ __('online.area') }}</th>
                <th class="num" data-nosum>{{ __('online.pieces') }}</th>
                <th class="num">{{ __('common.total') }}</th>
                <th class="num">{{ __('online.collected') }}</th>
                <th>{{ __('online.pickup_no') }}</th>
                <th>{{ __('common.status') }}</th>
                <th data-nosum>{{ __('common.date') }}</th>
            </tr>
            @forelse ($orders as $o)
                <tr>
                    {{-- رقم الأوردر بيفتح فاتورته (٢٢/٩) --}}
                    <td class="num s"><a href="{{ route('online.invoice', $o) }}"><b>#{{ $o->number }}</b></a></td>
                    <td>{{ $o->customer_name ?: '—' }}
                        @if ($o->cancel_reason)
                            <br><span style="font-size:10.5px;color:var(--muted)">✖ {{ $o->cancel_reason }}</span>
                        @endif
                    </td>
                    <td class="num s" dir="ltr">{{ $o->phone ?: '—' }}</td>
                    <td class="s">{{ $o->area ?: '—' }}</td>
                    <td class="num">{{ $o->items_count }}</td>
                    <td class="num"><b>{{ $money($o->total) }}</b></td>
                    <td class="num pos">{{ $money($o->collected_total) }}</td>
                    <td class="num s">
                        @if ($o->pickup)
                            <a href="{{ route('online.pickup', $o->pickup) }}"
                               style="font-weight:900;color:var(--royal-blue)">{{ $o->pickup->number }}</a>
                        @else
                            —
                        @endif
                    </td>
                    <td>
                        <span class="badge {{ $o->statusClass() }}">{{ $o->statusLabel() }}</span>
                        @if ($o->status === 'postponed' && $o->postponed_to)
                            <br><span style="font-size:10.5px;color:var(--muted)">📅 {{ $o->postponed_to->format('Y-m-d') }}</span>
                        @endif
                    </td>
                    <td class="s">{{ $o->ordered_at?->format('Y-m-d') ?: '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="10" style="text-align:center;color:var(--muted);padding:28px">
                    {{ __('online.orders_empty') }}
                </td></tr>
            @endforelse
            {{-- إجمالي كل النتيجة المفلترة من السيرفر — مش الصفحة (٢٢/٩) --}}
            @if ($orders->total() > 0)
                <tfoot><tr>
                    <td colspan="4"><b>{{ __('common.total') }}</b> <span class="s" style="color:var(--muted)">({{ __('ui.rows_n', ['n' => $orders->total()]) }})</span></td>
                    <td></td>
                    <td class="num"><b>{{ $money($totals->total) }}</b></td>
                    <td class="num"><b>{{ $money($totals->collected) }}</b></td>
                    <td colspan="3"></td>
                </tr></tfoot>
            @endif
        </table>
    </div>

    @include('partials._pagination', ['p' => $orders])
</div>

@endsection
