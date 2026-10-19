{{--
    فلتر مركز التكلفة على التقارير المالية (الخط ٣، ١٩/١٠/٢٠٢٦) — المركز
    بيجيب أحفاده (الشركة = الكل اللي متسجّل على مركز). «كل المراكز» = من غير فلتر.
--}}
<label class="fl"><span>{{ __('gl.cost_center') }}</span>
    <select name="cost_center_id" onchange="this.form.submit()">
        <option value="">{{ __('gl.all_centers') }}</option>
        @foreach ($centers as $cc)
            <option value="{{ $cc->id }}" @selected($centerId === $cc->id)>{{ $cc->code }} — {{ $cc->displayName() }}</option>
        @endforeach
    </select></label>
@if ($centerId)
    <span style="font-size:11.5px;color:var(--muted);flex-basis:100%">ℹ️ {{ __('gl.center_filter_note') }}</span>
@endif
