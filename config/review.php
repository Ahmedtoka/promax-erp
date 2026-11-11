<?php

/*
 * ═══════════════════════════════════════════════════════════════
 * مراجعة المستندات — مفاتيح قرارات أحمد (١٢/١١/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * القرارين دول اتعملوا بالافتراضي لحد ما أحمد يقرر؛ قلبهم من `.env`
 * من غير كود (وبعدها `php artisan config:cache` على اللايف):
 *
 *   REVIEW_CONFIRM_RED=true        — مستند عليه فحص أحمر: أول Enter تحذير،
 *                                    والتانية (خلال ٥ ثواني) تأكيد ✓. false = ✓ على طول.
 *   REVIEW_MONTH_NEEDS_ALL_DOCS=false — «طابق الشهر» في الحسابات والشهور:
 *                                    false = تحذير بس لو مستندات الشهر لسه ماتراجعتش كلها،
 *                                    true  = ممنوع ✓ الشهر لحد ما كل مستنداته تتراجع ✓.
 *   REVIEW_DAILY_TARGET=150        — هدف المستندات في اليوم (شريط السرعة).
 *   REVIEW_PO_PROOF_REQUIRED=false — أمر توريد اتسلّم من غير إثبات استلام (لا إذن ولا إمضاء/ختم):
 *                                    false = فحص أحمر بيتأكد بالضغطة التانية (زي باقي التحذيرات)،
 *                                    true  = ممنوع ✓ لحد ما يتعلّم إذن أو إمضاء/ختم (زي السيريال).
 */
return [
    'confirm_red_checks' => (bool) env('REVIEW_CONFIRM_RED', true),
    'month_needs_all_docs' => (bool) env('REVIEW_MONTH_NEEDS_ALL_DOCS', false),
    'daily_target' => (int) env('REVIEW_DAILY_TARGET', 150),
    'po_proof_required' => (bool) env('REVIEW_PO_PROOF_REQUIRED', false),

    /*
     * (١٢/١١ — أحمد) إمضاء / ختم العميل على إذن المرتجع: false (الافتراضي) =
     * تحذير أحمر بيتأكد بضغطة تانية، true = مفيش ✓ لمرتجع من غير إمضاء/ختم.
     */
    'return_proof_required' => (bool) env('REVIEW_RETURN_PROOF_REQUIRED', false),
];
