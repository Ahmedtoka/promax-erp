@extends('layouts.system')

{{--
    تسعير قايمة واحدة.

    ⚠️ **السيلكت المتعدد + التسعير الجماعي هو الشغل الأساسي هنا.**
    تسعير 31 صنف واحد واحد ممكن؛ تسعير 31 صنف في 5 قوايم لأ.
--}}

@php
    $fmt = fn ($n) => number_format((float) $n, 2);
    $u = auth()->user();
    $canEdit = $u->isAdmin() || $u->role === 'manager';
    $f = $filters;
@endphp

@section('title', $list->displayName())

@section('actions')
    <a class="btn" href="{{ route('erp.prices') }}">← {{ __('price.price_lists') }}</a>
@endsection

@section('content')

<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ __('price.list') }}</div>
        <div class="val" style="font-size:17px">{{ $list->displayName() }}</div>
        <div class="sub2">
            <span class="badge b-blue">{{ $list->code }}</span>
            @if ($list->active)
                <span class="badge b-green">{{ __('common.active') }}</span>
            @else
                <span class="badge b-gray">{{ __('price.draft') }}</span>
            @endif
            @if ($list->is_default)<span class="badge b-purple">{{ __('price.default') }}</span>@endif
        </div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('price.priced') }}</div>
        <div class="val pos">{{ $total - $missing }} <span style="font-size:13px;color:var(--muted)">/ {{ $total }}</span></div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('price.missing') }}</div>
        <div class="val {{ $missing > 0 ? 'neg' : 'pos' }}">{{ $missing }}</div>
        @if ($missing > 0)
            <div class="sub2">{{ __('price.missing_blocks_activation') }}</div>
        @endif
    </div>
</div>

@if (! $list->active && $missing === 0 && $canEdit)
    {{-- ⚠️ القايمة الكاملة الموقوفة مالهاش أي أثر — لازم حد ياخد
         باله إنها جاهزة، وإلا بتفضل مسوّدة والعملاء على غيرها. --}}
    <form method="POST" action="{{ route('erp.prices.activate', $list) }}" style="margin-bottom:14px">
        @csrf
        <div class="alert good" style="align-items:center">
            <span>✅</span>
            <span style="flex:1">{{ __('price.ready_to_activate') }}</span>
            <button class="btn gold" type="submit">{{ __('price.activate') }}</button>
        </div>
    </form>
@endif

<div class="card">
    <form class="searchbar" method="GET" style="margin-bottom:12px">
        <input type="text" name="q" value="{{ $f['q'] ?? '' }}"
               placeholder="{{ __('stock.search_item') }}" style="flex:1;min-width:180px">
        <select name="family" style="min-width:150px">
            <option value="">— {{ __('stock.family') }} —</option>
            @foreach ($families as $k => $lbl)
                <option value="{{ $k }}" @selected(($f['family'] ?? '') === $k)>{{ __('enums.family.'.$k) }}</option>
            @endforeach
        </select>
        <label style="display:flex;gap:6px;align-items:center;font-size:12.5px;white-space:nowrap">
            <input type="checkbox" name="missing" value="1" @checked($f['missing'] ?? false)>
            {{ __('price.missing_only') }}
        </label>
        <button class="btn gold" type="submit">{{ __('common.search') }}</button>
        <a class="btn" href="{{ route('erp.prices.show', $list) }}">{{ __('common.clear') }}</a>
    </form>

    @if ($errors->any())
        <div class="alert" style="margin-bottom:12px;flex-direction:column;align-items:stretch;gap:4px">
            @foreach ($errors->all() as $msg)
                <div class="errline" style="margin:0">{{ $msg }}</div>
            @endforeach
        </div>
    @endif

    @if ($canEdit)
        {{-- ═══ التسعير الجماعي ═══
             ⚠️ **بيتحسب من قايمة مرجعية مش من نفسه.** «زوّد 10%» على
             قايمة فاضية بيدّي صفر — فالمرجع بيتحدد صراحةً، والصنف
             اللي مالوش سعر في المرجع بيتساب مش بيتحط بصفر. --}}
        <form method="POST" action="{{ route('erp.prices.bulk', $list) }}" id="bulkForm">
            @csrf
            <div class="card" style="background:var(--card2);margin-bottom:12px">
                <h3 style="font-size:13px">⚡ {{ __('price.bulk_pricing') }}</h3>
                <div class="frow keep">
                    <div>
                        <label class="f">{{ __('price.mode') }}</label>
                        <select name="mode" id="bulkMode" style="width:100%" onchange="syncBulk()">
                            <option value="set">{{ __('price.mode_set') }}</option>
                            <option value="pct">{{ __('price.mode_pct') }}</option>
                            <option value="amount">{{ __('price.mode_amount') }}</option>
                            <option value="copy">{{ __('price.mode_copy') }}</option>
                        </select>
                    </div>
                    <div id="bulkValueBox">
                        <label class="f" id="bulkValueLabel">{{ __('price.value') }}</label>
                        <input type="number" step="0.01" name="value" id="bulkValue" style="width:100%">
                    </div>
                    <div id="bulkFromBox" style="display:none">
                        <label class="f">{{ __('price.from_list') }}</label>
                        <select name="from_list" style="width:100%">
                            @foreach (\App\Models\PriceList::orderBy('id')->get() as $o)
                                <option value="{{ $o->id }}" @selected($reference && $o->id === $reference->id)>
                                    {{ $o->displayName() }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="f">{{ __('price.round_to') }}</label>
                        <select name="round" style="width:100%">
                            <option value="">— {{ __('price.no_rounding') }} —</option>
                            <option value="0.25">0.25</option>
                            <option value="0.50">0.50</option>
                            <option value="1">1.00</option>
                            <option value="5">5.00</option>
                        </select>
                    </div>
                </div>

                <div style="display:flex;gap:8px;justify-content:space-between;align-items:center;margin-top:12px">
                    <span style="font-size:12.5px;color:var(--muted)">
                        <b id="selCount">0</b> {{ __('client.selected') }}
                    </span>
                    <button class="btn gold" type="submit" id="bulkBtn" disabled>
                        {{ __('price.apply_bulk') }}
                    </button>
                </div>
            </div>

            {{-- ⚠️ **الـ checkbox جوه فورم التسعير الجماعي.** لو كانت
                 جوه فورم الحفظ، الاتنين كانوا هيتبعتوا مع بعض والسيرفر
                 مش هيعرف اللي المستخدم قصده. --}}
            <div id="idsHost"></div>
        </form>
    @endif

    <form method="POST" action="{{ route('erp.prices.save', $list) }}" id="priceForm">
        @csrf
        <div class="tablewrap">
            <table>
                <tr>
                    @if ($canEdit)
                        <th style="width:34px"><input type="checkbox" id="allBox" onchange="toggleAll(this)"></th>
                    @endif
                    <th style="width:40px"></th>
                    <th>{{ __('common.code') }}</th>
                    <th>{{ __('stock.item') }}</th>
                    <th>{{ __('stock.family') }}</th>
                    <th>{{ __('stock.unit') }}</th>
                    @if ($reference && $reference->id !== $list->id)
                        <th class="num">{{ $reference->displayName() }}</th>
                    @endif
                    <th class="num" style="width:130px">{{ __('price.price') }}</th>
                </tr>

                @forelse ($products as $p)
                    @php
                        // ⚠️ العلاقة محمّلة بفلتر القايمة دي بس، فأول صف
                        // فيها هو الصح — مش `firstWhere` على مجموعة كاملة.
                        $price = (float) ($p->prices->first()->price ?? 0);
                    @endphp
                    <tr>
                        @if ($canEdit)
                            <td><input type="checkbox" class="rowBox" value="{{ $p->id }}"
                                       onchange="syncCount()"></td>
                        @endif
                        <td>
                            @if ($p->imageSrc())
                                <img src="{{ $p->imageSrc() }}" alt="" loading="lazy"
                                     style="width:30px;height:30px;object-fit:contain;border-radius:5px;
                                            border:1px solid var(--border);background:#fff">
                            @endif
                        </td>
                        <td class="num">
                            <a href="{{ route('erp.products.show', $p) }}">{{ $p->code }}</a>
                        </td>
                        <td><b>{{ $p->displayName() }}</b></td>
                        <td><span class="badge b-gray">{{ $p->familyLabel() }}</span></td>
                        <td style="color:var(--muted);font-size:11.5px">{{ $p->unitLabel() }}</td>
                        @if ($reference && $reference->id !== $list->id)
                            <td class="num" style="color:var(--muted)">
                                {{ $fmt($reference->priceFor($p)) }}
                            </td>
                        @endif
                        <td class="num">
                            @if ($canEdit)
                                <input type="number" step="0.01" min="0" style="width:110px"
                                       name="prices[{{ $p->id }}]"
                                       value="{{ old('prices.'.$p->id, $price > 0 ? number_format($price, 2, '.', '') : '') }}"
                                       placeholder="—"
                                       class="{{ $price <= 0 ? 'bad' : '' }}">
                            @else
                                {{ $price > 0 ? $fmt($price) : '—' }}
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" style="text-align:center;color:var(--muted);padding:28px">
                        {{ __('stock.no_items') }}
                    </td></tr>
                @endforelse
            </table>
        </div>

        @if ($canEdit && $products->isNotEmpty())
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
                <button class="btn gold" type="submit">{{ __('price.save_prices') }}</button>
            </div>
        @endif
    </form>
</div>

@endsection

@section('scripts')
<script>
/** الوضع بيحدد الخانات الظاهرة */
function syncBulk() {
    const mode = document.getElementById('bulkMode').value;
    const isCopy = mode === 'copy';

    document.getElementById('bulkValueBox').style.display = isCopy ? 'none' : '';
    document.getElementById('bulkFromBox').style.display = isCopy ? '' : 'none';
    document.getElementById('bulkValue').required = ! isCopy;

    const labels = {
        set: @json(__('price.value_set')),
        pct: @json(__('price.value_pct')),
        amount: @json(__('price.value_amount')),
    };

    if (labels[mode]) {
        document.getElementById('bulkValueLabel').textContent = labels[mode];
    }
}

/**
 * ⚠️ **«علّم على الكل» بتعلّم على الصفحة دي بس.** الجدول مفلتر،
 * والتعليم على اللي مش ظاهر معناه تسعير أصناف مالكش نية تسعّرها.
 */
function toggleAll(box) {
    document.querySelectorAll('.rowBox').forEach(b => { b.checked = box.checked; });
    syncCount();
}

function syncCount() {
    const boxes = [...document.querySelectorAll('.rowBox:checked')];

    document.getElementById('selCount').textContent = boxes.length;
    document.getElementById('bulkBtn').disabled = boxes.length === 0;

    // ⚠️ الـids بتتحط في الفورم بتاع التسعير الجماعي، مش في فورم
    // الحفظ — الاتنين فورمين منفصلين وكل واحد بيبعت اللي يخصّه.
    const host = document.getElementById('idsHost');

    host.innerHTML = '';
    boxes.forEach(b => {
        const h = document.createElement('input');

        h.type = 'hidden';
        h.name = 'ids[]';
        h.value = b.value;
        host.appendChild(h);
    });

    const all = document.getElementById('allBox');
    const total = document.querySelectorAll('.rowBox').length;

    if (all) {
        all.checked = boxes.length > 0 && boxes.length === total;
        all.indeterminate = boxes.length > 0 && boxes.length < total;
    }
}

syncBulk();
syncCount();
</script>
@endsection
