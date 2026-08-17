<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Visit;
use App\Models\Zone;
use App\Support\GeoSuggest;
use App\Support\Governorates;
use App\Support\MapLink;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ═══════════════════════════════════════════════════════════════
 * تأكيد لوكيشن العملاء من زيارات المناديب (2026-08-08)
 * ═══════════════════════════════════════════════════════════════
 *
 * **الفكرة:** المندوب بيروح عند العميل ويعمل تشيك إن — والسيستم
 * بياخد نقطته وقتها. النقطة دي هي **أدق حاجة معانا** عن مكان العميل:
 * بني آدم كان واقف هناك فعلاً. الشاشة دي بتاخد النقطة وبتحطها قدام
 * اللي بيراجع عشان يأكّدها بضغطة.
 *
 * ⚠️ **مش أوتوماتيك عن قصد.** تحويل كل تشيك إن لإحداثي عميل كان
 * هيخلّي مندوب عمل تشيك إن وهو في العربية على بعد شارع (أو غلط في
 * العميل) يكتب نقطة غلط للأبد. بني آدم بيبص ويأكّد — وده اللي
 * بيخلّي `location_confirmed_at` تعني حاجة.
 *
 * ⚠️ **والتأكيد بياخد العنوان معاه.** نقطة من غير عنوان مكتوب
 * مابتكفيش: التقارير والفواتير بتعرض نص، والمحافظة والمنطقة هما
 * أساس تسكين المناديب. عشان كده المودال بيطلب الاتنين مع بعض.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⚠️⚠️ **الشاشة دي مصدر الحقيقة للوكيشن العميل** (١٤ أغسطس ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * بلاغ المالك: «كنت عامل حسابي إن التشيك إن بتاع المندوب بيكون قدام
 * المحل — لكن المندوب بيعمل تشيك إن وهو في الطريق». يعني **نقطة
 * الزيارة تخمين مش دليل**، وشاشة كانت بتعتمد عليها لوحدها كانت
 * بتكتب نقط الطريق على العملاء.
 *
 * الحل: المندوب بقى عنده شاشة «ضيف لوكيشن العميل» في الأبلكيشن
 * بيسحب فيها نقطة **وهو واقف قدام المحل** — و`FieldApiController::
 * saveClientLocation` بتكتبها بـ`location_source = 'rep_app'` مع
 * `location_confirmed_at/by`. والنتيجة:
 *
 *   • العميل ده **بيخرج من «جاهز للتأكيد» فوراً** — كل الفلاتر
 *     المستنية شرطها `whereNull('location_confirmed_at')`.
 *   • وبيبان في «المتأكدة» ببصمة كاملة: مين ضبطها، من الأبلكيشن
 *     ولا من الداشبورد، وإمتى.
 *   • والأدمن لسه بيقدر **يصحّح** بزرار «تعديل» — الأبلكيشن بيسبق
 *     في الوقت، والمراجعة بتغلب في النهاية.
 */
class ClientLocationController extends Controller
{
    /** العملاء المستنية تأكيد + النقطة اللي جت من آخر زيارة */
    public function index(Request $request)
    {
        // ⚠️ **الافتراضي بقى «الطلبات»** (١٧/٨). ده الطابور اللي
        // بيتعمّر من الميدان كل يوم ومحدش غير المراجع بيفضّيه —
        // و«جاهز للتأكيد» شغل تخميني أقل أولوية منه.
        $filter = $request->string('show')->toString() ?: 'requests';

        // ═══════════════════════════════════════════════════════════
        // الفلاتر (اتعادت في ٨ أغسطس ٢٠٢٦ بطلب المالك)
        // ═══════════════════════════════════════════════════════════
        //
        // ⚠️ **«المستنية» كانت بتخلط حالتين مختلفتين تماماً:**
        //   • عميل **المندوب سحب نقطته** بتشيك إن → شغل جاهز، ضغطة
        //     واحدة وخلص
        //   • عميل **مالوش أي إحداثيات ولا زيارة** → مفيش حاجة
        //     تتأكّد أصلاً، ده شغل ميدان مش شغل مراجعة
        //
        // خلطهم كان بيخلّي اللي بيراجع يفتح قايمة ٦٥٤ عميل، أغلبهم
        // مالوش نقطة، ويدوّر بالعين على الصفوف اللي فيها شغل.
        //
        // ⚠️ **`from_visit` هو الافتراضي دلوقتي** — ده الشغل الجاهز.
        //
        // ⚠️ **أي تشيك إن مش بس اللي معاه GPS** (طلب المالك ١١/٨):
        // «الجاهز للتأكيد يكون العملاء اللي المندوب راح عندها وعمل
        // تشيك إن». الزيارة اللي الـGPS كان مقفول فيها برضه بتثبت إن
        // المندوب راح — والمراجع يقدر يكتب النقطة من لينك الخرايط أو
        // إحداثيات العميل القديمة (المودال بيسمح). النقطة المسحوبة
        // لو موجودة بتتعرض، ولو مش موجودة العمود بيقول «مفيش نقطة».
        $visitClientIds = Visit::select('client_id')->distinct();

        // ⚠️ `locationConfirmer` جوّه الـ`with` — عمود «الحالة» بقى
        // بيعرض مين ضبط اللوكيشن، ومن غير التحميل المسبق ده كان
        // كويري لكل صف في قايمة ٦٥٤ عميل.
        $q = Client::query()
            ->with(['zone', 'channel', 'group', 'locationConfirmer', 'locationSubmitter'])
            ->where('status', '!=', 'rejected');

        $q->when(
            // ═══════════════════════════════════════════════════
            // 🚩 طلبات تعديل العنوان — ١٧ أغسطس ٢٠٢٦
            // ═══════════════════════════════════════════════════
            //
            // طلب المالك: «شاشة أكّد منها كل الطلبات اللي اتعمل
            // تعديل العنوان بتاعها عشان نبني داتابيز قوية فيها
            // العناوين صح».
            //
            // ⚠️ **دي أقوى نقطة في السيستم ومستنية مراجعة.** المندوب
            // سحبها بإيده وهو **واقف قدام المحل** — مش نقطة تشيك إن
            // ممكن تكون من العربية في الطريق. فالمراجعة هنا غالباً
            // «بصّة وتأكيد»، مش تصحيح.
            //
            // ⚠️ **الشرطين مع بعض**: `submitted_at` بتفضل مكتوبة بعد
            // التأكيد كبصمة، فالطابور لازم يستثني اللي اتأكّد.
            $filter === 'requests',
            fn ($qq) => $qq->whereNotNull('location_submitted_at')
                ->whereNull('location_confirmed_at'),
        )->when(
            // ✅ جاهز للتأكيد: مندوب سحب نقطة والعميل لسه مااتأكّدش
            //
            // ⚠️ **العميل اللي المندوب ضبط لوكيشنه من الأبلكيشن بيخرج
            // من هنا لوحده** (١٤/٨) — `location_confirmed_at` بتتكتب
            // في `saveClientLocation`، والشرط ده `whereNull`. مفيش
            // استثناء مكتوب بالإيد ومفيش صف بيتعدّ مرتين: الشغل
            // اللي خلص فعلاً مابيقعدش في طابور المراجعة.
            // ⚠️ **الطلبات اتشالت من هنا** (١٧/٨): العميل اللي المندوب
            // بعت نقطته من الأبلكيشن كان بيظهر في الطابورين — مرة في
            // «طلبات تعديل العنوان» ومرة هنا (لأن عنده زيارة برضه).
            // المراجع كان هيأكّده من شاشة ويلاقيه لسه في التانية.
            $filter === 'from_visit',
            fn ($qq) => $qq->whereNull('location_confirmed_at')
                ->whereNull('location_submitted_at')
                ->whereIn('id', $visitClientIds),
        )->when(
            // 📱 اللي المندوب ضبطه من الأبلكيشن — بصمة كاملة، والمراجع
            // بيعدي عليها بالعين ويصحّح اللي مش مظبوط
            $filter === 'from_app',
            fn ($qq) => $qq->where('location_source', Client::LOC_SRC_APP),
        )->when(
            // ⚪ مفيش أي لوكيشن: لا إحداثيات على العميل ولا زيارة
            //    بإحداثيات — ده شغل ميدان، مش مراجعة
            $filter === 'no_location',
            fn ($qq) => $qq->whereNull('lat')->whereNotIn('id', $visitClientIds),
        )->when(
            // إحداثيات موجودة على العميل بس محدش أكّدها (استيراد/لينك)
            $filter === 'unconfirmed',
            fn ($qq) => $qq->whereNull('location_confirmed_at')->whereNotNull('lat'),
        )->when(
            $filter === 'done',
            fn ($qq) => $qq->whereNotNull('location_confirmed_at'),
        );

        $clients = Client::visibleTo($q)->orderBy('name')->get();

        // ═══ آخر زيارة بإحداثيات لكل عميل — استعلام واحد ═══
        //
        // ⚠️ **آخر زيارة مش أول واحدة.** العميل ممكن يكون نقل، وأحدث
        // نقطة هي الصح. و`whereNotNull` ضرورية: الزيارة اللي الـGPS
        // كان مقفول فيها بتتسجل من غير إحداثيات ومالهاش أي فايدة هنا.
        $visits = Visit::whereIn('client_id', $clients->pluck('id'))
            ->whereNotNull('lat')->whereNotNull('lng')
            ->with('user:id,name,name_en')
            ->orderByDesc('checked_in_at')
            ->get()
            ->unique('client_id')
            ->keyBy('client_id');

        $rows = $clients->map(fn (Client $c) => [
            'client' => $c,
            'visit' => $visits->get($c->id),
        ]);

        return view('erp.client_locations', [
            'rows' => $rows,
            'filter' => $filter,
            'zones' => Zone::orderBy('code')->get(),
            'governorates' => Governorates::options(),
            // ⚠️ العدادات من استعلامات مستقلة مش من `$rows` — الأخيرة
            // مفلترة، وعدّها كان بيخلي الشارة تقول «0 مستنية» وانت
            // واقف على فلتر «المتأكدة»
            // ⚠️ عدّاد لكل فلتر — الشارة على الزرار بتقول فيه شغل
            // قد إيه من غير ما تفتحه
            'counts' => [
                // 🚩 الطابور الأهم — أول رقم المراجع بيبصّ عليه
                'requests' => Client::visibleTo(Client::query())
                    ->where('status', '!=', 'rejected')
                    ->whereNotNull('location_submitted_at')
                    ->whereNull('location_confirmed_at')->count(),
                'from_visit' => Client::visibleTo(Client::query())
                    ->where('status', '!=', 'rejected')
                    ->whereNull('location_confirmed_at')
                    ->whereNull('location_submitted_at')
                    ->whereIn('id', $visitClientIds)->count(),
                'no_location' => Client::visibleTo(Client::query())
                    ->where('status', '!=', 'rejected')
                    ->whereNull('lat')->whereNotIn('id', $visitClientIds)->count(),
                'unconfirmed' => Client::visibleTo(Client::query())
                    ->where('status', '!=', 'rejected')
                    ->whereNull('location_confirmed_at')->whereNotNull('lat')->count(),
                'done' => Client::visibleTo(Client::query())
                    ->whereNotNull('location_confirmed_at')->count(),
                // 📱 عدّاد «من الأبلكيشن» — بيقول للمراجع الميدان
                // شغّال قد إيه من غير ما يفتح الفلتر
                'from_app' => Client::visibleTo(Client::query())
                    ->where('status', '!=', 'rejected')
                    ->where('location_source', Client::LOC_SRC_APP)->count(),
            ],
        ]);
    }

    /**
     * ═══════════════════════════════════════════════════════════
     * رصيد المناديب من العناوين  ·  ١٧ أغسطس ٢٠٢٦
     * ═══════════════════════════════════════════════════════════
     *
     * طلب المالك: «شاشة فيها كل مندوب عمل طلب تعديل لوكيشن كام مرة،
     * ولما أدوس عليهم يطلع ليست بكل العملاء اللي عملهم تعديل».
     *
     * شاشة واحدة بمستويين: من غير `?rep=` بتوري المناديب، ومعاه
     * بتوري عملاء المندوب ده. اتنين مالهمش لازمة.
     *
     * ⚠️⚠️ **الفرق بين «بعت» و«اتأكّد» هو كل الشاشة.** المندوب
     * بيبعت، والأدمن بيأكّد — و**النقط بتتحسب على المتأكّد بس**.
     * لو المكافأة على الإرسال، يقدر يبعت لعشرين عميل في ساعة من
     * غير ما يتحرك. العمودين الاتنين ظاهرين عشان تشوف مين بيبعت
     * كتير وبيتأكّدله قليل.
     *
     * ⚠️ **الصفر مابيظهرش.** المندوب اللي مابعتش ولا عنوان مالوش
     * صف — الشاشة دي عن اللي اشتغل، مش كشف حضور.
     */
    public function credits(Request $request)
    {
        $repId = (int) $request->integer('rep');

        // ⚠️ استعلام تجميعي واحد — مش لوب على المناديب بعدّاد لكل
        // واحد. عندنا مئات العملاء وعشرات المناديب.
        $rows = Client::query()
            ->whereNotNull('location_submitted_by')
            ->selectRaw('location_submitted_by as uid,
                         COUNT(*) as sent,
                         SUM(location_confirmed_at IS NOT NULL) as confirmed,
                         MAX(location_submitted_at) as last_at')
            ->groupBy('location_submitted_by')
            ->orderByDesc('confirmed')
            ->get();

        $users = \App\Models\User::whereIn('id', $rows->pluck('uid'))->get()->keyBy('id');

        // ⚠️ نفس إعدادات `RepKpis` بالحرف — مصدر واحد للقاعدة، وإلا
        // الشاشة بتقول نقطتين والتصفية بتدي تلاتة.
        $perPoint = max(1, (int) \App\Models\Setting::read('locations_per_point', '5'));
        $ptsPer = (int) \App\Models\Setting::read('pts_per_locations', '1');

        $clients = collect();

        if ($repId > 0) {
            $clients = Client::with(['zone', 'locationConfirmer'])
                ->where('location_submitted_by', $repId)
                ->orderByDesc('location_submitted_at')
                ->get();
        }

        return view('erp.location_credits', [
            'rows' => $rows,
            'users' => $users,
            'repId' => $repId,
            'rep' => $repId > 0 ? ($users->get($repId) ?? \App\Models\User::find($repId)) : null,
            'clients' => $clients,
            'perPoint' => $perPoint,
            'ptsPer' => $ptsPer,
            'pointValue' => (float) \App\Models\Setting::read('point_value', '5'),
        ]);
    }

    /**
     * اقتراح عنوان من إحداثيات — بيرجّع JSON للمودال.
     *
     * ⚠️ **اقتراح مش حفظ.** الرد بيتحط في خانات قابلة للتعديل، واللي
     * بيراجع بيصلّح قبل ما يأكّد. OSM بتدي أقرب معلَم مش عنوان المحل.
     *
     * ⚠️ **المنطق نفسه في `App\Support\GeoSuggest`** (١٤ أغسطس ٢٠٢٦)
     * — الأبلكيشن بينده على نفس الخدمة من
     * `FieldApiController::geocodeClient`، فالداشبورد والموبايل
     * بيقترحوا **نفس** المنطقة ونفس المحافظة لنفس النقطة.
     *
     * ⚠️ **المفاتيح القديمة `ar`/`en` فاضلة زي ما هي.** الجافاسكربت
     * في شاشتين (`erp.client_locations` و`ops.requests`) بتقراهم
     * بالاسم ده — والمفاتيح القياسية (`address_ar`…) اتضافت **جنبهم**
     * مش مكانهم. أي حذف هنا بيكسّر فورم اعتماد العميل الجديد بصمت.
     */
    public function suggest(Request $request)
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $hit = GeoSuggest::forPoint((float) $data['lat'], (float) $data['lng']);

        // ⚠️ الداشبورد بيرجّع 422 لما العنوان مايجيش — الرسالة بتظهر
        // جنب الزرار واللي بيراجع بيكتب بإيده. (الأبلكيشن بيتصرّف
        // غير كده: بيرجّع 200 بخانات فاضية عشان المنطقة تفضل تفيده.)
        if (! $hit['matched']) {
            return response()->json(['message' => __('geo.reverse_failed')], 422);
        }

        return response()->json([
            // المفاتيح القديمة — الجافاسكربت الموجود بيقراهم
            'ar' => (string) ($hit['address_ar'] ?? ''),
            'en' => (string) ($hit['address_en'] ?? ''),
            // ═══ المنطقة كمان (طلب المالك ٨/٨/٢٠٢٦) ═══
            //
            // ⚠️ **الزرار كان بيملا العنوان والمحافظة ويسيب المنطقة
            // فاضية** — واللي بيراجع بيفتح الدروب داون ويدوّر في ٣٦٢
            // منطقة بإيده في كل عميل. المنطقة أهم من المحافظة أصلاً:
            // هي أساس تسكين المناديب.
            'zone_id' => $hit['zone_id'],
            'governorate' => $hit['governorate'],
            // المفاتيح القياسية — نفس شكل رد الأبلكيشن بالحرف
            'address_ar' => $hit['address_ar'],
            'address_en' => $hit['address_en'],
            'governorate_label' => $hit['governorate_label'],
            'zone_name' => $hit['zone_name'],
        ]);
    }

    /** التأكيد — النقطة والعنوان بيتكتبوا على العميل مع بصمة المراجع */
    public function confirm(Request $request, Client $client)
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            // ⚠️ **العنوان إجباري باللغتين.** ده كل الغرض من الشاشة:
            // مانخرجش بنقطة مالهاش عنوان مكتوب. اللي بيأكّد شايف
            // الاقتراح قدامه فمفيش عبء حقيقي.
            'address' => ['required', 'string', 'max:190'],
            'address_ar' => ['required', 'string', 'max:190'],
            'governorate' => ['nullable', Governorates::rule()],
            'zone_id' => ['nullable', 'exists:zones,id'],
            // ⚠️ **`rep_app` مش مسموح هنا عن قصد.** المصدر ده معناه
            // «المندوب سحب النقطة من الأبلكيشن»، والداشبورد مايقدرش
            // يدّعيه. أدمن بيصحّح نقطة جات من الأبلكيشن بيكتب `manual`
            // — وده بالظبط اللي عايزين نشوفه في السجل بعد سنة.
            'source' => ['nullable', Rule::in([
                Client::LOC_SRC_VISIT, Client::LOC_SRC_MANUAL, Client::LOC_SRC_MAP,
            ])],
        ]);

        if (! MapLink::inEgypt((float) $data['lat'], (float) $data['lng'])) {
            return back()->withErrors(['lat' => __('geo.bad_point')]);
        }

        $client->forceFill([
            'lat' => round((float) $data['lat'], 7),
            'lng' => round((float) $data['lng'], 7),
            'address' => $data['address'],
            'address_ar' => $data['address_ar'],
            // ⚠️ المحافظة والمنطقة بيتكتبوا **بس لو اتبعتوا**. الشاشة
            // بتوريهم بقيمة العميل الحالية، وسيبانهم فاضيين معناه
            // «ماتغيّرش» مش «امسح» — والمسح كان بيخرّج العميل من
            // تسكين مندوبه في صمت.
            ...(($data['governorate'] ?? null) ? ['governorate' => $data['governorate']] : []),
            ...(($data['zone_id'] ?? null) ? ['zone_id' => (int) $data['zone_id']] : []),
            'location_confirmed_at' => now(),
            'location_confirmed_by' => $request->user()->id,
            // ⚠️ **الأصل بيتحفظ لما المراجع مايختارش** (١٧/٨).
            // الافتراضي القديم `visit` كان بيمسح `rep_app` على كل
            // طلب يتأكّد — يعني بعد أسبوع مفيش طريقة تعرف بيها إن
            // النقطة دي جات من مندوب واقف قدام المحل ولا من تخمين
            // نقطة تشيك إن. المراجع اللي **بيصحّح** النقطة بيبعت
            // `manual` صراحةً، وساعتها بس المصدر بيتغيّر.
            'location_source' => $data['source']
                ?? $client->location_source
                ?? Client::LOC_SRC_VISIT,
        ])->save();

        return back()->with('ok', __('geo.confirmed_ok', ['client' => $client->displayName()]));
    }
}
