<?php

namespace App\Services\Gl;

/**
 * فشل الفحص بعد إعادة البناء — القيود اتلفّت (rollback) والتقرير
 * محمول على الاستثناء عشان اللي بينادي يعرض للمستخدم فين المشكلة
 * من غير ما يعيد الحساب.
 */
class RebuildFailed extends \RuntimeException
{
    public RebuildReport $report;
}
