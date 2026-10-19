<?php

namespace App\Http\Controllers\Gl;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\CostCenter;
use App\Models\Gl\GlAccount;
use App\Models\Gl\GlOpeningLine;
use App\Models\Gl\GlPeriod;
use App\Models\Gl\GlRebuildRun;
use App\Models\Safe;
use App\Models\Setting;
use App\Models\Supplier;
use App\Services\Gl\ClosedPeriod;
use App\Services\Gl\CoaAdopter;
use App\Services\Gl\FoundationSeed;
use App\Services\Gl\GlMapping;
use App\Services\Gl\GoLive\GoLiveWizard;
use App\Services\Gl\Ledger;
use App\Services\Gl\OpeningBalances;
use App\Services\Gl\OpeningBuilder;
use App\Services\Gl\RebuildDiff;
use App\Support\Csv;
use App\Support\Scope;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * ═══════════════════════════════════════════════════════════════
 * معالج تشغيل دفتر الأستاذ (الخط ٣، ١٩/١٠/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * الشاشة للمحاسب يقرا منها (`gl.` = أدمن + محاسب)، وكل كتابة أدمن بس
 * (`act.gl.admin` + `role:admin` على الراوت). كل خطوة حالتها حية —
 * «اتأكدت» تاريخ بس، والخطوة اللي اتأكدت وبعدين بقت حمرا بتبان حمرا.
 */
class GoLiveController extends Controller
{
    public function __construct(private Ledger $ledger)
    {
    }

    public function index()
    {
        return redirect()->route('gl.golive.show', GoLiveWizard::current());
    }

    public function show(Request $request, int $n)
    {
        $steps = GoLiveWizard::steps();
        abort_unless(isset($steps[$n]), 404);
        $step = $steps[$n];

        if ($n === 8 && $request->boolean('export')) {
            return $this->exportPreview();
        }

        $states = [];
        foreach ($steps as $k => $s) {
            $states[$k] = ['state' => $s->state(), 'done' => GoLiveWizard::done($s)];
        }

        return view('gl.golive.index', [
            'steps' => $steps,
            'states' => $states,
            'n' => $n,
            'step' => $step,
            'current' => GoLiveWizard::current(),
            'data' => $step->data(),
        ]);
    }

    public function confirm(Request $request, int $n)
    {
        abort_unless(isset(GoLiveWizard::steps()[$n]), 404);
        try {
            GoLiveWizard::confirm($n, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['golive' => $e->getMessage()]);
        }

        return redirect()->route('gl.golive.show', GoLiveWizard::current())->with('ok', __('golive.confirmed'));
    }

    // ═══ ١ — البداية وسياسة القفل ═══
    public function saveStart(Request $request)
    {
        $data = $request->validate([
            'gl_start_date' => ['required', 'date'],
            'gl_close_policy' => ['required', Rule::in(['manual', 'auto'])],
            'gl_close_after_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            'close_through' => ['nullable', 'date_format:Y-m'],
        ]);

        $old = Setting::read('gl_start_date');
        $new = Carbon::parse($data['gl_start_date'])->toDateString();
        $moving = $old === null || $new > $old;
        if ($moving && ($hit = $this->ledger->closedBefore($new)) !== null) {
            return back()->withErrors(['gl_start_date' => __('gl.period_closed', ['period' => $hit])]);
        }
        // «اقفل لحد شهر»: بعد التشغيل الفعلي بس (قبله الشهر هيتجمّد فاضي
        // في إعادة البناء)، وعمره ما بيوصل للشهر الحالي أو اللي بعده
        if (! empty($data['close_through'])) {
            if (! RebuildDiff::executed()) {
                return back()->withErrors(['close_through' => __('golive.s1_close_before_run')]);
            }
            if ($data['close_through'] >= today()->format('Y-m')) {
                return back()->withErrors(['close_through' => __('golive.s1_close_future')]);
            }
        }

        Setting::writeMany([
            'gl_start_date' => $new,
            'gl_close_policy' => $data['gl_close_policy'],
            'gl_close_after_days' => (string) ($data['gl_close_after_days'] ?? 10),
        ]);

        $purged = $moving ? $this->ledger->purgeBefore($new) : 0;

        // «اقفل لحد شهر» — عمره ما بيقفل شهر قبل شهر البداية
        $closed = [];
        if (! empty($data['close_through'])) {
            $month = Carbon::parse($new)->startOfMonth();
            $last = Carbon::createFromFormat('!Y-m', $data['close_through']);
            while ($month->lte($last)) {
                GlPeriod::close($month->format('Y-m'), $request->user());
                $closed[] = $month->format('Y-m');
                $month->addMonthNoOverflow();
            }
        }

        $msg = __('golive.s1_saved');
        if ($purged > 0) {
            $msg .= ' — '.__('gl.start_moved_purged', ['n' => $purged]);
        }
        if ($closed !== []) {
            $msg .= ' — '.__('golive.s1_closed', ['list' => implode(' · ', $closed)]);
        }

        return redirect()->route('gl.golive.show', 1)->with('ok', $msg);
    }

    // ═══ ٣ — مراكز التكلفة ═══
    public function addCenter(Request $request)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['line', 'branch'])],
            'parent_id' => ['required', 'integer', 'exists:cost_centers,id'],
            'code' => ['required', 'string', 'max:30', 'unique:cost_centers,code'],
            'name' => ['required', 'string', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'],
        ]);
        CostCenter::create($data + ['active' => true]);

        return redirect()->route('gl.golive.show', 3)->with('ok', __('golive.s3_added', ['code' => $data['code']]));
    }

    public function seedCenters(Request $request)
    {
        $apply = $request->boolean('apply');
        try {
            $r = FoundationSeed::run(! $apply);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['golive' => $e->getMessage()]);
        }

        return redirect()->route('gl.golive.show', 3)
            ->with('ok', $apply ? __('golive.s3_seeded') : __('golive.s3_seed_dry'))
            ->with('seed', $r + ['dry' => ! $apply]);
    }

    // ═══ ٤ — الخزن ═══
    public function linkSafe(Request $request, Safe $safe)
    {
        $data = $request->validate(['gl_account_id' => ['required', 'integer', 'exists:gl_accounts,id']]);
        $acc = GlAccount::findOrFail($data['gl_account_id']);
        if (! $acc->active || ! $acc->is_postable || $acc->user_id !== null || $acc->type !== 'asset') {
            return back()->withErrors(['golive' => __('golive.s4_bad_account')]);
        }

        try {
            DB::transaction(function () use ($safe, $acc, $request) {
                $safe->update(['gl_account_id' => $acc->id]);
                // الخزنة القديمة (`cash_main` / `bank`) = مفتاح النظام بيتنقل معاها
                $legacy = array_search($safe->code, Safe::LEGACY, true);
                if ($legacy !== false) {
                    GlMapping::assignKey($legacy, $acc, $request->user());
                }
            });
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['golive' => $e->getMessage()]);
        }

        return redirect()->route('gl.golive.show', 4)->with('ok', __('golive.s4_linked', ['safe' => $safe->code, 'code' => $acc->code]));
    }

    // ═══ ٦ — الافتتاحي ═══
    public function addOpeningLine(Request $request)
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer', 'exists:gl_accounts,id'],
            'cost_center_id' => ['nullable', 'integer', 'exists:cost_centers,id'],
            'side' => ['required', Rule::in(['debit', 'credit'])],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'note' => ['nullable', 'string', 'max:250'],
        ]);
        $acc = GlAccount::findOrFail($data['account_id']);
        if (! $acc->active || ! $acc->is_postable || $acc->user_id !== null
            || (CoaAdopter::adopted() && $acc->coa_draft_id === null && $acc->system_key !== 'opening_difference')) {
            return back()->withErrors(['golive' => __('golive.s6_bad_account')]);
        }
        // العملاء/الموردين بيتحسبوا من كشوفهم — السطر اليدوي عليهم كان هيفك التطابق
        if (in_array($acc->system_key, ['receivables', 'payables'], true)) {
            return back()->withErrors(['golive' => __('golive.s6_control_account')]);
        }
        $amount = round((float) $data['amount'], 2);
        GlOpeningLine::create([
            'account_id' => $acc->id,
            'cost_center_id' => $data['cost_center_id'] ?? null,
            'debit' => $data['side'] === 'debit' ? $amount : 0,
            'credit' => $data['side'] === 'credit' ? $amount : 0,
            'note' => $data['note'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('gl.golive.show', 6)->with('ok', __('golive.s6_line_added'));
    }

    public function deleteOpeningLine(GlOpeningLine $line)
    {
        $line->delete();

        return redirect()->route('gl.golive.show', 6)->with('ok', __('golive.s6_line_deleted'));
    }

    public function openingClient(Request $request)
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'amount' => ['required', 'numeric', 'min:-999999999999', 'max:999999999999'],
        ]);
        $asOf = app(OpeningBuilder::class)->asOf();
        if ($asOf === null) {
            return back()->withErrors(['golive' => __('golive.opening_no_start')]);
        }
        $client = Client::findOrFail($data['client_id']);
        Scope::assertClient($request->user(), $client);

        try {
            app(OpeningBalances::class)->setClient($client, (float) $data['amount'], $asOf, $request->user());
        } catch (ClosedPeriod $e) {
            return back()->withErrors(['golive' => $e->getMessage()]);
        }

        return redirect()->route('gl.golive.show', 6)->with('ok', __('golive.s6_client_saved', ['name' => $client->displayName()]));
    }

    public function openingSupplier(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'amount' => ['required', 'numeric', 'min:-999999999999', 'max:999999999999'],
        ]);
        $asOf = app(OpeningBuilder::class)->asOf();
        if ($asOf === null) {
            return back()->withErrors(['golive' => __('golive.opening_no_start')]);
        }
        $supplier = Supplier::findOrFail($data['supplier_id']);

        try {
            app(OpeningBalances::class)->setSupplier($supplier, (float) $data['amount'], $asOf, $request->user());
        } catch (ClosedPeriod $e) {
            return back()->withErrors(['golive' => $e->getMessage()]);
        }

        return redirect()->route('gl.golive.show', 6)->with('ok', __('golive.s6_supplier_saved', ['name' => $supplier->displayName()]));
    }

    public function postOpening(Request $request)
    {
        try {
            $entry = app(OpeningBuilder::class)->post($request->user());
        } catch (\InvalidArgumentException|ClosedPeriod $e) {
            return back()->withErrors(['golive' => $e->getMessage()]);
        }

        return redirect()->route('gl.golive.show', 6)->with('ok', __('golive.s6_posted', ['number' => $entry->number]));
    }

    // ═══ ٨ — المعاينة ═══
    public function preview(Request $request)
    {
        // إعادة البناء ممكن تاخد وقت على بيانات حقيقية (بتزامن طبقة التكلفة كمان)
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        $run = app(RebuildDiff::class)->preview($request->user());

        return redirect()->route('gl.golive.show', 8)
            ->with($run->status === 'ok' ? 'ok' : 'err', __('golive.s8_ran_'.$run->status));
    }

    public function approve(Request $request, GlRebuildRun $run)
    {
        try {
            app(RebuildDiff::class)->approve($run, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['golive' => $e->getMessage()]);
        }

        return redirect()->route('gl.golive.show', 8)->with('ok', __('golive.s8_approved'));
    }

    private function exportPreview()
    {
        $run = GlRebuildRun::where('mode', 'preview')->latest('id')->first();
        $rows = [];
        foreach ($run?->rows ?? [] as $r) {
            $rows[] = [$r['period'], $r['source'], $r['created'], Csv::money($r['created_amount']), $r['deleted'],
                Csv::money($r['deleted_amount']), $r['changed'], Csv::money($r['changed_delta'])];
        }

        return Csv::download(
            'gl-rebuild-preview-'.now()->format('Y-m-d').'.csv',
            [__('golive.col_period'), __('golive.col_source'), __('golive.col_created'), __('golive.col_created_amount'),
                __('golive.col_deleted'), __('golive.col_deleted_amount'), __('golive.col_changed'), __('golive.col_changed_delta')],
            $rows,
        );
    }
}
