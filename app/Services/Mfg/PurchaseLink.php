<?php

namespace App\Services\Mfg;

use App\Exceptions\Rejected;
use App\Models\AppNotification;
use App\Models\ProductionOrder;
use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * عجز الخامات ← طلب شراء (D8 + لين ٩ D14): طلب لكل خامة ناقصة عن طريق
 * `PurchaseRequest::raiseFor`، وأول رقم طلب بيتسجل على الأمر
 * (`purchase_request_id`). الأدمن بيتبلّغ في كل مرة.
 */
class PurchaseLink
{
    /** الحالات اللي ينفع فيها طلب شراء للعجز — نفس شرط الزرار في صفحة الأمر */
    public const STATUSES = ['planned', 'in_progress'];

    public static function raise(ProductionOrder $o, User $by): ?int
    {
        // ⚠️ كل الفحوص على الصف المقفول — ضغطتين في نفس اللحظة = طلب واحد
        [$requestId, $short] = DB::transaction(function () use ($o, $by) {
            $row = ProductionOrder::query()->whereKey($o->id)->lockForUpdate()->firstOrFail();

            // مخطط أو جاري بس — المنتهي خلاص صرف اللي محتاجه ومستني القفل
            if (! in_array($row->status, self::STATUSES, true)) {
                throw new Rejected(__('mfg.order_bad_state'));
            }

            $short = Requirement::forOrder($row)->filter(fn (array $r) => $r['shortage'] > 0.0005)->values();

            if ($short->isEmpty()) {
                throw new Rejected(__('mfg.no_shortage'));
            }

            // مرفوض بس لو كل خامة ناقصة ليها طلب شغّال لنفس الأمر — غير كده بنطلب الناقص
            $active = PurchaseRequest::activeProductIds($row, $short->map(fn (array $r) => $r['product']->id)->all());
            if (count($active) >= $short->count()) {
                throw new Rejected(__('mfg.shortage_already_requested', [
                    'at' => ($row->shortage_requested_at ?? now())->format('Y-m-d H:i'),
                ]));
            }

            $lines = $short->map(fn (array $r) => [
                'product_id' => $r['product']->id,
                'qty' => $r['shortage'],
            ])->all();

            $id = PurchaseRequest::raiseFor($lines, $row, $by)->first()?->getKey();
            $row->forceFill([
                'shortage_requested_at' => now(),
                'purchase_request_id' => $id ?? $row->purchase_request_id,
            ])->save();
            $o->setRawAttributes($row->getAttributes(), true);

            return [$id === null ? null : (int) $id, $short];
        });

        $o->loadMissing('product');
        $number = $o->number;
        $product = $o->product?->displayName() ?? '';
        $link = route('mfg.orders.show', $o->id, false);
        $list = $short->map(fn (array $r) => [$r['product'], $r['shortage']]);

        User::query()->where('role', 'admin')->where('active', true)->get()
            ->each(fn (User $admin) => AppNotification::send(
                $admin,
                fn () => __('mfg.shortage_notice_title', ['number' => $number, 'product' => $product]),
                fn () => $list->map(fn ($x) => __('mfg.shortage_notice_line', [
                    'material' => $x[0]->displayName(),
                    'qty' => rtrim(rtrim(number_format($x[1], 3, '.', ''), '0'), '.'),
                    'unit' => $x[0]->unitLabel(),
                ]))->implode("\n"),
                good: false,
                link: $link,
            ));

        return $requestId;
    }
}
