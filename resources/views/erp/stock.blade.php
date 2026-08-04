@extends('layouts.system')

@section('title', __('stock.inventory'))

@php
    $fmt = fn ($n) => number_format((float) $n);
    // ⚠️ **مدير الفرع مش هنا.** الراوتس دي `role:admin,manager`،
    // و`isManager()` بترجّع له true — فكان بيشوف الزرار ويترمي على
    // 403 بعد ما يملا الفورم.
    $manager = auth()->user()->canDecideOps();

    // ⚠️ **التكلفة والهامش مش لأمين المخزن.** هو بيشوف كميات ويحرّك
    // بضاعة — التكلفة وهامش الربح بيانات تجارية، ومعرفتها بتخلّي
    // معلومة زي «الصنف ده بنكسب فيه 40%» تخرج من الشركة من غير سبب.
    // الشاشة دي في خريطته عشان يشوف الأرصدة، مش الفلوس.
    $seeCost = ! auth()->user()->isWarehouseKeeper();

    // ⚠️ فرق مهم: الـ KPIs فوق بتيجي من الكنترولر على المخزن **كله**
    // ($totalVal / $costVal)، أما دول تحت فعلى **المفلتر** بس ودورهم
    // إجماليات الجدول. ممنوع تخلط الاتنين في نفس المعادلة.
    $costValF = $products->sum(fn ($p) => $p->qtyTotal() * (float) $p->cost);
    $newValF = $products->sum(fn ($p) => $p->qtyTotal() * $p->sellingPrice());

    // تلوين الهامش: فوق 25% أخضر، من 10 لـ 25 برتقالي، أقل من 10 أحمر
    $mgCls = fn ($m) => $m > 0.25 ? 'pos' : ($m >= 0.10 ? 'mid' : 'neg');
@endphp

@section('actions')
    @if ($manager)<button class="btn gold" onclick="openDlg('dlgNewP')">+ {{ __('stock.new_item') }}</button>@endif
@endsection

@section('content')

<div class="kpis">
    <div class="kpi"><div class="lbl">{{ __('stock.stock_value_new') }}</div><div class="val" style="color:var(--primary)">{{ $fmt($totalVal) }} {{ __('common.currency') }}</div><div class="sub2">{{ __('stock.sku_countable', ['count' => $skuCount]) }}</div></div>
    @if ($seeCost)
        <div class="kpi"><div class="lbl">{{ __('stock.stock_value_cost') }}</div><div class="val">{{ $fmt($costVal) }} {{ __('common.currency') }}</div><div class="sub2">{{ __('stock.margin') }} {{ number_format(($totalVal - $costVal) / max($totalVal, 1) * 100, 1) }}%</div></div>
    @endif
    <div class="kpi"><div class="lbl">{{ __('stock.total_units') }}</div><div class="val">{{ $fmt($totalQty) }}</div><div class="sub2">{{ __('stock.finished_goods') }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('stock.good_stock_value') }}</div><div class="val pos">{{ $fmt($goodVal) }} {{ __('common.currency') }}</div><div class="sub2">{{ number_format($goodVal / max($totalVal, 1) * 100, 1) }}%</div></div>
    <div class="kpi"><div class="lbl">{{ __('stock.hold_value') }}</div><div class="val mid">{{ $fmt($holdVal) }} {{ __('common.currency') }}</div><div class="sub2">{{ __('stock.pct_on_hold', ['pct' => number_format($holdVal / max($totalVal, 1) * 100, 1)]) }}</div></div>
</div>

<div class="grid2">
    <div class="card"><h3>{{ __('stock.value_by_family') }}</h3><div class="chartbox"><canvas id="chFam"></canvas></div></div>
    <div class="card">
        <h3>{{ __('stock.family_summary') }}</h3>
        <div class="tablewrap">
            <table>
                <tr><th>{{ __('stock.family') }}</th><th>{{ __('stock.skus') }}</th><th>{{ __('stock.units') }}</th><th>{{ __('stock.value') }}</th><th>{{ __('stock.of_which_hold') }}</th></tr>
                @foreach ($famStats as $fam => $f)
                    <tr>
                        <td><b>{{ __('enums.family.'.$fam) }}</b></td>
                        <td class="num">{{ $f['n'] }}</td>
                        <td class="num">{{ $fmt($f['qty']) }}</td>
                        <td class="num">{{ $fmt($f['val']) }}</td>
                        <td class="num mid">{{ $fmt($f['hold']) }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    </div>
</div>

<div class="card">
    <h3>📦 {{ __('stock.finished_goods_inventory') }} <span class="side">{{ __('stock.cost_and_lists') }}</span></h3>
    <form class="searchbar" method="GET">
        <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="🔍 {{ __('stock.search_item') }}">
        <select name="family">
            <option value="">{{ __('stock.all_families') }}</option>
            @foreach ($families as $k => $v)<option value="{{ $k }}" @selected(($filters['family'] ?? '') === $k)>{{ __('enums.family.'.$k) }}</option>@endforeach
        </select>
        <button class="btn gold" type="submit">{{ __('common.search') }}</button>
        <a class="btn" href="{{ route('erp.stock') }}">{{ __('common.clear') }}</a>
    </form>
    <div class="tablewrap">
        <table>
            <tr>
                <th style="width:44px"></th>
                <th>{{ __('common.code') }}</th><th>{{ __('stock.item') }}</th><th>{{ __('stock.family') }}</th><th>{{ __('stock.unit') }}</th>
                @if ($seeCost)<th>{{ __('stock.cost') }}</th>@endif
                <th>{{ __('stock.price_old') }}</th><th>{{ __('stock.price_new') }}</th>
                @if ($seeCost)<th>{{ __('stock.margin_pct') }}</th>@endif
                <th>{{ __('stock.qty') }}</th>
                {{-- ⚠️ **عمود لكل مخزن.** «عندنا كام؟» مالهاش معنى من
                     غير «فين؟» — رقم إجمالي 400 كرتونة كله في العاشر
                     معناه إن المعادي فاضي والمندوب اللي بيحمّل من هناك
                     مش هيلاقي حاجة. --}}
                @foreach ($warehouses as $wh)
                    <th style="white-space:nowrap">{{ $wh->displayName() }}</th>
                @endforeach
                <th>{{ __('stock.hold') }}</th><th>{{ __('stock.good_stock') }}</th><th>{{ __('stock.value') }}</th>
                @if ($manager)<th></th>@endif
            </tr>
            @foreach ($products as $p)
                @php
                    $margin = $p->marginPct();
                    $delta = $p->priceDeltaPct();
                @endphp
                <tr>
                    {{-- ⚠️ صورة مصغّرة — اللي بيدوّر على صنف في 23 سطر
                         بيلاقيه بعينه أسرع من ما يقرا 23 اسم متشابه
                         («بار بروتين كوكيز» / «بار بروتين قهوة»). --}}
                    <td>
                        {{-- كبيرة (60px) — قرار المالك 2026-08-04: الصورة ريفرنس أساسي مش زينة --}}
                        @if ($p->imageSrc())
                            <img src="{{ $p->imageSrc() }}" alt="" loading="lazy"
                                 style="width:72px;height:72px;object-fit:contain;border-radius:10px;
                                        border:1px solid var(--border);background:#fff">
                        @else
                            <div style="width:72px;height:72px;border-radius:10px;border:1px dashed var(--border);
                                        display:flex;flex-direction:column;align-items:center;justify-content:center;
                                        gap:2px;color:var(--muted)">
                                <span style="font-size:18px">📦</span>
                                <span style="font-size:8.5px;font-weight:800;letter-spacing:.4px">NO IMAGE</span>
                            </div>
                        @endif
                    </td>
                    <td class="num">
                        <a href="{{ route('erp.products.show', $p) }}" style="font-weight:700">{{ $p->code }}</a>
                    </td>
                    <td><b>{{ $p->displayName() }}</b></td>
                    <td><span class="badge b-gray">{{ $p->familyLabel() }}</span></td>
                    <td style="color:var(--muted);font-size:11.5px">{{ $p->unitLabel() }}</td>
                    @if ($seeCost)<td class="num" style="color:var(--muted)">{{ number_format($p->cost, 2) }}</td>@endif
                    <td class="num">{{ number_format($p->price_old, 2) }}</td>
                    <td class="num">
                        <span style="color:var(--primary);font-weight:800">{{ number_format($p->price_new, 2) }}</span>
                        @if ($p->priceChanged())
                            @php $up = (float) $p->price_new > (float) $p->price_old; @endphp
                            <br><span class="badge {{ $up ? 'b-green' : 'b-red' }}" style="font-size:10px">
                                {{ __('stock.price_changed') }}@if ($delta != 0) {{ $delta > 0 ? '+' : '' }}{{ number_format($delta * 100, 1) }}%@endif
                            </span>
                        @endif
                    </td>
                    @if ($seeCost)<td class="num {{ $mgCls($margin) }}"><b>{{ number_format($margin * 100, 1) }}%</b></td>@endif
                    <td class="num"><b>{{ $fmt($p->qtyTotal()) }}</b>
                        {{-- التجميعة: «3 كرتونة + 1 علبة + 5 قطعة» — عرض بس، المخزون قطع --}}
                        @if ($bd = $p->packBreakdown($p->qtyTotal()))
                            <div style="font-size:10px;color:var(--muted);white-space:nowrap">{{ $bd }}</div>
                        @endif
                    </td>
                    @foreach ($warehouses as $wh)
                        @php $wq = $p->qtyIn($wh); @endphp
                        <td class="num" @class(['muted' => $wq === 0])>{{ $wq === 0 ? '—' : $fmt($wq) }}
                            @if ($wq > 0 && ($wbd = $p->packBreakdown($wq)))
                                <div style="font-size:10px;color:var(--muted);white-space:nowrap">{{ $wbd }}</div>
                            @endif
                        </td>
                    @endforeach
                    <td class="num mid">{{ $fmt($p->holdTotal()) }}</td>
                    <td class="num">{{ $fmt($p->goodTotal()) }}</td>
                    <td class="num pos">{{ $fmt($p->qtyTotal() * $p->sellingPrice()) }}</td>
                    @if ($manager)
                        {{-- ⚠️ **لينك للكارت مش مودال.** المودال كان
                             بـ12 حقل من 24 عمود، والكارت بيوري التعريف
                             كامل والمخزون والباتشات ومين بيشتريه. --}}
                        <td><a class="btn sm" href="{{ route('erp.products.show', $p) }}">{{ __('stock.product_card') }} ←</a></td>
                    @endif
                </tr>
            @endforeach
            <tr>
                {{-- ⚠️ **5 مش 4** — عمود الصورة اتضاف على الشمال.
                     الـcolspan اللي مش متظبط بيزحزح كل أرقام صف
                     الإجمالي عمود، فالكمية بتظهر تحت «القيمة». --}}
                <td colspan="5"><b>{{ __('common.total') }}</b></td>
                @if ($seeCost)<td class="num"><b>{{ $fmt($costValF) }}</b></td>@endif
                <td></td>
                {{-- العمود ده سعر وحدة، فمفيش إجمالي معنى له. الإجمالي في عمود القيمة --}}
                <td></td>
                @if ($seeCost)
                    <td class="num {{ $mgCls($newValF > 0 ? ($newValF - $costValF) / $newValF : 0) }}">
                        <b>{{ number_format(($newValF - $costValF) / max($newValF, 1) * 100, 1) }}%</b>
                    </td>
                @endif
                <td class="num"><b>{{ $fmt($products->sum(fn ($p) => $p->qtyTotal())) }}</b></td>
                @foreach ($warehouses as $wh)
                    <td class="num"><b>{{ $fmt($products->sum(fn ($p) => $p->qtyIn($wh))) }}</b></td>
                @endforeach
                <td class="num mid"><b>{{ $fmt($products->sum(fn ($p) => $p->holdTotal())) }}</b></td>
                <td class="num"><b>{{ $fmt($products->sum(fn ($p) => $p->goodTotal())) }}</b></td>
                <td class="num pos"><b>{{ $fmt($newValF) }}</b></td>
                @if ($manager)<td></td>@endif
            </tr>
        </table>
    </div>
</div>

{{-- ⚠️ **الفورم بقى partial مشترك.** كان مكتوب هنا وفي كارت
     الصنف، بـ12 حقل بس من 24 عمود — يعني نص التعريف (الباركودات،
     الوزن، البراند، مدة الصلاحية، كود المصلحة) ماكانش ليه أي واجهة.
     والمكتوب مرتين معناه إن العمود الجديد بيتضاف في شاشة ويتنسى في
     التانية. --}}
@if ($manager)
    @include('erp._product_form', ['p' => null, 'families' => $families])
@endif

@endsection

@section('scripts')
@php
    $famLabels = array_map(fn ($f) => __('enums.family.'.$f), array_keys($famStats));
    $famValues = array_map(fn ($f) => round($f['val']), array_values($famStats));
@endphp
<script>
new Chart(document.getElementById('chFam'), {
    type:'doughnut',
    data:{ labels:{!! json_encode($famLabels, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) !!},
           datasets:[{ data:{!! json_encode($famValues) !!}, backgroundColor:PALETTE, borderColor:'#fff', borderWidth:3, hoverOffset:6 }] },
    options:{ cutout:'58%', plugins:{ legend:{ position:'bottom' } } },
});

</script>
@endsection
