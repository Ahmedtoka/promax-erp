<?php

namespace App\Http\Controllers\Gl;

use App\Http\Controllers\Controller;
use App\Models\Gl\GlAccount;
use App\Models\Gl\GlPostingRule;
use App\Services\Gl\CoaAdopter;
use App\Services\Gl\GlMapping;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ═══════════════════════════════════════════════════════════════
 * شاشة الربط (D1) — مفاتيح النظام وخانات القواعد على الشجرة المعتمدة
 * ═══════════════════════════════════════════════════════════════
 *
 * الشاشة للمحاسب يقرا منها، والكتابة أدمن بس (`act.gl.admin`): المفتاح
 * بيحدد مكان كل قيد جاي. الفورمين «الكل أو ولا حاجة» زي `saveRules`.
 */
class MappingController extends Controller
{
    public function index()
    {
        $adopted = CoaAdopter::adopted();
        $active = GlAccount::where('active', true)->whereNull('user_id')->orderBy('code')->get();
        $withLines = DB::table('gl_lines')->distinct()->pluck('account_id')->flip();
        // بعد الاعتماد القايمة حسابات الشجرة المعتمدة بس؛ قبله الشجرة المتولدة
        $pool = $adopted ? $active->whereNotNull('coa_draft_id') : $active;

        return view('gl.mapping', [
            'adopted' => $adopted,
            'keys' => GlMapping::keys(),
            'rules' => GlMapping::rules(),
            'problems' => GlMapping::problems(),
            'postable' => $pool->where('is_postable', true)->values(),
            // `rep_cash` بيبقى مجموعة — أي حساب مالوش سطور
            'repCashPool' => $pool->reject(fn ($a) => isset($withLines[$a->id]))->values(),
        ]);
    }

    public function saveKeys(Request $request)
    {
        $data = $request->validate([
            'keys' => ['required', 'array'],
            'keys.*' => ['nullable', 'integer'],
        ]);

        $wanted = [];
        foreach ($data['keys'] as $key => $id) {
            if (! in_array($key, GlMapping::REQUIRED_KEYS, true) || ! $id) {
                continue;
            }
            $wanted[$key] = (int) $id;
        }
        $accounts = GlAccount::whereIn('id', array_values($wanted))->get()->keyBy('id');
        $current = GlAccount::whereIn('system_key', array_keys($wanted))->pluck('id', 'system_key');
        // المفاتيح اللي بتتنقل فعلاً — حاملها القديم بيتفضى في نفس الطلب
        $moving = array_filter($wanted, fn ($id, $key) => (int) ($current[$key] ?? 0) !== $id, ARRAY_FILTER_USE_BOTH);
        $freed = array_keys($moving);

        $errors = [];
        $taken = [];
        foreach ($moving as $key => $id) {
            $field = 'keys.'.$key;
            $acc = $accounts->get($id);
            if ($acc === null) {
                $errors[$field][] = __('gl.map_no_account', ['key' => GlMapping::keyLabel($key)]);

                continue;
            }
            if (isset($taken[$id])) {
                $errors[$field][] = __('gl.map_same_account', ['code' => $acc->code]);

                continue;
            }
            $taken[$id] = true;
            $why = GlMapping::keyRefusal($key, $acc, $freed);
            if ($why !== null) {
                $errors[$field][] = GlMapping::keyLabel($key).': '.$why;
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($moving, $accounts, $request) {
            GlAccount::whereIn('system_key', array_keys($moving))->update(['system_key' => null]);
            foreach ($moving as $key => $id) {
                GlMapping::assignKey($key, $accounts->get($id)->fresh(), $request->user());
            }
        });

        $msg = __('gl.map_saved', ['n' => count($moving)]);
        // القيود القديمة لسه على الحساب الرقابي القديم — الفحص الثابت محتاج إعادة بناء
        if (array_intersect(array_keys($moving), GlMapping::CONTROL_KEYS) !== []) {
            $msg .= ' '.__('gl.map_rebuild_needed');
        }

        return redirect()->route('gl.mapping')->with('ok', $msg);
    }

    public function saveRules(Request $request)
    {
        $data = $request->validate([
            'pins' => ['required', 'array'],
            'pins.*' => ['array'],
            'pins.*.*' => ['nullable', 'integer'],
        ]);

        $rules = GlPostingRule::whereIn('key', array_keys($data['pins']))->get()->keyBy('key');
        $ids = collect($data['pins'])->flatten()->filter()->map(fn ($v) => (int) $v)->unique()->all();
        $accounts = GlAccount::whereIn('id', $ids)->get()->keyBy('id');

        $errors = [];
        $changes = [];
        foreach ($data['pins'] as $key => $sides) {
            $rule = $rules->get($key);
            if ($rule === null) {
                continue;   // مش بنولّد قواعد من الفورم
            }
            foreach ($sides as $side => $id) {
                if (! in_array($side, GlMapping::SIDES, true)) {
                    continue;
                }
                $id = $id ? (int) $id : null;
                $was = $rule->{$side.'_account_id'};
                if (($was === null ? null : (int) $was) === $id) {
                    continue;
                }
                $acc = $id ? $accounts->get($id) : null;
                $why = $id && $acc === null
                    ? __('gl.map_no_account', ['key' => $rule->label ?: $key])
                    : GlMapping::pinRefusal($rule, $side, $acc);
                if ($why !== null) {
                    $errors['pins.'.$key.'.'.$side][] = $why;

                    continue;
                }
                $changes[] = [$rule, $side, $acc];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($changes) {
            foreach ($changes as [$rule, $side, $acc]) {
                GlMapping::pin($rule, $side, $acc);
            }
        });

        return redirect()->route('gl.mapping')->with('ok', __('gl.map_saved', ['n' => count($changes)]));
    }
}
