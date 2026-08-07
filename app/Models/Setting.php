<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * إعدادات الشركة — مفتاح/قيمة.
 *
 * ⚠️ البيانات دي بتتقرا في كل فاتورة بتتطبع وفي كل تصدير ضريبي، فلو
 * كل قراءة راحت للداتابيز هنعمل كويري لكل حقل. بنقرا الجدول كله مرة
 * واحدة ونحطه في الكاش، والكاش بيتمسح عند أي حفظ.
 *
 * ⚠️ متسمّيش أي ميثود هنا `get` أو `set` بصيغة instance — دول أسماء
 * موجودة في Eloquent. الاتنين هنا **static** فمفيش تعارض.
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    private const CACHE_KEY = 'promax.settings';

    /** القيم الافتراضية — الشاشة بتبدأ بيها لحد ما اليوزر يعدّل */
    public const DEFAULTS = [
        'tax_enabled' => '0',
        'tax_rate' => '14',            // نسبة مئوية زي ما اليوزر بيكتبها
        'company_name' => 'PROMAX Food Industries',
        'company_name_en' => 'PROMAX Food Industries',
        'company_tax_id' => '',
        'company_activity_code' => '',
        'company_branch_code' => '0',
        'company_governorate' => '',
        'company_city' => '',
        'company_street' => '',
        'company_building' => '',
        'company_phone' => '',
        'eta_client_id' => '',

        // ═══ الحوافز (2026-08-06) — بتتعدل من شاشة إعدادات الحوافز ═══
        'point_value' => '5',            // قيمة النقطة بالجنيه
        'pts_per_visit' => '1',          // نقطة لكل زيارة مكتملة
        'pts_per_new_client' => '10',    // نقاط لكل عميل جديد اتفعل
        'pts_per_100_pieces' => '1',     // نقطة لكل 100 قطعة مبيعات
        'lead_alert_km' => '1',          // نطاق أليرت العميل المحتمل بالكيلومتر

        // ═══ إصدار الأبلكيشن (2026-08-07) — من شاشة «إصدار الأبلكيشن» ═══
        //
        // ⚠️ **`app_min_version` هو اللي بيجبر التحديث، مش `app_version`.**
        // الفرق بينهم مقصود: `app_version` = آخر إصدار موجود (بيتعرض
        // كـ«فيه تحديث»)، و`app_min_version` = أقل إصدار مسموح يشتغل.
        // لو حطيتهم بنفس الرقم، كل مندوب لسه ما حدّثش هيتقفل عليه
        // الأبلكيشن فوراً — استخدم ده بس لما التحديث **إجباري فعلاً**
        // (تغيير في الـAPI بيكسّر القديم).
        'app_version' => '1.0.0',
        'app_min_version' => '1.0.0',
        'app_apk_url' => '',             // فاضي = الأبلكيشن مش هيعرض زرار تنزيل
        'app_update_note' => '',
    ];

    /** كل الإعدادات كمصفوفة — الافتراضي متسّرب تحت المحفوظ */
    public static function all_(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $saved = static::query()->pluck('value', 'key')->all();

            return array_merge(self::DEFAULTS, $saved);
        });
    }

    public static function read(string $key, ?string $fallback = null): ?string
    {
        $v = self::all_()[$key] ?? $fallback;

        return $v === '' ? $fallback : $v;
    }

    public static function write(string $key, ?string $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget(self::CACHE_KEY);
    }

    /** حفظ مجموعة مرة واحدة — مسح كاش واحد بدل مسح لكل مفتاح */
    public static function writeMany(array $pairs): void
    {
        foreach ($pairs as $k => $v) {
            static::updateOrCreate(['key' => $k], ['value' => $v]);
        }

        Cache::forget(self::CACHE_KEY);
    }

    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
