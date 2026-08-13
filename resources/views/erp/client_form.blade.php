@extends('layouts.system')

@section('title', $editing
    ? __('client.edit_title', ['name' => $src->displayName()])
    : ($src ? __('client.clone_title', ['name' => $src->displayName()]) : __('client.new_client')))

@php
    use App\Models\Channel;
    use App\Models\Client;
    use App\Models\Contract;

    /**
     * ⚠️ `old()` بتغلب المصدر دايماً. لو التحقق رفض الفورم، اللي
     * المستخدم كتبه لازم يرجع — مش قيم العميل اللي بننسخ منه.
     * القيمة الفاضية "" غير `null`: العنوان اللي المستخدم فضّاه
     * عن قصد مالازمش يترجع من المصدر.
     */
    $v = function (string $key, $fallback = null) use ($src) {
        $o = old($key);
        if ($o !== null) {
            return $o;
        }

        return $src?->getAttribute($key) ?? $fallback;
    };

    /**
     * الحقول اللي بتخص الفرع نفسه: الاسم والعنوان والتليفون واللوكيشن.
     *
     * ⚠️ **في الاستنساخ بقت بتتعبّى هي كمان** (قرار المالك ١١ أغسطس
     * ٢٠٢٦: «زرار فرع جديد بنفس الشروط لازم ينقل كل البيانات بالظبط
     * بكل تفاصيلها»). كانت بتفضل فاضية خوفاً من فرعين بنفس الاسم —
     * القرار اتغيّر: كل حاجة بتتنقل والمستخدم يعدّل اللي بيفرق
     * (الاسم والعنوان غالباً) بدل ما يعيد كتابة كل حاجة من الصفر.
     * الكود بيتولّد جديد تلقائياً فمفيش تصادم حقيقي.
     */
    $own = function (string $key, $fallback = null) use ($src, $editing) {
        $o = old($key);
        if ($o !== null) {
            return $o;
        }

        // تعديل أو استنساخ — الاتنين بينقلوا القيمة من المصدر
        return $src?->getAttribute($key) ?? $fallback;
    };

    $presetOn = fn ($k) => (bool) (old("clause.$k.on", $presets[$k]['on'] ? 1 : 0));
    $presetVal = fn ($k) => old("clause.$k.value", $presets[$k]['value'] ?: '');

    /**
     * البند مقفول؟
     *
     * ⚠️ **القفل في التعديل بس.** الـ22 عقد الحقيقيين فيهم بنود مكتوبة
     * بإيد من الـPDF من غير `preset`. `ContractIntake::syncClauses()`
     * بترفض تكتب فوقها عشان مايبقاش خصمين لنفس النوع. الشاشة لازم
     * تعرض القفل ده — من غيره المستخدم بيغيّر الرقم، بياخد «اتحفظ»
     * أخضر، والقيمة زي ما هي ومفيش رسالة تقول ليه.
     *
     * ⚠️ في الإنشاء/الاستنساخ مفيش قفل: العقد جديد فاضي، ولو قفلنا
     * الفرع الجديد كان هيفتح بخصم صفر.
     */
    $locked = fn ($k) => $editing && ! empty($presets[$k]['locked']);

    // ═══════════════════════════════════════════════════════════
    // أدوات عرض الأخطاء
    // ═══════════════════════════════════════════════════════════
    //
    // ⚠️ الخطأ لازم يبان **على الخانة نفسها**، مش سطر واحد فوق
    // الصفحة. الفورم ده 3 مراحل و40 خانة — رسالة عامة معناها إن
    // المستخدم يفضل يدوّر على اللي غلط.

    /** كلاس أحمر على الخانة اللي فيها خطأ */
    $bad = fn (string $field) => $errors->has($field) ? ' bad' : '';

    /** سطر الخطأ تحت الخانة */
    $err = function (string $field) use ($errors) {
        return $errors->has($field)
            ? '<div class="errline">'.e($errors->first($field)).'</div>'
            : '';
    };

    /** نجمة الحقل الإجباري */
    $star = '<b class="req-star">*</b>';

    /**
     * سطر الشرح تحت الخانة — **بيقول القيمة دي بتروح فين**.
     *
     * ⚠️ **مش وصف للخانة، ده وصف لأثرها.** «اسم العميل» مالهاش لازمة
     * كشرح؛ اللي بيدخّل الداتا محتاج يعرف إن الاسم الإنجليزي ده هو
     * اللي هيطلع على الفاتورة قدام العميل، وإن القناة بتحدد سعره.
     * الشروح اللي بتعيد اسم الخانة بتتقري كضوضاء وبيتوقف عن قراية
     * كل الشروح بعد كام واحدة.
     *
     * ⚠️ والمفتاح ناقص = الخانة مابيبانش تحتها حاجة، مش خطأ. عشان
     * إضافة خانة جديدة مايبقاش شرطها إن حد يفتكر يكتب شرحها.
     */
    $hint = function (string $key) {
        $k = 'client.hint_'.$key;
        $t = __($k);

        return $t === $k ? '' : '<div class="fhint">'.e($t).'</div>';
    };

    /**
     * المرحلة اللي فيها أول خطأ — الصفحة بتفتح عليها.
     *
     * ⚠️ من غير ده، الفورم بيرجع على مرحلة 1 والخطأ في مرحلة 3،
     * والمستخدم بيشوف صفحة سليمة ومش فاهم ليه الحفظ مانفعش.
     */
    $stepFields = [
        2 => ['price_list_id', 'discount', 'has_contract', 'contract_type',
              'contract_payment_days', 'contract_payment_days_from',
              'contract_starts_at', 'contract_ends_at', 'contract_note',
              'contract_clauses', 'contract_file', 'clause'],
        3 => ['taxable', 'tax_rate', 'tax_cycle', 'tax_id', 'eta_type', 'notes'],
    ];

    $errorStep = 1;

    foreach ($stepFields as $n => $fields) {
        foreach ($fields as $f) {
    // ⚠️ `hasAny` بنجمة عشان `clause.invoice_discount.value`
            // تتلقط من جذرها `clause`.
            if ($errors->has($f) || $errors->hasAny([$f.'.*'])) {
                $errorStep = $n;
                break 2;
            }
        }
    }

    // ⚠️ الخطأ في مرحلة 1 له أولوية — بنبدأ من أول مرحلة فيها خطأ
    $step1 = ['name', 'name_en', 'phone', 'governorate', 'zone_id', 'address',
              'location_url', 'channel_id', 'sub_channel', 'branch_id',
              'group_id', 'manager_id', 'lat', 'lng', 'contacts',
              // ⚠️ شروط الدفع اتنقلت لخطوة ١ — لو فضلت في قايمة خطوة ٢
              // الفورم كان هيفتح على خطوة العقد وخطأ الفاليديشن في خطوة ١
              'payment_terms', 'payment_days', 'payment_days_from'];

    foreach ($step1 as $f) {
        if ($errors->has($f) || $errors->hasAny([$f.'.*'])) {
            $errorStep = 1;
            break;
        }
    }
@endphp

@section('actions')
    <a class="btn" href="{{ route('erp.clients') }}">← {{ __('client.clients') }}</a>
@endsection

@section('content')

@if ($editing)
    <div class="alert info" style="margin-bottom:14px">
        <span>✎</span>
        <span>{{ __('client.edit_hint', ['name' => $src->displayName(), 'code' => $src->code]) }}</span>
        <a class="btn sm" href="{{ route('erp.clients.show', $src) }}"
           style="margin-inline-start:auto">{{ __('client.back_to_card') }}</a>
    </div>
@elseif ($src)
    <div class="alert info" style="margin-bottom:14px">
        <span>⧉</span>
        <span>{{ __('client.clone_hint', ['name' => $src->displayName(), 'code' => $src->code]) }}</span>
    </div>
@endif

{{-- ⚠️ ملخّص فوق **بالإضافة** للأخطاء على الخانات. المستخدم بيرجع
     للصفحة وعينه فوق، فلازم يشوف إن فيه مشكلة قبل ما يدوّر عليها. --}}
@if ($errors->any())
    <div class="alert" style="margin-bottom:14px;border-inline-start-color:var(--red)">
        <span>⛔</span>
        <div>
            <b>{{ __('common.fix_these', ['count' => $errors->count()]) }}</b>
            <ul style="margin:6px 0 0;padding-inline-start:18px;line-height:1.9">
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif

{{-- ═════════ شريط الخطوات ═════════ --}}
<div class="card" style="padding:12px 18px">
    <div id="steps" style="display:flex;gap:8px;flex-wrap:wrap">
        @foreach ([1 => __('client.step_identity'), 2 => __('client.step_contract'), 3 => __('client.step_tax')] as $n => $label)
            <button type="button" class="step-btn" data-step="{{ $n }}" onclick="goStep({{ $n }})">
                <span class="step-n">{{ $n }}</span>{{ $label }}
            </button>
        @endforeach
    </div>
</div>

{{-- الحقول كلها في فورم واحد — الخطوات إخفاء وإظهار بس، فمفيش
     داتا بتضيع لو المستخدم رجع خطوة لورا --}}
{{-- ⚠️ **التعديل بيروح على راوت تاني بـ`PUT`.** لو فضل `POST` على
     `clients.store`، «تعديل» كان بيعمل عميل جديد كل مرة. --}}
<form method="POST" id="clientForm" enctype="multipart/form-data"
      action="{{ $editing ? route('erp.clients.update', $src) : route('erp.clients.store') }}">
    @csrf
    @if ($editing)
        @method('PUT')
    @elseif ($src)
        <input type="hidden" name="cloned_from" value="{{ $src->id }}">
    @endif

    {{-- ══════════════════ 1. تعريف العميل ══════════════════ --}}
    <div class="card step-pane" data-pane="1">
        <h3>{{ __('client.step_identity') }}</h3>

        <div class="alert info" style="margin-bottom:14px">
            <span>🔤</span><span>{{ __('client.name_en_first_hint') }}</span>
        </div>

        <div class="frow">
            <div>
                <label class="f">{{ __('client.name_en_field') }} {!! $star !!}</label>
                {{-- ⚠️ **الـplaceholder بيقول «اكتب إيه» مش بيدي مثال.**
                     المثال (زي «Circle K — Maadi Degla») كان بيتقري كأنه
                     قيمة مكتوبة فعلاً، وفيه ناس بتسيب الخانة فاكرة إنها
                     مليانة — وبعدين الحفظ بيترفض وهم مش فاهمين ليه. --}}
                <input type="text" name="name_en" maxlength="190" dir="ltr" autofocus data-req
                       value="{{ $own('name_en') }}" style="width:100%"
                       class="{{ trim($bad('name_en')) }}"
                       placeholder="{{ __('client.name_en_ph') }}">
                {!! $err('name_en') !!}
                {!! $hint('name_en') !!}
            </div>
            <div>
                <label class="f">{{ __('client.name_ar_field') }} {!! $star !!}</label>
                <input type="text" name="name" maxlength="190" data-req
                       class="{{ trim($bad('name')) }}"
                       value="{{ $own('name') }}" style="width:100%"
                       placeholder="{{ __('client.name_ar_ph') }}">
                {!! $err('name') !!}
                {!! $hint('name') !!}
            </div>
        </div>

        <div class="frow">
            <div>
                <label class="f">{{ __('client.channel') }} {!! $star !!}</label>
                <select name="channel_id" style="width:100%" id="channelSel" data-req
                        class="{{ trim($bad('channel_id')) }}" onchange="syncSubChannel()">
                    {{-- ⚠️ الاختيار الفاضي أول القايمة عن قصد. لو أول
                         قناة كانت مختارة سلفاً، اللي بيدخل الداتا بيعدّي
                         عليها من غير ما يقرا — والعميل بيدخل قناة غلط. --}}
                    <option value="">— {{ __('client.pick_channel') }} —</option>
                    @foreach ($channels as $ch)
                        <option value="{{ $ch->id }}" data-code="{{ $ch->code }}"
                                @selected((int) $v('channel_id') === $ch->id)>
                            {{ $ch->displayName() }}
                        </option>
                    @endforeach
                </select>
                {!! $err('channel_id') !!}
                {!! $hint('channel_id') !!}
            </div>
            {{-- ⚠️ بتبدأ **مخفية** والـJS بيظهرها للكي أكاونت بس. لو
                 بدأت ظاهرة، بتبان وميضة على كل عميل أونلاين أو كاش فان
                 قبل ما السكربت يشتغل. --}}
            <div id="subChannelBox" style="display:none">
                <label class="f">{{ __('client.key_account_segment') }}</label>
                <select name="sub_channel" style="width:100%" class="{{ trim($bad('sub_channel')) }}">
                    <option value="">— {{ __('client.pick_segment') }} —</option>
                    @foreach (array_keys(Channel::SUB_CHANNELS) as $k)
                        <option value="{{ $k }}" @selected($v('sub_channel') === $k)>{{ __('enums.sub_channel.'.$k) }}</option>
                    @endforeach
                </select>
                {!! $err('sub_channel') !!}
                {!! $hint('sub_channel') !!}
            </div>
            <div>
                <label class="f">{{ __('common.phone') }}</label>
                <input type="text" name="phone" maxlength="30" dir="ltr" placeholder="01000000000"
                       class="{{ trim($bad('phone')) }}"
                       value="{{ $own('phone') }}" style="width:100%">
                {!! $err('phone') !!}
                {!! $hint('phone') !!}
            </div>
        </div>

        <div class="frow">
            <div>
                <label class="f">{{ __('geo.governorate') }}</label>
                <select name="governorate" id="govSel" style="width:100%"
                        class="{{ trim($bad('governorate')) }}" onchange="filterZones()">
                    <option value="">{{ __('geo.pick_governorate') }}</option>
                    @foreach ($governorates as $key => $label)
                        <option value="{{ $key }}" @selected($v('governorate') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                {!! $err('governorate') !!}
                {!! $hint('governorate') !!}
            </div>
            <div>
                <label class="f">{{ __('geo.zone') }}</label>
                <div style="display:flex;gap:6px">
                    <select name="zone_id" id="zoneSel" style="flex:1;min-width:0">
                        <option value="">{{ __('geo.pick_zone') }}</option>
                        @foreach ($zones as $z)
                            <option value="{{ $z->id }}" data-gov="{{ $z->governorate }}"
                                    @selected((int) $v('zone_id') === $z->id)>
                                {{ $z->displayName() }}
                            </option>
                        @endforeach
                    </select>
                    {{-- ⚠️ بيفتح مودال بيحفظ بـ fetch — **من غير ما الصفحة
                         تتحرك**. أي navigation هنا معناه إن اللي اتكتب في
                         الـ3 مراحل يضيع، والمستخدم بيحط العميل في منطقة
                         غلط بدل ما يقف يعمل منطقته. --}}
                    <button type="button" class="btn sm" onclick="openZoneBox()"
                            title="{{ __('geo.add_zone') }}">+</button>
                </div>
                {!! $err('zone_id') !!}
                {!! $hint('zone_id') !!}
                <div id="zoneHint" style="font-size:11px;color:var(--muted);margin-top:5px"></div>
            </div>
        </div>

        {{-- ═════ منطقة جديدة — بلوك جوه نفس الصفحة ═════ --}}
        <div id="zoneBox" style="display:none;border:1px dashed var(--royal-blue);border-radius:12px;padding:12px 14px;margin-bottom:12px;background:var(--card2)">
            <div style="font-size:12px;font-weight:900;color:var(--royal-blue);margin-bottom:9px">{{ __('geo.add_zone') }}</div>
            <div class="frow" style="margin-bottom:8px">
                <div><label class="f">{{ __('geo.zone') }}</label><input type="text" id="nzName" maxlength="190" style="width:100%"></div>
                <div><label class="f">{{ __('common.name_en') }}</label><input type="text" id="nzNameEn" dir="ltr" maxlength="190" style="width:100%"></div>
                <div>
                    <label class="f">{{ __('geo.governorate') }}</label>
                    <select id="nzGov" style="width:100%">
                        <option value="">{{ __('geo.pick_governorate') }}</option>
                        @foreach ($governorates as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div id="nzMsg" style="font-size:11.5px;margin-bottom:8px"></div>
            <div style="display:flex;gap:8px;justify-content:flex-end">
                <button type="button" class="btn sm" onclick="closeZoneBox()">{{ __('common.cancel') }}</button>
                {{-- ⚠️ `type=button` إجباري. زرار جوه فورم من غيرها بيبقى
                     submit ويبعت العميل ناقص قبل ما المستخدم يخلّص. --}}
                <button type="button" class="btn sm gold" id="nzSave" onclick="saveZone()">{{ __('common.save') }}</button>
            </div>
        </div>

        {{-- ⚠️ **خانة واحدة بالإنجليزي.** العنوان نص حر بيتكتب مرة
             لعميل واحد — مش داتا أساسية بتتعرّف مرة وبتتكرر في كل
             الشاشات زي القناة والمحافظة. عمودين هنا معناهم إن اللي
             بيدخل الداتا بيكتب نفس العنوان مرتين على 300 عميل. --}}
        <div class="frow">
            <div style="grid-column:1/-1">
                <label class="f">{{ __('common.address') }} <span style="color:var(--muted);font-weight:400">· EN</span></label>
                <input type="text" name="address" dir="ltr" maxlength="190" value="{{ $own('address') }}"
                       class="{{ trim($bad('address')) }}"
                       style="width:100%" placeholder="{{ __('client.address_ph') }}">
                {!! $err('address') !!}
                {!! $hint('address') !!}
            </div>
        </div>

        <div class="frow">
            <div style="grid-column:1/-1">
                <label class="f">{{ __('geo.location_url') }}</label>
                <div style="display:flex;gap:6px">
                    <input type="url" name="location_url" id="locUrl" maxlength="500" dir="ltr"
                           value="{{ $own('location_url') }}" style="flex:1;min-width:0"
                           class="{{ trim($bad('location_url')) }}"
                           placeholder="https://maps.app.goo.gl/..." oninput="autoDetect()">
                    <button type="button" class="btn" id="detectBtn" onclick="detectLocation()">🧭 {{ __('geo.detect') }}</button>
                </div>
                <div style="font-size:11px;color:var(--muted);margin-top:5px">{{ __('geo.location_url_hint') }}</div>
                {!! $err('location_url') !!}
                {!! $hint('location_url') !!}
                <div id="locMsg" style="font-size:11.5px;font-weight:700;margin-top:6px"></div>

                {{-- ⚠️ الإحداثيات بتتحفظ في حقول مخفية. الرابط ممكن يتغيّر
                     أو يبوظ، بس `lat/lng` هي اللي الخريطة وتوزيع المناطق
                     بيشتغلوا عليها — فبتتخزن كأرقام مش كنص جوه لينك. --}}
                <input type="hidden" name="lat" id="latField" value="{{ old('lat', $src?->lat) }}">
                <input type="hidden" name="lng" id="lngField" value="{{ old('lng', $src?->lng) }}">
            </div>
        </div>

        {{-- التبعية الإدارية — بتتنسخ من المصدر --}}
        <div style="font-size:12px;font-weight:900;color:var(--royal-blue);margin:18px 0 10px;padding-bottom:6px;border-bottom:1px solid var(--border)">{{ __('client.affiliation') }}</div>
        <div class="frow">
            <div>
                <label class="f">{{ __('branch.branch') }}</label>
                <select name="branch_id" style="width:100%" class="{{ trim($bad('branch_id')) }}">
                    <option value="">{{ __('branch.central') }}</option>
                    @foreach ($branches as $br)
                        <option value="{{ $br->id }}" @selected((int) $v('branch_id') === $br->id)>{{ $br->displayName() }}</option>
                    @endforeach
                </select>
                {!! $err('branch_id') !!}
                {!! $hint('branch_id') !!}
            </div>
            <div>
                <label class="f">{{ __('client.chain') }}</label>
                <div style="display:flex;gap:6px">
                    <select name="group_id" id="groupSel" style="flex:1;min-width:0" class="{{ trim($bad('group_id')) }}">
                        <option value="">— {{ __('client.independent') }} —</option>
                        @foreach ($groups as $grp)
                            <option value="{{ $grp->id }}" @selected((int) $v('group_id') === $grp->id)>{{ $grp->displayName() }}</option>
                        @endforeach
                    </select>
                    <button type="button" class="btn sm" onclick="openGroupBox()"
                            title="{{ __('client.new_chain') }}">+</button>
                </div>
                {!! $err('group_id') !!}
                {!! $hint('group_id') !!}
                <div style="font-size:11px;color:var(--muted);margin-top:5px">{{ __('client.chain_hint') }}</div>
            </div>
            <div>
                {{-- ⚠️ **المدير مش المندوب.** المندوب بيتخصص من شاشة توزيع
                     المناطق لأنه بيتغيّر مع خط السير كل شهر. ده المسؤول
                     التجاري عن الحساب — اللي متفاوض على العقد. --}}
                <label class="f">{{ __('client.account_manager') }}</label>
                <select name="manager_id" style="width:100%" class="{{ trim($bad('manager_id')) }}">
                    <option value="">— {{ __('client.pick_manager') }} —</option>
                    @foreach ($managers as $m)
                        <option value="{{ $m->id }}" @selected((int) $v('manager_id') === $m->id)>{{ $m->displayName() }}</option>
                    @endforeach
                </select>
                {!! $err('manager_id') !!}
                {!! $hint('manager_id') !!}
                <div style="font-size:11px;color:var(--muted);margin-top:5px">{{ __('client.account_manager_hint') }}</div>
            </div>
        </div>

        {{-- ═════ سلسلة جديدة — جوه نفس الصفحة ═════ --}}
        <div id="groupBox" style="display:none;border:1px dashed var(--royal-blue);border-radius:12px;padding:12px 14px;margin-bottom:12px;background:var(--card2)">
            <div style="font-size:12px;font-weight:900;color:var(--royal-blue);margin-bottom:4px">{{ __('client.new_chain') }}</div>
            {{-- ⚠️ الخصم والقناة مش هنا عن قصد — السلسلة اللي بتتعمل من
                 هنا وعاء بيجمّع الفروع بس. شروطها التجارية بتتظبط من
                 شاشة السلاسل، وإلا مستعجل بيحط رقم عشوائي وبيتطبق على
                 كل الفروع. --}}
            <div style="font-size:11px;color:var(--muted);margin-bottom:9px">{{ __('client.new_chain_hint') }}</div>
            <div class="frow" style="margin-bottom:8px">
                <div><label class="f">{{ __('client.chain_name') }}</label><input type="text" id="ngName" maxlength="190" style="width:100%"></div>
                <div><label class="f">{{ __('common.name_en') }}</label><input type="text" id="ngNameEn" dir="ltr" maxlength="190" style="width:100%"></div>
            </div>
            <div id="ngMsg" style="font-size:11.5px;margin-bottom:8px"></div>
            <div style="display:flex;gap:8px;justify-content:flex-end">
                <button type="button" class="btn sm" onclick="closeGroupBox()">{{ __('common.cancel') }}</button>
                <button type="button" class="btn sm gold" id="ngSave" onclick="saveGroup()">{{ __('common.save') }}</button>
            </div>
        </div>

        {{-- ═════ شروط الدفع ═════ --}}
        <div style="font-size:12px;font-weight:900;color:var(--royal-blue);margin:18px 0 10px;padding-bottom:6px;border-bottom:1px solid var(--border)">{{ __('client.pay_section') }}</div>
        <div style="font-size:11px;color:var(--muted);margin-bottom:9px">{{ __('client.pay_section_hint') }}</div>
        <div class="frow">
            <div>
                {{-- ⚠️ **قرار إدارة مش قرار مندوب.** الأبلكيشن بياخد
                     كاش/آجل من هنا ومابيسألش المندوب — إلا لو المدير
                     اختار «الاتنين»، وساعتها بس بيظهر سويتش في شاشة
                     البيع. الافتراضي حسب القناة: كاش فان وجملة كاش،
                     كي أكاونت وأونلاين آجل. و`danger` كاش إجباري مهما
                     اتكتب هنا. --}}
                <label class="f">{{ __('client.pay_method') }}</label>
                <select name="payment_terms" id="payTerms" style="width:100%"
                        onchange="togglePayDays()" class="{{ trim($bad('payment_terms')) }}">
                    <option value="" @selected($v('payment_terms') === null || $v('payment_terms') === '')>{{ __('client.terms_by_channel') }}</option>
                    @foreach (\App\Models\Client::PAY_TERMS as $pt)
                        <option value="{{ $pt }}" @selected($v('payment_terms') === $pt)>{{ __('client.terms_'.$pt) }}</option>
                    @endforeach
                </select>
                {!! $err('payment_terms') !!}
                {!! $hint('payment_terms') !!}
                <div style="font-size:11px;color:var(--muted);margin-top:5px">{{ __('client.pay_method_hint') }}</div>
            </div>
            <div class="payDaysBox">
                <label class="f">{{ __('client.pay_days') }}</label>
                <input type="number" min="0" max="365" name="payment_days" style="width:100%"
                       class="{{ trim($bad('payment_days')) }}"
                       value="{{ old('payment_days', $src?->payment_days) }}"
                       placeholder="{{ __('client.pay_days_ph') }}">
                {!! $err('payment_days') !!}
                {!! $hint('payment_days') !!}
            </div>
            <div class="payDaysBox">
                <label class="f">{{ __('client.pay_days_from') }}</label>
                {{-- ⚠️ **اختيار فاضي أول القايمة** — الخانة `nullable` في
                     السكيما وفي القواعد (`required_with:payment_days` بس)،
                     وأول عنصر مختار سلفاً كان بيختم **كل** عميل بـ«من
                     تاريخ الفاتورة» حتى لو مافيش أيام سداد أصلاً — قرار
                     محدش أخده وبيطلع بعدين في حساب الاستحقاق. نفس شكل
                     التوأم بتاع العقد (`contract_payment_days_from`). --}}
                <select name="payment_days_from" style="width:100%" class="{{ trim($bad('payment_days_from')) }}">
                    <option value="">— {{ __('client.pick_days_basis') }} —</option>
                    @foreach (\App\Models\Contract::DAYS_FROM as $df)
                        <option value="{{ $df }}"
                            @selected(old('payment_days_from', $src?->payment_days_from) === $df)>
                            {{ __('client.days_from_'.$df) }}
                        </option>
                    @endforeach
                </select>
                {!! $err('payment_days_from') !!}
                {!! $hint('payment_days_from') !!}
            </div>
        </div>
        {{-- ═══ سياسة المرتجع (قرار المالك ٨ أغسطس ٢٠٢٦) ═══
             ⚠️ **بتتعرّف على العميل مش على المندوب.** المندوب بيشوف
             المسموح هنا **بس** ويختار قبل ما يعمل المرتجع — من غير
             كده كل مندوب بيتصرف حسب علاقته بالعميل.
             ⚠️ ومصفوفة مش اختيار واحد: عميل ممكن ياخد كاش فوري أو
             تبديل حسب الموقف. --}}
        @php
            $curPolicies = old('return_policies', $src?->return_policies ?? []);
            $curPolicies = is_array($curPolicies) ? $curPolicies : [];
        @endphp
        <div class="frow">
            <div style="flex:2">
                <label class="f">{{ __('client.return_policies') }}</label>
                <div style="display:flex;flex-wrap:wrap;gap:12px;padding:8px 2px">
                    @foreach (\App\Models\Client::RETURN_POLICIES as $rp)
                        <label style="display:flex;align-items:center;gap:6px;font-size:12.5px">
                            <input type="checkbox" name="return_policies[]" value="{{ $rp }}"
                                   @checked(in_array($rp, $curPolicies, true))>
                            {{ __('field.return_policy_'.$rp) }}
                        </label>
                    @endforeach
                </div>
                <div style="font-size:11px;color:var(--muted);margin-top:2px">
                    {{ __('client.return_policies_hint') }}</div>
            </div>
        </div>

        {{-- ⚠️ **العقد يغلب الخانتين دول.** العقد ورقة موقّعة والخانة
             إعداد داخلي — فلو العميل ليه عقد سارٍ فيه مدة سداد، المدة
             دي هي اللي بتمشي و`Client::paymentDays()` بترجّعها. --}}
        {{-- ⚠️ `liveContract()` مش `->contract` — العقد ممكن ييجي من
             السلسلة، و`->contract` بترجّع عقد الفرع لوحده. --}}
        @php $payCt = $src?->liveContract(); @endphp
        @if ($payCt !== null && $payCt->paymentDays() !== null)
            <div class="alert info" style="margin-top:10px">
                <span>📄</span>
                <span>{{ __('client.pay_contract_wins', [
                    'days' => $payCt->paymentDays(),
                    'basis' => $payCt->paymentBasisLabel(),
                ]) }}</span>
            </div>
        @endif

        {{-- ═════ طرق التواصل عند العميل ═════ --}}
        <div style="font-size:12px;font-weight:900;color:var(--royal-blue);margin:18px 0 10px;padding-bottom:6px;border-bottom:1px solid var(--border)">{{ __('client.contacts') }}</div>
        <div style="font-size:11px;color:var(--muted);margin-bottom:9px">{{ __('client.contacts_hint') }}</div>
        {{-- ⚠️ الفهرس مكتوب صراحةً (`contacts[0][name]`) مش `contacts[][name]`.
             PHP بيفسّر كل `[]` كعنصر **جديد**، فالاسم كان بيروح لصف
             والتليفون لصف تاني — يعني 3 جهات تواصل نص كل واحدة فاضي. --}}
        <div id="contactRows">
            {{-- ⚠️ `(array)` لازم: القيمة اللي بترجع من `old()` بتيجي زي ما
                         المستخدم بعتها. لو بعت `contacts` كنص، الـ`foreach`
                         بترمي 500 أبيض بدل صفحة الأخطاء. --}}
                    {{-- ⚠️ **الاسم `$contact` مش `$ct`** (إصلاح 2026-08-08).
                         `$ct` هو **عقد العميل** المستخدم تحت في بلوك
                         العقد — ومتغيّر الـ`@foreach` في بليد بيفضل
                         موجود بعد ما اللوب تخلص. فالعميل اللي عنده جهات
                         تواصل كان `$ct` بتاعه بيتحوّل لآخر جهة تواصل
                         (أراي)، و`$ct?->type_key` ترمي 500 على شاشة
                         التعديل. العميل اللي مالوش جهات تواصل كان بيفتح
                         عادي — عشان كده الباج عاش. --}}
                    @foreach ((array) old('contacts', $src?->contactList() ?? []) as $i => $contact)
                <div class="frow contact-row" style="margin-bottom:6px">
                    <div><input type="text" name="contacts[{{ $i }}][name]" dir="ltr" maxlength="120" value="{{ $contact['name'] ?? '' }}" placeholder="{{ __('client.contact_name_ph') }}" style="width:100%"></div>
                    <div><input type="text" name="contacts[{{ $i }}][role]" dir="ltr" maxlength="120" value="{{ $contact['role'] ?? '' }}" placeholder="{{ __('client.contact_role_ph') }}" style="width:100%"></div>
                    <div style="display:flex;gap:6px">
                        <input type="text" name="contacts[{{ $i }}][phone]" dir="ltr" maxlength="30" value="{{ $contact['phone'] ?? '' }}" placeholder="01000000000" style="flex:1;min-width:0">
                        <button type="button" class="btn sm red" onclick="this.closest('.contact-row').remove()">&times;</button>
                    </div>
                </div>
            @endforeach
        </div>
        <button type="button" class="btn sm" onclick="addContact()">+ {{ __('client.add_contact') }}</button>

        {{-- ⚠️ **الحفظ موجود في الخطوات التلاتة** (2026-08-08). كان في
             آخر خطوة بس — يعني تعديل تليفون في الخطوة الأولى كان
             بيستلزم المرور على العقد والضريبة عشان توصل للزرار.
             وحارس الإرسال بيفحص المراحل التلاتة على أي حال، فالحفظ
             من هنا آمن: أول خانة ناقصة بيفتح مرحلتها ويقف عليها. --}}
        <div class="formbar">
            <span class="formbar-sp"></span>
            <button type="submit" class="btn gold">💾 {{ $editing ? __('client.save_changes') : __('client.save_client') }}</button>
            <button type="button" class="btn gold" onclick="goStep(2)">{{ __('client.step_contract') }} →</button>
        </div>
    </div>

    {{-- ══════════════════ 2. العقد ══════════════════ --}}
    <div class="card step-pane" data-pane="2" style="display:none">
        <h3>{{ __('client.step_contract') }}</h3>

        {{-- ═════ التسعير — بيتحفظ سواء فيه عقد أو مفيش ═════ --}}
        {{-- ⚠️ **بره بلوك العقد — دلوقتي بجد.** التعليق ده كان موجود
             والحقول كانت **جوه** البلوك فعلاً؛ ماكانش بيبان لأن البلوك
             ماكانش بيتقفل أبداً. أول ما التشيك بوكس رجع في التعديل،
             `toggleContract()` بقت تعطّل كل حاجة جوه البلوك — يعني عميل
             من غير عقد بيتبعت من غير `price_list` ولا `discount`،
             والاتنين `required`، فبياخد 422 على خانتين مش شايفهم أصلاً
             ومفيش طريقة يوصلهم. --}}
        <div style="font-size:12px;font-weight:900;color:var(--royal-blue);margin:0 0 10px;padding-bottom:6px;border-bottom:1px solid var(--border)">{{ __('ops.pricing') }}</div>
        {{-- ⚠️ **التصنيف مش هنا.** هو نتيجة سلوك مش مدخل: بيدفع في
             مواعيده ولا لأ، بيكبر ولا لأ. تحديده وقت التعريف تخمين
             بيتحوّل لحقيقة في الشاشة — عميل يتعلّم «تحصيل فوري» من
             يومه الأول ويتقفل عليه الآجل من غير سبب. العميل الجديد
             بيبدأ `grow` وبيتظبط من كارته بعد أول تعاملات. --}}
        <div class="frow">
            <div>
                <label class="f">{{ __('client.price_list') }} {!! $star !!}</label>
                {{-- ⚠️ **القوايم من الداتابيز مش متبتّتة** (2026-08-07).
                     كانت «قديم/جديد» مكتوبين في الفيو، فأي قايمة جديدة
                     بتتعمل من شاشة التسعير ماكانش فيه طريقة يتسكّن
                     عليها عميل. والقيمة بقت `price_list_id` لأن ده
                     اللي الفاتورة بتتحاسب منه فعلاً.

                     ⚠️ **مفيش قائمة مختارة سلفاً.** «الجديدة» كانت
                     الافتراضي، فاللي بيدخل الداتا بيعدّي عليها من
                     غير ما يقرا — والعميل اللي المفروض على القائمة
                     القديمة بياخد أسعار الجديدة وبيرفض الفاتورة. --}}
                <select name="price_list_id" style="width:100%" data-req class="{{ trim($bad('price_list_id')) }}">
                    <option value="">— {{ __('client.pick_price_list') }} —</option>
                    @foreach ($priceLists as $pl)
                        <option value="{{ $pl->id }}" @selected((int) $v('price_list_id') === $pl->id)>
                            {{ $pl->displayName() }}@unless ($pl->active) — {{ __('common.inactive') }}@endunless
                        </option>
                    @endforeach
                </select>
                {!! $err('price_list_id') !!}
                {!! $hint('price_list_id') !!}
                <div style="font-size:11px;color:var(--muted);margin-top:5px">{{ __('client.price_list_hint') }}</div>
            </div>
            <div>
                <label class="f">{{ __('client.custom_discount') }} % {!! $star !!}</label>
                <input type="number" step="0.5" min="0" max="100" name="discount" data-req style="width:100%"
                       class="{{ trim($bad('discount')) }}"
                       value="{{ old('discount', $src ? round((float) $src->discount * 100, 2) : 0) }}">
                {!! $err('discount') !!}
                {!! $hint('discount') !!}
                <div style="font-size:11px;color:var(--muted);margin-top:5px">{{ __('client.custom_discount_hint') }}</div>
            </div>
            {{-- ⚠️ **شروط الدفع اتنقلت لخطوة ١** (2026-08-08). كانت هنا
                 في خطوة «التعاقد»، وعميل الكاش فان مابيوصلش للخطوة دي
                 أصلاً — فالمدير التجاري كان بيدوّر على الخانة ومايلاقيهاش
                 ويفتكرها مش موجودة. كاش/آجل قرار تجاري أساسي زي القناة
                 والتصنيف، مش بند من بنود العقد. --}}
            {{-- ⚠️ **في التعديل بس.** التصنيف نتيجة سلوك مش مدخل،
                 فمالوش لازمة وقت التعريف (الشرح فوق). بس المودال
                 القديم كان **المكان الوحيد في السيستم كله** اللي
                 بيظبطه — وبشيله فضل كل عميل على `grow` للأبد، يعني
                 `danger` و`credit` و`internal` بقوا كود ميّت. --}}
            @if ($editing)
                <div>
                    <label class="f">{{ __('client.category') }}</label>
                    <select name="category" style="width:100%" class="{{ trim($bad('category')) }}">
                        {{-- ⚠️ المفتاح هو القيمة — `CATEGORIES` شكلها
                             `key => [label, css]`، والـforeach من غير
                             `$k =>` كان بيحط الأراي كله في `value`. --}}
                        @foreach (array_keys(Client::CATEGORIES) as $ck)
                            <option value="{{ $ck }}" @selected($v('category') === $ck)>{{ __('enums.category.'.$ck) }}</option>
                        @endforeach
                    </select>
                    {!! $err('category') !!}
                    {!! $hint('category') !!}
                </div>
            @endif
        </div>

        {{-- ⚠️ **مفيش تشيك بوكس «العميل ده له عقد» تاني.**
             القايمة نفسها بقت بتقول: «اتفاق تجاري بدون عقد» نوع،
             و«تعامل بالطلب بدون مدة» مدة. التشيك بوكس كان بيسأل سؤال
             القايمة بتجاوب عليه — واللي بيدخل الداتا كان بيسيبه فاضي
             ويعدّي، فالعميل بيتحفظ من غير أي شروط تجارية مسجّلة.

             ⚠️ الحقل المخفي بيفضل: `syncContract()` و`required_if`
             الاتنين بيقروا منه، والـAPI لسه بيدعم عميل من غير عقد
             (البوست اللي مابيبعتش `has_contract` خالص). --}}
        {{-- ⚠️ **في الإنشاء ثابت 1، في التعديل تشيك بوكس.** العميل
             الجديد لازم يتحدّد له نوع تعامل (ولو «بدون عقد»). لكن في
             التعديل الثبات ده كان كارثة:

             • **فرع بياخد عقد سلسلته** (`liveContract()` بترجع عقد
               المجموعة) `$ct` بتاعه `null`، فالويزارد كان بيرسم بلوك
               فاضي ويبعت `has_contract=1` — و`syncContract` بتعمله عقد
               خاص فاضي فعّال. الفرع بيفقد خصم السلسلة كله عشان حد
               عدّل تليفونه.
             • **عميل من غير عقد أصلاً** (كاش فان/جملة) كان بياخد 3
               أخطاء فاليديشن على أي حفظ.
             • **عقد اتوقف عن قصد** كان بيترجّع `active` في صمت. --}}
        @if ($editing)
            {{-- الحقل المخفي بيسبق: البوست بياخد آخر قيمة، فلو التشيك
                 بوكس مش متعلّم بيوصل `0` بدل ما مايوصلش خالص. --}}
            <input type="hidden" name="has_contract" value="0">
            <label style="display:flex;gap:8px;align-items:center;font-size:12.5px;font-weight:800;margin-bottom:12px;cursor:pointer">
                <input type="checkbox" name="has_contract" value="1" id="hasContract"
                       @checked(old('has_contract', $ct !== null ? 1 : 0))
                       onchange="toggleContract()">
                {{ __('client.has_contract') }}
            </label>

            @if ($ct === null && $src?->group?->contract)
                <div class="alert warn" style="margin-bottom:12px">
                    <span>⛓</span>
                    <span>{{ __('client.contract_from_chain_note', ['chain' => $src->group->displayName()]) }}</span>
                </div>
            @endif
        @else
            <input type="hidden" name="has_contract" value="1">
        @endif


        <div id="contractBox" @style(['display:none' => $editing && $ct === null])>
            @php
    // ⚠️ **الأنواع القديمة بتبان بس لو العقد ده نوعه واحد
                // منها.** الـ22 عقد الحقيقيين فيهم `supply_agreement`
                // و`annual` وغيرهم — مش في القايمة الجديدة. لو خبّيناهم
                // خالص، فتح عقد قديم بيوري الدروب داون فاضية، وأول حفظ
                // بيغيّر نوع العقد في صمت.
                $currentType = old('contract_type', $ct?->type_key);
                $typeOptions = Contract::TYPE_CHOICES;

                if ($currentType && ! in_array($currentType, $typeOptions, true)) {
                    $typeOptions[] = $currentType;
                }
            @endphp

            {{-- ═════ نوع التعاقد وخصمه — أول حاجة تتحدد ═════ --}}
            {{-- ⚠️ **الترتيب مقصود.** النوع بيحدد طبيعة العلاقة، وخصم
                 الفاتورة هو الرقم الوحيد اللي بينزل على سعر البيع فعلاً —
                 فالاتنين جنب بعض في أول صف. لما كان الخصم تحت في نص
                 الصفحة، كان بيتنسى ويتحفظ عقد بخصم صفر. --}}
            <div class="frow">
                <div>
                    <label class="f">{{ __('client.contract_type') }} {!! $star !!}</label>
                    {{-- ⚠️ **مفيش نوع مختار سلفاً.** لما كان «اتفاق» هو
                         الافتراضي، اللي بيدخل الداتا كان بيعدّي عليه من
                         غير ما يقرا — وبعد شهور محدش يعرف ده كان عقد
                         توريد ولا موزع معتمد ولا وكالة تجارية. --}}
                    <select name="contract_type" style="width:100%" data-req-contract
                            class="{{ trim($bad('contract_type')) }}">
                        <option value="">— {{ __('client.pick_contract_type') }} —</option>
                        @foreach ($typeOptions as $tk)
                            <option value="{{ $tk }}" @selected($currentType === $tk)>
                                {{ __('client.contract_type_'.$tk) }}
                            </option>
                        @endforeach
                    </select>
                    {!! $err('contract_type') !!}
                    {!! $hint('contract_type') !!}
                </div>
                {{-- ⚠️ **خصم الفاتورة حقل أساسي مش تشيك بوكس.** هو البند
                     الوحيد اللي بينزل على سعر البيع فعلاً، وكل عقد تقريباً
                     فيه واحد. لما كان جوه التشيك بوكسيس مع البنود النادرة،
                     كان بيتنسى ويتحفظ عقد بخصم صفر. --}}
                <div>
                    <label class="f">{{ __('client.preset_invoice_discount') }} % {!! $star !!}</label>
                    {{-- المقفول بيبعت `on=0` عشان السيرفر مايحاولش
                         يكتب فوق البند المكتوب بإيد. --}}
                    <input type="hidden" name="clause[invoice_discount][on]" value="{{ $locked('invoice_discount') ? 0 : 1 }}">
                    {{-- ⚠️ **المقفول لازم يبعت قيمة برضه.** قاعدة
                         `clause.invoice_discount.value` معلّقة على
                         `required_if:has_contract,1` مش على `on` — والبند
                         المقفول معناه إن فيه عقد، يعني `has_contract=1`.
                         الخانة `disabled` مابتتبعتش، فالتحقق كان بيرفض
                         الحفظ **كل مرة** برسالة تحت خانة رمادية. القيمة
                         دي مابتتكتبش: `syncClauses()` بتعمل `continue`
                         على المقفول قبل ما تقرا أي حاجة. --}}
                    @if ($locked('invoice_discount'))
                        <input type="hidden" name="clause[invoice_discount][value]" value="0">
                    @endif
                    <input type="number" step="0.5" min="0" max="100" style="width:100%"
                           @if (! $locked('invoice_discount')) data-req-contract @endif
                           class="{{ trim($bad('clause.invoice_discount.value')) }}"
                           @disabled($locked('invoice_discount')) @if ($locked('invoice_discount')) data-keep-disabled="1" @endif
                           name="clause[invoice_discount][value]"
                           value="{{ $presetVal('invoice_discount') !== '' ? $presetVal('invoice_discount') : '' }}">
                    {!! $err('clause.invoice_discount.value') !!}
                    {!! $hint('cl_invoice_discount_value') !!}
                    <div style="font-size:11px;color:var(--muted);margin-top:5px">
                        {{ $locked('invoice_discount') ? '🔒 '.__('client.clause_locked_hint') : __('client.invoice_discount_hint') }}
                    </div>
                </div>
            </div>

            {{-- ═════ مدة التعاقد وتواريخه ═════ --}}
            {{-- ⚠️ **التلاتة في صف واحد.** لما كانت المدة لوحدها في صف
                 والتواريخ في صف تحتها، الصف الأول كان بيطلع خانة واحدة
                 مفرودة على عرض الصفحة كله — شكلها غلط، والعين مابتربطش
                 المدة بالتواريخ اللي هي بتحسبها.

                 ⚠️ الإخفاء بقى **على الخانة** مش على الصف: «تعامل
                 بالطلب» بيخبّي التاريخين والمدة تفضل، و«مفتوح المدة»
                 بيخبّي النهاية بس. لو خبّينا الصف كله كنا هنخبّي المدة
                 نفسها اللي المستخدم لسه مختارها.

                 ⚠️ `keep` بيمنع الجريد إنه يطوي الأعمدة المخبّية —
                 من غيرها الدروب داون بتتمدد على الشاشة كلها قبل
                 الاختيار وبترجع طبيعية بعده. --}}
            <div class="frow keep">
                <div>
                    <label class="f">{{ __('client.duration') }} {!! $star !!}</label>
                    <select name="contract_duration" id="durationSel" style="width:100%" data-req-contract
                            class="{{ trim($bad('contract_duration')) }}"
                            onchange="syncDuration()">
                        <option value="">— {{ __('client.pick_duration') }} —</option>
                        @foreach (array_keys(Contract::DURATIONS) as $dk)
                            <option value="{{ $dk }}"
                                data-end="{{ Contract::durationHasEnd($dk) ? 1 : 0 }}"
                                data-dates="{{ Contract::durationHasDates($dk) ? 1 : 0 }}"
                                @selected(old('contract_duration', $ct?->duration) === $dk)>
                                {{ __('client.duration_'.$dk) }}
                            </option>
                        @endforeach
                    </select>
                    {!! $err('contract_duration') !!}
                    {!! $hint('contract_duration') !!}
                    <div id="durationHint" style="font-size:11px;color:var(--muted);margin-top:5px"></div>
                </div>
                <div id="startsAtBox" style="display:none">
                    <label class="f">{{ __('client.starts_at') }} {!! $star !!}</label>
                    {{-- ⚠️ **`syncDuration()` من غير باراميتر — يعني احسب
                         النهاية تاني.** كانت `syncDuration(true)` اللي
                         معناها «سيب النهاية زي ما هي»، فتغيير تاريخ
                         البداية ماكانش بيحرّك النهاية خالص. النتيجة:
                         المستخدم بيختار 3 شهور، النهاية بتتحسب صح، وبعدين
                         بيغيّر البداية فالنهاية بتفضل مكانها والفرق يبقى
                         67 يوم — والحفظ بيترفض وهو مش فاهم ليه. --}}
                    {{-- ⚠️ `data-req-contract` مش `data-req`: البوكس بيتخبّى
                         في «تعامل بالطلب»، و`hiddenInPane` بتستثني
                         المخبّي — فالنوع ده بيعدّي والباقي لأ. --}}
                    <input type="date" name="contract_starts_at" id="startsAt" style="width:100%"
                           data-req-contract
                           class="{{ trim($bad('contract_starts_at')) }}"
                           onchange="syncDuration()"
                           value="{{ old('contract_starts_at', $ct?->starts_at?->toDateString() ?? today()->toDateString()) }}">
                    {!! $err('contract_starts_at') !!}
                    {!! $hint('contract_starts_at') !!}
                </div>
                <div id="endsAtBox" style="display:none">
                    <label class="f">{{ __('client.ends_at') }}</label>
                    <input type="date" name="contract_ends_at" id="endsAt" style="width:100%"
                           class="{{ trim($bad('contract_ends_at')) }}"
                           onchange="showSpan()"
                           value="{{ old('contract_ends_at', $ct?->ends_at?->toDateString()) }}">
                    {!! $err('contract_ends_at') !!}
                    {!! $hint('contract_ends_at') !!}
                    <div id="spanHint" style="font-size:11px;color:var(--muted);margin-top:5px"></div>
                </div>
            </div>


            {{-- ═════ بنود الخصم ═════ --}}
            <div style="font-size:12px;font-weight:900;color:var(--royal-blue);margin:18px 0 10px;padding-bottom:6px;border-bottom:1px solid var(--border)">{{ __('client.discount_clauses') }}</div>
            <div class="alert warn" style="margin-bottom:12px">
                <span>⚠️</span><span>{{ __('client.only_invoice_discount_note') }}</span>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:10px">
                @foreach (Contract::CLAUSE_PRESETS as $key => $spec)
                    {{-- خصم الفاتورة طلع فوق كحقل أساسي --}}
                    @continue($key === 'invoice_discount')
                    @php $isPct = $spec['mode'] === 'pct'; @endphp
                    <div style="border:1px solid var(--border);border-radius:10px;padding:11px 13px;background:var(--card2)">
                        {{-- ⚠️ **مفيش قفل هنا.** الصفحة دي بتعمل عقد **جديد**
                             فاضي، فالبند المكتوب بإيد في عقد المصدر مالوش
                             نظير يتجمع معاه. القفل في كارت العميل بس، وهناك
                             السبب إن العقد فيه البندين مع بعض. لو قفلنا هنا،
                             الفرع الجديد كان هيفتح بخصم صفر. --}}
                        <label style="display:flex;gap:8px;align-items:center;font-size:12.5px;font-weight:800;cursor:pointer">
                            <input type="hidden" name="clause[{{ $key }}][on]" value="0">
                            <input type="checkbox" name="clause[{{ $key }}][on]" value="1"
                                   {{-- ⚠️ **باين ومتعلّم، مش مخفي.** لو فضّيناه،
                                        البند اللي في العقد الأصلي بيختفي من
                                        الشاشة خالص واللي بيراجع يفتكر إن
                                        العميل مالوش الشرط ده. --}}
                                   id="cl_{{ $key }}" @checked($presetOn($key))
                                   @disabled($locked($key)) @if ($locked($key)) data-keep-disabled="1" @endif
                                   onchange="toggleClause('{{ $key }}')">
                            {{ __('client.preset_'.$key) }}
                            @if ($locked($key))<span title="{{ __('client.clause_locked_hint') }}">🔒</span>@endif
                            <span class="b {{ $isPct ? 'b-green' : 'b-gray' }}" style="margin-inline-start:auto;font-size:10.5px">
                                {{ $isPct ? '%' : __('common.currency') }}
                            </span>
                        </label>
                        <div id="box_{{ $key }}" style="display:none;margin-top:9px">
                            <input type="number" name="clause[{{ $key }}][value]"
                                   step="{{ $isPct ? '0.5' : '1' }}" min="0" max="{{ $isPct ? '100' : '99999999' }}"
                                   @disabled($locked($key)) @if ($locked($key)) data-keep-disabled="1" @endif
                                   value="{{ $presetVal($key) }}" style="width:100%"
                                   placeholder="{{ $isPct ? __('client.pct_placeholder') : __('client.amount_placeholder') }}">
                            <input type="text" name="clause[{{ $key }}][note]" maxlength="500"
                                   @disabled($locked($key)) @if ($locked($key)) data-keep-disabled="1" @endif
                                   value="{{ old("clause.$key.note", $presets[$key]['note']) }}"
                                   style="width:100%;margin-top:6px"
                                   placeholder="{{ __('common.notes') }}">
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- ═════ السداد ═════ --}}
            <div style="font-size:12px;font-weight:900;color:var(--royal-blue);margin:18px 0 10px;padding-bottom:6px;border-bottom:1px solid var(--border)">{{ __('client.payment_terms') }}</div>
            {{-- ⚠️ **الاسم بقى «السداد» مش «المدة والسداد».** المدة
                 والتواريخ اتنقلوا فوق جنب النوع، وقسم اسمه «المدة»
                 ومفيهوش مدة بيخلّي المستخدم يدوّر فيه على حاجة مش
                 موجودة. --}}
            <div class="frow">
                <div>
                    <label class="f">{{ __('client.payment_days') }}</label>
                    <input type="number" step="1" min="0" max="365" name="contract_payment_days" style="width:100%"
                           id="paymentDays" class="{{ trim($bad('contract_payment_days')) }}"
                           value="{{ old('contract_payment_days', $ct?->paymentDays()) }}">
                    {!! $err('contract_payment_days') !!}
                    {!! $hint('contract_payment_days') !!}
                </div>
                <div>
                    <label class="f">{{ __('client.days_counted_from') }}</label>
                    {{-- ⚠️ **مفيش أساس مختار سلفاً.** الرقم من غير نقطة
                         بداية مالوش معنى: 60 يوم من أول توريد غير 60 يوم
                         من كل فاتورة. لو كان فيه افتراضي، المستخدم بيكتب
                         الرقم ويعدّي والاستحقاق يطلع بميعاد مالوش أساس
                         حد قرره. --}}
                    <select name="contract_payment_days_from" style="width:100%"
                            id="daysFrom" class="{{ trim($bad('contract_payment_days_from')) }}">
                        <option value="">— {{ __('client.pick_days_basis') }} —</option>
                        @foreach (Contract::DAYS_FROM as $basis)
                            <option value="{{ $basis }}"
                                @selected(old('contract_payment_days_from', $ct?->paymentBasis()) === $basis)>
                                {{ __('client.days_from_'.$basis) }}
                            </option>
                        @endforeach
                    </select>
                    {!! $err('contract_payment_days_from') !!}
                    {!! $hint('contract_payment_days_from') !!}
                </div>
            </div>

            {{-- ═════ ملف العقد ═════ --}}
            {{-- ⚠️ **لوحده في صف.** رفع الملف بياخد مساحة وبيحتاج
                 انتباه — حطّه جنب حقل تاني بيخلّي حد يعدّي عليه. --}}
            <div style="font-size:12px;font-weight:900;color:var(--royal-blue);margin:18px 0 10px;padding-bottom:6px;border-bottom:1px solid var(--border)">{{ __('client.contract_file') }}</div>
            <div class="frow">
                <div style="grid-column:1/-1">
                    <label class="f">{{ __('client.contract_file') }}</label>
                    <input type="file" name="contract_file" accept=".pdf,.jpg,.jpeg,.png" style="width:100%">
                    <div style="font-size:11px;color:var(--muted);margin-top:5px">{{ __('client.contract_file_hint') }}</div>
                </div>
            </div>

            {{-- ═════ بنود العقد والملاحظات ═════ --}}
            {{-- ⚠️ **عمودين جنب بعض.** البنود الحرة والملاحظات الاتنين
                 نص حر بيتكتب مرة لعقد واحد — قراهم مع بعض أسهل من
                 التنقّل بينهم في صفين. --}}
            <div style="font-size:12px;font-weight:900;color:var(--royal-blue);margin:18px 0 10px;padding-bottom:6px;border-bottom:1px solid var(--border)">{{ __('client.contract_clauses') }}</div>
            <div class="frow">
                <div>
                    <label class="f">{{ __('client.contract_clauses') }} <span style="color:var(--muted);font-weight:400">· EN</span></label>
                    <div id="clauseRows">
                        @foreach ((array) old('contract_clauses', $ct?->clauseList() ?? []) as $line)
                            @if (trim((string) $line) !== '')
                                <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px">
                                    <input type="text" name="contract_clauses[]" dir="ltr" maxlength="500"
                                           value="{{ $line }}" style="flex:1;min-width:0">
                                    <button class="btn sm red" type="button" onclick="this.parentElement.remove()">{{ __('client.remove_clause') }}</button>
                                </div>
                            @endif
                        @endforeach
                    </div>
                    <button class="btn sm" type="button" onclick="addClauseRow('clauseRows')">+ {{ __('client.add_clause') }}</button>
                </div>
                <div>
                    <label class="f">{{ __('common.notes') }} <span style="color:var(--muted);font-weight:400">· EN</span></label>
                    <textarea name="contract_note" dir="ltr" rows="2" style="width:100%">{{ old('contract_note', $ct?->note) }}</textarea>
                </div>
            </div>
        </div>

        <div class="formbar">
            <button type="button" class="btn" onclick="goStep(1)">← {{ __('client.step_identity') }}</button>
            <span class="formbar-sp"></span>
            <button type="submit" class="btn gold">💾 {{ $editing ? __('client.save_changes') : __('client.save_client') }}</button>
            <button type="button" class="btn gold" onclick="goStep(3)">{{ __('client.step_tax') }} →</button>
        </div>
    </div>

    {{-- ══════════════════ 3. الضريبة ══════════════════ --}}
    <div class="card step-pane" data-pane="3" style="display:none">
        <h3>{{ __('client.step_tax') }}</h3>

        <div class="alert info" style="margin-bottom:14px"><span>ℹ️</span><span>{{ __('client.tax_off_note') }}</span></div>

        <input type="hidden" name="taxable" value="0">
        <label style="display:flex;gap:8px;align-items:center;font-size:13px;font-weight:800;margin-bottom:14px">
            <input type="checkbox" name="taxable" value="1" id="taxable"
                   @checked(old('taxable', $src?->taxable ? 1 : 0))
                   onchange="toggleTax()">
            {{ __('client.taxable') }}
        </label>

        <div id="taxBox" style="display:none">
            <div class="frow">
                <div>
                    <label class="f">{{ __('client.tax_rate') }} %</label>
                    <input type="number" step="0.5" min="0" max="100" name="tax_rate" style="width:100%"
                           class="{{ trim($bad('tax_rate')) }}"
                           value="{{ old('tax_rate', $src ? round((float) $src->tax_rate * 100, 2) : 14) }}">
                    {!! $err('tax_rate') !!}
                    {!! $hint('tax_rate') !!}
                </div>
                <div>
                    <label class="f">{{ __('client.tax_cycle') }}</label>
                    <select name="tax_cycle" style="width:100%" class="{{ trim($bad('tax_cycle')) }}">
                        <option value="">— {{ __('client.pick_tax_cycle') }} —</option>
                        @foreach (Client::TAX_CYCLES as $cycle)
                            <option value="{{ $cycle }}" @selected($v('tax_cycle') === $cycle)>{{ __('client.tax_cycle_'.$cycle) }}</option>
                        @endforeach
                    </select>
                    {!! $err('tax_cycle') !!}
                    {!! $hint('tax_cycle') !!}
                </div>
                <div>
                    <label class="f">{{ __('client.tax_id') }}</label>
                    <input type="text" name="tax_id" maxlength="40" dir="ltr" style="width:100%"
                           class="{{ trim($bad('tax_id')) }}" placeholder="123-456-789"
                           {{-- ⚠️ **`$own` مش `old()` لوحدها.** الخانة دي جوه
                                `#taxBox` المخبّي، والمخبّي بيتبعت فاضي — فأول
                                حفظ من الشاشة دي كان بيمسح الرقم الضريبي لكل
                                عميل، وهو الرقم اللي رفع الفاتورة الإلكترونية
                                كله متعلّق بيه. --}}
                           value="{{ $own('tax_id') }}">
                    {!! $err('tax_id') !!}
                    {!! $hint('tax_id') !!}
                </div>
                <div>
                    <label class="f">{{ __('client.eta_type') }}</label>
                    <select name="eta_type" style="width:100%" class="{{ trim($bad('eta_type')) }}">
                        <option value="">— {{ __('client.pick_eta_type') }} —</option>
                        <option value="B" @selected($v('eta_type') === 'B')>{{ __('client.eta_type_b') }}</option>
                        <option value="P" @selected($v('eta_type') === 'P')>{{ __('client.eta_type_p') }}</option>
                    </select>
                    {!! $err('eta_type') !!}
                    {!! $hint('eta_type') !!}
                </div>
            </div>

            {{-- الخصم الخاص بالضريبة = بند حجز الضمان في العقد --}}
            <div class="alert warn" style="margin-top:6px">
                <span>📌</span><span>{{ __('client.withholding_lives_in_contract') }}</span>
            </div>
        </div>

        <div style="margin-top:16px">
            <label class="f">{{ __('common.notes') }} <span style="color:var(--muted);font-weight:400">· EN</span></label>
            <textarea name="notes" dir="ltr" rows="2" style="width:100%">{{ old('notes', $src?->notes) }}</textarea>
        </div>

        <div class="formbar">
            <button type="button" class="btn" onclick="goStep(2)">← {{ __('client.step_contract') }}</button>
            <span class="formbar-sp"></span>
            <button type="submit" class="btn gold">💾 {{ $editing ? __('client.save_changes') : __('client.save_client') }}</button>
        </div>
    </div>
</form>

@endsection

@section('scripts')
@php
    // ⚠️ ممنوع دايركتيف الـjson بمصفوفة جوه البليد — بيكسّر الـparser.
    //    `json_encode` العادية هي الطريقة الوحيدة الآمنة هنا.
    $formStrings = json_encode([
        'placeholder' => __('client.contract_clause'),
        'remove' => __('client.remove_clause'),
        'filtered' => __('geo.zone_filtered_hint'),
        'empty' => __('geo.no_zone_in_governorate'),
        'required' => __('common.field_required'),
        'contactNamePh' => __('client.contact_name_ph'),
        'contactRolePh' => __('client.contact_role_ph'),
        'openHint' => __('client.duration_hint_open'),
        'perOrderHint' => __('client.duration_hint_per_order'),
        'daysCount' => __('client.days_count', ['days' => '__N__']),
        // شهور كل مدة — من `Contract::DURATIONS` مش مكتوبة تاني هنا
        'months' => collect(\App\Models\Contract::DURATIONS)
            ->map(fn ($d) => $d['months'])->all(),
        'errorStep' => $errorStep,
        'zoneUrl' => route('erp.zones.quick'),
        'groupUrl' => route('erp.groups.quick'),
        'groupNeedsName' => __('client.chain_needs_name'),
        'geoUrl' => route('erp.geo.resolve'),
        'zoneNeedsName' => __('geo.zone_needs_name'),
        'zoneAdded' => __('geo.zone_added'),
        'detecting' => __('geo.detecting'),
        'detected' => __('geo.detected'),
        'detectFailed' => __('geo.detect_failed'),
        'saving' => __('common.saving'),
        'save' => __('common.save'),
        'failed' => __('common.failed'),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);

    $presetKeys = json_encode(array_keys(App\Models\Contract::CLAUSE_PRESETS));
@endphp
<style>
/* ═══ شريط إجراءات الفورم ═══
   ⚠️ **ملزوق في آخر الشاشة عن قصد.** خطوة العقد بتطول لتحت أوي
   (البنود والتواريخ والمرفق)، وزرار حفظ في آخر الصفحة معناه إن
   اللي عدّل خانة واحدة فوق لازم يسكرول لتحت خالص عشان يحفظ.
   ⚠️ و`z-index` أقل من المودالات — الدايالوج بتاع «سلسلة جديدة»
   و«منطقة جديدة» لازم يغطّي الشريط مش العكس. */
.formbar{
  position:sticky;bottom:0;z-index:20;
  display:flex;gap:8px;align-items:center;flex-wrap:wrap;
  margin:16px -18px -16px;padding:12px 18px;
  background:var(--card);
  border-top:1px solid var(--border);
  border-radius:0 0 var(--r-md) var(--r-md);
  box-shadow:0 -6px 18px rgba(0,0,0,.06);
}
/* بيدفع الحفظ والتالي لآخر الشريط والرجوع فاضل في أوله */
.formbar-sp{flex:1}

@media (max-width:640px){
  /* على الموبايل الأزرار بتنزل سطر تاني — الفاصل مالوش لازمة */
  .formbar-sp{flex-basis:100%;height:0}
}

.step-btn{display:flex;align-items:center;gap:8px;padding:9px 16px;border-radius:10px;border:1px solid var(--border);
          background:var(--card);cursor:pointer;font-family:inherit;font-weight:800;font-size:12.5px;color:var(--muted)}
.step-btn .step-n{display:inline-flex;align-items:center;justify-content:center;width:21px;height:21px;border-radius:50%;
                  background:var(--border);color:var(--text);font-size:11px}
.step-btn.on{border-color:transparent;background:var(--brand-gradient);color:#fff}
.step-btn.on .step-n{background:rgba(255,255,255,.25);color:#fff}
.step-btn.done .step-n{background:#0F7A38;color:#fff}
/* ⚠️ الخطوة اللي فيها خطأ بتتعلّم في الشريط — المستخدم بيشوف
   «مرحلة 2 فيها مشكلة» قبل ما يفتحها. */
.step-btn.has-error{border-color:var(--red);color:var(--red)}
.step-btn.has-error .step-n{background:var(--red);color:#fff}
</style>
<script>
const T = {!! $formStrings !!};
const PRESETS = {!! $presetKeys !!};

// ═══════════ الخطوات ═══════════
let step = 1;

/**
 * الخانات الإجبارية في مرحلة معيّنة.
 *
 * ⚠️ `data-req` دايماً إجباري، و`data-req-contract` إجباري **بس لما
 * يكون فيه عقد** — العميل اللي مالوش عقد بيعدّي من غيرها.
 */
/**
 * الخانة مخبّية **جوه المرحلة** ولا لأ.
 *
 * ⚠️ **مينفعش `offsetParent`.** المرحلة نفسها `display:none` لما
 * ماتكونش مفتوحة، فكل حقولها `offsetParent === null` — يعني الحارس
 * كان بيتخطّى مرحلة 1 و2 بالكامل وقت الإرسال (الزرار في مرحلة 3)،
 * وبيرجع «مفيش مشكلة» دايماً. كان كود ميت بالظبط.
 *
 * بنمشي لفوق **لحد المرحلة وبس**: كده قسم الكي أكاونت المقفول وبلوك
 * العقد المقفول بيتستثنوا، لكن المرحلة اللي لسه ماتفتحتش بتتفحص.
 */
function hiddenInPane(el, pane) {
    for (let node = el; node && node !== pane; node = node.parentElement) {
        if (node.style && node.style.display === 'none') return true;
    }

    return false;
}

function requiredIn(pane) {
    // ⚠️ **التشيك بوكس اتشال — البلوك مفتوح دايماً.** القايمة نفسها
    // بقت بتقول «اتفاق تجاري بدون عقد»، فمفيش حالة «مفيش عقد» تخفي
    // الحقول. الحقول اللي جوه بلوك مقفول لسه بتتستثنى بـ`hiddenInPane`
    // (زي قسم الكي أكاونت وخانات التواريخ في «تعامل بالطلب»).
    const sel = '[data-req], [data-req-contract]';

    return Array.from(pane.querySelectorAll(sel))
        .filter(el => ! el.disabled && ! hiddenInPane(el, pane));
}

function markBad(el, message) {
    el.classList.add('bad');

    // سطر خطأ تحت الخانة — بيتعمل مرة واحدة بس
    let line = el.parentElement.querySelector('.errline.js-err');
    if (!line) {
        line = document.createElement('div');
        line.className = 'errline js-err';
        el.parentElement.appendChild(line);
    }
    line.textContent = message;
}

function clearBad(el) {
    el.classList.remove('bad');

    // ⚠️ **بنشيل رسالة السيرفر كمان مش بتاعت الجافاسكربت بس.** لو
    // سيبناها، المستخدم بيصلّح الخانة والسطر الأحمر فاضل تحتها —
    // فبيفضل يدوّر على غلط مش موجود. وده بالظبط اللي التعديل ده
    // اتعمل عشانه.
    el.parentElement.querySelectorAll('.errline').forEach(l => l.remove());

    // وعلامة الخطأ على المرحلة بتتشال لما مايبقاش فيها خانة حمرا
    const pane = el.closest('.step-pane');

    if (pane && ! pane.querySelector('.bad, .errline')) {
        const btn = document.querySelector('.step-btn[data-step="' + pane.dataset.pane + '"]');
        if (btn) btn.classList.remove('has-error');
    }
}

/**
 * فحص مرحلة. بيرجّع أول خانة ناقصة أو `null`.
 *
 * ⚠️ **مش بنستخدم `required` بتاع HTML.** المراحل بتتخبّى بـ
 * `display:none`، والمتصفح بيرفض يعمل submit ويقول «An invalid form
 * control is not focusable» من غير ما يوري المستخدم أي حاجة — الفورم
 * بيبقى ميت والزرار مابيعملش حاجة.
 */
function checkPane(n) {
    const pane = document.querySelector('.step-pane[data-pane="' + n + '"]');
    if (!pane) return null;

    let firstBad = null;

    requiredIn(pane).forEach(function (el) {
        if (el.value === null || String(el.value).trim() === '') {
            markBad(el, T.required);
            if (!firstBad) firstBad = el;
        } else {
            clearBad(el);
        }
    });

    // ⚠️ **أيام السداد وأساسها بيمشوا مع بعض.** الرقم لوحده مالوش
    // معنى — 60 يوم من أول توريد غير 60 يوم من كل فاتورة. الشرط ده
    // مشروط فمينفعش يتحط `data-req`: بيشتغل بس لما يكون فيه رقم مكتوب.
    const days = document.getElementById('paymentDays');
    const basis = document.getElementById('daysFrom');

    if (days && basis && pane.contains(days) && ! basis.disabled && ! hiddenInPane(basis, pane)) {
        if (String(days.value).trim() !== '' && basis.value === '') {
            markBad(basis, T.required);
            if (!firstBad) firstBad = basis;
        } else {
            clearBad(basis);
        }
    }

    return firstBad;
}

function goStep(n) {
    // ⚠️ للأمام بس بيتفحص. الرجوع لورا مسموح دايماً — حبس المستخدم
    // في مرحلة عشان خانة ناقصة وهو عايز يراجع اللي فات إحباط بلا سبب.
    if (n > step) {
        for (let i = step; i < n; i++) {
            const bad = checkPane(i);
            if (bad) {
                showStep(i);
                bad.focus();
                bad.scrollIntoView({ block: 'center', behavior: 'smooth' });
                return;
            }
        }
    }

    showStep(n);
}

function showStep(n) {
    step = n;
    document.querySelectorAll('.step-pane').forEach(function (p) {
        p.style.display = (p.dataset.pane === String(n)) ? '' : 'none';
    });
    document.querySelectorAll('.step-btn').forEach(function (b) {
        const i = Number(b.dataset.step);
        b.classList.toggle('on', i === n);
        b.classList.toggle('done', i < n);
    });
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ═══════════ المناطق بتتفلتر بالمحافظة ═══════════
function filterZones() {
    const gov = document.getElementById('govSel').value;
    const sel = document.getElementById('zoneSel');
    const hint = document.getElementById('zoneHint');
    let shown = 0;

    Array.from(sel.options).forEach(function (opt) {
        if (!opt.value) return;
    // ⚠️ المنطقة اللي لسه مالهاش محافظة بتفضل ظاهرة. لو خبّيناها،
        // الـ18 منطقة الموجودة كانوا هيختفوا كلهم لحد ما حد يظبطهم.
    // ⚠️ والمختارة حالياً بتفضل ظاهرة **دايماً**. محافظات المناطق
        // اتحطت بالتخمين، فلو خمّنت غلط كان الاستنساخ بيخبّي منطقة
        // المصدر ويفضّي الاختيار — والفرع الجديد بيتحفظ من غير منطقة.
        const ok = !gov || !opt.dataset.gov || opt.dataset.gov === gov || opt.selected;
        opt.hidden = !ok;
        if (ok) shown++;
    });

    // الاختيار الحالي بقى مخفي؟ نفضّيه بدل ما نحفظ منطقة محافظة تانية
    if (sel.selectedOptions[0] && sel.selectedOptions[0].hidden) sel.value = '';

    hint.textContent = !gov ? '' : (shown ? T.filtered : T.empty);
}

// ═══════════ مدة التعاقد ═══════════

/**
 * تاريخ + شهور، ناقص يوم.
 *
 * ⚠️ **`setMonth` بيلف لوحده.** 31 يناير + شهر بيدي 3 مارس (لأن
 * فبراير 28 يوم)، فبنرجّع لآخر يوم في الشهر المستهدف. من غير كده
 * عقد بيبدأ آخر الشهر بينتهي بتاريخ في الشهر اللي بعده.
 *
 * ⚠️ و«ناقص يوم» مقصود: عقد سنة بيبدأ 1 يناير بينتهي 31 ديسمبر مش
 * 1 يناير اللي بعده — وإلا العقدين المتتاليين بيتداخلوا في يوم،
 * والخصم بيتحسب مرتين على فاتورة اليوم ده.
 */
function addMonths(iso, months) {
    if (!iso || !months) return '';

    const [y, m, d] = iso.split('-').map(Number);
    const target = new Date(Date.UTC(y, m - 1 + months, 1));
    const lastDay = new Date(
        Date.UTC(target.getUTCFullYear(), target.getUTCMonth() + 1, 0)
    ).getUTCDate();

    target.setUTCDate(Math.min(d, lastDay));
    target.setUTCDate(target.getUTCDate() - 1);

    return target.toISOString().slice(0, 10);
}

/** بيوري عدد أيام العقد تحت تاريخ النهاية */
function showSpan() {
    const start = document.getElementById('startsAt');
    const end = document.getElementById('endsAt');
    const hint = document.getElementById('spanHint');

    if (!start || !end || !hint) return;

    if (!start.value || !end.value) {
        hint.textContent = '';

        return;
    }

    const days = Math.round(
        (Date.parse(end.value) - Date.parse(start.value)) / 86400000
    ) + 1;

    // ⚠️ الرقم بيتعرض عشان المستخدم يشوف بعينه إن «سنة» = 365 يوم.
    // رسالة «مش مظبوط» من غير رقم بتخلّيه يخمّن فين الغلط.
    hint.textContent = days > 0 ? T.daysCount.replace('__N__', days) : '';
}

/**
 * المدة بتفتح التواريخ وبتحسب النهاية.
 *
 * ⚠️ **النهاية بتتحسب بس لما تكون فاضية أو المستخدم غيّر المدة.**
 * لو دستنا عليها كل مرة، أي حد عدّل تاريخ نهاية بإيده (عقد بينتهي
 * آخر الشهر مثلاً) كان بيلاقيه رجع للمحسوب من غير ما حد يقوله.
 *
 * @param {boolean} keepEnd سيب تاريخ النهاية المكتوب زي ما هو
 */
function syncDuration(keepEnd) {
    const sel = document.getElementById('durationSel');
    const startBox = document.getElementById('startsAtBox');
    const endBox = document.getElementById('endsAtBox');
    const hint = document.getElementById('durationHint');
    const start = document.getElementById('startsAt');
    const end = document.getElementById('endsAt');

    if (!sel || !startBox || !endBox || !start || !end) return;

    const opt = sel.selectedOptions[0];
    const hasDates = opt ? opt.dataset.dates === '1' : false;
    const hasEnd = opt ? opt.dataset.end === '1' : false;
    const show = Boolean(sel.value) && hasDates;

    // ⚠️ الإخفاء على **الخانة** مش على الصف: المدة لازم تفضل ظاهرة
    // حتى لما التواريخ تختفي، وإلا المستخدم بيختار «تعامل بالطلب»
    // والاختيار بتاعه بيختفي من قدامه.
    startBox.style.display = show ? '' : 'none';
    endBox.style.display = (show && hasEnd) ? '' : 'none';

    hint.textContent = !sel.value ? ''
        : (!hasDates ? T.perOrderHint : (!hasEnd ? T.openHint : ''));

    // ⚠️ **الخانات المخبّية بتتفضّى.** عقد «تعامل بالطلب» اتحفظ
    // بتواريخ من اختيار قبله كان بيعدّي التحقق ويطلع عقد بتواريخ
    // مالهاش معنى. والعقد المفتوح بتاريخ نهاية تناقض صريح.
    if (!hasDates) {
        start.value = '';
        end.value = '';
    } else if (!hasEnd) {
        end.value = '';
    } else if (start.value && ! keepEnd) {
    // ⚠️ **بيتحسب مع كل تغيير، مش أول مرة بس.**
        //
        // كانت الشرط `(!end.value || !keepEnd)` — يعني أول ما النهاية
        // تتملى، أي تغيير بعد كده في تاريخ البداية مابيحركهاش. المستخدم
        // كان بيختار «3 شهور»، النهاية تتحسب صح، وبعدين يغيّر البداية
        // فالنهاية تفضل مكانها والفرق يبقى 67 يوم — والسيرفر يرفض
        // بحجة «أقل من 88» وهو مش فاهم إيه اللي حصل.
        //
        // `keepEnd` بقت للتحميل الأول بس: العقد المتخزن نهايته
        // بتفضل زي ما هي.
        end.value = addMonths(start.value, T.months[sel.value] || 0);
    }

    showSpan();
}

// ═══════════ إظهار وإخفاء ═══════════
/**
 * ⚠️ **بقت شبه فاضية بعد ما التشيك بوكس اتشال.**
 *
 * سيبتها موجودة عن قصد: الدالة بتتنادى من `DOMContentLoaded` ومن
 * `ScreenScriptsTest`، وشيلها كان معناه تتبّع كل نداء. البلوك دلوقتي
 * مفتوح دايماً وحقوله شغّالة دايماً — والإخفاء الوحيد الباقي هو
 * خانات التواريخ اللي `syncDuration()` بتتحكم فيها.
 */
function toggleContract() {
    const box = document.getElementById('contractBox');
    if (!box) return;

    // ⚠️ **الدالة دي بتتنادى عند التحميل كمان (سطر التهيئة تحت).**
    // لما كانت بتفتح البلوك بالعافية، وضع التعديل لعميل بياخد عقد
    // سلسلته كان بيفتح بلوك عقد فاضي — واللي بيقرا الشاشة يفتكر إن
    // العميل مالوش شروط، ويحفظ فيعمله عقد خاص فاضي يلغي عقد السلسلة.
    // العميل الجديد مافيهوش تشيك بوكس أصلاً، فبيفضل مفتوح زي ما كان.
    const cb = document.getElementById('hasContract');
    const on = cb ? cb.checked : true;

    box.style.display = on ? '' : 'none';

    // ⚠️ **الإخفاء لوحده مش كفاية — لازم تعطيل.** `display:none`
    // مابيمنعش الإرسال. لما البلوك بيتقفل، `clause[...][on]` وتاريخ
    // البداية وأيام السداد بيفضلوا بيتبعتوا، فـ`required_if` بترفض
    // الحفظ وتوري رسالة عن خانة المستخدم مش شايفها أصلاً.
    //
    // ⚠️ **`data-keep-disabled` بيستثني البند المقفول.** البند المكتوب
    // بإيد في عقد الـPDF مايتغيّرش من هنا؛ لو الحلقة فكّت تعطيله،
    // المستخدم بيكتب رقم وياخد «اتحفظ» أخضر والسيرفر بيرميه في صمت.
    box.querySelectorAll('input, select, textarea').forEach(function (el) {
        if (el.dataset.keepDisabled === '1') return;
        // مفتوح ⇒ شغّال، مقفول ⇒ معطّل عشان مايتبعتش
        el.disabled = ! on;
    });
}

/**
 * ⚠️ **مدة السداد للآجل بس.** العميل الكاش بياخد فلوسه في إيده —
 * «30 يوم من الفاتورة» على عميل كاش رقم بيتخزن ومابيتطبقش، وبعد
 * شهور حد بيفتح كارته ويفتكر إن ليه مهلة سداد. و«الاتنين» بتوري
 * الخانتين لأن نص تعاملاته ممكن يكون آجل.
 *
 * ⚠️ **إخفاء بس من غير تعطيل** — عكس بلوك العقد. الخانتين دول
 * `nullable` في الفاليديشن، فإرسالهم فاضيين مش مشكلة؛ والتعطيل كان
 * هيمسح قيمة موجودة على عميل اتحوّل من آجل لكاش **مؤقتاً** وهو
 * بيعدّل حاجة تانية خالص.
 */
function togglePayDays() {
    const el = document.getElementById('payTerms');
    if (!el) return;

    // فاضي = «حسب القناة» — مانعرفش هيطلع كاش ولا آجل، فبنوري
    // الخانتين بدل ما نخبّي حاجة ممكن تكون مطلوبة
    const show = el.value !== 'cash';

    document.querySelectorAll('.payDaysBox').forEach(function (b) {
        b.style.display = show ? '' : 'none';
    });
}

function toggleTax() {
    const on = document.getElementById('taxable').checked;
    document.getElementById('taxBox').style.display = on ? '' : 'none';
}

function toggleClause(key) {
    const cb = document.getElementById('cl_' + key);
    const box = document.getElementById('box_' + key);
    if (!cb || !box) return;
    box.style.display = cb.checked ? '' : 'none';
    if (cb.checked) { const i = box.querySelector('input[type=number]'); if (i) i.focus(); }
}

// ═══════════ بند حر ═══════════
function addClauseRow(hostId, value) {
    const host = document.getElementById(hostId);
    if (!host) return;

    const row = document.createElement('div');
    row.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:8px';

    const input = document.createElement('input');
    input.type = 'text';
    input.name = 'contract_clauses[]';
    input.dir = 'ltr';
    input.maxLength = 500;
    input.placeholder = T.placeholder;
    input.style.flex = '1';
    input.style.minWidth = '0';
    if (value) input.value = value;

    const del = document.createElement('button');
    del.type = 'button';
    del.className = 'btn sm red';
    del.textContent = T.remove;
    del.addEventListener('click', function () { row.remove(); });

    row.appendChild(input);
    row.appendChild(del);
    host.appendChild(row);
    input.focus();
}

// ═══════════ منطقة جديدة من غير ما نسيب الصفحة ═══════════
// ⚠️ **الدوال دي اتشالت بالغلط** في تعديل سابق على نفس البلوك،
// فزرار «+» جنب المنطقة كان بينادي دالة مش موجودة ومابيعملش حاجة.
function openZoneBox() {
    const box = document.getElementById('zoneBox');
    if (!box) return;

    box.style.display = '';
    // المحافظة المختارة فوق هي الافتراضية للمنطقة الجديدة
    document.getElementById('nzGov').value = document.getElementById('govSel').value;
    document.getElementById('nzName').focus();
}

function closeZoneBox() {
    const box = document.getElementById('zoneBox');
    if (!box) return;

    box.style.display = 'none';
    document.getElementById('nzMsg').textContent = '';
}

async function saveZone() {
    const name = document.getElementById('nzName').value.trim();
    const msg = document.getElementById('nzMsg');
    const btn = document.getElementById('nzSave');

    if (!name) { msg.style.color = 'var(--red)'; msg.textContent = T.zoneNeedsName; return; }

    // ⚠️ الزرار بيتقفل أثناء الحفظ. دبل كليك على شبكة بطيئة كان
    // بيعمل منطقتين بنفس الاسم، والتانية بتفضل في القايمة للأبد.
    btn.disabled = true;
    btn.textContent = T.saving;
    msg.textContent = '';

    try {
        const res = await fetch(T.zoneUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
            },
            body: JSON.stringify({
                name: name,
                name_en: document.getElementById('nzNameEn').value.trim(),
                governorate: document.getElementById('nzGov').value,
            }),
        });

        if (!res.ok) throw new Error(res.status);

        const zone = await res.json();
        const sel = document.getElementById('zoneSel');
        const opt = document.createElement('option');
        opt.value = zone.id;
        opt.textContent = zone.name;
        opt.dataset.gov = zone.governorate || '';
        sel.appendChild(opt);
        sel.value = zone.id;   // ← بتتختار على طول، المستخدم مايدورش عليها

        document.getElementById('nzName').value = '';
        document.getElementById('nzNameEn').value = '';
        closeZoneBox();
        filterZones();
    } catch (e) {
        msg.style.color = 'var(--red)';
        msg.textContent = T.failed;
    } finally {
        btn.disabled = false;
        btn.textContent = T.save;
    }
}

// ═══════════ سلسلة جديدة من غير ما نسيب الصفحة ═══════════
function openGroupBox() {
    document.getElementById('groupBox').style.display = '';
    document.getElementById('ngName').focus();
}

function closeGroupBox() {
    document.getElementById('groupBox').style.display = 'none';
    document.getElementById('ngMsg').textContent = '';
}

async function saveGroup() {
    const name = document.getElementById('ngName').value.trim();
    const msg = document.getElementById('ngMsg');
    const btn = document.getElementById('ngSave');

    if (!name) { msg.style.color = 'var(--red)'; msg.textContent = T.groupNeedsName; return; }

    // ⚠️ الزرار بيتقفل أثناء الحفظ — دبل كليك على شبكة بطيئة كان
    // بيعمل سلسلتين بنفس الاسم وفروع العميل تتقسم عليهم.
    btn.disabled = true;
    btn.textContent = T.saving;
    msg.textContent = '';

    try {
        const res = await fetch(T.groupUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
            },
            body: JSON.stringify({
                name: name,
                name_en: document.getElementById('ngNameEn').value.trim(),
            }),
        });

        if (!res.ok) throw new Error(res.status);

        const group = await res.json();
        const sel = document.getElementById('groupSel');
        const opt = document.createElement('option');
        opt.value = group.id;
        opt.textContent = group.name;
        sel.appendChild(opt);
        sel.value = group.id;

        document.getElementById('ngName').value = '';
        document.getElementById('ngNameEn').value = '';
        closeGroupBox();
    } catch (e) {
        msg.style.color = 'var(--red)';
        msg.textContent = T.failed;
    } finally {
        btn.disabled = false;
        btn.textContent = T.save;
    }
}

// ═══════════ اكتشاف الإحداثيات من لينك اللوكيشن ═══════════
function showPoint(lat, lng) {
    document.getElementById('latField').value = lat;
    document.getElementById('lngField').value = lng;
    const m = document.getElementById('locMsg');
    m.style.color = 'var(--green, #0F7A38)';
    m.textContent = T.detected + ': ' + lat + ', ' + lng;
}

/** قراءة سريعة من نص الرابط — من غير أي طلب للسيرفر */
function readFromText(url) {
    const patterns = [
        /!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/,
        /@(-?\d+\.\d+),\s*(-?\d+\.\d+)/,
        /[?&](?:q|query|ll|center)=(-?\d+\.\d+),\s*(-?\d+\.\d+)/i,
    ];
    for (const re of patterns) {
        const m = decodeURIComponent(url).match(re);
        if (!m) continue;
        const lat = parseFloat(m[1]), lng = parseFloat(m[2]);
    // ⚠️ فحص المدى إجباري — `17z` (مستوى التكبير) كان بيتقرا
        // كإحداثي وبيحط الدبوس في نص المحيط.
        if (Math.abs(lat) <= 90 && Math.abs(lng) <= 180 && (lat || lng)) return [lat, lng];
    }
    return null;
}

/** بيشتغل مع الكتابة — الرابط الكامل بيتقرا فوراً من غير ضغط زرار */
function autoDetect() {
    const url = document.getElementById('locUrl').value.trim();
    if (!url) { document.getElementById('locMsg').textContent = ''; return; }
    const p = readFromText(url);
    if (p) showPoint(p[0], p[1]);
}

async function detectLocation() {
    const url = document.getElementById('locUrl').value.trim();
    const msg = document.getElementById('locMsg');
    const btn = document.getElementById('detectBtn');

    if (!url) return;

    const quick = readFromText(url);
    if (quick) { showPoint(quick[0], quick[1]); return; }

    // ⚠️ الرابط المختصر (`maps.app.goo.gl`) مافيهوش إحداثيات خالص —
    // لازم السيرفر يتابع إعادة التوجيه. الفحص الأمني للدومين جوه
    // `MapLink` قبل أي اتصال.
    btn.disabled = true;
    msg.style.color = 'var(--muted)';
    msg.textContent = T.detecting;

    try {
        const res = await fetch(T.geoUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
            },
            body: JSON.stringify({ url: url }),
        });

        if (!res.ok) throw new Error(res.status);

        const p = await res.json();
        showPoint(p.lat, p.lng);
    } catch (e) {
        msg.style.color = 'var(--red)';
        msg.textContent = T.detectFailed;
    } finally {
        btn.disabled = false;
    }
}

// ═══════════ جهات التواصل ═══════════
// ⚠️ الفهرس بيتحسب من عدد الصفوف الموجودة **وقت الإضافة** مش من عداد
// عالمي. لو استخدمنا عداد، مسح صف وإضافة واحد بيدي فهرس مكرر و PHP
// بيدوس على الصف القديم.
function addContact() {
    const host = document.getElementById('contactRows');
    const i = Date.now();   // فريد ومتصاعد — PHP بيرقّم من جديد بعد التحقق

    const row = document.createElement('div');
    row.className = 'frow contact-row';
    row.style.marginBottom = '6px';

    // ⚠️ **الـplaceholder جاي من ملف اللغة مش مكتوب هنا.** كان
    // مكتوب مثال إنجليزي («Mohamed Ahmed») — والمثال بيتقري كأنه
    // قيمة موجودة فعلاً، وفيه ناس بتسيب الخانة فاكرة إنها مليانة.
    // ودلوقتي الصف اللي بيتضاف بالجافاسكربت زيّه زيّ الصفوف اللي
    // البليد بيرسمها — لو اختلفوا، أول صف جديد بيبان بلغة تانية.
    const cell = (name, ph) =>
        '<div><input type="text" name="contacts[' + i + '][' + name + ']" dir="ltr" '
        + 'maxlength="120" placeholder="' + ph + '" style="width:100%"></div>';

    row.innerHTML = cell('name', T.contactNamePh) + cell('role', T.contactRolePh)
        + '<div style="display:flex;gap:6px"><input type="text" name="contacts[' + i
        + '][phone]" dir="ltr" maxlength="30" placeholder="01000000000"'
        + ' style="flex:1;min-width:0"><button type="button" class="btn sm red">&times;</button></div>';

    row.querySelector('button').addEventListener('click', function () { row.remove(); });
    host.appendChild(row);
    row.querySelector('input').focus();
}

// ═══════════ قسم الكي أكاونت ═══════════
// ⚠️ **الكي أكاونت وحده اللي بينقسم.** سلسلة هايبر وكونفينيانس/محطة
// بنزين بيتعاملوا مختلف تماماً — رفوف وعقود سنوية مقابل دوران سريع.
// الأونلاين والكاش فان والجملة متجانسين جواهم، وخانة فاضية بعنوان
// «قسم الكي أكاونت» عليهم بتخلّي اللي بيدخل الداتا يقف يفكّر هو ناسي
// حاجة ولا إيه.
//
// ⚠️ **الدالة دي كانت اتشالت بالغلط** في تعديل سابق، فالـonchange كان
// بينادي دالة مش موجودة — والخانة كانت بتفضل ظاهرة على كل القنوات.
function syncSubChannel() {
    const sel = document.getElementById('channelSel');
    const box = document.getElementById('subChannelBox');
    if (!sel || !box) return;

    const sub = box.querySelector('select');
    const code = sel.selectedOptions[0] ? sel.selectedOptions[0].dataset.code : '';
    const allowed = (code === 'key_account');

    // `display` مش `visibility` — بتختفي بمكانها مش بتسيب فراغ
    box.style.display = allowed ? '' : 'none';

    // ⚠️ **والقيمة بتتفضّى.** الإخفاء مش بيمسح اللي جوه الخانة — اللي
    // يختار كي أكاونت/سلاسل وبعدين يرجع كاش فان كان بيحفظ عميل كاش فان
    // وعليه قسم «سلاسل هايبر». (السيرفر بيصفّيها كمان في
    // `Client::booted()`، بس المستخدم لازم يشوف الخانة اتفضّت.)
    if (!allowed && sub) sub.value = '';
}

// ═══════════ التشغيل ═══════════
document.addEventListener('DOMContentLoaded', function () {
    // ⚠️ **بنفتح على مرحلة أول خطأ.** من غير كده الفورم بيرجع على
    // مرحلة 1 والخطأ في مرحلة 3، والمستخدم بيشوف صفحة سليمة ومش فاهم
    // ليه الحفظ مانفعش.
    showStep(T.errorStep || 1);
    toggleContract();
    // ⚠️ لازم يتنادى عند الفتح: العقد الموجود بيتحمّل بمدة، والتواريخ
    // لازم تبان من أول لحظة مش بعد أول تغيير. و`true` عشان مايدوسش
    // على تاريخ نهاية متخزن.
    syncDuration(true);
    toggleTax();
    togglePayDays();
    syncSubChannel();
    filterZones();
    PRESETS.forEach(toggleClause);
    autoDetect();

    // ⚠️ **الحارس على الإرسال.** بيفحص المراحل الثلاثة، وأول خانة
    // ناقصة بيفتح مرحلتها ويقف عليها بالأحمر. من غيره المستخدم بيدوس
    // حفظ ويستنى ويرجع بصفحة أخطاء كان ممكن يشوفها قبل ما يبعت.
    document.getElementById('clientForm').addEventListener('submit', function (e) {
        for (let i = 1; i <= 3; i++) {
            const bad = checkPane(i);
            if (bad) {
                e.preventDefault();
                showStep(i);
                bad.focus();
                bad.scrollIntoView({ block: 'center', behavior: 'smooth' });
                return;
            }
        }
    });

    // ⚠️ الأحمر بيتشال أول ما المستخدم يكتب. سيبه بعد التصحيح بيخلّيه
    // يفتكر إن لسه فيه غلط ويدوّر على حاجة مظبوطة.
    document.getElementById('clientForm').addEventListener('input', function (e) {
        if (e.target.classList && e.target.classList.contains('bad')) clearBad(e.target);
    });
    document.getElementById('clientForm').addEventListener('change', function (e) {
        if (e.target.classList && e.target.classList.contains('bad')) clearBad(e.target);
    });

    // ⚠️ الخطوة اللي فيها خطأ من السيرفر بتتعلّم في الشريط
    document.querySelectorAll('.errline').forEach(function (line) {
        const pane = line.closest('.step-pane');
        if (!pane) return;
        const btn = document.querySelector('.step-btn[data-step="' + pane.dataset.pane + '"]');
        if (btn) btn.classList.add('has-error');
    });
});
</script>
@endsection
