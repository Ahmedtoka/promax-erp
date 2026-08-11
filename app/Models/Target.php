<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * عقدة تارجيت سنوي — شجرة: شركة ← مدير ← مندوب ← عميل (١١ أغسطس ٢٠٢٦).
 *
 * ⚠️ **غير `RepTarget`** (تارجت الحوافز الشهري الأربع قيم — فلوس/عملاء/
 * زيارات/قطع). ده تارجيت المبيعات السنوي الهرمي بتاع المالك، والاتنين
 * عايشين جنب بعض: الحوافز بتقيس أداء الشهر، وده بيوزّع خطة السنة.
 *
 * المحقق مابيتخزنش هنا — بيتحسب من `transactions` (مصدر الحقيقة)
 * عن طريق `App\Services\TargetProgress`، والاستثناء الوحيد
 * `target_months.manual_actual` للشهور التاريخية.
 */
class Target extends Model
{
    public const KIND_COMPANY = 'company';
    public const KIND_MANAGER = 'manager';
    public const KIND_REP = 'rep';
    public const KIND_CLIENT = 'client';

    protected $fillable = [
        'year', 'kind', 'user_id', 'client_id', 'parent_id', 'amount', 'created_by',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    // ---------- Relations ----------

    public function months(): HasMany
    {
        return $this->hasMany(TargetMonth::class)->orderBy('month');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Target::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Target::class, 'parent_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ---------- Helpers ----------

    /**
     * الشهر ده اتقفل؟ — اللي فات بيتقفل (تارجيته مايتعدلش)،
     * وسنة كاملة فاتت كلها مقفولة، وسنة جاية كلها مفتوحة.
     */
    public static function monthLocked(int $year, int $month): bool
    {
        $now = now();

        return $year < (int) $now->year
            || ($year === (int) $now->year && $month < (int) $now->month);
    }

    /**
     * القسمة الشهرية [1..12 => مبلغ] — الشهر الناقص صفر.
     * بتستخدم العلاقة المحمّلة لو موجودة (من غير كويري جديدة).
     *
     * @return array<int, float>
     */
    public function monthsArray(): array
    {
        $out = array_fill(1, 12, 0.0);
        $rows = $this->relationLoaded('months') ? $this->months : $this->months()->get();

        foreach ($rows as $r) {
            $m = (int) $r->month;
            if ($m >= 1 && $m <= 12) {
                $out[$m] = (float) $r->amount;
            }
        }

        return $out;
    }

    /**
     * المحقق اليدوي [1..12 => مبلغ أو null] — null يعني «احسب من القيود».
     *
     * @return array<int, ?float>
     */
    public function manualByMonth(): array
    {
        $out = array_fill(1, 12, null);
        $rows = $this->relationLoaded('months') ? $this->months : $this->months()->get();

        foreach ($rows as $r) {
            $m = (int) $r->month;
            if ($m >= 1 && $m <= 12) {
                $out[$m] = $r->manual_actual === null ? null : (float) $r->manual_actual;
            }
        }

        return $out;
    }

    /**
     * كتابة القسمة الشهرية كلها — من غير ما نلمس `manual_actual`
     * (updateOrCreate بتكتب `amount` بس، فاليدوي المتسجّل بيفضل).
     *
     * @param  array<int, float>  $byMonth
     */
    public function setMonthAmounts(array $byMonth): void
    {
        for ($m = 1; $m <= 12; $m++) {
            TargetMonth::updateOrCreate(
                ['target_id' => $this->id, 'month' => $m],
                ['amount' => round((float) ($byMonth[$m] ?? 0), 2)],
            );
        }

        // العلاقة المحمّلة بقت قديمة — أي قراية بعد الكتابة تجيب الجديد
        $this->unsetRelation('months');
    }

    /** قسمة متساوية على ١٢ شهر — فرق التقريب كله على ديسمبر */
    public function distributeEvenly(): void
    {
        $per = round(((float) $this->amount) / 12, 2);
        $byMonth = array_fill(1, 12, $per);
        $byMonth[12] = round((float) $this->amount - $per * 11, 2);

        $this->setMonthAmounts($byMonth);
    }

    /**
     * إعادة توزيع بعد تغيير الإجمالي السنوي — **بنفس منحنى الشهور
     * القديم** تناسبياً (اللي عدّل منحناه بإيده مايخسروش). لو مفيش
     * منحنى (كله أصفار) → قسمة متساوية. فرق التقريب على ديسمبر.
     */
    public function rescaleMonths(): void
    {
        $old = $this->monthsArray();
        $oldSum = round(array_sum($old), 2);

        if ($oldSum <= 0.004) {
            $this->distributeEvenly();

            return;
        }

        $annual = round((float) $this->amount, 2);
        $new = [];

        for ($m = 1; $m <= 12; $m++) {
            $new[$m] = round($old[$m] * $annual / $oldSum, 2);
        }

        $drift = round($annual - array_sum($new), 2);
        $new[12] = round($new[12] + $drift, 2);

        $this->setMonthAmounts($new);
    }

    /**
     * تعديل شهر مع الحفاظ على الإجمالي السنوي: الفرق بيتوزّع على
     * الشهور **اللي بعده بس** تناسبياً — الماضي مايتلمسش. لو اللي
     * بعده كله أصفار → بالتساوي. فرق التقريب كله على آخر شهر.
     *
     * ⚠️ ديسمبر مالوش «بعده» — تعديله بيكسر الإجمالي، والكنترولر
     * بيرفضه برسالة (`targets.last_month_fixed`) قبل ما يوصل هنا.
     */
    public function rebalance(int $month, float $newAmount): void
    {
        if ($month < 1 || $month >= 12) {
            throw new \InvalidArgumentException('rebalance: month must be between 1 and 11');
        }

        $months = $this->monthsArray();
        $delta = round($newAmount - $months[$month], 2);
        $months[$month] = round($newAmount, 2);

        if (abs($delta) >= 0.01) {
            $following = range($month + 1, 12);
            $sumF = 0.0;

            foreach ($following as $m) {
                $sumF += $months[$m];
            }

            if ($sumF > 0.004) {
                foreach ($following as $m) {
                    $months[$m] = round($months[$m] - $delta * ($months[$m] / $sumF), 2);
                }
            } else {
                $share = round($delta / count($following), 2);

                foreach ($following as $m) {
                    $months[$m] = round($months[$m] - $share, 2);
                }
            }
        }

        // فرق التقريب كله على ديسمبر — الإجمالي السنوي خط أحمر
        $drift = round((float) $this->amount - array_sum($months), 2);

        if (abs($drift) >= 0.01) {
            $months[12] = round($months[12] + $drift, 2);
        }

        $this->setMonthAmounts($months);
    }

    /**
     * المحقق شهر بشهر — محسوب من القيود مع غلبة اليدوي.
     *
     * @return array<int, float>
     */
    public function achievedByMonth(): array
    {
        return \App\Services\TargetProgress::achievedByMonth($this);
    }

    public function achievedTotal(): float
    {
        return round(array_sum($this->achievedByMonth()), 2);
    }
}
