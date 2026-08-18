<?php

namespace App\Support;

use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * كشف تكرار العملاء — مصدر واحد للمنطق
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ المستورد وشاشة الإنشاء اليدوي **لازم يحكموا بنفس الطريقة** —
 * لما كان لكل مسار منطقه، العميل اللي الاستيراد بيرفضه كانت الشاشة
 * بتقبله والعكس. أي تعديل على التطبيع هنا بيسري على الاتنين تلقائياً.
 *
 * ═══════════════════════════════════════════════════════════════
 * قواعد المطابقة (١٥ أغسطس ٢٠٢٦) — مكتوبة ومضبوطة من هنا بس
 * ═══════════════════════════════════════════════════════════════
 *
 * | # | القاعدة | الثقة |
 * |---|---|---|
 * | 1 | نفس التليفون بعد التطبيع (محلي أو بكود الدولة) | مؤكد |
 * | 2 | نفس الاسم العربي المطبّع (همزات/تاء مربوطة/«فرع»/«ال»/أرقام) | مؤكد |
 * | 3 | نفس الاسم الإنجليزي المطبّع | مؤكد |
 * | 4 | اسم قريب جداً **جوّه نفس السلسلة** | مؤكد |
 * | 5 | اسم قريب جداً **جوّه نفس الزون** | محتمل |
 *
 * ⚠️ **مفيش يونيك على التليفون في الداتابيز عن قصد** — سلسلة زي
 * Circle K عندها 40 فرع بنفس رقم الإدارة، فيونيك هناك كان هيمنع
 * تعريف الفروع أصلاً. الحارس منطقي (هنا) مش سكيما، والسكيما بتساعده
 * بعمودين مفهرسين (`dupe_key` / `dupe_phone`) عشان اللقطة تفضل سريعة
 * على 10 آلاف عميل.
 *
 * ⚠️ **الحارس بيدوّر على كل العملاء مش على عملاء اللي بيشوف بس.**
 * لو سكوّبناه بـ`visibleTo`، مدير «س» كان هيقدر يعمل نسخة تانية من
 * عميل مدير «ص» — وده بالظبط التكرار اللي بنمنعه. اللي بيتسكّب هو
 * **العرض**: الصف اللي الفاعل مش مسموح له يشوفه بيتعرض بكوده وبلا
 * اسم ولا لينك (`visible = false`).
 */
final class Dupes
{
    /** أقل نسبة تشابه (٪) نعتبر بعدها الاسمين «قريبين» */
    public const NEAR_PCT = 86.0;

    /** الأسماء الأقصر من كده مابتتقارنش تقريبياً — «مول» و«محل» 75% */
    public const NEAR_MIN_LEN = 6;

    /** أقصى عدد نتايج بترجع للواجهة */
    public const MAX_MATCHES = 8;

    /** سقف الصفوف اللي بنقارنها تقريبياً — حارس أمان على قاعدة كبيرة */
    public const NEAR_POOL_CAP = 800;

    public const SURE = 'sure';
    public const LIKELY = 'likely';

    /**
     * مفتاح مطابقة للاسم: صغير، همزات وتاء مربوطة موحدة، من غير
     * «ال» و«فرع»، والأرقام العربي بقت إنجليزي — «المعادى ١» و
     * «فرع المعادي 1» نفس العميل.
     */
    public static function nameKey(?string $name): string
    {
        $s = mb_strtolower(trim((string) $name), 'UTF-8');
        $s = str_replace(['أ', 'إ', 'آ'], 'ا', $s);
        $s = str_replace('ة', 'ه', $s);
        $s = str_replace('ى', 'ي', $s);
        $s = preg_replace('/[\x{064B}-\x{0652}]/u', '', $s) ?? $s;   // تشكيل
        $s = preg_replace('/[()\-_.،,\/]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        $s = preg_replace('/^(فرع\s+)/u', '', trim($s)) ?? $s;
        $s = preg_replace('/(^|\s)ال/u', '$1', $s) ?? $s;
        $s = strtr($s, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);

        return trim($s);
    }

    /** تليفون للمقارنة: أرقام بس، وكود الدولة 20 بيتحول لصفر محلي */
    public static function phoneKey(?string $phone): string
    {
        $p = preg_replace('/\D+/', '', (string) $phone) ?? '';

        return preg_replace('/^20(1\d{9})$/', '0$1', $p) ?? $p;
    }

    /**
     * القاعدة 2/3 — نفس الاسم بعد التطبيع.
     *
     * ⚠️ المفتاح الفاضي مابيطابقش حاجة. من غير الشرط ده، عميلين
     * بأسماء فاضية كانوا بيبقوا «نفس العميل».
     */
    public static function sameName(?string $a, ?string $b): bool
    {
        $ka = self::nameKey($a);

        return $ka !== '' && $ka === self::nameKey($b);
    }

    /** القاعدة 1 — نفس التليفون بعد التطبيع */
    public static function samePhone(?string $a, ?string $b): bool
    {
        $ka = self::phoneKey($a);

        return $ka !== '' && $ka === self::phoneKey($b);
    }

    /**
     * القاعدة 4/5 — نسبة تشابه الاسمين المطبّعين، أو `null` لو
     * أقصر من الحد أو أقل من العتبة.
     *
     * ⚠️ `similar_text` بتشتغل على البايتات. بنقارن المفتاح المطبّع
     * بعد ما التشكيل والهمزات اتوحّدوا، فالفرق الباقي حروف حقيقية.
     */
    public static function nearName(?string $a, ?string $b): ?float
    {
        $ka = self::nameKey($a);
        $kb = self::nameKey($b);

        if ($ka === '' || $kb === '' || $ka === $kb) {
            return null;
        }

        if (mb_strlen($ka, 'UTF-8') < self::NEAR_MIN_LEN
            || mb_strlen($kb, 'UTF-8') < self::NEAR_MIN_LEN) {
            return null;
        }

        similar_text($ka, $kb, $pct);

        return $pct >= self::NEAR_PCT ? round((float) $pct, 1) : null;
    }

    /**
     * فيه عميل موجود بنفس الاسم (مطبّع) أو نفس التليفون؟
     *
     * ⚠️ **الحارس الصارم بتاع الاستيراد** — نتيجة واحدة، بلا سكوب،
     * بلا تشابه تقريبي. `matches()` تحت هي النسخة الغنية اللي
     * الشاشات بتستخدمها. الاتنين بينادوا نفس `sameName`/`samePhone`
     * فمستحيل يختلفوا في الحكم على المطابقة القاطعة.
     *
     * @return array{client: Client, by: 'name'|'phone'}|null
     */
    public static function existing(?string $name, ?string $phone, ?int $ignoreId = null): ?array
    {
        // العملاء مئات مش ملايين — المقارنة المطبّعة في PHP أدق من LIKE
        foreach (Client::query()->get(['id', 'code', 'name', 'phone']) as $c) {
            if ($c->id === $ignoreId) {
                continue;
            }

            if (self::sameName($name, $c->name)) {
                return ['client' => $c, 'by' => 'name'];
            }

            if (self::samePhone($phone, $c->phone)) {
                return ['client' => $c, 'by' => 'phone'];
            }
        }

        return null;
    }

    /**
     * كل العملاء اللي ممكن يكونوا نفس ده — للواجهات.
     *
     * @param  array{name?:?string,name_en?:?string,phone?:?string,zone_id?:mixed,group_id?:mixed}  $in
     * @return list<array<string, mixed>>
     */
    public static function matches(array $in, ?int $ignoreId = null, ?User $viewer = null): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        $nameEn = trim((string) ($in['name_en'] ?? ''));
        $phone = trim((string) ($in['phone'] ?? ''));
        $zoneId = ($in['zone_id'] ?? null) ? (int) $in['zone_id'] : null;
        $groupId = ($in['group_id'] ?? null) ? (int) $in['group_id'] : null;

        if (self::nameKey($name) === '' && self::nameKey($nameEn) === '' && self::phoneKey($phone) === '') {
            return [];
        }

        $hits = [];

        foreach (self::candidates($name, $nameEn, $phone, $zoneId, $groupId) as $c) {
            if ($ignoreId !== null && (int) $c->id === (int) $ignoreId) {
                continue;
            }

            [$by, $conf, $score] = self::judge($c, $name, $nameEn, $phone, $zoneId, $groupId);

            if ($by === null) {
                continue;
            }

            $hits[] = self::row($c, $by, $conf, $score, $viewer);
        }

        // المؤكد قبل المحتمل، وجوه كل مجموعة الأعلى تشابهاً
        usort($hits, fn ($a, $b) => [$b['confidence'] === self::SURE, $b['score']]
            <=> [$a['confidence'] === self::SURE, $a['score']]);

        return array_slice($hits, 0, self::MAX_MATCHES);
    }

    /** فيه مطابقة مؤكدة على الأقل؟ — الحارس اللي بيوقف الحفظ */
    public static function hasSure(array $hits): bool
    {
        foreach ($hits as $h) {
            if (($h['confidence'] ?? null) === self::SURE) {
                return true;
            }
        }

        return false;
    }

    /**
     * الحكم على صف واحد.
     *
     * @return array{0: ?string, 1: string, 2: float}
     */
    private static function judge(
        Client $c,
        string $name,
        string $nameEn,
        string $phone,
        ?int $zoneId,
        ?int $groupId,
    ): array {
        $sameChain = $groupId !== null && (int) $c->group_id === $groupId;
        $sameZone = $zoneId !== null && (int) $c->zone_id === $zoneId;

        // 2/3) الاسم المطبّع — بنقارن كل اسم بالاتنين عشان اللي
        //      بيكتب الإنجليزي في خانة العربي ما يعديش.
        //      **قبل التليفون** عشان الاسم المطابق يفضل «نفس الاسم»
        //      حتى لو الرقم مطابق كمان — الرسالة الأوضح للمستخدم.
        foreach ([[$name, 'name'], [$nameEn, 'name_en']] as [$val, $field]) {
            if ($val === '') {
                continue;
            }

            if (self::sameName($val, $c->name) || self::sameName($val, $c->name_en)) {
                return [$field, self::SURE, 100.0];
            }
        }

        // 1) التليفون — أقوى إشارة عندنا
        if (self::samePhone($phone, $c->phone)) {
            // ⚠️ **إلا جوّه السلسلة.** سلسلة زي Circle K عندها 40 فرع
            // كلهم مكتوب عليهم رقم الإدارة. لو الرقم لوحده «مؤكد»
            // هنا، فلو «فرع جديد بشروط السلسلة» كان هيتحرس على كل
            // فرع جديد — والمستخدم بيتعلّم يدوس «كمّل» من غير ما
            // يقرا، فالحارس يبقى شكل. الاسم مختلف ⇒ **محتمل**.
            return $sameChain && $name !== '' && ! self::sameName($name, $c->name)
                ? ['phone', self::LIKELY, 95.0]
                : ['phone', self::SURE, 100.0];
        }

        // 4/5) اسم قريب — جوّه نفس السلسلة مؤكد، وجوّه نفس الزون محتمل

        if (! $sameChain && ! $sameZone) {
            return [null, self::LIKELY, 0.0];
        }

        $best = null;

        foreach ([$name, $nameEn] as $val) {
            foreach ([$c->name, $c->name_en] as $other) {
                $pct = self::nearName($val, $other);

                if ($pct !== null && ($best === null || $pct > $best)) {
                    $best = $pct;
                }
            }
        }

        if ($best === null) {
            return [null, self::LIKELY, 0.0];
        }

        return [
            $sameChain ? 'near_chain' : 'near_zone',
            $sameChain ? self::SURE : self::LIKELY,
            $best,
        ];
    }

    /**
     * صف جاهز للعرض — والاسم بيتحجب لو الفاعل مش مسموح له يشوفه.
     *
     * @return array<string, mixed>
     */
    private static function row(Client $c, string $by, string $conf, float $score, ?User $viewer): array
    {
        $visible = $viewer === null
            || ($c->visibleBy($viewer) && $viewer->canSeeBranch($c->branch_id));

        // ═══ شروط التعامل بتاعة الشبيه (١٨ أغسطس ٢٠٢٦) ═══
        //
        // ⚠️ **ليه هنا مش في الكنترولر؟** شاشة اعتماد الطلبات بتعبّي
        // شروط العميل الجديد من الشبيه بزرار واحد (قناة/قسم/سلسلة/
        // قايمة/نسبة) — والشبيه بييجي من هنا. لو الكنترولر جاب الشروط
        // لوحده كان هيبقى فيه مصدرين ممكن يختلفوا.
        //
        // ⚠️ النسبة من `effectiveDiscount()` — **الرقم الفعلي** اللي
        // الشبيه بيتحاسب بيه (عقد سارٍ يغلب الخصم الخاص)، مش عمود خام.
        // ولو مصدرها عقد **السلسلة**، الفرع الجديد هيرثه أوتوماتيك
        // بمجرد اختيار نفس السلسلة — فبنعلّم `chain_covered` عشان
        // الواجهة تسيب الخصم الخاص صفر بدل ما تكرّر النسبة (خصم خاص
        // فوق عقد السلسلة = العقد بيتغلب حسب الترتيب المقدّس).
        //
        // ⚠️ للمرئي بس — صف الفريق التاني بيتعرض بكوده وبلا شروط،
        // نفس منطق حجب الاسم فوق.
        //
        // ⚠️ **وللمعتمِدين بس** (`canDecideOps`): حارس التكرار في
        // الأبلكيشن بينده نفس الدالة بمندوب كـviewer — المندوب مش
        // محتاج نسب خصم في الرد، وحساب `liveContract()` لكل مرشح
        // كان هيضيف كويريز عقود على مسار الموبايل ببلاش.
        // (`$viewer === null` = مسار داخلي/استيراد — الشروط بتتحسب
        // ومحدش بيعرضها، مقبول.)
        $terms = null;

        if ($visible && ($viewer === null || $viewer->canDecideOps())) {
            $lc = $c->liveContract();
            $chainCovered = $lc !== null
                && $c->group?->contract !== null
                && $lc->is($c->group->contract);

            $terms = [
                'channel_id' => $c->channel_id,
                'sub_channel' => $c->sub_channel,
                'group_id' => $c->group_id,
                'price_list_id' => $c->price_list_id,
                'discount' => round($c->effectiveDiscount() * 100, 2),
                'chain_covered' => $chainCovered,
            ];
        }

        return [
            'id' => $visible ? $c->id : null,
            'code' => $c->code,
            // ⚠️ الاسم بيتحجب مش الصف كله — إخفاء الصف معناه إن
            // الحارس بيسمح بالتكرار عبر الفرق، وده أسوأ من التسريب.
            'name' => $visible ? $c->fullName() : __('client.dup_other_team'),
            'phone' => $visible ? $c->phone : null,
            'zone' => $visible ? $c->zone?->displayName() : null,
            'rep' => $visible ? $c->rep?->displayName() : null,
            'manager' => $visible ? $c->manager?->displayName() : null,
            'last_activity' => $visible ? $c->last_activity_at?->format('Y-m-d') : null,
            'status' => $c->status,
            'by' => $by,
            'by_label' => __('client.dup_by_'.$by),
            'confidence' => $conf,
            'confidence_label' => __('client.dup_conf_'.$conf),
            'score' => $score,
            'visible' => $visible,
            'url' => $visible ? route('erp.clients.show', $c->id) : null,
            'terms' => $terms,
        ];
    }

    /**
     * العملاء المرشحين للمقارنة.
     *
     * ⚠️ **مش كل الجدول.** المطابقة القاطعة بتيجي من عمودين مفهرسين
     * (`dupe_key` / `dupe_phone`)، والمقارنة التقريبية محصورة في نفس
     * الزون أو نفس السلسلة — وده تعريف القاعدة نفسها مش تحسين أداء.
     * على قاعدة فيها 10 آلاف عميل ده بيقرا عشرات الصفوف مش عشرة آلاف.
     *
     * ⚠️ ولو المايجريشن لسه ماتشغّلش (السيرفر مش ريبو جيت والمالك
     * بيرفع بإيده)، بنرجع للمسح الكامل — الحارس مايقفش لأن عمود ناقص.
     *
     * @return \Illuminate\Support\Collection<int, Client>
     */
    private static function candidates(
        string $name,
        string $nameEn,
        string $phone,
        ?int $zoneId,
        ?int $groupId,
    ) {
        $with = ['zone:id,code,name,name_en', 'group:id,name,name_en',
            'rep:id,name,name_en', 'manager:id,name,name_en'];

        $cols = ['id', 'code', 'name', 'name_en', 'phone', 'status', 'branch_id',
            'zone_id', 'group_id', 'rep_id', 'manager_id', 'last_activity_at'];

        if (! self::hasKeyColumns()) {
            return Client::with($with)->get($cols);
        }

        $nk = self::nameKey($name);
        $nke = self::nameKey($nameEn);
        $pk = self::phoneKey($phone);
        $keys = array_values(array_filter([$nk, $nke]));

        // ⚠️ **كويريين مش واحد بـ`OR`.** لو دمجناهم وحطّينا `limit`،
        // الحد كان ممكن ياكل المطابقة القاطعة نفسها: زون فيه 900 عميل
        // بيملا الـ800 قبل ما يوصل للصف اللي اسمه مطابق بالحرف —
        // فالحارس بيسكت على أوضح تكرار ممكن. المطابقة القاطعة **بلا
        // حد** (مفهرسة، صفوف معدودة)، والحد على الجزء التقريبي بس.
        $exact = Client::with($with)
            ->where(function ($w) use ($keys, $pk) {
                if ($keys !== []) {
                    $w->orWhereIn('dupe_key', $keys);
                }
                if ($pk !== '') {
                    $w->orWhere('dupe_phone', $pk);
                }
                // مفيش شرط = مفيش صفوف (مش «كل الصفوف»)
                $w->orWhereRaw('1 = 0');
            })
            ->get($cols);

        if ($zoneId === null && $groupId === null) {
            return $exact;
        }

        $near = Client::with($with)
            ->where(function ($w) use ($zoneId, $groupId) {
                if ($zoneId !== null) {
                    $w->orWhere('zone_id', $zoneId);
                }
                if ($groupId !== null) {
                    $w->orWhere('group_id', $groupId);
                }
            })
            ->whereNotIn('id', $exact->pluck('id')->all() ?: [0])
            ->limit(self::NEAR_POOL_CAP)
            ->get($cols);

        return $exact->concat($near);
    }

    /** الأعمدة المفهرسة موجودة؟ — بيتسأل مرة واحدة في الريكوست */
    private static ?bool $hasKeys = null;

    public static function hasKeyColumns(): bool
    {
        return self::$hasKeys ??= Schema::hasColumn('clients', 'dupe_key')
            && Schema::hasColumn('clients', 'dupe_phone');
    }

    /** للتيستات — بيصفّر الكاش بتاع فحص الأعمدة */
    public static function forgetSchemaCache(): void
    {
        self::$hasKeys = null;
    }
}
