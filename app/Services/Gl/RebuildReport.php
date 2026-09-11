<?php

namespace App\Services\Gl;

class RebuildReport
{
    public int $deleted = 0;

    public int $created = 0;

    public int $overridesKept = 0;

    public int $overridesDropped = 0;

    /** @var array<string, array{before: float, after: float}> */
    public array $accountDiff = [];

    /** @var array<string, array{gl: float, expected: float, ok: bool}> */
    public array $invariants = [];

    public bool $ok = false;

    public bool $dryRun = false;
}
