@extends('layouts.system')

@section('title', __('count.page'))

@php
    $fmt = fn ($n) => number_format((float) $n);
    $money = fn ($n) => number_format((float) $n, 2);
    $manager = auth()->user()->isManager();
@endphp

@section('actions')
    <a class="btn" href="{{ route('wh.index') }}">🏭 {{ __('stock.warehouse_overview') }}</a>
    @if ($manager)
        <button class="btn gold" onclick="openDlg('dlgNewCount')">➕ {{ __('count.new_count') }}</button>
    @endif
@endsection

@section('content')

<div class="card">
    <h3>📊 {{ __('count.page') }} <span class="side">{{ __('count.page_sub') }}</span></h3>

    @if ($openCount > 0)
        <div class="alert warn">{{ __('count.open_now', ['count' => $openCount]) }}</div>
    @endif

    <form method="GET" action="{{ route('wh.counts') }}" class="searchbar">
        <div>
            <label class="f">{{ __('count.warehouse') }}</label>
            <select name="warehouse">
                <option value="">{{ __('common.all') }}</option>
                @foreach ($warehouses as $w)
                    <option value="{{ $w->id }}" @selected($filters['warehouse'] == $w->id)>{{ $w->displayName() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="f">{{ __('common.status') }}</label>
            <select name="status">
                <option value="">{{ __('common.all') }}</option>
                @foreach (\App\Models\StockCount::STATUS as $st)
                    <option value="{{ $st }}" @selected($filters['status'] === $st)>{{ __('count.status_'.$st) }}</option>
                @endforeach
            </select>
        </div>
        <button class="btn">{{ __('common.filter') }}</button>
    </form>
</div>

<div class="card">
    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('count.count') }}</th>
                <th>{{ __('count.warehouse') }}</th>
                <th>{{ __('count.count_date') }}</th>
                <th class="num">{{ __('count.lines') }}</th>
                <th class="num">{{ __('count.diff_lines') }}</th>
                <th class="num">{{ __('count.qty_diff') }}</th>
                <th class="num">{{ __('count.value_diff') }}</th>
                <th>{{ __('common.status') }}</th>
                <th></th>
            </tr>

            @forelse ($counts as $c)
                <tr>
                    <td><b>{{ $c->number }}</b>
                        <br><span style="font-size:10.5px;color:var(--muted)">{{ $c->startedBy?->displayName() }}</span>
                    </td>
                    <td>{{ $c->warehouse->displayName() }}</td>
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
        </table>
    </div>

    <div class="pag">{{ $counts->links() }}</div>
</div>

@if ($manager)
<dialog id="dlgNewCount">
    <form class="dlg" method="POST" action="{{ route('wh.counts.store') }}">
        @csrf
        <h4>{{ __('count.new_count') }}</h4>

        <div>
            <label class="f">{{ __('count.warehouse') }}</label>
            <select name="warehouse_id" required style="width:100%">
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
