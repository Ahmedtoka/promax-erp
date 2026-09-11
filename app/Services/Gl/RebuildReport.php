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

    /**
     * `receivables`/`payables` شكلهم `{gl: float, expected: float, ok: bool}` من
     * `Ledger::invariants()`؛ `entries` شكله `{deleted: int, created: int, ok: bool}`
     * وبيتحسب جوه `rebuild()` — بيمسك مصدر ضاع من غير ما يظهر في فحص العملاء/الموردين.
     *
     * @var array<string, array{gl?: float, expected?: float, deleted?: int, created?: int, ok: bool}>
     */
    public array $invariants = [];

    public bool $ok = false;

    public bool $dryRun = false;
}
