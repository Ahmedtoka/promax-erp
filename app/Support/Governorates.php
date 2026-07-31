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
    /** المفاتيح بترتيب العرض في القوايم */
    public const KEYS = [
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

    public static function has(?string $key): bool
    {
        return $key !== null && in_array($key, self::KEYS, true);
    }

    /** قاعدة التحقق — مصدر واحد بدل ما كل كنترولر يكتب الليستة */
    public static function rule(): string
    {
        return 'in:'.implode(',', self::KEYS);
    }

    public static function label(?string $key): string
    {
        return self::has($key) ? __('geo.gov.'.$key) : '—';
    }

    /**
     * مفتاح ⇒ اسم مترجم، للقوايم المنسدلة.
     *
     * ⚠️ الترتيب مابيتعملوش sort هنا — `KEYS` متظبطة جغرافياً بالفعل،
     * و`asort` على النص المترجم بيدي ترتيب مختلف في كل لغة.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::KEYS as $key) {
            $out[$key] = __('geo.gov.'.$key);
        }

        return $out;
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
                'امبابة', 'بولاق', 'الوراق', 'mohandessin', 'dokki', 'haram', 'faisal', 'october', 'zayed', 'agouza'],
            'qalyubia' => ['شبرا الخيمة', 'بنها', 'القناطر', 'قليوب', 'banha', 'qalyub'],
            'cairo' => ['مصر الجديدة', 'مدينة نصر', 'شبرا', 'وسط البلد', 'الزمالك', 'التجمع', 'الرحاب',
                'مدينتي', 'الشروق', 'المستقبل', 'العبور', 'المقطم', 'المعادي', 'حلوان', 'عين شمس',
                'المرج', 'السلام', 'الزيتون', 'حدائق القبة', 'heliopolis', 'nasr city', 'shubra',
                'downtown', 'zamalek', 'settlement', 'rehab', 'madinaty', 'shorouk', 'mostakbal',
                'obour', 'mokattam', 'maadi', 'helwan'],
            'alexandria' => ['إسكندرية', 'اسكندرية', 'العجمي', 'المنتزه', 'alexandria', 'agami'],
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
            'matrouh' => ['مرسى مطروح', 'العلمين', 'الساحل الشمالي', 'marsa matrouh', 'alamein', 'north coast'],
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
