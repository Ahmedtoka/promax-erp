<?php

namespace App\Models;

use App\Models\Concerns\HasBilingualName;
use App\Models\Gl\GlAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * أصل ثابت — قسط ثابت شهري (L4-D11)، ورصيد افتتاحي للإهلاك (L4-D12).
 *
 * الحسابات هنا **قراءة بس** (مفيش كتابة في الدفتر): القسط الشهري
 * بيتكتب صف في `fixed_asset_depreciations` من `Depreciation::run()`،
 * والأوبزرفر بتاعه هو اللي بيرحّل. الأصل نفسه مابيترحّلش — تكلفته
 * دخلت الدفتر من سند المصروف (L4-D10) أو من القيد الافتتاحي (Lane 3).
 */
class FixedAsset extends Model
{
    use HasBilingualName;

    public const STATUSES = ['active', 'disposed'];

    protected $fillable = [
        'code', 'name', 'name_en', 'category_id', 'expense_id', 'cost', 'salvage', 'life_months',
        'start_date', 'cost_center_id', 'asset_account_id', 'opening_accum', 'opening_as_of',
        'status', 'disposed_at', 'created_by',
    ];

    protected $attributes = ['status' => 'active', 'salvage' => 0, 'opening_accum' => 0];

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:2', 'salvage' => 'decimal:2', 'opening_accum' => 'decimal:2',
            'life_months' => 'integer', 'start_date' => 'date', 'opening_as_of' => 'date',
            'disposed_at' => 'datetime',
        ];
    }

    /**
     * الكود التالي FA-#### — نفس منطق HasDocumentNumber (أكبر رقم + ١) بس
     * العمود هنا اسمه `code` مش `number`.
     */
    public static function nextNumber(): string
    {
        $max = (int) static::query()
            ->where('code', 'like', 'FA-%')
            ->selectRaw("MAX(CAST(SUBSTRING_INDEX(code, '-', -1) AS UNSIGNED)) as n")
            ->value('n');

        return 'FA-'.($max > 0 ? $max + 1 : 1001);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function assetAccount(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'asset_account_id');
    }

    public function depreciations(): HasMany
    {
        return $this->hasMany(FixedAssetDepreciation::class)->orderBy('period_key');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** بدون ترتيب — لـ`withSum()` في السجل (الترتيب جوه subquery مالوش لازمة) */
    public function depreciationRows(): HasMany
    {
        return $this->hasMany(FixedAssetDepreciation::class);
    }

    // ═══════════ الإهلاك بالقسط الثابت (L4-D11) ═══════════

    /** القيمة القابلة للإهلاك = التكلفة − الخردة */
    public function depreciable(): float
    {
        return round(max(0.0, (float) $this->cost - (float) $this->salvage), 2);
    }

    public function monthlyAmount(): float
    {
        return $this->life_months > 0 ? round($this->depreciable() / $this->life_months, 2) : 0.0;
    }

    /** رقم الشهر في عمر الأصل — شهر `start_date` = ١، وقبله صفر أو سالب */
    public function periodIndex(string $periodKey): int
    {
        $p = self::periodStart($periodKey);
        $s = $this->start_date->copy()->startOfMonth();

        return ($p->year - $s->year) * 12 + ($p->month - $s->month) + 1;
    }

    /**
     * قسط الشهر ده: صفر بره عمر الأصل، أو قبل/في شهر `opening_as_of`
     * (L4-D12)، أو بعد شهر الاستبعاد، أو لو اتهلك بالكامل. آخر شهر في
     * العمر بياخد الباقي كله، وعمر القسط مايعدّيش الباقي.
     */
    public function amountFor(string $periodKey): float
    {
        self::periodStart($periodKey); // validation
        if ($this->life_months <= 0 || ! $this->start_date) {
            return 0.0;
        }
        if ($this->status !== 'active') {
            if ($this->status !== 'disposed' || ! $this->disposed_at || $periodKey > $this->disposed_at->format('Y-m')) {
                return 0.0;
            }
        }
        $idx = $this->periodIndex($periodKey);
        if ($idx < 1 || $idx > $this->life_months) {
            return 0.0;
        }
        if ($this->opening_as_of && $periodKey <= $this->opening_as_of->format('Y-m')) {
            return 0.0;
        }

        $others = $this->exists
            ? (float) $this->depreciationRows()->where('period_key', '!=', $periodKey)->sum('amount')
            : 0.0;
        $remaining = round($this->depreciable() - (float) $this->opening_accum - $others, 2);
        if ($remaining <= 0) {
            return 0.0;
        }

        return round($idx === $this->life_months ? $remaining : min($this->monthlyAmount(), $remaining), 2);
    }

    /** مجمع الإهلاك = الافتتاحي + الأقساط المرحّلة لحد الشهر ده (شامل) */
    public function accumulated(?string $uptoPeriod = null): float
    {
        if ($uptoPeriod === null && array_key_exists('depreciation_rows_sum_amount', $this->attributes)) {
            $sum = (float) $this->attributes['depreciation_rows_sum_amount'];
        } else {
            $sum = ! $this->exists ? 0.0 : (float) $this->depreciationRows()
                ->when($uptoPeriod !== null, fn ($q) => $q->where('period_key', '<=', $uptoPeriod))
                ->sum('amount');
        }

        return round((float) $this->opening_accum + $sum, 2);
    }

    public function bookValue(): float
    {
        return round((float) $this->cost - $this->accumulated(), 2);
    }

    /**
     * القيد الافتتاحي لأصل موجود قبل النظام (L4-D12) — **سطور بس**،
     * Lane 3 بيرحّلها بـ`Ledger::manual(..., 'opening')`:
     * مدين حساب الأصل بالتكلفة / دائن مجمع الإهلاك بالافتتاحي /
     * دائن الأرصدة الافتتاحية بالصافي. كل السطور على مركز الأصل.
     *
     * @return array{memo:string, date:string, lines:list<array{account_id:int,cost_center_id:?int,debit:float,credit:float}>}
     */
    public function openingEntry(): array
    {
        $cost = round((float) $this->cost, 2);
        if ($cost <= 0) {
            throw new \DomainException("Fixed asset {$this->code} has no cost");
        }
        $accum = round((float) $this->opening_accum, 2);
        $center = $this->cost_center_id;
        $assetId = $this->asset_account_id ?? GlAccount::findKey('fixed_assets')->id;

        $lines = [['account_id' => (int) $assetId, 'cost_center_id' => $center, 'debit' => $cost, 'credit' => 0.0]];
        if ($accum > 0) {
            $lines[] = ['account_id' => GlAccount::findKey('accum_depreciation')->id, 'cost_center_id' => $center, 'debit' => 0.0, 'credit' => $accum];
        }
        $lines[] = ['account_id' => GlAccount::findKey('opening_equity')->id, 'cost_center_id' => $center, 'debit' => 0.0, 'credit' => round($cost - $accum, 2)];

        return [
            'memo' => __('cash.asset_opening_memo', ['code' => $this->code, 'name' => $this->name]),
            'date' => ($this->opening_as_of ?? $this->start_date)->toDateString(),
            'lines' => $lines,
        ];
    }

    /** YYYY-MM → أول يوم في الشهر، وأي شكل تاني = InvalidArgumentException */
    public static function periodStart(string $periodKey): Carbon
    {
        if (! preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $periodKey, $m)) {
            throw new \InvalidArgumentException("Invalid period key [{$periodKey}] — expected YYYY-MM");
        }

        return Carbon::create((int) $m[1], (int) $m[2], 1)->startOfDay();
    }
}
