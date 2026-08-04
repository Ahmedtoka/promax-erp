{{--
    ═══════════════════════════════════════════════════════════════
    فورم تعريف الصنف — كل الأعمدة
    ═══════════════════════════════════════════════════════════════

    ⚠️ **partial مشترك بين كارت الصنف وشاشة المخزون.** لما كان الفورم
    مكتوب في الشاشتين، كل عمود جديد كان لازم يتضاف مرتين — والمرة
    اللي بتتنسى بتخلّي الحقل يتحفظ من شاشة ومايتحفظش من التانية،
    والمستخدم بيفتكر إن السيستم بيضيّع بياناته.

    ⚠️ **`enctype` لازم.** من غيره الصورة بتتبعت كنص فاضي
    و`hasFile()` بترجّع false — المستخدم بيرفع ومفيش حاجة بتتخزن.

    المتغيرات: `$p` (Product|null) · `$families`
--}}
@php
    $isNew = ($p ?? null) === null;
    // ⚠️ `old()` على كل حقل — الحفظ اللي بيفشل بيرجّع اللي المستخدم
    // كتبه. الفورم ده فيه 20+ خانة، وكتابتها تاني من الأول عقوبة
    // على غلطة في خانة واحدة.
    $v = fn (string $key, $fallback = null) => old($key, $isNew ? null : ($p->{$key} ?? $fallback));

    // ═══ التسعير من القوايم — قرار المالك 2026-08-04 ═══
    // مفيش خانتي «قديم/جديد» تايهين: صف لكل قائمة أسعار، والسعر
    // بيتكتب في `price_list_items` (مع مزامنة عمودي old/new للـKPIs
    // والأبلكيشن). خانة فاضية = ماتلمسش سعر القايمة دي.
    $priceLists = \App\Models\PriceList::orderByDesc('is_default')->orderBy('id')->get();
    $listPrices = $isNew
        ? collect()
        : \App\Models\PriceListItem::where('product_id', $p->id)->pluck('price', 'price_list_id');

    // «الوحدة» دروب منيو من الوحدات المستخدمة فعلاً + كتابة حرة
    $unitsAr = \App\Models\Product::whereNotNull('unit')->where('unit', '!=', '')->distinct()->orderBy('unit')->pluck('unit');
    $unitsEn = \App\Models\Product::whereNotNull('unit_en')->where('unit_en', '!=', '')->distinct()->orderBy('unit_en')->pluck('unit_en');
@endphp

@once
    <datalist id="dlUnitsAr">
        @foreach ($unitsAr as $u)<option value="{{ $u }}"></option>@endforeach
    </datalist>
    <datalist id="dlUnitsEn">
        @foreach ($unitsEn as $u)<option value="{{ $u }}"></option>@endforeach
    </datalist>
@endonce

<dialog id="{{ $isNew ? 'dlgNewP' : 'dlgEditP' }}">
    <form class="dlg" method="POST" enctype="multipart/form-data"
          style="max-height:88vh;overflow-y:auto;max-width:820px"
          action="{{ $isNew ? route('erp.products.store') : route('erp.products.update', $p) }}">
        @csrf
        @unless ($isNew) @method('PUT') @endunless

        <h4>{{ $isNew ? __('stock.new_item') : __('stock.edit_product') }}</h4>

        {{-- ═════ الصورة ═════ --}}
        <div style="font-size:12px;font-weight:900;color:var(--royal-blue);margin:14px 0 10px;padding-bottom:6px;border-bottom:1px solid var(--border)">{{ __('stock.image') }}</div>
        <div class="frow">
            <div style="grid-column:1/-1;display:flex;gap:14px;align-items:flex-start;flex-wrap:wrap">
                @unless ($isNew)
                    @if ($p->imageSrc())
                        <img src="{{ $p->imageSrc() }}" alt=""
                             style="width:84px;height:84px;object-fit:contain;border-radius:var(--r-sm);
                                    border:1px solid var(--border);background:#fff;padding:4px">
                    @endif
                @endunless
                <div style="flex:1;min-width:220px">
                    <input type="file" name="image" accept="image/jpeg,image/png,image/webp" style="width:100%">
                    <div style="font-size:11px;color:var(--muted);margin-top:5px">{{ __('stock.image_hint') }}</div>
                    @error('image')<div class="errline">{{ $message }}</div>@enderror

                    @unless ($isNew)
                        @if ($p->image_path)
                            {{-- ⚠️ زرار الشيل بيمسح **المرفوعة بس**. صورة GS1
                                 بتفضل — هي مش بتاعتنا ومابنمسحش من فيدهم. --}}
                            <label style="display:flex;gap:7px;align-items:center;font-size:12px;margin-top:8px">
                                <input type="checkbox" name="remove_image" value="1">
                                {{ __('stock.image_remove') }}
                            </label>
                        @endif
                    @endunless
                </div>
            </div>
        </div>

        {{-- ═════ التعريف ═════ --}}
        <div style="font-size:12px;font-weight:900;color:var(--royal-blue);margin:16px 0 10px;padding-bottom:6px;border-bottom:1px solid var(--border)">{{ __('stock.identity') }}</div>
        <div class="frow">
            @if ($isNew)
                <div>
                    <label class="f">{{ __('stock.sku') }} <b class="req-star">*</b></label>
                    <input type="text" name="code" dir="ltr" maxlength="20" required style="width:100%"
                           class="{{ $errors->has('code') ? 'bad' : '' }}" value="{{ old('code') }}">
                    @error('code')<div class="errline">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label class="f">{{ __('stock.family') }} <b class="req-star">*</b></label>
                    <select name="family" required style="width:100%">
                        <option value="">— {{ __('stock.family') }} —</option>
                        @foreach ($families as $k => $lbl)
                            <option value="{{ $k }}" @selected(old('family') === $k)>{{ __('enums.family.'.$k) }}</option>
                        @endforeach
                    </select>
                    @error('family')<div class="errline">{{ $message }}</div>@enderror
                </div>
            @else
                {{-- ⚠️ **الكود والعائلة مايتعدّلوش.** الكود متخزن على كل
                     فاتورة وباتش وعهدة، والعائلة بتحدد مدة الصلاحية
                     الافتراضية وتقسيم التقارير. تغييرهم بيخلّي الشغل
                     القديم منسوب لصنف مختلف. --}}
                <div>
                    <label class="f">{{ __('stock.sku') }}</label>
                    <input type="text" value="{{ $p->code }}" dir="ltr" style="width:100%" disabled>
                </div>
                <div>
                    <label class="f">{{ __('stock.family') }}</label>
                    <input type="text" value="{{ $p->familyLabel() }}" style="width:100%" disabled>
                </div>
            @endif
        </div>

        <div class="frow">
            <div>
                <label class="f">{{ __('common.name_en') }}</label>
                <input type="text" name="name_en" dir="ltr" maxlength="190" style="width:100%"
                       value="{{ $v('name_en') }}" placeholder="{{ __('stock.item') }} — EN">
            </div>
            <div>
                <label class="f">{{ __('stock.item') }} <b class="req-star">*</b></label>
                <input type="text" name="name" maxlength="190" required style="width:100%"
                       class="{{ $errors->has('name') ? 'bad' : '' }}" value="{{ $v('name') }}">
                @error('name')<div class="errline">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="frow">
            <div>
                <label class="f">{{ __('stock.unit') }} <b class="req-star">*</b></label>
                {{-- دروب منيو من الوحدات الموجودة + كتابة حرة لوحدة جديدة --}}
                <input type="text" name="unit" maxlength="40" required list="dlUnitsAr" autocomplete="off" style="width:100%" value="{{ $v('unit') }}">
                @error('unit')<div class="errline">{{ $message }}</div>@enderror
            </div>
            <div>
                <label class="f">{{ __('stock.unit') }} · EN</label>
                <input type="text" name="unit_en" dir="ltr" maxlength="40" list="dlUnitsEn" autocomplete="off" style="width:100%" value="{{ $v('unit_en') }}">
            </div>
            <div>
                <label class="f">{{ __('stock.brand') }}</label>
                <input type="text" name="brand" maxlength="40" style="width:100%" value="{{ $v('brand') }}">
            </div>
        </div>

        {{-- ═════ الباركودات ═════ --}}
        <div style="font-size:12px;font-weight:900;color:var(--royal-blue);margin:16px 0 10px;padding-bottom:6px;border-bottom:1px solid var(--border)">{{ __('stock.barcode') }}</div>
        <div class="frow">
            <div>
                <label class="f">{{ __('stock.barcode') }}</label>
                {{-- ⚠️ **فريد.** `findByBarcode()` بترجّع `first()` —
                     باركودين متكررين معناهم إن المسح في الأبلكيشن بيطلّع
                     صنف عشوائي، والفاتورة بتتكتب بصنف غلط. --}}
                <input type="text" name="barcode" dir="ltr" maxlength="20" style="width:100%"
                       class="{{ $errors->has('barcode') ? 'bad' : '' }}" value="{{ $v('barcode') }}">
                @error('barcode')<div class="errline">{{ $message }}</div>@enderror
            </div>
            <div>
                <label class="f">{{ __('stock.case_barcode') }}</label>
                <input type="text" name="case_barcode" dir="ltr" maxlength="20" style="width:100%" value="{{ $v('case_barcode') }}">
            </div>
            <div>
                <label class="f">{{ __('stock.units_per_case') }}</label>
                <input type="number" name="units_per_case" min="1" max="9999" style="width:100%" value="{{ $v('units_per_case') }}" oninput="packPreview(this)">
                <div style="font-size:11px;color:var(--muted);margin-top:5px">{{ __('stock.units_per_case_hint') }}</div>
            </div>
            <div>
                <label class="f">{{ __('stock.box_units') }}</label>
                <input type="number" name="box_units" min="1" max="9999" style="width:100%" value="{{ $v('box_units') }}" oninput="packPreview(this)">
                <div style="font-size:11px;color:var(--muted);margin-top:5px">{{ __('stock.box_units_hint') }}</div>
            </div>
        </div>
        {{-- معاينة التدريج لايف: «الكرتونة = 6 علب × 12 قطعة = 72» --}}
        <div class="pack-preview" style="font-size:12px;font-weight:800;color:var(--royal-blue);margin-top:4px">
            @unless ($isNew){{ $p->packLabel() }}@endunless
        </div>

        @once
        <script>
        // ⚠️ معاينة بس — التخزين بالقطعة والضرب في السيرفر
        const PACK_T = {
            bc: @json(__('stock.pack_box_case')),
            c: @json(__('stock.pack_case_only')),
            b: @json(__('stock.pack_box_only'))
        };

        function packPreview(el) {
            const form = el.closest('form');
            const box = Number(form.querySelector('[name="box_units"]').value || 0);
            const cs = Number(form.querySelector('[name="units_per_case"]').value || 0);
            const out = form.querySelector('.pack-preview');
            let txt = '';

            if (cs > 1 && box > 1 && cs % box === 0) {
                txt = PACK_T.bc.replace(':boxes', cs / box).replace(':box', box).replace(':case', cs);
            } else if (cs > 1) {
                txt = PACK_T.c.replace(':case', cs);
            } else if (box > 1) {
                txt = PACK_T.b.replace(':box', box);
            }

            out.textContent = txt;
        }
        </script>
        @endonce

        {{-- ═════ المواصفات ═════ --}}
        <div style="font-size:12px;font-weight:900;color:var(--royal-blue);margin:16px 0 10px;padding-bottom:6px;border-bottom:1px solid var(--border)">{{ __('stock.specs') }}</div>
        <div class="frow">
            <div>
                <label class="f">{{ __('stock.net_content') }}</label>
                <input type="number" step="0.01" min="0" name="net_content" style="width:100%" value="{{ $v('net_content') }}">
            </div>
            <div>
                <label class="f">{{ __('stock.net_uom') }}</label>
                <select name="net_uom" style="width:100%">
                    <option value="">—</option>
                    @foreach (['g' => 'g', 'ml' => 'ml', 'pc' => 'pc'] as $k => $lbl)
                        <option value="{{ $k }}" @selected($v('net_uom') === $k)>{{ $lbl }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="f">{{ __('stock.shelf_life') }}</label>
                <input type="number" name="shelf_life_months" min="1" max="120" style="width:100%"
                       value="{{ $v('shelf_life_months') }}" placeholder="12">
                <div style="font-size:11px;color:var(--muted);margin-top:5px">{{ __('stock.shelf_life_hint') }}</div>
            </div>
        </div>

        <div class="frow">
            <div style="grid-column:1/-1">
                <label class="f">{{ __('stock.gpc_category') }}</label>
                <input type="text" name="gpc_category" maxlength="200" style="width:100%" value="{{ $v('gpc_category') }}">
            </div>
        </div>

        <div class="frow">
            <div>
                <label class="f">{{ __('stock.description') }}</label>
                <textarea name="description" rows="3" maxlength="2000" style="width:100%">{{ $v('description') }}</textarea>
                <div style="font-size:11px;color:var(--muted);margin-top:5px">{{ __('stock.description_hint') }}</div>
            </div>
            <div>
                <label class="f">{{ __('stock.description') }} · EN</label>
                <textarea name="description_en" dir="ltr" rows="3" maxlength="2000" style="width:100%">{{ $v('description_en') }}</textarea>
            </div>
        </div>

        {{-- ═════ التسعير والضريبة ═════ --}}
        <div style="font-size:12px;font-weight:900;color:var(--royal-blue);margin:16px 0 10px;padding-bottom:6px;border-bottom:1px solid var(--border)">{{ __('ops.pricing') }}</div>
        <div class="frow">
            <div>
                <label class="f">{{ __('stock.cost') }} <b class="req-star">*</b></label>
                <input type="number" step="0.01" min="0" name="cost" required style="width:100%" value="{{ $v('cost', 0) }}">
            </div>
        </div>

        {{-- ⚠️ **السعر من القوايم مش من عمودين.** صف لكل قائمة أسعار —
             السعر اللي هنا هو اللي بيتحاسب بيه العميل المربوط بالقايمة.
             خانة فاضية = سعر القايمة دي مايتلمسش (والقايمة مابتتفعّلش
             غير لما كل صنف مفعّل يبقى ليه سعر فيها). --}}
        <div class="frow">
            @forelse ($priceLists as $list)
                <div>
                    <label class="f">
                        💰 {{ $list->displayName() }}
                        @if ($list->is_default)<span class="badge b-gold" style="font-size:9.5px">{{ __('price.default') }}</span> <b class="req-star">*</b>@endif
                    </label>
                    {{-- القايمة الافتراضية إجبارية للصنف الجديد — الباقي اختياري --}}
                    <input type="number" step="0.01" style="width:100%"
                           min="{{ $list->is_default && $isNew ? '0.01' : '0' }}"
                           @if ($list->is_default && $isNew) required @endif
                           name="list_price[{{ $list->id }}]"
                           value="{{ old('list_price.'.$list->id, $listPrices[$list->id] ?? null) }}">
                </div>
            @empty
                {{-- داتابيز لسه ماتهاجرتش للقوايم — نرجع للعمودين --}}
                <div>
                    <label class="f">{{ __('stock.price_old') }} <b class="req-star">*</b></label>
                    <input type="number" step="0.01" min="0" name="price_old" required style="width:100%" value="{{ $v('price_old', 0) }}">
                </div>
                <div>
                    <label class="f">{{ __('stock.price_new') }} <b class="req-star">*</b></label>
                    <input type="number" step="0.01" min="0" name="price_new" required style="width:100%" value="{{ $v('price_new', 0) }}">
                </div>
            @endforelse
        </div>
        <div style="font-size:11px;color:var(--muted);margin-top:2px">{{ __('stock.list_price_hint') }}</div>

        <div class="frow">
            <div>
                <label class="f">{{ __('stock.eta_code') }}</label>
                <input type="text" name="eta_code" dir="ltr" maxlength="30" style="width:100%" value="{{ $v('eta_code') }}">
                <div style="font-size:11px;color:var(--muted);margin-top:5px">{{ __('stock.eta_code_hint') }}</div>
            </div>
            <div>
                <label class="f">{{ __('client.tax_rate') }} %</label>
                <input type="number" step="0.5" min="0" max="100" name="tax_rate" style="width:100%"
                       value="{{ old('tax_rate', $isNew ? null : round((float) $p->tax_rate * 100, 2)) }}">
            </div>
            <div>
                <label class="f">{{ __('client.taxable') }}</label>
                {{-- ⚠️ الحقل المخفي لازم: التشيك بوكس المقفول مابيتبعتش
                     خالص، فالسيرفر مايقدرش يفرّق بين «مش خاضع» و«الحقل
                     مااتبعتش». --}}
                <input type="hidden" name="taxable" value="0">
                <label style="display:flex;gap:7px;align-items:center;font-size:12.5px;padding-top:8px">
                    <input type="checkbox" name="taxable" value="1"
                           @checked(old('taxable', $isNew ? 1 : ($p->taxable ? 1 : 0)))>
                    {{ __('client.taxable') }}
                </label>
            </div>
        </div>

        {{-- ═════ المخزون ═════ --}}
        {{--
            ⚠️ **الكميات اتشالت من هنا عن قصد.**

            الفورم ده كان فيه «الكمية» و«الهولد» — رقم واحد لصنف بقى
            له صف رصيد في كل مخزن. فكان بيعمل تلاتة:

            1. بيعرض رصيد أول مخزن (`stocks->first()`) وبيكتب على مخزن
               تاني (الافتراضي)، فالكمية بتتنسخ وتتضاعف على مستوى الشركة.
            2. أي حفظ — حتى تعديل وصف — كان بيكتب `counted_at = اليوم`،
               فتاريخ آخر جرد حقيقي بيضيع.
            3. مافيش خانة تقول «فين»، فاللي بيكتب مش عارف الرقم رايح لمين.

            التوزيع مكانه شاشة «تعديل الأرصدة» لكل مخزن، والصنف الجديد
            بيتولد بصفوف صفر في كل المخازن فبيبان فيها كلها من أول يوم.
        --}}
        <div style="font-size:12px;font-weight:900;color:var(--royal-blue);margin:16px 0 10px;padding-bottom:6px;border-bottom:1px solid var(--border)">{{ __('nav.inventory') }}</div>
        <div class="frow">
            <div style="flex:1">
                <div class="alert info" style="margin:0">
                    <span>📦</span><span>{{ __('stock.qty_from_warehouse_screen') }}</span>
                </div>
            </div>
            @unless ($isNew)
                <div>
                    <label class="f">{{ __('common.status') }}</label>
                    <input type="hidden" name="active" value="0">
                    <label style="display:flex;gap:7px;align-items:center;font-size:12.5px;padding-top:8px">
                        <input type="checkbox" name="active" value="1" @checked(old('active', $p->active ? 1 : 0))>
                        {{ __('common.active') }}
                    </label>
                    <div style="font-size:11px;color:var(--muted);margin-top:5px">{{ __('stock.active_hint') }}</div>
                </div>
            @endunless
        </div>

        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('{{ $isNew ? 'dlgNewP' : 'dlgEditP' }}')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>
