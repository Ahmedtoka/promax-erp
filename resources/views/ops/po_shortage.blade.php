@extends('layouts.system')

{{--
    الطلب مقابل المخزون (٩/١١/٢٠٢٦) — كل الأوامر المفتوحة قصاد المتاح.
    التعريفات (مفتوح / متغطّي / متاح / عجز) في App\Services\PoShortage بس.
    الكروت ← الفلاتر (نفس فلاتر `ops.pos` + المخزن + النواقص بس) ←
    جدول الأصناف (إكسيل الجدول تلقائي) ← الأوامر المستنية أو أوامر صنف.
--}}

@php
    $fmt = fn ($n) => number_format((float) $n);
    $f = $filters;
    $productUrl = fn (?int $pid) => request()->fullUrlWithQuery(['product' => $pid]);
@endphp

@section('title', __('shortage.title'))

@section('actions')
    <a class="btn" href="{{ route('ops.pos', \Illuminate\Support\Arr::except(request()->query(), ['warehouse', 'short', 'product'])) }}">🚚 {{ __('nav.purchase_orders') }}</a>
@endsection

@section('content')

<div class="card" style="padding:10px 14px">
    <div style="font-size:12.5px">{{ __('shortage.intro') }}</div>
    <div style="font-size:11px;color:var(--muted);margin-top:4px">ℹ️ {{ __('shortage.rules') }}</div>
</div>

<div class="kpis">
    <div class="kpi">
        <div class="lbl">🚚 {{ __('shortage.k_open') }}</div>
        <div class="val">{{ $fmt($t['ordered_units']) }}</div>
        <div class="sub2">{{ __('shortage.k_open_sub', ['u' => $fmt($t['ordered_units']), 'v' => $fmt($t['ordered_value'])]) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">📦 {{ __('shortage.k_covered') }}</div>
        <div class="val pos">{{ $fmt($t['prepared_units'] + $t['reserved_units']) }}</div>
        <div class="sub2">{{ __('shortage.k_covered_sub', ['r' => $fmt($t['reserved_units']), 'p' => $fmt($t['prepared_units'])]) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">🧾 {{ __('shortage.k_need') }}</div>
        <div class="val mid">{{ $fmt($t['need_units']) }}</div>
        <div class="sub2">{{ __('shortage.k_need_sub', ['n' => $fmt($orders->count()), 'v' => $fmt($t['need_value'])]) }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">🏭 {{ __('shortage.k_free') }}</div>
        <div class="val">{{ $fmt($t['free_for_need']) }}</div>
        <div class="sub2">{{ __('shortage.k_free_sub', ['n' => $fmt($t['free'])]) }}</div>
    </div>
    <a class="kpi {{ ($f['short'] ?? false) ? 'on' : '' }}" href="{{ request()->fullUrlWithQuery(['short' => ($f['short'] ?? false) ? null : 1, 'product' => null]) }}">
        <div class="lbl">⚠️ {{ __('shortage.k_short') }}</div>
        <div class="val {{ $t['short_units'] > 0 ? 'neg' : 'pos' }}">{{ $fmt($t['short_units']) }}</div>
        <div class="sub2">{{ __('shortage.k_short_sub', ['v' => $fmt($t['short_value']), 'n' => $fmt($t['short_products'])]) }}</div>
    </a>
    <div class="kpi">
        <div class="lbl">🎯 {{ __('shortage.k_cover') }}</div>
        <div class="val {{ $t['coverage'] >= 100 ? 'pos' : ($t['coverage'] >= 80 ? 'mid' : 'neg') }}">{{ $t['coverage'] }}%</div>
        <div class="sub2">{{ __('shortage.k_cover_sub') }}</div>
    </div>
</div>

<div class="card">
    <h3>📊 {{ __('shortage.products') }} <span class="side">{{ $rows->count() }}</span></h3>

    <form class="searchbar" method="GET">
        @if ($f['product'] ?? false)<input type="hidden" name="product" value="{{ $f['product'] }}">@endif
        @if ($f['late'] ?? false)<input type="hidden" name="late" value="1">@endif
        <label class="fl grow"><span>{{ __('ui.l_search') }}</span>
            <input type="text" name="q" value="{{ $f['q'] ?? '' }}" placeholder="🔍 {{ __('ops.search_po_ph') }}"></label>
        <label class="fl"><span>{{ __('shortage.l_warehouse') }}</span>
            <select name="warehouse">
                <option value="">{{ __('shortage.all_warehouses') }}</option>
                @foreach ($warehouses as $w)
                    <option value="{{ $w->id }}" @selected((int) ($f['warehouse'] ?? 0) === $w->id)>{{ $w->displayName() }}</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('ui.l_channel') }}</span>
            <select name="channel">
                <option value="">{{ __('client.all_channels') }}</option>
                @foreach ($channels as $ch)
                    <option value="{{ $ch->id }}" @selected((int) ($f['channel'] ?? 0) === $ch->id)>{{ $ch->displayName() }}</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('ui.l_group') }}</span>
            <select name="group">
                <option value="">{{ __('ui.all_of', ['x' => __('nav.chains')]) }}</option>
                @foreach ($groups as $g)
                    <option value="{{ $g->id }}" @selected((int) ($f['group'] ?? 0) === $g->id)>{{ $g->displayName() }}</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('ui.l_rep') }}</span>
            <select name="rep">
                <option value="">{{ __('ops.all_reps') }}</option>
                @foreach ($reps as $rp)
                    <option value="{{ $rp->id }}" @selected((int) ($f['rep'] ?? 0) === $rp->id)>{{ $rp->displayName() }}</option>
                @endforeach
            </select></label>
        <label class="fl"><span>{{ __('ui.l_approval') }}</span>
            <select name="approval">
                <option value="">{{ __('ui.all_of', ['x' => __('uic.decisions')]) }}</option>
                @foreach (['pending', 'approved'] as $a)
                    <option value="{{ $a }}" @selected(($f['approval'] ?? '') === $a)>{{ __('enums.po_approval.'.$a) }}</option>
                @endforeach
                <option value="none" @selected(($f['approval'] ?? '') === 'none')>{{ __('shortage.approval_none') }}</option>
            </select></label>
        <label class="fl"><span>{{ __('shortage.l_open_status') }}</span>
            <select name="status">
                <option value="">{{ __('ui.all_of', ['x' => __('uic.statuses')]) }}</option>
                @foreach (\App\Services\PoShortage::OPEN_STATUSES as $k)
                    <option value="{{ $k }}" @selected(($f['status'] ?? '') === $k)>{{ __('enums.po_status.'.$k) }}</option>
                @endforeach
            </select></label>
        @include('partials._range', ['from' => $f['from'] ?? '', 'to' => $f['to'] ?? ''])
        <label class="fl"><span>&nbsp;</span>
            <span style="display:flex;align-items:center;gap:6px;white-space:nowrap">
                <input type="checkbox" name="short" value="1" @checked($f['short'] ?? false)> {{ __('shortage.only_short') }}
            </span></label>
        <button class="btn gold" type="submit">{{ __('common.search') }}</button>
        <a class="btn" href="{{ route('ops.po.shortage') }}">{{ __('common.clear') }}</a>
    </form>

    <div class="tablewrap" style="max-height:62vh;overflow-y:auto">
        <table>
            <thead>
            <tr>
                <th>{{ __('shortage.c_product') }}</th>
                <th class="num">{{ __('shortage.c_pos') }}</th>
                <th class="num">{{ __('shortage.c_ordered') }}</th>
                <th class="num">{{ __('shortage.c_ordered_value') }}</th>
                <th class="num">{{ __('shortage.c_covered') }}</th>
                <th class="num">{{ __('shortage.c_need') }}</th>
                <th class="num">{{ __('shortage.c_shelf') }}</th>
                <th class="num">{{ __('shortage.c_held') }}</th>
                <th class="num">{{ __('shortage.c_free') }}</th>
                <th class="num">{{ __('shortage.c_short') }}</th>
                <th class="num">{{ __('shortage.c_short_value') }}</th>
                <th class="num" data-nosum>{{ __('shortage.c_cover') }}</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($rows as $r)
                <tr @if ((int) ($f['product'] ?? 0) === $r->product_id) style="background:var(--gold-soft, #FEF9C3)" @endif>
                    <td><b>{{ $r->product?->displayName() ?? '#'.$r->product_id }}</b>
                        @if ($r->product?->code)<br><span style="font-size:10.5px;color:var(--muted)" dir="ltr">{{ $r->product->code }}</span>@endif</td>
                    <td class="num">{{ $fmt($r->po_count) }}</td>
                    <td class="num">{{ $fmt($r->ordered_units) }}</td>
                    <td class="num">{{ $fmt($r->ordered_value) }}</td>
                    <td class="num">{{ $fmt($r->prepared_units + $r->reserved_units) }}</td>
                    <td class="num">{{ $fmt($r->need_units) }}</td>
                    <td class="num">{{ $fmt($r->shelf) }}
                        @if ($r->unshelved > 0)<br><span style="font-size:10px;color:var(--muted)">{{ __('shortage.unshelved', ['n' => $fmt($r->unshelved)]) }}</span>@endif</td>
                    <td class="num">{{ $fmt($r->held) }}</td>
                    <td class="num"><b>{{ $fmt($r->free) }}</b></td>
                    <td class="num" style="{{ $r->short_units > 0 ? 'color:var(--red);font-weight:800' : '' }}">{{ $fmt($r->short_units) }}</td>
                    <td class="num" style="{{ $r->short_units > 0 ? 'color:var(--red)' : '' }}">{{ $fmt($r->short_value) }}</td>
                    <td class="num"><span class="badge {{ $r->coverage >= 100 ? 'b-green' : ($r->coverage >= 80 ? 'b-orange' : 'b-red') }}">{{ $r->coverage }}%</span></td>
                    <td><a class="btn sm" href="{{ $productUrl($r->product_id) }}#orders">📋 {{ __('shortage.drill') }}</a></td>
                </tr>
            @empty
                <tr><td colspan="13" class="empty">{{ ($f['short'] ?? false) ? __('shortage.none_short') : __('shortage.none') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card" id="orders">
    @if ($product)
        <h3>📋 {{ __('shortage.orders_product', ['p' => $product->displayName()]) }}
            <span class="side"><a href="{{ $productUrl(null) }}">↩ {{ __('shortage.back') }}</a></span></h3>
        @if ($productRow)
            <div style="font-size:12px;margin-bottom:8px">
                {{ __('shortage.c_ordered') }} <b>{{ $fmt($productRow->ordered_units) }}</b> ·
                {{ __('shortage.c_covered') }} <b>{{ $fmt($productRow->prepared_units + $productRow->reserved_units) }}</b> ·
                {{ __('shortage.c_need') }} <b>{{ $fmt($productRow->need_units) }}</b> ·
                {{ __('shortage.c_free') }} <b>{{ $fmt($productRow->free) }}</b> ·
                {{ __('shortage.c_short') }} <b style="color:var(--red)">{{ $fmt($productRow->short_units) }}</b>
            </div>
        @endif
    @else
        <h3>⏳ {{ __('shortage.orders_need') }} <span class="side">{{ $orders->count() }}</span></h3>
    @endif
    <div style="font-size:11px;color:var(--muted);margin-bottom:6px">{{ __('shortage.orders_need_hint') }}</div>

    @php
        $missingNames = \App\Models\Product::whereIn('id', $orders->flatMap(fn ($o) => array_keys($o->missing))->unique()->all())
            ->get(['id', 'name', 'name_en'])->keyBy('id');
    @endphp
    <div class="tablewrap" style="max-height:62vh;overflow-y:auto">
        <table>
            <thead>
            <tr>
                <th>{{ __('shortage.c_po') }}</th>
                <th>{{ __('shortage.c_client') }}</th>
                <th>{{ __('shortage.c_rep') }}</th>
                <th data-nosum>{{ __('shortage.c_due') }}</th>
                <th>{{ __('common.status') }}</th>
                @if ($product)<th class="num">{{ __('shortage.c_this') }}</th>@endif
                <th class="num">{{ __('shortage.c_units') }}</th>
                <th class="num">{{ __('shortage.c_value') }}</th>
                <th>{{ __('shortage.c_state') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($orders as $o)
                @php $po = $o->po; @endphp
                <tr>
                    <td class="num"><a href="{{ route('ops.pos.show', $po) }}"><b>{{ $po->number }}</b></a>
                        <br><span style="font-size:10.5px;color:var(--muted)">{{ $po->created_at?->format('m-d') }}</span></td>
                    <td>{{ $po->client?->displayName() ?? '—' }}
                        @if ($po->client?->group)<br><span style="font-size:10.5px;color:var(--muted)">{{ $po->client->group->displayName() }}</span>@endif</td>
                    <td>{{ $po->courier?->displayName() ?? '—' }}</td>
                    <td class="num">{{ $po->due_at?->format('m-d H:i') ?? $po->due_date?->format('m-d') ?? '—' }}</td>
                    <td><span class="badge {{ $po->statusClass() }}">{{ $po->statusLabel() }}</span>
                        @if ($po->approval_status)<br><span class="badge {{ $po->approvalClass() }}" style="margin-top:2px">{{ $po->approvalLabel() }}</span>@endif</td>
                    @if ($product)<td class="num"><b>{{ $fmt($o->product_units) }}</b></td>@endif
                    <td class="num">{{ $fmt($o->units) }}</td>
                    <td class="num">{{ $fmt($o->value) }}</td>
                    <td>
                        @if ($o->covered)
                            <span class="badge b-blue">📦 {{ __('shortage.s_covered', ['n' => $po->pickOrder?->number]) }}</span>
                        @elseif ($o->can_serve)
                            <span class="badge b-green">✅ {{ __('shortage.s_can') }}</span>
                        @else
                            <span class="badge b-red">⛔ {{ __('shortage.s_cant', ['n' => $fmt($o->missing_units)]) }}</span>
                            <div style="font-size:10px;color:var(--muted);white-space:normal;max-width:260px">
                                {{ __('shortage.missing_of', ['list' => collect($o->missing)->map(fn ($q, $pid) => ($missingNames[$pid]?->displayName() ?? '#'.$pid).' ('.$fmt($q).')')->join('، ')]) }}
                            </div>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ $product ? 9 : 8 }}" class="empty">{{ __('shortage.orders_none') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
