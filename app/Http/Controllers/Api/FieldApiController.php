<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\StockShortage;
use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\TrackEvent;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Visit;
use App\Models\Zone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * الـ API بتاع الموبايل أبلكيشن — كاش فان وكورير
 */
class FieldApiController extends Controller
{
    /**
     * GET /api/pulse — بصمة حالة المندوب في ريكوست شبه مجاني.
     *
     * ⚠️ **ده اللي بيخلّي الأبلكيشن لايف من غير ما يولّع الشبكة.**
     * البوت ستراب بيجيب العهدة والزونز وخط السير والفواتير — تقيل
     * جداً لو اتنادى كل 10 ثواني × كل مندوب في الشارع. البلس ده
     * `COUNT` و`MAX(id)` بس، والأبلكيشن بينده البوت ستراب **بس**
     * لما البصمة تتغير فعلاً.
     *
     * أي حاجة المندوب مستنيها لازم تكون في البصمة — لو مش هنا،
     * التغيير مش هيوصله غير في المزامنة الكاملة بعد 45 ثانية.
     */
    public function pulse(Request $request): JsonResponse
    {
        $id = $request->user()->id;

        // ⚠️ MAX(id) مش COUNT بس: أمر اتقفل واتفتح غيره = نفس العدد
        // وبصمة مختلفة. الاتنين مع بعض بيمسكوا الحالتين.
        $picks = DB::table('pick_orders')->where('assigned_to', $id)
            ->selectRaw("SUM(status = 'ready') ready, MAX(updated_at) t")->first();

        $notif = DB::table('app_notifications')->where('user_id', $id)
            ->selectRaw('COUNT(*) n, SUM(read_at IS NULL) unread, MAX(id) mx')->first();

        $pos = DB::table('purchase_orders')->where('assigned_to', $id)
            ->whereIn('status', ['pending', 'arrived'])
            ->selectRaw('COUNT(*) n, MAX(updated_at) t')->first();

        // ⚠️ العبرة بالبنود مش برأس العهدة: صنف نزل أو اتباع بيغيّر
        // `custody_items` بس، و`custodies.updated_at` بتفضل مكانها.
        // ⚠️ النهارده **أو المفتوحة** (١٠/٨) — نفس عقيدة `currentCustody`:
        // البصمة لازم تحس بعهدة امبارح المفتوحة اللي الأبلكيشن بيعرضها.
        $custody = DB::table('custodies')->where('custodies.user_id', $id)
            ->where(fn ($q) => $q->whereDate('custodies.date', today())
                ->orWhereNull('custodies.status')
                ->orWhere('custodies.status', '<>', 'closed'))
            ->leftJoin('custody_items', 'custody_items.custody_id', '=', 'custodies.id')
            ->selectRaw('COUNT(custody_items.id) n, MAX(custody_items.updated_at) t')
            ->first();

        $reqs = DB::table('client_requests')->where('created_by', $id)
            ->selectRaw('COUNT(*) n, MAX(updated_at) t')->first();

        $visits = DB::table('visits')->where('user_id', $id)
            ->whereDate('created_at', today())
            ->selectRaw('COUNT(*) n, MAX(updated_at) t')->first();

        // ⚠️ الحضور في البصمة كمان (2026-08-08): المدير ممكن يقفل
        // شيفت من السيستم، والأبلكيشن لازم يعرف فوراً وإلا المندوب
        // بيفضل شايف نفسه شغال والأكشنز بترجع 423 من غير سبب باين.
        $att = DB::table('attendance_days')->where('user_id', $id)
            ->whereDate('date', today())
            ->selectRaw('status, updated_at')->first();

        // ⚠️ **إصدار الأبلكيشن في البصمة** (2026-08-08). لما المدير
        // يرفع إصدار جديد من الداشبورد، المندوب الشغال كان بيفضل
        // على النسخة القديمة لحد ما يقفل الأبلكيشن ويفتحه — يعني
        // ممكن يوم كامل. دلوقتي البلس (كل 10 ثواني) بيلاقي البصمة
        // اتغيّرت، والأبلكيشن يعيد الفحص ويقفل نفسه لو لازم.
        $ver = \App\Models\Setting::read('app_version');
        $minVer = \App\Models\Setting::read('app_min_version');

        return response()->json([
            'stamp' => implode('|', [
                (int) ($picks->ready ?? 0), $picks->t ?? '',
                (int) ($notif->n ?? 0), (int) ($notif->unread ?? 0), (int) ($notif->mx ?? 0),
                (int) ($pos->n ?? 0), $pos->t ?? '',
                (int) ($custody->n ?? 0), $custody->t ?? '',
                (int) ($reqs->n ?? 0), $reqs->t ?? '',
                (int) ($visits->n ?? 0), $visits->t ?? '',
                $att->status ?? '', $att->updated_at ?? '',
                $ver, $minVer,
            ]),
            // ⚠️ بيتبعتوا صريحين كمان — الأبلكيشن بيقارن بنفسه من
            // غير ما يعمل ريكوست تاني لـ`/app-version`
            'app_version' => $ver,
            'app_min_version' => $minVer,
            // الأبلكيشن بيستخدمهم للتنبيه الداخلي من غير ما يستنى
            // البوت ستراب يرجع
            'ready_picks' => (int) ($picks->ready ?? 0),
            'unread' => (int) ($notif->unread ?? 0),
            'last_notification_id' => (int) ($notif->mx ?? 0),
        ]);
    }

    // ================= بوت ستراب: كل اللي الأبلكيشن محتاجه في ريكوست واحد =================

    public function bootstrap(Request $request): JsonResponse
    {
        $user = $request->user();
        $custody = $user->currentCustody();
        $custody?->load('items.product');

        // ⚠️ **مرة واحدة** — بتتستخدم في حتتين تحت (حالة الزيارة
        // وسامري النهارده)، ونداءين كانوا كويريين على كل بوت ستراب.
        $openWh = \App\Services\WarehouseVisits::open($user);

        return response()->json([
            'user' => [
                'id' => $user->id, 'name' => $user->displayName(), 'code' => $user->code,
                'role' => $user->role, 'role_label' => $user->roleLabel(),
                'zone' => $user->zone?->displayName(),
                // الأبلكيشن بيظبط لغته من هنا — نفس لغة الإشعارات
                // ⚠️ نفس السبب: الافتراضي بييجي من إعدادات السيستم.
                'locale' => $user->locale ?: config('app.locale'),
                // ⚠️ لازم في **كل** ردود bootstrap مش اللوجين بس —
                // الأبلكيشن بيعيد بناء اليوزر من هنا مع كل ريفريش،
                // ولو المفتاح ناقص الصورة بتختفي بعد أول فتح.
                'avatar_url' => $user->avatarUrl(),
            ],
            // السواق بيشوف عهدته بسعر القائمة القديم والسيلز بالجديد
            'custody' => self::custodyPayload($custody, $user->isDriver() ? 'old' : 'new'),
            'zones' => $user->isSalesAgent() ? self::zonesPayload($user) : [],
            // ⚠️ السيلز بقى بيشوف أوامر التوريد برضو — فلو الكي أكاونت
            // (2026-08-04): أمر معتمد من الحسابات واتجهز بينزله يسلمه.
            // ⚠️ والمدير كمان (١١/٨) — بيسلّم أوردرات بنفسه.
            // ⚠️ والبروموتر (١١/٨ مساءً) — طلب الريفيل بقى ممكن يتنزّل
            // عليه هو نفسه («نفس المندوب اللي طلبه»)، فلازم يشوف أمره.
            'purchase_orders' => in_array($user->role, User::FIELD_WORK_ROLES, true)
                ? self::posPayload($user) : [],
            'today' => $this->todayPayload($user),
            // ⚠️ **مع البوت ستراب مش ريكوست منفصل** — الأبلكيشن
            // بيقرر من أول رسمة يعرض بوب أب الحضور ولا لأ، ولو
            // استنى ريكوست تاني كان المندوب هيشوف الشاشة ثانية
            // ويبدأ يدوس قبل ما البوب أب يظهر.
            'attendance' => \App\Services\Attendance::payload($user),

            // ═══ زيارة المخزن المفتوحة + المخازن المتاحة (2026-08-08) ═══
            //
            // ⚠️ **مع البوت ستراب لنفس سبب الحضور.** الأبلكيشن لازم
            // يرسم بانر «انت جوه مخزن المعادي من 9:12» من أول رسمة —
            // ولو استنى ريكوست تاني، المندوب بيدوس استلام ويتفاجئ
            // بالرفض وهو شايف نفسه مسجّل.
            // ═══ حزمة المخزن — مشتركة مع بوت ستراب المدير (١١/٨) ═══
            ...self::warehouseBundle($user, $openWh),

            // ═══ الزيارة المفتوحة — أياً كان يومها (إصلاح ١١/٨) ═══
            // البانر في الأبلكيشن كان بيدور في قوايم النهارده بس،
            // فزيارة اتنست من يوم قديم كانت بتمنع التشيك إن من غير
            // ما حد يعرف هي فين. المفتاح ده هو مصدر الحقيقة الوحيد.
            'open_visit' => self::openVisitPayload($user),
            // ⚠️ **`is_read` لازم تتبعت** (إصلاح 2026-08-07). الأبلكيشن
            // كان بيعد الإشعارات كلها في الشارة عشان مكانش عارف
            // المقروء من غيره — فالمندوب يفتحها ويقفلها والرقم زي ما
            // هو، ويفضل حاسس إن فيه حاجة مستنياه على طول.
            'notifications' => $user->appNotifications()->take(20)->get()->map(fn ($n) => [
                'id' => $n->id, 'title' => $n->title, 'body' => $n->body,
                // ⚠️ **الوجهة** — من غيرها الإشعار جوّه الأبلكيشن نفسه
                // مش قابل للضغط، والمندوب بيقرا «أمر جاهز» ويدوّر عليه
                // بإيده في التبويبات.
                'link' => $n->link,
                'is_good' => $n->is_good, 'time' => $n->created_at->toIso8601String(),
                'is_read' => $n->read_at !== null,
            ]),
            // ⚠️ خطة اليوم بتيجي مع البوت ستراب مش في ريكوست منفصل —
            // المندوب بيفتح الأبلكيشن على شبكة موبايل ضعيفة، وكل
            // ريكوست زيادة معناه ثواني استنى في أول الشغل.
            'journey' => self::journeyPayload($user),
            'events' => $this->eventsPayload($user),
            'client_requests' => ClientRequest::where('created_by', $user->id)
                ->latest()->take(20)->get()->map(fn ($r) => [
                    'id' => $r->id, 'number' => $r->number, 'name' => $r->name,
                    'status' => $r->status, 'status_label' => $r->statusLabel(),
                    // ⚠️ العميل اللي اتولد من الاعتماد (١٩/٨) — الطلب
                    // المتوافق عليه بقى كليك أبل في الأبلكيشن: يوديك
                    // على العميل تبيع له على طول.
                    'client_id' => $r->client_id,
                    'time' => $r->created_at->toIso8601String(),
                ]),
        ]);
    }

    /**
     * ⚠️ **`public static` عن قصد (١١ أغسطس ٢٠٢٦)** — دي وأخواتها
     * (`zonesPayload` / `posPayload` / `journeyPayload`) بقوا العقد
     * المشترك مع الأبلكيشن: `ManagerApiController::bootstrap` بينده
     * عليهم بنفس الأشكال بالظبط بدل ما ينسخ منطق الحمولة. أي تغيير
     * في شكل الحمولة هنا بيوصل للرولين مع بعض — وده المطلوب.
     */
    public static function custodyPayload($custody, string $mode): array
    {
        if (! $custody) {
            return ['exists' => false, 'items' => []];
        }

        return [
            'exists' => true,
            'id' => $custody->id,
            'date' => $custody->date->toDateString(),
            // ═══ ميتا هيدر «عهدتي» (٢٠/٨) ═══ العربية ووقت التحميل —
            // الهيدر الجديد بيقول «عربية X · محمّلة الساعة كذا»
            'vehicle' => $custody->vehicle?->plate,
            'loaded_at' => $custody->created_at?->toIso8601String(),
            'status' => $custody->status,
            'remaining_units' => $custody->remainingUnits(),
            'remaining_value' => round($custody->remainingValue($mode), 2),
            'assigned_value' => round($custody->assignedValue($mode), 2),
            // إجمالي مرتجع العملاء في العربية — معروض مفصول عن المتاح
            'returned_in_units' => (int) $custody->items->sum(fn ($i) => (int) ($i->returned_in ?? 0)),
            // ⚠️ **التالف منفصل** — بضاعة مش قابلة للبيع، بتتسلّم
            // للمخزن لوحدها وقت التصفية. رقم واحد للاتنين كان بيخلّي
            // المندوب يفتكر إن عنده بضاعة أكتر مما هو فعلاً قادر
            // يبيعه أو يرجّعه سليم.
            'damaged_in_units' => (int) $custody->items->sum(fn ($i) => (int) ($i->damaged_in ?? 0)),
            // ⚠️⚠️ **الفلترة دي هي اللي بتمنع بيع الدرافت** (١٧/٨).
            // الحمولة دي هي **كتالوج شاشة البيع** في الأبلكيشن —
            // بتتبني من `custody_items` مش من جدول المنتجات، فكل
            // فلاتر `active` اللي في الشاشات التانية ماكانتش بتمرّ
            // من هنا خالص. صنف درافت دخل عربية مندوب كان بيتباع
            // عادي بسعره وضريبته (أودِت ١٧/٨).
            //
            // ⚠️ **بتتشال من العرض بس، مش من الأرصدة فوق.** لو
            // شِلناها من `total_in`/`remaining` كمان، معادلة العهدة
            // (محمَّل = مباع + مرجَّع + الباقي) كانت هتقع، والمندوب
            // بيتحاسب على بضاعة في عربيته مش شايفها. المطلوب إنه
            // مايبيعهاش — مش إننا ننكر إنها معاه.
            'items' => $custody->items
                ->filter(fn ($i) => $i->product?->isSellable())
                ->map(fn ($i) => [
                'product_id' => $i->product_id,
                'code' => $i->product->code,
                'name' => $i->product->displayName(),
                // ⚠️ الاسمين الاتنين — البحث في شاشة البيع بيلاقي
                // «برو» عربي أو إنجليزي مهما كانت لغة الواجهة
                'name_ar' => $i->product->name,
                'name_en' => $i->product->name_en,
                'image' => $i->product->imageSrc(),
                'unit' => $i->product->unitLabel(),
                // تدريج الوحدات — الأبلكيشن بيعرض ويبعت اسم الوحدة،
                // والضرب للقطع بيحصل هنا في السيرفر وقت البيع/المرتجع
                'box_units' => (int) $i->product->box_units,
                'case_units' => (int) $i->product->units_per_case,
                // ⚠️ **ده سعر قائمة استرشادي، مش سعر العميل** (تدقيق
                // ٨/٨/٢٠٢٦). العهدة مش مرتبطة بعميل، فالقائمة هنا
                // بتتحدد من رول المندوب — والفاتورة بتتحسب من
                // `Pricing::quote($client, …)` بقايمة العميل وخصمه.
                // الاتنين كانوا بيختلفوا والمندوب بيقول للعميل رقم
                // غير اللي بيطلع في الفاتورة.
                //
                // الحل: شاشة البيع بتنده `GET /api/clients/{c}/prices`
                // أول ما يختار العميل، وبتستبدل الرقم ده بسعره هو.
                'price' => (float) $i->product->priceFor($mode),
                'assigned' => $i->assigned,
                'sold' => $i->sold,
                'remaining' => $i->remaining(),
                // مرتجع العملاء — بضاعة راجعة في العربية، **مش للبيع**
                'returned_in' => (int) ($i->returned_in ?? 0),
                'damaged_in' => (int) ($i->damaged_in ?? 0),
                // ⚠️ الأبلكيشن محتاج يعرض الضريبة **قبل** ما يحفظ —
                // المندوب بيقول للعميل الرقم وبيحصّله. عرض الصافي
                // معناه إنه بيحصّل ناقص قيمة الضريبة في كل بيعة.
                'taxable' => (bool) ($i->product->taxable ?? true),
                'tax_rate' => round((float) ($i->product->tax_rate ?? 0), 4),
                // ═══ تقسيمة الباتشات (١٩/٨) ═══ الصف الواحد في
                // custody_items = باتش واحد أصلاً — بنبعت هويته عشان
                // شاشة العهدة تعرض «معايا إيه من أنهي تشغيلة وصلاحيتها
                // إمتى» لما المندوب يدوس على الصنف.
                'batch' => $i->batchLabel(),
                'expires' => $i->batch?->expires_on?->toDateString(),
                'days_left' => $i->batch?->daysLeft(),
                // العائلة (١٩/٨) — شاشة العهدة بقت بتجمع بالفاملي
                // وبترتب المنتجات كل عيلة ورا بعضها
                'family' => (string) ($i->product->family ?? ''),
                'family_label' => $i->product->familyLabel(),
                // هدايا وعينات الصنف (٢٠/٨) — كارت «أرقام النهاردة»
                'gifted' => (int) ($i->gift_given ?? 0),
            ])->values(),
        ];
    }

    public static function zonesPayload($user): array
    {
        // ═══ المدير الميداني (١١ أغسطس ٢٠٢٦): عملاؤه = المتسكّنين له ═══
        // نفس مرساة `ownsClient` و`Client::visibleTo` — `manager_id`
        // مش `rep_id`. المندوب فاضل زي ما هو بالحرف.
        $isManager = $user->role === 'manager';

        // ⚠️ **مناطقه هو وبس.** كانت بترجّع كل مناطق الشركة بكل
        // عملائها — مندوب المعادي كان بيشوف عملاء الإسكندرية بأرصدتهم
        // وخصومهم. المناطق من شاشة التوزيع (`zone_user`)، ولو لسه
        // ماتوزّعش بياخد منطقته الأساسية (`zone_id`) لحد ما يتسكّن.
        $zoneIds = $user->zones()->pluck('zones.id');

        if ($zoneIds->isEmpty() && $user->zone_id) {
            $zoneIds = collect([$user->zone_id]);
        }

        // ⚠️ **مناطق عملائه كمان، مش التسكين بس.** المدير ساعات بيسكّن
        // العملاء (rep_id) من غير ما يعلّم على تشيك بوكس المناطق —
        // فالمندوب كان بيفتح «المناطق» يلاقيها فاضية وعملاؤه موجودين
        // فعلاً. أي منطقة فيها عميل بتاعه هي منطقته بحكم الواقع.
        //
        // ═══ بول الفريق (١١/٨ مساءً) ═══ «عملاؤه» للمندوب بقت
        // `Client::poolWhere`: عملاءه هو + كل عملاء مديره — مندوب «ب»
        // بيشوف مناطق وعملاء زميله الغايب من غير أي نقل يدوي.
        $clientZoneIds = ($isManager
            ? Client::where('manager_id', $user->id)
            : Client::poolWhere(Client::query(), $user))
            ->where('status', 'active')
            ->whereNotNull('zone_id')
            ->distinct()->pluck('zone_id');

        $zoneIds = $zoneIds->merge($clientZoneIds)->unique()->values();

        $zones = Zone::with([
            'clients' => function ($q) use ($user, $isManager) {
                // ⚠️ contract و group.contract ضروريين: effectiveDiscount()
                // بتنادي liveContract() لكل عميل. من غيرهم ~300 كويري زيادة
                // على /api/home وهو أكتر إندبوينت بيتنادى في الأبلكيشن.
                $q->where('status', 'active')
                    ->with(['channel', 'contract', 'group.contract'])
                    ->orderBy('name');

                // المدير: عملاؤه المتسكّنين له وبس — مفيش فولباك يتامى
                // هنا، اليتيم أصلاً مالوش `manager_id` فمش بتاعه.
                if ($isManager) {
                    $q->where('manager_id', $user->id);

                    return;
                }

                // ⚠️ **بول الفريق** (١١/٨ مساءً): عملاءه هو + كل عملاء
                // مديره (مهما كانت قناتهم) — البول المشترك، واللي لسه
                // من غير مندوب — دول بس بيتفلتروا بقناته لو ليه قناة.
                // مندوب من غير مدير = السلوك القديم بالحرف.
                $q->where(function ($w) use ($user) {
                    $w->where('rep_id', $user->id);

                    if ($user->manager_id !== null) {
                        $w->orWhere('manager_id', $user->manager_id);
                    }

                    $w->orWhere(function ($w2) use ($user) {
                        $w2->whereNull('rep_id');
                        if ($user->channel_id) {
                            $w2->where('channel_id', $user->channel_id);
                        }
                        // ⚠️ عميل بلا مندوب بس متسكّن لمدير **تاني** =
                        // بول فريق تاني — الفصل بين الفرق هو القاعدة.
                        $w2->where(fn ($w3) => $w3->whereNull('manager_id')
                            ->when($user->manager_id !== null,
                                fn ($q3) => $q3->orWhere('manager_id', $user->manager_id)));
                    });
                });
            },
        ])->whereIn('id', $zoneIds)->where('active', true)->orderBy('code')->get();

        $todayVisits = Visit::where('user_id', $user->id)
            ->whereDate('created_at', today())->get()->keyBy('client_id');

        // ═══ آخر زيارة لكل عميل (١٥ أغسطس) ═══
        //
        // طلب المالك: كارت العميل يقول «آخر زيارة يوم كام». الرقم ده
        // بيقول للمندوب مين مهمَل من غير ما يفتح حد.
        //
        // ⚠️ **كويري واحدة مجمّعة لكل العملاء** — لا علاقة `latestVisit`
        // على الموديل (بتعمل كويري لكل عميل = N+1 على ٢٠٠ عميل)، ولا
        // تحميل كل الزيارات في الذاكرة.
        //
        // ⚠️ **من كل المناديب مش منه هو** — العميل في البول المشترك
        // ممكن يكون زميله زاره امبارح، ولو حسبنا زياراته هو بس
        // الكارت هيقول «من ٣٠ يوم» والمحل اتزار من يومين.
        $clientIds = $zones->flatMap(fn ($z) => $z->clients->pluck('id'))->unique();

        $lastVisits = $clientIds->isEmpty()
            ? collect()
            : Visit::whereIn('client_id', $clientIds)
                ->whereNotNull('checked_in_at')
                ->selectRaw('client_id, MAX(checked_in_at) as last_at')
                ->groupBy('client_id')
                ->pluck('last_at', 'client_id');

        // ⚠️ **المناطق اللي فيها شغل ليه بس** (قرار المالك 2026-08-03).
        // التسكين ممكن يكون على ٢٠ منطقة والعملاء في ٤ — عرض الفاضي
        // زحمة بلا فايدة. أول ما يتسكن عليه عميل في منطقة هتظهر لوحدها.
        $zones = $zones->filter(fn ($z) => $z->clients->isNotEmpty())->values();

        return $zones->map(fn ($z) => [
            'id' => $z->id,
            'code' => $z->code,
            'name' => $z->displayName(),
            'day' => $z->day_label,
            'is_today' => $user->zone_id === $z->id,
            // ⚠️ additive (١١/٨ مساءً) — بادج «كام عميل» على كارت
            // المنطقة في الأبلكيشن. النسخ القديمة بتتجاهله عادي.
            'client_count' => $z->clients->count(),
            // ⚠️ **المحافظة** (١٥/٨) — الأبلكيشن بقى بيجمّع المناطق
            // تحت محافظاتها بدل ليست مسطّحة كانت بتخلط المستويين
            // («القاهرة» و«الدقي» جنب بعض وهي جواها). الكود للتجميع
            // واللابل للعرض.
            'gov' => $z->governorate,
            'gov_label' => $z->governorateLabel(),
            'clients' => $z->clients->map(function ($c) use ($todayVisits, $lastVisits) {
                $v = $todayVisits->get($c->id);

                // ⚠️ الاسم الكامل «السلسلة — الفرع» زي الـERP بالظبط —
                // «Katameya Heights» لوحدها ماتقولش إنه فرع جورميه.
                // والسلسلة والفرع مفصولين كمان للشاشات اللي بتعرضهم
                // سطرين (زي اختيار مستلم الهدية).
                $chain = $c->group?->displayName();
                $chain = ($chain && $chain !== $c->displayName()) ? $chain : null;

                return [
                    'id' => $c->id,
                    'name' => $c->fullName(),
                    'chain' => $chain,
                    'branch' => $c->displayName(),
                    // آخر زيارة — ISO أو null لو العميل ماتزارش قبل كده
                    'last_visit_at' => ($lv = $lastVisits->get($c->id)) !== null
                        ? \Illuminate\Support\Carbon::parse($lv)->toIso8601String()
                        : null,
                    // ⚠️ كتلة البحث عابرة اللغات (١١/٨): الاسم المعروض
                    // بلغة الأبلكيشن بس — والمندوب بيكتب بأي لغة.
                    // بنبعت كل الأسماء (فرع+سلسلة عربي وإنجليزي) في
                    // خانة واحدة صغيرة الأبلكيشن بيبحث فيها.
                    'q' => mb_strtolower(trim(implode(' ', array_filter([
                        $c->name, $c->name_en,
                        $c->group?->name, $c->group?->name_en,
                    ])))),
                    'address' => $c->address,
                    'phone' => $c->phone,
                    // لوكيشن العميل — زرار «الاتجاهات» في الأبلكيشن
                    'lat' => $c->lat === null ? null : (float) $c->lat,
                    'lng' => $c->lng === null ? null : (float) $c->lng,
                    'location_url' => $c->location_url,
                    // ⚠️ **اللوكيشن اتأكّد من الداشبورد** (طلب المالك
                    // ١٦/٨: «أنا أعمل كونفيرم عليه في الداش بورد،
                    // الزرار ده مايظهرش تاني»). زرار «عدّل لوكيشن
                    // العميل» بيختفي من الأبلكيشن أول ما يترفع هنا.
                    //
                    // ⚠️ **الوجود مش الفراغ**: عميل ليه إحداثيات
                    // مش معناه إن حد أكّدها — ممكن تكون من جيوكودينج
                    // تقريبي على نص عنوان. البصمة دي بتتكتب لما بني
                    // آدم يراجع النقطة، وهي وحدها اللي بتخفي الزرار.
                    'location_confirmed' => $c->location_confirmed_at !== null,
                    'category' => $c->category,
                    'category_label' => $c->categoryLabel(),
                    'balance' => (float) $c->balance,
                    'purchases' => (float) $c->purchases,
                    'discount' => $c->effectiveDiscount(),
                    'discount_source' => $c->discountSource(),
                    'channel' => $c->channel?->displayName(),
                    // ⚠️ **المنطقة والمحافظة للفاتورة اللي بتتبعت للعميل**
                    // (2026-08-08). العنوان لوحده «7 شارع 9» مالوش معنى
                    // على ورقة بتتبعت واتساب — العميل لازم يشوف الفرع
                    // اللي الفاتورة دي بتاعته.
                    'zone' => $c->zone?->displayName(),
                    'governorate' => $c->governorateLabel(),
                    'cash_only' => $c->cashOnly(),
                    // كاش/آجل — قرار الأدمن؛ الأبلكيشن بيعرضها ومابيسألش
                    'payment_terms' => $c->paymentTerms(),
                    // ⚠️ **الاستثناء الوحيد لقاعدة «المندوب مابيختارش».**
                    // `true` بس لما الإدارة تختار «الاتنين» على العميل —
                    // وساعتها بس الأبلكيشن بيوري سويتش. أي عميل تاني
                    // الشاشة مافيهاش اختيار خالص، فمفيش فرصة يغلط.
                    'payment_choice' => $c->paymentIsChoice(),
                    'payment_days' => $c->paymentDays(),
                    'is_new' => $c->is_new,
                    'taxable' => (bool) $c->taxable,
                    'tax_rate' => \App\Services\Tax::rate($c),
                    'tax_on' => \App\Services\Tax::enabled(),
                    'visit_status' => $v === null ? 'pending' : ($v->isOpen() ? 'in_visit' : 'done'),
                    'visit_id' => $v?->id,
                    'checked_in_at' => $v?->checked_in_at?->toIso8601String(),
                    'checked_out_at' => $v?->checked_out_at?->toIso8601String(),
                ];
            })->values(),
        ])->values()->all();
    }

    public static function posPayload($user): array
    {
        return PurchaseOrder::with(['client', 'items.product'])
            ->where('assigned_to', $user->id)
            ->whereIn('status', ['pending', 'arrived', 'delivered'])
            // ⚠️ **أمر الموافقة مايظهرش غير معتمد.** pending موافقة =
            // الحسابات ممكن ترفضه — المندوب مايشوفوش أصلاً.
            // null = الفلو القديم (سواق/ريفيل) زي ما هو.
            ->where(fn ($q) => $q->whereNull('approval_status')->orWhere('approval_status', 'approved'))
            // أوامر الكي أكاونت ليها معاد مستقبلي — مانقصرش عليها الـ3 أيام
            ->where(fn ($q) => $q->whereDate('created_at', '>=', today()->subDays(3))
                ->orWhere(fn ($qq) => $qq->whereNotNull('due_at')->where('status', '!=', 'delivered')))
            ->latest()->get()->map(fn ($po) => [
                'id' => $po->id,
                'number' => $po->number,
                'client' => $po->client->fullName(),
                'source' => $po->sourceLabel(),
                // ⚠️ علم ثابت — الأبلكيشن كان بيقارن `source` (نص حر
                // مترجم) بنص مترجم تاني عشان يلوّن الكارت (تدقيق ٩/٨)
                'is_replenishment' => $po->fromReplenishment(),
                'address' => $po->address,
                'status' => $po->status,
                'status_label' => $po->statusLabel(),
                // ⚠️ الرقم اللي السواق بيوريه للعميل ويحصّله — شامل الضريبة
                'total' => $po->payable(),
                'net_total' => (float) $po->total,
                'tax_total' => (float) $po->tax_total,
                'qty_total' => $po->qtyTotal(),
                'arrived_at' => $po->arrived_at?->toIso8601String(),
                'delivered_at' => $po->delivered_at?->toIso8601String(),
                // معاد التوريد بالساعة + متأخر — لأوامر الكي أكاونت
                'due_at' => $po->due_at?->toIso8601String(),
                'late' => $po->isLate(),
                'delivered_qty_total' => (int) $po->deliveredQtyTotal(),
                // ⚠️ **صورة الـPO الحقيقي بتاع الشركة** (طلب المالك
                // ٨/٨/٢٠٢٦). التشانل مانجر بيرفعها وقت الإنشاء،
                // والمندوب بيفتحها برفيو عند العميل عشان يطابق —
                // الفرع بيقارن بورقته هو مش بشاشة الأبلكيشن.
                'image' => $po->imageUrl(),
                // مرجع السلسلة اللي جه في الشيت — بيتكتب على الكارت
                'reference' => $po->sheet_name,
                'client_address' => $po->client->displayAddress(),
                'client_phone' => $po->client->phone,
                'lat' => $po->client->lat === null ? null : (float) $po->client->lat,
                'lng' => $po->client->lng === null ? null : (float) $po->client->lng,
                'items' => $po->items->map(fn ($i) => [
                    'item_id' => $i->id,
                    'product_id' => $i->product_id,
                    'name' => $i->product->displayName(),
                    'unit' => $i->product->unitLabel(),
                    'image' => $i->product->imageSrc(),
                    'qty' => $i->qty,
                    'delivered_qty' => (int) $i->delivered_qty,
                    // تدريج الوحدات — عشان المندوب يعدل «9 كراتين» وقت التسليم
                    'box_units' => (int) $i->product->box_units,
                    'case_units' => (int) $i->product->units_per_case,
                    'price' => (float) $i->price,
                    'total' => (float) $i->total,
                ])->values(),
            ])->values()->all();
    }

    /**
     * خطة سير النهارده.
     *
     * ⚠️ الترتيب من `sort` — الشاشة بتوري العملاء بالترتيب ده والمندوب
     * بيمشي عليه، فأي إعادة ترتيب في الـ ERP لازم توصله زي ما هي.
     */
    public static function journeyPayload($user): array
    {
        // ⚠️ مرة واحدة — `summary()` كانت بتعيد حساب نفس الخطة،
        // فكل `/api/bootstrap` كان بيعمل الشغل مرتين على شبكة موبايل.
        $rows = \App\Services\Journeys::forDay($user);

        // ⚠️ الخصم بيتبعت مع المحطة — من غيره العميل اللي مش في زونز
        // المندوب (المدير حاطه في الخطة) كان بيتسعّر في الشاشة بصفر
        // خصم، والمندوب يقول للعميل رقم أعلى من الفاتورة الفعلية.
        // `loadMissing` دفعة واحدة — مش كويريين لكل محطة.
        \Illuminate\Database\Eloquent\Collection::make($rows->pluck('client'))
            ->unique('id')->values()->loadMissing(['contract', 'group.contract']);

        // آخر زيارة لكل عميل — كويري واحد مجمّع مش كويري لكل محطة
        $lastVisits = Visit::whereIn('client_id', $rows->pluck('client')->pluck('id'))
            ->selectRaw('client_id, MAX(checked_in_at) as t')
            ->groupBy('client_id')
            ->pluck('t', 'client_id');

        $done = $rows->where('status', 'done')->count();
        $planned = $rows->count();

        return [
            'summary' => [
                'planned' => $planned,
                'done' => $done,
                'in_visit' => $rows->where('status', 'in_visit')->count(),
                'pending' => $rows->where('status', 'pending')->count(),
                'off_plan' => \App\Services\Journeys::offPlan($user, null, $rows)->count(),
                'pct' => $planned > 0 ? round($done / $planned * 100, 1) : 0.0,
            ],
            'stops' => $rows->map(fn ($r) => [
                'plan_id' => $r['plan']->id,
                'client_id' => $r['client']->id,
                // الاسم الكامل — السلسلة الأول وبعدين الفرع
                'name' => $r['client']->fullName(),
                'address' => $r['client']->address,
                'phone' => $r['client']->phone,
                'lat' => $r['client']->lat !== null ? (float) $r['client']->lat : null,
                'lng' => $r['client']->lng !== null ? (float) $r['client']->lng : null,
                'location_url' => $r['client']->location_url,
                // ⚠️ **لازم هنا كمان زي حمولة الزونز.** المحطة دي
                // بتتحوّل لـ`Client` لما المندوب يفتحها من خط السير،
                // ولو الحقل ناقص نفس العميل بيوري زرار «عدّل
                // اللوكيشن» من شاشة ويخبّيه من التانية.
                'location_confirmed' => $r['client']->location_confirmed_at !== null,
                'balance' => (float) $r['client']->balance,
                'cash_only' => $r['client']->cashOnly(),
                'payment_terms' => $r['client']->paymentTerms(),
                'payment_choice' => $r['client']->paymentIsChoice(),
                'payment_days' => $r['client']->paymentDays(),
                'discount' => $r['client']->effectiveDiscount(),
                // كارت المحطة بيوري تاريخ العميل: مبيعاته وآخر مرة اتزار
                'purchases' => (float) $r['client']->purchases,
                'last_visit_at' => $lastVisits->get($r['client']->id),
                'taxable' => (bool) $r['client']->taxable,
                'tax_rate' => \App\Services\Tax::rate($r['client']),
                'category' => $r['client']->category,
                'category_label' => $r['client']->categoryLabel(),
                'status' => $r['status'],
                'visit_id' => $r['visit']?->id,
                'sort' => $r['sort'],
                // ⚠️ مفتاح **إضافي** (١٣ أغسطس ٢٠٢٦) — وقت الزيارة
                // المتفق عليه جاهز للعرض `h:i A`، و`null` للخطط اللي
                // مالهاش وقت. الأبلكيشن القديم بيتجاهله والجديد بيوريه.
                'visit_at' => $r['plan']->visitTimeLabel() ?: null,
            ])->values()->all(),
        ];
    }

    /** GET /api/journey — خطة السير لوحدها (للريفريش) */
    public function journey(Request $request): JsonResponse
    {
        return response()->json(self::journeyPayload($request->user()));
    }

    private function todayPayload($user): array
    {
        return [
            // ⚠️ **`grand_total` مش `total`** (تدقيق ٩/٨ مساءً): الكارت ده
            // بيفتح شاشة «مبيعات اليوم» اللي بتجمع بالإجمالي الشامل —
            // فالرقمين كانوا بيختلفوا قدام المندوب بقيمة الضريبة.
            'sales' => (float) Invoice::where('user_id', $user->id)->whereDate('created_at', today())->sum('grand_total'),
            'invoices' => Invoice::where('user_id', $user->id)->whereDate('created_at', today())->count(),
            'visits' => Visit::where('user_id', $user->id)->whereDate('created_at', today())->count(),
            'visits_done' => Visit::where('user_id', $user->id)->whereDate('created_at', today())
                ->whereNotNull('checked_out_at')->count(),
            'pos_delivered' => PurchaseOrder::where('assigned_to', $user->id)
                ->where('status', 'delivered')->whereDate('delivered_at', today())->count(),
            'pos_value' => (float) PurchaseOrder::where('assigned_to', $user->id)
                ->where('status', 'delivered')->whereDate('delivered_at', today())->sum('grand_total'),
        ];
    }

    private function eventsPayload($user): array
    {
        return TrackEvent::where('user_id', $user->id)
            ->whereDate('happened_at', today())
            ->orderBy('happened_at')->get()->map(fn ($e) => [
                'type' => $e->type,
                'title' => $e->title,
                'subtitle' => $e->subtitle,
                'lat' => (float) $e->lat,
                'lng' => (float) $e->lng,
                'time' => $e->happened_at->toIso8601String(),
            ])->values()->all();
    }

    // ================= الزيارات =================

    /**
     * ═══════════════════════════════════════════════════════════
     * مرساة العلاقة — «العميل ده بتاع المندوب ده؟»
     * ═══════════════════════════════════════════════════════════
     *
     * ⚠️ **الفحص ده كان ناقص خالص** (تدقيق ٨/٨/٢٠٢٦): `exists:clients,id`
     * كانت بتخلّي **أي** توكن ميداني يفتح زيارة — ويفوتر — على **أي**
     * عميل في الداتابيز. مندوب ماتسكّنش على العميل كان يقدر يخصم من
     * عهدته ويمدّن حساب عميل مندوب تاني.
     *
     * القبول: العميل مسكّن عليه (`rep_id`)، **أو جوه بول فريقه**
     * (نفس `manager_id` بتاع مديره — قرار المالك ١١/٨ مساءً)، **أو**
     * في زون من زوناته، **أو** عنده عليه أمر توريد (السواق بيوصّل
     * لعملاء مش بتوعه).
     *
     * ⚠️ **مش بنشدّد أكتر من كده.** المندوب الجديد اللي لسه مااتسكّنش
     * له عملاء بيشتغل بالزون، وقفل الزون كان هيمنعه يفتح أي زيارة.
     *
     * ⚠️ المرساة دي بتغطي كل أكشنات الزيارة مرة واحدة: التشيك إن هنا،
     * والفاتورة/المرتجع/التحصيل/الرف/طلب البضاعة مرساتهم الزيارة
     * المفتوحة نفسها — فقبول البول هنا بيفتح السايكل كله لزميل الفريق.
     */
    private function ownsClient(User $user, Client $client): bool
    {
        // بيغطي rep_id **وبول الفريق** مع بعض — مندوب بلا مدير بيرجع
        // لفحص rep_id القديم بالحرف جوه `inPoolOf`.
        if ($client->inPoolOf($user)) {
            return true;
        }

        // ═══ المدير الميداني (١١ أغسطس ٢٠٢٦): عملاؤه = المتسكّنين له ═══
        // نفس مرساة `Client::visibleTo` بالظبط (`clients.manager_id`) —
        // المدير بيتشيك إن على عملاء فريقه اللي هو مسؤول عنهم،
        // مش أي عميل في الشركة.
        if ($user->role === 'manager' && (int) $client->manager_id === (int) $user->id) {
            return true;
        }

        $zoneIds = $user->zones->pluck('id')->all();

        if ($user->zone_id !== null) {
            $zoneIds[] = (int) $user->zone_id;
        }

        if ($client->zone_id !== null && in_array((int) $client->zone_id, array_map('intval', $zoneIds), true)) {
            return true;
        }

        return \App\Models\PurchaseOrder::where('assigned_to', $user->id)
            ->where('client_id', $client->id)
            ->whereIn('status', ['pending', 'arrived', 'delivered'])
            ->exists();
    }

    /** بيرجّع رد 403 جاهز لو العميل مش بتاع المندوب، وإلا `null` */
    private function guardClient(User $user, Client $client): ?JsonResponse
    {
        return $this->ownsClient($user, $client)
            ? null
            : response()->json(['message' => __('api.not_your_client')], 403);
    }

    /** POST /api/visits/check-in { client_id, lat, lng } */
    public function checkIn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
        ]);

        $user = $request->user();

        if ($open = $user->openVisit()) {
            return response()->json([
                'message' => __('field.must_check_out_first', ['client' => $open->client->displayName()]),
            ], 422);
        }

        $client = Client::findOrFail($data['client_id']);

        if ($err = $this->guardClient($user, $client)) {
            return $err;
        }

        // ⚠️ الزيارة بتتربط بخطة اليوم لو العميل ده فيها. من غير
        // الربط ده الشاشة اللايف مش هتفرّق بين زيارة من الخطة وزيارة
        // بره الخطة، ونسبة الإنجاز هتبقى رقم بلا معنى.
        $plan = \App\Models\JourneyPlan::where('user_id', $user->id)
            ->where('client_id', $client->id)
            ->where('weekday', today()->dayOfWeek)
            ->where('active', true)
            ->value('id');

        $visit = Visit::create([
            'user_id' => $user->id,
            'client_id' => $client->id,
            'journey_plan_id' => $plan,
            'checked_in_at' => now(),
            'lat' => $this->egyptPoint($data)[0],
            'lng' => $this->egyptPoint($data)[1],
        ]);

        [$evLat, $evLng] = $this->eventPoint($data, $client);
        TrackEvent::log($user, 'check_in',
            __('field.event_check_in', ['client' => $client->displayName()]), $client->address,
            $evLat, $evLng);

        return response()->json(['visit_id' => $visit->id, 'checked_in_at' => $visit->checked_in_at->toIso8601String()]);
    }

    /**
     * GET /api/clients/{client}/prices — أسعار العميل للأصناف اللي في عهدته.
     *
     * ⚠️ **الإندبوينت ده موجود عشان الرقم اللي المندوب بيقوله للعميل
     * يبقى هو الرقم اللي هيطلع في الفاتورة** (تدقيق ٨/٨/٢٠٢٦). قبله
     * شاشة البيع كانت بتعرض سعر قائمة مشتق من رول المندوب، والفاتورة
     * بتتحسب من قائمة العميل وخصمه — والفرق بيبان بعد ما العميل يوافق.
     *
     * ⚠️ **من `Pricing::quote` بالظبط زي `storeInvoice`** — ممنوع أي
     * حساب سعر تاني هنا، وإلا رجعنا لنفس الباج من الباب التاني.
     */
    public function clientPrices(Request $request, Client $client): JsonResponse
    {
        $user = $request->user();

        if ($err = $this->guardClient($user, $client)) {
            return $err;
        }

        $custody = $user->currentCustody();
        $custody?->load('items.product', 'items.batch');

        $rows = [];

        foreach ($custody?->items ?? [] as $it) {
            // ⚠️ **`isSellable` هنا كمان** (١٧/٨) — الإندبوينت ده
            // بيسعّر أصناف العهدة للعميل وقت الزيارة. من غيره الصنف
            // الدرافت كان بياخد سعر عميل كامل حتى بعد ما اختفى من
            // كتالوج البيع، والأبلكيشن القديم اللي لسه شايفه بيبيعه
            // بسعر رسمي.
            if ($it->product === null
                || ! $it->product->isSellable()
                || isset($rows[$it->product_id])) {
                continue;
            }

            $q = \App\Services\Pricing::quote($client, $it->product, $it->batch, 1);

            $rows[$it->product_id] = [
                'product_id' => $it->product_id,
                'list_price' => (float) $q['list_price'],
                // ⚠️ **مخصوم أصلاً** — الأبلكيشن بيعرضه زي ما هو
                // وممنوع يخصم عليه تاني.
                'price' => (float) $q['unit_price'],
                'tax_rate' => round((float) \App\Services\Tax::rate($client, $it->product), 4),
            ];
        }

        return response()->json([
            'client_id' => $client->id,
            'discount' => (float) $client->effectiveDiscount(),
            'discount_source' => $client->discountSource(),
            'price_list' => $client->priceList(),
            'items' => array_values($rows),
        ]);
    }

    /** POST /api/visits/{visit}/check-out */
    public function checkOut(Request $request, Visit $visit): JsonResponse
    {
        if ($visit->user_id !== $request->user()->id) {
            return response()->json(['message' => __('api.not_your_visit')], 403);
        }
        if (! $visit->isOpen()) {
            return response()->json(['message' => __('field.visit_already_closed')], 422);
        }

        $visit->update(['checked_out_at' => now()]);

        TrackEvent::log($request->user(), 'check_out',
            __('field.event_check_out', ['client' => $visit->client->displayName()]),
            __('field.event_visit_minutes', ['minutes' => $visit->minutes()]),
            $visit->lat ?? $visit->client->lat, $visit->lng ?? $visit->client->lng);

        return response()->json(['minutes' => $visit->minutes()]);
    }

    // ═══════════ ٣ أوبشنات الزيارة الجديدة (٩ أغسطس ٢٠٢٦) ═══════════

    /**
     * POST /api/visits/{visit}/collect — تحصيل من العميل أثناء الزيارة.
     * multipart: amount, method, reference?, cheque_bank?, cheque_due?, proof?
     *
     * ⚠️ **الزيارة المفتوحة هي المرساة** — نفس دوكترين الفاتورة
     * والمرتجع بالظبط. قيد `collection` على دفتر عميل من غير زيارة
     * كان معناه إن أي توكن مندوب يقدر «يحصّل» من أي عميل ويصفّر
     * مديونيته على الورق والفلوس ماوصلتش.
     *
     * ⚠️ **صورة الإثبات إجبارية لغير الكاش.** «تحويل 5000» من غير
     * سكرين شوت كلمة — المحاسب في التصفية بيطابق القيد على الصورة.
     *
     * ⚠️ **`source` = الزيارة** — التصفية بتلم تحصيلات المندوب من
     * قيود `collection` اللي مصدرها زياراته (زي `refund` بالظبط).
     */
    public function collect(Request $request, Visit $visit): JsonResponse
    {
        $user = $request->user();

        if ($visit->user_id !== $user->id) {
            return response()->json(['message' => __('api.not_your_visit')], 403);
        }
        if (! $visit->isOpen()) {
            return response()->json(['message' => __('field.visit_already_closed')], 422);
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:10000000'],
            'method' => ['required', \Illuminate\Validation\Rule::in(Transaction::METHODS)],
            // نفس قواعد شاشة التحصيل في الـERP — السيرفر هو اللي بيرفض
            'reference' => ['nullable', 'required_unless:method,cash', 'string', 'max:100'],
            'cheque_bank' => ['nullable', 'required_if:method,cheque', 'string', 'max:120'],
            'cheque_due' => ['nullable', 'required_if:method,cheque', 'date'],
            'proof' => ['nullable', 'required_unless:method,cash', 'file', 'image', 'max:8192'],
            'note' => ['nullable', 'string', 'max:190'],
            'idem_key' => ['nullable', 'string', 'max:64'],
        ], [], [
            'amount' => __('field.attr_collect_amount'),
            'proof' => __('field.attr_collect_proof'),
        ]);

        $client = $visit->client;

        // ⚠️ **نفس درس المرتجعات**: الأبلكيشن بيولّد المفتاح مرة
        // واحدة للشاشة، وإعادة النداء بعد تايم أوت بترجّع نفس القيد
        // بدل ما تصفّر مديونية ماتدفعتش. اليونيك في الداتابيز بيمسك
        // السباق، والفحص هنا بيرجّع رد نضيف بدل خطأ SQL خام.
        if (! empty($data['idem_key'])) {
            $existing = Transaction::where('idem_key', $data['idem_key'])->first();

            if ($existing !== null) {
                return response()->json([
                    'id' => $existing->id,
                    'amount' => (float) $existing->credit,
                    'method' => $existing->method,
                    'balance' => (float) $client->fresh()->balance,
                    'proof_url' => $existing->proofUrl(),
                ]);
            }
        }

        $proofPath = $request->hasFile('proof')
            ? $request->file('proof')->store('collection-proofs', 'public')
            : null;

        $tx = DB::transaction(function () use ($data, $client, $visit, $proofPath) {
            $tx = Transaction::create([
                'client_id' => $client->id,
                'date' => today(),
                'memo' => $data['note'] ?? __('flash.memo_field_collection'),
                'debit' => 0,
                'credit' => round((float) $data['amount'], 2),
                'kind' => 'collection',
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'cheque_bank' => $data['method'] === Transaction::METHOD_CHEQUE
                    ? ($data['cheque_bank'] ?? null) : null,
                'cheque_due' => $data['method'] === Transaction::METHOD_CHEQUE
                    ? ($data['cheque_due'] ?? null) : null,
                'proof_path' => $proofPath,
                'idem_key' => $data['idem_key'] ?? null,
                'source_type' => Visit::class,
                'source_id' => $visit->id,
            ]);

            $client->recalculate();

            return $tx;
        });

        TrackEvent::log($user, 'collect',
            __('field.event_collect', [
                'amount' => number_format((float) $data['amount'], 2),
                'client' => $client->displayName(),
            ]),
            $tx->methodLabel(),
            $visit->lat ?? $client->lat, $visit->lng ?? $client->lng);

        // ⚠️ **غير الكاش بيتبلّغ للمحاسبين فوراً** — الشيك محتاج
        // يتحط في الدرج والتحويل محتاج يتطابق مع البنك. الكاش
        // مستنّي التصفية عادي ومالوش داعي يزن.
        if ($data['method'] !== Transaction::METHOD_CASH) {
            foreach (User::where('role', 'accountant')->where('active', true)->get() as $acc) {
                AppNotification::send(
                    $acc,
                    fn () => __('field.notif_collect_title', [
                        'amount' => number_format((float) $data['amount'], 2),
                    ]),
                    fn () => __('field.notif_collect_body', [
                        'method' => __('client.pay_method_'.$data['method']),
                        'client' => $client->displayName(),
                        'user' => $user->displayName(),
                    ]),
                    link: 'collections',
                );
            }
        }

        return response()->json([
            'id' => $tx->id,
            'amount' => (float) $tx->credit,
            'method' => $tx->method,
            'balance' => (float) $client->fresh()->balance,
            'proof_url' => $tx->proofUrl(),
        ], 201);
    }

    /**
     * POST /api/visits/{visit}/shelf-photo — صورة رف قبل/بعد الترتيب.
     *
     * ⚠️ **متعدد الصور لكل مرحلة** (طلب المالك) — مش زي زيارة
     * البروموتر اللي صورة واحدة لكل مرحلة. كل نداء بيضيف صورة.
     */
    public function shelfPhoto(Request $request, Visit $visit): JsonResponse
    {
        if ($visit->user_id !== $request->user()->id) {
            return response()->json(['message' => __('api.not_your_visit')], 403);
        }
        if (! $visit->isOpen()) {
            return response()->json(['message' => __('field.visit_already_closed')], 422);
        }

        $data = $request->validate([
            'stage' => ['required', 'in:before,after'],
            'photo' => ['required', 'file', 'image', 'max:8192'],
        ], [], ['photo' => __('field.attr_shelf_photo')]);

        $path = $request->file('photo')->store('visit-shelves', 'public');

        $photo = $visit->photos()->create([
            'stage' => $data['stage'],
            'path' => $path,
        ]);

        // أول صورة بس بتتسجل في التايم لاين — مش كل لقطة
        if ($visit->photos()->where('stage', $data['stage'])->count() === 1) {
            TrackEvent::log($request->user(), 'shelf',
                __('field.event_shelf_'.$data['stage'], [
                    'client' => $visit->client->displayName(),
                ]), null,
                $visit->lat ?? $visit->client->lat, $visit->lng ?? $visit->client->lng);
        }

        return response()->json([
            'id' => $photo->id,
            'url' => $photo->url(),
            'stage' => $data['stage'],
            'counts' => [
                'before' => $visit->photos()->where('stage', 'before')->count(),
                'after' => $visit->photos()->where('stage', 'after')->count(),
            ],
        ], 201);
    }

    /**
     * GET /api/clients/{client}/catalog — الكتالوج الكامل بأسعار العميل.
     *
     * ⚠️ **مش `clientPrices`** — دي بترجّع أصناف العهدة بس (شاشة
     * البيع بتبيع من العربية). طلب البضاعة بيطلب من المخزن، فمحتاج
     * الكتالوج كله بوحدات الكرتونة والعلبة عشان يكتب «٢ كرتونة».
     */
    public function catalog(Request $request, Client $client): JsonResponse
    {
        $user = $request->user();

        if ($err = $this->guardClient($user, $client)) {
            return $err;
        }

        $items = Product::where('active', true)->orderBy('name')->get()
            ->map(function (Product $p) use ($client) {
                $q = \App\Services\Pricing::quote($client, $p, null, 1);

                return [
                    'product_id' => $p->id,
                    'name' => $p->displayName(),
                    // ⚠️ **الصورة كانت ناقصة هنا بس** (بلاغ المالك
                    // ١٦/٨). باقي الإندبوينتس بترجّع `imageSrc()`،
                    // فشاشة طلب البضاعة كانت الوحيدة اللي بتوري
                    // أيقونات رمادية — والمندوب بيدوّر على الصنف
                    // بشكله وسط أصناف أسماؤها متشابهة.
                    'image' => $p->imageSrc(),
                    'barcode' => $p->barcode,
                    'price' => (float) $q['unit_price'],
                    'units_per_case' => (int) $p->units_per_case,
                    'box_units' => (int) $p->box_units,
                ];
            })
            // ⚠️ **الصنف الغير متسعّر في قايمة العميل مابيظهرش أصلاً**
            // (تدقيق ٩/٨). لو ظهر بـ0.00، المندوب هيضيفه والرفض
            // هيطلع في وش المدير وقت الموافقة — بعد ما العميل اتوعد.
            ->filter(fn ($row) => $row['price'] > 0)
            ->values()->all();

        return response()->json(['items' => $items]);
    }

    // ═══════════════════════════════════════════════════════════════
    // تاريخ العميل  ·  ١٦ أغسطس ٢٠٢٦
    // ═══════════════════════════════════════════════════════════════
    //
    // طلب المالك: «مربعات كليكابل في صفحة العميل — كل مبيعاته، كل
    // تحصيلاته، كل مرتجعاته، كل هداياه، كل صور ترتيب الأرفف. في
    // المربع السامري، ولما أدوس تطلع ليستة، وأدخل على الليست أشوف
    // التفاصيل».
    //
    // ⚠️ **المندوب واقف قدام العميل والعميل بيقول «أنا دفعتلك
    // الأسبوع اللي فات»** — من غير الشاشة دي مافيش رد غير «هرجعلك».
    // ده مش عرض بيانات، ده إنهاء نقاش في الزيارة.
    //
    // ⚠️ **كله من مصادره الأصلية مش من عدادات محفوظة على العميل.**
    // `clients.purchases/collections/returns` أرقام مجمّعة بتتحدّث
    // بـ`recalculate()`؛ الشاشة دي بتوري **مستندات** والمندوب
    // بيقارنها بورقه. لو قرينا العدّادات كنا هنوري رقم مش وراه ورق.

    /**
     * GET /api/clients/{client}/history
     *
     * سامري المربعات الخمسة — عدد وإجمالي وآخر تاريخ لكل نوع.
     *
     * ⚠️ **استعلام عدّ لكل نوع، مش تحميل الصفوف وعدّها.** العميل
     * القديم عنده مئات الفواتير، وتحميلها كلها عشان نطبع رقم على
     * مربع كان هيقفّل الشاشة على شبكة الشارع.
     */
    public function clientHistory(Request $request, Client $client): JsonResponse
    {
        if ($err = $this->guardClient($request->user(), $client)) {
            return $err;
        }

        $sales = Invoice::where('client_id', $client->id);
        $returns = \App\Models\ClientReturn::where('client_id', $client->id);
        $gifts = \App\Models\GiftHandout::where('client_id', $client->id);

        // ⚠️ **التحصيل قيد دائن نوعه `collection`** — مش أي قيد دائن.
        // المرتجع كمان بيعمل قيد دائن، وخلطهم كان هيعرض المرتجعات
        // مرتين: مرة في مربعها ومرة في التحصيلات.
        $collections = \App\Models\Transaction::where('client_id', $client->id)
            ->where('kind', 'collection');

        // صور الأرفف بتتعلّق بالزيارة مش بالعميل — الربط عن طريقها
        $photos = \App\Models\VisitPhoto::whereHas(
            'visit', fn ($q) => $q->where('client_id', $client->id)
        );

        return response()->json(['summary' => [
            'sales' => [
                'count' => (clone $sales)->count(),
                'total' => (float) (clone $sales)->sum('grand_total'),
                'last_at' => $this->lastAt(clone $sales),
            ],
            'collections' => [
                'count' => (clone $collections)->count(),
                'total' => (float) (clone $collections)->sum('credit'),
                'last_at' => $this->lastAt(clone $collections),
            ],
            'returns' => [
                'count' => (clone $returns)->count(),
                'total' => (float) (clone $returns)->sum('grand_total'),
                'last_at' => $this->lastAt(clone $returns),
            ],
            'gifts' => [
                // ⚠️ الهدايا **بالقطع مش بالفلوس** — مالهاش قيمة في
                // كشف الحساب أصلاً، وعرضها بالجنيه كان هيوحي إنها
                // مديونية.
                'count' => (clone $gifts)->count(),
                'total' => (float) (clone $gifts)->sum('qty'),
                'last_at' => $this->lastAt(clone $gifts),
            ],
            'shelf' => [
                'count' => (clone $photos)->count(),
                'total' => 0.0,
                'last_at' => $this->lastAt(clone $photos),
            ],
        ]]);
    }

    private function lastAt($query): ?string
    {
        $at = $query->max('created_at');

        return $at ? \Illuminate\Support\Carbon::parse($at)->toIso8601String() : null;
    }

    /**
     * GET /api/clients/{client}/history/{type}
     *
     * ليستة نوع واحد بتفاصيله. البنود **جوّه الصف** مش في نداء
     * تاني — المندوب بيفتح الليستة ويدوس على عنصر وهو في الشارع،
     * ونداء لكل عنصر كان معناه انتظار في كل دوسة.
     *
     * ⚠️ **سقف ١٠٠ صف.** مفيش صفحات في الشاشة دي عن قصد: المندوب
     * محتاج «آخر اللي حصل» مش أرشيف كامل، والأرشيف مكانه الداشبورد.
     */
    public function clientHistoryList(Request $request, Client $client, string $type): JsonResponse
    {
        if ($err = $this->guardClient($request->user(), $client)) {
            return $err;
        }

        $rows = match ($type) {
            'sales' => Invoice::with('items.product')
                ->where('client_id', $client->id)
                ->latest()->take(100)->get()
                ->map(fn ($i) => [
                    'id' => $i->id,
                    'title' => $i->number,
                    'amount' => (float) $i->grand_total,
                    'time' => $i->created_at->toIso8601String(),
                    // ⚠️ **القيمة الخام مش نص مترجم.** الأبلكيشن هو
                    // طبقة اللغة (`L.t`) وعنده `cash`/`credit` أصلاً؛
                    // لو السيرفر بعت عربي، المندوب اللي شغّال إنجليزي
                    // كان هيشوف كلمة عربية وسط شاشة إنجليزي.
                    'payment' => $i->payment,
                    'lines' => $i->items->map(fn ($it) => [
                        'name' => $it->product?->displayName() ?? '—',
                        'image' => $it->product?->imageSrc(),
                        'qty' => (int) $it->qty,
                        'total' => (float) $it->total,
                    ])->values(),
                ]),

            'collections' => \App\Models\Transaction::where('client_id', $client->id)
                ->where('kind', 'collection')
                ->latest()->take(100)->get()
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'title' => $t->reference ?: $t->memo ?: '—',
                    'amount' => (float) $t->credit,
                    'time' => $t->created_at->toIso8601String(),
                    // خام — الأبلكيشن بيترجمها
                    'method' => $t->method,
                    // صورة إثبات التحصيل الميداني — الشيك أو التحويل
                    'photo' => $t->proofUrl(),
                    'lines' => [],
                ]),

            'returns' => \App\Models\ClientReturn::with('items.product')
                ->where('client_id', $client->id)
                ->latest()->take(100)->get()
                ->map(fn ($r) => [
                    'id' => $r->id,
                    'title' => $r->number,
                    'amount' => (float) $r->grand_total,
                    'time' => $r->created_at->toIso8601String(),
                    // ⚠️ **دي استثناء مقصود**: `policyLabel()` نص
                    // مترجم من السيرفر لأن سياسات المرتجع بتتعرّف في
                    // الإعدادات ومالهاش مقابل ثابت في ملف لغة
                    // الأبلكيشن. نفس اللي `invoices()` بتعمله.
                    'policy_label' => $r->policyLabel(),
                    'lines' => $r->items->map(fn ($it) => [
                        'name' => $it->product?->displayName() ?? '—',
                        'image' => $it->product?->imageSrc(),
                        'qty' => (int) $it->qty,
                        'total' => (float) $it->total,
                        // سليم ولا تالف — الفرق ده بيحدّد السياسة
                        'condition' => $it->condition,
                    ])->values(),
                ]),

            'gifts' => \App\Models\GiftHandout::with('product')
                ->where('client_id', $client->id)
                ->latest()->take(100)->get()
                ->map(fn ($g) => [
                    'id' => $g->id,
                    'title' => $g->product?->displayName() ?? '—',
                    // ⚠️ **قطع مش جنيه** — الهدية مش قيد على الحساب
                    'qty' => (int) $g->qty,
                    'time' => $g->created_at->toIso8601String(),
                    // ⚠️ **السبب مش اسم المنتج.** `title` هو اسم
                    // الصنف؛ لو بعتناه هنا كمان اللابل الجانبي كان
                    // هيكرّر نفس الكلام اللي فوقه بالظبط.
                    'reason' => $g->reason,
                    'image' => $g->product?->imageSrc(),
                    'lines' => [],
                ]),

            'shelf' => \App\Models\VisitPhoto::with('visit')
                ->whereHas('visit', fn ($q) => $q->where('client_id', $client->id))
                ->latest()->take(100)->get()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    // خام (`before`/`after`) — الأبلكيشن بيترجمها
                    'stage' => $p->stage,
                    'time' => $p->created_at->toIso8601String(),
                    'photo' => $p->url(),
                    'lines' => [],
                ]),

            default => null,
        };

        if ($rows === null) {
            return response()->json(['message' => __('api.bad_request')], 422);
        }

        return response()->json(['type' => $type, 'items' => $rows->values()]);
    }

    // ═══════ لوكيشن العميل من الأبلكيشن (١٤ أغسطس ٢٠٢٦) ═══════
    //
    // ⚠️⚠️ **بلاغ المالك اللي بنى الفيتشر ده**: «كنت عامل حسابي إن
    // التشيك إن بتاع المندوب بيكون قدام المحل فيبقى ده لوكيشن
    // المكان — لكن المندوب بيعمل تشيك إن وهو في الطريق وبيدخل
    // يجهّز الفاتورة».
    //
    // يعني `visits.lat` **مش** لوكيشن المحل: نقطة تشيك إن ممكن تكون
    // من العربية على بعد شارع. الحل مش تحسين التخمين — الحل إن
    // المندوب يسحب نقطة **بقصد** وهو واقف قدام المحل.
    //
    // ⚠️ **الحارس هو نفس مرساة الزيارة** (`guardClient` ← `ownsClient`):
    // عميله أو بول فريقه أو في زوناته أو عنده عليه أمر توريد.
    // من غيرها أي توكن ميداني كان يقدر يعيد كتابة لوكيشن **أي** عميل
    // في الشركة — والعنوان بيتطبع على الفاتورة وبيسكّن المندوب.
    //
    // ⚠️ **مش مربوطين بزيارة مفتوحة عن قصد.** الكارت في الأبلكيشن
    // بيوري «ليس له لوكيشن» على العميل قبل التشيك إن كمان، والمندوب
    // اللي عدّى قدام محل ولاقى نقطته ناقصة لازم يقدر يظبطها.

    /**
     * POST /api/clients/{client}/geocode { lat, lng }
     *
     * بيرجّع اقتراح العنوان والمحافظة والمنطقة من `GeoSuggest` —
     * **نفس الخدمة** اللي شاشة «تأكيد لوكيشن العملاء» في الداشبورد
     * بتناديها (`ClientLocationController::suggest`). جيوكودر واحد،
     * مش اتنين بيقترحوا مناطق مختلفة لنفس النقطة.
     *
     * ⚠️ **بيرجّع 200 حتى لو العنوان مالقيناهوش** — عكس الداشبورد.
     * المندوب في الشارع على شبكة ضعيفة: لو رمينا 422 كانت الشاشة
     * هتبان «فشل» وهو أصلاً معاه النقطة (وهي أهم حاجة) والمنطقة
     * (محسوبة من الداتابيز بتاعتنا مش من الإنترنت). `matched: false`
     * بتخلّي الشاشة تقول «اكتب العنوان بإيدك» بدل ما توقف.
     *
     * ⚠️ **قوايم المحافظات والمناطق بترجع هنا مش في البوت ستراب** —
     * البوت ستراب بيتنده عشرات المرات في اليوم لكل مندوب، والليستة
     * دي ثابتة ومحتاجة شاشة واحدة بس.
     */
    public function geocodeClient(Request $request, Client $client): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $user = $request->user();

        if ($err = $this->guardClient($user, $client)) {
            return $err;
        }

        // ⚠️ **نفس فلتر `egyptPoint`** — الإميوليتر لوكيشنه الافتراضي
        // مقر جوجل في كاليفورنيا، وجيوكودينج نقطة هناك كان هيرجّع
        // عنوان أمريكي ويحطه في خانة عنوان عميل مصري.
        [$lat, $lng] = $this->egyptPoint($data);

        // ⚠️ **القوايم بترجع حتى لما النقطة مرفوضة** (إصلاح ١٥/٨).
        // قبل كده الرد كان `message` بس، فالأبلكيشن مايوصلوش
        // المحافظات ولا المناطق — والمندوب يلاقي الدروب داون فاضية
        // ومالوش أي طريقة يكمّل. حصل حرفياً على الإميوليتر (لوكيشنه
        // كاليفورنيا)، وبيحصل في الواقع لو الـGPS زاغ بره الحدود أو
        // الجيوكودينج فشل. القوايم دي **ثابتة ومالهاش علاقة بالنقطة**،
        // فحجبها كان عقاب بلا سبب.
        $lists = [
            'governorates' => \App\Support\GeoSuggest::governorateOptions(),
            'zones' => \App\Support\GeoSuggest::zoneOptions(),
        ];

        if ($lat === null) {
            return response()->json(['message' => __('geo.bad_point')] + $lists, 422);
        }

        return response()->json(\App\Support\GeoSuggest::forPoint($lat, $lng) + $lists);
    }

    /**
     * GET /api/geo/options
     *
     * المحافظات والمناطق لوحدها — من غير نقطة.
     *
     * ⚠️ شاشة لوكيشن العميل كانت بتستنى سحب GPS ناجح عشان تملأ
     * الدروب داون، فالمندوب مايقدرش يختار محافظة يدوي قبل ما يسحب
     * — ولا خالص لو السحب فشل. القوايم دي داتا مرجعية ثابتة،
     * فالشاشة بتحمّلها أول ما تفتح.
     */
    public function geoOptions(Request $request)
    {
        return response()->json([
            'governorates' => \App\Support\GeoSuggest::governorateOptions(),
            'zones' => \App\Support\GeoSuggest::zoneOptions(),
        ]);
    }

    /**
     * POST /api/geo/suggest { lat, lng }
     *
     * زي `geocodeClient` بالحرف بس **من غير عميل** — لشاشة تسجيل
     * العميل الجديد (٢٠/٨): المندوب واقف قدام المحل، بيسحب النقطة،
     * والعنوان العربي والمحافظة والمنطقة بيتعبّوا قبل ما الطلب
     * يتبعت أصلاً — فالمدير وقت الاعتماد يدوب يظبط التسعير ويعتمد.
     *
     * ⚠️ نفس فلسفة `geocodeClient`: 200 حتى لو العنوان مالقيناهوش
     * (`matched: false`)، والقوايم بترجع دايماً حتى مع نقطة مرفوضة.
     */
    public function geoSuggest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        [$lat, $lng] = $this->egyptPoint($data);

        $lists = [
            'governorates' => \App\Support\GeoSuggest::governorateOptions(),
            'zones' => \App\Support\GeoSuggest::zoneOptions(),
        ];

        if ($lat === null) {
            return response()->json(['message' => __('geo.bad_point')] + $lists, 422);
        }

        return response()->json(\App\Support\GeoSuggest::forPoint($lat, $lng) + $lists);
    }

    /**
     * POST /api/clients/{client}/location
     * { lat, lng, address?, address_ar?, governorate?, zone_id? }
     *
     * المندوب سحب النقطة وصحّح العنوان → بتتكتب على العميل بمصدر
     * `rep_app` وبصمة `location_confirmed_at/by`.
     *
     * ⚠️ **بتتعامل كـ«تأكيد» كامل مش كاقتراح** (قرار المالك ١٤/٨):
     * «وخلّي صفحة تأكيد اللوكيشن في الداشبورد تبقى هي الصفحة الصح».
     * يعني العميل ده **بيخرج فوراً** من «جاهز للتأكيد» في
     * `ClientLocationController::index` (كل فلاترها `whereNull
     * ('location_confirmed_at')`) وبيدخل «المتأكدة» ببصمة كاملة —
     * والأدمن لسه بيقدر يصحّح بزرار «تعديل».
     *
     * ⚠️ **إعادة الإرسال بتكتب فوق** — مفيش `idem_key` هنا عن قصد.
     * ده مش قيد مالي بيتكرر؛ ده عمود بيتحدّث. إرسال نفس النقطة
     * مرتين بيدي نفس النتيجة بالحرف.
     *
     * ⚠️ **الخانة الفاضية معناها «ماتغيّرش» مش «امسح»** — نفس دوكترين
     * `ClientLocationController::confirm`. مسح المحافظة أو المنطقة
     * كان بيخرّج العميل من تسكين مندوبه في صمت.
     */
    public function saveClientLocation(Request $request, Client $client): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'address' => ['nullable', 'string', 'max:190'],
            'address_ar' => ['nullable', 'string', 'max:190'],
            // ⚠️ مصدر واحد لقايمة المحافظات — نفس `Governorates::rule()`
            // اللي الداشبورد بيستخدمها، فمحافظة جديدة بتشتغل في
            // الاتنين من غير أي تعديل هنا.
            'governorate' => ['nullable', \App\Support\Governorates::rule()],
            'zone_id' => ['nullable', 'exists:zones,id'],
        ]);

        $user = $request->user();

        if ($err = $this->guardClient($user, $client)) {
            return $err;
        }

        [$lat, $lng] = $this->egyptPoint($data);

        if ($lat === null) {
            return response()->json(['message' => __('geo.bad_point')], 422);
        }

        $address = trim((string) ($data['address'] ?? ''));
        $addressAr = trim((string) ($data['address_ar'] ?? ''));

        DB::transaction(function () use ($client, $user, $lat, $lng, $address, $addressAr, $data) {
            $client->forceFill([
                'lat' => $lat,
                'lng' => $lng,
                ...($address !== '' ? ['address' => $address] : []),
                ...($addressAr !== '' ? ['address_ar' => $addressAr] : []),
                ...(($data['governorate'] ?? null) ? ['governorate' => $data['governorate']] : []),
                ...(($data['zone_id'] ?? null) ? ['zone_id' => (int) $data['zone_id']] : []),
                // ⚠️⚠️ **دي بقت «طلب» مش «تأكيد»** (١٧ أغسطس ٢٠٢٦).
                //
                // كانت بتكتب `location_confirmed_at/by` باليوزر —
                // يعني **المندوب بيأكّد لنفسه**: العميل بيخرج من
                // طابور المراجعة من غير ما حد يبصّ، وشاشة تأكيد
                // اللوكيشن (اللي الغرض منها بناء داتابيز عناوين
                // صحيحة) مابتشوفهوش أصلاً.
                //
                // ⚠️ وبعد ما الأبلكيشن بقى يخبّي زرار «عدّل لوكيشن
                // العميل» على العميل المؤكَّد، التأكيد الذاتي ده كان
                // هيقفل الباب: المندوب يحفظ نقطة غلط، الزرار يختفي،
                // ومايقدرش يصحّحها ولا حد راجعها.
                //
                // ⚠️ **`location_confirmed_at` مابتتلمسش هنا خالص** —
                // لا بتتكتب ولا بتتفضّى. عملياً العميل المؤكَّد
                // مايوصلش هنا أصلاً (الأبلكيشن بيخبّي الزرار عنه)،
                // بس لو وصل بأي طريقة تانية فمسح تأكيد الأدمن من
                // نداء مندوب هيبقى أسوأ من سيبانه.
                'location_submitted_at' => now(),
                'location_submitted_by' => $user->id,
                'location_source' => Client::LOC_SRC_APP,
            ])->save();
        });

        $client->refresh();

        TrackEvent::log($user, 'set_location',
            __('field.event_set_location', ['client' => $client->displayName()]),
            $client->displayAddress() ?: null,
            $lat, $lng);

        // ═══ النوتفيكيشن للمدير (طلب المالك) ═══
        //
        // ⚠️ **مدير العميل هو المستهدف**، ولو العميل يتيم (مفيش
        // `manager_id`) بنرجع لمدير المندوب — من غير الفولباك ده
        // العميل اللي لسه ماتسكّنش لمدير كان بيتظبط لوكيشنه ومحدش
        // يعرف. ولو الفاعل هو المدير نفسه مابنبعتش لنفسه.
        //
        // ⚠️ **`teamManager` مش `manager`** — الأخيرة مش موجودة على
        // `User` (الموديل فيه `teamManager`/`teamMembers`)، ونداء
        // خاصية مش موجودة على Eloquent بيرجّع `null` **في صمت**
        // فالإشعار كان هيتبلع من غير أي خطأ.
        $manager = $client->manager ?? $user->teamManager;

        if ($manager !== null && (int) $manager->id !== (int) $user->id) {
            AppNotification::send(
                $manager,
                fn () => __('field.notif_rep_location_title'),
                fn () => __('field.notif_rep_location_body', [
                    'user' => $user->displayName(),
                    'client' => $client->displayName(),
                ]),
                link: 'client_locations',
            );
        }

        return response()->json([
            'client' => [
                'id' => $client->id,
                'lat' => (float) $client->lat,
                'lng' => (float) $client->lng,
                'address' => $client->displayAddress(),
                'address_en' => $client->address,
                'address_ar' => $client->address_ar,
                'governorate' => $client->governorate,
                'governorate_label' => $client->governorateLabel(),
                'zone_id' => $client->zone_id,
                'zone_name' => $client->zone?->displayName(),
                'confirmed_at' => $client->location_confirmed_at?->toIso8601String(),
                // ⚠️ **الأبلكيشن حالياً بيتجاهل الرد ده ويعمل
                // `refresh()`** — فالحالة بتيجي من البوت ستراب مش من
                // هنا، وزرار «عدّل اللوكيشن» بيفضل ظاهر صح لوحده.
                // الحقلين موجودين عشان الرد مايكدبش على اللي يقراه:
                // إندبوينت حفظ بيرجّع نص الحالة بيغري أي كود جاي
                // إنه يستنتج الباقي غلط.
                'location_confirmed' => $client->location_confirmed_at !== null,
                'submitted_at' => $client->location_submitted_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * POST /api/goods-requests — طلب بضاعة لعميل من عند العميل.
     * { visit_id, items: [{product_id, qty, unit?}], note? }
     *
     * ⚠️ **نفس كيان طلب الريفيل** (`ReplenishmentRequest`) — الفلو
     * بعد كده موجود ومجرّب: موافقة المدير → أمر توريد → تجهيز →
     * «تعالى استلم» → تسليم عند العميل. الفرق الوحيد إن المرساة
     * زيارة سيلز (`visit_id`) بدل زيارة بروموتر.
     *
     * ⚠️ **الزيارة المفتوحة إجبارية** — الطلب بيتعمل والمندوب واقف
     * عند العميل (طلب المالك صراحةً)، ودي نفس مرساة الفاتورة.
     */
    public function storeGoodsRequest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'visit_id' => ['required', 'exists:visits,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', new \App\Rules\SellableProduct],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:9999'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $request->user();

        $visit = Visit::findOrFail($data['visit_id']);

        if ($visit->user_id !== $user->id) {
            return response()->json(['message' => __('api.not_your_visit')], 403);
        }
        if (! $visit->isOpen()) {
            return response()->json(['message' => __('field.visit_already_closed')], 422);
        }

        // الوحدات بتتحول قطع في السيرفر — نفس حارس الفاتورة بالظبط.
        // ⚠️ **والسقف بعد الضرب** (تدقيق ٩/٨): «9999 كرتونة» بتعدّي
        // `max:9999` وتبقى 719,928 قطعة في أمر هيتجهّز فعلاً —
        // الطلب مالوش حارس عهدة زي الفاتورة، فالسقف هنا هو الوحيد.
        if ($err = $this->itemsToPieces($data['items'], 9999)) {
            return $err;
        }

        $req = DB::transaction(function () use ($data, $visit, $user) {
            $req = \App\Models\ReplenishmentRequest::create([
                'number' => \App\Models\ReplenishmentRequest::nextNumber(),
                'client_id' => $visit->client_id,
                'visit_id' => $visit->id,
                'requested_by' => $user->id,
                'status' => 'pending',
                'note' => $data['note'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                \App\Models\ReplenishmentItem::create([
                    'replenishment_request_id' => $req->id,
                    'product_id' => $item['product_id'],
                    'qty' => $item['qty'],
                ]);
            }

            return $req;
        });

        $qty = (int) $req->items()->sum('qty');
        $client = $visit->client;

        TrackEvent::log($user, 'request',
            __('field.event_goods_request', [
                'number' => $req->number,
                'client' => $client->displayName(),
            ]),
            __('field.event_qty_requested', ['qty' => $qty]),
            $visit->lat ?? $client->lat, $visit->lng ?? $client->lng);

        // ═══ النوتفيكيشن رايح جاي (طلب المالك ٩ أغسطس) ═══
        // مدير القناة بيوافق، وأمين المخزن بياخد بال إن فيه شغل جاي —
        // أمر التجهيز نفسه هيجيله إشعار تاني أول ما الموافقة تنزل.
        foreach ($req->managers() as $manager) {
            AppNotification::send(
                $manager,
                fn () => __('field.notif_goods_request_title', ['number' => $req->number]),
                fn () => __('field.notif_goods_request_body', [
                    'client' => $client->displayName(),
                    'qty' => $qty,
                    'user' => $user->displayName(),
                ]),
                link: AppNotification::replenishmentLink($req->id),
            );
        }

        foreach (User::where('role', 'warehouse_keeper')->where('active', true)->get() as $keeper) {
            AppNotification::send(
                $keeper,
                fn () => __('field.notif_goods_request_wh_title', ['number' => $req->number]),
                fn () => __('field.notif_goods_request_wh_body', [
                    'client' => $client->displayName(),
                    'qty' => $qty,
                ]),
                link: AppNotification::replenishmentLink($req->id),
            );
        }

        return response()->json([
            'request' => [
                'id' => $req->id,
                'number' => $req->number,
                'status' => $req->status,
                'status_label' => $req->statusLabel(),
                'qty' => $qty,
            ],
        ], 201);
    }

    /**
     * GET /api/my-goods-requests — طلبات البضاعة اللي الموظف ده طلبها.
     *
     * ⚠️ **كان بيطلب في الفراغ** (تدقيق ٩/٨): المندوب يبعت الطلب
     * ومفيش أي شاشة تقوله اتوافق ولا اترفض ولا بقى أمر رقم كام —
     * البروموتر ليه تاب ريفيل والسيلز ماكانش ليه حاجة.
     */
    public function myGoodsRequests(Request $request): JsonResponse
    {
        // ⚠️ `pickOrder` انضم (فلو ١٥/٨): طلب الريفيل مابقاش ياخد أمر
        // توريد — بينزل المخزن بأمر تجهيز، والمندوب لازم يشوف رقمه
        // وحالته عشان يعرف إن بضاعته اتجهّزت. `purchaseOrder` باقي
        // للطلبات القديمة اللي اتعملت قبل التغيير.
        $rows = \App\Models\ReplenishmentRequest::with([
            'client.group', 'items.product', 'assignee:id,name,name_en',
            'purchaseOrder:id,number,status',
            'pickOrder:id,number,status,warehouse_id', 'pickOrder.warehouse:id,name,name_en',
        ])
            ->where('requested_by', $request->user()->id)
            ->latest()->take(30)->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'number' => $r->number,
                'client' => $r->client?->fullName() ?? '—',
                'status' => $r->status,
                'status_label' => $r->statusLabel(),
                'qty_total' => $r->qtyTotal(),
                'note' => $r->note,
                // أمر التجهيز — ده المسار الحقيقي دلوقتي
                'pick_number' => $r->pickOrder?->number,
                'pick_status' => $r->pickOrder?->status,
                'pick_warehouse' => $r->pickOrder?->warehouse?->displayName(),
                // ⚠️ باقيين للطلبات القديمة وللنسخ القديمة من الأبلكيشن
                'po_number' => $r->purchaseOrder?->number,
                'po_status' => $r->purchaseOrder?->status,
                'assignee' => $r->assignee?->displayName(),
                'time' => $r->created_at->toIso8601String(),
                // ⚠️ **`image` كان ناقص** (بلاغ المالك ١٥/٨: «صفحة
                // طلبات الريفيل مش بتطلع صور المنتجات»). الأبلكيشن
                // بيرسم الصورة لو موجودة، والسيرفر ماكانش بيبعتها
                // أصلاً — فالشاشة كانت أسماء عريانة والمندوب بيقرا
                // بدل ما يتعرّف بالعين، وهو واقف في الشارع.
                'items' => $r->items->map(fn ($i) => [
                    'name' => $i->product?->displayName() ?? '—',
                    'image' => $i->product?->imageSrc(),
                    'qty' => (int) $i->qty,
                ])->values()->all(),
            ])->values()->all();

        return response()->json(['requests' => $rows]);
    }

    // ================= الفواتير =================

    /** POST /api/invoices { client_id, visit_id, payment, items: [{product_id, qty}] } */
    public function storeInvoice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            // ⚠️ **`required` مش `nullable`** (تدقيق ٨/٨/٢٠٢٦). الفاتورة
            // كانت بتكتب قيد على دفتر العميل **بلا مرساة علاقة**: مندوب
            // ماتسكّنش على العميل كان يفوتره ويخصم من عهدته ويمدّن
            // حسابه من غير ولا زيارة مفتوحة. الزيارة هي الإثبات إنه
            // كان واقف قدام المحل.
            'visit_id' => ['required', 'exists:visits,id'],
            // ⚠️ بيتقبل من نسخ قديمة بس **بيتطنش** — شوف تحت
            'payment' => ['nullable', 'in:cash,credit'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
            // سيريال الفاتورة الورقية المختومة اللي المندوب كتبها
            // بإيده (١٩/٨/٢٠٢٦) — للمطابقة بين الدفتر والسيستم
            'paper_ref' => ['nullable', 'string', 'max:30'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', new \App\Rules\SellableProduct],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.unit' => ['nullable', 'in:piece,box,case'],
        ]);

        $user = $request->user();
        $client = Client::findOrFail($data['client_id']);
        $custody = $user->currentCustody();

        if (! $custody) {
            return response()->json(['message' => __('field.no_custody_today')], 422);
        }

        // ═══ مرساة العلاقة — نفس حارس المرتجع بالظبط (٨/٨/٢٠٢٦) ═══
        // ⚠️ الزيارة لازم تكون **بتاعته**، **مفتوحة**، وعلى **نفس
        // العميل**. من غير التلاتة، `visit_id` مجرد رقم في الريكوست
        // بيتحط في عمود ومابيثبتش حاجة.
        $visit = Visit::find($data['visit_id']);

        if ($visit === null || $visit->user_id !== $user->id) {
            return response()->json(['message' => __('api.not_your_visit')], 403);
        }

        if (! $visit->isOpen() || (int) $visit->client_id !== (int) $client->id) {
            return response()->json(['message' => __('api.invoice_needs_open_visit')], 422);
        }

        // ⚠️ **كاش/آجل من تعريف العميل مش من المندوب** (قرار المالك
        // 2026-08-03). اللي الأبلكيشن بيبعته بيتطنش — توكن معدّل كان
        // يقدر يبعت `credit` لعميل كاش ويفتح مديونية محدش قررها.
        //
        // ⚠️ **إلا لو الإدارة اختارت «الاتنين»** (2026-08-08). ساعتها
        // بس اللي جاي من الأبلكيشن بيتقرا — والحارس فاضل زي ما هو
        // لباقي العملاء. لاحظ إن الفحص على `paymentIsChoice()` مش على
        // اللي الأبلكيشن بعته: لو اتعكس، أي توكن يبعت `payment` وياخد
        // اللي هو عايزه.
        $terms = $client->paymentTerms();

        // ⚠️ الافتراضي **كاش** لو العميل مختلط والأبلكيشن مابعتش —
        // نسخة قديمة من الأبلكيشن مش عارفة السويتش لازم تفتح أضيق
        // الاحتمالين، مش تفتح مديونية من غير ما حد يختارها.
        $data['payment'] = $terms === Client::PAY_BOTH
            ? (in_array($data['payment'] ?? null, [Client::PAY_CASH, Client::PAY_CREDIT], true)
                ? $data['payment']
                : Client::PAY_CASH)
            : $terms;

        // ⚠️ **وحدة البيع بتتضرب هنا مش في الأبلكيشن** — التفاصيل في itemsToPieces. «2 كرتونة»
        // بتتحول 24 قطعة قبل الخصم من العهدة والتسعير — والسعر سعر
        // القطعة × العدد (قرار المالك 2026-08-04). وحدة مش معرّفة
        // للصنف = رفض الفاتورة كلها، مش افتراض قطعة.
        if ($err = $this->itemsToPieces($data['items'])) {
            return $err;
        }

        $qtyByProduct = [];
        foreach ($data['items'] as $i) {
            $qtyByProduct[$i['product_id']] = ($qtyByProduct[$i['product_id']] ?? 0) + $i['qty'];
        }

        // ⚠️ الخصم من العهدة لازم يبقى جوه نفس الترانزاكشن بتاعة الفاتورة.
        // لو كان بره وحصل خطأ في إنشاء الفاتورة، العهدة تتخصم من غير فاتورة
        // والمندوب يخسر بضاعة على الورق.
        try {
            $invoice = DB::transaction(function () use ($data, $user, $client, $custody, $qtyByProduct) {
                // الخصم بالـ FEFO — بيرجّع كل بند بالباتش اللي خرج منه
                $deduction = $custody->deductWithBatches($qtyByProduct);
                if ($deduction['error']) {
                    throw new StockShortage($deduction['error']);
                }

                $subtotal = 0;   // قبل الخصم — بسعر القائمة
                $net = 0;        // بعد الخصم — ده اللي العميل بيدفعه
                $costTotal = 0;
                $rows = [];
                $priceList = $client->priceList();

                // سطر فاتورة لكل باتش — لو الكمية اتاخدت من باتشين يبقى سطرين،
                // وده المقصود عشان نقدر نتتبع أي شحنة راحت لأي عميل
                foreach ($deduction['lines'] as $line) {
                    /** @var \App\Models\CustodyItem $item */
                    $item = $line['item'];
                    $qty = (int) $line['qty'];

                    // التسعير كله من Pricing: قائمة العميل، خصمه، وتكلفة الباتش.
                    // بنخزّن اللقطة على السطر عشان الربحية التاريخية ماتتأثرش
                    // بأي تعديل سعر أو تكلفة بعد كده.
                    $quote = \App\Services\Pricing::quote($client, $item->product, $item->batch, $qty);

                    // ⚠️ **سعر القايمة صفر = بيع مرفوض.** الصنف اللي مش
                    // متسعّر في قايمة العميل كان بيعدّي ويطلع سطر فاتورة
                    // بـ0.00 من غير أي رسالة — بضاعة بتخرج ببلاش والرقم
                    // مابيبانش غير في مراجعة آخر الشهر.
                    if ($quote['list_price'] <= 0) {
                        throw new \App\Exceptions\Rejected(__('api.product_not_priced', [
                            'product' => $item->product->displayName(),
                        ]));
                    }

                    // ⚠️ الضريبة على الصافي **بعد الخصم**، وسطر بسطر —
                    // الفاتورة ممكن تجمع صنف خاضع وصنف معفى.
                    $taxRate = \App\Services\Tax::rate($client, $item->product);
                    $lineTax = \App\Services\Tax::on($quote['line_total'], $client, $item->product);

                    $rows[] = [
                        'product_id' => $item->product_id,
                        'batch_id' => $item->batch_id,
                        'qty' => $qty,
                        'list_price' => $quote['list_price'],
                        'price' => $quote['unit_price'],
                        'unit_cost' => $quote['unit_cost'],
                        'total' => $quote['line_total'],
                        'tax_rate' => $taxRate,
                        'tax' => $lineTax,
                    ];

                    // ⚠️ subtotal = قبل الخصم (سعر القائمة)، و net = بعده.
                    // unit_price اللي جوه الـ quote **مخصوم أصلاً**، فممنوع
                    // نخصم تاني على الإجمالي — ده كان بيخصم مرتين.
                    $subtotal += round($quote['list_price'] * $qty, 2);
                    $net += $quote['line_total'];
                    $costTotal += $quote['line_cost'];
                }

                $discPct = $client->effectiveDiscount();
                $discount = round($subtotal - $net, 2);

                // ⚠️ الضريبة بتتجمّع من السطور، مش بضرب الإجمالي في نسبة —
                // السطور ممكن تكون بنسب مختلفة أو فيها صنف معفى.
                $sums = \App\Services\Tax::totals($rows);
                $net = $sums['net'];
                $taxTotal = $sums['tax'];
                $grandTotal = $sums['grand'];

                $invoice = Invoice::create([
                    'number' => Invoice::nextNumber(),
                    // ⚠️ محروس بـhasColumn — الكود ممكن يوصل قبل المايجريشن
                    ...(\Illuminate\Support\Facades\Schema::hasColumn('invoices', 'paper_ref')
                        ? ['paper_ref' => $data['paper_ref'] ?? null] : []),
                    'client_id' => $client->id,
                    'user_id' => $user->id,
                    'visit_id' => $data['visit_id'] ?? null,
                    'payment' => $data['payment'],
                    'price_list' => $priceList,
                    'subtotal' => $subtotal,
                    'discount_pct' => $discPct,
                    'discount_source' => $client->discountSourceKey(),
                    'discount' => $discount,
                    // ⚠️ `total` = صافي المبيعات قبل الضريبة. كل تقرير في
                    // السيستم بيجمعه وبيقصد بيه المبيعات. اللي العميل بيدفعه
                    // هو `grand_total`، وهو **الوحيد** اللي بيتقيّد في الليدجر.
                    'total' => $net,
                    'tax_total' => $taxTotal,
                    'grand_total' => $grandTotal,
                    'eta_status' => $taxTotal > 0 ? 'ready' : 'none',
                    'cost_total' => round($costTotal, 2),
                    'lat' => $this->egyptPoint($data)[0],
                    'lng' => $this->egyptPoint($data)[1],
                ]);

                foreach ($rows as $r) {
                    InvoiceItem::create($r + ['invoice_id' => $invoice->id]);
                }

                // ⚠️ عقد الأمانة: البضاعة بتروح الفرع وتفضل ملك بروماكس
                // لحد ما تتباع. فالقيد بيتسجل بصفر مدين ونوع consignment —
                // بيفضل في كشف الحساب كأثر، بس مايزوّدش الرصيد. المديونية
                // بتتولد بعدين من تقرير مبيعات الفرع.
                $consigned = $client->isConsignment();

                Transaction::create([
                    'client_id' => $client->id,
                    'date' => today(),
                    'memo' => $consigned
                        ? __('flash.memo_consignment', [
                            'number' => $invoice->number,
                            'amount' => number_format($grandTotal),
                        ])
                        : __('flash.memo_invoice', [
                            'number' => $invoice->number,
                            'user' => $user->displayName(),
                        ]),
                    // ⚠️ المديونية بالإجمالي **شامل الضريبة** — ده اللي
                    // العميل بيدفعه فعلاً. القيد بالصافي بيسيب فرق الضريبة
                    // مالوش مقابل في كشف الحساب.
                    'debit' => $consigned ? 0 : $grandTotal,
                    'credit' => 0,
                    // ⚠️ نصيب الضريبة من القيد. عمولات العقود بتطرحه
                    // عشان تتحسب على الصافي — من غيره العمولة بتزيد
                    // بنسبة الضريبة وده كاش بيخرج فعلاً.
                    'tax' => $consigned ? 0 : $taxTotal,
                    'kind' => $consigned ? 'consignment' : 'sale',
                    'source_type' => Invoice::class,
                    'source_id' => $invoice->id,
                ]);

                // لو كاش: قيد تحصيل مقابل (دائن) — الرصيد يفضل صفر.
                // في الأمانة مفيش مديونية أصلاً فمفيش تحصيل مقابلها.
                if ($data['payment'] === 'cash' && ! $consigned) {
                    Transaction::create([
                        'client_id' => $client->id,
                        'date' => today(),
                        'memo' => __('flash.memo_cash_with_invoice', ['number' => $invoice->number]),
                        'debit' => 0,
                        // التحصيل بالإجمالي عشان الرصيد يرجع صفر بالظبط
                        'credit' => $grandTotal,
                        'tax' => $taxTotal,
                        'kind' => 'collection',
                        'source_type' => Invoice::class,
                        'source_id' => $invoice->id,
                    ]);
                }

                $client->recalculate();

                [$evLat, $evLng] = $this->eventPoint($data, $client);
                TrackEvent::log($user, 'sale',
                    __('field.event_invoice', ['number' => $invoice->number, 'client' => $client->displayName()]),
                    __('common.money', ['amount' => number_format($grandTotal)]),
                    $evLat, $evLng);

                return $invoice;
            });
        } catch (\App\Exceptions\Rejected $e) {
            // رفض تجاري (نقص عهدة، صنف مش متسعّر…) — الترانزاكشن
            // اترجعت والعهدة زي ما هي. StockShortage وريثة Rejected
            // فبتتلقف هنا برضه. أي خطأ تاني (SQL مثلاً) بيكمّل لـ500
            // عن قصد.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $invoice->load('items.product');

        return response()->json([
            'invoice' => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'subtotal' => (float) $invoice->subtotal,
                'discount' => (float) $invoice->discount,
                'total' => (float) $invoice->total,
                'tax_total' => (float) $invoice->tax_total,
                // ⚠️ الأبلكيشن بيوري ده للعميل ويحصّله — لازم يبقى الإجمالي
                // شامل الضريبة مش الصافي، وإلا المندوب بيحصّل ناقص.
                'grand_total' => (float) $invoice->grand_total,
                'payment' => $invoice->payment,
                'time' => $invoice->created_at->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * GET /api/clients/{client}/returnable — كتالوج المرتجع وسياساته.
     *
     * ⚠️ **المرتجع اتفتح على الكتالوج كله (قرار المالك ١٢ أغسطس ٢٠٢٦).**
     * العملاء ماسكين بضاعة من قبل السيستم (أرصدة افتتاحية ماتسجلتش)،
     * فقايمة «المشترى بس» كانت بتقول «مفيش حاجة ترجع» لعميل البضاعة
     * القديمة واقفة على رفّه. القايمة بقت نفس كتالوج البيع: كل صنف
     * نشط ومتسعّر في قايمة العميل، بسعر `Pricing` بتاعه — نفس السعر
     * اللي فاتورة بيع كانت هتطلع بيه. التسعير النهائي في الخدمة:
     * سطور الفواتير الأصلية أولاً بسعرها، والباقي بسعر `Pricing`.
     *
     * ⚠️ `qty` = المتاح من فواتير السيستم — معلومة للعرض، **مش حد**.
     * الأبلكيشن القديم بيعامل الصفر «مش متاح» فبيفضل على سلوكه
     * القديم بأمان، والجديد بيسيبها مفتوحة بسقف 9999.
     */
    public function returnable(Request $request, Client $client): JsonResponse
    {
        if ($err = $this->guardClient($request->user(), $client)) {
            return $err;
        }

        $prev = \App\Services\Returns::returnableByProduct($client);

        $rows = Product::where('active', true)->orderBy('name')->get()
            ->map(function (Product $p) use ($client, $prev) {
                $q = \App\Services\Pricing::quote($client, $p, null, 1);

                return [
                    'product_id' => $p->id,
                    'name' => $p->displayName(),
                    'image' => $p->imageSrc(),
                    'unit' => $p->unitLabel(),
                    'box_units' => (int) $p->box_units,
                    'case_units' => (int) $p->units_per_case,
                    'qty' => (int) ($prev[$p->id] ?? 0),
                    // ⚠️ سعر العميل النهارده — عرض بس. البنود اللي
                    // ليها سطر فاتورة أصلي بتتسعّر منه في الخدمة.
                    'price' => (float) $q['unit_price'],
                    'tax_rate' => \App\Services\Tax::rate($client, $p),
                ];
            })
            // ⚠️ **الصنف الغير متسعّر مابيظهرش** — نفس قاعدة كتالوج
            // البيع: لو ظهر بـ0.00 الخدمة هترفضه بعد ما المندوب
            // يكون وعد العميل.
            ->filter(fn ($row) => $row['price'] > 0)
            ->values()->all();

        return response()->json([
            'client_id' => $client->id,
            'policies' => array_map(fn ($p) => [
                'code' => $p,
                'label' => __('field.return_policy_'.$p),
                'hint' => __('field.return_policy_'.$p.'_hint'),
            ], $client->returnPolicies()),
            'items' => $rows,
        ]);
    }

    /**
     * POST /api/returns
     * { client_id, visit_id, policy, idem_key?, note?,
     *   items: [{product_id, qty, unit?, condition?}] }
     *
     * ⚠️ **الميثود دي بقت غلاف رفيع فوق `App\Services\Returns`**
     * (٨ أغسطس ٢٠٢٦). قبل كده كل منطق المرتجع كان مكتوب هنا: التسعير
     * بسعر النهارده (باج)، بلا سقف بالصنف (ثغرة)، بلا مستند (البنود
     * بتعيش في رد الـJSON بس)، وبلا تفرقة بين السليم والتالف. الخدمة
     * هي المكان الوحيد دلوقتي، والـERP بينده على نفس الكود.
     *
     * ⚠️ الفحوصات اللي فاضلة هنا **مرتبطة بالأبلكيشن**: ملكية
     * الزيارة وفتحها وتحويل الوحدات لقطع. الباقي (السياسة، السقف،
     * السعر، القيود، العهدة) في الخدمة.
     */
    public function storeReturn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'exists:clients,id'],
            // ⚠️ **الزيارة إجبارية** — هي المرساة المادية للمرتجع.
            // من غيرها أي توكن كان يقدر يكتب قيد دائن بلا حدود على
            // أي عميل في الشركة ويمسح مديونيته.
            'visit_id' => ['required', 'exists:visits,id'],
            'policy' => ['required', 'string', 'max:20'],
            // ⚠️ مفتاح منع التكرار — الأبلكيشن بيولّده مرة للشاشة،
            // فإعادة الإرسال بعد انقطاع شبكة مابتكتبش قيد تاني.
            'idem_key' => ['nullable', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:500'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', new \App\Rules\SellableProduct],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:9999'],
            'items.*.unit' => ['nullable', 'in:piece,box,case'],
            'items.*.condition' => ['nullable', 'in:good,damaged'],
        ]);

        // وحدة المرتجع → قطع، في السيرفر — نفس قاعدة البيع،
        // والسقف 9999 **قطعة** بعد التحويل
        if ($err = $this->itemsToPieces($data['items'], 9999)) {
            return $err;
        }

        $user = $request->user();
        $client = Client::findOrFail($data['client_id']);

        // زيارته هو، مفتوحة، وعلى نفس العميل — نفس منطق التشيك إن
        $visit = Visit::find($data['visit_id']);

        if ($visit === null || $visit->user_id !== $user->id) {
            return response()->json(['message' => __('api.not_your_visit')], 403);
        }

        if (! $visit->isOpen() || (int) $visit->client_id !== (int) $client->id) {
            return response()->json(['message' => __('api.return_needs_open_visit')], 422);
        }

        try {
            $doc = \App\Services\Returns::create(
                client: $client,
                items: $data['items'],
                policy: $data['policy'],
                rep: $user,
                visit: $visit,
                note: $data['note'] ?? null,
                idemKey: $data['idem_key'] ?? null,
                actor: $user,
                source: 'app',
            );
        } catch (\App\Exceptions\Rejected $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        [$evLat, $evLng] = $this->eventPoint($data, $client);
        TrackEvent::log($user, 'return',
            __('field.event_return', ['client' => $client->displayName()]),
            __('common.money', ['amount' => number_format($doc->payable())]),
            $evLat, $evLng);

        return response()->json(['return' => $this->returnPayload($doc)], 201);
    }

    /** شكل المرتجع في الردود — مستخدم في الحفظ وفي «مبيعاتي» */
    private function returnPayload(\App\Models\ClientReturn $doc): array
    {
        return [
            'id' => $doc->id,
            'number' => $doc->number,
            'policy' => $doc->policy,
            'policy_label' => $doc->policyLabel(),
            'net' => (float) $doc->total,
            'tax_total' => (float) $doc->tax_total,
            'grand_total' => (float) $doc->grand_total,
            'good_units' => (int) $doc->good_units,
            'damaged_units' => (int) $doc->damaged_units,
            // ⚠️ **التبديل محتاج خطوة تانية** — البضاعة البديلة بتخرج
            // بفاتورة عادية. الفلاج ده بيخلّي الشاشة تفتح البيع بعده
            // على طول بدل ما المندوب ينسى ويمشي.
            'needs_exchange_sale' => $doc->policy === \App\Models\ClientReturn::POLICY_EXCHANGE,
            'lines' => $doc->items->map(fn ($it) => [
                'product_id' => $it->product_id,
                'name' => $it->product?->displayName() ?? '—',
                'image' => $it->product?->imageSrc(),
                'unit' => $it->product?->unitLabel(),
                'qty' => (int) $it->qty,
                'condition' => $it->condition,
                'condition_label' => $it->conditionLabel(),
                'price' => (float) $it->price,
                'total' => (float) $it->total,
            ])->values(),
            'time' => $doc->created_at->toIso8601String(),
        ];
    }

    /**
     * GET /api/invoices — مبيعات ومرتجعات المندوب (آخر ٧ أيام).
     *
     * المندوب لازم يقدر يراجع «بعت إيه ورجّعت إيه» في أي وقت —
     * مش بس توتال اليوم على الرئيسية.
     */
    public function invoices(Request $request): JsonResponse
    {
        $user = $request->user();

        // فلتر التاريخ: from/to (Y-m-d) — الافتراضي آخر ٧ أيام
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $since = isset($data['from']) ? \Illuminate\Support\Carbon::parse($data['from']) : today()->subDays(7);
        $until = isset($data['to']) ? \Illuminate\Support\Carbon::parse($data['to']) : today();

        $invoices = Invoice::with(['client', 'items.product'])
            ->where('user_id', $user->id)
            ->whereDate('created_at', '>=', $since)
            ->whereDate('created_at', '<=', $until)
            ->latest()->take(200)->get()->map(fn ($i) => [
                'id' => $i->id, 'number' => $i->number, 'client' => $i->client->displayName(),
                'total' => (float) $i->total,
                // ⚠️ الإجمالي شامل الضريبة — ده اللي اتحصّل فعلاً
                'grand_total' => (float) $i->grand_total,
                'payment' => $i->payment,
                'time' => $i->created_at->toIso8601String(),
                // بنود الفاتورة بالصور — المندوب يفتحها ويتأكد بعينه
                // إيه اللي اتباع بالظبط (قرار المالك 2026-08-04)
                'lines' => $i->items->map(fn ($it) => [
                    'name' => $it->product?->displayName() ?? '—',
                    'image' => $it->product?->imageSrc(),
                    'qty' => (int) $it->qty,
                    'price' => (float) $it->price,
                    'total' => (float) $it->total,
                ])->values(),
            ]);

        // ⚠️ **من المستند مش من القيد** (٨ أغسطس ٢٠٢٦). كانت بتقرا
        // قيود `return` المربوطة بزيارات المندوب وتولّد رقم من
        // `id` القيد — فالمندوب ماكانش يقدر يشوف رجّع إيه بالظبط
        // ولا سليم ولا تالف. المستند بقى موجود وعليه `user_id`.
        $returns = \App\Models\ClientReturn::with(['client', 'items.product'])
            ->where('user_id', $user->id)
            ->whereDate('created_at', '>=', $since)
            ->whereDate('created_at', '<=', $until)
            ->latest()->take(200)->get()->map(fn ($r) => [
                'id' => $r->id,
                'number' => $r->number,
                'client' => $r->client?->displayName() ?? '—',
                'total' => (float) $r->grand_total,
                'policy' => $r->policy,
                'policy_label' => $r->policyLabel(),
                'good_units' => (int) $r->good_units,
                'damaged_units' => (int) $r->damaged_units,
                'memo' => $r->note,
                'lines' => $r->items->map(fn ($it) => [
                    'name' => $it->product?->displayName() ?? '—',
                    'image' => $it->product?->imageSrc(),
                    'qty' => (int) $it->qty,
                    'condition' => $it->condition,
                    'price' => (float) $it->price,
                    'total' => (float) $it->total,
                ])->values(),
                'time' => $r->created_at->toIso8601String(),
            ]);

        return response()->json(['invoices' => $invoices, 'returns' => $returns]);
    }

    // ================= أوامر التوريد (الكورير) =================

    /** POST /api/pos/{purchaseOrder}/arrive */
    public function arrive(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        if ($purchaseOrder->assigned_to !== $request->user()->id) {
            return response()->json(['message' => __('api.order_not_yours')], 403);
        }
        if ($purchaseOrder->status !== 'pending') {
            return response()->json(['message' => __('api.order_not_pending')], 422);
        }

        $purchaseOrder->update(['status' => 'arrived', 'arrived_at' => now()]);

        [$evLat, $evLng] = $this->eventPoint($request->only(['lat', 'lng']), $purchaseOrder->client);
        TrackEvent::log($request->user(), 'check_in',
            __('field.event_arrived', ['client' => $purchaseOrder->client->displayName()]),
            $purchaseOrder->address, $evLat, $evLng);

        return response()->json(['status' => 'arrived']);
    }

    /**
     * POST /api/pos/{purchaseOrder}/cancel-arrival — إلغاء التسليم
     * (طلب المالك ١١ أغسطس ٢٠٢٦ مساءً).
     *
     * المندوب عمل «وصول» وماعرفش يسلّم (المحل قافل، مسؤول الاستلام
     * مش موجود، رفضوا الاستلام...) — كان محبوس: «لازم تسلّم» ومفيش
     * رجوع، ولا يعرف يعمل انصراف (الأمر الـarrived بيمنعه — عكس
     * حارس الحضور) ولا يروح محل تاني.
     *
     * الإلغاء **بسبب إجباري**: الأمر بيرجع «مستني» بنفس تسكينه
     * (يقدر يرجعله بعدين أو المدير يعيد تسكينه)، السبب بيتسجل على
     * الأمر نفسه (`abort_reason` بيبان في الداش بورد) وفي التراكينج،
     * والمدير/منشئ الأمر بياخد إشعار فوري بالسبب.
     */
    public function cancelArrival(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $user = $request->user();

        if ($purchaseOrder->assigned_to !== $user->id) {
            return response()->json(['message' => __('api.order_not_yours')], 403);
        }
        if ($purchaseOrder->status !== 'arrived') {
            return response()->json(['message' => __('field.must_arrive_first')], 422);
        }

        $data = $request->validate([
            // ⚠️ السبب إجباري — «إلغاء صامت» بيضيّع المعلومة اللي
            // الإدارة محتاجاها عشان تحل المشكلة مع الفرع.
            'reason' => ['required', 'string', 'min:3', 'max:190'],
        ]);

        $purchaseOrder->update([
            'status' => 'pending',
            'arrived_at' => null,
            'abort_reason' => $data['reason'],
        ]);

        [$evLat, $evLng] = $this->eventPoint($request->only(['lat', 'lng']), $purchaseOrder->client);
        TrackEvent::log($user, 'po_abort',
            __('field.event_po_aborted', [
                'number' => $purchaseOrder->number,
                'client' => $purchaseOrder->client->displayName(),
            ]),
            $data['reason'], $evLat, $evLng);

        // إشعار فوري لمنشئ الأمر (المدير غالباً) — بالسبب نصاً.
        // ⚠️ مايتبعتش للمندوب نفسه لو هو اللي أنشأه (المدير الميداني
        // بيسلّم أوامر بيعملها لنفسه).
        $creator = $purchaseOrder->creator;
        if ($creator !== null && $creator->id !== $user->id) {
            AppNotification::send(
                $creator,
                fn () => __('field.notif_po_aborted_title', ['number' => $purchaseOrder->number]),
                fn () => __('field.notif_po_aborted_body', [
                    'client' => $purchaseOrder->client->displayName(),
                    'rep' => $user->displayName(),
                    'reason' => $data['reason'],
                ]),
                good: false,
                link: AppNotification::poLink($purchaseOrder->id),
            );
        }

        return response()->json(['status' => 'pending']);
    }

    /** POST /api/pos/{purchaseOrder}/deliver */
    public function deliver(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        $user = $request->user();

        if ($purchaseOrder->assigned_to !== $user->id) {
            return response()->json(['message' => __('api.order_not_yours')], 403);
        }
        if ($purchaseOrder->status !== 'arrived') {
            return response()->json(['message' => __('field.must_arrive_first')], 422);
        }

        $custody = $user->currentCustody();
        if (! $custody) {
            return response()->json(['message' => __('field.no_custody_today')], 422);
        }

        // ═══ تسليم بكميات فعلية (فلو الكي أكاونت 2026-08-04) ═══
        // الأبلكيشن يقدر يبعت items: [{product_id, qty, unit}] بالمسلَّم
        // فعلاً («9 كراتين مش 10») — والقيد بيتكتب **بالمسلَّم** مش
        // بالمطلوب. من غير items = الفلو القديم (تسليم كامل) زي ما هو.
        $data = $request->validate([
            'items' => ['nullable', 'array'],
            'items.*.product_id' => ['required_with:items', new \App\Rules\SellableProduct],
            'items.*.qty' => ['required_with:items', 'integer', 'min:0'],
            'items.*.unit' => ['nullable', 'in:piece,box,case'],
        ]);

        $purchaseOrder->load('items.product');

        $delivered = null;   // null = تسليم كامل بالمطلوب

        if (! empty($data['items'])) {
            if ($err = $this->itemsToPieces($data['items'])) {
                return $err;
            }

            $delivered = [];
            foreach ($data['items'] as $i) {
                $delivered[$i['product_id']] = ($delivered[$i['product_id']] ?? 0) + (int) $i['qty'];
            }

            // ⚠️ المسلَّم مقفول بالمطلوب: أكتر من المطلوب مش تعديل —
            // ده بيع من غير أمر، وله مساره (فاتورة عادية).
            foreach ($purchaseOrder->items as $item) {
                if (($delivered[$item->product_id] ?? 0) > (int) $item->qty) {
                    return response()->json(['message' => __('api.po_over_delivery')], 422);
                }
            }

            // صنف مش في الأمر أصلاً = رفض
            $orderProducts = $purchaseOrder->items->pluck('product_id')->all();
            foreach (array_keys($delivered) as $pid) {
                if (! in_array((int) $pid, $orderProducts, true)) {
                    return response()->json(['message' => __('api.po_item_not_in_order')], 422);
                }
            }

            if (array_sum($delivered) === 0) {
                return response()->json(['message' => __('api.po_nothing_delivered')], 422);
            }
        }

        $qty = $delivered ?? $purchaseOrder->items->pluck('qty', 'product_id')->all();
        // الخصم بالكميات اللي **اتسلمت فعلاً** — الباقي بيفضل في العهدة
        $qty = array_filter($qty, fn ($q) => (int) $q > 0);

        // ⚠️ نفس قاعدة storeInvoice: الخصم من العهدة جوه الترانزاكشن،
        // مايصحّش تخرج البضاعة من العربية وأمر التوريد يفضل مش متسلّم.
        try {
            DB::transaction(function () use ($purchaseOrder, $user, $request, $custody, $qty, $delivered) {
                if ($err = $custody->deduct($qty)) {
                    throw new StockShortage($err);
                }

                // ⚠️ `abort_reason` بتتمسح مع أول تسليم ناجح — السبب
                // القديم مالوش معنى بعد ما البضاعة وصلت فعلاً.
                $purchaseOrder->update(['status' => 'delivered', 'delivered_at' => now(), 'abort_reason' => null]);

                // ═══ قيمة القيد = المسلَّم فعلاً بسعر بنوده وضريبتها ═══
                $net = 0.0;
                $taxTotal = 0.0;

                foreach ($purchaseOrder->items as $item) {
                    $dq = $delivered === null ? (int) $item->qty : (int) ($delivered[$item->product_id] ?? 0);
                    $item->update(['delivered_qty' => $dq]);

                    // ⚠️ **السطر الكامل بياخد أرقامه المخزنة بالمليم** —
                    // إعادة الحساب ممكن تفرق قرش تقريب عن grand_total
                    // اللي الفلو القديم كان بيقيّد بيه. الجزئي بس هو
                    // اللي بيتحسب من جديد.
                    if ($dq === (int) $item->qty) {
                        $net += (float) $item->total;
                        $taxTotal += (float) $item->tax;
                    } else {
                        $lineNet = round($dq * (float) $item->price, 2);
                        $net += $lineNet;
                        $taxTotal += round($lineNet * (float) ($item->tax_rate ?? 0), 2);
                    }
                }

                $net = round($net, 2);
                $taxTotal = round($taxTotal, 2);
                $grand = round($net + $taxTotal, 2);
                $variance = $purchaseOrder->qtyTotal() - $purchaseOrder->items->sum('delivered_qty');

                // نفس قاعدة الفاتورة: عقد الأمانة مايعملش مديونية عند التوريد
                $consigned = $purchaseOrder->client->isConsignment();

                Transaction::create([
                    'client_id' => $purchaseOrder->client_id,
                    'date' => today(),
                    'memo' => $consigned
                        ? __('flash.memo_consignment', [
                            'number' => $purchaseOrder->number,
                            'amount' => number_format($grand),
                        ])
                        : ($variance > 0
                            ? __('flash.memo_po_partial', ['number' => $purchaseOrder->number, 'diff' => $variance])
                            : __('flash.memo_po_delivered', ['number' => $purchaseOrder->number])),
                    // ⚠️ المديونية **بالمسلَّم** شامل ضريبته — الفرع
                    // مايتحاسبش على كرتونة ماوصلتلوش (قرار المالك 2026-08-04)
                    'debit' => $consigned ? 0 : $grand,
                    'tax' => $consigned ? 0 : $taxTotal,
                    'credit' => 0,
                    'kind' => $consigned ? 'consignment' : 'sale',
                    'source_type' => PurchaseOrder::class,
                    'source_id' => $purchaseOrder->id,
                ]);

                // ═══ عميل كاش: التسليم بيتقفل نقدي في نفس اللحظة ═══
                //
                // ⚠️ **كان بيفتح مديونية محدش بيتحصّلها** (تدقيق ٨/٨):
                // الفاتورة الكاش بتعمل قيدين (`sale` مدين + `collection`
                // دائن) فالرصيد بيرجع صفر، لكن تسليم الـPO كان بيكتب
                // المدين بس. النتيجة: عميل كاش عليه مديونية وهمية،
                // والفلوس اللي السواق قبضها مش ظاهرة في تصفيته —
                // يعني عجز نقدي عليه هو، والعميل مدين بنفس المبلغ.
                //
                // ⚠️ **`PAY_CASH` الصريحة بس.** العميل المختلط
                // (`both`) بياخد آجل هنا: أمر التوريد مالوش شاشة
                // اختيار زي الفاتورة، وافتراض الكاش كان هيقفل مديونية
                // حقيقية بقيد تحصيل محصلش.
                $payCash = ! $consigned
                    && $purchaseOrder->client->paymentTerms() === \App\Models\Client::PAY_CASH
                    && $grand > 0;

                if ($payCash) {
                    Transaction::create([
                        'client_id' => $purchaseOrder->client_id,
                        'date' => today(),
                        'memo' => __('flash.memo_po_cash', ['number' => $purchaseOrder->number]),
                        'debit' => 0,
                        'credit' => $grand,
                        'kind' => 'collection',
                        'source_type' => PurchaseOrder::class,
                        'source_id' => $purchaseOrder->id,
                    ]);
                }

                $purchaseOrder->client->recalculate();

                TrackEvent::log($user, 'deliver',
                    __('field.event_delivered', [
                        'number' => $purchaseOrder->number,
                        'client' => $purchaseOrder->client->displayName(),
                    ]),
                    __('field.event_delivered_sub', [
                        'qty' => $purchaseOrder->items->sum('delivered_qty'),
                        'amount' => number_format($grand),
                    ]),
                    ...$this->eventPoint($request->only(['lat', 'lng']), $purchaseOrder->client));
            });
        } catch (StockShortage $e) {
            // نقص في عهدة العربية — مفيش حاجة اتغيرت
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $purchaseOrder->refresh()->load('items');

        // السامري: سلم إيه وإيه الفرق — من السيرفر مش من حساب الأبلكيشن
        return response()->json([
            'status' => 'delivered',
            'number' => $purchaseOrder->number,
            'qty_ordered' => $purchaseOrder->qtyTotal(),
            'qty_delivered' => (int) $purchaseOrder->items->sum('delivered_qty'),
            'delivered_value' => $purchaseOrder->deliveredValue(),
        ]);
    }

    // ================= طلبات العملاء الجدد =================

    /**
     * POST /api/client-requests
     * multipart: { name, phone, address, has_docs, photo (صورة المكان), docs (صورة أو PDF للأوراق) }
     */
    public function storeClientRequest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:190'],
            // ═══ الفلو الجديد (٢٠/٨): العنوان العربي والمنطقة من
            // الأبلكيشن — المندوب سحب النقطة والاقتراح عبّاهم قبل
            // الإرسال. المنطقة دي بتنزل في فورم الاعتماد جاهزة.
            'address_ar' => ['nullable', 'string', 'max:190'],
            'zone_id' => ['nullable', 'exists:zones,id'],
            // ⚠️ النقطة اللي المندوب لقّطها وهو واقف عند المحل — المدير
            // بيكشف منها العنوان في فورم الاعتماد. اختيارية: الطلب
            // بيتسجّل حتى لو الـGPS مقفول.
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'has_docs' => ['nullable'],
            'photo' => ['nullable', 'file', 'image', 'max:8192'],
            'docs' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:12288'],
        ], [], [
            'name' => __('field.attr_place_name'),
            'photo' => __('field.attr_place_photo'),
            'docs' => __('field.attr_official_docs'),
        ]);

        $user = $request->user();

        // ═══ حارس التكرار (١٥ أغسطس ٢٠٢٦) ═══
        //
        // ⚠️ **المسار ده كان مفتوح خالص.** شاشة الـERP بتفحص التكرار
        // من ٦ أغسطس، والاستيراد بيفحص — لكن المندوب من الأبلكيشن كان
        // بيسجّل نفس المحل تاني من غير أي سؤال، والمدير بيعتمده وهو
        // مش شايف إن فيه واحد زيه. أغلب التكرار الحقيقي جه من هنا.
        //
        // ⚠️ **بيرجّع 409 مش 422** — ده مش خطأ في المدخلات، ده سؤال:
        // «فيه واحد شبهه، تكمّل؟». الأبلكيشن بيوري الشبيهين وبيبعت
        // تاني بـ`confirm_duplicate=1` لو المندوب متأكد. **ومابنمنعوش
        // نهائي** — فيه فروع حقيقية بنفس الاسم ونفس رقم الإدارة.
        //
        // ⚠️ ونفس الحارس بيتكرر في `OpsController::decideRequest`:
        // الطلب ممكن يتعمل النهارده ويتعتمد بعد أسبوع، وفي الوقت ده
        // يكون حد تاني سجّل نفس المحل من الويب.
        $confirmDupe = filter_var($request->input('confirm_duplicate', false), FILTER_VALIDATE_BOOLEAN);

        if (! $confirmDupe) {
            $dupes = \App\Support\Dupes::matches([
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'zone_id' => $user->zone_id,
            ], null, $user);

            if ($dupes !== []) {
                return response()->json([
                    // ⚠️ المفتاح `message` مش `error` — الأبلكيشن بيقرا ده
                    'message' => __('field.dup_client_found', ['count' => count($dupes)]),
                    'needs_confirm' => true,
                    'duplicates' => $dupes,
                ], 409);
            }
        }

        $photoPath = $request->hasFile('photo')
            ? $request->file('photo')->store('client-requests/photos', 'public')
            : null;

        $docsPath = null;
        $docsType = null;
        if ($request->hasFile('docs')) {
            $file = $request->file('docs');
            $docsPath = $file->store('client-requests/docs', 'public');
            $docsType = strtolower($file->getClientOriginalExtension()) === 'pdf' ? 'pdf' : 'image';
        }

        $hasDocs = filter_var($request->input('has_docs', false), FILTER_VALIDATE_BOOLEAN);

        // ═══ المدير بيفتح الأكاونت من غير موافقة (قرار المالك ١١ أغسطس ٢٠٢٦) ═══
        //
        // «هو كده كده المدير» — الطلب **بيتسجّل برضه** (أثر مراجعة:
        // مين فتح إيه وإمتى وبأنهي أوراق) بس بيتعمد في نفس اللحظة
        // **بنفس منطق `OpsController::decideRequest` بالحرف**: العميل
        // بيرث الزون/القناة/الفرع من الطلب/المدير، و`manager_id` =
        // المدير نفسه — **مش بيتولد يتيم**. `rep_id` بتفضل null لحد
        // ما يسكّنه لمندوب من شاشة التخصيص.
        //
        // ⚠️ الإنشاء والاعتماد في **ترانزاكشن واحدة** — طلب «معتمد»
        // من غير عميل اتولد فعلاً أسوأ من طلب معلّق.
        $isManager = $user->role === 'manager';

        [$req, $client] = DB::transaction(function () use ($data, $user, $hasDocs, $photoPath, $docsPath, $docsType, $isManager) {
            $req = ClientRequest::create([
                'number' => ClientRequest::nextNumber(),
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null,
                'address_ar' => $data['address_ar'] ?? null,
                // منطقة المندوب اللي اختارها في الشاشة — وإلا منطقته هو
                'zone_id' => $data['zone_id'] ?? $user->zone_id,
                'lat' => $data['lat'] ?? null,
                'lng' => $data['lng'] ?? null,
                'has_docs' => $hasDocs || $docsPath !== null,
                'photo_path' => $photoPath,
                'docs_path' => $docsPath,
                'docs_type' => $docsType,
                'status' => 'pending',
                'created_by' => $user->id,
            ]);

            if (! $isManager) {
                return [$req, null];
            }

            // نفس أعمدة `decideRequest` بالظبط — بفارق واحد مقصود:
            // صاحب الطلب هنا مدير مش مندوب، فالوراثة منه شخصياً.
            $client = Client::create([
                'code' => Client::nextCode(),
                'name' => $req->name,
                'phone' => $req->phone,
                'address' => $req->address,
                // النقطة والعنوان العربي بيرثوا من الطلب لو موجودين —
                // المدير من الموبايل مالوش فورم اعتماد غني زي الويب،
                // فبناخد اللي المندوب لقّطه من غير ما نضيّعه.
                'address_ar' => $req->address_ar,
                'lat' => $req->lat,
                'lng' => $req->lng,
                'zone_id' => $req->zone_id ?? $user->zone_id,
                'rep_id' => null,
                'channel_id' => $user->channel_id,
                'manager_id' => $user->id,
                'branch_id' => $user->branch_id,
                'category' => 'grow',
                'status' => 'active',
                'discount' => 0,
                'uses_channel_discount' => true,
                'is_new' => true,
                'has_docs' => $req->has_docs,
                'photo_path' => $req->photo_path,
                'docs_path' => $req->docs_path,
                'docs_type' => $req->docs_type,
                'created_by' => $req->created_by,
            ]);

            $req->status = 'approved';
            $req->decided_by = $user->id;
            $req->decided_at = now();
            $req->client_id = $client->id;
            $req->save();

            return [$req, $client];
        });

        TrackEvent::log($user, 'request',
            __('field.event_client_request', ['name' => $req->name]),
            $isManager
                ? __('field.event_client_auto_approved')
                : __('field.event_awaiting_manager'));

        // ═══ نوتفيكيشن للمدير — موبايل وداش بورد (2026-08-09) ═══
        //
        // ⚠️ **كانت ناقصة.** الطلب كان بينزل في الشاشة وخلاص، والمدير
        // مايعرفش غير لو فتحها بنفسه — والسيلز واقف مستني الموافقة
        // عشان يبيع. مدير المندوب المباشر + الأدمنز.
        //
        // ⚠️ **والمدير مش بيبلّغ نفسه** (١١/٨) — طلبه اتوافق في نفس
        // النداء، فالإشعار كان هيبقى «في طلب مستنيك» على حاجة خلصت.
        if (! $isManager) {
            $recipients = User::where('active', true)
                ->where(fn ($q) => $q
                    ->where('role', 'admin')
                    ->when($user->manager_id, fn ($w) => $w->orWhere('id', $user->manager_id)))
                ->get();

            foreach ($recipients as $recipient) {
                AppNotification::send(
                    $recipient,
                    fn () => __('field.notif_client_request_title', ['name' => $req->name]),
                    fn () => __('field.notif_client_request_body', ['user' => $user->displayName()]),
                    link: AppNotification::requestLink($req->id),
                );
            }
        }

        return response()->json([
            'request' => [
                'id' => $req->id, 'number' => $req->number, 'name' => $req->name,
                'status' => $req->status, 'status_label' => $req->statusLabel(),
            ],
            // ═══ مفاتيح إضافية (١١/٨) — الأبلكيشن بيفرّق بيها رد
            // المدير (اتوافق فوراً + id العميل الجديد) عن رد المندوب.
            // النسخ القديمة بتتجاهلها عادي.
            'approved' => $isManager,
            'client_id' => $client?->id,
        ], 201);
    }

    // ================= الإشعارات =================

    public function readNotifications(Request $request): JsonResponse
    {
        $request->user()->appNotifications()->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['message' => __('common.saved')]);
    }

    /**
     * تحويل بنود البيع/المرتجع من وحدتها المكتوبة للقطع — في السيرفر.
     *
     * ⚠️ **الأبلكيشن بيبعت اسم الوحدة بس، عمره ما يضرب.** توكن معدّل
     * يقدر يبعت أي أرقام — فالضرب والفحص هنا. وحدة مش معرّفة للصنف
     * بترجّع 422 بدل افتراض إنها قطعة (الفرق بين 2 و144 في العهدة
     * والفلوس). بترجّع null لو كله سليم، أو JsonResponse بالرفض.
     */
    private function itemsToPieces(array &$items, ?int $maxPieces = null)
    {
        foreach ($items as $idx => $item) {
            $unit = $item['unit'] ?? 'piece';

            if ($unit !== 'piece') {
                $product = \App\Models\Product::find($item['product_id']);
                $factor = $product?->unitFactor($unit);

                if ($factor === null) {
                    return response()->json([
                        'message' => __('stock.unit_not_for_product', ['name' => $product?->displayName() ?? $item['product_id']]),
                    ], 422);
                }

                $items[$idx]['qty'] = (int) $item['qty'] * $factor;
            }

            // ⚠️ **السقف بيتفحص بعد الضرب مش قبله.** «9999 كرتونة» كانت
            // بتعدّي فاليديشن max:9999 وتتحول 719,928 قطعة — والمرتجع
            // قيد دائن من غير حارس مخزون، فالسقف هنا هو الحارس الوحيد.
            if ($maxPieces !== null && (int) $items[$idx]['qty'] > $maxPieces) {
                return response()->json(['message' => __('api.qty_too_large')], 422);
            }
        }

        return null;
    }

    /**
     * ⚠️ **النقطة بره مصر بتتبلع** (2026-08-08).
     *
     * أندرويد إميوليتر لوكيشنه الافتراضي مقر جوجل في كاليفورنيا
     * (37.42, -122.08). كل تشيك إن من جهاز تيست كان بيكتب نقطة في
     * أمريكا في `visits` — وبعدين تطلع في اللايف تراكر كدبوس في نص
     * المحيط، وفي شاشة تأكيد لوكيشن العملاء كـ«نقطة مقترحة».
     *
     * **بنخزّن `null` بدل ما نرفض العملية.** التشيك إن نفسه مايتعطلش
     * عشان الـGPS — الزيارة بتتسجل من غير إحداثيات، وشاشة الـERP
     * بتوري «مفيش لوكيشن» بدل ما توري نقطة كدّابة.
     *
     * @return array{0: ?float, 1: ?float}
     */
    private function egyptPoint(?array $data): array
    {
        $lat = isset($data['lat']) ? (float) $data['lat'] : null;
        $lng = isset($data['lng']) ? (float) $data['lng'] : null;

        if ($lat === null || $lng === null || ! \App\Support\MapLink::inEgypt($lat, $lng)) {
            return [null, null];
        }

        return [round($lat, 7), round($lng, 7)];
    }

    /**
     * حزمة المخزن في البوت ستراب — زيارة مفتوحة + سامري النهارده +
     * قايمة المخازن. اتفصلت (١١ أغسطس ٢٠٢٦) عشان بوت ستراب **المدير
     * الميداني** ياخد نفسها بالظبط: من غيرها كان بيشوف قايمة مخازن
     * فاضية ومايعرفش يدخل يستلم عهدته.
     *
     * ⚠️ زي شاشة الحضور بالظبط: السامري بيقول عمل إيه النهارده مش
     * بس إنه جوّه دلوقتي. و`minutes` بيجمع الزيارة المفتوحة
     * بـ`liveMinutes()` (العمود بيتكتب عند الخروج بس) — وبس لو
     * المفتوحة بتاعة النهارده. والقايمة **كل** المخازن النشطة مش
     * المسكّن له — الاستلام بيحصل من أي مخزن جهّزوا له فيه.
     */
    public static function warehouseBundle(User $user, ?\App\Models\WarehouseVisit $openWh): array
    {
        return [
            'warehouse_visit' => $openWh?->payload(),
            'warehouse_today' => [
                'picks' => \App\Models\PickOrder::where('assigned_to', $user->id)
                    ->whereDate('handed_at', today())->count(),
                'pos' => PurchaseOrder::where('assigned_to', $user->id)
                    ->whereDate('delivered_at', today())->count(),
                'minutes' => (int) \App\Models\WarehouseVisit::where('user_id', $user->id)
                    ->whereDate('checked_in_at', today())->sum('minutes')
                    + ($openWh?->checked_in_at?->isToday() ? $openWh->liveMinutes() : 0),
                'visits' => \App\Models\WarehouseVisit::where('user_id', $user->id)
                    ->whereDate('checked_in_at', today())->count(),
            ],
            'warehouses' => \App\Models\Warehouse::where('active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'name_en', 'address', 'lat', 'lng'])
                ->map(fn ($w) => [
                    'id' => $w->id,
                    'name' => $w->displayName(),
                    'address' => $w->address,
                    'lat' => $w->lat === null ? null : (float) $w->lat,
                    'lng' => $w->lng === null ? null : (float) $w->lng,
                ])->values(),
        ];
    }

    /**
     * الزيارة المفتوحة حالياً — أياً كان يومها (١١ أغسطس ٢٠٢٦).
     *
     * الأبلكيشن بيعرض بانر «اقفل زيارة فلان الأول» بزرار تشيك أوت
     * مباشر — حتى لو العميل مش في قوايم النهارده (زيارة قديمة اتنست).
     */
    public static function openVisitPayload(User $user): ?array
    {
        $ov = $user->openVisit();

        if ($ov === null) {
            return null;
        }

        return [
            'visit_id' => $ov->id,
            'client_id' => $ov->client_id,
            'client' => $ov->client?->displayName() ?? '—',
            'since' => $ov->checked_in_at?->toIso8601String(),
        ];
    }

    /**
     * نقطة **حدث التراكينج** — GPS الجهاز، ولو فشل → لوكيشن العميل المسجّل.
     *
     * ⚠️ (إصلاح ٩ أغسطس): `Locator.get()` في الأبلكيشن بيرجع null بصمت
     * لو الإذن اتأخر أو الـfix ماجاش في المهلة — فكان «دخل عند عميل»
     * بيتسجل من غير مكان وصفحة التراكينج تقول «مفيش إحداثيات مسجلة»
     * واليوم كله أحداث. العميل لوكيشنه متسجّل عندنا أصلاً — نقطة
     * محله أصدق من لا شيء.
     *
     * ⚠️ الفولباك ده **للحدث بس** — `visits.lat` بتفضل null لو مفيش
     * GPS، لأنها بتتاكل في شاشة «تأكيد لوكيشن العميل» كنقطة مقترحة،
     * ولو حطينا فيها لوكيشن العميل نفسه بقت الاقتراحات بتأكد نفسها.
     *
     * @return array{0: ?float, 1: ?float}
     */
    private function eventPoint(?array $data, ?Client $client): array
    {
        [$lat, $lng] = $this->egyptPoint($data);

        if ($lat !== null) {
            return [$lat, $lng];
        }

        if ($client && $client->lat !== null && $client->lng !== null) {
            return [(float) $client->lat, (float) $client->lng];
        }

        return [null, null];
    }
}
