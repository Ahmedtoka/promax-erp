{{-- خانات بند المصروف المشتركة بين «جديد» و«تعديل» — `$accounts` من `ExpenseCategoryController::index` --}}
<div class="frow" style="margin-top:10px">
    <div>
        <label class="f">{{ __('cash.name') }} <b class="req-star">*</b></label>
        <input type="text" name="name" required maxlength="120" value="{{ old('name') }}" style="width:100%">
    </div>
    <div>
        <label class="f">{{ __('cash.name_en') }}</label>
        <input type="text" name="name_en" maxlength="120" dir="ltr" value="{{ old('name_en') }}" style="width:100%">
    </div>
</div>
<div style="margin-top:10px">
    <label class="f">{{ __('gl.account') }} <b class="req-star">*</b></label>
    <select name="account_id" required style="width:100%">
        <option value="">{{ __('ui.choose', ['x' => __('gl.account')]) }}</option>
        @foreach ($accounts as $a)
            <option value="{{ $a->id }}" @selected(old('account_id') == $a->id)>{{ $a->code }} · {{ $a->displayName() }}</option>
        @endforeach
    </select>
</div>
<div class="frow" style="margin-top:10px">
    <div data-cat-life>
        <label class="f">{{ __('cash.cat_life') }}</label>
        <input type="number" name="life_months" min="1" max="600" step="1" dir="ltr" value="{{ old('life_months') }}" style="width:100%">
        <div style="font-size:10.5px;color:var(--muted);margin-top:4px">{{ __('cash.cat_life_hint') }}</div>
    </div>
    <div>
        <label style="display:flex;gap:6px;align-items:center;margin-top:22px">
            <input type="checkbox" name="links_vehicle" value="1" @checked(old('links_vehicle'))> ⛽ {{ __('cash.cat_links_vehicle') }}
        </label>
    </div>
</div>
