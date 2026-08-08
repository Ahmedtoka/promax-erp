<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * صورة رف على زيارة سيلز إيجينت — قبل الترتيب أو بعده.
 *
 * ⚠️ متعدد الصور لكل مرحلة عن قصد (طلب المالك ٩ أغسطس ٢٠٢٦) —
 * مش زي `merch_visits.photo_before/after` اللي صورة واحدة.
 */
class VisitPhoto extends Model
{
    public const STAGE_BEFORE = 'before';
    public const STAGE_AFTER = 'after';

    public const STAGES = [self::STAGE_BEFORE, self::STAGE_AFTER];

    protected $fillable = ['visit_id', 'stage', 'path'];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function url(): string
    {
        return asset('storage/'.$this->path);
    }
}
