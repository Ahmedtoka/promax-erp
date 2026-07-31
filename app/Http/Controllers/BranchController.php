<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Client;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Warehouse;
use App\Models\Zone;
use Illuminate\Http\Request;

/**
 * الفروع والعربيات.
 *
 * ⚠️ **الأدمن ومدير القنوات بس** بيعدّلوا الفروع. مدير الفرع بيشوف
 * فرعه للقراءة — لو قدر يعدّل الفروع يقدر ينقل نفسه لفرع تاني.
 */
class BranchController extends Controller
{
    public function index(Request $request)
    {
        // مدير الفرع بيشوف فرعه بس
        $branches = Branch::scope(Branch::with('manager'), $request->user(), 'id')
            ->withCount([
                'users' => fn ($q) => $q->where('active', true),
                'clients' => fn ($q) => $q->where('status', 'active'),
                'zones',
                'warehouses',
                'vehicles' => fn ($q) => $q->where('active', true),
            ])
            ->orderBy('code')
            ->get();

        return view('erp.branches', [
            'branches' => $branches,
            'managers' => User::whereIn('role', User::MANAGER_ROLES)
                ->where('active', true)->orderBy('name')->get(),
            'canEdit' => $request->user()->isAdmin() || $request->user()->role === 'manager',

            // ⚠️ اللي مش متخصص لفرع — دول اللي بيبانوا لكل الفروع.
            // لازم يبان عددهم عشان اليوزر يعرف إن فيه حاجة مركزية.
            'unassigned' => [
                'clients' => Client::whereNull('branch_id')->where('status', 'active')->count(),
                'users' => User::whereNull('branch_id')->where('active', true)->count(),
                'zones' => Zone::whereNull('branch_id')->count(),
                'warehouses' => Warehouse::whereNull('branch_id')->count(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->rules($request);

        // ⚠️ `$data + [...]` **مابيستبدلش** — المفتاح موجود أصلاً
        // بقيمة `null` (الفورم بيبعت فاضي)، فالكود المولّد بيتحسب
        // ويترمي، و`code` بتدخل NULL على عمود NOT NULL → 500.
        $data['code'] = $data['code'] ?: Branch::nextCode();

        Branch::create($data);

        return back()->with('ok', __('branch.added'));
    }

    public function update(Request $request, Branch $branch)
    {
        $data = $this->rules($request);

        // كود فاضي في التعديل = سيبه زي ما هو
        if (blank($data['code'])) {
            unset($data['code']);
        }

        $branch->update($data);

        return back()->with('ok', __('branch.updated'));
    }

    // ═══════════════════════ العربيات ═══════════════════════

    public function vehicles(Request $request)
    {
        return view('erp.vehicles', [
            'vehicles' => Branch::scope(Vehicle::with(['branch', 'rep', 'driver']), $request->user())
                ->orderBy('plate')
                ->get(),
            // ⚠️ مسكوب — القايمة دي في المودال وبتكشف كود واسم كل
            // فرع لمدير فرع تاني حتى لو مايقدرش يحفظ.
            'branches' => Branch::scope(
                Branch::where('active', true), $request->user(), 'id',
            )->orderBy('code')->get(),

            // ⚠️ السواق والمندوب الاتنين ينفع يتحطوا — في عربيات
            // المندوب فيها بيسوق بنفسه، والقايمة لازم تسمح بده.
            'crew' => Branch::scope(User::query(), $request->user())
                ->whereIn('role', ['sales_agent', 'driver'])
                ->where('active', true)
                ->orderBy('name')
                ->get(),
            // ⚠️ `isManager()` بقت بتشمل مدير الفرع — لازم نفس شرط
            // شاشة الفروع بالظبط، وإلا بيشوف أزرار بترجّع 403.
            'canEdit' => $request->user()->isAdmin() || $request->user()->role === 'manager',
        ]);
    }

    public function storeVehicle(Request $request)
    {
        Vehicle::create($this->vehicleRules($request));

        return back()->with('ok', __('branch.vehicle_added'));
    }

    public function updateVehicle(Request $request, Vehicle $vehicle)
    {
        $data = $this->vehicleRules($request, $vehicle);

        // ⚠️ **`driver_id` بيتشال من التحديث الجماعي.** لو اتكتب على
        // العربية مباشرةً، جدول `vehicle_assignments` مابيتكتبش خالص —
        // يعني كل الموديول اللي اتعمل عشان يجاوب «العربية دي كانت مع
        // مين ومشيت كام؟» بيفضل فاضي، والعمود بيتدعس عليه زي الأول.
        $driverId = $data['driver_id'] ?? null;
        $odometer = ($data['odometer'] ?? null) !== null ? (int) $data['odometer'] : null;

        // ⚠️ **الاتنين بيتشالوا من التحديث الجماعي.** لو العداد اتكتب
        // هنا، بيدوس على الرقم الحالي من غير ما يعدّي على حارس الرجوع
        // لورا ولا حارس القفزة — وصفر زيادة واحد بيقفل العربية للأبد.
        unset($data['driver_id'], $data['odometer']);

        $vehicle->update($data);

        // نفس السواق ونفس العداد؟ مافيش حاجة تتسجّل
        $changed = (int) ($driverId ?? 0) !== (int) ($vehicle->driver_id ?? 0)
            || ($odometer !== null && $odometer !== (int) $vehicle->odometer);

        if ($changed) {
            $driver = $driverId ? \App\Models\User::find($driverId) : null;

            if ($err = \App\Models\VehicleAssignment::assign($vehicle, $driver, $odometer)) {
                // ⚠️ التحديث اتحفظ والتسكين اترفض — لازم يتقال، مش
                // نرجّع «اتحفظ» خضرا والسواق ما اتغيّرش.
                return back()->withErrors(['driver_id' => $err]);
            }
        }

        return back()->with('ok', __('branch.vehicle_updated'));
    }

    // ═══════════════════════ التحقق ═══════════════════════

    private function rules(Request $request): array
    {
        return $request->validate([
            // ⚠️ التفرد لازم يتفحص هنا — من غيره التكرار بيرجع
            // خطأ SQL خام بدل رسالة على الحقل
            'code' => ['nullable', 'string', 'max:20',
                \Illuminate\Validation\Rule::unique('branches', 'code')
                    ->ignore($request->route('branch')?->id)],
            'name' => ['required', 'string', 'max:190'],
            'name_en' => ['nullable', 'string', 'max:190'],
            'address' => ['nullable', 'string', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30'],
            'manager_id' => ['nullable', 'exists:users,id'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]) + ['active' => $request->boolean('active', true)];
    }

    private function vehicleRules(Request $request, ?Vehicle $vehicle = null): array
    {
        // ⚠️ رقم اللوحة فريد — عربيتين بنفس الرقم بيخلّوا العهدة
        // والتتبع يتخلطوا. الاستثناء للعربية اللي بنعدّلها هي نفسها.
        $unique = 'unique:vehicles,plate'.($vehicle ? ','.$vehicle->id : '');

        return $request->validate([
            'plate' => ['required', 'string', 'max:30', $unique],
            'kind' => ['nullable', 'string', 'max:190'],
            'kind_en' => ['nullable', 'string', 'max:190'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'rep_id' => ['nullable', 'exists:users,id'],
            'driver_id' => ['nullable', 'exists:users,id'],
            'model_year' => ['nullable', 'integer', 'min:1990', 'max:2100'],
            // ⚠️ العداد بيتفحص هنا بس **مابيتكتبش** من التحديث الجماعي —
            // بيعدّي على `VehicleAssignment::assign()` اللي بترفض الرجوع
            // لورا والقفزات الغلط.
            'odometer' => ['nullable', 'integer', 'min:0', 'max:4000000'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]) + [
            'is_fridge' => $request->boolean('is_fridge'),
            'active' => $request->boolean('active', true),
        ];
    }
}
