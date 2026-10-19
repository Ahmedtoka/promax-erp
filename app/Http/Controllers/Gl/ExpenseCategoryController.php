<?php

namespace App\Http\Controllers\Gl;

use App\Http\Controllers\Controller;
use App\Models\ExpenseCategory;
use App\Models\Gl\GlAccount;
use App\Support\Csv;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * بنود المصروف (L4-D8) — قايمة إعدادات: نوع البند، حسابه الافتراضي،
 * العمر الافتراضي للأصل، وربط البنزين بالعربية. الشاشة للمحاسب يقرا
 * منها، والإضافة والتعديل أدمن بس (`act.gl.admin`).
 *
 * ⚠️ مفيش تاريخ شغل على البند (إعداد مش مستند) — فمفيش `DateRange`.
 */
class ExpenseCategoryController extends Controller
{
    public function index(Request $request)
    {
        $q = ExpenseCategory::with('account')->withCount('expenses')
            ->when(in_array($request->query('type'), ExpenseCategory::TYPES, true), fn ($q) => $q->where('type', $request->query('type')))
            ->orderBy('type')->orderBy('code');

        if ($request->boolean('export')) {
            $all = (clone $q)->get();

            return Csv::download('expense-categories-'.now()->format('Y-m-d-Hi').'.csv',
                [__('cash.code'), __('cash.name'), __('cash.name_en'), __('cash.cat_type'), __('gl.code'), __('gl.account'),
                    __('cash.cat_life'), __('cash.cat_links_vehicle'), __('cash.active'), __('cash.cat_expenses')],
                $all->map(fn (ExpenseCategory $c) => [
                    $c->code, $c->name, (string) $c->name_en, __('cash.cat_type_'.$c->type), (string) $c->account?->code,
                    $c->account?->displayName() ?? '', (string) $c->life_months, $c->links_vehicle ? __('cash.yes') : __('cash.no'),
                    $c->active ? __('cash.yes') : __('cash.no'), (string) $c->expenses_count,
                ]),
                null,
                Csv::meta(__('cash.categories')));
        }

        return view('gl.expense_categories', [
            'rows' => $q->get(),
            'types' => ExpenseCategory::TYPES,
            'accounts' => GlAccount::whereIn('type', ['expense', 'asset'])->where('is_postable', true)->where('active', true)->orderBy('code')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->rules($request, null);
        if (($err = $this->accountError($data)) !== null) {
            return back()->withErrors(['account_id' => $err])->withInput();
        }
        ExpenseCategory::create($data);

        return back()->with('ok', __('cash.cat_saved'));
    }

    public function update(Request $request, ExpenseCategory $category)
    {
        $data = $this->rules($request, $category);
        if (($err = $this->accountError($data)) !== null) {
            return back()->withErrors(['account_id' => $err])->withInput();
        }
        // الكود ثابت بعد الحفظ — الصفوف القديمة والتقارير بتتكلم بيه
        unset($data['code']);
        $category->update($data);

        return back()->with('ok', __('cash.cat_saved'));
    }

    /** @return array<string, mixed> */
    private function rules(Request $request, ?ExpenseCategory $category): array
    {
        $data = $request->validate([
            'code' => [$category ? 'nullable' : 'required', 'alpha_dash', 'max:30', Rule::unique('expense_categories', 'code')->ignore($category?->id)],
            'name' => ['required', 'string', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'],
            'type' => ['required', Rule::in(ExpenseCategory::TYPES)],
            'account_id' => ['required', 'integer', Rule::exists('gl_accounts', 'id')->whereIn('type', ['expense', 'asset'])->where('is_postable', 1)->where('active', 1)],
            'life_months' => ['nullable', 'integer', 'min:1', 'max:600'],
            'links_vehicle' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
        ]);
        $data['links_vehicle'] = $request->boolean('links_vehicle');
        $data['active'] = $category ? $request->boolean('active') : true;
        if ($data['type'] !== 'asset') {
            $data['life_months'] = null;
        }

        return $data;
    }

    /** بند أصل = حساب أصل، وأي نوع تاني = حساب مصروف */
    private function accountError(array $data): ?string
    {
        $want = $data['type'] === 'asset' ? 'asset' : 'expense';

        return GlAccount::whereKey($data['account_id'])->value('type') === $want
            ? null
            : __($want === 'asset' ? 'cash.exp_account_asset' : 'cash.exp_account_expense');
    }
}
