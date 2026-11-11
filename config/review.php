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
 */
return [
    'confirm_red_checks' => (bool) env('REVIEW_CONFIRM_RED', true),
    'month_needs_all_docs' => (bool) env('REVIEW_MONTH_NEEDS_ALL_DOCS', false),
    'daily_target' => (int) env('REVIEW_DAILY_TARGET', 150),
];
