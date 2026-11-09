@extends('layouts.system')

{{--
    الطلب مقابل المخزون (٩/١١/٢٠٢٦ — إعادة تصميم تانية بعد ملاحظات المالك:
    «مش فيجوال» · «البوكسات مش موضحة عدد الأوامر من القطع من الفلوس» ·
    «أفلتر بالمستنية بس» · «وضّح متوفر في أنهي مخزن»).

    من فوق لتحت: الفلتر السريع ← الإجابة (دفتر الطلبات | العجز | المخزون
    + الطلب قصاد المخزون + وضع القطع) ← المتاح فين + رسم الأصناف ←
    فلاتر تانية ← جدول الأصناف ← الأوامر المستنية.
    التعريفات كلها في App\Services\PoShortage — الشاشة بتعرض بس.
--}}

@php
    $fmt = fn ($n) => number_format((float) $n);
    $f = $filters;
    $scope = $f['scope'] ?? 'awaiting';
    $productUrl = fn (?int $pid) => request()->fullUrlWithQuery(['product' => $pid]);
    $wh = fn ($id) => $whModels[$id]?->displayName() ?? '#'.$id;
    $covered = $t['prepared_units'] + $t['reserved_units'];
    $ordered = max($t['ordered_units'], 1);
    $pc = fn ($n, $of) => $of > 0 ? round($n / $of * 100, 2) : 0;
    $state = $t['ordered_units'] === 0 ? 'none' : ($t['short_units'] > 0 ? 'short' : 'ok');
    $coverTone = fn ($p) => $p >= 100 ? 'ok' : ($p >= 80 ? 'warn' : 'bad');
    $listMode = ! $product;
    $ready = $listMode ? $orders->where('can_serve', true)->count() : 0;
    $blocked = $listMode ? $orders->where('state', 'short')->count() : 0;
    $moreFilters = collect($f)->except(['product', 'scope'])->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty();
    $chart = $rows->where('need_units', '>', 0)->sortByDesc('need_units')->take(10)->values();
    $chartMax = max((int) $chart->max('need_units'), 1);
    $whMax = max((int) collect($t['by_warehouse'])->max('units'), 1);
    // «مستني الحسابات» مالوش أوامر تجهيز — عمود «متغطّي» هيبقى أصفار
    $showCovered = $scope !== 'awaiting';
@endphp

@section('title', __('shortage.title'))

@section('actions')
    <a class="btn" href="{{ route('ops.pos', \Illuminate\Support\Arr::except(request()->query(), ['warehouse', 'short', 'product'])) }}">🚚 {{ __('shortage.back_to_pos') }}</a>
@endsection

@section('content')
<div class="sh">

@include('ops._po_scope', ['current' => $scope, 'withAll' => false])

{{-- ═══ ١. الإجابة ═══ --}}
<section class="card sh-hero sh-{{ $state }}">
    <div class="sh-hero-top">
        <h2 class="sh-q">⚖️ {{ __('shortage.hero_q') }}</h2>
        <span class="sh-verdict">{{ __('shortage.verdict_'.$state) }}</span>
    </div>

    @if ($state === 'none')
        <div class="sh-empty"><div class="sh-empty-ic">📭</div><b>{{ __('shortage.empty_title') }}</b><span>{{ __('shortage.empty_body') }}</span></div>
    @else
        @include('ops._demand_ledger', ['t' => $t])

        {{-- الطلب قصاد المخزون — شريطين بنفس المقياس والفرق بالأحمر --}}
        <div class="sh-cmp">
            <div class="sh-sec">{{ __('shortage.cmp_title') }}</div>
            <div class="cmp-row">
                <div class="cmp-l"><b>{{ __('shortage.cmp_need') }}</b><span>{{ __('shortage.cmp_need_cap') }}</span></div>
                <div class="cmp-track"><i class="cmp-need" style="width:100%"></i></div>
                <div class="cmp-n">{{ $fmt($t['need_units']) }} <small>{{ __('shortage.u_pcs') }}</small></div>
            </div>
            <div class="cmp-row">
                <div class="cmp-l"><b>{{ __('shortage.cmp_free') }}</b><span>{{ __('shortage.cmp_free_cap') }}</span></div>
                <div class="cmp-track">
                    <i class="cmp-free" style="width:{{ $pc($t['free_for_need'], $t['need_units']) }}%"></i>
                    @if ($t['short_units'] > 0)
                        <i class="cmp-gap" style="width:{{ $pc($t['short_units'], $t['need_units']) }}%"><span>{{ __('shortage.cmp_gap') }}: {{ $fmt($t['short_units']) }}</span></i>
                    @endif
                </div>
                <div class="cmp-n">{{ $fmt($t['free_for_need']) }} <small>{{ __('shortage.u_pcs') }}</small></div>
            </div>
            @if ($t['need_units'] > 0 && $t['short_units'] === 0)
                <div class="cmp-ok">✅ {{ __('shortage.cmp_ok') }}</div>
            @endif
        </div>

        {{-- وضع كل القطع: متغطّي / ليه مخزون / ناقص --}}
        <div class="sh-mix">
            <div class="sh-sec">{{ __('shortage.mix_title') }}</div>
            <div class="sh-cover">
                <div class="sh-cover-num"><b>{{ $t['coverage'] }}%</b><span>{{ __('shortage.cover_label') }}</span></div>
                <div class="sh-cover-body">
                    <div class="sh-bar" role="img" aria-label="{{ __('shortage.cover_label') }} {{ $t['coverage'] }}%">
                        <i class="seg-covered" style="width:{{ $pc($covered, $ordered) }}%"></i>
                        <i class="seg-free" style="width:{{ $pc($t['free_for_need'], $ordered) }}%"></i>
                        <i class="seg-short" style="width:{{ $pc($t['short_units'], $ordered) }}%"></i>
                    </div>
                    <div class="sh-legend">
                        <span title="{{ __('shortage.seg_covered_cap', ['r' => $fmt($t['reserved_units']), 'p' => $fmt($t['prepared_units'])]) }}"><i class="dot seg-covered"></i>{{ __('shortage.seg_covered') }} <b>{{ $fmt($covered) }}</b></span>
                        <span title="{{ __('shortage.seg_free_cap') }}"><i class="dot seg-free"></i>{{ __('shortage.seg_free') }} <b>{{ $fmt($t['free_for_need']) }}</b></span>
                        <span title="{{ __('shortage.seg_short_cap') }}"><i class="dot seg-short"></i>{{ __('shortage.seg_short') }} <b>{{ $fmt($t['short_units']) }}</b></span>
                        <span class="muted">{{ __('shortage.u_pcs') }} · {{ __('shortage.cover_cap') }}</span>
                    </div>
                </div>
            </div>
            <div class="sh-mini">
                @if ($t['awaiting_units'] > 0)
                    <span class="chip chip-wait">⏳ {{ __('shortage.awaiting_note', ['a' => $fmt($t['awaiting_units']), 'n' => $fmt($t['need_orders'])]) }}</span>
                @endif
                @if ($listMode && $orders->isNotEmpty())
                    <a href="#orders" class="chip chip-ok">✓ {{ __('shortage.orders_ready', ['n' => $ready]) }}</a>
                    @if ($blocked)<a href="#orders" class="chip chip-bad">✕ {{ __('shortage.orders_blocked', ['n' => $blocked]) }}</a>@endif
                @endif
            </div>
        </div>
    @endif

    <details class="sh-how">
        <summary>ⓘ {{ __('shortage.how') }}</summary>
        <ul>
            @foreach (['open', 'value', 'covered', 'free', 'excluded', 'short', 'cover', 'serve'] as $k)
                <li>{!! __('shortage.how_'.$k) !!}</li>
            @endforeach
        </ul>
    </details>
</section>

@if ($state !== 'none')
{{-- ═══ ٢. المتاح فين + أكبر الأصناف ═══ --}}
<div class="sh-two">
    <section class="card">
        <h3>🏭 {{ __('shortage.wh_title') }}</h3>
        <p class="sh-cap">{{ __('shortage.wh_cap') }}</p>
        @forelse ($t['by_warehouse'] as $wid => $w)
            <div class="whr">
                <div class="whr-h"><b>{{ $wh($wid) }}</b><span>{{ __('shortage.wh_products', ['n' => $w['products']]) }}</span></div>
                <div class="whr-bar"><i style="width:{{ $pc($w['units'], $whMax) }}%"></i></div>
                <div class="whr-n"><b>{{ $fmt($w['units']) }}</b> {{ __('shortage.u_pcs') }} · <b>{{ $fmt($w['value']) }}</b> {{ __('shortage.u_egp') }}</div>
            </div>
        @empty
            <div class="sh-empty is-bad"><div class="sh-empty-ic">📦</div><span>{{ __('shortage.wh_none') }}</span></div>
        @endforelse
    </section>

    <section class="card">
        <h3>📊 {{ __('shortage.chart_title') }}</h3>
        <p class="sh-cap">{{ __('shortage.chart_cap') }}</p>
        @foreach ($chart as $c)
            @php $have = min($c->free, $c->need_units); @endphp
            <a class="pc" href="{{ $productUrl($c->product_id) }}#orders">
                <div class="pc-h"><b>{{ $c->product?->displayName() ?? '#'.$c->product_id }}</b>
                    <span>{{ __('shortage.c_need') }} {{ $fmt($c->need_units) }} · {{ __('shortage.c_free') }} {{ $fmt($c->free) }}@if ($c->short_units > 0) · <em>{{ __('shortage.seg_short') }} {{ $fmt($c->short_units) }}</em>@endif</span></div>
                <div class="pc-track">
                    <i class="pc-need" style="width:{{ $pc($c->need_units, $chartMax) }}%"></i>
                    <i class="pc-have" style="width:{{ $pc($have, $chartMax) }}%"></i>
                    @if ($c->short_units > 0)
                        <i class="pc-gap" style="inset-inline-start:{{ $pc($have, $chartMax) }}%;width:{{ $pc($c->short_units, $chartMax) }}%"></i>
                    @endif
                </div>
            </a>
        @endforeach
        @if ($rows->where('need_units', '>', 0)->count() > $chart->count())
            <div class="sh-cap" style="margin:8px 0 0">↓ {{ __('shortage.chart_more') }}</div>
        @endif
    </section>
</div>
@endif

{{-- ═══ ٣. فلاتر تانية — متجمّعة: مين · المخزون · أنهي أوامر ═══ --}}
<details class="card sh-filters" @if ($moreFilters) open @endif>
    <summary>🔎 {{ __('shortage.f_title') }}</summary>
    <form method="GET">
        <input type="hidden" name="scope" value="{{ $scope }}">
        @if ($f['product'] ?? false)<input type="hidden" name="product" value="{{ $f['product'] }}">@endif
        @if ($f['late'] ?? false)<input type="hidden" name="late" value="1">@endif
        <div class="sh-fsearch">
            <label class="fl grow"><span>{{ __('ui.l_search') }}</span>
                <input type="text" name="q" value="{{ $f['q'] ?? '' }}" placeholder="🔍 {{ __('ops.search_po_ph') }}"></label>
        </div>
        <div class="sh-fgroups">
            <fieldset class="sh-fg">
                <legend>{{ __('shortage.f_who') }}</legend>
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
            </fieldset>
            <fieldset class="sh-fg">
                <legend>{{ __('shortage.f_which') }}</legend>
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
            </fieldset>
            <fieldset class="sh-fg">
                <legend>{{ __('shortage.f_stock') }}</legend>
                <label class="fl"><span>{{ __('shortage.l_warehouse') }}</span>
                    <select name="warehouse">
                        <option value="">{{ __('shortage.all_warehouses') }}</option>
                        @foreach ($warehouses as $w)
                            <option value="{{ $w->id }}" @selected((int) ($f['warehouse'] ?? 0) === $w->id)>{{ $w->displayName() }}</option>
                        @endforeach
                    </select></label>
                <label class="sh-switch">
                    <input type="checkbox" name="short" value="1" @checked($f['short'] ?? false)>
                    <span class="sh-switch-ui" aria-hidden="true"></span>
                    <span>{{ __('shortage.only_short') }}</span>
                </label>
            </fieldset>
        </div>
        <div class="sh-factions">
            <button class="btn gold" type="submit">{{ __('shortage.apply') }}</button>
            <a class="btn" href="{{ route('ops.po.shortage', ['scope' => $scope]) }}">{{ __('common.clear') }}</a>
        </div>
    </form>
</details>

{{-- ═══ ٤. الأصناف ═══ --}}
<section class="card">
    <h3>📋 {{ __('shortage.products') }} <span class="side">{{ $fmt($rows->count()) }} {{ __('shortage.u_products') }}</span></h3>
    <p class="sh-cap">{{ __('shortage.products_cap') }}</p>

    @if ($rows->isEmpty())
        @if (($f['short'] ?? false) && $state !== 'none')
            <div class="sh-empty is-ok"><div class="sh-empty-ic">✅</div><b>{{ __('shortage.ok_title') }}</b><span>{{ __('shortage.ok_body') }}</span></div>
        @else
            <div class="sh-empty"><div class="sh-empty-ic">📭</div><b>{{ __('shortage.empty_title') }}</b><span>{{ __('shortage.empty_body') }}</span>
                @if ($moreFilters)<a class="btn sm" href="{{ route('ops.po.shortage', ['scope' => $scope]) }}">{{ __('shortage.empty_clear') }}</a>@endif</div>
        @endif
    @else
        <div class="tablewrap sh-table" style="max-height:70vh;overflow-y:auto">
            <table>
                <thead>
                <tr>
                    <th>{{ __('shortage.c_product') }}</th>
                    <th class="g-cvr" data-nosum data-tip="{{ __('shortage.t_cover') }}" tabindex="0">{{ __('shortage.c_cover') }}</th>
                    <th class="num" data-tip="{{ __('shortage.t_pos') }}" tabindex="0">{{ __('shortage.c_pos') }}</th>
                    <th class="num" data-tip="{{ __('shortage.t_ordered') }}" tabindex="0">{{ __('shortage.c_ordered') }}</th>
                    <th class="num" data-tip="{{ __('shortage.t_ordered_value') }}" tabindex="0">{{ __('shortage.c_ordered_value') }}</th>
                    @if ($showCovered)<th class="num g-cov" data-tip="{{ __('shortage.t_covered') }}" tabindex="0">{{ __('shortage.c_covered') }}</th>@endif
                    <th class="num g-cov" data-tip="{{ __('shortage.t_need') }}" tabindex="0">{{ __('shortage.c_need') }}</th>
                    <th class="num g-stk" data-tip="{{ __('shortage.t_free') }}" tabindex="0">{{ __('shortage.c_free') }}</th>
                    <th class="g-stk" data-nosum data-tip="{{ __('shortage.t_where') }}" tabindex="0">{{ __('shortage.c_where') }}</th>
                    <th class="num g-res" data-tip="{{ __('shortage.t_short') }}" tabindex="0">{{ __('shortage.c_short') }}</th>
                    <th class="num g-res" data-tip="{{ __('shortage.t_short_value') }}" tabindex="0">{{ __('shortage.c_short_value') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($rows as $r)
                    <tr @class(['is-short' => $r->short_units > 0, 'is-picked' => (int) ($f['product'] ?? 0) === $r->product_id])>
                        <td class="sh-prod">
                            <b>{{ $r->product?->displayName() ?? '#'.$r->product_id }}</b>
                            <span class="sh-code" dir="ltr">{{ $r->product?->code }}</span>
                            @if ($r->short_units > 0)<span class="chip chip-bad chip-xs">{{ __('shortage.short_badge') }}</span>@endif
                            <a class="sh-drill" href="{{ $productUrl($r->product_id) }}#orders">📋 {{ __('shortage.drill') }} ←</a>
                        </td>
                        <td><div class="sh-pcell">
                            <div class="sh-pbar sh-{{ $coverTone($r->coverage) }}"><i style="width:{{ min($r->coverage, 100) }}%"></i></div>
                            <span class="sh-pnum">{{ $r->coverage }}%</span>
                        </div></td>
                        <td class="num">{{ $fmt($r->po_count) }}</td>
                        <td class="num"><b>{{ $fmt($r->ordered_units) }}</b></td>
                        <td class="num">{{ $fmt($r->ordered_value) }}</td>
                        @if ($showCovered)<td class="num g-cov">{{ $fmt($r->prepared_units + $r->reserved_units) }}</td>@endif
                        <td class="num g-cov">{{ $fmt($r->need_units) }}</td>
                        <td class="num g-stk"><b>{{ $fmt($r->free) }}</b>
                            <span class="sh-sub">{{ __('shortage.c_shelf') }} {{ $fmt($r->shelf) }} − {{ __('shortage.c_held') }} {{ $fmt($r->held) }}</span>
                            @if ($r->unshelved > 0)<span class="sh-sub">{{ __('shortage.unshelved', ['n' => $fmt($r->unshelved)]) }}</span>@endif</td>
                        <td class="g-stk sh-where">
                            @if ($r->free_by_wh)
                                <details>
                                    <summary>
                                        @foreach ($r->free_by_wh as $wid => $q)
                                            <span class="whc" title="{{ $wh($wid) }}">{{ $wh($wid) }}: <b>{{ $fmt($q) }}</b></span>
                                        @endforeach
                                    </summary>
                                    <table class="bt" data-plain>
                                        <tr><th>{{ __('shortage.l_warehouse') }}</th><th>{{ __('shortage.b_batch') }}</th><th>{{ __('shortage.b_exp') }}</th><th>{{ __('shortage.b_qty') }}</th></tr>
                                        @foreach ($batches[$r->product_id] ?? [] as $b)
                                            <tr><td>{{ $wh($b->warehouse_id) }}</td><td dir="ltr">{{ $b->batch_no }}</td>
                                                <td dir="ltr">{{ \Illuminate\Support\Carbon::parse($b->expires_on)->format('Y-m-d') }}</td><td class="num">{{ $fmt($b->qty) }}</td></tr>
                                        @endforeach
                                    </table>
                                </details>
                            @else
                                <span class="whc whc-none">{{ __('shortage.no_stock') }}</span>
                            @endif
                        </td>
                        <td class="num g-res sh-short">{{ $fmt($r->short_units) }}</td>
                        <td class="num g-res sh-short">{{ $fmt($r->short_value) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

{{-- ═══ ٥. الأوامر ═══ --}}
<section class="card" id="orders">
    @if ($product)
        <h3>📋 {{ __('shortage.orders_product', ['p' => $product->displayName()]) }}
            <span class="side"><a class="btn sm" href="{{ $productUrl(null) }}">↩ {{ __('shortage.back') }}</a></span></h3>
        @if ($productRow)
            <div class="sh-strip">
                <span>{{ __('shortage.c_ordered') }} <b>{{ $fmt($productRow->ordered_units) }}</b> {{ __('shortage.u_pcs') }}</span>
                <span><i class="dot seg-covered"></i>{{ __('shortage.c_covered') }} <b>{{ $fmt($productRow->prepared_units + $productRow->reserved_units) }}</b></span>
                <span>{{ __('shortage.c_need') }} <b>{{ $fmt($productRow->need_units) }}</b></span>
                <span><i class="dot seg-free"></i>{{ __('shortage.c_free') }} <b>{{ $fmt($productRow->free) }}</b></span>
                <span class="{{ $productRow->short_units > 0 ? 'is-bad' : '' }}"><i class="dot seg-short"></i>{{ __('shortage.c_short') }} <b>{{ $fmt($productRow->short_units) }}</b></span>
                @foreach ($productRow->free_by_wh as $wid => $q)
                    <span class="whc">{{ $wh($wid) }}: <b>{{ $fmt($q) }}</b></span>
                @endforeach
            </div>
        @endif
    @else
        <h3>⏳ {{ __('shortage.orders_need') }} <span class="side">{{ $fmt($orders->count()) }} {{ __('shortage.u_orders') }}</span></h3>
    @endif
    <p class="sh-cap">{{ __('shortage.orders_need_cap') }}</p>

    @php
        $missingNames = \App\Models\Product::whereIn('id', $orders->flatMap(fn ($o) => array_keys($o->missing))->unique()->all())
            ->get(['id', 'name', 'name_en'])->keyBy('id');
        $whList = fn ($ids) => collect($ids ?? [])->map($wh)->join(' + ');
    @endphp

    @if ($orders->isEmpty())
        <div class="sh-empty is-ok"><div class="sh-empty-ic">📦</div><span>{{ $state === 'none' ? __('shortage.empty_title') : __('shortage.orders_none') }}</span></div>
    @else
        <div class="tablewrap" style="max-height:70vh;overflow-y:auto">
            <table>
                <thead>
                <tr>
                    <th>{{ __('shortage.c_po') }}</th>
                    <th>{{ __('shortage.c_client') }}</th>
                    <th data-nosum data-tip="{{ __('shortage.t_due') }}" tabindex="0">{{ __('shortage.c_due') }}</th>
                    <th data-tip="{{ __('shortage.t_wh') }}" tabindex="0">{{ __('shortage.c_wh') }}</th>
                    @if ($product)<th class="num" data-tip="{{ __('shortage.t_this') }}" tabindex="0">{{ __('shortage.c_this') }}</th>@endif
                    <th class="num" data-tip="{{ __('shortage.t_units') }}" tabindex="0">{{ __('shortage.c_units') }}</th>
                    <th class="num" data-tip="{{ __('shortage.t_value') }}" tabindex="0">{{ __('shortage.c_value') }}</th>
                    <th data-tip="{{ __('shortage.t_state') }}" tabindex="0">{{ __('shortage.c_state') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($orders as $o)
                    @php $po = $o->po; @endphp
                    <tr @class(['is-short' => $o->state === 'short'])>
                        <td class="num"><a href="{{ route('ops.pos.show', $po) }}"><b>{{ $po->number }}</b></a>
                            <span class="sh-sub">{{ $po->approvalLabel() }}</span></td>
                        <td>{{ $po->client?->displayName() ?? '—' }}
                            <span class="sh-sub">{{ $po->client?->group?->displayName() }}@if ($po->courier) · {{ $po->courier->displayName() }}@endif</span></td>
                        <td class="num" dir="ltr">{{ $po->due_at?->format('m-d H:i') ?? $po->due_date?->format('m-d') ?? '—' }}</td>
                        <td>{{ $po->warehouse_id ? $wh($po->warehouse_id) : '—' }}</td>
                        @if ($product)<td class="num"><b>{{ $fmt($o->product_units) }}</b></td>@endif
                        <td class="num">{{ $fmt($o->units) }}</td>
                        <td class="num">{{ $fmt($o->value) }}</td>
                        <td class="sh-state">
                            @switch($o->state)
                                @case('covered')
                                    <span class="chip chip-info">📦 {{ __('shortage.s_covered', ['n' => $po->pickOrder?->number]) }}</span>
                                    @break
                                @case('can')
                                    <span class="chip chip-ok">✓ {{ __('shortage.s_can', ['w' => $whList($o->from)]) }}</span>
                                    @break
                                @case('other')
                                    <span class="chip chip-warn">↔ {{ __('shortage.s_other', ['w' => $whList($o->from)]) }}</span>
                                    @break
                                @case('split')
                                    <span class="chip chip-warn">⇄ {{ __('shortage.s_split', ['w' => $whList($o->from)]) }}</span>
                                    @break
                                @default
                                    <span class="chip chip-bad">✕ {{ __('shortage.s_cant', ['n' => $fmt($o->missing_units)]) }}</span>
                                    <div class="sh-miss">
                                        @foreach ($o->missing as $pid => $q)
                                            <span>{{ $missingNames[$pid]?->displayName() ?? '#'.$pid }} <b>×{{ $fmt($q) }}</b></span>
                                        @endforeach
                                    </div>
                            @endswitch
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

</div>

<div class="sh-tip" id="shTip" role="tooltip" hidden></div>

<style>
.sh{--c-cov:var(--royal-blue);--c-free:#16A34A;--c-short:#DC2626;--c-warn:#EA8C1C;--c-need:#C7D7F5;font-variant-numeric:tabular-nums}
.sh ::selection{background:var(--blue-050);color:var(--royal-blue)}
.sh-sec{font-size:12.5px;font-weight:900;margin-bottom:9px}

/* ═══ الإجابة ═══ */
.sh-hero{padding:18px 20px 14px;position:relative;overflow:hidden}
.sh-hero::before{content:"";position:absolute;inset-block-start:0;inset-inline:0;height:4px;background:var(--brand-gradient)}
.sh-hero.sh-short::before{background:linear-gradient(90deg,var(--c-short),#F97316)}
.sh-hero.sh-ok::before{background:linear-gradient(90deg,var(--c-free),#22C55E)}
.sh-hero-top{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px}
.sh-q{font-size:17px;font-weight:900;margin:0}
.sh-verdict{font-size:13.5px;font-weight:900;padding:6px 16px;border-radius:999px;background:var(--card2);color:var(--text)}
.sh-short .sh-verdict{background:#FDECEC;color:#B91C1C}
.sh-ok .sh-verdict{background:#E7F7EE;color:#0F7A38}
.sh-hero .dl .kpi{box-shadow:none}

/* الطلب قصاد المخزون */
.sh-cmp{border:1px solid var(--border);border-radius:12px;padding:14px 16px 12px;margin-bottom:12px}
.cmp-row{display:grid;grid-template-columns:200px 1fr 120px;align-items:center;gap:14px;padding:6px 0}
.cmp-l b{display:block;font-size:13px;font-weight:900}
.cmp-l span{font-size:11px;color:var(--muted);line-height:1.5}
.cmp-track{position:relative;display:flex;height:30px;border-radius:8px;background:var(--card2);overflow:hidden}
.cmp-track i{display:block;height:100%}
.cmp-need{background:var(--c-need)}
.cmp-free{background:var(--c-free)}
.cmp-gap{background:#FEE2E2;box-shadow:inset 0 0 0 2px var(--c-short);display:flex !important;align-items:center;justify-content:center;overflow:hidden}
.cmp-gap span{font-size:11.5px;font-weight:900;color:#B91C1C;white-space:nowrap;padding:0 6px}
.cmp-n{font-size:20px;font-weight:900;letter-spacing:-.02em;text-align:end;white-space:nowrap}
.cmp-n small{font-size:12px;color:var(--muted);font-weight:800}
.cmp-ok{font-size:12.5px;font-weight:900;color:#0F7A38;margin-top:4px}

/* وضع القطع */
.sh-mix{border:1px solid var(--border);border-radius:12px;padding:14px 16px 12px}
.sh-cover{display:flex;align-items:center;gap:18px}
.sh-cover-num{display:flex;flex-direction:column;align-items:center;min-width:88px}
.sh-cover-num b{font-size:32px;font-weight:900;line-height:1;letter-spacing:-.02em;color:var(--royal-blue)}
.sh-short .sh-cover-num b{color:#B91C1C}
.sh-ok .sh-cover-num b{color:#0F7A38}
.sh-cover-num span{font-size:11px;font-weight:800;color:var(--muted);margin-top:4px}
.sh-cover-body{flex:1;min-width:0}
.sh-bar{display:flex;height:16px;border-radius:999px;overflow:hidden;background:var(--card2)}
.sh-bar i{display:block;height:100%}
.seg-covered{background:var(--c-cov)} .seg-free{background:var(--c-free)} .seg-short{background:var(--c-short)}
.sh-legend{display:flex;flex-wrap:wrap;gap:4px 16px;font-size:12px;margin-top:8px}
.sh-legend span{display:inline-flex;align-items:center;gap:6px;cursor:default}
.dot{display:inline-block;width:10px;height:10px;border-radius:3px;flex-shrink:0}
.sh-mini{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}

/* ═══ إزاي بنحسب ═══ */
.sh-how{margin-top:12px;border-top:1px dashed var(--border);padding-top:10px}
.sh-how summary,.sh-filters > summary{cursor:pointer;font-size:12.5px;font-weight:800;color:var(--royal-blue);list-style:none;display:inline-flex;gap:6px;align-items:center}
.sh-how summary::-webkit-details-marker,.sh-filters > summary::-webkit-details-marker{display:none}
.sh-how summary::after,.sh-filters > summary::after{content:"▾";transition:transform .2s}
.sh-how[open] summary::after,.sh-filters[open] > summary::after{transform:rotate(180deg)}
.sh-how summary:focus-visible,.sh-filters > summary:focus-visible{outline:2px solid var(--royal-blue);outline-offset:3px;border-radius:4px}
.sh-how ul{margin:10px 0 0;padding-inline-start:20px;display:grid;gap:7px;font-size:12.5px;line-height:1.8;max-width:95ch}

/* ═══ المتاح فين + رسم الأصناف ═══ */
.sh-two{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.5fr);gap:14px}
.sh-two > .card{min-width:0}
.whr{padding:9px 0;border-top:1px solid var(--border)}
.whr:first-of-type{border-top:0}
.whr-h{display:flex;justify-content:space-between;gap:8px;font-size:13px}
.whr-h span{font-size:11px;color:var(--muted)}
.whr-bar{height:10px;border-radius:999px;background:var(--card2);overflow:hidden;margin:6px 0 4px}
.whr-bar i{display:block;height:100%;background:var(--c-free);border-radius:inherit}
.whr-n{font-size:12px;color:var(--muted)}
.whr-n b{color:var(--text);font-weight:900}
.pc{display:block;padding:7px 6px;border-radius:8px;text-decoration:none;color:inherit}
.pc:hover{background:var(--card2)}
.pc:focus-visible{outline:2px solid var(--royal-blue)}
.pc-h{display:flex;justify-content:space-between;gap:10px;font-size:12px;margin-bottom:5px}
.pc-h b{font-weight:800;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pc-h span{color:var(--muted);white-space:nowrap;font-size:11.5px}
.pc-h em{font-style:normal;color:#B91C1C;font-weight:900}
.pc-track{position:relative;height:12px;border-radius:4px;background:var(--card2)}
.pc-track i{position:absolute;inset-block:0;inset-inline-start:0;border-radius:4px}
.pc-need{background:var(--c-need)}
.pc-have{background:var(--c-free)}
.pc-gap{background:var(--c-short);border-radius:0}

/* ═══ الفلاتر ═══ */
.sh-filters{padding:12px 16px}
.sh-filters[open] > summary{margin-bottom:12px}
.sh-fsearch{display:flex;margin-bottom:10px}
.sh-fsearch .fl{flex:1}
.sh-fgroups{display:grid;grid-template-columns:3fr 2fr;gap:10px}
.sh-fg{border:1px solid var(--border);border-radius:12px;padding:6px 12px 12px;margin:0;display:flex;flex-wrap:wrap;gap:8px 10px;align-items:flex-end;min-width:0}
.sh-fg:nth-child(2){grid-column:1 / -1;order:3}
.sh-fg:nth-child(3){order:2}
.sh-fg legend{font-size:11px;font-weight:900;color:var(--royal-blue);padding:0 6px}
.sh-fg .fl{flex:1 1 140px;min-width:0}
.sh-fg .fl select{width:100%}
.sh-switch{display:inline-flex;align-items:center;gap:8px;font-size:12.5px;font-weight:800;cursor:pointer;padding:8px 2px;user-select:none}
.sh-switch input{position:absolute;opacity:0;width:1px;height:1px}
.sh-switch-ui{width:36px;height:20px;border-radius:999px;background:var(--border);position:relative;transition:background .2s;flex-shrink:0}
.sh-switch-ui::after{content:"";position:absolute;top:2px;inset-inline-start:2px;width:16px;height:16px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:inset-inline-start .2s}
.sh-switch input:checked + .sh-switch-ui{background:var(--c-short)}
.sh-switch input:checked + .sh-switch-ui::after{inset-inline-start:18px}
.sh-switch input:focus-visible + .sh-switch-ui{outline:2px solid var(--royal-blue);outline-offset:2px}
.sh-factions{display:flex;gap:8px;margin-top:12px;justify-content:flex-end}

/* ═══ الجداول ═══ */
.sh-cap{font-size:12px;color:var(--muted);margin:-6px 0 12px;line-height:1.7}
.sh th[data-tip]{cursor:help;white-space:nowrap}
.sh th[data-tip]::after{content:"ⓘ";font-size:10.5px;opacity:.55;margin-inline-start:4px;font-weight:700}
.sh th[data-tip]:focus-visible{outline:2px solid var(--royal-blue);outline-offset:-2px}
.sh-table thead th.g-cov{box-shadow:inset 0 -3px 0 var(--c-cov)}
.sh-table thead th.g-stk{box-shadow:inset 0 -3px 0 var(--c-free)}
.sh-table thead th.g-res,.sh-table thead th.g-cvr{box-shadow:inset 0 -3px 0 var(--c-short)}
.sh-table table{font-size:12.5px}
.sh-table th,.sh-table td{padding-inline:8px}
.sh-prod b{display:block;max-width:240px;white-space:normal;line-height:1.45}
.sh-code{font-size:10.5px;color:var(--muted);margin-inline-end:6px}
.sh-sub{display:block;font-size:10.5px;color:var(--muted);font-weight:600;white-space:nowrap}
.sh-drill{display:inline-block;font-size:11px;font-weight:800;color:var(--royal-blue);margin-top:4px;text-decoration:none}
.sh-drill:hover{text-decoration:underline;text-underline-offset:3px}
tr.is-short > td{background:#FFF6F6}
tr.is-short .sh-short{color:#B91C1C;font-weight:900}
tr.is-picked > td{background:var(--blue-050)}
.sh-pcell{display:flex;flex-direction:column-reverse;gap:4px;align-items:flex-start}
.sh-pbar{width:64px;height:7px;border-radius:999px;background:var(--card2);overflow:hidden;display:inline-block}
.sh-pbar i{display:block;height:100%;border-radius:inherit}
.sh-pbar.sh-ok i{background:var(--c-free)} .sh-pbar.sh-warn i{background:var(--c-warn)} .sh-pbar.sh-bad i{background:var(--c-short)}
.sh-pnum{font-size:11.5px;font-weight:800}
.sh-where{min-width:150px;max-width:230px}
.sh-where summary{list-style:none;cursor:pointer;display:flex;flex-wrap:wrap;gap:4px}
.sh-where summary::-webkit-details-marker{display:none}
.sh-where summary:focus-visible{outline:2px solid var(--royal-blue);border-radius:6px}
.whc{display:inline-block;font-size:10.5px;background:#ECFDF3;color:#0F5F2E;border:1px solid #BBE5CB;border-radius:6px;padding:1px 7px;white-space:nowrap;max-width:200px;overflow:hidden;text-overflow:ellipsis}
.whc-none{background:var(--card2);color:var(--muted);border-color:var(--border)}
.bt{margin-top:6px;font-size:10.5px;border-collapse:collapse;width:100%}
.bt th,.bt td{padding:3px 5px;border-bottom:1px solid var(--border);text-align:start;white-space:nowrap;background:transparent !important;color:var(--text) !important;font-weight:600;position:static !important}
.bt th{color:var(--muted) !important;font-weight:800}

/* ═══ الشارات ═══ */
.chip{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:800;padding:4px 11px;border-radius:999px;text-decoration:none;white-space:normal;line-height:1.4}
.chip-ok{background:#E7F7EE;color:#0F7A38}
.chip-bad{background:#FDECEC;color:#B91C1C}
.chip-warn{background:#FFF4E5;color:#9A4F00}
.chip-wait{background:#FFF8E1;color:#7A5300}
.chip-info{background:var(--blue-050);color:var(--royal-blue)}
.chip-xs{font-size:10px;padding:1px 8px;vertical-align:middle}
a.chip:hover{filter:brightness(.97)}
.sh-state{min-width:200px;max-width:320px}
.sh-miss{display:flex;flex-wrap:wrap;gap:4px;margin-top:5px}
.sh-miss span{font-size:10.5px;background:var(--card);border:1px solid #F5C2C2;color:#7F1D1D;border-radius:6px;padding:1px 7px;white-space:normal}
.sh-strip{display:flex;flex-wrap:wrap;gap:6px 16px;font-size:12.5px;background:var(--card2);border-radius:10px;padding:9px 12px;margin-bottom:12px;align-items:center}
.sh-strip span{display:inline-flex;align-items:center;gap:6px}
.sh-strip .is-bad b{color:#B91C1C}

/* ═══ الحالات الفاضية ═══ */
.sh-empty{display:flex;flex-direction:column;align-items:center;gap:6px;text-align:center;padding:26px 16px;color:var(--muted);font-size:13px}
.sh-empty b{color:var(--text);font-size:14.5px}
.sh-empty-ic{font-size:30px;line-height:1}
.sh-empty .btn{margin-top:6px}

/* ═══ التولتيب ═══ */
.sh-tip{position:fixed;z-index:200;max-width:270px;background:#12121A;color:#F5F5F7;font-size:12px;line-height:1.7;padding:8px 11px;border-radius:9px;box-shadow:0 8px 24px rgba(10,10,15,.25);pointer-events:none;white-space:normal}

@media (max-width:1100px){.sh-two{grid-template-columns:1fr}}
@media (max-width:900px){.sh-fgroups{grid-template-columns:1fr}}
@media (max-width:640px){
    .sh-hero{padding:14px 12px 12px}
    .sh-q{font-size:15px}
    .cmp-row{grid-template-columns:1fr auto;gap:4px 10px}
    .cmp-l{grid-column:1 / -1}
    .cmp-n{font-size:17px}
    .sh-cover{flex-direction:column;align-items:stretch;gap:8px}
    .sh-cover-num{flex-direction:row;gap:8px;align-items:baseline;min-width:0}
    .sh-cover-num b{font-size:26px}
    .pc-h{flex-direction:column;gap:2px}
    .sh-factions .btn{flex:1;text-align:center}
}
@media (prefers-reduced-motion:reduce){.sh-switch-ui,.sh-switch-ui::after{transition:none}}
</style>

<script>
(function () {
    // تولتيب عناوين الأعمدة: هوفر بالماوس، فوكس بالكيبورد، ولمسة على الموبايل
    var tip = document.getElementById('shTip');
    if (!tip) return;
    var cur = null;
    function show(el) {
        cur = el;
        tip.textContent = el.getAttribute('data-tip');
        tip.hidden = false;
        var r = el.getBoundingClientRect(), w = tip.offsetWidth, h = tip.offsetHeight;
        var x = Math.min(Math.max(8, r.left + r.width / 2 - w / 2), window.innerWidth - w - 8);
        var y = r.bottom + 8;
        if (y + h > window.innerHeight - 8) y = r.top - h - 8;
        tip.style.left = x + 'px';
        tip.style.top = y + 'px';
    }
    function hide() { cur = null; tip.hidden = true; }
    document.querySelectorAll('.sh th[data-tip]').forEach(function (th) {
        th.addEventListener('mouseenter', function () { show(th); });
        th.addEventListener('mouseleave', hide);
        th.addEventListener('focus', function () { show(th); });
        th.addEventListener('blur', hide);
        th.addEventListener('touchstart', function () { cur === th ? hide() : show(th); }, {passive: true});
    });
    window.addEventListener('scroll', hide, true);
})();
</script>
@endsection
