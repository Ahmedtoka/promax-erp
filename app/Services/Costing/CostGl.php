<?php

namespace App\Services\Costing;

use App\Models\CostCenter;
use App\Models\CostEntry;
use App\Models\CostMonthLine;
use App\Models\Gl\GlPostingRule;
use App\Models\ProductionOrder;
use App\Models\StockTransfer;
use App\Models\Transaction;
use App\Models\Warehouse;
use App\Services\Gl\Rules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * قيود التكلفة في الدفتر العام (الخط ٩ — D3/D4). مفيش كتابة هنا —
 * `lines()` بترجّع نفس شكل `Rules::linesFor` و`App\Services\Gl\Ledger`
 * هو اللي بيكتب. كل سطر عليه مركزه صريح (`Rules::withCostCenter` بيسيبه).
 *
 * البيع (المبلغ الرسمي = `$e->official()` بطريقة الإعدادات):
 *   dr/cr       مدين تكلفة المبيعات [مركز البيع] / دائن المخزون [نفس المركز]   — `cost.sale`
 *   mk_dr/mk_cr مدين تكلفة المبيعات [مركز البيع] / دائن استبعاد الهامش [الشركة] — `cost.markup`
 *               المبلغ = Σ على السطور اللي ليها سعر تحويل (مبلغ التحويل − الرسمي)؛
 *               سالب = الزوج بيتقلب. السطر من غير سعر تحويل مالوش دعوة.
 * المرتجع = نفس الأزواج مقلوبة (المدين دائن والعكس) — الخانات بأساميها.
 * الإنتاج (المهمة ٥): dr/cr = `cost.production` بالمواد (مدين المخزون [مركز
 * التام = other_center] / دائن المخزون [مركز مخزن الخامات، وإلا الخط])، و
 * oh_dr/oh_cr = `cost.overhead` بالتحميل (مدين المخزون [التام] / دائن المحمّل [الخط]).
 * الانحراف: dr/cr = `cost.variance` على الخط (أو المصنع للغير موزّع)، السالب بيتقلب.
 * مبلغ صفر = مفيش سطور (و`absentOk` بيقبل الغياب).
 *
 * قاعدة ناقصة أو موقوفة = الزوج بتاعها بيتشال (من غير الـseed مفيش قيود).
 * حساب ناقص/موقوف = «المعلّق» + مراجعة (نفس `Rules::slotAccount`).
 */
final class CostGl
{
    public static function handles(Model $m): bool
    {
        return $m instanceof CostEntry;
    }

    /** @return array{rule:string, memo:string, needs_review:bool, lines:list<array<string, mixed>>} */
    public static function lines(CostEntry $e): array
    {
        $none = ['rule' => '', 'memo' => '', 'needs_review' => false, 'lines' => []];
        if (! in_array($e->kind, ['sale', 'return', 'transfer', 'production', 'variance'], true)) {
            return $none;
        }

        [$official, $markup] = self::amounts($e);

        $center = $e->cost_center_id ?? CostCenter::maadi()->id;
        $company = CostCenter::company()->id;
        $flip = $e->kind === 'return';
        $rules = app(Rules::class);
        $needsReview = false;
        $lines = [];
        $ruleKey = '';
        $label = '';

        $pair = function (string $key, string $drSlot, string $crSlot, float $amount, int $drCenter, int $crCenter)
            use ($rules, $flip, &$needsReview, &$lines, &$ruleKey, &$label): void {
            $rule = GlPostingRule::forKey($key);
            if ($amount == 0.0 || $rule === null || ! $rule->active) {
                return;
            }
            $swap = $flip !== ($amount < 0);
            $amt = abs($amount);
            $drId = $rules->slotAccount($rule, 'debit', null, $needsReview);
            $crId = $rules->slotAccount($rule, 'credit', null, $needsReview);
            $lines[] = ['slot' => $drSlot, 'account_id' => $drId, 'cost_center_id' => $drCenter,
                'debit' => $swap ? 0.0 : $amt, 'credit' => $swap ? $amt : 0.0];
            $lines[] = ['slot' => $crSlot, 'account_id' => $crId, 'cost_center_id' => $crCenter,
                'debit' => $swap ? $amt : 0.0, 'credit' => $swap ? 0.0 : $amt];
            if ($ruleKey === '') {
                $ruleKey = $key;
                $label = (string) $rule->label;
            }
        };

        if ($e->kind === 'production') {
            // مواد: مدين المخزون [مركز التام] / دائن المخزون [مركز الخامات، وإلا الخط]
            // تحميل: مدين المخزون [مركز التام] / دائن المحمّل [الخط]
            $order = ProductionOrder::find($e->origin_id);
            $fg = $e->other_center_id ?? $center;
            $raw = $order?->raw_warehouse_id ? CostCenter::forWarehouse(Warehouse::find($order->raw_warehouse_id))?->id : null;
            $pair('cost.production', 'dr', 'cr', round((float) $e->materials, 2), $fg, $raw ?? $center);
            $pair('cost.overhead', 'oh_dr', 'oh_cr', round((float) $e->overhead, 2), $fg, $center);
        } elseif ($e->kind === 'variance') {
            // الحوض − المحمّل: مدين الانحرافات / دائن المحمّل على الخط (أو المصنع) — السالب بيتقلب
            $pair('cost.variance', 'dr', 'cr', $official, $center, $center);
        } elseif ($e->kind === 'transfer') {
            // البيع الداخلي: R الرسمي على المصنع، والهامش مدين الاستبعاد [الشركة] / دائن المبيعات الداخلية [المصنع]
            $pair('cost.transfer', 'dr', 'cr', $official, $center, $center);
            $pair('cost.transfer_markup', 'mk_dr', 'mk_cr', $markup, $company, $center);
        } else {
            $pair('cost.sale', 'dr', 'cr', $official, $center, $center);
            $pair('cost.markup', 'mk_dr', 'mk_cr', $markup, $center, $company);
        }

        if ($lines === []) {
            return $none;
        }

        $isTx = $e->origin_type === (new Transaction)->getMorphClass();
        $ref = match (true) {
            $isTx => $e->relationLoaded('origin') ? $e->origin?->memo : Transaction::whereKey($e->origin_id)->value('memo'),
            $e->origin_type === (new StockTransfer)->getMorphClass() => StockTransfer::whereKey($e->origin_id)->value('number'),
            $e->origin_type === (new ProductionOrder)->getMorphClass() => ProductionOrder::whereKey($e->origin_id)->value('number'),
            $e->origin_type === (new CostMonthLine)->getMorphClass() => self::varianceRef($e),
            default => null,
        };

        return [
            'rule' => $ruleKey,
            'memo' => $label.' — '.($ref ?: '#'.$e->origin_id),
            'needs_review' => $needsReview,
            'lines' => $lines,
        ];
    }

    /**
     * المبلغ الرسمي وهامش التحويل (Σ على سطور سعر التحويل: مبلغ التحويل −
     * الرسمي للسطر) بطريقة الإعدادات.
     *
     * @return array{0:float, 1:float}
     */
    public static function amounts(CostEntry $e): array
    {
        $method = CostingSettings::method();
        $markup = 0.0;
        foreach ($e->relationLoaded('lines') ? $e->lines : $e->lines()->get() as $l) {
            if ($l->unit_transfer !== null) {
                $markup += (float) $l->amount_transfer - (float) ($method === 'wavg' ? $l->amount_wavg : $l->amount_batch);
            }
        }

        return [$e->amount($method), round($markup, 2)];
    }

    /**
     * فحص «كل مصدر اتمسح قيده رجع» في إعادة البناء (`Ledger::rebuild`).
     * غياب قيد تكلفة **مقصود** بس لو: القيد اتشال في التشغيلة دي لأن أصله
     * اختفى أو بقى مش قابل للتكلفة، أو أصله (لسه موجود) مش قابل للتكلفة،
     * أو مالوش قيمة (المبلغ الرسمي والهامش الاتنين صفر — مرتجع كله تالف مثلاً).
     * قاعدة `cost.*` موقوفة/ناقصة أو قيد مختفي وأصله قابل للتكلفة = ضياع حقيقي.
     *
     * @param  string  $key  `source_type|source_id`
     */
    public static function absentOk(string $key): bool
    {
        $prefix = (new CostEntry)->getMorphClass().'|';
        if (! str_starts_with($key, $prefix)) {
            return false;
        }
        $id = (int) substr($key, strlen($prefix));
        $entry = CostEntry::find($id);
        if ($entry === null) {
            return CostSync::droppedLegitimately($id);
        }
        if ($entry->origin_type === (new Transaction)->getMorphClass()) {
            $tx = Transaction::find($entry->origin_id);
            if ($tx === null || ! CostSync::costable($tx)) {
                return true;
            }
        } elseif ($entry->origin_type === (new StockTransfer)->getMorphClass()) {
            $t = StockTransfer::find($entry->origin_id);
            if ($t === null || ! CostSync::internalTransfer($t)) {
                return true;
            }
        } elseif ($entry->kind === 'production') {
            // أمر اتشال/مابقاش مقفول، أو مواد وتحميل الاتنين صفر
            $o = ProductionOrder::find($entry->origin_id);

            return $o === null || ! CostSync::producible($o)
                || (round((float) $entry->materials, 2) == 0.0 && round((float) $entry->overhead, 2) == 0.0);
        } elseif ($entry->kind === 'variance') {
            // انحراف صفر = مفيش سطور (مقصود)
            return CostMonthLine::find($entry->origin_id) === null || $entry->official() == 0.0;
        }
        [$official, $markup] = self::amounts($entry);

        return $official == 0.0 && $markup == 0.0;
    }

    /** مرجع قيد الانحراف: الشهر + كود الخط/المصنع */
    private static function varianceRef(CostEntry $e): ?string
    {
        $ml = CostMonthLine::with(['month', 'line'])->find($e->origin_id);

        return $ml === null ? null : trim(($ml->month?->period ?? '').' '.($ml->line?->code ?? ''));
    }

    /**
     * مصادر إعادة البناء: بيزامن الطبقة من التاريخ **الأول** (جوه ترانزاكشن
     * إعادة البناء، من غير ترحيل) وبعدين بيمشي على القيود بالتاريخ والـid.
     * ⚠️ فشل المزامنة بيتسجّل وبيطلع — إعادة البناء لازم تفشل بصوت عالي.
     */
    public static function sources(string $date): \Generator
    {
        if (! CostSync::ready()) {
            return;
        }
        try {
            CostSync::run(Carbon::parse($date), false);
        } catch (\Throwable $e) {
            Log::error('costing.sync failed inside rebuild', ['from' => $date, 'error' => $e->getMessage()]);
            throw $e;
        }

        foreach (CostEntry::with(['lines', 'origin'])->whereDate('date', '>=', $date)->orderBy('date')->orderBy('id')->lazy(500) as $entry) {
            yield $entry;
        }
    }
}
