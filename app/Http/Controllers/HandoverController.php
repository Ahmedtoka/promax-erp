<?php

namespace App\Http\Controllers;

use App\Models\HandoverMark;
use App\Models\HandoverModule;
use App\Models\HandoverScreen;
use App\Services\Handover;
use App\Support\Access;
use App\Support\Csv;
use App\Support\DateRange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * ═══════════════════════════════════════════════════════════════
 * تسليم النظام (الإغلاق ١٩/١٠/٢٠٢٦، spec §3.8 و§4.1)
 * ═══════════════════════════════════════════════════════════════
 *
 * الشركة المستلمة (`handover_reviewer`) بتعلّم على الشاشات، والأدمن
 * بيكتب حالة التيست واللايف والديدلاين. الراوتس كلها
 * `role:admin,handover_reviewer`، والـmeta `role:admin` + `act.handover.meta`.
 *
 * ⚠️ **التعليم مابيتمسحش أبداً** — الإلغاء بيملا `unmarked_at`/`unmarked_by`.
 * ⚠️ مفيش علاقة جاية من الريكوست غير الموديول والشاشة (route-model binding)،
 * والاتنين مش متقيدين بفرع ولا فريق — الأداة كلها للنظام كله.
 */
class HandoverController extends Controller
{
    public function index(Request $request)
    {
        $modules = $this->modulesWithProgress();

        return view('handover.index', [
            'modules' => $modules,
            'overall' => Handover::overall(),
        ]);
    }

    public function show(HandoverModule $module)
    {
        $module->load(['screens.marks' => fn ($q) => $q->whereNull('unmarked_at')->with('user')]);

        return view('handover.module', [
            'module' => $module,
            'progress' => Handover::moduleProgress($module),
            'canMeta' => Access::action(auth()->user(), 'act.handover.meta'),
        ]);
    }

    public function mark(Request $request, HandoverScreen $screen): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        DB::transaction(function () use ($screen, $data) {
            // ⚠️ قفل الشاشة — ضغطتين ورا بعض ماتعملش تعليمين شغّالين
            HandoverScreen::query()->whereKey($screen->id)->lockForUpdate()->first();
            $this->markOne($screen, ($data['note'] ?? null) ?: null);
        });

        return back()->with('ok', __('handover.flash_marked'));
    }

    public function unmark(HandoverScreen $screen): RedirectResponse
    {
        DB::transaction(function () use ($screen) {
            HandoverScreen::query()->whereKey($screen->id)->lockForUpdate()->first();
            $this->unmarkWhere(HandoverMark::query()->where('screen_id', $screen->id));
        });

        return back()->with('ok', __('handover.flash_unmarked'));
    }

    /** تعليم الموديول من بره = كل شاشاته اتعلّمت (spec §3.8) */
    public function markAll(HandoverModule $module): RedirectResponse
    {
        $n = DB::transaction(function () use ($module) {
            $screens = HandoverScreen::query()->where('module_id', $module->id)->lockForUpdate()->get();
            $n = 0;

            foreach ($screens as $screen) {
                $n += $this->markOne($screen, null) ? 1 : 0;
            }

            return $n;
        });

        return back()->with('ok', __('handover.flash_marked_all', ['n' => $n]));
    }

    public function unmarkAll(HandoverModule $module): RedirectResponse
    {
        $n = DB::transaction(function () use ($module) {
            HandoverScreen::query()->where('module_id', $module->id)->lockForUpdate()->get();

            return $this->unmarkWhere(HandoverMark::query()
                ->whereIn('screen_id', HandoverScreen::query()->where('module_id', $module->id)->select('id')));
        });

        return back()->with('ok', __('handover.flash_unmarked_all', ['n' => $n]));
    }

    /** حالة الموديول — المالك بس (`role:admin` على الراوت + الأكشن هنا) */
    public function meta(Request $request, HandoverModule $module): RedirectResponse
    {
        abort_unless(Access::action($request->user(), 'act.handover.meta'), 403, __('common.forbidden'));

        $data = $request->validate([
            'test_status' => ['required', Rule::in(HandoverModule::TEST_STATUSES)],
            'live_status' => ['required', Rule::in(HandoverModule::LIVE_STATUSES)],
            'deadline' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            // الموديولز «بتتعدل» بعد التوليد (spec §4.1) — الاسم اختياري هنا
            'name_ar' => ['sometimes', 'required', 'string', 'max:150'],
            'name_en' => ['sometimes', 'required', 'string', 'max:150'],
        ]);

        $module->update([
            'test_status' => $data['test_status'],
            'live_status' => $data['live_status'],
            'deadline' => ($data['deadline'] ?? null) ?: null,
            'notes' => ($data['notes'] ?? null) ?: null,
        ] + array_intersect_key($data, array_flip(['name_ar', 'name_en'])));

        return back()->with('ok', __('handover.flash_meta'));
    }

    /** ملخص الموديولز CSV: الموديول · الشاشات · المعلّم · النسبة · التيست · اللايف · الديدلاين */
    public function export()
    {
        $rows = $this->modulesWithProgress()->map(fn (HandoverModule $m) => [
            $m->displayName(),
            $m->progress['total'],
            $m->progress['marked'],
            $m->progress['pct'],
            __('handover.test_'.$m->test_status),
            __('handover.live_'.$m->live_status),
            $m->deadline?->toDateString() ?? '',
        ]);

        $overall = Handover::overall();

        return Csv::download('handover-'.now()->format('Y-m-d').'.csv', [
            __('handover.col_module'), __('handover.col_screens'), __('handover.col_marked'),
            __('handover.col_pct'), __('handover.test_status'), __('handover.live_status'), __('handover.deadline'),
        ], $rows, [__('handover.overall'), $overall['total'], $overall['marked'], $overall['pct'], '', '', ''],
            Csv::meta(__('handover.title')));
    }

    /** سجل التعليمات — فلتر «من — إلى» على `marked_at` + تصدير السيرفر (`?export=1`) */
    public function log(Request $request)
    {
        $range = DateRange::fromRequest($request);

        $q = HandoverMark::query()
            ->with(['screen.module', 'user', 'unmarker'])
            ->orderByDesc('marked_at')->orderByDesc('id');
        $range->apply($q, 'marked_at');

        if ($request->boolean('export')) {
            $rows = $q->get()->map(fn (HandoverMark $k) => [
                $k->marked_at?->format('Y-m-d H:i'),
                $k->screen?->module?->displayName() ?? '',
                $k->screen?->displayTitle() ?? '',
                $k->screen?->route ?? '',
                $k->user?->displayName() ?? '',
                $k->note ?? '',
                $k->unmarked_at ? __('handover.state_unmarked') : __('handover.state_active'),
                $k->unmarked_at?->format('Y-m-d H:i') ?? '',
                $k->unmarker?->displayName() ?? '',
            ]);

            return Csv::download('handover-log-'.now()->format('Y-m-d').'.csv', [
                __('handover.col_marked_at'), __('handover.col_module'), __('handover.col_screen'),
                __('handover.col_route'), __('handover.col_user'), __('handover.note'), __('handover.col_state'),
                __('handover.col_unmarked_at'), __('handover.col_unmarked_by'),
            ], $rows, null, Csv::meta(__('handover.log_title'),
                $range->fromValue() ?: null, $range->toValue() ?: null));
        }

        return view('handover.log', [
            'marks' => $q->paginate(50)->withQueryString(),
            'range' => $range,
        ]);
    }

    // ═══════════════ أدوات ═══════════════

    /** الموديولز بعدّ شاشاتها والمعلّم منها — كويري واحدة مش كويري لكل موديول */
    private function modulesWithProgress()
    {
        return HandoverModule::query()
            ->withCount([
                'screens as screens_total',
                'screens as screens_marked' => fn ($q) => $q->marked(),
            ])
            ->orderBy('sort')->orderBy('id')
            ->get()
            ->each(function (HandoverModule $m) {
                $m->progress = Handover::pack((int) $m->screens_marked, (int) $m->screens_total);
            });
    }

    /** تعليم شاشة لو مش معلّمة — true لو اتعمل تعليم جديد */
    private function markOne(HandoverScreen $screen, ?string $note): bool
    {
        if (HandoverMark::query()->where('screen_id', $screen->id)->whereNull('unmarked_at')->exists()) {
            return false;
        }

        HandoverMark::create([
            'screen_id' => $screen->id,
            'user_id' => auth()->id(),
            'marked_at' => now(),
            'note' => $note,
        ]);

        return true;
    }

    /** إلغاء التعليمات الشغّالة — بيملا السجل مش بيمسح */
    private function unmarkWhere($q): int
    {
        return $q->whereNull('unmarked_at')->update([
            'unmarked_at' => now(),
            'unmarked_by' => auth()->id(),
        ]);
    }
}
