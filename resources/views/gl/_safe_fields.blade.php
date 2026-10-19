{{-- خانات خزنة/بنك — مشتركة بين ديالوج الإضافة والتعديل في `gl/safes` (المتغيرات من الشاشة، مافيش كويري هنا) --}}
<div class="frow" style="margin-top:10px">
    <div>
        <label class="f">{{ __('cash.name') }} <b class="req-star">*</b></label>
        <input type="text" name="name" required maxlength="120" value="{{ $mode === 'new' ? old('name') : '' }}" style="width:100%">
    </div>
    <div>
        <label class="f">{{ __('cash.name_en') }}</label>
        <input type="text" name="name_en" maxlength="120" dir="ltr" value="{{ $mode === 'new' ? old('name_en') : '' }}" style="width:100%">
    </div>
</div>

<div class="frow" style="margin-top:10px" data-bank-box hidden>
    <div>
        <label class="f">{{ __('cash.bank_name') }} <b class="req-star">*</b></label>
        <input type="text" name="bank_name" maxlength="120" value="{{ $mode === 'new' ? old('bank_name') : '' }}" style="width:100%">
    </div>
    <div>
        <label class="f">{{ __('cash.account_no') }}</label>
        <input type="text" name="account_no" maxlength="60" dir="ltr" value="{{ $mode === 'new' ? old('account_no') : '' }}" style="width:100%">
    </div>
</div>

<div class="frow" style="margin-top:10px">
    <div>
        <label class="f">{{ __('cash.branch') }}</label>
        <select name="branch_id" style="width:100%">
            <option value="">—</option>
            @foreach ($branches as $b)
                <option value="{{ $b->id }}">{{ $b->displayName() }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="f">{{ __('cash.cost_center') }}</label>
        <select name="cost_center_id" style="width:100%">
            <option value="">{{ __('cash.cost_center_hint') }}</option>
            @foreach ($costCenters as $cc)
                <option value="{{ $cc->id }}">{{ $cc->code }} — {{ $cc->displayName() }}</option>
            @endforeach
        </select>
    </div>
</div>

<div class="frow" style="margin-top:10px">
    <div>
        <label class="f">{{ __('cash.custodian') }}</label>
        <select name="custodian_user_id" style="width:100%">
            <option value="">—</option>
            @foreach ($users as $u)
                <option value="{{ $u->id }}">{{ $u->displayName() }}{{ $u->code ? ' ('.$u->code.')' : '' }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="f">{{ __('cash.gl_account') }}</label>
        <select name="gl_account_id" style="width:100%">
            <option value="">{{ __('cash.gl_account_auto') }}</option>
            @foreach ($accounts as $a)
                <option value="{{ $a->id }}">{{ $a->code }} — {{ $a->displayName() }}</option>
            @endforeach
        </select>
    </div>
</div>

<div style="margin-top:10px">
    <input type="hidden" name="active" value="0">
    <label><input type="checkbox" name="active" value="1" checked> {{ __('cash.active') }}</label>
</div>
