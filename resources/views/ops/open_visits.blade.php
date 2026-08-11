@extends('layouts.system')

{{--
    الزيارات المفتوحة (١١ أغسطس ٢٠٢٦) — «المندوب عامل إن فين وواقف
    فين، وأقدر أعمله Out من الداش بورد».

    كل زيارة عميل مفتوحة أياً كان يومها (القديمة المنسية بتبان
    بالأحمر) + زيارات المخزن المفتوحة — بزرار إخراج إداري بيسجّل في
    التراكينج مين قفل وبيبلّغ المندوب بإشعار.
--}}

@section('title', __('nav.open_visits'))

@php
    $mins = fn ($t) => (int) round(abs($t->diffInMinutes(now())));
    $dur = function ($t) use ($mins) {
        $m = $mins($t);

        return $m >= 60 ? intdiv($m, 60).':'.str_pad($m % 60, 2, '0', STR_PAD_LEFT) : $m.' '.__('common.minutes');
    };
@endphp

@section('content')

<div class="kpis" style="margin-bottom:14px">
    <div class="kpi"><div class="lbl">📍 {{ __('ops.ov_clients') }}</div>
        <div class="val {{ $visits->count() > 0 ? 'mid' : '' }}">{{ $visits->count() }}</div></div>
    <div class="kpi"><div class="lbl">🏭 {{ __('ops.ov_warehouses') }}</div>
        <div class="val">{{ $whVisits->count() }}</div></div>
    <div class="kpi"><div class="lbl">⏳ {{ __('ops.ov_stale') }}</div>
        <div class="val neg">{{ $visits->filter(fn ($v) => ! $v->checked_in_at?->isToday())->count() }}</div>
        <div class="sub2">{{ __('ops.ov_stale_hint') }}</div></div>
</div>

<div class="card">
    <h3>📍 {{ __('ops.ov_clients') }}
        <span class="side">{{ __('ops.ov_hint') }}</span></h3>

    <div class="tablewrap">
        <table>
            <thead>
            <tr>
                <th style="text-align:start">{{ __('ops.rep') }}</th>
                <th>{{ __('client.client') }}</th>
                <th>{{ __('ops.ov_since') }}</th>
                <th>{{ __('ops.ov_duration') }}</th>
                <th>{{ __('geo.current_point') }}</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($visits as $v)
                @php $stale = ! $v->checked_in_at?->isToday(); @endphp
                <tr @if ($stale) style="background:#FDECEC" @endif>
                    <td>
                        <div style="display:flex;gap:9px;align-items:center">
                            @include('partials._avatar', ['u' => $v->user, 'size' => 32])
                            <div>
                                <b>{{ $v->user?->displayName() ?? '—' }}</b>
                                <div style="font-size:10.5px;color:var(--muted)">{{ $v->user?->roleLabel() }}</div>
                            </div>
                        </div>
                    </td>
                    <td><b>{{ $v->client?->displayName() ?? '—' }}</b>
                        <div style="font-size:10.5px;color:var(--muted)">{{ $v->client?->zone?->displayName() ?? '' }}</div>
                    </td>
                    <td dir="ltr">
                        {{ $v->checked_in_at?->format('Y-m-d h:i A') ?? '—' }}
                        @if ($stale)
                            <br><span class="badge b-red" style="font-size:9.5px">{{ __('ops.ov_old_visit') }}</span>
                        @endif
                    </td>
                    <td dir="ltr" style="font-weight:800">{{ $v->checked_in_at ? $dur($v->checked_in_at) : '—' }}</td>
                    <td class="num">
                        @if ($v->lat && $v->lng)
                            <a href="https://www.google.com/maps?q={{ $v->lat }},{{ $v->lng }}" target="_blank"
                               style="font-weight:800;color:var(--primary)">🗺️ <span dir="ltr">{{ number_format((float) $v->lat, 4) }}, {{ number_format((float) $v->lng, 4) }}</span></a>
                        @else
                            <span style="color:var(--muted)">📵 {{ __('ops.no_coords_one') }}</span>
                        @endif
                    </td>
                    <td>
                        <form method="POST" action="{{ route('ops.open_visits.out', $v) }}" style="display:inline"
                              onsubmit="return confirm(@js(__('ops.ov_confirm', ['rep' => $v->user?->displayName() ?? '—', 'client' => $v->client?->displayName() ?? '—'])))">
                            @csrf
                            <button class="btn sm red" type="submit">🚪 {{ __('ops.ov_force_out') }}</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6"><div class="empty">✅ {{ __('ops.ov_none') }}</div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h3>🏭 {{ __('ops.ov_warehouses') }}
        <span class="side">{{ __('ops.ov_wh_hint') }}</span></h3>

    <div class="tablewrap">
        <table>
            <thead>
            <tr>
                <th style="text-align:start">{{ __('hr.employee') }}</th>
                <th>{{ __('stock.warehouse') }}</th>
                <th>{{ __('ops.ov_since') }}</th>
                <th>{{ __('ops.ov_duration') }}</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($whVisits as $w)
                <tr>
                    <td>
                        <div style="display:flex;gap:9px;align-items:center">
                            @include('partials._avatar', ['u' => $w->user, 'size' => 32])
                            <b>{{ $w->user?->displayName() ?? '—' }}</b>
                        </div>
                    </td>
                    <td>{{ $w->warehouse?->displayName() ?? '—' }}</td>
                    <td dir="ltr">{{ $w->checked_in_at?->format('Y-m-d h:i A') ?? '—' }}</td>
                    <td dir="ltr" style="font-weight:800">{{ $w->checked_in_at ? $dur($w->checked_in_at) : '—' }}</td>
                    <td>
                        <form method="POST" action="{{ route('ops.open_visits.wh_out', $w) }}" style="display:inline"
                              onsubmit="return confirm(@js(__('ops.ov_confirm', ['rep' => $w->user?->displayName() ?? '—', 'client' => $w->warehouse?->displayName() ?? '—'])))">
                            @csrf
                            <button class="btn sm red" type="submit">🚪 {{ __('ops.ov_force_out') }}</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5"><div class="empty">✅ {{ __('ops.ov_none') }}</div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@endsection
