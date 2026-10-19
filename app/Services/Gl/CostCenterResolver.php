<?php

namespace App\Services\Gl;

use App\Models\ClientReturn;
use App\Models\CostCenter;
use App\Models\Custody;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\RepSettlement;
use App\Models\Transaction;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * مركز التكلفة لمستند (خطة القفل ١٩/١٠) — `Rules` بتناديه وتحط الرقم
 * على سطور القيد.
 *
 * الترتيب (العقد في الخطة الرئيسية):
 *   ١. المخزن اللي البضاعة طلعت منه ← مركزه (`CostCenter::forWarehouse`)
 *   ٢. مركز الموظف (الموارد البشرية — لما `App\Models\Employee` يتعمل)
 *   ٣. البيع والتحصيل ← المعادي (التصميم §٣.١.٢: «أي بيعة بتتحسب للمعادي
 *      دلوقتي»، وأي فرع جديد بمخزنه هياخد مبيعاته من الخطوة ١)
 *   غير كده null — المورد والمصروف والنقدية لازم يجيبوا مركزهم بنفسهم.
 */
class CostCenterResolver
{
    /** أنواع حركات العملاء اللي بتقع على المعادي لو مالهاش مخزن */
    public const SALES_KINDS = ['sale', 'collection', 'return', 'refund', 'rebate', 'settlement', 'taxded'];

    /** مستندات البيع اللي بتقع على المعادي لو مالهاش مخزن */
    private const SALES_SOURCES = [Invoice::class, PurchaseOrder::class, ClientReturn::class, RepSettlement::class];

    private static ?bool $ready = null;

    public static function for(Model $source): ?int
    {
        // ⚠️ الكود ممكن يترفع على اللايف قبل المايجريشن — مفيش جدول = مفيش مركز، مش 500
        if (! (self::$ready ??= Schema::hasTable('cost_centers'))) {
            return null;
        }

        if ($center = CostCenter::forWarehouse(self::warehouseOf($source))) {
            return $center->id;
        }

        if ($id = self::employeeCenter($source)) {
            return $id;
        }

        return self::isSales($source) ? CostCenter::maadi()->id : null;
    }

    /** المخزن اللي المستند خرج منه — أو null */
    public static function warehouseOf(Model $source, int $depth = 0): ?Warehouse
    {
        if ($source->getAttribute('warehouse_id')) {
            return Warehouse::find($source->getAttribute('warehouse_id'));
        }

        if ($source->getAttribute('custody_id')
            && ($wid = Custody::whereKey($source->getAttribute('custody_id'))->value('warehouse_id'))) {
            return Warehouse::find($wid);
        }

        // الفاتورة: باتش أول بند بيقول البضاعة طلعت من أنهي مخزن
        if ($source instanceof Invoice) {
            $wid = $source->items()->join('batches', 'batches.id', '=', 'invoice_items.batch_id')
                ->orderBy('invoice_items.id')->value('batches.warehouse_id');

            return $wid ? Warehouse::find($wid) : null;
        }

        // القيد ← مستنده (خطوة واحدة)
        if ($source instanceof Transaction && $depth === 0 && $source->source_type && $source->source_id) {
            $doc = $source->source;

            return $doc instanceof Model ? self::warehouseOf($doc, 1) : null;
        }

        return null;
    }

    /** مركز الموظف — جاهز للموارد البشرية (Lane 7)، ومن غيرها null */
    private static function employeeCenter(Model $source): ?int
    {
        $employeeId = $source->getAttribute('employee_id');
        $class = 'App\\Models\\Employee';

        if (! $employeeId || ! class_exists($class)
            || ! Schema::hasColumn((new $class)->getTable(), 'cost_center_id')) {
            return null;
        }

        $id = $class::whereKey($employeeId)->value('cost_center_id');

        return $id ? (int) $id : null;
    }

    private static function isSales(Model $source): bool
    {
        if ($source instanceof Transaction) {
            return in_array($source->kind, self::SALES_KINDS, true);
        }

        foreach (self::SALES_SOURCES as $class) {
            if ($source instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
