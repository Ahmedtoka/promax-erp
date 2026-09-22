@extends('layouts.system')

@section('title', __('stock.pick_orders'))

@php
    $fmt = fn ($n) => number_format((float) $n);
    // ⚠️ **أمين المخزن لازم يشوف الأزرار دي — دي شغله.** كانت
    // `isManager()` وهو مش منهم، فالراوتس اتديتله والأزرار اتخبّت
    // عنه: مخزن للقراية بس.
    $manager = auth()->user()->canWorkWarehouse();

    $statusFilter = (string) ($filters['status'] ?? '');
    $repFilter = (string) ($filters['rep'] ?? '');
    $whFilter = (string) ($filters['warehouse'] ?? '');

    // ترتيب الحالات في الفلتر — نفس ترتيب الفلو في الموديل
    $statusOptions = [
        'open' => __('stock.open_only'),
        'requested' => __('stock.pick_status_requested'),
        'picking' => __('stock.pick_status_picking'),
        'ready' => __('stock.pick_status_ready'),
        'handed' => __('stock.pick_status_handed'),
        'cancelled' => __('stock.pick_status_cancelled'),
    ];
@endphp

@section('actions')
    <a class="btn" href="{{ route('wh.index') }}">🏭 {{ __('stock.warehouse_overview') }}</a>
    @if ($manager)
        {{-- ⚠️ **زرار «أمر تجهيز جديد» اتشال** (2026-08-08).
             الأمر بيتخلق من «تسليم عهدة» أو من موافقة الحسابات على
             أمر توريد — والشاشة دي بقت تنفيذ وعرض بس. --}}
        {{-- ⚠️ **`ops.handout` مش `wh.handout`.** شاشة تسليم العهدة
             ساكنة في مجموعة `ops.` رغم إن أمين المخزن هو اللي
             بيشغّلها — والاسم بيتبع مكان الراوت مش مين بيستخدمه. --}}
        <a class="btn" href="{{ route('ops.handout') }}">📦 {{ __('stock.go_handout') }}</a>
        {{-- ⚠️ أمر التوريد `role:admin,manager` — أمين المخزن الراوت
             بيرفضه، فاللينك لازم يتخفي عنه بدل ما يدوس وياخد 403 --}}
        @if (\App\Support\Access::allows(auth()->user(), 'ops.po.handout'))
            <a class="btn" href="{{ route('ops.po.handout') }}">🚚 {{ __('stock.go_po') }}</a>
        @endif
    @endif
@endsection

@section('content')

{{-- كروت الحالات فلاتر بضغطة (٢٢/٩): الأمين عايز «إيه اللي مستنيني» قبل التاريخ كله.
     العدادات جوه باقي الفلاتر (مخزن/مندوب/فترة/بحث) — ومجموعها = إجمالي الصفوف من غير فلتر حالة. --}}
@php
    $sc = fn ($k) => (int) ($statusCounts[$k] ?? 0);
    $scAll = (int) collect($statusCounts)->sum();
    $scOpen = $sc('requested') + $sc('picking') + $sc('ready');
    $scLink = fn ($st) => route('wh.picks', array_filter(['status' => $st] + request()->except(['status', 'page', 'export'])));
    $scMeta = ['requested' => 'mid', 'picking' => 'mid', 'ready' => '', 'handed' => 'pos', 'cancelled' => 'neg'];
@endphp
<div class="kpis">
    <a class="kpi {{ $statusFilter === 'open' ? 'on' : '' }}" href="{{ $scLink($statusFilter === 'open' ? null : 'open') }}" title="{{ __('ui.click_to_filter') }}">
        <div class="lbl">⚡ {{ __('uid.pk_open') }}</div>
        <div class="val {{ $scOpen ? 'mid' : 'pos' }}">{{ $scOpen }}</div>
        <div class="sub2"><span dir="ltr">{{ $scOpen }} = {{ $sc('requested') }} + {{ $sc('picking') }} + {{ $sc('ready') }}</span><br>{{ __('stock.pick_status_requested') }} + {{ __('stock.pick_status_picking') }} + {{ __('stock.pick_status_ready') }}</div>
    </a>
    @foreach ($scMeta as $k => $cls)
        <a class="kpi {{ $statusFilter === $k ? 'on' : '' }}" href="{{ $scLink($statusFilter === $k ? null : $k) }}" title="{{ __('ui.click_to_filter') }}">
            <div class="lbl">{{ $statusOptions[$k] ?? $k }}</div>
            <div class="val {{ $cls }}">{{ $sc($k) }}</div>
            <div class="sub2">{{ __('uid.pk_'.$k) }}</div>
        </a>
    @endforeach
</div>
<div class="sub2" style="margin:-6px 4px 14px;font-size:11.5px;color:var(--muted)">
    {{ __('uid.pk_total') }} <b dir="ltr">{{ $scAll }} = {{ $sc('requested') }} + {{ $sc('picking') }} + {{ $sc('ready') }} + {{ $sc('handed') }} + {{ $sc('cancelled') }}</b>
    — {{ __('uid.pk_range_note') }}
</div>

<div class="card">
    <h3>🧺 {{ __('stock.pick_orders') }}
        <span class="side">{{ __('stock.pick_open_count', ['count' => $openCount]) }}</span></h3>

    <form class="searchbar" method="GET">
        <label class="fl grow"><span>{{ __('ui.l_search') }}</span>
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="🔍 {{ __('uid.pk_search_ph') }}"></label>
        <label class="fl"><span>{{ __('ui.l_status') }}</span>
        <select name="status">
            <option value="">{{ __('stock.all_statuses') }}</option>
            @foreach ($statusOptions as $k => $lbl)
                <option value="{{ $k }}" @selected($statusFilter === $k)>{{ $lbl }}</option>
            @endforeach
        </select></label>
        <label class="fl wide"><span>{{ __('ui.l_rep') }}</span>
        <select name="rep">
            <option value="">{{ __('ops.all_reps') }}</option>
            @foreach ($reps as $r)
                <option value="{{ $r->id }}" @selected($repFilter === (string) $r->id)>{{ $r->name }}</option>
            @endforeach
        </select></label>
        <label class="fl"><span>{{ __('ui.l_warehouse') }}</span>
        <select name="warehouse">
            <option value="">{{ __('stock.all_warehouses') }}</option>
            @foreach ($warehouses as $w)
                <option value="{{ $w->id }}" @selected($whFilter === (string) $w->id)>{{ $w->displayName() }}</option>
            @endforeach
        </select></label>
        {{-- فلتر «من — إلى» على موعد التحميل `pickup_at` (٩/٩/٢٠٢٦) --}}
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue()])
        <button class="btn gold" type="submit">{{ __('common.search') }}</button>
        <a class="btn" href="{{ route('wh.picks') }}">{{ __('common.clear') }}</a>
        {{-- القايمة صفحات — ده بينزّل كل النتيجة المفلترة (٢٢/٩) --}}
        <a class="btn sm green" href="{{ request()->fullUrlWithQuery(['export' => 1, 'page' => null]) }}">⬇ {{ __('ui.export_all') }}</a>
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('stock.pick_order') }}</th>
                <th>{{ __('stock.warehouse') }}</th>
                <th>{{ __('ops.rep') }}</th>
                <th>{{ __('stock.pick_purpose') }}</th>
                <th data-nosum>{{ __('stock.pickup_at') }}</th>
                <th>{{ __('stock.qty_requested') }}</th>
                <th>{{ __('stock.qty_picked') }}</th>
                <th>{{ __('stock.qty_received_col') }}</th>
                <th>{{ __('common.status') }}</th>
                <th></th>
            </tr>
            @forelse ($orders as $o)
                <tr class="clickable" onclick="location.href='{{ route('wh.picks.show', $o) }}'">
                    <td class="num"><a href="{{ route('wh.picks.show', $o) }}"><b>{{ $o->number }}</b></a>
                        <br><span style="font-size:10.5px;color:var(--muted)">{{ $o->created_at?->format('Y-m-d') ?? '—' }}</span>
                    </td>
                    <td>@if ($o->warehouse)<a href="{{ route('erp.warehouses.stock', $o->warehouse) }}" onclick="event.stopPropagation()">{{ $o->warehouse->displayName() }}</a>@else — @endif</td>
                    <td>@if ($o->rep)<a href="{{ route('ops.rep', $o->rep) }}" onclick="event.stopPropagation()">{{ $o->rep->displayName() }}</a>@else — @endif</td>
                    <td>
                        {{-- ⚠️ **الفرق بين عهدة وتوريد لازم يبان من نظرة**
                             (2026-08-08). كل الأوامر كانت بنفس الشكل
                             والبادج البنفسجي، وأمين المخزن مايعرفش
                             البضاعة دي رايحة عربية ولا فرع كي أكاونت. --}}
                        @if ($o->purchase_order_id)
                            <span class="badge b-purple">🚚 {{ __('stock.pick_purpose_customer_po') }}</span>
                            {{-- رقم أمر التوريد بيفتحه، والعميل بيفتح كارته (٢٢/٩) --}}
                            <div style="font-size:10.5px;color:var(--muted)">
                                @if ($o->purchaseOrder)<a href="{{ route('ops.pos.show', $o->purchaseOrder) }}" onclick="event.stopPropagation()" dir="ltr">{{ $o->purchaseOrder->number }}</a> ·@endif
                                @if ($o->purchaseOrder?->client)<a href="{{ route('erp.clients.show', $o->purchaseOrder->client) }}" onclick="event.stopPropagation()">{{ $o->purchaseOrder->client->displayName() }}</a>@else — @endif
                            </div>
                        @else
                            <span class="badge b-blue">📦 {{ __('stock.pick_purpose_van_load') }}</span>
                        @endif
                    </td>
                    <td>
                        {{-- موعد وصول المندوب المخزن --}}
                        @if ($o->pickup_at)
                            <span dir="ltr" style="font-weight:800;font-size:11.5px;
                                {{ $o->pickup_at->isPast() && $o->isOpen() ? 'color:#B00020' : '' }}">
                                {{ $o->pickup_at->format('d/m h:i A') }}
                            </span>
                        @elseif ($o->needed_on)
                            <span dir="ltr" style="font-size:11.5px;color:var(--muted)">
                                {{ $o->needed_on->format('d/m') }}
                            </span>
                        @else
                            <span class="side">—</span>
                        @endif

                        {{-- وللتوريد: معاد تسليم الفرع كمان --}}
                        @if ($o->purchaseOrder?->due_at)
                            <div dir="ltr" style="font-size:10px;color:#7C3AED">
                                🚚 {{ $o->purchaseOrder->due_at->format('d/m h:i A') }}
                            </div>
                        @endif
                    </td>
                    <td class="num">{{ $fmt($o->qtyRequested()) }}</td>
                    <td class="num pos">{{ $fmt($o->qtyPicked()) }}</td>
                    <td class="num">{{ $fmt($o->qtyReceived()) }}</td>
                    <td><span class="badge {{ $o->statusClass() }}">{{ $o->statusLabel() }}</span></td>
                    <td>
                        @if ($o->has_variance)
                            <span class="badge b-red">⚠️ {{ __('stock.variance') }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="10" style="text-align:center;color:var(--muted);padding:28px">
                    {{ __('stock.no_picks') }}
                </td></tr>
            @endforelse
            {{-- إجمالي كل النتيجة المفلترة من السيرفر — مش الصفحة (٢٢/٩) --}}
            @if ($orders->total() > 0)
                <tfoot><tr>
                    <td colspan="5"><b>{{ __('common.total') }}</b> <span class="s" style="color:var(--muted)">({{ __('ui.rows_n', ['n' => $orders->total()]) }})</span></td>
                    <td class="num"><b>{{ $fmt($totals->requested) }}</b></td>
                    <td class="num"><b>{{ $fmt($totals->picked) }}</b></td>
                    <td class="num"><b>{{ $fmt($totals->received) }}</b></td>
                    <td colspan="2"></td>
                </tr></tfoot>
            @endif
        </table>
    </div>
    @include('partials._pagination', ['p' => $orders])
</div>

@endsection
