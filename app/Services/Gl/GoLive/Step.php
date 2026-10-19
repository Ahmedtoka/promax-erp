<?php

namespace App\Services\Gl\GoLive;

/**
 * خطوة في معالج تشغيل الدفتر (الخط ٣). الحالة **حية** (بتتحسب كل مرة)،
 * و«اتأكدت» تاريخ بس في `gl_golive_steps` — خطوة اتأكدت وبعدين بقت حمرا
 * بتبان حمرا تاني.
 *
 * الخط ٩ بيستبدل ٧ و٩ بـ`GoLiveWizard::register()` من السيرفس بروفايدر بتاعه.
 */
interface Step
{
    public function number(): int;

    public function key(): string;

    public function titleKey(): string;

    public function state(): StepState;

    /** اسم الفيو (Blade) اللي بيترسم جوه صفحة المعالج */
    public function view(): string;

    /** @return array<string, mixed> داتا الفيو */
    public function data(): array;
}
