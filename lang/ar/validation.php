<?php

/**
 * ═══════════════════════════════════════════════════════════════
 * رسايل التحقق بالعربي
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **الملف ده ماكانش موجود خالص.** لارافيل كان بيطلّع رسايله
 * الإنجليزية الافتراضية باسم العمود الخام: «The name_en field is
 * required.» — واللي بيدخل الداتا مش عارف `name_en` دي مين ولا هي
 * فين في الشاشة.
 *
 * ⚠️ `attributes` تحت هي الجزء المهم فعلاً: بتحوّل اسم العمود لاسم
 * الخانة زي ما هي مكتوبة على الشاشة. من غيرها الرسالة بتفضل بلغة
 * الداتابيز مش بلغة المستخدم.
 */
return [

    'required' => 'حقل :attribute مطلوب.',
    'required_if' => 'حقل :attribute مطلوب لما :other يكون :value.',
    'required_with' => 'حقل :attribute مطلوب مع :values.',
    'required_unless' => 'حقل :attribute مطلوب إلا لو :other كان :values.',
    'filled' => 'حقل :attribute مايصحّش يبقى فاضي.',

    'string' => ':attribute لازم يكون نص.',
    'numeric' => ':attribute لازم يكون رقم.',
    'integer' => ':attribute لازم يكون رقم صحيح.',
    'boolean' => ':attribute لازم يكون نعم أو لا.',
    'array' => ':attribute لازم يكون قايمة.',
    'date' => ':attribute لازم يكون تاريخ صحيح.',
    'email' => ':attribute لازم يكون إيميل صحيح.',
    'url' => ':attribute لازم يكون رابط صحيح.',
    'json' => ':attribute لازم يكون JSON صحيح.',

    'in' => 'الاختيار في :attribute مش من القايمة.',
    'not_in' => 'الاختيار في :attribute مش مسموح.',
    'exists' => ':attribute المختار مش موجود.',
    'unique' => ':attribute ده مستخدم قبل كده.',
    'confirmed' => 'تأكيد :attribute مش مطابق.',
    'same' => ':attribute و :other لازم يكونوا زي بعض.',
    'different' => ':attribute و :other لازم يكونوا مختلفين.',

    'after' => ':attribute لازم يكون بعد :date.',
    'after_or_equal' => ':attribute لازم يكون :date أو بعده.',
    'before' => ':attribute لازم يكون قبل :date.',
    'before_or_equal' => ':attribute لازم يكون :date أو قبله.',

    'regex' => 'صيغة :attribute مش صحيحة.',

    'file' => ':attribute لازم يكون ملف.',
    'image' => ':attribute لازم يكون صورة.',
    'mimes' => ':attribute لازم يكون من نوع: :values.',
    'uploaded' => 'رفع :attribute فشل — يمكن الملف كبير أوي.',

    // ⚠️ لارافيل بيختار الصيغة حسب نوع القيمة. الأربعة لازم يتكتبوا،
    // وإلا الرسالة بتطلع بالمفتاح الخام على النوع اللي اتنسي.
    'max' => [
        'numeric' => ':attribute مايزيدش عن :max.',
        'file' => ':attribute مايزيدش عن :max كيلوبايت.',
        'string' => ':attribute مايزيدش عن :max حرف.',
        'array' => ':attribute مايزيدش عن :max عنصر.',
    ],
    'min' => [
        'numeric' => ':attribute ماينقصش عن :min.',
        'file' => ':attribute ماينقصش عن :min كيلوبايت.',
        'string' => ':attribute ماينقصش عن :min حرف.',
        'array' => ':attribute لازم يكون فيه :min عنصر على الأقل.',
    ],
    'between' => [
        'numeric' => ':attribute لازم يكون بين :min و :max.',
        'file' => ':attribute لازم يكون بين :min و :max كيلوبايت.',
        'string' => ':attribute لازم يكون بين :min و :max حرف.',
        'array' => ':attribute لازم يكون فيه من :min لـ :max عنصر.',
    ],

    'custom' => [
        'location_url' => [
            'regex' => 'لينك اللوكيشن لازم يبدأ بـ http أو https.',
        ],
        'clause.*.value' => [
            'required_if' => 'علّمت البند ده — اكتب قيمته أو شيل العلامة.',
        ],
    ],

    /**
     * أسماء الخانات زي ما هي مكتوبة على الشاشة.
     *
     * ⚠️ أي حقل جديد لازم ينزل هنا. من غيره الرسالة بتقول اسم العمود
     * الخام (`manager_id`) واللي بيدخل الداتا بيدوّر عليه في الشاشة
     * ومش لاقيه.
     */
    'attributes' => [
        // ─── تعريف العميل ───
        'name' => 'اسم العميل',
        'name_en' => 'الاسم بالإنجليزي',
        'phone' => 'التليفون',
        'governorate' => 'المحافظة',
        'zone_id' => 'المنطقة',
        'address' => 'العنوان',
        'location_url' => 'لينك اللوكيشن',
        'channel_id' => 'القناة',
        'sub_channel' => 'قسم الكي أكاونت',
        'branch_id' => 'الفرع',
        'group_id' => 'السلسلة',
        'manager_id' => 'مدير الحساب',
        'rep_id' => 'المندوب',
        'lat' => 'خط العرض',
        'lng' => 'خط الطول',
        'contacts' => 'جهات التواصل',
        'contacts.*.name' => 'اسم جهة التواصل',
        'contacts.*.role' => 'صفة جهة التواصل',
        'contacts.*.phone' => 'تليفون جهة التواصل',
        'notes' => 'الملاحظات',
        'category' => 'التصنيف',

        // ─── التسعير والعقد ───
        'price_list' => 'قائمة السعر',
        'discount' => 'الخصم الخاص',
        'has_contract' => 'العميل له عقد',
        'contract_type' => 'نوع العقد',
        'contract_duration' => 'مدة التعاقد',
        'contract_payment_days' => 'أيام السداد',
        'contract_payment_days_from' => 'طريقة حساب الأيام',
        'contract_starts_at' => 'تاريخ بداية العقد',
        'contract_ends_at' => 'تاريخ نهاية العقد',
        'contract_note' => 'ملاحظات العقد',
        'contract_clauses' => 'بنود العقد',
        'contract_clauses.*' => 'بند العقد',
        'contract_file' => 'ملف العقد',
        'clause' => 'بنود الخصم',
        'clause.invoice_discount.value' => 'الخصم على الفاتورة',
        'clause.quarterly_rebate.value' => 'الخصم الربع سنوي',
        'clause.annual_rebate.value' => 'الخصم السنوي',
        'clause.collection_fee.value' => 'الخصم عند التحصيل',
        'clause.withholding.value' => 'حجز الضمان',
        'clause.shelf_rent.value' => 'إيجار الأرفف',
        'clause.magazine.value' => 'المجلات والدعاية',
        'clause.listing_fee.value' => 'رسوم التكويد',
        'clause.opening_fee.value' => 'دعم افتتاح الفرع',

        // ─── الضريبة ───
        'taxable' => 'خاضع للضريبة',
        'tax_rate' => 'نسبة الضريبة',
        'tax_cycle' => 'دورة الإقرار',
        'tax_id' => 'الرقم الضريبي',
        'eta_type' => 'نوع المستلم عند المصلحة',

        // ─── حاجات تانية في السيستم ───
        'code' => 'الكود',
        'unit' => 'الوحدة',
        'unit_en' => 'الوحدة بالإنجليزي',
        'family' => 'عائلة المنتج',
        'cost' => 'التكلفة',
        'price_old' => 'السعر القديم',
        'price_new' => 'السعر الجديد',
        'qty' => 'الكمية',
        'barcode' => 'الباركود',
        'amount' => 'المبلغ',
        'date' => 'التاريخ',
        'memo' => 'البيان',
        'plate' => 'رقم اللوحة',
        'kind' => 'النوع',
        'kind_en' => 'النوع بالإنجليزي',
        'email' => 'الإيميل',
        'password' => 'كلمة السر',
        'chain' => 'السلسلة',
        'chain_en' => 'السلسلة بالإنجليزي',
        'label' => 'نص البند',
        'label_en' => 'نص البند بالإنجليزي',
        'pct' => 'النسبة',
        'basis' => 'التوقيت',
    ],
];
