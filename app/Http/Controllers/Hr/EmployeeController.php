<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\CostCenter;
use App\Models\Employee;
use App\Models\User;
use App\Support\Csv;
use App\Support\DateRange;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * ملف الموظف (Lane 7 — المهمة ١). القايمة للأدمن والموارد البشرية
 * والمحاسب، والكتابة للأدمن والموارد البشرية (`act.hr.employees`).
 *
 * ⚠️ الرقم القومي ورقم التأمين في فورم التعديل بس — مش في القايمة ولا التصدير.
 */
class EmployeeController extends Controller
{
    private const CONTRACT_DIR = 'hr/contracts';

    public function index(Request $request)
    {
        $range = DateRange::fromRequest($request);
        $q = Employee::query()->visibleTo($request->user())
            ->with(['costCenter:id,code,name,name_en', 'manager:id,code,name,name_en', 'user:id,code'])
            ->tap(fn ($q) => $range->apply($q, 'hire_date'))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = trim((string) $request->query('q'));
                $q->where(fn ($w) => $w->searchName($term)->orWhere('code', 'like', "%{$term}%"));
            })
            ->when($request->filled('cost_center_id'), fn ($q) => $q->where('cost_center_id', $request->integer('cost_center_id')))
            ->when($request->filled('department'), fn ($q) => $q->where('department', (string) $request->query('department')))
            ->when(in_array($request->query('active'), ['0', '1'], true), fn ($q) => $q->where('active', $request->query('active') === '1'));

        if ($request->boolean('export')) {
            $all = (clone $q)->orderBy('code')->limit(5000)->get();
            $yes = fn (bool $b) => $b ? __('common.yes') : __('common.no');

            return Csv::download('employees-'.now()->format('Y-m-d-Hi').'.csv',
                [
                    __('common.code'), __('hrm.name'), __('hrm.cost_center'), __('hrm.department'), __('hrm.job_title'),
                    __('hrm.manager'), __('hrm.hire_date'), __('hrm.pay_method'), __('hrm.insured'), __('hrm.taxable'),
                    __('hrm.borne_by'), __('hrm.basic_salary'), __('hrm.allowances'), __('hrm.active'),
                ],
                $all->map(fn (Employee $e) => [
                    $e->code, $e->displayName(), $e->costCenter?->displayName() ?? '', (string) $e->department,
                    (string) $e->job_title, $e->manager?->displayName() ?? '', $e->hire_date?->format('Y-m-d') ?? '',
                    __('hrm.pay_'.$e->pay_method), $yes($e->insured), $yes($e->taxable),
                    __('hrm.borne_'.$e->employee_share_borne_by), Csv::money($e->basic_salary), Csv::money($e->allowances),
                    $yes($e->active),
                ]),
                null,
                Csv::meta(__('hrm.employees'), $range->fromValue() ?: null, $range->toValue() ?: null));
        }

        return view('hr.employees', [
            'range' => $range,
            'rows' => (clone $q)->orderBy('code')->paginate(50)->withQueryString(),
            'total' => (clone $q)->count(),
            'activeCount' => (clone $q)->where('active', true)->count(),
            'salaryTotal' => (float) (clone $q)->where('active', true)->sum(DB::raw('basic_salary + allowances')),
            'costCenters' => $this->costCenters(),
            'departments' => Employee::query()->whereNotNull('department')->distinct()->orderBy('department')->pluck('department'),
        ] + $this->formOptions());
    }

    /** فورم التعديل — صفحة لوحدها عشان الرقم القومي مايبقاش في HTML القايمة */
    public function edit(Request $request, Employee $employee)
    {
        abort_unless($employee->isManagedBy($request->user()), 403);

        return view('hr.employee_form', ['employee' => $employee] + $this->formOptions($employee));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, null);

        $employee = DB::transaction(function () use ($request, $data) {
            $code = trim((string) ($data['code'] ?? ''));
            $e = Employee::create(array_merge($data, ['code' => $code !== '' ? $code : Employee::nextCode()]));

            if ($request->hasFile('contract')) {
                $e->update(['contract_path' => $this->storeContract($e, $request->file('contract'))]);
            }

            return $e;
        });

        return redirect()->route('hr.employees')->with('ok', __('hrm.employee_saved', ['code' => $employee->code]));
    }

    public function update(Request $request, Employee $employee)
    {
        abort_unless($employee->isManagedBy($request->user()), 403);

        $data = $this->validated($request, $employee);
        $code = trim((string) ($data['code'] ?? ''));
        $data['code'] = $code !== '' ? $code : $employee->code;

        DB::transaction(function () use ($request, $employee, $data) {
            $employee->update($data);

            if ($request->hasFile('contract')) {
                $employee->update(['contract_path' => $this->storeContract($employee, $request->file('contract'))]);
            }
        });

        return redirect()->route('hr.employees')->with('ok', __('hrm.employee_saved', ['code' => $employee->code]));
    }

    public function contract(Request $request, Employee $employee)
    {
        // ⚠️ الراوت بيقفل الرول، وده بيقفل الصف — نفس `visibleTo` بتاع القايمة
        abort_unless(Employee::query()->visibleTo($request->user())->whereKey($employee->id)->exists(), 403);

        $path = (string) $employee->contract_path;
        $root = realpath(storage_path('app/'.self::CONTRACT_DIR));
        $real = $path !== '' ? realpath(storage_path('app/'.$path)) : false;

        if ($real === false || $root === false || ! str_starts_with($real, $root) || ! is_file($real)) {
            abort(404, __('hrm.contract_missing'));
        }

        $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        $type = match ($ext) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'application/pdf',
        };

        return response()->file($real, [
            'Content-Type' => $type,
            'Content-Disposition' => 'inline; filename="'.$employee->code.'.'.($ext ?: 'pdf').'"',
        ]);
    }

    // ═══════════════ مساعدين ═══════════════

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Employee $employee): array
    {
        $id = $employee?->id;

        $validator = validator($request->all(), [
            'code' => ['nullable', 'string', 'max:20', Rule::unique('employees', 'code')->ignore($id)],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id'), Rule::unique('employees', 'user_id')->ignore($id)],
            'name' => ['required', 'string', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'],
            'cost_center_id' => ['nullable', 'integer', Rule::exists('cost_centers', 'id')],
            'department' => ['nullable', 'string', 'max:80'],
            'job_title' => ['nullable', 'string', 'max:80'],
            'manager_id' => ['nullable', 'integer', Rule::exists('employees', 'id')],
            'hire_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:hire_date'],
            'national_id' => ['nullable', 'digits:14'],
            'insurance_no' => ['nullable', 'string', 'max:20'],
            'pay_method' => ['nullable', Rule::in(Employee::PAY_METHODS)],
            'bank_account' => ['nullable', 'string', 'max:40'],
            'basic_salary' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'allowances' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'insurable_wage' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'insured' => ['nullable', 'boolean'],
            'taxable' => ['nullable', 'boolean'],
            'employee_share_borne_by' => ['nullable', Rule::in(Employee::BORNE_BY)],
            'attendance_required' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
            'contract' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:8192'],
        ]);

        // ⚠️ المدير لازم يبقى موظف تاني — ومش حد من تحته (دايرة بتعلّق visibleTo والاعتمادات)
        $validator->after(function (Validator $v) use ($request, $employee) {
            $managerId = (int) $request->input('manager_id');
            if ($employee === null || $managerId === 0) {
                return;
            }
            if ($managerId === $employee->id || $this->reportsUpTo($managerId, $employee->id)) {
                $v->errors()->add('manager_id', __('hrm.manager_not_self'));
            }
        });

        $data = $validator->validate();
        unset($data['contract']);

        // الخانات المتسابة فاضية بتاخد الديفولت عند الإنشاء وبتفضل زي ما هي عند التعديل
        foreach (['pay_method', 'employee_share_borne_by', 'basic_salary', 'allowances'] as $k) {
            if (array_key_exists($k, $data) && $data[$k] === null) {
                unset($data[$k]);
            }
        }
        foreach (['insured', 'taxable', 'attendance_required', 'active'] as $k) {
            if (array_key_exists($k, $data)) {
                if ($data[$k] === null) {
                    unset($data[$k]);
                } else {
                    $data[$k] = (bool) $data[$k];
                }
            }
        }
        foreach (['basic_salary', 'allowances', 'insurable_wage'] as $k) {
            if (isset($data[$k])) {
                $data[$k] = round((float) $data[$k], 2);
            }
        }

        return $data;
    }

    /** الموظف `$candidate` تحت `$employeeId` في السلسلة؟ */
    private function reportsUpTo(int $candidate, int $employeeId): bool
    {
        $seen = [];
        $current = $candidate;

        while ($current && ! isset($seen[$current])) {
            $seen[$current] = true;
            $current = (int) Employee::query()->whereKey($current)->value('manager_id');
            if ($current === $employeeId) {
                return true;
            }
        }

        return false;
    }

    private function storeContract(Employee $e, UploadedFile $file): string
    {
        $dir = storage_path('app/'.self::CONTRACT_DIR);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        // ⚠️ الامتداد من محتوى الملف مش من اسمه (نفس حارس `ContractIntake::storeFile`)
        $ext = strtolower($file->guessExtension() ?: 'pdf');
        $name = preg_replace('/[^A-Za-z0-9_-]+/', '_', $e->code).'-'.now()->format('YmdHis').'.'.$ext;
        $old = (string) $e->contract_path;

        $file->move($dir, $name);

        // النسخة القديمة بتتمسح — مايبقاش فيه ملف يتيم
        if ($old !== '' && $old !== self::CONTRACT_DIR.'/'.$name) {
            $realOld = realpath(storage_path('app/'.$old));
            $root = realpath($dir);
            if ($realOld !== false && $root !== false && str_starts_with($realOld, $root) && is_file($realOld)) {
                @unlink($realOld);
            }
        }

        return self::CONTRACT_DIR.'/'.$name;
    }

    private function costCenters()
    {
        return CostCenter::query()->where('active', true)->orderBy('code')->get(['id', 'code', 'name', 'name_en']);
    }

    /** @return array<string, mixed> */
    private function formOptions(?Employee $employee = null): array
    {
        $linked = Employee::query()->whereNotNull('user_id')
            ->when($employee?->user_id, fn ($q) => $q->where('user_id', '!=', $employee->user_id))
            ->pluck('user_id');

        return [
            'costCenters' => $this->costCenters(),
            'managers' => Employee::query()->where('active', true)
                ->when($employee, fn ($q) => $q->whereKeyNot($employee->id))
                ->orderBy('name')->get(['id', 'code', 'name', 'name_en']),
            'users' => User::query()->where('active', true)->whereNotIn('id', $linked)
                ->orderBy('name')->get(['id', 'code', 'name', 'name_en']),
            'payMethods' => Employee::PAY_METHODS,
            'borneBy' => Employee::BORNE_BY,
        ];
    }
}
