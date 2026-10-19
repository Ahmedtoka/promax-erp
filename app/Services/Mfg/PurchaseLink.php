<?php

namespace App\Services\Mfg;

use App\Exceptions\Rejected;
use App\Models\AppNotification;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * عجز الخامات ← طلب شراء (D8).
 *
 * ⚠️ **STUB لحد ما الخط ٥ يندمج.** لو `App\Models\PurchaseRequest::raiseFor(array $lines,
 * Model $for, User $by)` موجودة بتتنده ورقم الطلب بيتسجل على الأمر
 * (`purchase_request_id`)؛ غير كده بنسجّل `shortage_requested_at` ونبلّغ
 * الأدمن بس ونرجّع null. في الحالتين الأدمن بيتبلّغ.
 */
class PurchaseLink
{
    public static function raise(ProductionOrder $o, User $by): ?int
    {
        $o->loadMissing('product');

        $short = Requirement::forOrder($o)->filter(fn (array $r) => $r['shortage'] > 0.0005)->values();

        if (! $o->isOpen()) {
            throw new Rejected(__('mfg.order_bad_state'));
        }

        if ($short->isEmpty()) {
            throw new Rejected(__('mfg.no_shortage'));
        }

        $lines = $short->map(fn (array $r) => [
            'product_id' => $r['product']->id,
            'qty' => $r['shortage'],
        ])->all();

        $requestId = DB::transaction(function () use ($o, $by, $lines) {
            $row = ProductionOrder::query()->whereKey($o->id)->lockForUpdate()->firstOrFail();

            $id = null;
            $class = '\\App\\Models\\PurchaseRequest';
            if (class_exists($class) && method_exists($class, 'raiseFor')) {
                $pr = $class::raiseFor($lines, $row, $by);
                $id = is_object($pr) ? (int) $pr->getKey() : ($pr !== null ? (int) $pr : null);
            }

            $row->forceFill([
                'shortage_requested_at' => now(),
                'purchase_request_id' => $id ?? $row->purchase_request_id,
            ])->save();
            $o->setRawAttributes($row->getAttributes(), true);

            return $id;
        });

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
