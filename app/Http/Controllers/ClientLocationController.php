<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Visit;
use App\Models\Zone;
use App\Support\Geocoder;
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
 */
class ClientLocationController extends Controller
{
    /** العملاء المستنية تأكيد + النقطة اللي جت من آخر زيارة */
    public function index(Request $request)
    {
        $filter = $request->string('show')->toString() ?: 'from_visit';

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
        $visitClientIds = Visit::whereNotNull('lat')->whereNotNull('lng')
            ->select('client_id')->distinct();

        $q = Client::query()
            ->with(['zone', 'channel', 'group'])
            ->where('status', '!=', 'rejected');

        $q->when(
            // ✅ جاهز للتأكيد: مندوب سحب نقطة والعميل لسه مااتأكّدش
            $filter === 'from_visit',
            fn ($qq) => $qq->whereNull('location_confirmed_at')
                ->whereIn('id', $visitClientIds),
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
                'from_visit' => Client::visibleTo(Client::query())
                    ->where('status', '!=', 'rejected')
                    ->whereNull('location_confirmed_at')
                    ->whereIn('id', $visitClientIds)->count(),
                'no_location' => Client::visibleTo(Client::query())
                    ->where('status', '!=', 'rejected')
                    ->whereNull('lat')->whereNotIn('id', $visitClientIds)->count(),
                'unconfirmed' => Client::visibleTo(Client::query())
                    ->where('status', '!=', 'rejected')
                    ->whereNull('location_confirmed_at')->whereNotNull('lat')->count(),
                'done' => Client::visibleTo(Client::query())
                    ->whereNotNull('location_confirmed_at')->count(),
            ],
        ]);
    }

    /**
     * اقتراح عنوان من إحداثيات — بيرجّع JSON للمودال.
     *
     * ⚠️ **اقتراح مش حفظ.** الرد بيتحط في خانات قابلة للتعديل، واللي
     * بيراجع بيصلّح قبل ما يأكّد. OSM بتدي أقرب معلَم مش عنوان المحل.
     */
    public function suggest(Request $request)
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $hit = Geocoder::reverse((float) $data['lat'], (float) $data['lng']);

        if ($hit === null) {
            return response()->json(['message' => __('geo.reverse_failed')], 422);
        }

        $gov = Governorates::match((string) ($hit['governorate'] ?? ''));

        return response()->json([
            'ar' => $hit['ar'],
            'en' => $hit['en'],
            // ═══ المنطقة كمان (طلب المالك ٨/٨/٢٠٢٦) ═══
            //
            // ⚠️ **الزرار كان بيملا العنوان والمحافظة ويسيب المنطقة
            // فاضية** — واللي بيراجع بيفتح الدروب داون ويدوّر في ٣٦٢
            // منطقة بإيده في كل عميل. المنطقة أهم من المحافظة أصلاً:
            // هي أساس تسكين المناديب.
            'zone_id' => self::nearestZone((float) $data['lat'], (float) $data['lng'], $gov),
            // ⚠️ اسم المحافظة اللي OSM بترجّعه نص حر («محافظة القاهرة»)
            // — بنحوّله لكود السيستم، ولو مالقيناش بنسيبه للمستخدم
            // ⚠️ `match()` مش مقارنة نصية: OSM بترجّع «محافظة القاهرة»
            // أو «Cairo Governorate» حسب اللغة، وهي بتعرف تطابق
            // الاتنين مع كود السيستم — نفس الدالة اللي المستورد بيستخدمها
            'governorate' => $gov,
        ]);
    }

    /**
     * أقرب منطقة للنقطة — أو `null` لو مفيش حاجة قريبة كفاية.
     *
     * ⚠️ **بيتفلتر بالمحافظة الأول لو معروفة.** أقرب منطقة جغرافياً
     * ممكن تكون في محافظة تانية على الحدود — والتسكين على منطقة في
     * محافظة غلط أسوأ من خانة فاضية، لأنه بيبان صح.
     *
     * ⚠️ **٢٥ كم سقف.** المناطق اللي مالهاش إحداثيات بتتستبعد
     * (`whereNotNull`)، ومن غير السقف كانت أي نقطة هتلاقي «أقرب»
     * منطقة حتى لو على بعد محافظتين.
     *
     * ⚠️ **هافرساين بالكيلومتر** — الفخ المعروف في المشروع إن
     * الدالة بترجّع كيلومترات والكود بيفتكرها أمتار.
     */
    private static function nearestZone(float $lat, float $lng, ?string $gov): ?int
    {
        $zones = Zone::query()
            ->where('active', true)
            ->whereNotNull('lat')->whereNotNull('lng')
            ->when($gov, fn ($q) => $q->where('governorate', $gov))
            ->get(['id', 'lat', 'lng']);

        // ⚠️ المحافظة مالهاش مناطق بإحداثيات؟ نجرب من غير فلترها بدل
        // ما نرجّع فاضي — الاقتراح بيتعدّل بالإيد على أي حال.
        if ($zones->isEmpty() && $gov !== null) {
            $zones = Zone::query()
                ->where('active', true)
                ->whereNotNull('lat')->whereNotNull('lng')
                ->get(['id', 'lat', 'lng']);
        }

        $best = null;
        $bestKm = 25.0;

        foreach ($zones as $z) {
            $km = self::haversineKm($lat, $lng, (float) $z->lat, (float) $z->lng);

            if ($km < $bestKm) {
                $bestKm = $km;
                $best = (int) $z->id;
            }
        }

        return $best;
    }

    /** المسافة بالكيلومتر بين نقطتين */
    private static function haversineKm(float $aLat, float $aLng, float $bLat, float $bLng): float
    {
        $r = 6371.0;
        $dLat = deg2rad($bLat - $aLat);
        $dLng = deg2rad($bLng - $aLng);

        $h = sin($dLat / 2) ** 2
            + cos(deg2rad($aLat)) * cos(deg2rad($bLat)) * sin($dLng / 2) ** 2;

        return $r * 2 * asin(min(1.0, sqrt($h)));
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
            'source' => ['nullable', Rule::in(['visit', 'manual', 'map'])],
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
            'location_source' => $data['source'] ?? 'visit',
        ])->save();

        return back()->with('ok', __('geo.confirmed_ok', ['client' => $client->displayName()]));
    }
}
