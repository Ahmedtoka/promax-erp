<?php

/**
 * مسميات القيم المكوّدة (رولز، حالات، تصنيفات، عائلات).
 * المفاتيح لازم تطابق ثوابت الموديلز بالظبط.
 * المصطلحات محكومة بسكيل promax-i18n — ممنوع الاجتهاد.
 */
return [

    'role' => [
        'admin' => 'أدمن',
        'manager' => 'مدير قناة',
        'branch_manager' => 'مدير فرع',
        'accountant' => 'محاسب',
        'warehouse_keeper' => 'أمين مخزن',
        'sales_agent' => 'سيلز إيجينت',
        'driver' => 'سواق توزيع',
        'promoter' => 'بروموتر',
    ],

    'channel' => [
        'key_account' => 'كي أكاونت',
        'online' => 'أونلاين',
        'cash_van' => 'كاش فان',
        'wholesale' => 'جملة (هول سيل)',
    ],

    'sub_channel' => [
        'chain' => 'سلاسل هايبر وماركت',
        'convenience' => 'كونفينيانس ومحطات',
    ],

    'category' => [
        'danger' => '🔴 تحصيل فوري',
        'watch' => '🟠 تابع عن قرب',
        'grow' => '🟢 كبّر التعامل',
        'ok' => '✅ منتظم',
        'idle' => '⚪ خامل',
        'internal' => '🚚 قناة داخلية',
        'credit' => '🔵 رصيد دائن',
    ],

    'client_status' => [
        'active' => 'نشط',
        'inactive' => 'موقوف',
        'blocked' => 'محظور',
    ],

    'family' => [
        'promax_bar' => 'بروماكس بار',
        'promax_cup' => 'بروكب',
        'spreads' => 'سبريدز',
        'pmx_bar' => 'PMX بار',
        'energy_bar' => 'إنرچي بار',
    ],

    'po_approval' => [
        'pending' => 'مستني الحسابات',
        'approved' => 'اعتمدته الحسابات',
        'rejected' => 'مرفوض',
    ],

    'po_status' => [
        'pending' => 'مستني',
        'arrived' => 'جاري التسليم',
        'delivered' => 'اتسلم',
        'cancelled' => 'ملغي',
    ],

    'request_status' => [
        'pending' => 'مستني الموافقة',
        'review' => 'تحت المراجعة',
        'approved' => 'متوافق عليه',
        'rejected' => 'مرفوض',
    ],

    'replenishment_status' => [
        'pending' => 'مستني التوزيع',
        'assigned' => 'اتنزّل على مندوب',
        'delivered' => 'اتورد',
        'cancelled' => 'ملغي',
    ],

    'payment' => [
        'cash' => 'كاش',
        'credit' => 'آجل',
    ],

    'transaction' => [
        'sale' => 'بيع',
        'collection' => 'تحصيل',
        'return' => 'مرتجع',
        'rebate' => 'خصم تجاري',
        'settlement' => 'تسوية',
        'opening' => 'رصيد افتتاحي',
        'transfer' => 'قيد تحويل',
        'taxded' => 'ضرائب مخصومة',
        'consignment' => 'بضاعة أمانة',
        'refund' => 'رد نقدي لمرتجع',
    ],

    'price_mode' => [
        'client' => 'تسعيرة العميل (قائمته وخصمه)',
        'channel' => 'تسعيرة العميل (قديم)',
        'old' => 'قائمة السعر القديمة',
        'new' => 'قائمة السعر الجديدة',
    ],

    // ⚠️ لازم تطابق مفاتيح TrackEvent::TYPES بالحرف — أي نوع ناقص
    // هنا بيطلع في الشاشات باسمه الإنجليزي الخام
    'track' => [
        'start' => 'استلم عهدة',
        'open' => 'فتح الأبلكيشن',
        'check_in' => 'دخل عند عميل',
        'check_out' => 'خرج من عميل',
        'sale' => 'باع',
        'return' => 'مرتجع',
        'gift' => 'هدية',
        'deliver' => 'سلّم أمر توريد',
        'refill' => 'ريفيل رف',
        'request' => 'طلب عميل جديد',
        'shift_in' => 'بدأ شغل',
        'shift_break' => 'بريك',
        'shift_back' => 'رجع من البريك',
        'shift_out' => 'خلّص شغل',
        'wh_in' => 'دخل مخزن',
        'wh_out' => 'خرج من مخزن',
        'collect' => 'حصّل فلوس',
        'shelf' => 'ترتيب رف',
        'po_abort' => 'رجّع أمر من غير تسليم',
        'custody_adjust' => 'تعديل إداري على العهدة',
    ],

];
