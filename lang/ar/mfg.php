<?php

/**
 * التصنيع (الإغلاق ١٩/١٠، الخط ٦) — مواصفات، قوايم مواد، أوامر إنتاج، جودة.
 */
return [
    // ═══ الصرف من مخزن الخامات ═══
    'qty_positive' => 'الكمية لازم تكون أكبر من صفر',
    'raw_whole_units' => 'الكمية لازم تكون رقم صحيح لحد ما وحدات الخامات تنزل',
    'raw_short' => 'رصيد :product مش كفاية — المتاح :available',

    // ═══ الحالات ═══
    'spec_status_draft' => 'مسودة',
    'spec_status_under_review' => 'تحت المراجعة',
    'spec_status_approved' => 'معتمدة',
    'spec_status_archived' => 'مؤرشفة',
    'bom_status_draft' => 'مسودة',
    'bom_status_approved' => 'معتمدة',
    'bom_status_archived' => 'مؤرشفة',
    'order_status_planned' => 'مخطط',
    'order_status_in_progress' => 'جاري',
    'order_status_finished' => 'انتهى',
    'order_status_closed' => 'مقفول',
    'order_status_cancelled' => 'ملغي',
    'qc_status_quarantine' => 'في الحجر',
    'qc_status_released' => 'مُفرج عنه',
    'qc_status_rejected' => 'مرفوض',
    'issue_kind_issue' => 'صرف',
    'issue_kind_extra' => 'صرف إضافي',
    'issue_kind_return' => 'رجوع للمخزن',

    // ═══ الشاشات ═══
    'orders_title' => 'أوامر الإنتاج',
    'qc_title' => 'الجودة والإفراج',
    'specs_title' => 'مواصفات المنتجات',
    'col_number' => 'رقم الأمر',
    'col_product' => 'المنتج',
    'col_line' => 'الخط',
    'col_batch_no' => 'رقم الباتش',
    'col_planned_qty' => 'الكمية المخططة',
    'col_output_qty' => 'الإنتاج الفعلي',
    'col_planned_on' => 'تاريخ الخطة',
    'col_produced_on' => 'تاريخ الإنتاج',
    'col_expires_on' => 'الانتهاء',
    'col_status' => 'الحالة',
    'col_qc_status' => 'حالة الجودة',
    'col_qc_at' => 'وقت القرار',
    'col_version' => 'الإصدار',
    'col_shelf_life' => 'الصلاحية (يوم)',
    'col_net_weight' => 'الوزن الصافي (جم)',
    'col_updated_at' => 'آخر تعديل',
    'no_orders' => 'مفيش أوامر إنتاج في الفترة دي',
    'no_qc' => 'مفيش باتشات إنتاج في الجودة في الفترة دي',
    'no_specs' => 'مفيش مواصفات في الفترة دي',
    'all_statuses' => 'كل الحالات',
];
