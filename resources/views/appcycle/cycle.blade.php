@extends('layouts.system')

@section('title', 'سايكل الموبايل أبلكيشن')

@section('content')

<div class="alert info" style="margin-bottom:14px">
    <div>📱 <b>الفلو المنفّذ في أبلكيشن Flutter</b> — داتا ديمو مربوطة بأسماء عملاء PROMAX الحقيقيين. لسه مش مربوط بالـ API.</div>
</div>

<div class="kpis">
    <div class="kpi"><div class="lbl">مبيعات الكاش فان النهارده</div><div class="val pos">20,372 ج</div><div class="sub2">9 فواتير — أحمد + مريم</div></div>
    <div class="kpi"><div class="lbl">POs مسلمة</div><div class="val" style="color:var(--blue)">7,262 ج</div><div class="sub2">2 من 5 — Gourrmet / Rabbit</div></div>
    <div class="kpi"><div class="lbl">طلبات عملاء جدد</div><div class="val mid">2 مستنية</div><div class="sub2">من إجمالي 4 طلبات</div></div>
    <div class="kpi"><div class="lbl">زيارات اليوم</div><div class="val">5 / 11</div><div class="sub2">تشيك إن / أوت مسجل</div></div>
    <div class="kpi"><div class="lbl">قيمة العهدة ع العربيات</div><div class="val" style="color:var(--gold)">48,900 ج</div><div class="sub2">3 عربيات محمّلة</div></div>
    <div class="kpi"><div class="lbl">مناديب أونلاين</div><div class="val pos">3 / 3</div><div class="sub2">تراكينج لايف شغال</div></div>
</div>

<div class="card">
    <h3>🔄 الفلو — 3 رولز في الأبلكيشن</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:12px">
        <div style="background:var(--card2);border-radius:12px;padding:16px;border-top:4px solid var(--green)">
            <div style="font-weight:900;margin-bottom:8px">🛒 مندوب كاش فان <span class="badge b-green">أحمد • مريم</span></div>
            <div style="color:var(--muted);font-size:12.5px;line-height:2.1">
                يستلم العهدة ← ينزل الزون بتاع اليوم ← تشيك إن عند العميل ← فاتورة (كاش/آجل) بتخصم من العهدة تلقائي ← تشيك أوت ← العميل اللي بعده<br>
                <b style="color:var(--text)">+ عميل جديد:</b> معدي على محل/جيم جديد ← يسجله (اسم، صورة، تليفون، أوراق) ← طلب للمانجر ← موافقة = نوتفيكيشن ويبدأ يفوتر
            </div>
        </div>
        <div style="background:var(--card2);border-radius:12px;padding:16px;border-top:4px solid var(--blue)">
            <div style="font-weight:900;margin-bottom:8px">🚚 كورير توزيع <span class="badge b-blue">محمد COU-007</span></div>
            <div style="color:var(--muted);font-size:12.5px;line-height:2.1">
                PO جاهز بييجي من <b style="color:var(--text)">Gourrmet Egypt / Rabbit</b> ← المانجر بينزّله على الكورير كريكوست ← يروح المكان ← تشيك إن وصول ← تسليم ← بيتخصم من عهدة العربية ← المكان اللي بعده<br>
                <b style="color:var(--text)">التسعير:</b> POs بسعر hold 50%
            </div>
        </div>
        <div style="background:var(--card2);border-radius:12px;padding:16px;border-top:4px solid var(--purple)">
            <div style="font-weight:900;margin-bottom:8px">👔 Channel Manager <span class="badge b-purple">حسام CHM-001</span></div>
            <div style="color:var(--muted);font-size:12.5px;line-height:2.1">
                بيوافق / يراجع / يرفض طلبات العملاء الجدد ← بيشوف كل مندوب معاه إيه في العهدة ومين في زون إيه ← تراكينج لايف لكل المناديب ← بينزّل الـ POs على الكوريرز
            </div>
        </div>
    </div>
</div>

<div class="card">
    <h3>🛰️ لايف تراكينج — تايم لاين اليوم</h3>
    <div class="alerts">
        @foreach ($tracking as $t)
            @php
                $cls = match ($t['type']) {
                    'sale', 'deliver' => 'good',
                    'in' => 'info',
                    'req' => 'warn',
                    'out' => '',
                    default => 'info',
                };
            @endphp
            <div class="alert {{ $cls }}">
                <div><b>{{ $t['time'] }} — {{ $t['rep'] }}:</b> {{ $t['event'] }}</div>
            </div>
        @endforeach
    </div>
</div>

@endsection
