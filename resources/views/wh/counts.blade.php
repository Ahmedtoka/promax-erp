@extends('layouts.system')

@section('title', __('count.page'))

@php
    $fmt = fn ($n) => number_format((float) $n);
    $money = fn ($n) => number_format((float) $n, 2);
    // ⚠️ **`canDecideOps()` مش `isManager()`.** فتح جرد جديد راوته
    // `role:admin,manager` — و`isManager()` بتشمل مدير الفرع، فكان
    // بيشوف الزرار وياخد 403.
    $manager = auth()->user()->canDecideOps();
@endphp

@section('actions')
    <a class="btn" href="{{ route('wh.index') }}">🏭 {{ __('stock.warehouse_overview') }}</a>
    @if ($manager)
        @if (\App\Support\Access::action(auth()->user(), 'act.wh.count_manage'))<button class="btn gold" onclick="openDlg('dlgNewCount')">➕ {{ __('count.new_count') }}</button>@endif
    @endif
@endsection

@section('content')

<div class="card">
    <h3>📊 {{ __('count.page') }} <span class="side">{{ __('count.page_sub') }}</span></h3>

    @if ($openCount > 0)
        <div class="alert warn">{{ __('count.open_now', ['count' => $openCount]) }}</div>
    @endif

    <form method="GET" action="{{ route('wh.counts') }}" class="searchbar">
        <label class="fl"><span>{{ __('ui.l_warehouse') }}</span>
            <select name="warehouse">
                <option value="">{{ __('stock.all_warehouses') }}</option>
                @foreach ($warehouses as $w)
                    <option value="{{ $w->id }}" @selected($filters['warehouse'] == $w->id)>{{ $w->displayName() }}</option>
                @endforeach
            </select>
        </label>
        <label class="fl"><span>{{ __('ui.l_status') }}</span>
            <select name="status">
                <option value="">{{ __('stock.all_statuses') }}</option>
                @foreach (\App\Models\StockCount::STATUS as $st)
                    <option value="{{ $st }}" @selected($filters['status'] === $st)>{{ __('count.status_'.$st) }}</option>
                @endforeach
            </select>
        </label>
        {{-- فلتر «من — إلى» على يوم الجرد `count_date` (٩/٩/٢٠٢٦) —
             ده فلتر عرض GET، مش خانة `count_date` بتاعة فتح جرد جديد تحت --}}
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue()])
        <button class="btn gold">{{ __('common.filter') }}</button>
        <a class="btn" href="{{ route('wh.counts') }}">{{ __('common.clear') }}</a>
        {{-- القايمة صفحات — ده بينزّل كل النتيجة المفلترة (٢٢/٩) --}}
        <a class="btn sm green" href="{{ request()->fullUrlWithQuery(['export' => 1, 'page' => null]) }}">⬇ {{ __('ui.export_all') }}</a>
    </form>
</div>

<div class="card">
    <h3>📋 {{ __('count.counts') }}</h3>
    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('count.count') }}</th>
                <th>{{ __('count.warehouse') }}</th>
                <th data-nosum>{{ __('count.count_date') }}</th>
                <th class="num">{{ __('count.lines') }}</th>
                <th class="num">{{ __('count.diff_lines') }}</th>
                <th class="num">{{ __('count.qty_diff') }}</th>
                <th class="num">{{ __('count.value_diff') }}</th>
                <th>{{ __('common.status') }}</th>
                <th></th>
            </tr>

            @forelse ($counts as $c)
                <tr>
                    <td><a href="{{ route('wh.count', $c) }}"><b>{{ $c->number }}</b></a>
                        <br><span style="font-size:10.5px;color:var(--muted)">{{ $c->startedBy?->displayName() }}</span>
                    </td>
                    <td>@if ($c->warehouse)<a href="{{ route('erp.warehouses.stock', $c->warehouse) }}">{{ $c->warehouse->displayName() }}</a>@else — @endif</td>
                    <td class="num">{{ $c->count_date?->format('Y-m-d') }}</td>
                    <td class="num">{{ $fmt($c->lines) }}</td>
                    <td class="num {{ $c->diff_lines > 0 ? 'mid' : '' }}">{{ $fmt($c->diff_lines) }}</td>
                    <td class="num {{ $c->qty_diff < 0 ? 'neg' : ($c->qty_diff > 0 ? 'pos' : '') }}">
                        {{ $c->qty_diff > 0 ? '+' : '' }}{{ $fmt($c->qty_diff) }}
                    </td>
                    <td class="num {{ $c->value_diff < 0 ? 'neg' : ($c->value_diff > 0 ? 'pos' : '') }}">
                        {{ $money($c->value_diff) }}
                    </td>
                    <td><span class="badge {{ $c->statusClass() }}">{{ $c->statusLabel() }}</span></td>
                    <td class="num">
                        <a class="btn sm {{ $c->isOpen() ? 'gold' : '' }}" href="{{ route('wh.count', $c) }}">
                            {{ $c->isOpen() ? __('count.sheet') : __('common.view') }}
                        </a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" style="text-align:center;color:var(--muted);padding:28px">
                    {{ __('count.no_counts') }}
                </td></tr>
            @endforelse
            {{-- إجمالي كل النتيجة المفلترة من السيرفر — مش الصفحة (٢٢/٩) --}}
            @if ($counts->total() > 0)
                <tfoot><tr>
                    <td colspan="3"><b>{{ __('common.total') }}</b> <span class="s" style="color:var(--muted)">({{ __('ui.rows_n', ['n' => $counts->total()]) }})</span></td>
                    <td class="num"><b>{{ $fmt($totals->lines) }}</b></td>
                    <td class="num"><b>{{ $fmt($totals->diff_lines) }}</b></td>
                    <td class="num"><b>{{ $totals->qty_diff > 0 ? '+' : '' }}{{ $fmt($totals->qty_diff) }}</b></td>
                    <td class="num"><b>{{ $money($totals->value_diff) }}</b></td>
                    <td colspan="2"></td>
                </tr></tfoot>
            @endif
        </table>
    </div>

    @include('partials._pagination', ['p' => $counts])
</div>

@if ($manager)
<dialog id="dlgNewCount">
    <form class="dlg" method="POST" action="{{ route('wh.counts.store') }}">
        @csrf
        <h4>{{ __('count.new_count') }}</h4>

        <div>
            <label class="f">{{ __('count.warehouse') }}</label>
            <select name="warehouse_id" required style="width:100%">
                {{-- ⚠️ من غير مخزن جاهز (٢٢/٩): أول مخزن كان بينزل لوحده والجرد يتفتح على مخزن غلط --}}
                <option value="">{{ __('ui.choose', ['x' => __('ui.l_warehouse')]) }}</option>
                @foreach ($warehouses as $w)
                    <option value="{{ $w->id }}">{{ $w->displayName() }}</option>
                @endforeach
            </select>
        </div>

        <div style="margin-top:10px">
            <label class="f">{{ __('count.count_date') }}</label>
            <input type="date" name="count_date" value="{{ now()->toDateString() }}" style="width:100%">
        </div>

        <label style="display:flex;align-items:center;gap:7px;margin-top:12px;font-size:12.5px">
            <input type="hidden" name="include_zero" value="0">
            <input type="checkbox" name="include_zero" value="1">
            {{ __('count.include_zero') }}
        </label>
        <div style="font-size:11px;color:var(--muted);margin-top:3px">{{ __('count.include_zero_hint') }}</div>

        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgNewCount')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('count.open_count') }}</button>
        </div>
    </form>
</dialog>
@endif

@endsection
