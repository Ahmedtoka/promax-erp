{{-- حقول المورد — مشتركة بين الإضافة والتعديل. $s = المورد أو null --}}
<div class="frow">
    <div>
        <label class="f">{{ __('client.name_en_field') }}</label>
        <input type="text" name="name_en" dir="ltr" maxlength="190" style="width:100%"
               value="{{ old('name_en', $s?->name_en) }}">
    </div>
    <div>
        <label class="f">{{ __('client.name_ar_field') }} *</label>
        <input type="text" name="name" required maxlength="190" style="width:100%"
               value="{{ old('name', $s?->name) }}">
    </div>
</div>
<div class="frow">
    <div>
        <label class="f">{{ __('common.phone') }}</label>
        <input type="text" name="phone" dir="ltr" maxlength="30" style="width:100%"
               value="{{ old('phone', $s?->phone) }}">
    </div>
    <div>
        <label class="f">{{ __('supplier.contact_person') }}</label>
        <input type="text" name="contact_person" maxlength="190" style="width:100%"
               value="{{ old('contact_person', $s?->contact_person) }}">
    </div>
</div>
<div class="frow">
    <div>
        <label class="f">{{ __('client.tax_id') }}</label>
        <input type="text" name="tax_id" dir="ltr" maxlength="40" style="width:100%"
               value="{{ old('tax_id', $s?->tax_id) }}" placeholder="123-456-789">
    </div>
    <div>
        <label class="f">{{ __('supplier.payment_days') }}</label>
        <input type="number" name="payment_days" min="0" max="365" style="width:100%"
               value="{{ old('payment_days', $s?->payment_days) }}"
               placeholder="{{ __('supplier.payment_days_ph') }}">
    </div>
</div>
<div style="margin-top:10px">
    <label class="f">{{ __('common.address') }}</label>
    <input type="text" name="address" maxlength="190" style="width:100%"
           value="{{ old('address', $s?->address) }}">
</div>
<div style="margin-top:10px">
    <label class="f">{{ __('common.notes') }}</label>
    <textarea name="notes" rows="2" style="width:100%">{{ old('notes', $s?->notes) }}</textarea>
</div>
@if ($s !== null)
    <label style="display:flex;gap:8px;align-items:center;font-size:12.5px;font-weight:800;margin-top:12px;cursor:pointer">
        <input type="hidden" name="active" value="0">
        <input type="checkbox" name="active" value="1" @checked(old('active', $s->active))>
        {{ __('common.active') }}
    </label>
@endif
