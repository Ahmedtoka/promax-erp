<?php

namespace App\Support;

use App\Models\ClientReturn;
use App\Models\GiftHandout;
use App\Models\Invoice;
use App\Models\ReplenishmentRequest;
use App\Models\Transaction;
use App\Models\Visit;
use App\Models\VisitPhoto;
use Illuminate\Support\Collection;

/**
 * ═══════════════════════════════════════════════════════════════
 * ناتج الزيارة — كل اللي حصل جوه زيارة، بكويري واحدة لكل نوع
 * (١٥ أغسطس ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **الشاشة بتعرض صفحة زيارات، مش زيارة.** أي كود بيسأل عن ناتج
 * كل صف لوحده (`$visit->invoices`, `$visit->photos`) بيعمل N كويري
 * لكل صفحة — والشاشة دي مفروض تعيش مع آلاف الزيارات. فالمجمّع ده
 * بياخد **مصفوفة id** ويرجّع خريطة جاهزة: **٦ كويريز ثابتة** أياً
 * كان عدد الصفوف.
 *
 * ⚠️ **مرساة التحصيل مش عمود** — التحصيل الميداني قيد `collection`
 * بـ`source_type = Visit::class` (عقيدة ٩/٨)، مش عمود `visit_id`
 * على الترانزاكشن. أي كود بيدوّر على `transactions.visit_id`
 * هيرجع فاضي وهو مقتنع إن مفيش تحصيل.
 *
 * ⚠️ **الفلوس بالـ`grand_total`** — اللي العميل بيدفعه فعلاً
 * (عقيدة الأرقام التلاتة). الشاشة دي بتعرض للمالك «الزيارة دي طلع
 * منها كام»، وده رقم الجيب مش رقم التقرير.
 */
class VisitOutcomes
{
    /**
     * خريطة ناتج الزيارات — `visit_id => array`.
     *
     * @param  list<int>  $visitIds
     * @return array<int, array<string, mixed>>
     */
    public static function map(array $visitIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $visitIds)));

        if ($ids === []) {
            return [];
        }

        // ═══ ١. الفواتير — الرقم والمبلغ عشان الشارة تقول «بكام» ═══
        $invoices = Invoice::whereIn('visit_id', $ids)
            ->orderBy('id')
            ->get(['id', 'visit_id', 'number', 'grand_total', 'payment'])
            ->groupBy('visit_id');

        // ═══ ٢. التحصيلات — قيود `collection` بمرساة الزيارة ═══
        $collections = Transaction::where('kind', 'collection')
            ->where('source_type', Visit::class)
            ->whereIn('source_id', $ids)
            ->selectRaw('source_id, COUNT(*) AS c, COALESCE(SUM(credit), 0) AS s')
            ->groupBy('source_id')
            ->get()
            ->keyBy('source_id');

        // ═══ ٣. المرتجعات ═══
        $returns = ClientReturn::whereIn('visit_id', $ids)
            ->selectRaw('visit_id, COUNT(*) AS c, COALESCE(SUM(grand_total), 0) AS s')
            ->groupBy('visit_id')
            ->get()
            ->keyBy('visit_id');

        // ═══ ٤. صور الرف — الصفوف نفسها مش عدّ ═══
        // المودال محتاج الروابط، والعدّ بيتحسب من نفس الكولكشن —
        // كويري عدّ منفصلة كانت هتبقى كويري زيادة على الفاضي.
        $photos = VisitPhoto::whereIn('visit_id', $ids)
            ->orderBy('id')
            ->get()
            ->groupBy('visit_id');

        // ═══ ٥. الهدايا ═══
        $gifts = GiftHandout::whereIn('visit_id', $ids)
            ->selectRaw('visit_id, COUNT(*) AS c, COALESCE(SUM(qty), 0) AS q')
            ->groupBy('visit_id')
            ->get()
            ->keyBy('visit_id');

        // ═══ ٦. طلبات البضاعة — نفس كيان الريفيل بمرساة الزيارة ═══
        $goods = ReplenishmentRequest::whereIn('visit_id', $ids)
            ->selectRaw('visit_id, COUNT(*) AS c')
            ->groupBy('visit_id')
            ->get()
            ->keyBy('visit_id');

        $out = [];

        foreach ($ids as $id) {
            $inv = $invoices->get($id) ?? collect();
            $ph = $photos->get($id) ?? collect();

            $before = $ph->where('stage', VisitPhoto::STAGE_BEFORE)->values();
            $after = $ph->where('stage', VisitPhoto::STAGE_AFTER)->values();

            $row = [
                'invoices' => $inv,
                'inv_count' => $inv->count(),
                'inv_total' => round((float) $inv->sum('grand_total'), 2),
                'coll_count' => (int) ($collections->get($id)?->c ?? 0),
                'coll_total' => round((float) ($collections->get($id)?->s ?? 0), 2),
                'ret_count' => (int) ($returns->get($id)?->c ?? 0),
                'ret_total' => round((float) ($returns->get($id)?->s ?? 0), 2),
                'photos' => $ph,
                'before' => $before,
                'after' => $after,
                'photo_count' => $ph->count(),
                'gift_count' => (int) ($gifts->get($id)?->c ?? 0),
                'gift_qty' => (int) ($gifts->get($id)?->q ?? 0),
                'goods_count' => (int) ($goods->get($id)?->c ?? 0),
            ];

            // ⚠️ **«زيارة بلا نتيجة» = الرقم اللي المالك بيسأل عنه.**
            // الهدية وطلب البضاعة **مش** ناتج بيع، بس هما شغل حصل —
            // فبيتحسبوا نتيجة. اللي بلا نتيجة خالص هو اللي دخل وخرج.
            $row['any'] = $row['inv_count'] > 0
                || $row['coll_count'] > 0
                || $row['ret_count'] > 0
                || $row['photo_count'] > 0
                || $row['gift_count'] > 0
                || $row['goods_count'] > 0;

            $out[$id] = $row;
        }

        return $out;
    }

    /** صف فاضي — عشان الفيو مايتكسرش على زيارة مش في الخريطة */
    public static function blank(): array
    {
        return [
            'invoices' => collect(),
            'inv_count' => 0,
            'inv_total' => 0.0,
            'coll_count' => 0,
            'coll_total' => 0.0,
            'ret_count' => 0,
            'ret_total' => 0.0,
            'photos' => collect(),
            'before' => collect(),
            'after' => collect(),
            'photo_count' => 0,
            'gift_count' => 0,
            'gift_qty' => 0,
            'goods_count' => 0,
            'any' => false,
        ];
    }

    /**
     * سب-كويريز «الزيارات اللي فيها كذا» — كل واحدة بتختار عمود
     * `visit_id` من جدول الناتج، فتنفع `whereIn` (فلتر «فيها») و
     * `whereNotIn` (حساب «الزيارة الضايعة») بنفس التعريف بالحرف.
     *
     * ⚠️ **`whereNotNull` على كل مصدر مش زيادة.** `NOT IN` بترجّع
     * صفر صفوف لو السب-كويري رجّعت `NULL` واحدة — والأعمدة دي كلها
     * nullable (الفاتورة ممكن تتعمل من الويب بلا زيارة). من غير
     * الحارس ده رقم «زيارات بلا نتيجة» كان هيبقى صفر دايماً.
     *
     * ⚠️ **مثيل جديد كل نداء** — الـbuilder حالته بتتغير لما
     * يتحقن في كويري، فمشاركة نفس المثيل بين فلترين بتلوّث الاتنين.
     *
     * @return array<string, \Illuminate\Database\Eloquent\Builder>
     */
    public static function idSources(): array
    {
        return [
            'photos' => VisitPhoto::whereNotNull('visit_id')->select('visit_id'),
            'invoice' => Invoice::whereNotNull('visit_id')->select('visit_id'),
            'collection' => Transaction::where('kind', 'collection')
                ->where('source_type', Visit::class)
                ->whereNotNull('source_id')
                ->select('source_id'),
            'return' => ClientReturn::whereNotNull('visit_id')->select('visit_id'),
            'gift' => GiftHandout::whereNotNull('visit_id')->select('visit_id'),
            'goods' => ReplenishmentRequest::whereNotNull('visit_id')->select('visit_id'),
        ];
    }

    /**
     * صور الرف بس — للشاشات اللي عايزة الصور من غير باقي الناتج
     * (كارت العميل ويوم المندوب وشاشة الرفوف الموحّدة).
     *
     * @param  list<int>  $visitIds
     * @return Collection<int, Collection<int, VisitPhoto>>
     */
    public static function photos(array $visitIds): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $visitIds)));

        if ($ids === []) {
            return collect();
        }

        return VisitPhoto::whereIn('visit_id', $ids)
            ->orderBy('id')
            ->get()
            ->groupBy('visit_id');
    }
}
