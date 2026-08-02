{{--
    اختيار منطقة مجمّعة بالمحافظة — البارشال الموحد.

    ⚠️ **الهرمية بتتعرض في كل مكان اختيار.** قايمة 49 منطقة مسطّحة
    بتخلّي «المعادي» جنب «العاشر من رمضان» واللي بيدور مش عارف هو في
    أنهي محافظة أصلاً — التجميع بيوريه المحافظة وجواها مناطقها،
    بالترتيب الجغرافي بتاع `Governorates::KEYS` (القاهرة الكبرى الأول).

    المدخلات:
      $zones      كولكشن المناطق (لازم فيها governorate)
      $name       اسم الحقل (default: zone_id)
      $selected   القيمة المختارة (default: null)
      $required   إجباري؟ (default: false)
      $placeholder نص أول اختيار (default: —)
      $attrs      خصائص إضافية كنص (default: '')
--}}
@php
    $name = $name ?? 'zone_id';
    $selected = (int) ($selected ?? 0);
    $required = $required ?? false;
    $placeholder = $placeholder ?? '—';
    $attrs = $attrs ?? '';
    // جوه فورم عادي 100%، جوه searchbar سيبه أوتوماتيك
    $style = $style ?? '';

    // المناطق مجمّعة: محافظات بالترتيب الجغرافي، وبعدين «بدون محافظة»
    $byGov = $zones->groupBy(fn ($z) => $z->governorate ?: '_none');
@endphp
<select name="{{ $name }}" @if ($required) required @endif @if ($style) style="{{ $style }}" @endif {!! $attrs !!}>
    <option value="">{{ $placeholder }}</option>
    @foreach (\App\Support\Governorates::KEYS as $gk)
        @if (($group = $byGov->get($gk)) && $group->isNotEmpty())
            <optgroup label="{{ \App\Support\Governorates::label($gk) }}">
                @foreach ($group->sortBy(fn ($z) => $z->displayName()) as $z)
                    <option value="{{ $z->id }}" @selected($selected === $z->id)>{{ $z->displayName() }}</option>
                @endforeach
            </optgroup>
        @endif
    @endforeach
    @if (($none = $byGov->get('_none')) && $none->isNotEmpty())
        <optgroup label="{{ __('geo.no_governorate') }}">
            @foreach ($none->sortBy(fn ($z) => $z->displayName()) as $z)
                <option value="{{ $z->id }}" @selected($selected === $z->id)>{{ $z->displayName() }}</option>
            @endforeach
        </optgroup>
    @endif
</select>
