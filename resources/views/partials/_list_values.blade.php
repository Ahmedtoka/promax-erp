{{--
    شرائح «القيمة بكل قايمة سعر» — عرض فقط (١٢ أغسطس ٢٠٢٦).

    بتترسم تحت أي كمية عهدة: «القائمة القديمة: 4,250.00 · الجديدة: 4,890.00».
    الباراميترز:
      - totals: ناتج CustodyValue::totals / remainingTotals / merge
                — [list_id => ['list' => PriceList, 'total' => float]]
      - size:   حجم الخط (اختياري — الافتراضي 10px)

    ⚠️ أرقام استرشادية — التصفية بالقطع، والفلوس دايماً بخانتين عشريتين.
--}}
@php
    $lvTotals = collect($totals ?? [])->filter(fn ($t) => ($t['list'] ?? null) !== null);
    $lvSize = $size ?? '10px';
@endphp
@if ($lvTotals->isNotEmpty())
    <div style="font-size:{{ $lvSize }};color:var(--muted);display:flex;flex-wrap:wrap;gap:2px 10px;justify-content:inherit">
        @foreach ($lvTotals as $lvT)
            <span style="white-space:nowrap">{{ $lvT['list']->displayName() }}:
                <b style="color:var(--royal-blue, #12399B)" dir="ltr">{{ number_format((float) $lvT['total'], 2) }}</b></span>
        @endforeach
    </div>
@endif
