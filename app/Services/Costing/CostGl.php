<?php

namespace App\Services\Costing;

use App\Models\CostCenter;
use App\Models\CostEntry;
use App\Models\Gl\GlPostingRule;
use App\Models\Transaction;
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
 * `oh_dr`/`oh_cr` محجوزة لتحميل التشغيل على الإنتاج (المهمة ٥).
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
        if (! in_array($e->kind, ['sale', 'return'], true)) {
            return $none;
        }

        $method = CostingSettings::method();
        $official = $e->amount($method);
        $markup = 0.0;
        foreach ($e->lines()->get() as $l) {
            if ($l->unit_transfer !== null) {
                $markup += (float) $l->amount_transfer - (float) ($method === 'wavg' ? $l->amount_wavg : $l->amount_batch);
            }
        }
        $markup = round($markup, 2);

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

        $pair('cost.sale', 'dr', 'cr', $official, $center, $center);
        $pair('cost.markup', 'mk_dr', 'mk_cr', $markup, $center, $company);

        if ($lines === []) {
            return $none;
        }

        $ref = $e->origin_type === (new Transaction)->getMorphClass()
            ? Transaction::whereKey($e->origin_id)->value('memo') : null;

        return [
            'rule' => $ruleKey,
            'memo' => $label.' — '.($ref ?: '#'.$e->origin_id),
            'needs_review' => $needsReview,
            'lines' => $lines,
        ];
    }

    /**
     * فحص «كل مصدر اتمسح قيده رجع» في إعادة البناء (`Ledger::rebuild`):
     * قيد تكلفة اتشال من الطبقة (أصله اختفى) أو بقى من غير سطور (مبلغه صفر
     * أو القواعد اتقفلت) غيابه مقصود — مش مصدر ضاع.
     *
     * @param  string  $key  `source_type|source_id`
     */
    public static function absentOk(string $key): bool
    {
        $prefix = (new CostEntry)->getMorphClass().'|';
        if (! str_starts_with($key, $prefix)) {
            return false;
        }
        $entry = CostEntry::find((int) substr($key, strlen($prefix)));

        return $entry === null || self::lines($entry)['lines'] === [];
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

        foreach (CostEntry::whereDate('date', '>=', $date)->orderBy('date')->orderBy('id')->cursor() as $entry) {
            yield $entry;
        }
    }
}
