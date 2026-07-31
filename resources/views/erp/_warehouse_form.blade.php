{{--
    فورم المخزن — إضافة وتعديل.

    ⚠️ `$w` بتكون `null` للإضافة أو النص `'edit'` لمودال التعديل
    اللي الجافاسكربت بيملاه. مش موديل — الشاشة فيها مودال تعديل
    واحد بيتملى لأي مخزن، مش مودال لكل صف.

    المتغيرات: `$w` · `$managers`
--}}
@php
    use App\Models\Warehouse;

    $isNew = $w === null;
    $id = $isNew ? 'NewW' : 'EditW';

    // ⚠️ **`old()` لمودال الإضافة بس.** الحفظ اللي بيفشل بيرجّع بـ
    // `back()`، والمودال بيتقفل — فلو المودالين الاتنين قروا `old()`
    // كان مودال «مخزن جديد» بيفتح مليان ببيانات المخزن اللي المستخدم
    // كان بيعدّله، وكليك واحدة تعمل نسخة مكررة.
    //
    // ⚠️ وعلامة الخطأ الحمرا كمان لمودال الإضافة بس، لنفس السبب:
    // كانت بتلوّن الخانة في المودالين وتحط الرسالة في واحد بس.
    $bad = fn (string $key) => $isNew && $errors->has($key) ? 'bad' : '';
    $o = fn (string $key, $default = null) => $isNew ? old($key, $default) : null;
@endphp

<dialog id="dlg{{ $id }}">
    <form class="dlg" method="POST" id="{{ $isNew ? 'newWForm' : 'edWForm' }}"
          action="{{ $isNew ? route('erp.warehouses.store') : '' }}">
        @csrf
        @unless ($isNew) @method('PUT') @endunless

        <h4>{{ $isNew ? __('stock.new_warehouse') : __('stock.edit_warehouse') }}</h4>

        <div class="frow">
            <div>
                <label class="f">{{ __('common.code') }} <b class="req-star">*</b></label>
                {{-- ⚠️ **الكود ثابت عملياً.** متخزن على أمين المخزن
                     (`users.warehouse_id`) وعلى الباتشات والأرفف — بس
                     سايبينه قابل للتعديل عشان الغلطة الإملائية أول يوم
                     تتصلّح. --}}
                <input type="text" name="code" id="{{ $isNew ? 'nwCode' : 'edWCode' }}"
                       dir="ltr" maxlength="20" required style="width:100%"
                       class="{{ $bad('code') }}"
                       value="{{ $o('code') }}" placeholder="TENTH">
                @if ($isNew) @error('code')<div class="errline">{{ $message }}</div>@enderror @endif
            </div>
            <div>
                <label class="f">{{ __('stock.type') }} <b class="req-star">*</b></label>
                <select name="type" id="{{ $isNew ? 'nwType' : 'edWType' }}" required style="width:100%">
                    {{-- ⚠️ `old()` هنا كمان — من غيره النوع كان بيرجع
                         «مصنع» بعد أي فشل تحقّق بينما كل خانات النص
                         بترجع مليانة، فالمستخدم يبعت تاني ويعمل مصنع. --}}
                    <option value="{{ Warehouse::TYPE_FACTORY }}" @selected($o('type') === Warehouse::TYPE_FACTORY)>{{ __('stock.type_factory') }}</option>
                    <option value="{{ Warehouse::TYPE_BRANCH }}" @selected($o('type') === Warehouse::TYPE_BRANCH)>{{ __('stock.type_branch') }}</option>
                </select>
                <div style="font-size:11px;color:var(--muted);margin-top:5px">{{ __('stock.type_hint') }}</div>
            </div>
        </div>

        <div class="frow">
            {{-- ⚠️ الإنجليزي الأول — زي كل فورم في السيستم. --}}
            <div>
                <label class="f">{{ __('stock.warehouse_name_en') }}</label>
                <input type="text" name="name_en" id="{{ $isNew ? 'nwNameEn' : 'edWNameEn' }}"
                       dir="ltr" maxlength="190" style="width:100%"
                       value="{{ $o('name_en') }}"
                       placeholder="{{ __('stock.warehouse_name_en_ph') }}">
            </div>
            <div>
                <label class="f">{{ __('stock.warehouse_name_ar') }} <b class="req-star">*</b></label>
                <input type="text" name="name" id="{{ $isNew ? 'nwName' : 'edWName' }}"
                       maxlength="190" required style="width:100%"
                       class="{{ $bad('name') }}"
                       value="{{ $o('name') }}"
                       placeholder="{{ __('stock.warehouse_name_ar_ph') }}">
                @if ($isNew) @error('name')<div class="errline">{{ $message }}</div>@enderror @endif
            </div>
        </div>

        <div class="frow">
            <div>
                <label class="f">{{ __('common.address') }}</label>
                <input type="text" name="address" id="{{ $isNew ? 'nwAddress' : 'edWAddress' }}"
                       maxlength="190" style="width:100%" value="{{ $o('address') }}">
            </div>
            <div>
                <label class="f">{{ __('stock.keeper') }}</label>
                <select name="manager_id" id="{{ $isNew ? 'nwManager' : 'edWManager' }}" style="width:100%">
                    <option value="">— {{ __('common.none') }} —</option>
                    @foreach ($managers as $m)
                        <option value="{{ $m->id }}" @selected((string) $o('manager_id') === (string) $m->id)>{{ $m->displayName() }} · {{ $m->roleLabel() }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        @unless ($isNew)
            <div class="frow">
                <div>
                    <label class="f">{{ __('common.status') }}</label>
                    {{-- ⚠️ الحقل المخفي لازم: التشيك بوكس المقفول مابيتبعتش
                         خالص، فالسيرفر مايفرّقش بين «موقوف» و«مااتبعتش». --}}
                    <input type="hidden" name="active" value="0">
                    <label style="display:flex;gap:7px;align-items:center;font-size:12.5px;padding-top:8px">
                        <input type="checkbox" name="active" value="1" id="edWActive">
                        {{ __('common.active') }}
                    </label>
                </div>
            </div>
        @endunless

        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlg{{ $id }}')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>
