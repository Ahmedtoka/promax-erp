<?php

namespace App\Services\Importers;

use App\Models\Channel;
use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use App\Models\Zone;
use App\Services\Sheet;
use App\Support\Dupes;
use App\Support\Governorates;
use App\Support\LeadScore;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * استيراد العملاء المحتملين من الأدلة الخارجية
 * ═══════════════════════════════════════════════════════════════
 *
 * المستورد ده بياكل **إكسبورت أكتور Apify زي ما هو** — مافيش تنضيف
 * يدوي للملف قبل الرفع. أعمدة جوجل مابس (`title` / `categoryName` /
 * `location/lat`) وأعمدة سكرابر صفحات فيسبوك (`pageName` / `likes`)
 * كلها متعرّفة تحت، جنب الأسماء العربية اللي حد ممكن يكتبها بإيده.
 *
 * ═══ ليه ده مش `ClientImporter` ═══
 *
 * لأن الليد **مش عميل**. الداتا الجاية من دليل خارجي غير متحققة:
 * التليفون ممكن يكون قديم، والمكان ممكن يكون قفل، والاسم ممكن يكون
 * فرع لسلسلة إحنا أصلاً بنبيعلها. لو دخلت `clients` على طول:
 *
 *   - هتظهر في كل دروب داون وكل تقرير وكل عدّاد في السيستم
 *   - هتاخد كود عميل حقيقي مايتلغيش
 *   - وهتفضل «عميل مالوش أي حركة» للأبد وتخرب تقارير الخمول
 *
 * فالمسار الصح: `leads` → متابعة → `Leads::convert()` → عميل.
 *
 * ═══ التوزيع الأوتوماتيك ═══
 *
 * كل ليد فيه إحداثيات بيتربط بـ**أقرب زون** (لو في حدود
 * `MAX_ZONE_KM`) وبيروح لمندوب الزون ده. الاقتراح مش قفل — المدير
 * بيعدّله من شاشة الليدز عادي.
 *
 * ⚠️ **الليد من غير إحداثيات بيدخل من غير زون ولا مندوب** بدل ما
 * يتحط في زون افتراضي. ليد في زون غلط أسوأ من ليد بلا زون: التاني
 * بيبان في الفلتر ومحدش بيضيّع يوم عليه.
 */
class LeadImporter extends Importer
{
    /** أبعد مسافة نقبل بيها ربط الليد بزون — بره كده بنسيبه بلا زون */
    private const MAX_ZONE_KM = 12.0;

    /** أقرب من كده لعميل موجود بنفس الاسم ⇒ نفس الفرع، بنتخطاه */
    private const SAME_PLACE_M = 100;

    public function kind(): string
    {
        return 'leads';
    }

    /**
     * ⚠️ الأسماء دي **مقصودة بالحرف**: أول اسم هو اللي بينزل في
     * القالب الفاضي، وباقي القايمة هي الأسماء اللي بتطلع من الأكتورز
     * الجاهزة. أي تعديل هنا لازم يفضل شايل أسماء Apify زي ما هي —
     * أول ما نغيّرها، اللي بيرفع هيبقى مضطر يعدّل الهيدر بإيده وده
     * بالظبط اللي المستورد ده اتعمل عشان يمنعه.
     */
    public function columns(): array
    {
        return [
            // ═══ الأساسي ═══
            'name' => ['اسم المكان', 'name', 'title', 'pageName', 'page name', 'الاسم'],
            'category' => ['النشاط', 'category', 'categoryName', 'category name', 'categories/0', 'التصنيف'],
            'phone' => ['التليفون', 'phone', 'phoneUnformatted', 'الموبايل', 'mobile'],
            'address' => ['العنوان', 'address', 'street', 'full address'],
            'city' => ['المدينة', 'city', 'neighborhood', 'المنطقة'],
            'governorate' => ['المحافظة', 'governorate', 'state'],

            // ═══ الإحداثيات — الأكتور بيسمّيها بنقطة أو بسلاش ═══
            'lat' => ['خط العرض', 'lat', 'location/lat', 'location.lat', 'latitude'],
            'lng' => ['خط الطول', 'lng', 'location/lng', 'location.lng', 'longitude'],

            // ═══ إشارات الحجم ═══
            'rating' => ['التقييم', 'rating', 'totalScore', 'total score', 'stars'],
            'reviews' => ['عدد التقييمات', 'reviews', 'reviewsCount', 'reviews count', 'likes', 'followers'],

            // ═══ المراجع ═══
            'external_id' => ['المعرّف الخارجي', 'external_id', 'placeId', 'place id', 'pageId', 'fid'],
            'website' => ['الموقع', 'website', 'url', 'facebookUrl', 'pageUrl'],
            'contact_name' => ['اسم المسؤول', 'contact_name', 'contact'],

            // ═══ حالة المكان — الأكتور بيقول لو قافل ═══
            'closed' => ['مقفول', 'closed', 'permanentlyClosed', 'permanently closed', 'temporarilyClosed'],

            // ═══ اختياري: تقدير بشري لحجم الشغل ═══
            'expected_monthly' => ['المتوقع شهرياً', 'expected_monthly', 'expected'],
            'notes' => ['ملاحظات', 'notes', 'note'],
        ];
    }

    public function required(): array
    {
        return ['name'];
    }

    /** ملاحظات التنفيذ — بتتعرض في كارت الاستيراد جنب الأخطاء */
    private array $notes = [];

    /** @return list<string> */
    public function notes(): array
    {
        return $this->notes;
    }

    public function validateRow(array $row, int $line): array
    {
        $out = [];

        if (trim((string) ($row['name'] ?? '')) === '') {
            $out[] = __('import.name_required');
        }

        foreach (['lat', 'lng', 'rating', 'reviews', 'expected_monthly'] as $f) {
            $v = $row[$f] ?? null;

            if ($v !== null && $v !== '' && Sheet::number($v) === null) {
                $out[] = __('import.not_a_number', ['column' => $f, 'value' => $v]);
            }
        }

        $lat = Sheet::number($row['lat'] ?? null);
        $lng = Sheet::number($row['lng'] ?? null);

        // ⚠️ إحداثيات مقلوبة (lat في خانة lng) بتحصل كتير في الإكسبورت
        // اليدوي، والنتيجة ليد في المحيط الهندي بيتربط بأقرب زون
        // «جغرافياً» وهو على بعد ٤٠٠٠ كيلو. الرفض أوضح من ليد تايه.
        if ($lat !== null && ($lat < -90 || $lat > 90)) {
            $out[] = __('import.bad_lat', ['value' => $lat]);
        }

        if ($lng !== null && ($lng < -180 || $lng > 180)) {
            $out[] = __('import.bad_lng', ['value' => $lng]);
        }

        return $out;
    }

    /**
     * ⚠️⚠️ **حارس التكرار — أهم جزء في المستورد كله.**
     *
     * الرفعة التانية لنفس المنطقة بترجّع ٨٠٪ من نفس الأماكن. من غير
     * الحارس ده، المندوب بيلاقي نفس المحل تلات مرات في قايمته
     * وبيتصل بيه تلاتة، والعميل بيقول «انتوا كلمتوني إمبارح».
     *
     * الترتيب — من الأدق للأقل:
     *
     *   ١. `external_id` — مفتاح المكان نفسه. تطابق = نفس المكان، خلاص.
     *   ٢. التليفون المطبّع — ضد **العملاء الحاليين** كمان مش الليدز بس.
     *      محل إحنا بنبيعله خلاص مالوش لازمة في خط الأنابيب.
     *   ٣. الاسم المطبّع + قرب جغرافي — «كافيه المعادي» على بعد ٥٠ متر
     *      من «كافيه المعادى» = نفس المكان باختلاف همزة.
     *   ٤. تكرار جوه نفس الملف — الأكتور بيرجّع الفرع مرتين لو ظهر في
     *      نتيجتين بحث مختلفتين.
     *
     * ⚠️ التكرار هنا **بيتخطى بهدوء مش بيترفض بخطأ**. رفعة ٢٠٠٠ صف
     * فيها ١٦٠٠ مكرر كانت هتطلّع ١٦٠٠ سطر أحمر ومحدش هيقرأهم — العدّ
     * في الملخص أنفع.
     */
    public function validateAll(array $rows): array
    {
        $this->notes = [];

        // ═══ فهارس الداتابيز — مرة واحدة ═══
        $clientPhones = [];
        $clientNames = [];
        $clientSpots = [];

        foreach (Client::query()->get(['id', 'code', 'name', 'phone', 'lat', 'lng']) as $c) {
            if (($pk = Dupes::phoneKey($c->phone)) !== '') {
                $clientPhones[$pk] ??= $c;
            }

            $nk = Dupes::nameKey($c->name);
            $clientNames[$nk] ??= $c;
            $clientSpots[$nk][] = [$c->lat !== null ? (float) $c->lat : null, $c->lng !== null ? (float) $c->lng : null];
        }

        $leadExternals = [];
        $leadPhones = [];
        $leadSpots = [];

        foreach (Lead::query()->get(['id', 'number', 'name', 'phone', 'external_id', 'lat', 'lng']) as $l) {
            if (($ex = trim((string) $l->external_id)) !== '') {
                $leadExternals[$ex] ??= $l;
            }

            if (($pk = Dupes::phoneKey($l->phone)) !== '') {
                $leadPhones[$pk] ??= $l;
            }

            $leadSpots[Dupes::nameKey($l->name)][] = [
                $l->lat !== null ? (float) $l->lat : null,
                $l->lng !== null ? (float) $l->lng : null,
            ];
        }

        $seenExternal = $seenPhone = [];
        $seenSpots = [];
        $ok = [];
        $errors = [];
        $rowNotes = [];
        $dupClients = $dupLeads = $dupSheet = $closed = $offTarget = 0;

        foreach ($rows as $i => $row) {
            // +2: سطر العناوين + الترقيم بيبدأ من واحد — زي الأب بالظبط
            $line = $i + 2;

            $problems = $this->validateRow($row, $line);

            if ($problems !== []) {
                foreach ($problems as $p) {
                    $errors[] = __('import.line_error', ['line' => $line, 'error' => $p]);
                }

                continue;
            }

            $name = trim((string) $row['name']);

            // ═══ المكان مقفول ═══
            if ($this->isClosed($row['closed'] ?? null)) {
                $closed++;

                continue;
            }

            // ═══ النشاط مش من شغلنا ═══
            // ⚠️ الترتيب مهم: التطابق الإيجابي **قبل** الرفض. محل اسمه
            // «Gym & Juice Bar» بيتقبل كجيم، مش بيترفض بسبب كلمة bar.
            $category = trim((string) ($row['category'] ?? ''));

            if (LeadScore::match($category, $name) === null && LeadScore::rejected($category, $name)) {
                $offTarget++;

                continue;
            }

            $ex = trim((string) ($row['external_id'] ?? ''));
            $pk = Dupes::phoneKey($row['phone'] ?? null);
            $nk = Dupes::nameKey($name);
            $lat = Sheet::number($row['lat'] ?? null);
            $lng = Sheet::number($row['lng'] ?? null);

            // ═══ ١. المعرّف الخارجي ═══
            if ($ex !== '' && (isset($leadExternals[$ex]) || isset($seenExternal[$ex]))) {
                isset($seenExternal[$ex]) ? $dupSheet++ : $dupLeads++;

                continue;
            }

            // ═══ ٢. التليفون ═══
            if ($pk !== '') {
                if (isset($clientPhones[$pk])) {
                    $dupClients++;

                    continue;
                }

                if (isset($leadPhones[$pk])) {
                    $dupLeads++;

                    continue;
                }

                if (isset($seenPhone[$pk])) {
                    $dupSheet++;

                    continue;
                }
            }

            // ═══ ٣. الاسم المطبّع + القرب ═══
            //
            // ⚠️⚠️ **الاسم لوحده ممنوع يكون كفاية — للتلاتة.** إكسبورت
            // جوجل بيكتب اسم السلسلة في عمود `title` لكل فرع، فـ«سيلانترو»
            // بتتكرر ٤٠ مرة لـ٤٠ فرع مختلف. الحكم بالاسم بس كان بيدخّل
            // فرع واحد ويرمي الـ٣٩ الباقيين كـ«مكررين». ونفس الحكاية مع
            // «سوبر ماركت النور» اللي في كل حتة.
            //
            // القاعدة: نفس الاسم **و** على بعد أقل من `SAME_PLACE_M` =
            // نفس المكان. مفيش إحداثيات على أي من الطرفين ⇒ بنعتبره
            // تكرار (مانقدرش نفرّق، والتخطي أأمن من ليد مكرر).
            if ($nk !== '') {
                if ($this->sameSpot($seenSpots, $nk, $lat, $lng)) {
                    $dupSheet++;

                    continue;
                }

                if ($this->sameSpot($leadSpots, $nk, $lat, $lng)) {
                    $dupLeads++;

                    continue;
                }

                if ($this->sameSpot($clientSpots, $nk, $lat, $lng)) {
                    $dupClients++;
                    $rowNotes[] = __('lead.dup_client_note', [
                        'name' => $name,
                        'code' => (string) ($clientNames[$nk]->code ?? ''),
                    ]);

                    continue;
                }
            }

            if ($ex !== '') {
                $seenExternal[$ex] = $line;
            }
            if ($pk !== '') {
                $seenPhone[$pk] = $line;
            }
            if ($nk !== '') {
                $seenSpots[$nk][] = [$lat, $lng];
            }

            $ok[] = $row;
        }

        // ═══ الملخص أول حاجة ═══
        //
        // ⚠️⚠️ **الترتيب مش تجميل.** `ImportController` بيقص الملاحظات
        // على ٦٠ سطر. رفعة فيها ١٦٠٠ مكرر كانت بتملا الستين بسطور
        // فردية، **والملخص اللي الشاشة كلها معتمدة عليه بيتقص ويختفي**.
        // الملخص فوق، والسطور الفردية بحد أقصى ٢٠ بعده.
        $summary = [];

        foreach ([
            'lead.skip_dup_clients' => $dupClients,
            'lead.skip_dup_leads' => $dupLeads,
            'lead.skip_dup_sheet' => $dupSheet,
            'lead.skip_closed' => $closed,
            'lead.skip_off_target' => $offTarget,
        ] as $key => $n) {
            if ($n > 0) {
                $summary[] = __($key, ['n' => number_format($n)]);
            }
        }

        $this->notes = array_merge($summary, array_slice($rowNotes, 0, 20));

        return ['ok' => $ok, 'errors' => $errors];
    }

    public function apply(array $rows): array
    {
        $created = 0;
        $zoned = 0;
        $assigned = 0;
        $unzoned = 0;

        // ═══ الزونز بإحداثياتها — للربط بالأقرب ═══
        $zonePoints = [];

        // ⚠️ **النشطة بس.** زون متوقّف وقريب جغرافياً كان بيخطف الليد،
        // ومالوش مندوب نشط في `zoneReps()` ⇒ الليد بيقع في زون ميت بلا
        // مسؤول. وده بالظبط أسوأ من الليد بلا زون: مابيبانش في أي فلتر
        // بيشتغل عليه حد.
        foreach (Zone::query()->where('active', true)->whereNotNull('lat')->whereNotNull('lng')->get(['id', 'lat', 'lng']) as $z) {
            $zonePoints[] = [(int) $z->id, (float) $z->lat, (float) $z->lng];
        }

        $channelIds = Channel::query()->pluck('id', 'code')->all();

        // ⚠️ مندوب كل زون بيتحسب **مرة واحدة قبل اللوب**. حسابه جوه
        // اللوب معناه كويري لكل صف — رفعة ٢٠٠٠ صف = ٢٠٠٠ كويري
        // والريكوست بيموت قبل ما يخلص.
        $zoneReps = $this->zoneReps();

        // ⚠️ **الرقم بيتحسب مرة واحدة وبيزيد في الذاكرة.**
        // `Lead::nextNumber()` جوه اللوب = كويري لكل صف، ورفعة ٢٠٠٠
        // صف بتتحول لـ٢٠٠٠ كويري زيادة على الإنشاء نفسه.
        $seq = (int) preg_replace('/\D+/', '', Lead::nextNumber());

        DB::transaction(function () use ($rows, $zonePoints, $channelIds, $zoneReps, &$seq, &$created, &$zoned, &$assigned, &$unzoned) {
            foreach ($rows as $row) {
                $name = trim((string) $row['name']);
                $category = trim((string) ($row['category'] ?? '')) ?: null;

                $lat = Sheet::number($row['lat'] ?? null);
                $lng = Sheet::number($row['lng'] ?? null);
                $rating = Sheet::number($row['rating'] ?? null);
                $reviews = Sheet::number($row['reviews'] ?? null);

                // ═══ الزون والمندوب ═══
                $zoneId = ($lat !== null && $lng !== null)
                    ? $this->nearestZone($zonePoints, $lat, $lng)
                    : null;

                if ($zoneId !== null) {
                    $zoned++;
                } else {
                    $unzoned++;
                }

                $repId = $zoneId !== null ? ($zoneReps[$zoneId] ?? null) : null;

                if ($repId !== null) {
                    $assigned++;
                }

                // ═══ القناة المقترحة وقسمها ═══
                $guess = LeadScore::match($category, $name);
                $channelCode = $guess['channel'] ?? null;
                $channelId = $channelCode !== null ? ($channelIds[$channelCode] ?? null) : null;

                // ⚠️ القسم للكي أكاونت وبس — نفس قاعدة `Channel`.
                // قسم على قناة تانية بيتصفّى في الموديل بعدين، بس
                // كتابته أصلاً بتخلّي فلتر «سلاسل هايبر» يكدب لحد ساعتها.
                $subChannel = ($channelCode === Channel::KEY_ACCOUNT) ? ($guess['sub'] ?? null) : null;

                Lead::create([
                    'number' => 'LD-'.$seq++,
                    'name' => $name,
                    'phone' => $this->phone($row['phone'] ?? null),
                    'contact_name' => $this->trimOrNull($row['contact_name'] ?? null, 190),
                    'address' => $this->address($row),
                    'governorate' => Governorates::match($row['governorate'] ?? null)
                        ?? Governorates::match($row['city'] ?? null),
                    'zone_id' => $zoneId,
                    'channel_id' => $channelId,
                    'sub_channel' => $subChannel,
                    'assigned_to' => $repId,
                    'status' => 'new',
                    'source' => $this->source($row),
                    'external_id' => $this->trimOrNull($row['external_id'] ?? null, 120),
                    'website' => $this->trimOrNull($row['website'] ?? null, 255),
                    'rating' => $rating !== null ? round(max(0, min(5, $rating)), 2) : null,
                    'reviews_count' => $reviews !== null ? max(0, (int) $reviews) : 0,
                    'category_raw' => $category !== null ? mb_substr($category, 0, 120) : null,
                    'score' => LeadScore::compute($category, $name, $rating, $reviews !== null ? (int) $reviews : null),
                    'lat' => $lat,
                    'lng' => $lng,
                    // ⚠️ **صفر عن قصد.** السكور ترتيب مش فلوس، وحطّه في
                    // خانة المتوقع شهرياً كان هيخلّي KPI «قيمة خط
                    // الأنابيب» يجمع أرقام مالهاش وحدة ويطلع رقم بالجنيه
                    // مش مبني على أي حاجة. اللي بيملا الرقم ده إنسان.
                    'expected_monthly' => Sheet::number($row['expected_monthly'] ?? null) ?? 0,
                    'notes' => $this->trimOrNull($row['notes'] ?? null, 2000),
                    'created_by' => auth()->id(),
                ]);

                $created++;
            }
        });

        return [
            'created' => $created,
            'zoned' => $zoned,
            'unzoned' => $unzoned,
            'assigned' => $assigned,
        ];
    }

    // ==================== المساعدات ====================

    /**
     * مندوب واحد لكل زون.
     *
     * الأولوية: سيلز إيجينت مربوط بالزون في `zone_user` ← سيلز إيجينت
     * الزون ده زونه الأساسي. مفيش ⇒ الليد بيدخل بلا مندوب والمدير
     * بيوزّعه.
     *
     * ⚠️ **النشطين بس.** تسكين ليد على مندوب مستقيل معناه إنه بيختفي
     * من كل شاشة — مش بيبان لحد، ومش بيتشتغل عليه.
     *
     * @return array<int, int>
     */
    private function zoneReps(): array
    {
        $out = [];

        // ١) الربط الصريح في `zone_user`
        $links = DB::table('zone_user')
            ->join('users', 'users.id', '=', 'zone_user.user_id')
            ->where('users.active', true)
            ->where('users.role', 'sales_agent')
            ->orderBy('zone_user.zone_id')
            ->orderBy('users.id')
            ->get(['zone_user.zone_id', 'users.id as user_id']);

        foreach ($links as $l) {
            $out[(int) $l->zone_id] ??= (int) $l->user_id;
        }

        // ٢) زون المندوب الأساسي — للزونز اللي مالهاش ربط صريح
        $primary = User::query()
            ->where('active', true)
            ->where('role', 'sales_agent')
            ->whereNotNull('zone_id')
            ->orderBy('id')
            ->get(['id', 'zone_id']);

        foreach ($primary as $u) {
            $out[(int) $u->zone_id] ??= (int) $u->id;
        }

        return $out;
    }

    /**
     * أقرب زون للنقطة — أو null لو كلهم أبعد من `MAX_ZONE_KM`.
     *
     * @param  list<array{0: int, 1: float, 2: float}>  $zones
     */
    private function nearestZone(array $zones, float $lat, float $lng): ?int
    {
        $bestId = null;
        $bestM = null;

        foreach ($zones as [$id, $zlat, $zlng]) {
            $m = self::metres($lat, $lng, $zlat, $zlng);

            if ($bestM === null || $m < $bestM) {
                $bestM = $m;
                $bestId = $id;
            }
        }

        return ($bestM !== null && $bestM <= self::MAX_ZONE_KM * 1000) ? $bestId : null;
    }

    /**
     * فيه صف بنفس الاسم المطبّع **وفي نفس المكان**؟
     *
     * ⚠️ إحداثيات ناقصة على أي من الطرفين ⇒ `true`. مانقدرش نفرّق،
     * والتخطي أأمن: ليد مكرر بيخلّي مندوبين يكلّموا نفس المحل، وده
     * أغلى من فرصة اتفوّتت وممكن تتضاف بإيد.
     *
     * @param  array<string, list<array{0: ?float, 1: ?float}>>  $spots
     */
    private function sameSpot(array $spots, string $nameKey, ?float $lat, ?float $lng): bool
    {
        foreach ($spots[$nameKey] ?? [] as [$plat, $plng]) {
            if ($plat === null || $plng === null || $lat === null || $lng === null) {
                return true;
            }

            if (self::metres($lat, $lng, $plat, $plng) <= self::SAME_PLACE_M) {
                return true;
            }
        }

        return false;
    }

    /** هافرساين — المسافة بالمتر */
    private static function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * المصدر: من فين جه الصف.
     *
     * ⚠️ بنشتقه من شكل المرجع مش بنسأل اليوزر — اللي بيرفع إكسبورت
     * جوجل مش لازم يفتكر يختار «جوجل مابس» من دروب داون، والاختيار
     * الغلط بيخلّي فلتر المصدر يكدب بعدين.
     */
    private function source(array $row): string
    {
        // ⚠️⚠️ **العمودين منفصلين، مايتدمجوش في نص واحد.** أول نسخة
        // كانت بتدمجهم بمسافة، فـ`str_starts_with($ref, 'chij')` كانت
        // بترجّع false دايماً لما الموقع فاضي (النص بيبدأ بمسافة).
        // وأسوأ: في إكسبورت جوجل الحقيقي عمود `website` بيبقى **موقع
        // المحل** (`https://goldsgym.com`) مش لينك جوجل — فالدمج
        // بيخلّي كشف المصدر مايشتغلش خالص.
        $site = mb_strtolower(trim((string) ($row['website'] ?? '')), 'UTF-8');
        $id = mb_strtolower(trim((string) ($row['external_id'] ?? '')), 'UTF-8');

        if (str_contains($site, 'facebook') || str_contains($site, 'instagram')) {
            return 'facebook';
        }

        // placeId بتاع جوجل بيبدأ بـ ChIJ، والـ fid بيبقى فيه `0x`
        if (str_starts_with($id, 'chij') || str_contains($id, '0x') || str_contains($site, 'google.com/maps')) {
            return 'gmaps';
        }

        return 'sheet';
    }

    /** التليفون زي ما جه، بس من غير مسافات زيادة وبحد الطول */
    private function phone(?string $v): ?string
    {
        $v = trim((string) $v);

        if ($v === '') {
            return null;
        }

        // ⚠️ **مابنطبّعش الرقم للتخزين.** `Dupes::phoneKey` للمقارنة بس.
        // المندوب محتاج الرقم زي ما هو عشان يتصل، وكتابته بشكل تاني
        // بتخلّي الرقم مايتطابقش مع اللي في تليفون العميل.
        return mb_substr(preg_replace('/\s+/u', ' ', $v) ?? $v, 0, 30);
    }

    /** العنوان = العنوان + المدينة، من غير تكرار */
    private function address(array $row): ?string
    {
        $parts = [];

        foreach (['address', 'city'] as $k) {
            $v = trim((string) ($row[$k] ?? ''));

            if ($v !== '' && ! in_array($v, $parts, true)) {
                $parts[] = $v;
            }
        }

        $out = implode(' — ', $parts);

        return $out !== '' ? mb_substr($out, 0, 190) : null;
    }

    private function trimOrNull(?string $v, int $max): ?string
    {
        $v = trim((string) $v);

        return $v !== '' ? mb_substr($v, 0, $max) : null;
    }

    /**
     * المكان مقفول؟ الأكتورز بتكتبها `true` / `TRUE` / `1` / `نعم`.
     *
     * ⚠️ `(bool) 'false'` في PHP بترجّع **true** — أي نص غير فاضي
     * بيبقى true. الفحص لازم يكون على القيم نفسها.
     */
    private function isClosed(?string $v): bool
    {
        $v = mb_strtolower(trim((string) $v), 'UTF-8');

        return in_array($v, ['1', 'true', 'yes', 'نعم', 'مقفول', 'مغلق', 'closed'], true);
    }
}
