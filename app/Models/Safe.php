<?php

namespace App\Models;

use App\Models\Concerns\HasBilingualName;
use App\Models\Gl\GlAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
}
