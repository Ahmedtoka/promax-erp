<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\RolePermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * إدارة الرولز المخصصة (٩/١١) — أدمن بس (`role:admin` على الراوت).
 *
 * ⚠️ `base_role` بيتحدد وقت الإنشاء/النسخ وبس — بيحدد نطاق البيانات
 * فمايتغيرش بعدها. الرول اللي عليه يوزرز (نشطين أو لأ) مايتوقفش ولا يتمسح.
 */
class RoleController extends Controller
{
    /** @return array<string, mixed> */
    private function nameRules(?Role $ignore = null): array
    {
        $builtin = $this->builtinLabels();

        $rule = fn (string $col) => [
            'required', 'string', 'max:60',
            Rule::unique('roles', $col)->ignore($ignore?->id),
            function ($attr, $value, $fail) use ($builtin) {
                if (in_array(mb_strtolower(trim((string) $value)), $builtin, true)) {
                    $fail(__('role.name_builtin'));
                }
            },
        ];

        return ['name_ar' => $rule('name_ar'), 'name_en' => $rule('name_en')];
    }

    /** @return list<string> أسماء الرولز الأساسية (عربي + إنجليزي) صغيرة */
    private function builtinLabels(): array
    {
        $out = [];

        foreach (['ar', 'en'] as $loc) {
            foreach ((array) __('enums.role', [], $loc) as $label) {
                $out[] = mb_strtolower(trim((string) $label));
            }
        }

        return $out;
    }

    /** يقص المسافات قبل الفحص */
    private function trimmed(Request $request): void
    {
        $request->merge(array_map(
            fn ($v) => is_string($v) ? trim($v) : $v,
            $request->only(['name_ar', 'name_en', 'description_ar', 'description_en'])
        ));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->trimmed($request);

        $data = $request->validate($this->nameRules() + [
            'base_role' => ['required', Rule::in(Role::BASE_ROLES())],
            'description_ar' => ['nullable', 'string', 'max:1000'],
            'description_en' => ['nullable', 'string', 'max:1000'],
            'web_login' => ['sometimes', 'boolean'],
            'app_login' => ['sometimes', 'boolean'],
        ]);

        $role = Role::create([
            'name_ar' => $data['name_ar'],
            'name_en' => $data['name_en'],
            'base_role' => $data['base_role'],
            'description_ar' => $data['description_ar'] ?? null,
            'description_en' => $data['description_en'] ?? null,
            'web_login' => $request->boolean('web_login'),
            'app_login' => $request->boolean('app_login'),
            'active' => true,
            'created_by' => $request->user()->id,
        ]);

        $this->log('roles.created', $role);

        return back()->with('ok', __('role.created', ['name' => $role->displayName()]));
    }

    public function clone(Request $request): RedirectResponse
    {
        $this->trimmed($request);

        $data = $request->validate($this->nameRules() + [
            'source' => ['required', 'string'],
            'web_login' => ['sometimes', 'boolean'],
            'app_login' => ['sometimes', 'boolean'],
        ]);

        $source = $data['source'];
        $custom = null;

        if (str_starts_with($source, 'c:')) {
            $custom = Role::fromKey($source);
            abort_if($custom === null, 422);
            $base = (string) $custom->base_role;
        } else {
            abort_unless(in_array($source, Role::BASE_ROLES(), true), 422);
            $base = $source;
        }

        $role = DB::transaction(function () use ($data, $request, $base, $custom, $source) {
            $role = Role::create([
                'name_ar' => $data['name_ar'],
                'name_en' => $data['name_en'],
                'base_role' => $base,
                'cloned_from' => $source,
                'web_login' => $request->boolean('web_login'),
                'app_login' => $request->boolean('app_login'),
                'active' => true,
                'created_by' => $request->user()->id,
            ]);

            if ($custom !== null) {
                foreach (RolePermission::where('role', $custom->key())->get(['perm', 'allow']) as $row) {
                    RolePermission::create([
                        'role' => $role->key(), 'perm' => $row->perm, 'allow' => (bool) $row->allow,
                    ]);
                }
            }

            return $role;
        });

        RolePermission::flush();
        $this->log('roles.cloned', $role, ['changes' => ['cloned_from' => $source]]);

        return back()->with('ok', __('role.cloned', ['name' => $role->displayName()]));
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $this->trimmed($request);

        // ⚠️ base_role مش في القواعد — حتى لو اتبعت مايتحفظش
        $data = $request->validate($this->nameRules($role) + [
            'description_ar' => ['nullable', 'string', 'max:1000'],
            'description_en' => ['nullable', 'string', 'max:1000'],
            'web_login' => ['sometimes', 'boolean'],
            'app_login' => ['sometimes', 'boolean'],
        ]);

        $role->update([
            'name_ar' => $data['name_ar'],
            'name_en' => $data['name_en'],
            'description_ar' => $data['description_ar'] ?? null,
            'description_en' => $data['description_en'] ?? null,
            'web_login' => $request->boolean('web_login'),
            'app_login' => $request->boolean('app_login'),
        ]);

        $this->log('roles.updated', $role);

        return back()->with('ok', __('role.updated', ['name' => $role->displayName()]));
    }

    public function toggle(Role $role): RedirectResponse
    {
        if ($role->active && ($n = $role->users()->count()) > 0) {
            return back()->withErrors(['role' => __('role.has_users', ['count' => $n])]);
        }

        $role->update(['active' => ! $role->active]);

        $this->log($role->active ? 'roles.activated' : 'roles.deactivated', $role);

        return back()->with('ok', __($role->active ? 'role.activated' : 'role.deactivated', ['name' => $role->displayName()]));
    }

    public function destroy(Role $role): RedirectResponse
    {
        if (($n = $role->users()->count()) > 0) {
            return back()->withErrors(['role' => __('role.has_users', ['count' => $n])]);
        }

        $name = $role->displayName();
        $key = $role->key();

        DB::transaction(function () use ($role, $key) {
            RolePermission::where('role', $key)->delete();
            $role->delete();
        });

        RolePermission::flush();
        $this->log('roles.deleted', $role);

        return back()->with('ok', __('role.deleted', ['name' => $name]));
    }

    /** Role مش في AUDITED — فبنسجل بإيدنا */
    private function log(string $event, Role $role, array $extra = []): void
    {
        ActivityLog::record($event, $extra + [
            'subject_type' => 'role',
            'subject_id' => $role->id,
            'title' => $role->displayName(),
        ]);
    }
}
