<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Client;
use App\Models\User;
use App\Models\Visit;
use App\Models\Zone;
use App\Support\VisitOutcomes;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * ═══════════════════════════════════════════════════════════════
 * «الزيارات» — اللي حصل فعلاً في الشارع (١٥ أغسطس ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **بلاغ المالك**: «أنا مش شايف الزيارات اللي اتعملت، وفيه مناديب
 * صوّروا الرف قبل وبعد ومفيش حاجة على الداش بورد أشوفها فيها.»
 * السيستم كان فيه `ops.open_visits` (المفتوحة بس) و«يوم المندوب»
 * (مندوب واحد في يوم واحد) — **مفيش شاشة بتقول الزيارات المقفولة
 * عملت إيه**، وصور الرف اللي جوه الزيارة العادية (`visit_photos`)
 * ماكانش ليها أي عارض غير كارت في يوم المندوب.
 *
 * ⚠️ **الشاشة دي مبنية على الباتشينج** — كل ناتج بيتجمّع بكويري
 * واحدة مفتاحها `visit_id` (`App\Support\VisitOutcomes`)، والـKPIs
 * بكويريز عدّ ثابتة على نفس الكويري المفلترة. عدد الكويريز ثابت
 * سواء الصفحة فيها ٢٥ زيارة أو الفلتر عليه ٥٠ ألف.
 *
 * ⚠️ **الـKPIs من نفس النطاق المفلتر بالحرف** — مفيش رقم فوق من
 * «النهارده» وجدول تحت من «الشهر».
 */
class VisitBoardController extends Controller
{
    /** عدد الصفوف في الصفحة */
    private const PER_PAGE = 25;

    public function index(Request $request)
    {
        $viewer = $request->user();

        // ═══ فريق الميدان المسموح — سكوب المدير قبل أي حاجة ═══
        $teamIds = User::fieldVisibleTo(Branch::scope(User::query(), $viewer), $viewer)
            ->select('id');

        // ⚠️ **`is_string` قبل أي `(string)`** — `?q[]=x` بتوصل أراي،
        // والكاست عليها بيطلع تحذير و«Array» كنص بحث.
        $txt = fn (string $k) => is_string($request->input($k)) ? trim($request->input($k)) : '';

        // ═══ نطاق التاريخ — النهارده افتراضياً ═══
        // ⚠️ `Carbon::parse` على نص عبيط بترمي 500 — نفس حارس يوم
        // المندوب بالحرف: الباراميتر الغلط بيرجّع النهارده.
        $from = $this->day($txt('from'), today());
        $to = $this->day($txt('to'), $from->copy());

        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        // ⚠️ **الفلتر على `checked_in_at` مش `created_at`** — الزيارة
        // بتتعمل لحظة التشيك إن، والعمودين بيتفقوا دلوقتي، بس
        // `checked_in_at` هو المعنى التجاري («امتى وقف عند العميل»).
        $q = Visit::query()
            ->whereIn('user_id', $teamIds)
            ->whereDate('checked_in_at', '>=', $from->toDateString())
            ->whereDate('checked_in_at', '<=', $to->toDateString());

        // ═══ فلتر المندوب — من نفس السكوب، مش من الريكوست الخام ═══
        $repId = (int) $request->integer('user');

        if ($repId > 0) {
            $q->where('user_id', $repId)
                ->whereIn('user_id', $teamIds);
        }

        // ═══ العميل — بحث بالاسم/الكود/التليفون/السلسلة، مسكوب ═══
        $search = $txt('q');

        if ($search !== '') {
            $q->whereIn('client_id',
                Client::search(Client::visibleTo(Client::query(), $viewer), $search)->select('id'));
        }

        $zoneId = (int) $request->integer('zone');

        if ($zoneId > 0) {
            // ⚠️ `visibleTo` على منتقي العملاء كمان — الدوكترين بيقول
            // أي كويري بتعرض أو تجمّع عملاء تعدّي عليها، مش القوايم بس
            $q->whereIn('client_id',
                Client::visibleTo(Client::where('zone_id', $zoneId), $viewer)->select('id'));
        }

        // ═══ الحالة — مقفولة / لسه مفتوحة ═══
        $status = $txt('status');

        if ($status === 'closed') {
            $q->whereNotNull('checked_out_at');
        } elseif ($status === 'open') {
            $q->whereNull('checked_out_at');
        }

        // ═══ «فيها كذا» — نفس التعريف بتاع الـKPIs بالحرف ═══
        foreach (['photos', 'invoice', 'collection', 'return'] as $flag) {
            if ($request->boolean('has_'.$flag)) {
                $q->whereIn('id', VisitOutcomes::idSources()[$flag]);
            }
        }

        // ═══════════ الـKPIs — على الكويري المفلترة كلها ═══════════
        // ⚠️ **مش على الصفحة.** «٣ زيارات بلا نتيجة» في صفحة من ٢٥
        // رقم مالوش معنى؛ المالك بيسأل عن اليوم كله.
        $agg = (clone $q)
            ->selectRaw('COUNT(*) AS n,
                COUNT(DISTINCT client_id) AS clients,
                COALESCE(AVG(CASE WHEN checked_out_at IS NOT NULL
                    THEN TIMESTAMPDIFF(MINUTE, checked_in_at, checked_out_at) END), 0) AS avg_min,
                SUM(CASE WHEN checked_out_at IS NULL THEN 1 ELSE 0 END) AS still_open')
            ->first();

        $withPhotos = (clone $q)->whereIn('id', VisitOutcomes::idSources()['photos'])->count();
        $withInvoice = (clone $q)->whereIn('id', VisitOutcomes::idSources()['invoice'])->count();

        // «بلا نتيجة» = لا فاتورة ولا تحصيل ولا مرتجع ولا صور ولا هدية
        // ولا طلب بضاعة. ⚠️ الزيارة **المفتوحة** مستبعدة — لسه شغالة،
        // ومحاسبتها على إنها ضايعة كذب.
        $wasted = (clone $q)->whereNotNull('checked_out_at');

        foreach (VisitOutcomes::idSources() as $src) {
            $wasted->whereNotIn('id', $src);
        }

        $wasted = $wasted->count();

        $kpi = [
            'visits' => (int) ($agg->n ?? 0),
            'clients' => (int) ($agg->clients ?? 0),
            'avg_min' => (int) round((float) ($agg->avg_min ?? 0)),
            'open' => (int) ($agg->still_open ?? 0),
            'photos' => $withPhotos,
            'invoiced' => $withInvoice,
            'wasted' => $wasted,
        ];

        // ═══════════ الصفوف ═══════════
        $visits = $q->with([
            'user',
            'client.channel',
            'client.zone',
            'client.group',
        ])
            ->orderByDesc('checked_in_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $out = VisitOutcomes::map($visits->getCollection()->pluck('id')->all());

        // ═══════════ حمولة المودال ═══════════
        // ⚠️ **مبنية في الكنترولر** — الفيو ممنوع يستعلم، والـJSON
        // بيتحقن مرة واحدة بـ`json_encode` (مش دايركتيف بمصفوفة —
        // الفخ اللي وقّع السيستم مرتين).
        $tz = 'Africa/Cairo';
        $hia = fn (?Carbon $d) => $d?->copy()->timezone($tz)->format('h:i A');

        $detail = [];

        foreach ($visits as $v) {
            $o = $out[$v->id] ?? VisitOutcomes::blank();

            $detail[(string) $v->id] = [
                'rep' => $v->user?->displayName() ?? '—',
                'client' => $v->client?->displayName() ?? '—',
                'zone' => $v->client?->zone?->displayName() ?? '',
                'chain' => $v->client?->group?->displayName() ?? '',
                'in' => $hia($v->checked_in_at),
                'out' => $hia($v->checked_out_at),
                'date' => $v->checked_in_at?->copy()->timezone($tz)->format('Y-m-d'),
                'minutes' => $v->minutes(),
                'note' => $v->note,
                'map' => $v->lat !== null && $v->lng !== null
                    ? 'https://www.google.com/maps?q='.$v->lat.','.$v->lng
                    : null,
                'client_url' => $v->client_id ? route('erp.clients.show', $v->client_id) : null,
                'before' => $o['before']->map(fn ($p) => $p->url())->all(),
                'after' => $o['after']->map(fn ($p) => $p->url())->all(),
                'invoices' => $o['invoices']->map(fn ($i) => [
                    'number' => $i->number,
                    'total' => number_format((float) $i->grand_total, 2),
                    'url' => route('ops.invoice', $i->id),
                ])->values()->all(),
                'coll_count' => $o['coll_count'],
                'coll_total' => number_format($o['coll_total'], 2),
                'ret_count' => $o['ret_count'],
                'ret_total' => number_format($o['ret_total'], 2),
                'gift_qty' => $o['gift_qty'],
                'goods_count' => $o['goods_count'],
            ];
        }

        return view('ops.visits', [
            'visits' => $visits,
            'out' => $out,
            'kpi' => $kpi,
            'detail' => $detail,
            'from' => $from,
            'to' => $to,
            'reps' => User::fieldVisibleTo(
                Branch::scope(User::whereIn('role', User::FIELD_WORK_ROLES), $viewer), $viewer)
                ->where('active', true)->orderBy('name')->get(),
            'zones' => Branch::scope(Zone::query(), $viewer)->orderBy('code')->get(),
            'filters' => [
                'user' => $repId,
                'q' => $search,
                'zone' => $zoneId,
                'status' => $status,
                'has_photos' => $request->boolean('has_photos'),
                'has_invoice' => $request->boolean('has_invoice'),
                'has_collection' => $request->boolean('has_collection'),
                'has_return' => $request->boolean('has_return'),
            ],
        ]);
    }

    /** تاريخ من الريكوست — والافتراضي لو فاضي أو بايظ */
    private function day(string $raw, Carbon $fallback): Carbon
    {
        if (trim($raw) === '') {
            return $fallback->copy()->startOfDay();
        }

        return rescue(
            fn () => Carbon::parse($raw)->startOfDay(),
            fn () => $fallback->copy()->startOfDay(),
            report: false,
        );
    }
}
