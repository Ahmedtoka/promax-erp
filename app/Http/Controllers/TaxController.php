<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Setting;
use App\Services\EtaExport;
use App\Services\Tax;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * ═══════════════════════════════════════════════════════════════
 * الضريبة: الإعدادات + الفاتورة الإلكترونية
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ الشاشتين **للأدمن بس**. الرقم الضريبي والنسبة بيأثروا على كل
 * فاتورة جديدة، ومدير قناة مالوش حق يغيّرهم.
 */
class TaxController extends Controller
{
    // ═══════════════════════ الإعدادات ═══════════════════════

    public function settings()
    {
        return view('erp.tax_settings', [
            's' => Setting::all_(),
            'taxableClients' => Client::where('taxable', true)->count(),
            'totalClients' => Client::count(),
        ]);
    }

    public function saveSettings(Request $request)
    {
        $data = $request->validate([
            'tax_enabled' => ['nullable', 'boolean'],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'company_name' => ['required', 'string', 'max:190'],
            'company_name_en' => ['nullable', 'string', 'max:190'],
            'company_tax_id' => ['nullable', 'string', 'max:30'],
            'company_activity_code' => ['nullable', 'string', 'max:20'],
            'company_branch_code' => ['nullable', 'string', 'max:20'],
            'company_governorate' => ['nullable', 'string', 'max:80'],
            'company_city' => ['nullable', 'string', 'max:80'],
            'company_street' => ['nullable', 'string', 'max:190'],
            'company_building' => ['nullable', 'string', 'max:30'],
            'company_phone' => ['nullable', 'string', 'max:30'],
            'eta_client_id' => ['nullable', 'string', 'max:120'],
        ]);

        // ⚠️ **الشاشة دي بتحفظ مفاتيحها هي بس** (إصلاح 2026-08-07).
        // كانت بتلفّ على `Setting::DEFAULTS` كلها وتكتب `''` لأي مفتاح
        // مش في الفورم — يعني **حفظ إعدادات الضريبة كان بيمسح إعدادات
        // الحوافز** (قيمة النقطة، نقاط الزيارة، نطاق الليدز) وكل مفتاح
        // جديد يتضاف للسيستم. الحلقة على مفاتيح الفورم نفسها.
        //
        // ⚠️ المفاتيح الاختيارية بتختفي من `$data` خالص لما تكون فاضية،
        // فلازم `??` — من غيرها مسح حقل مابيتحفظش وبيفضل بقيمته القديمة.
        $own = [
            'tax_rate', 'company_name', 'company_name_en', 'company_tax_id',
            'company_activity_code', 'company_branch_code', 'company_governorate',
            'company_city', 'company_street', 'company_building', 'company_phone',
            'eta_client_id',
        ];

        $pairs = [];
        foreach ($own as $key) {
            $pairs[$key] = (string) ($data[$key] ?? '');
        }

        // الشيك بوكس مابيتبعتش أصلاً وهو مقفول
        $pairs['tax_enabled'] = $request->boolean('tax_enabled') ? '1' : '0';

        Setting::writeMany($pairs);

        return back()->with('ok', __('tax.saved'));
    }

    // ═══════════════════════ الفاتورة الإلكترونية ═══════════════════════

    public function eta(Request $request)
    {
        [$from, $to] = $this->period($request);

        $invoices = $this->periodInvoices($from, $to);

        // أسباب الرفض بتتحسب مرة واحدة هنا وبتتمرّر للفيو — الفيو
        // مايناديش خدمة لكل صف.
        $problems = [];
        foreach ($invoices as $inv) {
            $p = EtaExport::problems($inv);
            if ($p) {
                $problems[$inv->id] = $p;
            }
        }

        return view('erp.eta', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'invoices' => $invoices,
            'problems' => $problems,
            'ready' => $invoices->where('eta_status', 'ready')->count(),
            'exported' => $invoices->where('eta_status', 'exported')->count(),
            'submitted' => $invoices->where('eta_status', 'submitted')->count(),
            'taxTotal' => round((float) $invoices->sum('tax_total'), 2),
            'netTotal' => round((float) $invoices->sum('total'), 2),
            'taxOn' => Tax::enabled(),
            'companyTaxId' => Setting::read('company_tax_id'),
        ]);
    }

    /** تنزيل ملف المنظومة للفترة */
    public function export(Request $request)
    {
        [$from, $to] = $this->period($request);

        // ⚠️ المرفوضة والسليمة مابيتخلطوش: مستند واحد ناقص بيرفّض
        // الحزمة كلها عند المصلحة، فبنصدّر السليم بس.
        $invoices = $this->periodInvoices($from, $to)
            ->filter(fn (Invoice $i) => EtaExport::problems($i) === [])
            ->values();

        if ($invoices->isEmpty()) {
            return back()->withErrors(['period' => __('tax.nothing_to_export')]);
        }

        // ⚠️ الملف بيتبني **قبل** ما نغيّر الحالة. لو البناء رمى
        // استثناء بعد ما علّمنا «اتصدّرت»، الفواتير بتختفي من قايمة
        // الجاهز وملف مانزلش — واليوزر مش عارف يرجّعها.
        $json = json_encode(EtaExport::batch($invoices), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        if ($json === false) {
            return back()->withErrors(['period' => __('tax.export_failed')]);
        }

        Invoice::whereIn('id', $invoices->pluck('id'))
            ->where('eta_status', 'ready')
            ->update(['eta_status' => 'exported']);

        return response($json, 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'
                .EtaExport::filename($from->toDateString(), $to->toDateString()).'"',
        ]);
    }

    /** اليوزر رفع الملف على البورتال ورجع يعلّم */
    public function markSubmitted(Request $request)
    {
        [$from, $to] = $this->period($request);

        $count = Invoice::whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->where('eta_status', 'exported')
            ->update(['eta_status' => 'submitted', 'eta_submitted_at' => now()]);

        return back()->with('ok', __('tax.marked', ['count' => $count]));
    }

    // ═══════════════════════ مساعدات ═══════════════════════

    /** @return array{0: Carbon, 1: Carbon} */
    private function period(Request $request): array
    {
        // الافتراضي: الشهر الحالي — أكتر فترة بيتقدّم بيها
        $from = $request->filled('from')
            ? Carbon::parse($request->input('from'))
            : now()->startOfMonth();

        $to = $request->filled('to')
            ? Carbon::parse($request->input('to'))
            : now()->endOfMonth();

        // ⚠️ لو اليوزر عكس التواريخ بنقلبهم بدل ما نرجّع نتيجة فاضية
        // ونسيبه يفكّر إن مفيش فواتير.
        return $to->lt($from) ? [$to, $from] : [$from, $to];
    }

    private function periodInvoices(Carbon $from, Carbon $to)
    {
        return Invoice::with(['client', 'items.product'])
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            // ⚠️ مش `tax_total > 0`. فاتورة لعميل مسجّل كل أصنافها
            // معفاة ضريبتها صفر وبرضو **مستند واجب الإبلاغ**.
            // الفلترة بالمبلغ كانت بتخبّيها خالص.
            ->where('eta_status', '!=', 'none')
            ->orderBy('created_at')
            ->get();
    }
}
