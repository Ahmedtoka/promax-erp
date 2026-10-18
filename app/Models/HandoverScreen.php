<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * شاشة جوه موديول تسليم — `route` اسم الراوت من `Access::NAV`.
 *
 * ⚠️ **«معلّمة» = ليها تعليم من غير `unmarked_at`.** الإلغاء مابيمسحش
 * الصف، فأي عدّ لازم يعدّي على `activeMark()` / `scopeMarked` مش على
 * وجود صف في `handover_marks`.
 */
class HandoverScreen extends Model
{
    protected $fillable = ['module_id', 'route', 'title_ar', 'title_en', 'sort', 'touched_at'];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'touched_at' => 'datetime',
        ];
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(HandoverModule::class, 'module_id');
    }

    public function marks(): HasMany
    {
        return $this->hasMany(HandoverMark::class, 'screen_id');
    }

    /** الشاشات اللي ليها تعليم شغّال */
    public function scopeMarked($q)
    {
        return $q->whereHas('marks', fn ($m) => $m->whereNull('unmarked_at'));
    }

    /**
     * آخر تعليم شغّال (من غير `unmarked_at`) — null لو مش معلّمة.
     * ⚠️ لو العلاقة محمّلة بنقرا منها (الشاشة بتعرض قايمة شاشات).
     */
    public function activeMark(): ?HandoverMark
    {
        $marks = $this->relationLoaded('marks')
            ? $this->marks
            : $this->marks()->whereNull('unmarked_at')->get();

        return $marks->whereNull('unmarked_at')
            ->sortByDesc(fn (HandoverMark $m) => [$m->marked_at?->getTimestamp() ?? 0, $m->id])
            ->first();
    }

    /** الشاشة اتعدلت بعد التعليم؟ — «اتعدلت بعد الاستلام»، والتعليم فاضل */
    public function isStale(): bool
    {
        $mark = $this->activeMark();

        return $mark !== null
            && $this->touched_at !== null
            && $this->touched_at->gt($mark->marked_at);
    }

    public function displayTitle(): string
    {
        return app()->getLocale() === 'ar' ? $this->title_ar : ($this->title_en ?: $this->title_ar);
    }
}
