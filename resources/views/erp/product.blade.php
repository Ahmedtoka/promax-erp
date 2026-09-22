@extends('layouts.system')

@section('title', $p->displayName())

@php
    use App\Models\Product;

    $fmt = fn ($n) => number_format((float) $n);
    $money = fn ($n) => number_format((float) $n, 2);

    // تلوين الهامش: فوق 25% أخضر، من 10 لـ 25 برتقالي، أقل من 10 أحمر
    $mgCls = fn ($m) => $m > 0.25 ? 'pos' : ($m >= 0.10 ? 'mid' : 'neg');

    // ⚠️ إجماليات محسوبة من كل المخازن — مش صف واحد.
    $qty = $p->qtyTotal();
    $margin = $p->marginPct();

    // ⚠️ حالة الصلاحية من **الباتشات** مش من مدة الصلاحية النظرية.
    // مدة الصلاحية بتقول «الصنف عمره 12 شهر»، والباتشات بتقول
    // «الموجود عندك دلوقتي بينتهي إمتى» — والتاني هو اللي بيقلق.
    $worst = $p->worstExpiryState();

    $stateCls = [
        'expired' => 'b-red', 'danger' => 'b-red',
        'warn' => 'b-orange', 'ok' => 'b-green',
    ][$worst] ?? 'b-gray';
@endphp

@section('actions')
    <a class="btn" href="{{ route('erp.stock') }}">← {{ __('stock.back_to_stock') }}</a>
    @if ($manager)
        <button class="btn gold" onclick="openDlg('dlgEditP')">{{ __('stock.edit_product') }}</button>
    @endif
    @if (auth()->user()?->role === 'admin')
        {{-- مسح نهائي — للصنف اللي نزل غلط ومحصلش عليه أي حركة.
             السيرفر بيرفض لو فيه أي حركة ويقول فيه إيه بالظبط. --}}
        <form method="POST" action="{{ route('erp.products.destroy', $p) }}" style="display:inline"
              onsubmit="return confirm(@js(__('stock.del_product_confirm', ['name' => $p->displayName()])))">
            @csrf
            @method('DELETE')
            <button class="btn sm" type="submit" style="color:var(--red);border-color:var(--red)">🗑 {{ __('stock.delete_product') }}</button>
        </form>
    @endif
@endsection

@section('content')

{{-- ═════════ الرأس: الصورة + الهوية ═════════ --}}
<div class="card">
    <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-start">

        {{-- ⚠️ **الصورة أول حاجة وأكبر حاجة.** ده كارت صنف — اللي
             بيفتحه عايز يتأكد إنه الصنف الصح قبل ما يقرا أي رقم. --}}
        <div style="flex:0 0 190px">
            @if ($p->imageSrc())
                <img src="{{ $p->imageSrc() }}" alt="{{ $p->displayName() }}"
                     style="width:260px;height:260px;object-fit:contain;border-radius:var(--r);
                            border:1px solid var(--border);background:#fff;padding:8px">
                @unless ($p->imageIsOurs())
                    <div style="font-size:10.5px;color:var(--muted);margin-top:6px;text-align:center">
                        {{ __('stock.image_from_gs1') }}
                    </div>
                @endunless
            @else
                <div style="width:260px;height:260px;border-radius:var(--r);border:1px dashed var(--border);
                            background:var(--paper);display:flex;align-items:center;justify-content:center;
                            color:var(--muted);font-size:12px;text-align:center;padding:12px">
                    {{ __('stock.image_none') }}
                </div>
            @endif
        </div>

        <div style="flex:1;min-width:280px">
            <h2 style="margin:0 0 4px;font-size:21px">{{ $p->displayName() }}</h2>

            {{-- ⚠️ الاسم التاني بيبان تحت الأساسي. اللي بيدور على صنف
                 بالإنجليزي في شاشة عربية لازم يلاقيه بعينه. --}}
            @php $other = app()->getLocale() === 'ar' ? $p->name_en : $p->name; @endphp
            @if (trim((string) $other) !== '' && $other !== $p->displayName())
                <div dir="auto" style="color:var(--muted);font-size:13px;margin-bottom:8px">{{ $other }}</div>
            @endif

            <div style="display:flex;gap:6px;flex-wrap:wrap;margin:10px 0">
                <span class="badge b-blue">{{ __('stock.sku') }} {{ $p->code }}</span>
                <span class="badge b-gray">{{ $p->familyLabel() }}</span>
                @if ($p->brand)<span class="badge b-purple">{{ $p->brand }}</span>@endif
                <span class="badge b-gray">{{ $p->unitLabel() }}</span>
                @if ($p->netLabel())<span class="badge b-gray">{{ $p->netLabel() }}</span>@endif
                @unless ($p->active)
                    <span class="badge b-red">{{ __('stock.inactive') }}</span>
                @endunless
                @if ($qty > 0)
                    <span class="badge {{ $stateCls }}">{{ __('stock.state_'.$worst) }}</span>
                @endif
            </div>

            @if ($p->descriptionLabel())
                <p style="margin:10px 0 0;font-size:13px;line-height:1.9;color:var(--text)">
                    {{ $p->descriptionLabel() }}
                </p>
            @endif
        </div>
    </div>
</div>

{{-- ═════════ الأرقام ═════════ --}}
{{-- الكروت: السعر والتكلفة بيودّوا على أسعار القوايم، الكمية والقيمة بيفتحوا توزيع المخازن (٢٢/٩) --}}
<div class="kpis">
    <a class="kpi" href="#pLists">
        <div class="lbl">{{ __('stock.price_new') }}</div>
        <div class="val" style="color:var(--primary)">{{ $money($p->price_new) }} {{ __('common.currency') }}</div>
        @if ($p->priceChanged())
            @php $up = (float) $p->price_new > (float) $p->price_old; @endphp
            <div class="sub2">
                <span class="badge {{ $up ? 'b-green' : 'b-red' }}" style="font-size:10px">
                    {{ __('stock.price_changed') }}
                    {{ $p->priceDeltaPct() > 0 ? '+' : '' }}{{ number_format($p->priceDeltaPct() * 100, 1) }}%
                </span>
            </div>
        @else
            <div class="sub2">{{ __('stock.price_old') }} {{ $money($p->price_old) }}</div>
        @endif
    </a>

    @if ($seeCost)
        {{-- ⚠️ التكلفة والهامش مخفيين عن أمين المخزن — نفس بوابة
             شاشة المخزون. هو بيشوف كميات ويحرّك بضاعة، والتكلفة
             وهامش الربح بيانات تجارية. --}}
        <a class="kpi" href="#pLists">
            <div class="lbl">{{ __('stock.cost') }}</div>
            <div class="val">{{ $money($p->cost) }} {{ __('common.currency') }}</div>
            {{-- تكلفة 0 = هامش 100% مضلّل — بنقولها صريحة، وغير كده المعادلة بأرقامها (٢٢/٩) --}}
            @if ((float) $p->cost <= 0)
                <div class="sub2 neg">⚠ {{ __('uid.no_cost_tip') }}</div>
            @else
                <div class="sub2 {{ $mgCls($margin) }}"><span dir="ltr">{{ __('stock.margin') }} {{ number_format($margin * 100, 1) }}% = ({{ $money($p->sellingPrice()) }} − {{ $money($p->cost) }}) ÷ {{ $money($p->sellingPrice()) }}</span></div>
            @endif
        </a>
    @endif

    <div class="kpi" data-explain onclick="openDlg('dlgWhBreak')" title="{{ __('ui.click_to_explain') }}">
        <div class="lbl">{{ __('stock.qty') }}</div>
        <div class="val">{{ $fmt($qty) }}</div>
        {{-- التجميعة بالوحدات — عرض بس، المخزون قطع --}}
        @if ($bd = $p->packBreakdown($qty))
            <div class="sub2" style="font-weight:800;color:var(--royal-blue)">{{ $bd }}</div>
        @endif
        <div class="sub2">
            {{ __('stock.good_stock') }} {{ $fmt($p->goodTotal()) }}
            @if ($p->holdTotal() > 0) · <span class="mid">{{ __('stock.hold') }} {{ $fmt($p->holdTotal()) }}</span>@endif
        </div>
        {{-- ⚠️ **التوزيع تحت الإجمالي مباشرةً.** الرقم الإجمالي لوحده
             بيخلّي حد يوافق على طلب من المعادي وهو كله في العاشر. --}}
        @if ($p->stocks->isNotEmpty())
            <div class="sub2" style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap">
                @foreach ($p->stocks->sortByDesc('qty') as $s)
                    <span class="badge {{ (int) $s->qty > 0 ? 'b-blue' : 'b-gray' }}">
                        {{ $s->warehouse?->displayName() ?? '—' }} {{ $fmt($s->qty) }}
                    </span>
                @endforeach
            </div>
        @endif
    </div>

    <div class="kpi" data-explain onclick="openDlg('dlgWhBreak')" title="{{ __('ui.click_to_explain') }}">
        <div class="lbl">{{ __('stock.value') }}</div>
        <div class="val pos">{{ $fmt($qty * $p->sellingPrice()) }} {{ __('common.currency') }}</div>
        <div class="sub2">{{ __('stock.value_at_new') }}<br><span dir="ltr">{{ $fmt($qty * $p->sellingPrice()) }} = {{ $fmt($qty) }} × {{ $money($p->sellingPrice()) }}</span></div>
    </div>

    <a class="kpi" href="#pBatches">
        <div class="lbl">{{ __('stock.shelf_life') }}</div>
        <div class="val">{{ $p->shelfLife() }}</div>
        <div class="sub2">{{ __('stock.shelf_life_months') }} — {{ __('uid.shelf_life_how') }}</div>
    </a>
</div>

<div class="grid2">
    {{-- ═════════ التعريف والمواصفات ═════════ --}}
    <div class="card">
        <h3>{{ __('stock.identity') }}</h3>
        <div class="tablewrap">
            <table data-noxl data-plain>
                <tr><th>{{ __('stock.sku') }}</th><td class="num">{{ $p->code }}</td></tr>
                <tr>
                    <th>{{ __('stock.barcode') }}</th>
                    <td class="num">{{ $p->barcode ?: '—' }}</td>
                </tr>
                <tr>
                    <th>{{ __('stock.case_barcode') }}</th>
                    <td class="num">
                        {{ $p->case_barcode ?: '—' }}
                        @if ($p->units_per_case)
                            <span class="badge b-gray" style="font-size:10px">
                                {{ $p->units_per_case }} {{ __('stock.units_per_case') }}
                            </span>
                        @endif
                    </td>
                </tr>
                @if ($p->packLabel())
                    <tr>
                        <th>{{ __('stock.pack_tiers') }}</th>
                        <td>{{ $p->packLabel() }}</td>
                    </tr>
                @endif
                <tr><th>{{ __('stock.eta_code') }}</th><td class="num">{{ $p->eta_code ?: '—' }}</td></tr>
                <tr><th>{{ __('stock.family') }}</th><td><a href="{{ route('erp.stock', ['family' => $p->family]) }}">{{ $p->familyLabel() }}</a></td></tr>
                <tr><th>{{ __('stock.brand') }}</th><td>{{ $p->brand ?: '—' }}</td></tr>
                <tr><th>{{ __('stock.unit') }}</th><td>{{ $p->unitLabel() }}</td></tr>
                <tr><th>{{ __('stock.net_content') }}</th><td>{{ $p->netLabel() ?: '—' }}</td></tr>
                <tr><th>{{ __('stock.gpc_category') }}</th><td style="font-size:11.5px">{{ $p->gpc_category ?: '—' }}</td></tr>
                <tr>
                    <th>{{ __('client.taxable') }}</th>
                    <td>
                        @if ($p->taxable)
                            <span class="badge b-green">{{ __('common.yes') }}</span>
                            @if ((float) $p->tax_rate > 0)
                                <span class="num">{{ number_format((float) $p->tax_rate * 100, 1) }}%</span>
                            @endif
                        @else
                            <span class="badge b-gray">{{ __('common.no') }}</span>
                        @endif
                    </td>
                </tr>
            </table>
        </div>
    </div>

    {{-- ═════════ الأسعار في القوايم ═════════ --}}
    {{-- ⚠️ الفواتير بتتسعّر من القوايم المسمّاة مش من العمودين —
         فالكارت لازم يوري سعر الصنف في **كل** قايمة، والناقص فيها
         بيبان بشارة حمرا لأنه هو اللي بيمنع تفعيلها. --}}
    @if ($priceLists->isNotEmpty())
    <div class="card" id="pLists">
        <h3>🏷️ {{ __('price.price_lists') }}</h3>
        <div class="tablewrap">
            <table>
                <tr>
                    <th>{{ __('price.list') }}</th>
                    <th class="num" data-nosum>{{ __('price.price') }}</th>
                    <th class="num" data-nosum>@if ($seeCost){{ __('stock.margin_pct') }}@endif</th>
                </tr>
                @foreach ($priceLists as $pl)
                    {{-- ⚠️ **السعر الفعلي مش سعر صف القايمة.** القايمتين
                         المهاجرتين بيرجعوا لعمود الصنف لو مافيش صف —
                         لو عرضنا الصف بس، الكارت بيقول «ناقص» والـKPI
                         اللي فوقه بيقول 20.00 وهي فعلاً اللي بتتحاسب. --}}
                    @php $lp = \App\Services\Pricing::listPrice($p, $pl); @endphp
                    <tr>
                        <td>
                            <a href="{{ route('erp.prices.show', $pl) }}"><b>{{ $pl->displayName() }}</b></a>
                            @if ($pl->is_default)<span class="badge b-blue" style="font-size:10px">{{ __('price.default') }}</span>@endif
                            @unless ($pl->active)<span class="badge b-gray" style="font-size:10px">{{ __('price.inactive') }}</span>@endunless
                        </td>
                        <td class="num">
                            @if ($lp > 0)
                                <b>{{ $money($lp) }}</b> {{ __('common.currency') }}
                            @else
                                <span class="badge b-red" style="font-size:10px">{{ __('price.missing') }}</span>
                            @endif
                        </td>
                        <td class="num">
                            @if ($seeCost && $lp > 0 && (float) $p->cost > 0)
                                @php $m = ($lp - (float) $p->cost) / $lp; @endphp
                                <span class="{{ $mgCls($m) }}">{{ number_format($m * 100, 1) }}%</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>
    </div>
    @endif

    {{-- ═════════ الباتشات ═════════ --}}
    <div class="card" id="pBatches">
        <h3>{{ __('stock.batches_of') }} <span class="side">{{ $batches->count() }}</span></h3>
        {{-- فلتر «من — إلى» على `expires_on` (٩/٩/٢٠٢٦): «إيه اللي بينتهي في الفترة دي؟» --}}
        <form method="GET" class="searchbar" style="margin-bottom:12px" data-noprint>
            @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue(), 'auto' => true])
            <span style="font-size:11px;color:var(--muted);align-self:flex-end;padding-bottom:9px">ℹ️ {{ __('uid.exp_range_note') }}</span>
        </form>
        <div class="tablewrap">
            <table>
                <tr>
                    <th>{{ __('stock.batch_no') }}</th><th>{{ __('ui.l_warehouse') }}</th><th data-nosum>{{ __('stock.produced_on') }}</th>
                    <th data-nosum>{{ __('stock.expires_on') }}</th><th>{{ __('stock.qty') }}</th>
                </tr>
                @forelse ($batches as $b)
                    <tr>
                        <td class="num">
                            @if ($b->goods_receipt_id)<a href="{{ route('wh.receipt', $b->goods_receipt_id) }}">{{ $b->batch_no }}</a>@else{{ $b->batch_no }}@endif
                        </td>
                        <td>@if ($b->warehouse)<a href="{{ route('erp.warehouses.stock', $b->warehouse) }}">{{ $b->warehouse->displayName() }}</a>@else — @endif</td>
                        <td class="num" style="color:var(--muted)">{{ $b->produced_on?->format('Y-m-d') ?? '—' }}</td>
                        <td class="num">
                            {{ $b->expires_on?->format('Y-m-d') ?? '—' }}
                            @if ($b->expires_on)
                                <br><span class="badge {{ [
                                    'expired' => 'b-red', 'danger' => 'b-red',
                                    'warn' => 'b-orange', 'ok' => 'b-green',
                                ][$b->expiryState()] ?? 'b-gray' }}" style="font-size:10px">
                                    {{ __('stock.state_'.$b->expiryState()) }}
                                </span>
                            @endif
                        </td>
                        <td class="num">{{ $fmt($b->qty_remaining) }}</td>
                    </tr>
                @empty
                    {{-- ⚠️ **الصنف من غير باتشات حالة عادية** مش خطأ:
                         الأصناف القديمة اتسجّل مخزونها إجمالي من الشيت
                         قبل ما نظام الباتشات يشتغل. --}}
                    <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:24px">
                        {{-- فترة متحددة ومفيش نتيجة ≠ «مفيش باتشات» — الصنف فيه رصيد وباتشاته بره الفترة (٢٢/٩) --}}
                        @if ($range->fromValue() || $range->toValue())
                            {{ __('uid.no_batches_in_window') }}
                            <a href="{{ route('erp.products.show', $p) }}#pBatches"><b>{{ __('stock.view_all') }}</b></a>
                        @else
                            {{ __('stock.no_batches') }}
                        @endif
                    </td></tr>
                @endforelse
            </table>
        </div>
    </div>
</div>

{{-- ═════════ مين بيشتريه ═════════ --}}
<div class="card">
    <h3>{{ __('stock.buyers') }} <span class="side">{{ $buyers->count() }}</span></h3>
    <div class="tablewrap">
        <table>
            <tr>
                <th>{{ __('client.client') }}</th><th>{{ __('stock.qty') }}</th>
                <th>{{ __('client.purchases') }}</th><th data-nosum>{{ __('stock.last_bought') }}</th>
            </tr>
            @forelse ($buyers as $b)
                <tr @if (\App\Support\Access::allows(auth()->user(), 'erp.clients.show'))onclick="location.href='{{ route('erp.clients.show', $b->id) }}'" style="cursor:pointer"@endif>
                    <td>
                        {{-- ⚠️ الاسم من الأعمدة المجمّعة مباشرةً — الكويري
                             `groupBy` مابترجّعش موديل، فمفيش `displayName()`. --}}
                        <a href="{{ route('erp.clients.show', $b->id) }}" onclick="event.stopPropagation()"><b>{{ app()->getLocale() === 'en' && $b->name_en ? $b->name_en : $b->name }}</b></a>
                        <span style="font-size:10.5px;color:var(--muted)">{{ $b->code }}</span>
                    </td>
                    <td class="num">{{ $fmt($b->qty) }}</td>
                    <td class="num">{{ $fmt($b->total) }} {{ __('common.currency') }}</td>
                    <td class="num" style="color:var(--muted)">{{ \Illuminate\Support\Carbon::parse($b->last_at)->format('Y-m-d') }}</td>
                </tr>
            @empty
                <tr><td colspan="4" style="text-align:center;color:var(--muted);padding:24px">
                    {{ __('stock.buyers_none') }}
                </td></tr>
            @endforelse
        </table>
    </div>
</div>

{{-- توزيع الكمية والقيمة على المخازن — الصفوف مجموعها = الكارت (٢٢/٩) --}}
<dialog id="dlgWhBreak" class="wide">
    <div class="dlg">
        <h4>{{ __('uid.wh_breakdown') }} — {{ $p->displayName() }}</h4>
        <div class="tablewrap">
            <table>
                <thead><tr>
                    <th style="text-align:start">{{ __('ui.l_warehouse') }}</th><th>{{ __('stock.qty') }}</th>
                    <th>{{ __('stock.good_stock') }}</th><th>{{ __('stock.hold') }}</th><th>{{ __('stock.value') }}</th>
                </tr></thead>
                <tbody>
                @foreach ($p->stocks->sortByDesc('qty') as $s)
                    <tr>
                        <td style="text-align:start">@if ($s->warehouse)<a href="{{ route('erp.warehouses.stock', $s->warehouse) }}">{{ $s->warehouse->displayName() }}</a>@else — @endif</td>
                        <td class="num">{{ $fmt($s->qty) }}</td>
                        <td class="num">{{ $fmt($s->good_qty) }}</td>
                        <td class="num mid">{{ $fmt($s->hold_qty) }}</td>
                        <td class="num pos">{{ $fmt((int) $s->qty * $p->sellingPrice()) }}</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot><tr>
                    <td><b>{{ __('common.total') }}</b></td>
                    <td class="num"><b>{{ $fmt($qty) }}</b></td>
                    <td class="num"><b>{{ $fmt($p->goodTotal()) }}</b></td>
                    <td class="num"><b>{{ $fmt($p->holdTotal()) }}</b></td>
                    <td class="num"><b>{{ $fmt($qty * $p->sellingPrice()) }}</b></td>
                </tr></tfoot>
            </table>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
            <button class="btn" type="button" onclick="closeDlg('dlgWhBreak')">{{ __('common.close') }}</button>
        </div>
    </div>
</dialog>

@if ($manager)
@include('erp._product_form', ['p' => $p, 'families' => $families])
@endif

@endsection
