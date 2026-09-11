<?php

namespace App\Models\Gl;

use App\Models\Concerns\HasBilingualName;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
        'payables', 'vat_output', 'opening_equity',
        'sales', 'sales_returns', 'discounts_allowed',
        'expense_purchases', 'expense_fuel', 'expense_maintenance', 'expense_salaries',
        'expense_commissions', 'expense_rent', 'expense_gifts', 'expense_other',
    ];

    protected $table = 'gl_accounts';

    protected $fillable = [
        'code', 'name', 'name_en', 'parent_id', 'type', 'normal_side',
        'is_system', 'system_key', 'user_id', 'is_postable', 'active',
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

    /** حساب النظام بمفتاحه — بيرمي لو الشجرة مش متولدة (شغّل GlSeeder) */
    public static function findKey(string $systemKey): self
    {
        $acc = static::where('system_key', $systemKey)->first();
        if ($acc === null) {
            throw new \RuntimeException("GL system account [{$systemKey}] missing — run GlSeeder");
        }

        return $acc;
    }

    /** حساب «نقدية مع المندوب» — بيتولد أول مرة تحت الأب rep_cash */
    public static function repCash(User $user): self
    {
        $existing = static::where('user_id', $user->id)->first();
        if ($existing) {
            return $existing;
        }
        $parent = static::findKey('rep_cash');

        return static::create([
            'code' => $parent->code.'.'.$user->code,
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
