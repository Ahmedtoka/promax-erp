{{--
    دفتر «الطلبات | العجز | المخزون» (٩/١١/٢٠٢٦ — «مش فاهم عدد الأوامر من
    عدد القطع من الفلوس، وهل دي فلوس الأوامر ولا المخزون»).

    كل رقم جنبه وحدته (أمر / قطعة / ج) وتحته اسمه، وكل فلوس بتقول بأنهي
    سعر. الطلبات ناحية والمخزون ناحية والعجز في النص. نفس كروت `.kpi`.

    @include('ops._demand_ledger', ['t' => $totals, 'whCount' => 2, 'link' => $url])
--}}
@php
    $lf = fn ($n) => number_format((float) $n);
    $lShort = $t['short_units'] > 0;
    $lLine = fn ($n, $unit, $label, $note = null, $tone = '') =>
        '<div class="dl-row"><div class="dl-v '.$tone.'"><b>'.$lf($n).'</b> <small>'.e($unit).'</small></div>'
        .'<div class="dl-l">'.e($label).($note ? ' <i>('.e($note).')</i>' : '').'</div></div>';
@endphp
<div class="dl">
    <div class="kpi dl-side dl-orders">
        <div class="dl-h">{{ __('shortage.side_orders') }}</div>
        <div class="dl-c">{{ __('shortage.side_orders_cap') }}</div>
        {!! $lLine($t['open_orders'], __('shortage.u_orders'), __('shortage.l_orders_n')) !!}
        {!! $lLine($t['ordered_units'], __('shortage.u_pcs'), __('shortage.l_pcs_ordered')) !!}
        {!! $lLine($t['ordered_value'], __('shortage.u_egp'), __('shortage.l_value_ordered'), __('shortage.l_value_ordered_note')) !!}
    </div>

    <div @class(['kpi', 'dl-side', 'dl-short', 'is-bad' => $lShort])>
        <div class="dl-h">{{ __('shortage.side_short') }}</div>
        <div class="dl-c">{{ __('shortage.side_short_cap') }}</div>
        @if ($lShort)
            {!! $lLine($t['short_units'], __('shortage.u_pcs'), __('shortage.l_pcs_short'), null, 'neg') !!}
            {!! $lLine($t['short_value'], __('shortage.u_egp'), __('shortage.l_value_short'), __('shortage.l_value_short_note'), 'neg') !!}
            {!! $lLine($t['short_products'], __('shortage.u_products'), __('shortage.l_products_short'), null, 'neg') !!}
        @else
            <div class="dl-ok">✅ {{ __('shortage.box_short_ok') }}</div>
        @endif
    </div>

    <div class="kpi dl-side dl-stock">
        <div class="dl-h">{{ __('shortage.side_stock') }}</div>
        <div class="dl-c">{{ __('shortage.side_stock_cap') }}</div>
        {!! $lLine($t['free'], __('shortage.u_pcs'), __('shortage.l_pcs_free')) !!}
        {!! $lLine($t['free_value'], __('shortage.u_egp'), __('shortage.l_value_free'), __('shortage.l_value_free_note')) !!}
        {!! $lLine(count($t['by_warehouse']), __('shortage.u_wh'), __('shortage.l_wh_n')) !!}
    </div>
</div>
@once
<style>
.dl{display:grid;grid-template-columns:1fr minmax(0,.9fr) 1fr;gap:12px;margin-bottom:14px}
.dl-side{display:flex;flex-direction:column;gap:2px;padding:15px 16px 12px}
.dl-orders::before{background:var(--royal-blue)}
.dl-stock::before{background:#16A34A}
.dl-short::before{background:#9CA3AF}
.dl-short.is-bad{background:#FFF8F8}
.dl-short.is-bad::before{background:#DC2626}
.dl-h{font-size:14px;font-weight:900}
.dl-c{font-size:11px;color:var(--muted);margin-bottom:6px}
.dl-row{padding:7px 0;border-top:1px solid var(--border)}
.dl-v{display:flex;align-items:baseline;gap:5px;unicode-bidi:plaintext;font-variant-numeric:tabular-nums}
.dl-v b{font-size:21px;font-weight:900;letter-spacing:-.02em;line-height:1.15}
.dl-v small{font-size:12px;font-weight:800;color:var(--muted)}
.dl-v.neg b{color:#B91C1C}
.dl-l{font-size:11.5px;font-weight:700;color:var(--text);opacity:.8;line-height:1.5}
.dl-l i{font-style:normal;font-weight:600;color:var(--muted)}
.dl-ok{font-size:14px;font-weight:900;color:#0F7A38;padding:14px 0}
@media (max-width:900px){.dl{grid-template-columns:1fr}}
</style>
@endonce
