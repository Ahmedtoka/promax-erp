<?php

namespace App\Services\Gl;

/** الاعتماد اترفض: المسودة فيها ملاحظات حمرا لسه ماتصلّحتش */
class CoaHasRed extends \RuntimeException
{
    public function __construct(public readonly int $red)
    {
        parent::__construct(__('coa.adopt_blocked', ['n' => $red]));
    }
}
