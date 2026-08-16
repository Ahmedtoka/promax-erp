<?php

namespace App\Support;

/**
 * ═══════════════════════════════════════════════════════════════
 * محافظات مصر الـ27
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **مفاتيح ثابتة، والأسماء في ملفات اللغة.** لو خزّنا الاسم نفسه
 * في الداتابيز، العميل اللي اتعمل من الشاشة الإنجليزية بيتخزن
 * "Cairo" واللي من العربية "القاهرة" — وأول تقرير بالمحافظة بيدي
 * صفّين لنفس المكان.
 *
 * ⚠️ الترتيب هنا **جغرافي مش أبجدي**: القاهرة الكبرى الأول لأنها
 * أغلب العملاء، بعدين الدلتا، بعدين القناة، بعدين الصعيد والحدود.
 * الترتيب الأبجدي بيحط "أسوان" فوق و"القاهرة" في النص، والمستخدم
 * بيدوّر في كل تعريف عميل.
 */
final class Governorates
{
    /**
     * الـ27 الأصلية — **زرع واحتياطي بس** (2026-08-05). المصدر بقى
     * جدول `governorates`: أسماء قابلة للتعديل ومحافظات جديدة تتضاف
     * من شاشة «المناطق والمحافظات». الترتيب جغرافي مش أبجدي.
     */
    public const BUILTIN = [
        // القاهرة الكبرى
        'cairo', 'giza', 'qalyubia',
        // الإسكندرية والساحل
        'alexandria', 'beheira', 'matrouh',
        // الدلتا
        'kafr_el_sheikh', 'dakahlia', 'damietta', 'sharqia',
        'gharbia', 'monufia',
        // القناة وسيناء
        'ismailia', 'port_said', 'suez', 'north_sinai', 'south_sinai',
        // الصعيد
        'beni_suef', 'faiyum', 'minya', 'asyut', 'sohag',
        'qena', 'luxor', 'aswan',
        // الحدود
        'red_sea', 'new_valley',
    ];

    /**
     * الصفوف من الداتابيز — ميمو للريكوست + احتياطي للثوابت لو
     * الجدول لسه ماتعملش (نص migrate مثلاً) أو حصل خطأ اتصال.
     *
     * @return array<string, array{name: string, name_en: ?string}>
     */
    private static function rows(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('governorates')) {
                $rows = \App\Models\Governorate::where('active', true)
                    ->orderBy('sort')->orderBy('id')
                    ->get(['key', 'name', 'name_en']);

                if ($rows->isNotEmpty()) {
                    return self::$cache = $rows
                        ->mapWithKeys(fn ($g) => [$g->key => ['name' => $g->name, 'name_en' => $g->name_en]])
                        ->all();
                }
            }
        } catch (\Throwable) {
            // من غير داتابيز (كاش الكونفج مثلاً) — الاحتياطي تحت
        }

        $out = [];
        foreach (self::BUILTIN as $key) {
            $out[$key] = [
                'name' => trans('geo.gov.'.$key, [], 'ar'),
                'name_en' => trans('geo.gov.'.$key, [], 'en'),
            ];
        }

        return self::$cache = $out;
    }

    /** @var array<string, array{name: string, name_en: ?string}>|null */
    private static ?array $cache = null;

    /** بعد إضافة/تعديل محافظة — الكاش بقى قديم */
    public static function flush(): void
    {
        self::$cache = null;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::rows());
    }

    public static function has(?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::rows());
    }

    /** قاعدة التحقق — مصدر واحد بدل ما كل كنترولر يكتب الليستة */
    public static function rule(): string
    {
        return 'in:'.implode(',', self::keys());
    }

    public static function label(?string $key): string
    {
        $row = $key !== null ? (self::rows()[$key] ?? null) : null;

        if ($row === null) {
            return '—';
        }

        return app()->getLocale() === 'ar'
            ? $row['name']
            : ($row['name_en'] ?: $row['name']);
    }

    /**
     * مفتاح ⇒ اسم معروض، للقوايم المنسدلة — بترتيب `sort` من الجدول.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::keys() as $key) {
            $out[$key] = self::label($key);
        }

        return $out;
    }

    /**
     * مطابقة قيمة من شيت (مفتاح أو اسم عربي أو إنجليزي) — للمستوردات.
     */
    public static function match(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        foreach (self::rows() as $key => $row) {
            if (strcasecmp($value, $key) === 0
                || $value === $row['name']
                || ($row['name_en'] !== null && strcasecmp($value, $row['name_en']) === 0)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * تخمين المحافظة من اسم منطقة — بيستخدم مرة واحدة وقت المايجريشن
     * أو السيدر عشان المناطق الموجودة ماتفضلش من غير محافظة.
     *
     * ⚠️ بيرجّع `null` لما مايعرفش. التخمين الغلط أسوأ من الفاضي:
     * الفاضي بيبان في الشاشة ويتظبط، والغلط بيعدّي في التقرير.
     */
    public static function guessFromZone(?string $arabic, ?string $english = null): ?string
    {
        $haystack = mb_strtolower(trim(($arabic ?? '').' '.($english ?? '')));

        if ($haystack === '') {
            return null;
        }

        $hints = [
            'giza' => ['مهندسين', 'دقي', 'هرم', 'فيصل', 'أكتوبر', 'اكتوبر', 'زايد', 'العجوزة', 'إمبابة',
                'امبابة', 'بولاق', 'الوراق', 'mohandessin', 'dokki', 'haram', 'faisal', 'october', 'zayed', 'agouza',
                // إضافة ١٥/٨ — أسماء مناطق حقيقية كانت بتقع في «بلا محافظة»
                'ميدان سفنكس', 'سفنكس', 'محور التعمير', 'ميت عقبة', 'الدقي',
                'sphinx', 'mit okba'],
            'qalyubia' => ['شبرا الخيمة', 'بنها', 'القناطر', 'قليوب', 'banha', 'qalyub'],
            'cairo' => ['مصر الجديدة', 'مدينة نصر', 'شبرا', 'وسط البلد', 'الزمالك', 'التجمع', 'الرحاب',
                'مدينتي', 'الشروق', 'المستقبل', 'العبور', 'المقطم', 'المعادي', 'حلوان', 'عين شمس',
                'المرج', 'السلام', 'الزيتون', 'حدائق القبة', 'heliopolis', 'nasr city', 'shubra',
                'downtown', 'zamalek', 'settlement', 'rehab', 'madinaty', 'shorouk', 'mostakbal',
                'obour', 'mokattam', 'maadi', 'helwan',
                // إضافة ١٥/٨ — أسماء مناطق حقيقية كانت بتقع في «بلا محافظة»
                'سيتي ستارز', 'البساتين', 'الظاهر', 'غمرة', 'حلمية الزيتون',
                'city stars', 'basateen', 'ghamra'],
            'alexandria' => ['إسكندرية', 'اسكندرية', 'العجمي', 'المنتزه', 'alexandria', 'agami',
                'سموحة', 'ستانلي', 'smouha', 'stanley'],
            'sharqia' => ['العاشر من رمضان', 'الزقازيق', 'بلبيس', 'ramadan', 'zagazig'],
            'ismailia' => ['الإسماعيلية', 'الاسماعيلية', 'ismailia'],
            'port_said' => ['بورسعيد', 'port said', 'portsaid'],
            'suez' => ['السويس', 'suez'],
            'dakahlia' => ['المنصورة', 'ميت غمر', 'mansoura'],
            'gharbia' => ['طنطا', 'المحلة', 'tanta', 'mahalla'],
            'monufia' => ['شبين', 'منوف', 'shibin'],
            'beheira' => ['دمنهور', 'كفر الدوار', 'damanhour'],
            'damietta' => ['دمياط', 'damietta'],
            'kafr_el_sheikh' => ['كفر الشيخ', 'دسوق', 'kafr el'],
            // ⚠️ القرى السياحية بتتكتب باسمها من غير ذكر المحافظة
            // (إضافة ١٥/٨) — «مراسي» و«سيدي عبد الرحمن» كانوا بيقعوا
            // في «بلا محافظة».
            'matrouh' => ['مرسى مطروح', 'العلمين', 'الساحل الشمالي', 'مراسي', 'سيدي عبد الرحمن',
                'marsa matrouh', 'alamein', 'north coast', 'marassi', 'sidi abdel rahman'],
            'south_sinai' => ['شرم', 'دهب', 'طور سيناء', 'sharm', 'dahab'],
            'north_sinai' => ['العريش', 'arish'],
            'red_sea' => ['الغردقة', 'الجونة', 'سفاجا', 'مرسى علم', 'hurghada', 'gouna', 'safaga'],
            'faiyum' => ['الفيوم', 'faiyum', 'fayoum'],
            'beni_suef' => ['بني سويف', 'beni suef'],
            'minya' => ['المنيا', 'minya'],
            'asyut' => ['أسيوط', 'اسيوط', 'asyut', 'assiut'],
            'sohag' => ['سوهاج', 'sohag'],
            'qena' => ['قنا', 'qena'],
            'luxor' => ['الأقصر', 'الاقصر', 'luxor'],
            'aswan' => ['أسوان', 'اسوان', 'aswan'],
            'new_valley' => ['الوادي الجديد', 'الخارجة', 'new valley', 'kharga'],
        ];

        foreach ($hints as $key => $words) {
            foreach ($words as $word) {
                if (str_contains($haystack, mb_strtolower($word))) {
                    return $key;
                }
            }
        }

        return null;
    }
}
