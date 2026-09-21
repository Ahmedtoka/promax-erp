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

        // ═══ تصدير كل النتيجة المفلترة (٢٢/٩) — الجدول مقسم صفحات فزرار الجدول بياخد صفحة واحدة ═══
        if ($request->boolean('export')) {
            $all = (clone $q)->latest('id')->limit(5000)->get();

            return \App\Support\Csv::download('agent-runs-'.now()->format('Y-m-d-Hi').'.csv',
                [__('common.date'), __('agent.r_user'), __('agent.r_message'), __('agent.r_domain'), __('agent.r_tools'),
                    __('agent.r_tokens'), '⏱ (s)', __('common.status')],
                $all->map(fn ($run) => [
                    $run->created_at->format('Y-m-d h:i A'),
                    $run->conversation?->user?->name ?? '—',
                    (string) $run->user_message,
                    $run->agent_name,
                    collect($run->tools_called ?? [])->pluck('name')->implode('، '),
                    $run->tokens_in + $run->tokens_out,
                    number_format($run->duration_ms / 1000, 1, '.', ''),
                    __('agent.st_'.$run->status),
                ]),
                [__('common.total'), '', '', '', '', $all->sum('tokens_in') + $all->sum('tokens_out'), '', ''],
                \App\Support\Csv::meta(__('agent.runs_title'), $from ?: null, $to ?: null));
        }

        // تفسير كروت التوكنز والتكلفة والمتوسط: نفس الأرقام مفرودة بالمجال
        $byDomain = (clone $q)->selectRaw('agent_name, COUNT(*) n, COALESCE(SUM(tokens_in), 0) tin,
            COALESCE(SUM(tokens_out), 0) tout, COALESCE(AVG(duration_ms), 0) avg_ms')
            ->groupBy('agent_name')->orderByDesc('n')->get();

        // تكلفة تقريبية بالدولار — أسعار الموديل من الكونفيج
        $cost = ($stats->tin / 1000000) * (float) config('agents.price_in')
            + ($stats->tout / 1000000) * (float) config('agents.price_out');

        return view('erp.agent_runs', [
            'rows' => $q->latest('id')->paginate(50)->withQueryString(),
            'stats' => $stats,
            'byDomain' => $byDomain,
            'cost' => $cost,
            'domains' => AgentRun::select('agent_name')->distinct()->orderBy('agent_name')
                ->pluck('agent_name'),
            'filters' => $request->only(['status', 'domain', 'from', 'to']),
        ]);
    }
}
