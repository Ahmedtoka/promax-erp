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

    private static ?bool $employeeReady = null;

    public static function for(Model $source): ?int
    {
        // ⚠️ الكود ممكن يترفع على اللايف قبل المايجريشن — مفيش جدول = مفيش مركز، مش 500.
        // **الإيجابي بس بيتكاش**: worker قام قبل الـmigrate يشوف الجدول أول ما يتعمل.
        if (self::$ready !== true && ! (self::$ready = Schema::hasTable('cost_centers'))) {
            return null;
        }

        // الخطوة ٠: مستند عنده مركزه بنفسه (أمر/فاتورة/دفعة مورد) أو قيد دفتر مصدره كده
        if ($own = self::ownCenter($source)) {
            return $own;
        }

        $sales = self::isSales($source);

        // ⚠️ أي فشل في الاكتشاف (مصدر بكلاس اتشال، علاقة مكسورة) = مفيش مخزن،
        // مش exception — الريزولفر جوه `Rules::linesFor` ورميه كان هيضيّع القيد كله
        try {
            $center = CostCenter::forWarehouse(self::warehouseOf($source));
        } catch (\Throwable $e) {
            report($e);
            $center = null;
        }

        // ⚠️ البيع للمعادي دلوقتي (التصميم §٣.١.٢) — مخزن مركزه مصنع أو خط
        // مابياخدش مبيعات (المصنع بيبيع داخلي بس، Lane 9). مخزن فرع بياخدها.
        if ($center && (! $sales || $center->kind === 'branch')) {
            return $center->id;
        }

        if ($id = self::employeeCenter($source)) {
            return $id;
        }

        return $sales ? CostCenter::maadi()->id : null;
    }

    /**
     * مركز المستند نفسه: `cost_center_id` متملي على الصف، أو على مستنده
     * (morph `source_type`/`source_id`) لو الصف قيد دفتر. مابيرميش أبداً.
     */
    private static function ownCenter(Model $source): ?int
    {
        try {
            if ($id = $source->getAttribute('cost_center_id')) {
                return (int) $id;
            }

            if ($source->getAttribute('source_type') && $source->getAttribute('source_id')) {
                $doc = $source->source ?? null;

                if ($doc instanceof Model && ($id = $doc->getAttribute('cost_center_id'))) {
                    return (int) $id;
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return null;
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

        if (! $employeeId || ! class_exists($class)) {
            return null;
        }
        if (self::$employeeReady !== true
            && ! (self::$employeeReady = Schema::hasColumn((new $class)->getTable(), 'cost_center_id'))) {
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
