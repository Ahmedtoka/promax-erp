@extends('layouts.system')

@section('title', 'المناديب والعهدة')

@section('content')

<div class="card">
    <h3>👥 المناديب — لايف</h3>
    <div class="tablewrap">
        <table>
            <tr><th>المندوب</th><th>النوع</th><th>الزون النهارده</th><th>الأداء</th><th>عهدة متبقية</th><th>الحالة</th></tr>
            <tr>
                <td><b>أحمد محمود</b><br><span style="color:var(--muted);font-size:11px">REP-014</span></td>
                <td><span class="badge b-green">كاش فان</span></td>
                <td>مدينة نصر</td>
                <td class="num">11,832 EGP<br><span style="color:var(--muted)">2/5 زيارة</span></td>
                <td class="num">197 وحدة</td>
                <td><span class="badge b-orange">في زيارة</span></td>
            </tr>
            <tr>
                <td><b>مريم</b><br><span style="color:var(--muted);font-size:11px">REP-021 — Daily Dash</span></td>
                <td><span class="badge b-green">كاش فان</span></td>
                <td>مصر الجديدة</td>
                <td class="num">8,540 EGP<br><span style="color:var(--muted)">3/6 زيارة</span></td>
                <td class="num">210 وحدة</td>
                <td><span class="badge b-green">متحركة</span></td>
            </tr>
            <tr>
                <td><b>محمد سعيد</b><br><span style="color:var(--muted);font-size:11px">COU-007</span></td>
                <td><span class="badge b-blue">كورير</span></td>
                <td>خط Gourrmet / Rabbit</td>
                <td class="num">7,262 EGP<br><span style="color:var(--muted)">2/5 توصيلة</span></td>
                <td class="num">434 وحدة</td>
                <td><span class="badge b-blue">جاري تسليم</span></td>
            </tr>
        </table>
    </div>
</div>

<div class="card">
    <h3>📦 عهدة العربيات (محمّل ← متبقي)</h3>
    <div class="tablewrap">
        <table>
            <tr><th>المندوب</th><th>PROMAX Bar</th><th>PROMAX Cup</th><th>PMX Bar</th><th>PRO Spreads</th><th>قيمة المتبقي</th></tr>
            <tr><td>أحمد محمود</td><td class="num">150 ← 122</td><td class="num">60 ← 41</td><td class="num">120 ← 96</td><td class="num">45 ← 38</td><td class="num pos">17,830 EGP</td></tr>
            <tr><td>مريم (Daily Dash)</td><td class="num">180 ← 138</td><td class="num">72 ← 50</td><td class="num">90 ← 71</td><td class="num">30 ← 22</td><td class="num pos">16,210 EGP</td></tr>
            <tr><td>محمد سعيد (كورير)</td><td class="num">220 ← 148</td><td class="num">96 ← 66</td><td class="num">160 ← 160</td><td class="num">44 ← 32</td><td class="num pos">14,860 EGP</td></tr>
        </table>
    </div>
    <div style="color:var(--muted);font-size:11.5px;margin-top:8px">
        الكاش فان بيبيع بسعر cash van — الكورير بيسلّم POs بسعر hold 50%. الخصم من العهدة تلقائي مع كل فاتورة/تسليم.
    </div>
</div>

<div class="card">
    <h3>🛰️ تايم لاين اليوم — كل المناديب</h3>
    <div class="alerts">
        @foreach ($tracking as $t)
            @php
                $cls = match ($t['type']) {
                    'sale', 'deliver' => 'good',
                    'in' => 'info',
                    'req' => 'warn',
                    default => '',
                };
            @endphp
            <div class="alert {{ $cls }}"><div><b>{{ $t['time'] }} — {{ $t['rep'] }}:</b> {{ $t['event'] }}</div></div>
        @endforeach
    </div>
</div>

@endsection
