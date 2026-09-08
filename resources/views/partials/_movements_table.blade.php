{{-- ═══════════════════════════════════════════════════════════════
     حركة الأصناف بالكمية — كارت مشترك بين كارت العميل وصفحة السلسلة (٨/٩/٢٠٢٦)

     $movements  ⇐ App\Services\ProductMovements::summary(...)
     $exportUrl  ⇐ راوت التصدير التفصيلي (بيتضاف عليه ?view=summary للملخص)
     $title      ⇐ عنوان الكارت
     $hint       ⇐ سطر الشرح تحت العنوان (اختياري)

     ⚠️ الأرقام هنا كميات من المستندات — مش فلوس من القيود. «قيمة المسحوب»
     للاسترشاد بمتوسط السعر بس؛ الرصيد والمشتريات من كشف الحساب.
     ═══════════════════════════════════════════════════════════════ --}}
@php
    $mvFmt = fn ($n) => number_format((float) $n);
    $mvMoney = fn ($n) => number_format((float) $n, 2);
    $canProduct = \App\Support\Access::allows(auth()->user(), 'erp.products.show');
    $mvTotals = $movements['totals'];
@endphp
<div class="card" id="movements">
    <h3>📦 {{ $title }}
        <span class="side">
            @if (! empty($hint))<span style="margin-inline-end:10px">{{ $hint }}</span>@endif
            <a class="btn sm" href="{{ $exportUrl }}">⬇ {{ __('client.export_movements') }}</a>
            <a class="btn sm" href="{{ $exportUrl.(str_contains($exportUrl, '?') ? '&' : '?') }}view=summary">⬇ {{ __('client.export_movements_summary') }}</a>
        </span>
    </h3>

    @if ($movements['families'] === [])
        <div style="text-align:center;color:var(--muted);padding:22px">{{ __('client.no_movements') }}</div>
    @else
        <div class="kpis" style="margin-bottom:10px">
            <div class="kpi"><div class="lbl">{{ __('client.sold_qty') }}</div><div class="val">{{ $mvFmt($mvTotals['sold_qty']) }}</div></div>
            <div class="kpi"><div class="lbl">{{ __('client.returned_qty') }}</div><div class="val neg">{{ $mvFmt($mvTotals['returned_qty']) }}</div></div>
            <div class="kpi"><div class="lbl">{{ __('client.gift_qty') }}</div><div class="val">{{ $mvFmt($mvTotals['gift_qty']) }}</div></div>
            <div class="kpi"><div class="lbl">{{ __('client.net_qty') }}</div><div class="val pos">{{ $mvFmt($mvTotals['net_qty']) }}</div></div>
            <div class="kpi"><div class="lbl">{{ __('client.families_count') }}</div><div class="val">{{ count($movements['families']) }}</div></div>
        </div>

        <div class="tablewrap">
            <table>
                <thead>
                <tr>
                    <th>{{ __('stock.item') }}</th>
                    <th class="num">{{ __('client.sold_qty') }}</th>
                    <th class="num">{{ __('client.returned_qty') }}</th>
                    <th class="num">{{ __('client.gift_qty') }}</th>
                    <th class="num">{{ __('client.net_qty') }}</th>
                    <th class="num" data-nosum>{{ __('client.avg_price') }}</th>
                    <th class="num">{{ __('client.sold_value') }}</th>
                    <th data-nosum>{{ __('client.last_withdrawal') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($movements['families'] as $family)
                    {{-- صف العائلة: إجمالياتها، والأصناف تحتها --}}
                    <tr style="background:var(--blue-050,#EEF3FF);font-weight:800">
                        <td>🏷️ {{ $family['label'] }} <span style="color:var(--muted);font-weight:600;font-size:11px">({{ count($family['products']) }})</span></td>
                        <td class="num">{{ $mvFmt($family['sold_qty']) }}</td>
                        <td class="num neg">{{ $family['returned_qty'] > 0 ? $mvFmt($family['returned_qty']) : '—' }}</td>
                        <td class="num">{{ $family['gift_qty'] > 0 ? $mvFmt($family['gift_qty']) : '—' }}</td>
                        <td class="num pos">{{ $mvFmt($family['net_qty']) }}</td>
                        <td class="num" data-nosum></td>
                        <td class="num">{{ $mvMoney($family['sold_value']) }}</td>
                        <td data-nosum></td>
                    </tr>
                    @foreach ($family['products'] as $p)
                        <tr>
                            <td style="padding-inline-start:26px">
                                @if ($canProduct)
                                    <a href="{{ route('erp.products.show', $p['product']) }}" style="color:inherit">{{ $p['product']->displayName() }}</a>
                                @else
                                    {{ $p['product']->displayName() }}
                                @endif
                                <br><span style="font-size:10.5px;color:var(--muted)" dir="ltr">{{ $p['product']->code }}</span>
                            </td>
                            <td class="num"><b>{{ $mvFmt($p['sold_qty']) }}</b>
                                <br><span style="font-size:10px;color:var(--muted)">{{ __('client.doc_countable', ['count' => $p['docs']]) }}</span></td>
                            <td class="num neg">
                                @if ($p['returned_qty'] > 0)
                                    {{ $mvFmt($p['returned_qty']) }}
                                    @if ($p['returned_damaged'] > 0)
                                        <br><span style="font-size:10px;color:var(--muted)">{{ __('client.cond_damaged') }} {{ $mvFmt($p['returned_damaged']) }}</span>
                                    @endif
                                @else — @endif
                            </td>
                            <td class="num">{{ $p['gift_qty'] > 0 ? $mvFmt($p['gift_qty']) : '—' }}</td>
                            <td class="num pos">{{ $mvFmt($p['net_qty']) }}</td>
                            <td class="num" data-nosum>{{ $p['avg_price'] > 0 ? $mvMoney($p['avg_price']) : '—' }}</td>
                            <td class="num">{{ $mvMoney($p['sold_value']) }}</td>
                            <td data-nosum style="font-size:11.5px">{{ $p['last_at']?->format('Y-m-d') ?? '—' }}</td>
                        </tr>
                    @endforeach
                @endforeach
                </tbody>
                <tfoot>
                <tr style="font-weight:900">
                    <td>{{ __('common.total') }}</td>
                    <td class="num">{{ $mvFmt($mvTotals['sold_qty']) }}</td>
                    <td class="num neg">{{ $mvFmt($mvTotals['returned_qty']) }}</td>
                    <td class="num">{{ $mvFmt($mvTotals['gift_qty']) }}</td>
                    <td class="num pos">{{ $mvFmt($mvTotals['net_qty']) }}</td>
                    <td class="num" data-nosum></td>
                    <td class="num">{{ $mvMoney($mvTotals['sold_value']) }}</td>
                    <td data-nosum></td>
                </tr>
                </tfoot>
            </table>
        </div>
        <div style="font-size:11px;color:var(--muted);margin-top:6px">{{ __('client.movements_footnote') }}</div>
    @endif
</div>
