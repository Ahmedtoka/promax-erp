<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\App;

/**
 * اسم ثنائي اللغة مع fallback.
 * Bilingual name with fallback.
 *
 * القاعدة: لو اللغة إنجليزي والعمود name_en مليان → نستخدمه.
 * غير كده نرجّع الاسم العربي — فمفيش خانة فاضية أبداً في الشاشة.
 *
 * ⚠️ ممنوع تطبع $model->name مباشرة في أي فيو — استخدم displayName().
 */
trait HasBilingualName
{
    public function displayName(): string
    {
        return $this->localized('name');
    }

    /**
     * نسخة عامة لأي عمود ليه توأم _en (زي unit / unit_en).
     */
    public function localized(string $column): string
    {
        $value = (string) ($this->{$column} ?? '');

        if (App::getLocale() !== 'en') {
            return $value;
        }

        $english = trim((string) ($this->{$column.'_en'} ?? ''));

        return $english !== '' ? $english : $value;
    }

    /**
     * للبحث: بيدوّر في الاسمين مع بعض.
     * Search across both name columns.
     */
    public function scopeSearchName($query, ?string $term)
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('name_en', 'like', "%{$term}%");
        });
    }
}
