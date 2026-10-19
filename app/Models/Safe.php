<?php

namespace App\Models;

use App\Models\Concerns\HasBilingualName;
use App\Models\Gl\GlAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * خزنة أو حساب بنك (التصميم §٣.٢).
 *
 * ⚠️ **`Expense::PAID_FROM` القديم بيتربط هنا:** `cash_main` = الخزنة
 * الرئيسية للمعادي `MAADI-MAIN`، و`bank` = أول حساب بنك `BANK-1`.
 * `rep_cash` **مش خزنة** — دي عهدة المندوب وفضلت حسابات `1110.*`.
 */
class Safe extends Model
{
    use HasBilingualName;

    public const KINDS = ['cash', 'bank'];

    /** قيمة `paid_from` القديمة ← كود الخزنة */
    public const LEGACY = ['cash_main' => 'MAADI-MAIN', 'bank' => 'BANK-1'];

    protected $fillable = [
        'code', 'name', 'name_en', 'kind', 'branch_id', 'cost_center_id', 'currency',
        'custodian_user_id', 'gl_account_id', 'bank_name', 'account_no', 'active',
    ];

    protected $attributes = ['currency' => 'EGP', 'active' => true];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /** الخزنة اللي بتقابل `paid_from` قديم — `rep_cash` وأي قيمة تانية null */
    public static function forLegacy(string $paidFrom): ?self
    {
        $code = self::LEGACY[$paidFrom] ?? null;

        return $code ? static::where('code', $code)->first() : null;
    }

    /**
     * رصيد الخزنة من الدفتر: صافي (مدين − دائن) على حسابها في الشجرة
     * لحد تاريخ (شامل). الخزنة والبنك طبيعتهم مدينة. من غير حساب = صفر.
     */
    public function balance(?Carbon $asOf = null): float
    {
        if (! $this->gl_account_id) {
            return 0.0;
        }

        $row = DB::table('gl_lines')
            ->join('gl_entries', 'gl_entries.id', '=', 'gl_lines.entry_id')
            ->where('gl_lines.account_id', $this->gl_account_id)
            ->when($asOf, fn ($q) => $q->whereDate('gl_entries.date', '<=', $asOf->toDateString()))
            ->selectRaw('COALESCE(SUM(gl_lines.debit),0) d, COALESCE(SUM(gl_lines.credit),0) c')
            ->first();

        return round((float) $row->d - (float) $row->c, 2);
    }

    /**
     * داخل/خارج الخزنة في نافذة (شامل الطرفين، على `gl_entries.date`) —
     * مجموع المدين ومجموع الدائن على حسابها. من غير حساب = صفر/صفر.
     *
     * @return array{in: float, out: float}
     */
    public function movementsBetween(?Carbon $from, ?Carbon $to): array
    {
        if (! $this->gl_account_id) {
            return ['in' => 0.0, 'out' => 0.0];
        }

        $row = DB::table('gl_lines')
            ->join('gl_entries', 'gl_entries.id', '=', 'gl_lines.entry_id')
            ->where('gl_lines.account_id', $this->gl_account_id)
            ->when($from, fn ($q) => $q->whereDate('gl_entries.date', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->whereDate('gl_entries.date', '<=', $to->toDateString()))
            ->selectRaw('COALESCE(SUM(gl_lines.debit),0) d, COALESCE(SUM(gl_lines.credit),0) c')
            ->first();

        return ['in' => round((float) $row->d, 2), 'out' => round((float) $row->c, 2)];
    }

    /**
     * حساب الخزنة في الشجرة (L4-D2): لو متربط يرجع هو، وإلا يتفتح حساب
     * مدين قابل للترحيل تحت أب `cash_main` بكود `<الأب>.<كود الخزنة>`.
     * ⚠️ ده صف في الشجرة مش قيد — زي `GlAccount::repCash`، مش لازم يعدّي
     * على `Ledger`.
     */
    public function ensureGlAccount(): GlAccount
    {
        if ($this->gl_account_id && ($acc = GlAccount::find($this->gl_account_id))) {
            return $acc;
        }

        return DB::transaction(function () {
            // ⚠️ تحت **أب** النقدية (`11`) مش تحت `cash_main` نفسه — ده حساب قابل للترحيل
            $parent = GlAccount::findKey('cash_main')->parent
                ?? throw new \RuntimeException('GL account [cash_main] has no parent group');
            $code = $parent->code.'.'.$this->code;
            if (GlAccount::where('code', $code)->exists()) {
                $code .= '-'.$this->id;
            }

            $acc = GlAccount::create([
                'code' => $code,
                'name' => $this->name,
                'name_en' => $this->name_en ?: $this->name,
                'parent_id' => $parent->id,
                'type' => 'asset',
                'normal_side' => 'debit',
                'is_system' => true,
                'is_postable' => true,
                'active' => true,
            ]);
            $this->forceFill(['gl_account_id' => $acc->id])->save();

            return $acc;
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function custodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'custodian_user_id');
    }

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class);
    }

    public function counts(): HasMany
    {
        return $this->hasMany(SafeCount::class);
    }

    public function statements(): HasMany
    {
        return $this->hasMany(BankStatement::class);
    }
}
