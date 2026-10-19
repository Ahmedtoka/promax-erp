<?php

namespace App\Services\Gl;

/**
 * الشجرة اتعتمدت وفيه مفتاح نظام أو خانة قاعدة لسه مش مربوطة صح (D5) —
 * إعادة البناء بتترفض لحد ما شاشة الربط تخلص.
 */
class UnmappedRules extends \RuntimeException
{
    /** @param  list<string>  $problems */
    public function __construct(public array $problems)
    {
        parent::__construct(__('gl.map_unmapped', ['list' => implode(' · ', $problems)]));
    }
}
