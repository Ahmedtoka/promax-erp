<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Target;
use App\Models\User;
use App\Services\TargetProgress;
use App\Support\Scope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * التارجيت السنوي الهرمي (١١ أغسطس ٢٠٢٦) — قرار المالك
 * ═══════════════════════════════════════════════════════════════
 *
 * الأدمن بيحط تارجيت الشركة للسنة ويوزّعه على المديرين، وكل مدير
 * بيوزّع على فريقه، وتارجيت المندوب بيتوزّع على عملائه. كل مستوى
 * ١٢ شهر، وتعديل شهر بيوازن الفرق على الشهور اللي بعده بس.
 *
 * ⚠️ **التوزيع مرن مش صارم (قرار المالك):** الحفظ مابيرفضش لو مجموع
 * القسمة ≠ الإجمالي — الشاشة بتحذّر بالألوان وبس.
 *
 * ⚠️ **السكوب:** المدير مايوصلش غير لشجرته — كل POST بيتأكد إن
 * العقدة بتاعته (`assertSubtree`) وإن المندوب من فريقه والعميل
 * مرئي له (`Scope::assertClient`). الأدمن بيعدّي في كل حتة.
 *
 * ⚠️ غير `IncentiveController` (تارجتات الحوافز الشهرية) — الاتنين
 * شغالين جنب بعض.
 */
class TargetPlanController extends Controller
{
    private function year(Request $request): int
    {
        $y = (int) $request->input('year', now()->year);

        return max(2020, min(2100, $y));
    }

    private function company(int $year): ?Target
    {
        return Target::with('months')
            ->where('year', $year)
            ->where('kind', Target::KIND_COMPANY)
            ->first();
    }

    /**
     * فريق الميدان بتاع مدير معيّن **+ هو نفسه** — المدير بقى بيشتغل
     * ميداني (قرار ١١/٨) فبياخد نصيب من التوزيع زي أي مندوب.
     */
    private function teamOf(User $manager)
    {
        return User::query()
            ->where('active', true)
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->whereIn('role', User::FIELD_ROLES)
                    ->where('manager_id', $manager->id))
                ->orWhere('id', $manager->id))
            ->orderBy('name')
            ->get();
    }

    /**
     * الفاعل يقدر يكتب في العقدة دي؟ — أدمن في كل حتة، والمدير في
     * شجرته بس (عقدة مندوب أبوها هو، أو عقدة عميل جدها هو).
     * عقدة الشركة وعقدة المدير نفسها قرار أدمن.
     */
    private function assertSubtree(User $actor, Target $t): void
    {
        if ($actor->isAdmin()) {
            return;
        }

        abort_unless($actor->role === 'manager', 403, __('targets.not_your_subtree'));

        $ownerId = match ($t->kind) {
            Target::KIND_REP => $t->parent?->user_id,
            Target::KIND_CLIENT => $t->parent?->parent?->user_id,
            default => null,
        };

        abort_unless((int) $ownerId === (int) $actor->id, 403, __('targets.not_your_subtree'));
    }

    /**
     * إنشاء/تحديث عقدة ابن — جديدة بتتقسم بالتساوي، وتغيير الإجمالي
     * بيعيد التوزيع بنفس منحنى شهورها القديم.
     */
    private function writeChild(Target $parent, array $keys, float $amount, User $actor): void
    {
        $node = Target::firstOrNew($keys + ['year' => (int) $parent->year]);
        $isNew = ! $node->exists;
        $changed = $isNew || round((float) $node->amount, 2) !== $amount;

        $node->parent_id = $parent->id;
        $node->amount = $amount;

        if ($isNew) {
            $node->created_by = $actor->id;
        }

        $node->save();

        if ($isNew) {
            $node->distributeEvenly();
        } elseif ($changed) {
            $node->rescaleMonths();
        }
    }

    // ═══════════════════════ الشاشة الرئيسية ═══════════════════════

    public function annual(Request $request)
    {
        $actor = $request->user();
        $year = $this->year($request);
        $company = $this->company($year);

        $managerNodes = $company
            ? Target::with('months')
                ->where('parent_id', $company->id)
                ->where('kind', Target::KIND_MANAGER)
                ->get()
                ->keyBy('user_id')
            : collect();

        // ═══ الجريد الشهري: الأدمن بيشوف الشركة، والمدير عقدته ═══
        $gridNode = $actor->isAdmin() ? $company : $managerNodes->get($actor->id);
        $grid = null;

        if ($gridNode !== null) {
            $computed = $gridNode->kind === Target::KIND_COMPANY
                ? TargetProgress::companyByMonth($year)
                : TargetProgress::managerByMonth($year, (int) $gridNode->user_id);

            $manuals = $gridNode->manualByMonth();
            $effective = $computed;

            foreach ($manuals as $m => $v) {
                if ($v !== null) {
                    $effective[$m] = $v;
                }
            }

            $grid = [
                'node_id' => $gridNode->id,
                'kind' => $gridNode->kind,
                'annual' => (float) $gridNode->amount,
                'targets' => $gridNode->monthsArray(),
                'computed' => $computed,
                'achieved' => $effective,
                'manuals' => $manuals,
                'achieved_total' => round(array_sum($effective), 2),
            ];
        }

        // ═══ كارت توزيع المديرين — أدمن بس ═══
        $managersCard = null;

        if ($actor->isAdmin() && $company !== null) {
            $rows = User::where('role', 'manager')
                ->where('active', true)
                ->orderBy('name')
                ->get()
                ->map(function (User $mgr) use ($year, $managerNodes) {
                    $node = $managerNodes->get($mgr->id);

                    return [
                        'user' => $mgr,
                        'amount' => $node === null ? null : (float) $node->amount,
                        'achieved' => round(array_sum(TargetProgress::managerByMonth($year, $mgr->id)), 2),
                    ];
                })
                ->all();

            $managersCard = ['annual' => (float) $company->amount, 'rows' => $rows];
        }

        // ═══ بلوكات توزيع الفرق: الأدمن كل مدير له عقدة، والمدير نفسه ═══
        $blockManagers = $actor->isAdmin()
            ? User::whereIn('id', $managerNodes->keys()->all())->orderBy('name')->get()
            : ($managerNodes->has($actor->id) ? collect([$actor]) : collect());

        $blocks = [];

        foreach ($blockManagers as $mgr) {
            $node = $managerNodes->get($mgr->id);

            if ($node === null) {
                continue;
            }

            $repNodes = Target::where('parent_id', $node->id)
                ->where('kind', Target::KIND_REP)
                ->get()
                ->keyBy('user_id');

            $rows = [];

            foreach ($this->teamOf($mgr) as $u) {
                $rn = $repNodes->get($u->id);

                $rows[] = [
                    'user' => $u,
                    'amount' => $rn === null ? null : (float) $rn->amount,
                    'achieved' => round(array_sum(TargetProgress::repByMonth($year, $u->id)), 2),
                ];
            }

            $blocks[] = [
                'manager' => $mgr,
                'node_id' => $node->id,
                'annual' => (float) $node->amount,
                'achieved' => round(array_sum(TargetProgress::managerByMonth($year, $mgr->id)), 2),
                'rows' => $rows,
            ];
        }

        return view('erp.targets_annual', [
            'year' => $year,
            'company' => $company,
            'grid' => $grid,
            'managersCard' => $managersCard,
            'blocks' => $blocks,
        ]);
    }

    // ═══════════════════ توزيع عملاء مندوب واحد ═══════════════════

    public function repClients(Request $request, User $user)
    {
        $actor = $request->user();
        $year = $this->year($request);

        // سكوب: الأدمن، أو المدير على نفسه أو على واحد من فريقه
        if ($actor->id !== $user->id) {
            Scope::assertStaff($actor, $user);
        }

        abort_unless(in_array($user->role, User::FIELD_WORK_ROLES, true), 404);

        $repNode = Target::with('months')
            ->where('year', $year)
            ->where('kind', Target::KIND_REP)
            ->where('user_id', $user->id)
            ->first();

        $clientNodes = $repNode
            ? Target::where('parent_id', $repNode->id)
                ->where('kind', Target::KIND_CLIENT)
                ->get()
                ->keyBy('client_id')
            : collect();

        // ⚠️ أي قايمة عملاء لازم تعدّي على visibleTo — الدوكترين
        $clients = Client::visibleTo(Client::query(), $actor)
            ->where('rep_id', $user->id)
            ->with('group')
            ->orderBy('name')
            ->get();

        $rows = $clients->map(function (Client $c) use ($year, $clientNodes) {
            $node = $clientNodes->get($c->id);

            return [
                'client' => $c,
                'amount' => $node === null ? null : (float) $node->amount,
                'achieved' => round(array_sum(TargetProgress::clientByMonth($year, $c->id)), 2),
            ];
        })->all();

        return view('erp.targets_rep', [
            'year' => $year,
            'rep' => $user,
            'repNode' => $repNode,
            'repAchieved' => $repNode !== null
                ? $repNode->achievedTotal()
                : round(array_sum(TargetProgress::repByMonth($year, $user->id)), 2),
            'rows' => $rows,
        ]);
    }

    // ═══════════════════════ الكتابة ═══════════════════════

    /** إنشاء/تعديل تارجيت الشركة للسنة — أدمن بس */
    public function createCompany(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);

        $data = $request->validate([
            'year' => ['required', 'integer', 'between:2020,2100'],
            'amount' => ['required', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($data, $request) {
            $node = Target::firstOrNew([
                'year' => (int) $data['year'],
                'kind' => Target::KIND_COMPANY,
            ]);

            $isNew = ! $node->exists;
            $node->amount = round((float) $data['amount'], 2);

            if ($isNew) {
                $node->created_by = $request->user()->id;
            }

            $node->save();

            $isNew ? $node->distributeEvenly() : $node->rescaleMonths();
        });

        return redirect()
            ->route('erp.targets.annual', ['year' => (int) $data['year']])
            ->with('ok', __('targets.company_saved'));
    }

    /** حفظ توزيع المديرين كله مرة واحدة — أدمن بس */
    public function saveManagers(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor->isAdmin(), 403);

        $data = $request->validate([
            'year' => ['required', 'integer', 'between:2020,2100'],
            'rows' => ['nullable', 'array'],
            'rows.*' => ['nullable', 'numeric', 'min:0'],
        ]);
        $data['rows'] = $data['rows'] ?? [];

        $company = $this->company((int) $data['year']);
        abort_unless($company !== null, 422, __('targets.no_company'));

        $managerIds = User::where('role', 'manager')
            ->where('active', true)
            ->pluck('id')
            ->all();

        DB::transaction(function () use ($data, $company, $managerIds, $actor) {
            foreach ($data['rows'] as $uid => $amount) {
                if (! in_array((int) $uid, $managerIds, true)) {
                    continue;
                }

                $this->writeChild($company, [
                    'kind' => Target::KIND_MANAGER,
                    'user_id' => (int) $uid,
                ], round((float) ($amount ?? 0), 2), $actor);
            }
        });

        return back()->with('ok', __('targets.managers_saved'));
    }

    /** حفظ توزيع فريق مدير — الأدمن أو صاحب العقدة نفسه */
    public function saveReps(Request $request, Target $target)
    {
        abort_unless($target->kind === Target::KIND_MANAGER, 404);

        $actor = $request->user();
        abort_unless(
            $actor->isAdmin() || (int) $target->user_id === (int) $actor->id,
            403,
            __('targets.not_your_subtree'),
        );

        $data = $request->validate([
            'rows' => ['nullable', 'array'],
            'rows.*' => ['nullable', 'numeric', 'min:0'],
        ]);
        $data['rows'] = $data['rows'] ?? [];

        $manager = $target->user;
        abort_unless($manager !== null, 404);

        // المرساة: بس فريق المدير ده (+ هو نفسه) — أي id غريب بيتتجاهل
        $teamIds = $this->teamOf($manager)->pluck('id')->all();

        DB::transaction(function () use ($data, $target, $teamIds, $actor) {
            foreach ($data['rows'] as $uid => $amount) {
                if (! in_array((int) $uid, $teamIds, true)) {
                    continue;
                }

                $this->writeChild($target, [
                    'kind' => Target::KIND_REP,
                    'user_id' => (int) $uid,
                ], round((float) ($amount ?? 0), 2), $actor);
            }
        });

        return back()->with('ok', __('targets.reps_saved'));
    }

    /** حفظ توزيع عملاء مندوب — الأدمن أو المدير صاحب الشجرة */
    public function saveClients(Request $request, Target $target)
    {
        abort_unless($target->kind === Target::KIND_REP, 404);

        $actor = $request->user();
        $this->assertSubtree($actor, $target);

        $data = $request->validate([
            'rows' => ['nullable', 'array'],
            'rows.*' => ['nullable', 'numeric', 'min:0'],
        ]);
        $data['rows'] = $data['rows'] ?? [];

        $rep = $target->user;
        abort_unless($rep !== null, 404);

        DB::transaction(function () use ($data, $target, $rep, $actor) {
            foreach ($data['rows'] as $clientId => $amount) {
                $client = Client::find((int) $clientId);

                // المرساة: العميل متسكّن للمندوب ده فعلاً — الغريب بيتتجاهل
                if ($client === null || (int) $client->rep_id !== (int) $rep->id) {
                    continue;
                }

                // ⚠️ والفاعل شايفه أصلاً (سكوب المدير + الفرع)
                Scope::assertClient($actor, $client);

                $this->writeChild($target, [
                    'kind' => Target::KIND_CLIENT,
                    'client_id' => $client->id,
                ], round((float) ($amount ?? 0), 2), $actor);
            }
        });

        return back()->with('ok', __('targets.clients_saved'));
    }

    /**
     * تعديل تارجيت شهر — الفرق بيتوازن على الشهور اللي بعده.
     * الماضي مقفول، وديسمبر مالوش بعده فمرفوض.
     */
    public function rebalance(Request $request, Target $target)
    {
        $actor = $request->user();

        // عقدة الشركة والمدير أدمن بس — والمندوب/العميل لصاحب الشجرة
        if (in_array($target->kind, [Target::KIND_COMPANY, Target::KIND_MANAGER], true)) {
            abort_unless($actor->isAdmin(), 403, __('targets.not_your_subtree'));
        } else {
            $this->assertSubtree($actor, $target);
        }

        $data = $request->validate([
            'month' => ['required', 'integer', 'between:1,12'],
            'amount' => ['required', 'numeric', 'min:0'],
        ]);

        $month = (int) $data['month'];

        abort_if(Target::monthLocked((int) $target->year, $month), 422, __('targets.month_locked'));
        abort_if($month >= 12, 422, __('targets.last_month_fixed'));

        DB::transaction(fn () => $target->rebalance($month, round((float) $data['amount'], 2)));

        return back()->with('ok', __('targets.rebalanced'));
    }

    /**
     * المحقق اليدوي للشهور اللي فاتت — أدمن بس وعلى عقدة الشركة بس
     * (قرار المالك: «أوفر أول» رقم واحد للشهر على مستوى الشركة).
     * الخانة الفاضية بتمسح اليدوي ويرجع حساب السيستم.
     */
    public function saveManual(Request $request, Target $target)
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_unless($target->kind === Target::KIND_COMPANY, 422, __('targets.manual_company_only'));

        $data = $request->validate([
            'manual' => ['required', 'array'],
            'manual.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($data, $target) {
            for ($m = 1; $m <= 12; $m++) {
                if (! array_key_exists($m, $data['manual'])) {
                    continue;
                }

                // اليدوي للشهور اللي فاتت بس — الجاي بيتحسب لايف
                if (! Target::monthLocked((int) $target->year, $m)) {
                    continue;
                }

                $v = $data['manual'][$m];

                \App\Models\TargetMonth::updateOrCreate(
                    ['target_id' => $target->id, 'month' => $m],
                    ['manual_actual' => ($v === null || $v === '') ? null : round((float) $v, 2)],
                );
            }
        });

        return back()->with('ok', __('targets.manual_saved'));
    }
}
