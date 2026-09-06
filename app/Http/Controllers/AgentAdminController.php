<?php

namespace App\Http\Controllers;

use App\Models\AgentRun;
use Illuminate\Http\Request;

/**
 * ═══════════════════════════════════════════════════════════════
 * مراجعة مساعد بروماكس — شاشة الأدمن (٧/٩/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * مين سأل إيه، أنهي دومين، الأدوات اللي اتنادت، التوكنز والتكلفة
 * التقريبية، والحالة. المرفوض (refused) هو خريطة طلبات الناس
 * للمراحل الجاية — أهم عمود في الشاشة.
 */
class AgentAdminController extends Controller
{
    public function runs(Request $request)
    {
        $status = (string) $request->query('status', '');
        $domain = (string) $request->query('domain', '');
        $from = $request->query('from');
        $to = $request->query('to');

        $q = AgentRun::with(['conversation.user'])
            ->when(in_array($status, ['ok', 'failed', 'refused'], true),
                fn ($w) => $w->where('status', $status))
            ->when($domain !== '', fn ($w) => $w->where('agent_name', $domain))
            ->when($from, fn ($w) => $w->whereDate('created_at', '>=', $from))
            ->when($to, fn ($w) => $w->whereDate('created_at', '<=', $to));

        // الإجماليات من نفس الكويري المفلترة — الكروت تساوي الجدول
        $stats = (clone $q)->selectRaw("
            COUNT(*) n,
            COALESCE(SUM(tokens_in), 0) tin,
            COALESCE(SUM(tokens_out), 0) tout,
            COALESCE(SUM(status = 'refused'), 0) refused,
            COALESCE(SUM(status = 'failed'), 0) failed,
            COALESCE(AVG(duration_ms), 0) avg_ms
        ")->first();

        // تكلفة تقريبية بالدولار — أسعار الموديل من الكونفيج
        $cost = ($stats->tin / 1000000) * (float) config('agents.price_in')
            + ($stats->tout / 1000000) * (float) config('agents.price_out');

        return view('erp.agent_runs', [
            'rows' => $q->latest('id')->paginate(50)->withQueryString(),
            'stats' => $stats,
            'cost' => $cost,
            'domains' => AgentRun::select('agent_name')->distinct()->orderBy('agent_name')
                ->pluck('agent_name'),
            'filters' => $request->only(['status', 'domain', 'from', 'to']),
        ]);
    }
}
