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
        $filter = $request->string('show')->toString() ?: 'pending';

        $q = Client::query()
            ->with(['zone', 'channel', 'group'])
            ->where('status', '!=', 'rejected');

        // ⚠️ **الافتراضي «المستنية» مش «الكل».** الشاشة دي شغل يومي
        // بيتفضّى — وفتحها على 300 عميل أغلبهم متأكد بيخلّي اللي
        // بيراجع يدوّر على اللي ناقص بالعين كل مرة.
        $q->when($filter === 'pending', fn ($qq) => $qq->whereNull('location_confirmed_at'))
            ->when($filter === 'no_coords', fn ($qq) => $qq->whereNull('lat'))
            ->when($filter === 'done', fn ($qq) => $qq->whereNotNull('location_confirmed_at'));

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
            'pendingCount' => Client::visibleTo(Client::query())
                ->whereNull('location_confirmed_at')->count(),
            'doneCount' => Client::visibleTo(Client::query())
                ->whereNotNull('location_confirmed_at')->count(),
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

        return response()->json([
            'ar' => $hit['ar'],
            'en' => $hit['en'],
            // ⚠️ اسم المحافظة اللي OSM بترجّعه نص حر («محافظة القاهرة»)
            // — بنحوّله لكود السيستم، ولو مالقيناش بنسيبه للمستخدم
            // ⚠️ `match()` مش مقارنة نصية: OSM بترجّع «محافظة القاهرة»
            // أو «Cairo Governorate» حسب اللغة، وهي بتعرف تطابق
            // الاتنين مع كود السيستم — نفس الدالة اللي المستورد بيستخدمها
            'governorate' => Governorates::match((string) ($hit['governorate'] ?? '')),
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
