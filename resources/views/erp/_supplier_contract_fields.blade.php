{{-- حقول عقد المورد — مشتركة بين الإضافة والتعديل. $c = العقد أو null --}}
<div class="frow">
    <div>
        <label class="f">{{ __('supplier.contract_starts') }} *</label>
        <input type="date" name="starts_on" required style="width:100%"
               value="{{ old('starts_on', $c?->starts_on?->format('Y-m-d')) }}">
    </div>
    <div>
        <label class="f">{{ __('supplier.contract_ends') }} *</label>
        <input type="date" name="ends_on" required style="width:100%"
               value="{{ old('ends_on', $c?->ends_on?->format('Y-m-d')) }}">
        @error('ends_on')<div class="neg" style="font-size:12px">{{ $message }}</div>@enderror
    </div>
</div>
<label class="f">{{ __('supplier.contract_attachment') }}</label>
<input type="file" name="attachment" accept=".jpg,.jpeg,.png,.webp,.pdf" style="width:100%">
<label class="f">{{ __('common.notes') }}</label>
<textarea name="notes" rows="2" style="width:100%">{{ old('notes', $c?->notes) }}</textarea>
@if ($c)
    <input type="hidden" name="active" value="0">
    <label class="f" style="display:flex;gap:6px;align-items:center;margin-top:8px">
        <input type="checkbox" name="active" value="1" @checked(old('active', $c->active))>
        {{ __('common.active') }}
    </label>
@endif
