{{--
    ═══ فلتر الفترة الموحّد «من — إلى» (مراجعة ٢٢ سبتمبر ٢٠٢٦) ═══

    بلاغ المالك: «التاريخ من في مكان وإلى في مكان ومش منسق». كل شاشة كانت
    راسمة خانتين التاريخ بطريقتها (من غير عنوان، أو بعنوان جنبها، أو كل واحدة
    في ناحية). من هنا ورايح أي فلتر فترة في فورم GET بيترسم من هنا بس:
    خانتين جنب بعض، فوق كل واحدة اسمها، وتحتهم اختصارات الفترة.

    @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue()])

    from / to     قيمة الخانتين (نص Y-m-d أو فاضي)
    fromName/toName  اسم الباراميتر لو الشاشة مش بتستخدم from/to
    auto          true = الفورم بيتبعت أول ما التاريخ يتغير
    shortcuts     false = من غير الاختصارات (الفورمات اللي مش GET)
    all           false = من غير اختصار «كل الفترات»

    ⚠️ الاختصارات لينكات على نفس الـURL بنفس باقي الفلاتر — وبتصفّر
    `page` و`export` عشان مايفتحش صفحة 7 من نتيجة اتغيرت ولا ينزّل ملف.
--}}
@php
    $rFromName = $fromName ?? 'from';
    $rToName = $toName ?? 'to';
    $rFrom = (string) ($from ?? '');
    $rTo = (string) ($to ?? '');
    $rAuto = ! empty($auto) ? 'this.form.submit()' : '';
    $rQs = fn (?string $a, ?string $b) => request()->fullUrlWithQuery([$rFromName => $a, $rToName => $b, 'page' => null, 'export' => null]);
    $rToday = today()->toDateString();
    $rLm = today()->startOfMonth()->subMonthNoOverflow();
    $rSets = [
        [__('ui.today'), $rToday, $rToday],
        [__('ui.this_month'), today()->startOfMonth()->toDateString(), $rToday],
        [__('ui.last_month'), $rLm->toDateString(), $rLm->copy()->endOfMonth()->toDateString()],
        [__('ui.this_year'), today()->startOfYear()->toDateString(), $rToday],
    ];
@endphp
<div class="rng">
    <label class="fl"><span>{{ __('ui.from_date') }}</span>
        <input type="date" name="{{ $rFromName }}" value="{{ $rFrom }}" @if($rAuto) onchange="{{ $rAuto }}" @endif></label>
    <label class="fl"><span>{{ __('ui.to_date') }}</span>
        <input type="date" name="{{ $rToName }}" value="{{ $rTo }}" @if($rAuto) onchange="{{ $rAuto }}" @endif></label>
    @if ($shortcuts ?? true)
        <div class="rng-q" data-noprint>
            @foreach ($rSets as [$rLbl, $rA, $rB])
                <a href="{{ $rQs($rA, $rB) }}" @class(['on' => $rFrom === $rA && $rTo === $rB])>{{ $rLbl }}</a>
            @endforeach
            @if ($all ?? true)
                <a href="{{ $rQs(null, null) }}" @class(['on' => $rFrom === '' && $rTo === ''])>{{ __('ui.all_time') }}</a>
            @endif
        </div>
    @endif
</div>
