<?php

namespace App\Http\Controllers\Gl;

use App\Http\Controllers\Controller;
use App\Models\Gl\GlAccount;
use App\Services\Gl\Reports;
use App\Support\Csv;
use App\Support\DateRange;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ═══════════════════════════════════════════════════════════════
 * شجرة الحسابات + كشف الحساب
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **حساب النظام اسمه بس اللي بيتغيّر.** قواعد الترحيل بتشاور على
 * الحساب بـ`system_key` مش بالكود — بس الكود بيظهر في كل تقرير مطبوع
 * وفي كشوف المراجع، فتغييره من الشاشة كان معناه إن تقرير الشهر اللي
 * فات وتقرير الشهر ده بيتكلموا عن نفس الحساب برقمين مختلفين. المالك
 * بيغيّر الاسم («البنك» ← «بنك CIB») والكود بيفضل زي ما هو.
 */
class AccountController extends Controller
{
    public function __construct(private Reports $reports)
    {
    }

    public function index(Request $request)
    {
        $range = DateRange::fromRequest($request);
        $accounts = GlAccount::orderBy('code')->get();

        // الأبناء مجمّعين مرة واحدة — البارشال بيرسم نفسه بالتكرار
        // من الخريطة دي من غير كويري لكل عقدة
        $children = [];
        foreach ($accounts as $a) {
            $children[(int) $a->parent_id][] = $a;
        }

        return view('gl.accounts', [
            'range' => $range,
            'roots' => $children[0] ?? [],
            'children' => $children,
            'balances' => $this->reports->balancesByAccount($range->from, $range->to),
            'parents' => $accounts->where('is_postable', false)->values(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'parent_id' => ['required', 'exists:gl_accounts,id'],
            // كود رقمي، وممكن لاحقة بعد نقطة (زي نقدية مندوب `1110.SLS-1`)
            'code' => ['required', 'string', 'max:20', 'regex:/^[0-9]{1,6}(\.[A-Z0-9-]{1,12})?$/', 'unique:gl_accounts,code'],
            'name' => ['required', 'string', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'],
        ]);

        $parent = GlAccount::findOrFail($data['parent_id']);

        // ⚠️ **الكود لازم يبدأ برقم جذر الأب.** نوع الحساب بيتحدد من أول
        // رقم في الكود (`ROOT_TYPES`) — حساب كوده `5108` تحت أب أصول
        // كان هيتسجّل «مصروف» وهو في نص شجرة الأصول، فالميزانية بتبوظ
        // والمجموع في الشجرة مايساويش مجموع الأبناء.
        if ($data['code'][0] !== $parent->code[0]) {
            return back()->withErrors(['code' => __('gl.code_root_mismatch', ['root' => $parent->code[0]])])->withInput();
        }
        if ($parent->is_postable) {
            return back()->withErrors(['parent_id' => __('gl.parent_must_be_group')])->withInput();
        }

        $type = GlAccount::ROOT_TYPES[$data['code'][0]];

        GlAccount::create([
            'code' => $data['code'],
            'name' => $data['name'],
            'name_en' => $data['name_en'] ?? null,
            'parent_id' => $parent->id,
            'type' => $type,
            'normal_side' => GlAccount::normalSideFor($type),
            'is_system' => false,
            'is_postable' => true,
            'active' => true,
        ]);

        return redirect()->route('gl.accounts')->with('ok', __('gl.account_saved'));
    }

    public function update(Request $request, GlAccount $account)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'],
            'active' => ['nullable', 'boolean'],
            'code' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]{1,6}(\.[A-Z0-9-]{1,12})?$/', Rule::unique('gl_accounts', 'code')->ignore($account->id)],
            'parent_id' => ['nullable', 'exists:gl_accounts,id'],
        ]);

        $attrs = ['name' => $data['name'], 'name_en' => $data['name_en'] ?? null];

        if ($account->is_system) {
            // ⚠️ إيقاف حساب نظام = القاعدة بتدوّر عليه وقت الترحيل
            // وماتلاقيهوش، والفاتورة الجاية بترمي. الاسم بس اللي بيتغيّر.
            if ($request->has('active') && ! $request->boolean('active')) {
                return back()->withErrors(['active' => __('gl.system_account_stays')])->withInput();
            }
        } else {
            $code = ($data['code'] ?? '') !== '' ? $data['code'] : $account->code;

            // ⚠️ **رقم الجذر مايتغيّرش أبداً.** النوع (`type`) بيتحدد من أول
            // رقم في الكود، والسطور المترحّلة على الحساب ده اتكتبت وهو
            // «مصروف» — تغيير الكود لـ`1108` كان بيحوّله «أصل» وكل قيد
            // قديم فيه بيهاجر من قائمة الدخل للميزانية في نفس اللحظة
            // من غير أي قيد تصحيح.
            if ($code[0] !== $account->code[0]) {
                return back()->withErrors(['code' => __('gl.code_root_locked', ['root' => $account->code[0]])])->withInput();
            }

            if (! empty($data['parent_id'])) {
                $parent = GlAccount::findOrFail((int) $data['parent_id']);

                if ($parent->is_postable) {
                    return back()->withErrors(['parent_id' => __('gl.parent_must_be_group')])->withInput();
                }
                if ($parent->code[0] !== $code[0]) {
                    return back()->withErrors(['parent_id' => __('gl.code_root_mismatch', ['root' => $code[0]])])->withInput();
                }
                // ⚠️ الأب جوه شجرة الحساب نفسه (أو هو هو) = حلقة —
                // `subtreeIds()` و`balanceBetween()` بيلفّوا للأبد والشاشة
                // بتعلّق من غير رسالة
                if (in_array($parent->id, $account->subtreeIds(), true)) {
                    return back()->withErrors(['parent_id' => __('gl.parent_cycle')])->withInput();
                }

                $attrs['parent_id'] = $parent->id;
            }

            // الجذر متقفل فوق، فالنوع مايتغيّرش عملياً — بنحسبه تاني
            // عشان صف قديم بنوع مش متسق مع كوده يتصلّح وهو بيتعدّل
            $type = GlAccount::ROOT_TYPES[$code[0]];
            $attrs['code'] = $code;
            $attrs['type'] = $type;
            $attrs['normal_side'] = GlAccount::normalSideFor($type);
            $attrs['active'] = $request->boolean('active');
        }

        $account->update($attrs);

        return redirect()->route('gl.accounts')->with('ok', __('gl.account_saved'));
    }

    public function show(Request $request, GlAccount $account)
    {
        $range = DateRange::fromRequest($request, 'month');
        $statement = $this->reports->statement($account, $range->from, $range->to);

        if ($request->boolean('export')) {
            $rows = [];
            foreach ($statement['rows'] as $r) {
                $rows[] = [
                    $r['entry']?->date?->format('Y-m-d'),
                    $r['entry']?->number,
                    $r['entry']?->memo,
                    $r['line']->account?->code,
                    Csv::money($r['line']->debit),
                    Csv::money($r['line']->credit),
                    Csv::money($r['running']),
                ];
            }

            return Csv::download(
                'gl-'.$account->code.'-'.now()->format('Y-m-d').'.csv',
                [__('common.date'), __('gl.number'), __('gl.memo'), __('gl.account'), __('gl.debit'), __('gl.credit'), __('gl.running')],
                $rows,
                [__('gl.closing'), '', '', '', '', '', Csv::money($statement['closing'])],
            );
        }

        return view('gl.account', [
            'account' => $account,
            'range' => $range,
            'statement' => $statement,
        ]);
    }
}
