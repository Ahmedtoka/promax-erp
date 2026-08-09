<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تصحيح عنوان وتليفون الشركة (قرار المالك ٩ أغسطس ٢٠٢٦ مساءً).
 *
 * ⚠️ مايجريشن 000100 زرعت العنوان القديم بـ`insertOrIgnore` —
 * فتعديل الـDEFAULTS في الموديل مش كفاية للداتابيز اللي المايجريشن
 * القديمة اشتغلت عليها فعلاً. بنحدّث **بس لو القيمة لسه القديمة** —
 * لو المالك عدّلها من الشاشة، إيده هي اللي تكسب.
 */
return new class extends Migration
{
    private const UPDATES = [
        'company_address' => [
            'old' => '23 ب شارع المنصور - تقسيم اللاسلكي، المعادي، القاهرة، مصر',
            'new' => '٢ مشروع ١٦ عمارة - تقسيم اللاسلكي - المعادي',
        ],
        'company_phone' => [
            'old' => '01008820066',
            'new' => '+201044242200',
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        foreach (self::UPDATES as $key => $v) {
            DB::table('settings')
                ->where('key', $key)
                ->where('value', $v['old'])
                ->update(['value' => $v['new'], 'updated_at' => now()]);
        }

        // الكاش شايل القيم القديمة
        \Illuminate\Support\Facades\Cache::forget('promax.settings');
    }

    public function down(): void
    {
        // تصحيح داتا — مالوش رجوع
    }
};
