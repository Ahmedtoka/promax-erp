@extends('layouts.system')

@section('title', $w->displayName())

@php
    $fmt = fn ($n) => number_format((float) $n);
    $manager = auth()->user()->canDecideOps();
@endphp

@section('actions')
    <a class="btn" href="{{ route('erp.warehouses') }}">← {{ __('stock.warehouses') }}</a>
@endsection

@section('content')

<div class="card" style="padding:14px 18px">
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
        <h2 style="margin:0;font-size:18px">{{ $w->displayName() }}</h2>
        <span class="badge b-blue">{{ $w->code }}</span>
        <span class="badge b-gray">{{ __('stock.type_'.$w->type) }}</span>
        @unless ($w->active)<span class="badge b-red">{{ __('stock.inactive') }}</span>@endunless
    </div>
</div>

{{-- ⚠️ **التحذير ده لازم يفضل ظاهر.** الفلو الطبيعي (إذن استلام →
     باتش → ترصيف) بيسيب أثر: مين استلم وإمتى وبأنهي مستند. الشاشة
     دي بتكتب الرقم مباشرةً من غير أي أثر — للتأسيس والتصحيح بس.
     لو اتستخدمت بدل الفلو، المخزن بيبقى أرقام محدش يعرف مصدرها. --}}
<div class="alert warn" style="margin-bottom:14px">
    <span>⚠️</span><span>{{ __('stock.manual_stock_warning') }}</span>
</div>

<div class="card">
    <form class="searchbar" method="GET" style="margin-bottom:12px">
        <input type="text" name="q" value="{{ $filters['q'] ?? '' }}"
               placeholder="{{ __('stock.search_item') }}" style="flex:1;min-width:180px">
        <select name="family" style="min-width:150px">
            <option value="">— {{ __('stock.family') }} —</option>
            @foreach ($families as $k => $lbl)
                <option value="{{ $k }}" @selected(($filters['family'] ?? '') === $k)>{{ __('enums.family.'.$k) }}</option>
            @endforeach
        </select>
        <button class="btn gold" type="submit">{{ __('common.search') }}</button>
        <a class="btn" href="{{ route('erp.warehouses.stock', $w) }}">{{ __('common.clear') }}</a>
    </form>

    <form method="POST" action="{{ route('erp.warehouses.stock.save', $w) }}" id="stockForm">
        @csrf
        {{-- ⚠️ **توجيه الخطأ على المفتاح `rows` لوحده ماكانش بيشتغل.** أخطاء
             التحقّق بتتسجّل بمفتاح `rows.<id>.qty`، و`has('rows')`
             مابتطابقهاش — فالمستخدم كان بيمسح خانة، الصفحة تتحمّل
             تاني، والشغل يضيع من غير أي رسالة على الشاشة. --}}
        @if ($errors->any())
            <div class="alert" style="margin-bottom:12px;flex-direction:column;align-items:stretch;gap:4px">
                @foreach ($errors->all() as $msg)
                    <div class="errline" style="margin:0">{{ $msg }}</div>
                @endforeach
            </div>
        @endif

        <div class="tablewrap">
            <table>
                <tr>
                    <th style="width:40px"></th>
                    <th>{{ __('common.code') }}</th><th>{{ __('stock.item') }}</th>
                    <th>{{ __('stock.family') }}</th><th>{{ __('stock.unit') }}</th>
                    <th style="width:110px">{{ __('stock.qty') }}</th>
                    <th style="width:110px">{{ __('stock.hold') }}</th>
                    <th>{{ __('stock.good_stock') }}</th>
                    <th>{{ __('stock.last_count') }}</th>
                </tr>
                @forelse ($products as $p)
                    @php
                        // ⚠️ العلاقة محمّلة بفلتر المخزن ده بس، فأول صف
                        // فيها هو الصح — مش `firstWhere` على مجموعة كاملة.
                        $st = $p->stocks->first();
                        $qty = (int) ($st->qty ?? 0);
                        $hold = (int) ($st->hold_qty ?? 0);
                    @endphp
                    <tr>
                        <td>
                            {{-- كبيرة + بلاسهولدر واضح للي لسه ملوش صورة --}}
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
                            <a href="{{ route('erp.products.show', $p) }}">{{ $p->code }}</a>
                        </td>
                        <td><b>{{ $p->displayName() }}</b></td>
                        <td><span class="badge b-gray">{{ $p->familyLabel() }}</span></td>
                        <td style="color:var(--muted);font-size:11.5px">{{ $p->unitLabel() }}</td>
                        <td>
                            <input type="number" min="0" max="9999999" style="width:100%"
                                   name="rows[{{ $p->id }}][qty]" value="{{ old('rows.'.$p->id.'.qty', $qty) }}"
                                   data-row="{{ $p->id }}" data-kind="qty" oninput="syncGood({{ $p->id }})"
                                   @readonly(! $manager)>
                        </td>
                        <td>
                            <input type="number" min="0" max="9999999" style="width:100%"
                                   name="rows[{{ $p->id }}][hold]" value="{{ old('rows.'.$p->id.'.hold', $hold) }}"
                                   data-row="{{ $p->id }}" data-kind="hold" oninput="syncGood({{ $p->id }})"
                                   @readonly(! $manager)>
                        </td>
                        {{-- ⚠️ **السليم محسوب مش مُدخل.** لو كان خانة تالتة،
                             حد كان هيكتب فيها رقم مايطابقش الإجمالي ناقص
                             الهولد — وتلات أرقام مايجمعوش. --}}
                        <td class="num" id="good{{ $p->id }}"><b>{{ $fmt(max(0, $qty - $hold)) }}</b></td>
                        <td class="num" style="color:var(--muted);font-size:11.5px">
                            {{ $st?->counted_at?->format('Y-m-d') ?? '—' }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" style="text-align:center;color:var(--muted);padding:28px">
                        {{ __('stock.no_items') }}
                    </td></tr>
                @endforelse
            </table>
        </div>

        @if ($manager && $products->isNotEmpty())
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
                <button class="btn gold" type="submit">{{ __('stock.save_stock') }}</button>
            </div>
        @endif
    </form>
</div>

@endsection

@section('scripts')
<script>
/**
 * السليم = الإجمالي − الهولد، بيتحدّث وانت بتكتب.
 *
 * ⚠️ **الرقم الأحمر بيبان قبل الحفظ.** الهولد الأكبر من الإجمالي
 * بيخلّي السليم بالسالب، والمندوب بيشوف كمية متاحة سالبة في
 * الأبلكيشن. السيرفر بيرفضه برضه — بس الشاشة بتقوله قبل ما يبعت.
 */
function syncGood(id) {
    const qty = Number(document.querySelector('[data-row="' + id + '"][data-kind="qty"]').value || 0);
    const hold = Number(document.querySelector('[data-row="' + id + '"][data-kind="hold"]').value || 0);
    const cell = document.getElementById('good' + id);

    if (!cell) return;

    const good = qty - hold;

    cell.innerHTML = '<b>' + good.toLocaleString() + '</b>';
    cell.className = good < 0 ? 'num neg' : 'num';
}
</script>
@endsection
