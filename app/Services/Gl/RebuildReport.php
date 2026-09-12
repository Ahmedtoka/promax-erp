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
     * `Ledger::invariants()`؛ `entries` شكله
     * `{deleted: int, created: int, missing: int, ok: bool}` وبيتحسب جوه
     * `rebuild()` — فحص بالمفتاح (source_type|source_id) مش بمقارنة عددين:
     * كل مصدر اتمسح قيده لازم يرجّع قيد جديد. أول إعادة بناء على شجرة فاضية
     * (deleted=0, created=N) بتعدي عادي؛ مصدر ضاع فعلاً أو قاعدة اتقفلت
     * بيظهر في `missing` حتى لو الأعداد اتصادفت بالمصادفة.
     *
     * @var array<string, array{gl?: float, expected?: float, deleted?: int, created?: int, missing?: int, ok: bool}>
     */
    public array $invariants = [];

    /** أول 50 مفتاح مصدر (source_type|source_id) اتمسح ولم يرجّع له قيد جديد */
    public array $missingSources = [];

    public bool $ok = false;

    public bool $dryRun = false;
}
