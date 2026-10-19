<?php

namespace App\Models\Gl;

use App\Models\Concerns\HasBilingualName;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * حساب في الشجرة. الجذور الخمسة وحسابات النظام (`system_key`) بتتولد من
 * `GlSeeder` وقواعد الترحيل بتشاور عليها بالمفتاح — الكود ممكن يتغيّر، المفتاح لا.
 */
class GlAccount extends Model
{
    use HasBilingualName;

    public const TYPES = ['asset', 'liability', 'equity', 'revenue', 'expense'];

    /** نوع الجذر حسب أول رقم في الكود */
    public const ROOT_TYPES = ['1' => 'asset', '2' => 'liability', '3' => 'equity', '4' => 'revenue', '5' => 'expense'];

    public const SYSTEM_KEYS = [
        'cash_main', 'bank', 'rep_cash', 'receivables', 'withheld_tax', 'suspense',
        'payables', 'vat_output', 'opening_equity', 'inventory', 'opening_difference',
        'sales', 'sales_returns', 'discounts_allowed',
        'expense_purchases', 'expense_fuel', 'expense_maintenance', 'expense_salaries',
        'expense_commissions', 'expense_rent', 'expense_gifts', 'expense_other',
        // Lane 4 — بتتزرع من CashGlSeed بالمفتاح مش بالكود (L4-D13)
        'fixed_assets', 'accum_depreciation', 'expense_depreciation', 'cash_over_short', 'expense_factory',
    ];

    protected $table = 'gl_accounts';

    protected $fillable = [
        'code', 'name', 'name_en', 'parent_id', 'type', 'normal_side',
        'is_system', 'system_key', 'user_id', 'is_postable', 'active', 'coa_draft_id',
    ];

    protected function casts(): array
    {
        return ['is_system' => 'bool', 'is_postable' => 'bool', 'active' => 'bool'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GlLine::class, 'account_id');
    }

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    public static function normalSideFor(string $type): string
    {
        return in_array($type, ['asset', 'expense'], true) ? 'debit' : 'credit';
    }

    /**
     * حساب النظام بمفتاحه — بيرمي لو الشجرة مش متولدة (شغّل GlSeeder).
     * `opening_difference` بيتعمل عند الطلب (مش من السيدر).
     */
    public static function findKey(string $systemKey): self
    {
        $acc = static::where('system_key', $systemKey)->first();
        if ($acc === null && $systemKey === 'opening_difference') {
            return static::openingDifference();
        }
        if ($acc === null) {
            throw new \RuntimeException("GL system account [{$systemKey}] missing — run GlSeeder");
        }

        return $acc;
    }

    /**
     * «فرق الأرصدة الافتتاحية» — أي عدم توازن في قيد الافتتاح بيروحله.
     * بيتعمل مرة واحدة تحت جذر حقوق الملكية المتولد (`3`، أو `S-3` بعد الاعتماد) بالكود 3199 (أو 3199.1 لو
     * الكود متاخد)؛ لو الجذر مش موجود بيتعمل من غير أب.
     */
    public static function openingDifference(): self
    {
        $existing = static::where('system_key', 'opening_difference')->first();
        if ($existing) {
            return $existing;
        }
        $root = static::seededRoots()['equity'] ?? null;
        // أول كود فاضي: 3199 ثم 3199.1 ثم 3199.2 … — الشجرة المعتمدة ممكن تكون واخدة الاتنين
        $code = '3199';
        for ($i = 1; static::where('code', $code)->exists(); $i++) {
            $code = '3199.'.$i;
        }

        return static::firstOrCreate(['system_key' => 'opening_difference'], [
            'code' => $code,
            'name' => 'فرق الأرصدة الافتتاحية',
            'name_en' => 'Opening balance difference',
            'parent_id' => $root?->id,
            'type' => 'equity',
            'normal_side' => 'credit',
            'is_system' => true,
            'is_postable' => true,
            'active' => true,
        ]);
    }

    /**
     * جذور الأنواع المتولدة من `GlSeeder` بالنوع ⇒ الحساب. بتتلاقي بصفتها (حساب نظام
     * من غير أب ومن غير مفتاح ومش من المسودة) مش بكودها — الاعتماد ممكن ينقل كودها لـ`S-1`.
     *
     * @return array<string, self>
     */
    public static function seededRoots(): array
    {
        $out = [];
        $rows = static::where('is_system', true)->whereNull('parent_id')->whereNull('coa_draft_id')
            ->whereNull('system_key')->orderBy('id')->get();
        foreach ($rows as $g) {
            $code = (string) $g->code;
            $digit = str_starts_with($code, 'S-') ? substr($code, 2, 1) : substr($code, 0, 1);
            $type = in_array($g->type, self::TYPES, true) ? $g->type : (self::ROOT_TYPES[$digit] ?? null);
            if ($type !== null && ! isset($out[$type])) {
                $out[$type] = $g;
            }
        }

        return $out;
    }

    /** حساب «نقدية مع المندوب» — بيتولد أول مرة تحت الأب rep_cash */
    public static function repCash(User $user): self
    {
        $existing = static::where('user_id', $user->id)->first();
        if ($existing) {
            return $existing;
        }
        $parent = static::findKey('rep_cash');
        // لو مفيش كود للمستخدم (users.code nullable) منستخدمش سلسلة فاضية
        // بعد النقطة — بتعمل كود مكرر لأكتر من مندوب من غير كود
        $suffix = $user->code ?: 'U'.$user->id;

        return static::create([
            'code' => $parent->code.'.'.$suffix,
            'name' => 'نقدية مع '.$user->name,
            'name_en' => 'Cash with '.($user->name_en ?: $user->name),
            'parent_id' => $parent->id,
            'type' => 'asset',
            'normal_side' => 'debit',
            'is_system' => true,
            'user_id' => $user->id,
            'is_postable' => true,
            'active' => true,
        ]);
    }

    /** كل الأحفاد (ids) بما فيهم الحساب نفسه — للتجميع في الشجرة */
    public function subtreeIds(): array
    {
        $ids = [$this->id];
        $frontier = [$this->id];
        while ($frontier) {
            $next = static::whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = array_merge($ids, $next);
            $frontier = $next;
        }

        return $ids;
    }

    /**
     * الرصيد الموقّع بطبيعة الحساب (مدين موجب لحسابات المدين، دائن موجب
     * لحسابات الدائن) على الفترة، شامل الأحفاد.
     */
    public function balanceBetween(?Carbon $from, ?Carbon $to): float
    {
        $q = GlLine::whereIn('gl_lines.account_id', $this->subtreeIds())
            ->join('gl_entries', 'gl_entries.id', '=', 'gl_lines.entry_id');
        if ($from) {
            $q->whereDate('gl_entries.date', '>=', $from->toDateString());
        }
        if ($to) {
            $q->whereDate('gl_entries.date', '<=', $to->toDateString());
        }
        $row = $q->selectRaw('COALESCE(SUM(gl_lines.debit),0) d, COALESCE(SUM(gl_lines.credit),0) c')->first();
        $net = (float) $row->d - (float) $row->c;

        return round($this->normal_side === 'debit' ? $net : -$net, 2);
    }
}
