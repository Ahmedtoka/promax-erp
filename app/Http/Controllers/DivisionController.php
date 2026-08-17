<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Support\Divisions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * شاشة الديفيجنز  ·  ١٧ أغسطس ٢٠٢٦
 * ═══════════════════════════════════════════════════════════════
 *
 * طلب المالك: «عاوز أدخل أشوف العملاء بالديفيجن وطريقة التعامل».
 *
 * مستويين في شاشة واحدة (نفس نمط رصيد المناديب): من غير `?division=`
 * كروت الـ11 قسم بأعدادها وأرقامها، ومعاه عملاء القسم ده.
 */
class DivisionController extends Controller
{
    public function index(Request $request)
    {
        $division = $request->string('division')->value();

        // ⚠️ استعلام تجميعي واحد لكل الجدول — مش ١١ استعلام.
        // نفس أعمدة شاشة القنوات القديمة بس على الديفيجنز — «السايكل
        // الجديدة» (طلب المالك ١٧/٨).
        $agg = Client::visibleTo(Client::query())
            ->where('status', '!=', 'rejected')
            ->selectRaw('division,
                         COUNT(*) as n,
                         SUM(purchases) as purchases,
                         SUM(collections) as collections,
                         SUM(balance) as balance,
                         MIN(NULLIF(discount, 0)) as dmin,
                         MAX(discount) as dmax,
                         AVG(NULLIF(discount, 0)) as davg')
            ->groupBy('division')
            ->get()->keyBy('division');

        // مبيعات النهارده والكميات — من مصادرها الأصلية (الدوكترين)
        $today = \App\Models\Invoice::join('clients', 'clients.id', '=', 'invoices.client_id')
            ->whereDate('invoices.created_at', today())
            ->selectRaw('clients.division, SUM(invoices.grand_total) as t')
            ->groupBy('clients.division')->pluck('t', 'division');

        $qty = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->join('clients', 'clients.id', '=', 'invoices.client_id')
            ->selectRaw('clients.division, SUM(invoice_items.qty) as q')
            ->groupBy('clients.division')->pluck('q', 'division');

        $clients = collect();

        if (Divisions::has($division)) {
            $clients = Client::visibleTo(Client::query())
                ->with(['zone', 'channel', 'group'])
                ->where('status', '!=', 'rejected')
                ->where('division', $division)
                ->orderByDesc('purchases')
                ->get();
        } elseif ($division === 'none') {
            // ⚠️ «بدون قسم» فلتر حقيقي مش حالة مخفية — المالك محتاج
            // يشوف مين لسه ماتسكّنش عشان يسكّنه
            $clients = Client::visibleTo(Client::query())
                ->with(['zone', 'channel', 'group'])
                ->where('status', '!=', 'rejected')
                ->whereNull('division')
                ->orderByDesc('purchases')
                ->get();
        }

        return view('erp.divisions', [
            'agg' => $agg,
            'today' => $today,
            'qty' => $qty,
            'division' => $division,
            'clients' => $clients,
            // عدّاد الغير مسكَّن — صف لوحده في الجدول
            'unassigned' => $agg->get(null)?->n ?? ($agg->get('')?->n ?? 0),
        ]);
    }

    /**
     * تغيير قسم عميل واحد — من صف الجدول مباشرة.
     *
     * ⚠️ فورم صغير مش صفحة تعديل: المالك بيسكّن العملاء الفاضلين
     * واحد ورا واحد، وفتح فورم العميل الكامل لكل واحد كان هيخلّي
     * التسكين اليدوي عذاب.
     */
    public function assign(Request $request, Client $client)
    {
        $data = $request->validate([
            'division' => ['nullable', Divisions::rule()],
        ]);

        $client->update(['division' => $data['division'] ?: null]);

        return back()->with('ok', __('client.division_saved', [
            'client' => $client->displayName(),
            'division' => Divisions::label($client->division),
        ]));
    }
}
