<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * محافظة — الشاشات بتتعامل مع `key` الثابت، والأسماء (عربي/إنجليزي)
 * هي اللي بتتعدل. العملاء والمناطق متخزن عليهم المفتاح، فتغيير الاسم
 * بيظهر في كل السيستم من غير ما أي عميل يتلمس.
 *
 * ⚠️ **المفتاح مابيتغيرش بعد الإنشاء** — تغييره بيسيب العملاء
 * القدام على مفتاح ميت. شاشة التعديل بتعدّل الأسماء بس.
 */
class Governorate extends Model
{
    protected $fillable = [
        'key', 'name', 'name_en', 'iso_code', 'capital', 'capital_en',
        'region', 'region_en', 'lat', 'lng', 'sort', 'active',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
