@extends('layouts.system')

@section('title', __('geo.confirm_locations'))

@php
    // ⚠️ الأجزاء اللي بتترسم في جافاسكربت بتتجهّز هنا — بليد مابيشتغلش
    // جوه بلوك السكربت، وبناء الأوبشنز هناك كان بيطلع نص حرفي.
    //
    // ⚠️ ومكتوبة «بلوك السكربت» مش بالتاج نفسه عن قصد: أي أداة
    // بتستخرج السكربتات بالبحث عن التاج كانت بتلقطه من جوه التعليق
    // ده وتفحص نص بليد كأنه جافاسكربت.
    $govOptions = '';
    foreach ($governorates as $k => $label) {
        $govOptions .= '<option value="'.e($k).'">'.e($label).'</option>';
    }

    $zoneOptions = '';
    foreach ($zones as $z) {
        $zoneOptions .= '<option value="'.$z->id.'">'.e($z->displayName()).'</option>';
    }
@endphp

@section('content')

<div class="alert info" style="margin-bottom:14px">
    <span>📍</span>
    <span>{{ __('geo.screen_hint') }}</span>
</div>

{{-- ⚠️ **الكروت بقت أربعة بدل اتنين** (طلب المالك ٨/٨/٢٠٢٦):
     «مستنية» لوحدها كانت بتجمع شغل جاهز مع شغل ميدان في رقم واحد. --}}
<div class="kpis">
    <div class="kpi"><div class="lbl">{{ __('geo.f_from_visit') }}</div>
        <div class="val pos">{{ number_format($counts['from_visit']) }}</div>
        <div class="sub2">{{ __('geo.f_from_visit_hint') }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('geo.f_unconfirmed') }}</div>
        <div class="val mid">{{ number_format($counts['unconfirmed']) }}</div>
        <div class="sub2">{{ __('geo.f_unconfirmed_hint') }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('geo.f_no_location') }}</div>
        <div class="val neg">{{ number_format($counts['no_location']) }}</div>
        <div class="sub2">{{ __('geo.f_no_location_hint') }}</div></div>
    <div class="kpi"><div class="lbl">{{ __('geo.confirmed') }}</div>
        <div class="val">{{ number_format($counts['done']) }}</div></div>
</div>

<div class="card">
    <h3>📍 {{ __('geo.confirm_locations') }}
        <span class="side">{{ __('client.client_countable', ['count' => $rows->count()]) }}</span></h3>

    {{-- ⚠️ **الفلاتر مترتّبة بترتيب الشغل**: الجاهز الأول، بعده
         المستني مراجعة، بعده اللي محتاج نزول ميدان، وآخر حاجة
         الأرشيف. والعدّاد على كل زرار عشان محدش يفتح فلتر فاضي. --}}
    <div class="searchbar">
        @foreach ([
            'from_visit' => __('geo.f_from_visit'),
            'unconfirmed' => __('geo.f_unconfirmed'),
            'no_location' => __('geo.f_no_location'),
            'done' => __('geo.f_done'),
            'all' => __('common.all'),
        ] as $k => $label)
            <a class="btn sm {{ $filter === $k ? 'gold' : '' }}"
               href="{{ route('erp.client_locations', ['show' => $k]) }}">
                {{ $label }}
                @if (($counts[$k] ?? 0) > 0)
                    <b style="margin-inline-start:5px">{{ number_format($counts[$k]) }}</b>
                @endif
            </a>
        @endforeach
    </div>

    <div class="tablewrap">
        <table>
            <thead>
            <tr>
                <th>{{ __('client.client') }}</th>
                <th>{{ __('client.zone') }}</th>
                <th>{{ __('geo.current_point') }}</th>
                <th>{{ __('geo.from_visit') }}</th>
                <th>{{ __('geo.state') }}</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($rows as $r)
                @php $c = $r['client']; $v = $r['visit']; @endphp
                <tr>
                    <td>
                        <b>{{ $c->fullName() }}</b>
                        <br><span style="font-size:10.5px;color:var(--muted)">{{ $c->displayAddress() ?: '—' }}</span>
                    </td>
                    <td style="color:var(--muted)">{{ $c->zone?->displayName() ?? '—' }}</td>
                    <td class="num">
                        @if ($c->hasLocation())
                            <span dir="ltr">{{ number_format((float) $c->lat, 5) }}, {{ number_format((float) $c->lng, 5) }}</span>
                        @else
                            <span style="color:var(--red)">—</span>
                        @endif
                    </td>
                    <td class="num">
                        @if ($v)
                            <span dir="ltr">{{ number_format((float) $v->lat, 5) }}, {{ number_format((float) $v->lng, 5) }}</span>
                            <br><span style="font-size:10px;color:var(--muted)">
                                {{ $v->user?->displayName() }} · {{ $v->checked_in_at?->format('m-d H:i') }}
                            </span>
                        @else
                            {{-- ⚠️ **مفيش زيارة ≠ مفيش حل.** اللي بيراجع لسه
                                 يقدر يفتح المودال ويكتب النقطة بإيده من لينك
                                 خرايط — الزيارة اختصار مش شرط. --}}
                            <span style="color:var(--muted)">{{ __('geo.no_visit') }}</span>
                        @endif
                    </td>
                    <td>
                        @if ($c->locationTrusted())
                            <span class="badge b-green">✅ {{ __('geo.confirmed') }}</span>
                            <br><span style="font-size:10px;color:var(--muted)">
                                {{ $c->location_confirmed_at?->format('Y-m-d') }}</span>
                        @elseif ($c->hasLocation())
                            <span class="badge b-orange">{{ __('geo.unverified') }}</span>
                        @else
                            <span class="badge b-red">{{ __('geo.missing') }}</span>
                        @endif
                    </td>
                    <td>
                        {{-- ⚠️ **الداتا في `data-*` مش في `onclick`.** الأسماء
                             والعناوين فيها أقواس وكوتيشن عربي، وحقنها في
                             `onclick='confirm({...})'` كان بيكسّر الـHTML على
                             أول اسم فيه أبوستروف. --}}
                        {{-- ⚠️ المتأكد خلاص مايتأكدش تاني (طلب المالك ١١/٨) —
                             زراره «تعديل» رمادي، للتصحيح بس. --}}
                        <button type="button" class="btn sm {{ $c->locationTrusted() ? '' : 'gold' }}"
                                data-id="{{ $c->id }}"
                                data-name="{{ $c->fullName() }}"
                                data-lat="{{ $v?->lat ?? $c->lat }}"
                                data-lng="{{ $v?->lng ?? $c->lng }}"
                                data-src="{{ $v ? 'visit' : 'manual' }}"
                                data-address="{{ $c->address }}"
                                data-address-ar="{{ $c->address_ar }}"
                                data-gov="{{ $c->governorate }}"
                                data-zone="{{ $c->zone_id }}"
                                onclick="openGeo(this)">
                            {{ $c->locationTrusted() ? '✏️ '.__('common.edit') : '✔ '.__('geo.confirm') }}
                        </button>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:30px">
                    {{ __('geo.none_here') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- ═══ مودال التأكيد ═══ --}}
<dialog id="dlgGeo">
    {{-- ⚠️ **`.dlg` كانت ناقصة** — الحواف والعرض كانوا على الكلاس ده
         وحده، فالمودال كان بيطلع ملزوق في ركن الشاشة بلا padding.
         الستايل بقى على `dialog>form` كمان، والكلاس فاضل للوضوح. --}}
    <form method="POST" id="geoForm" class="dlg">
        @csrf
        <h3 style="margin-bottom:4px">📍 {{ __('geo.confirm_for') }} <span id="geoName"></span></h3>
        <div style="font-size:11.5px;color:var(--muted);margin-bottom:12px">{{ __('geo.modal_hint') }}</div>

        <input type="hidden" name="source" id="geoSrc">

        <div class="frow">
            <div>
                <label class="f">{{ __('geo.lat') }} <b class="req-star">*</b></label>
                <input type="number" step="0.0000001" name="lat" id="geoLat" dir="ltr" required style="width:100%">
            </div>
            <div>
                <label class="f">{{ __('geo.lng') }} <b class="req-star">*</b></label>
                <input type="number" step="0.0000001" name="lng" id="geoLng" dir="ltr" required style="width:100%">
            </div>
        </div>

        <div style="display:flex;gap:8px;align-items:center;margin:10px 0">
            <button type="button" class="btn sm" id="geoFill" onclick="fillFromMap()">
                🌐 {{ __('geo.fill_from_map') }}
            </button>
            <span id="geoMsg" style="font-size:11.5px;color:var(--muted)"></span>
        </div>

        <div class="frow">
            <div>
                <label class="f">{{ __('geo.address_en') }} <b class="req-star">*</b></label>
                <input type="text" name="address" id="geoAddr" dir="ltr" maxlength="190" required style="width:100%">
            </div>
            <div>
                <label class="f">{{ __('geo.address_ar') }} <b class="req-star">*</b></label>
                <input type="text" name="address_ar" id="geoAddrAr" maxlength="190" required style="width:100%">
            </div>
        </div>

        <div class="frow" style="margin-top:10px">
            <div>
                <label class="f">{{ __('geo.governorate') }}</label>
                <select name="governorate" id="geoGov" style="width:100%">
                    <option value="">—</option>
                    {!! $govOptions !!}
                </select>
            </div>
            <div>
                <label class="f">{{ __('client.zone') }}</label>
                <select name="zone_id" id="geoZone" style="width:100%">
                    <option value="">—</option>
                    {!! $zoneOptions !!}
                </select>
            </div>
        </div>

        <div class="formbar" style="margin-top:16px">
            <button type="button" class="btn" onclick="closeDlg('dlgGeo')">{{ __('common.cancel') }}</button>
            <span class="formbar-sp"></span>
            <button type="submit" class="btn gold">✔ {{ __('geo.confirm_save') }}</button>
        </div>
    </form>
</dialog>

@endsection

@section('scripts')
<script>
const GEO_URL = @json(route('erp.client_locations.confirm', ['client' => 0]));
const GEO_SUGGEST = @json(route('erp.client_locations.suggest'));
const T_F_ADDR = @json(__('geo.address'));
const T_F_GOV  = @json(__('geo.governorate'));
const T_F_ZONE = @json(__('client.zone'));
const T_WAIT = @json(__('geo.fetching'));
const T_FAIL = @json(__('geo.reverse_failed'));
const T_NO_POINT = @json(__('geo.need_point_first'));

function openGeo(btn) {
    const d = btn.dataset;

    // ⚠️ **الراوت بيتبني بالاستبدال مش بالضم.** `route()` بتاعت لارافيل
    // بتحط `0` مكان الـid، وضم الـid في الآخر كان بيدي `/0/123`.
    document.getElementById('geoForm').action = GEO_URL.replace(/\/0$/, '/' + d.id);

    document.getElementById('geoName').textContent = d.name || '';
    document.getElementById('geoLat').value = d.lat || '';
    document.getElementById('geoLng').value = d.lng || '';
    document.getElementById('geoSrc').value = d.src || 'manual';
    document.getElementById('geoAddr').value = d.address || '';
    document.getElementById('geoAddrAr').value = d.addressAr || '';
    document.getElementById('geoGov').value = d.gov || '';
    document.getElementById('geoZone').value = d.zone || '';
    document.getElementById('geoMsg').textContent = '';

    openDlg('dlgGeo');
}

/**
 * ⚠️ **بيملا الخانات ومابيحفظش.** الرد اقتراح من الخريطة — بتدي أقرب
 * معلَم مش عنوان المحل. اللي بيراجع بيصلّح قبل ما يأكّد، وعشان كده
 * الخانات بتفضل قابلة للتعديل والزرار مش بيحفظ.
 */
async function fillFromMap() {
    const lat = document.getElementById('geoLat').value;
    const lng = document.getElementById('geoLng').value;
    const msg = document.getElementById('geoMsg');
    const btn = document.getElementById('geoFill');

    if (!lat || !lng) {
        msg.textContent = T_NO_POINT;
        return;
    }

    // ⚠️ التعطيل ضروري: الخدمة بتاخد ثانية على الأقل (شرط Nominatim)،
    // والدوس المتكرر بيبعت 5 طلبات ويتحظر
    btn.disabled = true;
    msg.textContent = T_WAIT;

    try {
        const r = await fetch(GEO_SUGGEST, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({ lat: lat, lng: lng }),
        });

        const j = await r.json();

        if (!r.ok) {
            msg.textContent = j.message || T_FAIL;
            return;
        }

        if (j.ar) document.getElementById('geoAddrAr').value = j.ar;
        if (j.en) document.getElementById('geoAddr').value = j.en;

        // ⚠️ المحافظة بتتحط **بس لو الخانة فاضية** — اللي بيراجع ممكن
        // يكون عارف المحافظة الصح، والخريطة مش دايماً بتصيبها
        const gov = document.getElementById('geoGov');
        if (j.governorate && !gov.value) gov.value = j.governorate;

        // ⚠️ **والمنطقة كمان** (طلب المالك ٨/٨/٢٠٢٦) — أقرب منطقة
        // بإحداثيات جوّه المحافظة. نفس القاعدة: بتتحط لو فاضية بس،
        // واللي بيراجع بيعدّلها لو الاقتراح غلط.
        const zone = document.getElementById('geoZone');
        if (j.zone_id && !zone.value) zone.value = j.zone_id;

        // رسالة توضح إيه اللي اتملى فعلاً — «تمام» لوحدها مابتقولش
        // إذا كانت المنطقة اتحطت ولا لأ
        const filled = [];
        if (j.en || j.ar) filled.push(T_F_ADDR);
        if (j.governorate) filled.push(T_F_GOV);
        if (j.zone_id) filled.push(T_F_ZONE);

        msg.textContent = filled.length ? '✔ ' + filled.join(' · ') : T_FAIL;
    } catch (e) {
        msg.textContent = T_FAIL;
    } finally {
        btn.disabled = false;
    }
}
</script>
@endsection
