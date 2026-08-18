@extends('layouts.system')

{{--
    «المنتجات» — الصفحة الأم (تطوير 2026-08-06):

    السعر المعروض واحد بس: **سعر القايمة الافتراضية** — مفيش
    قديم/جديد في الشاشة. شارتات (قيمة بالعائلة + وحدات بالعائلة +
    توزيع المخازن) + ملخص عائلات ببار نسبة القيمة + جدول بهيدر
    ثابت وبار حصة كل صنف من الوحدات وفلاتر وترتيب.
--}}
@section('title', __('nav.inventory'))

@php
    $fmt = fn ($n) => number_format((float) $n);
    // ⚠️ **مدير الفرع مش هنا.** الراوتس دي `role:admin,manager`،
    // و`isManager()` بترجّع له true — فكان بيشوف الزرار ويترمي على
    // 403 بعد ما يملا الفورم.
    $manager = auth()->user()->canDecideOps();

    // ⚠️ **التكلفة والهامش مش لأمين المخزن.** هو بيشوف كميات ويحرّك
    // بضاعة — التكلفة وهامش الربح بيانات تجارية، ومعرفتها بتخلّي
    // معلومة زي «الصنف ده بنكسب فيه 40%» تخرج من الشركة من غير سبب.
    $seeCost = ! auth()->user()->isWarehouseKeeper();

    // السعر الواحد: سعر القايمة الافتراضية — نفس مصدر الكنترولر
    $priceOf = fn ($p) => \App\Services\Pricing::listPrice($p, $defaultList);

    // ⚠️ فرق مهم: الـ KPIs فوق من الكنترولر على المخزن **كله**، أما
    // دول تحت فعلى **المفلتر** بس ودورهم إجماليات الجدول.
    $costValF = $products->sum(fn ($p) => $p->qtyTotal() * (float) $p->cost);
    $newValF = $products->sum(fn ($p) => $p->qtyTotal() * $priceOf($p));
    $totalQtyF = max($products->sum(fn ($p) => $p->qtyTotal()), 1);

    // تلوين الهامش: فوق 25% أخضر، من 10 لـ 25 برتقالي، أقل من 10 أحمر
    $mgCls = fn ($m) => $m > 0.25 ? 'pos' : ($m >= 0.10 ? 'mid' : 'neg');
    $f = $filters;
@endphp

@section('actions')
    @if (\App\Support\Access::action(auth()->user(), 'act.products.edit'))<button class="btn gold" onclick="openDlg('dlgNewP')">+ {{ __('stock.new_item') }}</button>@endif
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

{{-- ═══ الشارتات: القيمة والوحدات بالعائلة + توزيع المخازن ═══ --}}
<div class="card">
    <h3>📊 {{ __('nav.inventory') }} — {{ __('report.overview') }}</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:18px">
        <div>
            <div style="font-size:12px;font-weight:800;color:var(--muted);margin-bottom:6px">💰 {{ __('stock.value_by_family') }}</div>
            <div class="chartbox"><canvas id="chFam"></canvas></div>
        </div>
        <div>
            <div style="font-size:12px;font-weight:800;color:var(--muted);margin-bottom:6px">📦 {{ __('stock.units_by_family') }}</div>
            <div class="chartbox"><canvas id="chFamQty"></canvas></div>
        </div>
        <div>
            <div style="font-size:12px;font-weight:800;color:var(--muted);margin-bottom:6px">🏭 {{ __('stock.wh_distribution') }}</div>
            <div class="chartbox"><canvas id="chWh"></canvas></div>
        </div>
    </div>
</div>

{{-- ═══ ملخص العائلات — ببار نسبة القيمة بالعين ═══ --}}
<div class="card">
    <h3>🧬 {{ __('stock.family_summary') }}</h3>
    <div class="tablewrap prod-tbl">
        <table>
            <tr><th style="text-align:start">{{ __('stock.family') }}</th><th>{{ __('stock.skus') }}</th><th>{{ __('stock.units') }}</th><th>{{ __('stock.value') }}</th><th style="width:200px">{{ __('stock.value_share') }}</th><th>{{ __('stock.of_which_hold') }}</th></tr>
            @foreach ($famStats as $fam => $fs)
                @php $share = (int) round($fs['val'] / max($totalVal, 1) * 100); @endphp
                <tr>
                    <td style="text-align:start"><b>{{ \App\Models\ProductFamily::label($fam) }}</b></td>
                    <td class="num">{{ $fs['n'] }}</td>
                    <td class="num">{{ $fmt($fs['qty']) }}</td>
                    <td class="num"><b>{{ $fmt($fs['val']) }}</b></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px">
                            <div style="flex:1;height:9px;border-radius:6px;background:var(--card2, #eee);overflow:hidden;border:1px solid var(--border)">
                                <div style="height:100%;width:{{ $share }}%;background:var(--brand-gradient, var(--royal-blue));border-radius:6px"></div>
                            </div>
                            <span style="font-size:10.5px;font-weight:800" dir="ltr">{{ $share }}%</span>
                        </div>
                    </td>
                    <td class="num mid">{{ $fmt($fs['hold']) }}</td>
                </tr>
            @endforeach
        </table>
    </div>
</div>

<div class="card">
    <h3>📦 {{ __('stock.finished_goods_inventory') }}
        <span class="side">{{ __('stock.price_from_default_list') }}@if ($defaultList) — {{ $defaultList->displayName() }}@endif</span></h3>
    <form class="searchbar" method="GET">
        <input type="text" name="q" value="{{ $f['q'] ?? '' }}" placeholder="🔍 {{ __('stock.search_item') }}">
        <select name="family">
            <option value="">{{ __('stock.all_families') }}</option>
            @foreach ($families as $k => $v)<option value="{{ $k }}" @selected(($f['family'] ?? '') === $k)>{{ $v }}</option>@endforeach
        </select>
        {{-- ⚠️ **فلتر الحالة** (١٧/٨) — الشاشة ماكانتش بتفرّق بين
             المفعّل والدرافت: مفيش فلتر ولا شارة ولا عمود. المالك
             أوقف صنف ومالقاش طريقة يلاقيه تاني غير إنه يفتح المنتجات
             واحد واحد. --}}
        <select name="status">
            <option value="" @selected(($f['status'] ?? '') === '')>{{ __('stock.all_statuses') }}</option>
            <option value="active" @selected(($f['status'] ?? '') === 'active')>{{ __('common.active') }}</option>
            <option value="draft" @selected(($f['status'] ?? '') === 'draft')>
                {{ __('stock.draft_only') }}@if (($draftCount ?? 0) > 0) ({{ $draftCount }})@endif
            </option>
        </select>
        <select name="sort">
            <option value="" @selected(($f['sort'] ?? '') === '')>{{ __('stock.sort_code') }}</option>
            <option value="qty" @selected(($f['sort'] ?? '') === 'qty')>{{ __('stock.sort_qty') }}</option>
            <option value="value" @selected(($f['sort'] ?? '') === 'value')>{{ __('stock.sort_value') }}</option>
        </select>
        <button class="btn gold" type="submit">{{ __('common.search') }}</button>
        <a class="btn" href="{{ route('erp.stock') }}">{{ __('common.clear') }}</a>
    </form>
    <div class="tablewrap prod-tbl" style="max-height:66vh;overflow-y:auto">
        <table>
            <thead>
            <tr>
                <th style="width:44px"></th>
                <th>{{ __('common.code') }}</th><th style="text-align:start">{{ __('stock.item') }}</th><th>{{ __('stock.family') }}</th><th>{{ __('stock.unit') }}</th>
                @if ($seeCost)<th>{{ __('stock.cost') }}</th>@endif
                {{-- سعر واحد بس — سعر القايمة الافتراضية (قرار المالك 2026-08-06) --}}
                <th>{{ __('stock.price_one') }}</th>
                @if ($seeCost)<th>{{ __('stock.margin_pct') }}</th>@endif
                <th style="min-width:150px">{{ __('stock.qty') }}</th>
                {{-- ⚠️ **عمود لكل مخزن.** «عندنا كام؟» مالهاش معنى من
                     غير «فين؟» --}}
                @foreach ($warehouses as $wh)
                    <th style="white-space:nowrap">{{ $wh->displayName() }}</th>
                @endforeach
                <th>{{ __('stock.hold') }}</th><th>{{ __('stock.good_stock') }}</th><th>{{ __('stock.value') }}</th>
                @if ($manager)<th></th>@endif
            </tr>
            </thead>
            <tbody>
            @foreach ($products as $p)
                @php
                    $price = $priceOf($p);
                    $margin = $price > 0 ? ($price - (float) $p->cost) / $price : 0;
                    $qty = $p->qtyTotal();
                    $qShare = (int) round($qty / $totalQtyF * 100);
                @endphp
                <tr>
                    <td>
                        {{-- كبيرة — قرار المالك 2026-08-04: الصورة ريفرنس أساسي مش زينة --}}
                        @if ($p->imageSrc())
                            <img src="{{ $p->imageSrc() }}" alt="" loading="lazy"
                                 style="width:120px;height:120px;object-fit:contain;border-radius:10px;
                                        border:1px solid var(--border);background:#fff">
                        @else
                            <div style="width:120px;height:120px;border-radius:10px;border:1px dashed var(--border);
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
                    <td style="text-align:start">
                        <b>{{ $p->displayName() }}</b>
                        {{-- ⚠️ **الشارة كانت موجودة في كارت المنتج بس**
                             — يعني عشان تعرف إن صنف موقوف كنت لازم
                             تفتحه. في القايمة كان شكله زي المفعّل
                             بالظبط، والمالك أوقف صنف ومالقاهوش. --}}
                        @unless ($p->active)
                            <span class="badge b-orange" style="margin-inline-start:6px">
                                ⏸ {{ __('stock.draft') }}</span>
                        @endunless
                    </td>
                    <td><span class="badge b-gray">{{ $p->familyLabel() }}</span></td>
                    <td style="color:var(--muted);font-size:11.5px">{{ $p->unitLabel() }}</td>
                    @if ($seeCost)<td class="num" style="color:var(--muted)">{{ number_format($p->cost, 2) }}</td>@endif
                    <td class="num"><span style="color:var(--primary);font-weight:800;font-size:13.5px">{{ number_format($price, 2) }}</span></td>
                    @if ($seeCost)<td class="num {{ $mgCls($margin) }}"><b>{{ number_format($margin * 100, 1) }}%</b></td>@endif
                    {{-- الكمية + بار حصة الصنف من إجمالي الوحدات — بالعين --}}
                    <td class="num"><b>{{ $fmt($qty) }}</b>
                        @if ($bd = $p->packBreakdown($qty))
                            <div style="font-size:10px;color:var(--muted);white-space:nowrap">{{ $bd }}</div>
                        @endif
                        @if ($qty > 0)
                            <div style="display:flex;align-items:center;gap:6px;margin-top:3px">
                                <div style="flex:1;height:6px;border-radius:4px;background:var(--card2, #eee);overflow:hidden">
                                    <div style="height:100%;width:{{ max($qShare, 2) }}%;background:var(--royal-blue, #12399B);border-radius:4px"></div>
                                </div>
                                <span style="font-size:9px;color:var(--muted)" dir="ltr">{{ $qShare }}%</span>
                            </div>
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
                    <td class="num pos"><b>{{ $fmt($qty * $price) }}</b></td>
                    @if ($manager)
                        <td><a class="btn sm" href="{{ route('erp.products.show', $p) }}">{{ __('stock.product_card') }} ←</a></td>
                    @endif
                </tr>
            @endforeach
            <tr>
                {{-- ⚠️ **5 مش 4** — عمود الصورة على الشمال. --}}
                <td colspan="5"><b>{{ __('common.total') }}</b></td>
                @if ($seeCost)<td class="num"><b>{{ $fmt($costValF) }}</b></td>@endif
                {{-- عمود السعر سعر وحدة — مفيش إجمالي ليه --}}
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
            </tbody>
        </table>
    </div>
</div>

{{-- ⚠️ **الفورم بقى partial مشترك** — التعريف كامل في مكان واحد --}}
@if ($manager)
    @include('erp._product_form', ['p' => null, 'families' => $families])
@endif

@endsection

@section('scripts')
<style>
.prod-tbl th, .prod-tbl td { text-align: center; vertical-align: middle; }
</style>
@php
    $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP;
    $famLabels = json_encode(array_map(fn ($fam) => \App\Models\ProductFamily::label($fam), array_keys($famStats)), $jsonFlags);
    $famValues = json_encode(array_map(fn ($fs) => round($fs['val']), array_values($famStats)));
    $famQtys = json_encode(array_map(fn ($fs) => (int) $fs['qty'], array_values($famStats)));
    $whLabels = json_encode(array_column($whStats, 'name'), $jsonFlags);
    $whQtys = json_encode(array_column($whStats, 'qty'));
    $whVals = json_encode(array_column($whStats, 'val'));
    $lblUnits = json_encode(__('stock.units'), $jsonFlags);
    $lblValue = json_encode(__('stock.value'), $jsonFlags);
@endphp
<script>
new Chart(document.getElementById('chFam'), {
    type:'doughnut',
    data:{ labels:{!! $famLabels !!},
           datasets:[{ data:{!! $famValues !!}, backgroundColor:PALETTE, borderColor:'#fff', borderWidth:3, hoverOffset:6 }] },
    options:{ cutout:'58%', plugins:{ legend:{ position:'bottom' } } },
});

new Chart(document.getElementById('chFamQty'), {
    type:'bar',
    data:{ labels:{!! $famLabels !!},
           datasets:[{ data:{!! $famQtys !!}, backgroundColor:'#602D90', borderRadius:6 }] },
    options:{ plugins:{ legend:{ display:false } }, scales:{ y:{ beginAtZero:true } } },
});

new Chart(document.getElementById('chWh'), {
    type:'bar',
    data:{ labels:{!! $whLabels !!}, datasets:[
        { label:{!! $lblUnits !!}, data:{!! $whQtys !!}, backgroundColor:'#12399B', borderRadius:6 },
        { label:{!! $lblValue !!}, data:{!! $whVals !!}, backgroundColor:'#D74297', borderRadius:6 },
    ]},
    options:{ plugins:{ legend:{ position:'bottom' } }, scales:{ y:{ beginAtZero:true } } },
});
</script>
@endsection
