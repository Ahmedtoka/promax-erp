<?php

namespace App\Support;

use App\Models\Channel;

/**
 * ═══════════════════════════════════════════════════════════════
 * تصنيف المكان المستورد وترتيب أولويته
 * ═══════════════════════════════════════════════════════════════
 *
 * الرفعة الواحدة من دليل خارجي بتجيب آلاف الأماكن. المندوب بيزور
 * عشرين في اليوم. يعني **الترتيب هو المنتج، مش القايمة** — ولو
 * القايمة نزلت من غير ترتيب، اللي هيحصل إن المندوب هيمشي بالترتيب
 * الأبجدي ويقابل محلات مالهاش لازمة أول أسبوع ويسيب الشغل كله.
 *
 * الترتيب المعتمد للكاش فان (قرار المالك 2026-08-13):
 *
 *     الجيم  >  الكافيه الكبير  >  السوبرماركت الكبير  >  الباقي
 *
 * ═══ إزاي بنعرف «كبير»؟ ═══
 *
 * مفيش حقل بيقول حجم المحل. أقرب بديل متاح هو **عدد الريفيوهات**:
 * ده بروكسي لعدد الزباين مش لجودة المكان. كافيه بـ٤.٢ و٣٠٠٠ ريفيو
 * أكبر بكتير من كافيه بـ٤.٩ و١٢ ريفيو، وده اللي يهمنا.
 *
 * ⚠️ **التقييم وحده ممنوع يترتّب بيه.** الأماكن الصغيرة جداً بتاخد
 * ٥.٠٠ من ٣ ريفيوهات، فترتيب بالتقييم بيطلّع أضعف المحلات في أول
 * القايمة. التقييم هنا بيعدّل السكور شوية بس، والوزن الأساسي
 * للنشاط وعدد الريفيوهات.
 *
 * ⚠️ **السكور ترتيب مش فلوس.** ماينفعش يتحط في `expected_monthly`
 * ولا يتجمع في تقرير — رقم مقارنة بين ليدين، معندوش وحدة.
 */
final class LeadScore
{
    /**
     * الحد اللي فوقه الليد «فرصة قوية».
     *
     * ⚠️ **رقم واحد للـKPI وللون الشارة.** لما كان الـKPI بيعدّ من ٧٠
     * والشارة بتخضّر من ٧٥، الليدز ٧٠-٧٤ كانت بتتعدّ في «فرص قوية»
     * وشارتها زرقا في الجدول — واليوزر بيعدّ الصفوف الخضرا ويقول
     * الرقم غلط.
     */
    public const STRONG = 70;

    /**
     * وزن النشاط ٠..٦٠ + القناة اللي بيروح لها.
     *
     * المفتاح كلمة بندوّر عليها **جوه** تصنيف جوجل أو اسم المكان،
     * بعد التطبيع. الترتيب في المصفوفة مهم: أول تطابق بيكسب، فالأخص
     * فوق الأعم («gym» قبل «club»، «hyper» قبل «market»).
     *
     * @var array<string, array{0: int, 1: string, 2: ?string}>
     */
    private const RULES = [
        // ═══ الجيمات — أقوى نقطة بيع للكاش فان ═══
        'gym' => [60, Channel::CASH_VAN, null],
        'fitness' => [60, Channel::CASH_VAN, null],
        'جيم' => [60, Channel::CASH_VAN, null],
        'crossfit' => [58, Channel::CASH_VAN, null],
        'health club' => [55, Channel::CASH_VAN, null],
        'نادي صحي' => [55, Channel::CASH_VAN, null],
        'sports club' => [45, Channel::CASH_VAN, null],
        'نادي رياضي' => [45, Channel::CASH_VAN, null],
        'yoga' => [35, Channel::CASH_VAN, null],
        'personal trainer' => [30, Channel::CASH_VAN, null],

        // ═══ الكافيهات ═══
        'coffee shop' => [50, Channel::CASH_VAN, null],
        'coffee' => [48, Channel::CASH_VAN, null],
        'cafe' => [48, Channel::CASH_VAN, null],
        'café' => [48, Channel::CASH_VAN, null],
        'كافيه' => [48, Channel::CASH_VAN, null],
        'كافيتيريا' => [42, Channel::CASH_VAN, null],
        'espresso' => [45, Channel::CASH_VAN, null],
        'juice' => [40, Channel::CASH_VAN, null],
        'عصير' => [40, Channel::CASH_VAN, null],
        'bakery' => [35, Channel::CASH_VAN, null],
        'مخبز' => [35, Channel::CASH_VAN, null],
        'restaurant' => [30, Channel::CASH_VAN, null],
        'مطعم' => [30, Channel::CASH_VAN, null],

        // ═══ السلاسل — كي أكاونت، مش كاش فان ═══
        // ⚠️ دي فوق «supermarket» عشان «Carrefour Hypermarket» ماينزلش
        // كاش فان. السلسلة بتتفتح بعقد مركزي مش بزيارة مندوب.
        'hypermarket' => [55, Channel::KEY_ACCOUNT, 'chain'],
        'هايبر' => [55, Channel::KEY_ACCOUNT, 'chain'],
        'department store' => [45, Channel::KEY_ACCOUNT, 'chain'],

        // ═══ الكونفينيانس ومحطات البنزين ═══
        'convenience' => [45, Channel::KEY_ACCOUNT, 'convenience'],
        'gas station' => [40, Channel::KEY_ACCOUNT, 'convenience'],
        'petrol' => [40, Channel::KEY_ACCOUNT, 'convenience'],
        'محطة بنزين' => [40, Channel::KEY_ACCOUNT, 'convenience'],

        // ═══ أونلاين ═══
        // ⚠️ **فوق `grocery` عن قصد.** `str_contains` بتلاقي «grocery»
        // جوه «online grocery»، فلو القاعدة العامة سبقت، القناتين
        // دول كانوا كود ميت وكل أونلاين جروسري كان بيروح كاش فان.
        'online grocery' => [40, Channel::ONLINE, null],
        'grocery delivery' => [40, Channel::ONLINE, null],

        // ═══ السوبرماركت المستقل — كاش فان ═══
        'supermarket' => [45, Channel::CASH_VAN, null],
        'سوبر ماركت' => [45, Channel::CASH_VAN, null],
        'سوبرماركت' => [45, Channel::CASH_VAN, null],
        'grocery' => [38, Channel::CASH_VAN, null],
        'بقاله' => [30, Channel::CASH_VAN, null],
        'مينى ماركت' => [35, Channel::CASH_VAN, null],
        'mini market' => [35, Channel::CASH_VAN, null],

        // ═══ الجملة ═══
        'wholesale' => [40, Channel::WHOLESALE, null],
        'جمله' => [40, Channel::WHOLESALE, null],
        'distributor' => [38, Channel::WHOLESALE, null],
        'موزع' => [38, Channel::WHOLESALE, null],
        'cash and carry' => [40, Channel::WHOLESALE, null],

        // ═══ أماكن بتبيع أكل صحي — مناسبة بس مش أولوية ═══
        'health food' => [42, Channel::CASH_VAN, null],
        'nutrition' => [40, Channel::CASH_VAN, null],
        'مكملات' => [40, Channel::CASH_VAN, null],
        'supplement' => [40, Channel::CASH_VAN, null],
        'pharmacy' => [25, Channel::CASH_VAN, null],
        'صيدليه' => [25, Channel::CASH_VAN, null],
    ];

    /**
     * الأنشطة اللي **ممنوع** تدخل كليد أصلاً.
     *
     * ⚠️ رفعة «كافيهات القاهرة» من جوجل بترجّع معاها شيش وقهاوي
     * بلدي وأماكن مالهاش أي علاقة. سيبها تدخل معناه إن المندوب هيقفل
     * الشاشة بعد أول عشرين ليد فاضي.
     */
    private const REJECT = [
        'hookah', 'shisha', 'شيشه', 'bar', 'night club', 'hotel', 'mosque', 'church',
        'school', 'مدرسه', 'hospital', 'مستشفى', 'مستشفي', 'bank', 'بنك',
        'atm', 'car repair', 'workshop', 'ورشه', 'real estate', 'عقارات',
        'clothing', 'ملابس', 'barber', 'حلاق', 'مكتبه', 'book store',
    ];

    /** النشاط ده مرفوض؟ */
    public static function rejected(?string $category, ?string $name = null): bool
    {
        $hay = self::hay($category, $name);

        if ($hay === '') {
            return false;
        }

        // ⚠️ الرفض بيتفحص **بعد** القبول في `match()` — محل اسمه
        // «Gym Bar Cafe» بيتقبل كجيم. اللي بنرفضه هنا هو اللي مالوش
        // أي تطابق إيجابي أصلاً، والفحص ده بيتنده من المستورد بعدها.
        //
        // ⚠️ **بكلمة كاملة مش `str_contains`.** «bar» جوه «Barista»
        // و«atm» جوه «Fatma» كانوا بيرموا كافيهات سليمة بره القايمة.
        $words = ' '.$hay.' ';

        foreach (self::REJECT as $bad) {
            if (str_contains($words, ' '.self::key($bad).' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * أول قاعدة بتطابق النشاط.
     *
     * @return array{weight: int, channel: string, sub: ?string}|null
     */
    public static function match(?string $category, ?string $name = null): ?array
    {
        $hay = self::hay($category, $name);

        if ($hay === '') {
            return null;
        }

        foreach (self::RULES as $needle => [$weight, $channel, $sub]) {
            if (str_contains($hay, self::key($needle))) {
                return ['weight' => $weight, 'channel' => $channel, 'sub' => $sub];
            }
        }

        return null;
    }

    /** كود القناة المقترح — null يعني مش عارفين، والمدير بيقرر */
    public static function channel(?string $category, ?string $name = null): ?string
    {
        return self::match($category, $name)['channel'] ?? null;
    }

    /** القسم الفرعي المقترح — للكي أكاونت وبس */
    public static function subChannel(?string $category, ?string $name = null): ?string
    {
        return self::match($category, $name)['sub'] ?? null;
    }

    /**
     * السكور ٠..١٠٠ = وزن النشاط (٠..٦٠) + الحجم (٠..٣٠) + التقييم (٠..١٠).
     *
     * ⚠️ **الحجم لوغاريتمي.** الفرق بين ١٠ و١٠٠ ريفيو حقيقي، والفرق
     * بين ٣٠٠٠ و٤٠٠٠ مالوش معنى تشغيلي. سلّم خطّي كان بيخلّي فرع
     * كارفور واحد يدوس على كل الجيمات في القايمة.
     */
    public static function compute(?string $category, ?string $name, ?float $rating, ?int $reviews): int
    {
        $weight = self::match($category, $name)['weight'] ?? 20;

        // ═══ الحجم — ٠..٣٠ ═══
        // 0 ريفيو = 0 · 10 = ~10 · 100 = ~20 · 1000+ = 30
        $r = max(0, (int) $reviews);
        $size = $r > 0 ? (int) round(min(30, 10 * log10($r + 1))) : 0;

        // ═══ التقييم — ٠..١٠، وبس لو فيه ريفيوهات تكفي ═══
        // ⚠️ أقل من ١٠ ريفيوهات = التقييم ضوضاء. مكان بـ٥.٠٠ من
        // ريفيو واحد ماينفعش ياخد نفس نقط مكان بـ٤.٦ من ٨٠٠.
        $stars = 0;

        if ($rating !== null && $r >= 10) {
            // 3.0 فأقل = 0 · 5.0 = 10
            $stars = (int) round(max(0, min(10, ($rating - 3.0) * 5)));
        }

        return (int) max(0, min(100, $weight + $size + $stars));
    }

    /** لون الشارة حسب قوة الليد */
    public static function badgeClass(int $score): string
    {
        return match (true) {
            $score >= self::STRONG => 'b-green',
            $score >= 55 => 'b-blue',
            $score >= 35 => 'b-orange',
            default => 'b-gray',
        };
    }

    /**
     * تطبيع نص المقارنة.
     *
     * ⚠️ نفس تطبيع `Dupes::nameKey` **مش** مستخدم هنا عن قصد — ده
     * بيشيل «ال» و«فرع» عشان مطابقة الأسماء، واللي إحنا محتاجينه هنا
     * تطبيع أخف بيسيب الكلمات زي ما هي عشان `str_contains` تشتغل.
     */
    private static function key(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = str_replace(['أ', 'إ', 'آ'], 'ا', $s);
        $s = str_replace('ة', 'ه', $s);
        $s = str_replace('ى', 'ي', $s);
        $s = preg_replace('/[\x{064B}-\x{0652}]/u', '', $s) ?? $s;
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

        return trim($s);
    }

    /** نص البحث: التصنيف + الاسم — الاتنين لأن جوجل بيسيب التصنيف فاضي كتير */
    private static function hay(?string $category, ?string $name): string
    {
        return self::key(trim((string) $category).' '.trim((string) $name));
    }
}
