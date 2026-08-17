<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Support\Divisions;
use Illuminate\Http\Request;

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

        // ⚠️ استعلام تجميعي واحد لكل الكروت — مش ١١ استعلام
        $agg = Client::visibleTo(Client::query())
            ->where('status', '!=', 'rejected')
            ->selectRaw('division,
                         COUNT(*) as n,
                         SUM(purchases) as purchases,
                         SUM(balance) as balance')
            ->groupBy('division')
            ->get()->keyBy('division');

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
            'division' => $division,
            'clients' => $clients,
            // عدّاد الغير مسكَّن — كارت لوحده في الشاشة
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
