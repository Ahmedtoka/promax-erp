<?php

namespace App\Http\Controllers;

use App\Models\DayClose;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\RepSettlement;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * ═══════════════════════════════════════════════════════════════
 * قفل اليوم — يومية الحسابات (2026-08-06)
 * ═══════════════════════════════════════════════════════════════
 *
 * بعد ما المحاسب يصفّي مع المناديب، بيدوس «قفل اليوم»: أرقام اليوم
 * كلها بتتجمد سنابشوت — كام فاتورة، كاش/آجل/صافي، مرتجعات،
 * تحصيلات، توريدات اتسلمت، تصفيات وكام اتحصّل — والسامري بيفضل
 * كشف يومي دائم. الأرقام على نفس عقيدة الأرقام الثلاثة.
 */
class DayCloseController extends Controller
{
    public function index(Request $request)
    {
        $date = Carbon::parse($request->query('date', today()->toDateString()));

        return view('erp.dayclose', [
            'date' => $date,
            'figures' => $this->figuresFor($date),
            'close' => DayClose::whereDate('date', $date->toDateString())->first(),
            'history' => DayClose::with('closer')->orderByDesc('date')->limit(30)->get(),
        ]);
    }

    /** قفل اليوم — السنابشوت بيتاخد لحظة الضغط */
    public function store(Request $request)
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $date = Carbon::parse($data['date']);

        if (DayClose::whereDate('date', $date->toDateString())->exists()) {
            return back()->withErrors(['date' => __('incent.day_already_closed')]);
        }

        DayClose::create($this->figuresFor($date) + [
            'date' => $date->toDateString(),
            'notes' => $data['notes'] ?? null,
            'closed_by' => $request->user()->id,
        ]);

        return back()->with('ok', __('incent.day_closed_ok', ['date' => $date->toDateString()]));
    }

    /**
     * أرقام يوم — نفس الحساب للعرض اللايف وللسنابشوت:
     * المبيعات صافي من `total` (عقيدة الأرقام)، الكاش/الآجل بالمدفوع
     * (`grand_total`)، والتحصيلات قيود collection اللي **مش** مربوطة
     * بفاتورة كاش (دي جوة الكاش خلاص).
     */
    private function figuresFor(Carbon $date): array
    {
        $d = $date->toDateString();

        $inv = Invoice::whereDate('created_at', $d)
            ->selectRaw('COUNT(*) as n, COUNT(DISTINCT client_id) as clients,
                         COALESCE(SUM(total),0) as net,
                         COALESCE(SUM(CASE WHEN payment = "cash" THEN grand_total ELSE 0 END),0) as cash,
                         COALESCE(SUM(CASE WHEN payment != "cash" THEN grand_total ELSE 0 END),0) as credit')
            ->first();

        // تحصيلات مستقلة — قيود collection من غير مصدر فاتورة
        // (تحصيل الفاتورة الكاش جوه رقم الكاش فوق خلاص)
        $collections = (float) Transaction::where('kind', 'collection')
            ->whereDate('date', $d)
            ->where(fn ($q) => $q->whereNull('source_type')->orWhere('source_type', '!=', Invoice::class))
            ->sum('credit');

        $returns = (float) Transaction::where('kind', 'return')->whereDate('date', $d)->sum('credit');

        $pos = PurchaseOrder::whereDate('delivered_at', $d)
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(grand_total),0) as v')
            ->first();

        $settlements = RepSettlement::whereDate('to_at', $d)
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(received),0) as received, COALESCE(SUM(balance),0) as balance')
            ->first();

        return [
            'invoices_count' => (int) $inv->n,
            'clients_count' => (int) $inv->clients,
            'sales_cash' => round((float) $inv->cash, 2),
            'sales_credit' => round((float) $inv->credit, 2),
            'sales_net' => round((float) $inv->net, 2),
            'returns_total' => round($returns, 2),
            'collections_total' => round($collections, 2),
            'pos_delivered_count' => (int) $pos->n,
            'pos_delivered_value' => round((float) $pos->v, 2),
            'settlements_count' => (int) $settlements->n,
            'settlements_received' => round((float) $settlements->received, 2),
            'settlements_balance' => round((float) $settlements->balance, 2),
        ];
    }
}
