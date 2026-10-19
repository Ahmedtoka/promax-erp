<?php

namespace App\Models\Gl;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * تشغيلة إعادة بناء من المعالج: معاينة (`preview`) بالفروق، أو تنفيذ
 * (`execute`) مربوط بالمعاينة اللي اتوافق عليها (`preview_id`).
 */
class GlRebuildRun extends Model
{
    public const MODES = ['preview', 'execute'];

    public const STATUSES = ['ok', 'failed', 'blocked'];

    protected $table = 'gl_rebuild_runs';

    protected $fillable = [
        'mode', 'from_date', 'status', 'summary', 'rows', 'checks', 'error',
        'preview_id', 'approved_at', 'approved_by', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'summary' => 'array',
            'rows' => 'array',
            'checks' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    public function preview(): BelongsTo
    {
        return $this->belongsTo(self::class, 'preview_id');
    }
}
