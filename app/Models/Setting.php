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
        'company_tax_id' => '767-179-153',
        'company_activity_code' => '',
        'company_branch_code' => '0',
        'company_governorate' => '',
        'company_city' => '',
        'company_street' => '',
        'company_building' => '',
        'company_phone' => '',
        'eta_client_id' => '',

        // ═══ بيانات الترويسة والفوتر في المستندات المطبوعة (2026-08-09) ═══
        //
        // ⚠️ الحقول دي **قانونية** — السجل التجاري والبطاقة الضريبية
        // لازم يظهروا على أي مستند بيتسلّم لفرع. متشيلهاش من الورقة
        // حتى لو الشاشة سايباها فاضية؛ الورقة بتخفي السطر لو فاضي بس.
        //
        // ⚠️ `company_address` سطر واحد **للطباعة**، منفصل عن حقول
        // العنوان المفكوكة فوق (محافظة/مدينة/شارع/عقار). المفكوكة
        // دي بتروح لمصلحة الضرائب بصيغتها المطلوبة، والسطر ده بيتقرا
        // بعين بشرية. توحيدهم كان معناه إن أي تظبيط للشكل يكسر
        // ملف الفاتورة الإلكترونية.
        'company_cr' => '197434',            // السجل التجاري
        'company_email' => 'info@promaxfoods.com',
        'company_address' => '23 ب شارع المنصور - تقسيم اللاسلكي، المعادي، القاهرة، مصر',

        // ═══ بيانات البنك — ديمو لحد ما المالك يدخّل الحقيقية ═══
        //
        // ⚠️ **القيم دي وهمية عن قصد**، و`bankIsDemo()` تحت بتكشفها.
        // الشاشة بتحذّر والورقة بتوسم البوكس طول ما هي زي ما هي —
        // مستند بيقول «حوّل على الحساب المدرج فقط» وفيه رقم حساب
        // وهمي أخطر من مستند من غير بيانات بنك أصلاً.
        'bank_name' => 'البنك التجاري الدولي — CIB',
        'bank_branch' => 'فرع المعادي',
        'bank_account_name' => 'PROMAX Food Industries',
        'bank_account_no' => '100012345678',
        'bank_iban' => 'EG380003000100000000012345678',
        'bank_swift' => 'CIBEEGCX',

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

    /**
     * بيانات البنك لسه على قيم الديمو؟
     *
     * ⚠️ المقارنة على **رقم الحساب والآيبان** بس — دول اللي الفلوس
     * بتتحوّل عليهم. اسم البنك ممكن يكون فعلاً CIB والفرع فعلاً
     * المعادي، فمقارنتهم كانت هتخلّي التحذير يفضل طالع للأبد بعد
     * ما المالك يدخّل الحساب الصح ويتعوّد يتجاهله.
     */
    public static function bankIsDemo(?array $s = null): bool
    {
        // ⚠️ بتقبل المصفوفة من اللي بيناديها: `docHeader()` كانت
        // بتقرا الكاش مرتين، والطباعة المجمعة (100 أمر) كانت بتعمل
        // 200 قراءة ملف وdeserialize للإعدادات كلها.
        $s ??= self::all_();

        foreach (['bank_account_no', 'bank_iban'] as $key) {
            if (($s[$key] ?? '') === self::DEFAULTS[$key]) {
                return true;
            }
        }

        return false;
    }

    /** بيانات المستندات المطبوعة — كتلة واحدة بدل 10 قراءات في الورقة */
    public static function docHeader(): array
    {
        $s = self::all_();

        return [
            'name' => app()->getLocale() === 'en'
                ? ($s['company_name_en'] ?: $s['company_name'])
                : $s['company_name'],
            'tax_id' => $s['company_tax_id'] ?? '',
            'cr' => $s['company_cr'] ?? '',
            'phone' => $s['company_phone'] ?? '',
            'email' => $s['company_email'] ?? '',
            'address' => $s['company_address'] ?? '',
            'bank' => [
                'name' => $s['bank_name'] ?? '',
                'branch' => $s['bank_branch'] ?? '',
                'account_name' => $s['bank_account_name'] ?? '',
                'account_no' => $s['bank_account_no'] ?? '',
                'iban' => $s['bank_iban'] ?? '',
                'swift' => $s['bank_swift'] ?? '',
            ],
            'bank_demo' => self::bankIsDemo($s),
        ];
    }
}
