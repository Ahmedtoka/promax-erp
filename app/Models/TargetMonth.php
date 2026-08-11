<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * شهر من قسمة تارجيت سنوي (١١ أغسطس ٢٠٢٦).
 *
 * `amount` = تارجيت الشهر (مجموع الاتناشر = إجمالي العقدة السنوي).
 * `manual_actual` = المحقق اليدوي — **لما يتكتب بيغلب المحسوب من
 * القيود** للشهر ده في عقدته، و**فرقه عن اللايف بيصعّد لأبوه**
 * (دوكترين TargetProgress ١١/٨). الأدمن بيكتبه للشهور التاريخية
 * على عقدة الشركة أو عقدة مدير، والعمود عام لأي عقدة عن قصد.
 */
class TargetMonth extends Model
{
    protected $fillable = ['target_id', 'month', 'amount', 'manual_actual'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'manual_actual' => 'decimal:2',
        ];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Target::class);
    }
}
