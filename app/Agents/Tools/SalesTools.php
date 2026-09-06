<?php

namespace App\Agents\Tools;

use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * أدوات المبيعات — دومين «مبيعات» في مساعد بروماكس (قراءة فقط)
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ مرآة الداشبورد الرئيسية (`ErpController::overview`) بالحرف:
 * نفس معادلة المبيعات (فواتير كاش/آجل + توريدات مسلّمة) ونفس
 * كويري التحصيل بمصادره ونفس كويري أفضل المنتجات.
 */
class SalesTools
{
    use Shared;

    public static function specs(): array
    {
        return [
            [
                'name' => 'sales_summary',
                'description' => 'ملخص المبيعات والتحصيل والمرتجعات لفترة (الافتراضي النهارده): الفواتير (عدد/كاش/إجمالي)، التوريدات المسلّمة، التحصيل بمصادره، المرتجعات. اختيارياً لمندوب واحد (rep_id من find_rep). نفس أرقام الداشبورد.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'from' => ['type' => 'string', 'description' => 'من تاريخ YYYY-MM-DD (الافتراضي النهارده)'],
                        'to' => ['type' => 'string', 'description' => 'إلى تاريخ YYYY-MM-DD (الافتراضي النهارده)'],
                        'rep_id' => ['type' => 'integer', 'description' => 'رقم المندوب (اختياري)'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'top_products',
                'description' => 'أفضل المنتجات مبيعاً في فترة (بقيمة المبيعات) — نفس ويدجت الداشبورد.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'from' => ['type' => 'string', 'description' => 'من تاريخ YYYY-MM-DD (الافتراضي أول الشهر)'],
                        'to' => ['type' => 'string', 'description' => 'إلى تاريخ YYYY-MM-DD (الافتراضي النهارده)'],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }

    /** حدود الفترة — نص ليل لآخر اليوم زي فلاتر الداشبورد بالظبط */
    private static function window(?string $from, ?string $to, string $defFrom = 'today'): array
    {
        $a = Carbon::parse($from ?? ($defFrom === 'month' ? today()->startOfMonth() : today()));
        $b = Carbon::parse($to ?? today());

        return [$a->startOfDay(), $b->endOfDay()];
    }

    /**
     * ⚠️ نفس قصر الداشبورد: التشانل مانجر أرقامه أرقام فريقه دايماً
     * (`overview` بتفرض `mgrId = $u->id`) — من غيرها الشات كان
     * هيديله أرقام الشركة كلها والشاشة بتديله فريقه بس.
     */
    private static function teamScope(User $user): ?array
    {
        if ($user->role !== 'manager') {
            return null;
        }

        return User::whereIn('role', User::FIELD_WORK_ROLES)
            ->where('manager_id', $user->id)->pluck('id')->push($user->id)->all();
    }

    public static function salesSummary(?string $from, ?string $to, ?int $repId, User $user): array
    {
        [$a, $b] = self::window($from, $to);

        $repIds = self::teamScope($user);
        $repName = null;

        if ($repId !== null && $repId > 0) {
            $rep = self::guardedRep($repId, $user);

            if ($rep === null) {
                return self::notAvailable();
            }

            $repIds = [$rep->id];
            $repName = $rep->displayName();
        }

        // ═══ الفواتير — نفس KPI الداشبورد بالحرف ═══
        $inv = Invoice::whereBetween('invoices.created_at', [$a, $b])
            ->when($repIds, fn ($q) => $q->whereIn('invoices.user_id', $repIds))
            ->selectRaw("COUNT(*) n, COALESCE(SUM(grand_total),0) g,
                COALESCE(SUM(total),0) net, COALESCE(SUM(tax_total),0) tax,
                COALESCE(SUM(CASE WHEN payment='cash' THEN grand_total ELSE 0 END),0) cash_g")
            ->first();

        // ═══ التوريدات المسلّمة — نفس كويري الداشبورد ═══
        $pos = PurchaseOrder::where('status', 'delivered')
            ->whereBetween('delivered_at', [$a, $b])
            ->when($repIds, fn ($q) => $q->whereIn('assigned_to', $repIds))
            ->selectRaw('COUNT(*) n, COALESCE(SUM(grand_total),0) g')->first();

        // ═══ التحصيل بمصادره — نفس كويري الداشبورد (فواتير/ميداني/توريدات) ═══
        $collRows = Transaction::where('kind', 'collection')
            ->whereBetween('created_at', [$a, $b])
            ->when($repIds, fn ($q) => $q->where(fn ($w) => $w
                ->where(fn ($x) => $x->where('source_type', Invoice::class)
                    ->whereIn('source_id', Invoice::whereIn('user_id', $repIds)->select('id')))
                ->orWhere(fn ($x) => $x->where('source_type', \App\Models\Visit::class)
                    ->whereIn('source_id', \App\Models\Visit::whereIn('user_id', $repIds)->select('id')))
                ->orWhere(fn ($x) => $x->where('source_type', PurchaseOrder::class)
                    ->whereIn('source_id', PurchaseOrder::whereIn('assigned_to', $repIds)->select('id')))))
            ->selectRaw('source_type, COALESCE(SUM(credit),0) v')
            ->groupBy('source_type')
            ->pluck('v', 'source_type');

        // ═══ المرتجعات — نفس كويري الداشبورد ═══
        $rets = \App\Models\ClientReturn::whereBetween('created_at', [$a, $b])
            ->when($repIds, fn ($q) => $q->whereIn('user_id', $repIds))
            ->selectRaw('COUNT(*) n, COALESCE(SUM(grand_total),0) g')->first();

        return [
            'from' => $a->toDateString(),
            'to' => $b->toDateString(),
            'rep' => $repName,
            'invoices' => [
                'count' => (int) $inv->n,
                'grand' => round((float) $inv->g, 2),
                'cash' => round((float) $inv->cash_g, 2),
                'credit' => round((float) $inv->g - (float) $inv->cash_g, 2),
            ],
            'delivered_pos' => [
                'count' => (int) $pos->n,
                'grand' => round((float) $pos->g, 2),
            ],
            'total_sales' => round((float) $inv->g + (float) $pos->g, 2),
            'collections' => [
                'total' => round((float) $collRows->sum(), 2),
                'invoice_cash' => round((float) ($collRows[Invoice::class] ?? 0), 2),
                'field' => round((float) ($collRows[\App\Models\Visit::class] ?? 0), 2),
                'po' => round((float) ($collRows[PurchaseOrder::class] ?? 0), 2),
            ],
            'returns' => [
                'count' => (int) $rets->n,
                'grand' => round((float) $rets->g, 2),
            ],
        ];
    }

    public static function topProducts(?string $from, ?string $to, User $user): array
    {
        [$a, $b] = self::window($from, $to, 'month');

        $repIds = self::teamScope($user);

        // ⚠️ نفس كويري ويدجت الداشبورد بالحرف (`ErpController::overview`)
        $rows = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->join('products', 'products.id', '=', 'invoice_items.product_id')
            ->whereBetween('invoices.created_at', [$a, $b])
            ->when($repIds, fn ($q) => $q->whereIn('invoices.user_id', $repIds))
            ->selectRaw('products.name pname, products.name_en pname_en,
                SUM(invoice_items.qty) q, SUM(invoice_items.total) v')
            ->groupBy('products.id', 'pname', 'pname_en')
            ->orderByDesc('v')->take(8)->get();

        return [
            'from' => $a->toDateString(),
            'to' => $b->toDateString(),
            'products' => $rows->map(fn ($r) => [
                'name' => (app()->getLocale() === 'en' && $r->pname_en) ? $r->pname_en : $r->pname,
                'qty' => (int) $r->q,
                'value' => round((float) $r->v, 2),
            ])->values()->all(),
        ];
    }
}
