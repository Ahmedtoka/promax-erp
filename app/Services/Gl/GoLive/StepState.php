<?php

namespace App\Services\Gl\GoLive;

/** حالة خطوة: red يمنع التأكيد · yellow تنبيه · green تمام · todo لسه */
final class StepState
{
    public const LEVELS = ['red', 'yellow', 'green', 'todo'];

    /** @param  list<string>  $messages  نصوص مترجمة جاهزة للعرض */
    public function __construct(public string $level, public array $messages = [], public bool $done = false)
    {
    }

    public function blocks(): bool
    {
        return $this->level === 'red';
    }
}
