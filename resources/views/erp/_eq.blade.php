{{--
    معادلة الكارت (٢٢/٩) — أي رقم ملخّص مركّب بيكتب تحته اتجمع إزاي بالأرقام الحقيقية:
    `603,588.78 = 172,880.00 نقدي + 190,715.00 شيك − …`
    الاستخدام: @include('erp._eq', ['total' => $x, 'parts' => [[label, value], [label, value, '-']], 'dec' => 2])
    - الأجزاء الصفرية بتتشال (إلا لو `zeros => true`) عشان السطر يفضل قصير
    - `op` للعمليات غير الجمع: '÷' أو '×' (الإشارة بتتاخد من الجزء نفسه)
    - `suffix` بيتكتب بعد الإجمالي (مثلاً %)، والعنصر الخامس في الجزء لاحقة لرقمه (مثلاً %)
    - العنصر الرابع في الجزء عدد الكسور بتاعه لو مختلف
    الأرقام جاية من نفس متغيرات الكارت — مفيش تعريف تاني للرقم.
--}}
@php
    $eqDec = $dec ?? 2;
    $eqF = fn ($n) => number_format((float) $n, $eqDec);
    $eqParts = collect($parts)->filter(fn ($p) => ($zeros ?? false) || abs((float) $p[1]) > 0.0000001)->values();
@endphp
@if ($eqParts->count() > 1 || ($single ?? false))
    {{-- المعادلة ماشية مع اتجاه الصفحة، وكل رقم لوحده LTR — كده العربي والإنجليزي الاتنين بيتقروا صح --}}
    <span class="eq" style="display:inline-block;line-height:1.7">{{ isset($totalLabel) ? $totalLabel.' ' : '' }}<span dir="ltr" style="font-weight:700">{{ isset($totalText) ? $totalText : $eqF($total).($suffix ?? '') }}</span> =
        @foreach ($eqParts as $i => $p)
            @php $sign = $p[2] ?? '+'; $pd = $p[3] ?? $eqDec; @endphp
            @if ($i > 0 || $sign !== '+') {{ $sign === '-' ? '−' : $sign }} @endif
            <span style="white-space:nowrap"><span dir="ltr">{{ number_format(abs((float) $p[1]), $pd) }}{{ $p[4] ?? '' }}</span> {{ $p[0] }}</span>
        @endforeach
    </span>
@endif
