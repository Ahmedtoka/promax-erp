{{-- ═══ تاب الرولز: كل الرولز (أساسية + مخصصة) بعدّاداتها، ورول جديد/نسخ/تعديل/إيقاف/مسح ═══ --}}
@php
    $customs = array_filter($roles, fn ($r) => $r['custom']);
    $baseOpts = function (?string $selected) use ($baseRoles) {
        $html = '';

        foreach ($baseRoles as $b) {
            $html .= '<option value="'.e($b).'"'.($selected === $b ? ' selected' : '').'>'.e(__('enums.role.'.$b)).'</option>';
        }

        return $html;
    };
    $form = old('_form');
@endphp

<div class="card">
    <h3>👥 {{ __('perm.roles_title') }}
        <span class="side">{{ __('perm.roles_hint') }}</span></h3>

    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:12px">
        <button class="btn gold" type="button" onclick="pmNewRole('new')">＋ {{ __('perm.new_role') }}</button>
        <button class="btn" type="button" onclick="pmNewRole('clone')">⧉ {{ __('perm.clone_role') }}</button>
        <span class="pm-muted" style="flex:1 1 320px">{{ __('perm.roles_model_hint') }}</span>
    </div>

    <div class="tablewrap">
        <table>
            <thead><tr>
                <th>{{ __('perm.c_role') }}</th>
                <th>{{ __('perm.base') }}</th>
                <th>{{ __('perm.c_access') }}</th>
                <th>{{ __('perm.c_users') }}</th>
                <th>{{ __('perm.c_diffs') }}</th>
                <th>{{ __('common.status') }}</th>
                <th class="act" data-nosum>{{ __('perm.c_actions') }}</th>
            </tr></thead>
            <tbody>
            @foreach ($roles as $k => $r)
                @php $busy = $r['users'] > 0; $why = __('role.has_users', ['count' => $r['users']]); @endphp
                <tr data-role-row="{{ $k }}" data-users="{{ $r['users'] }}" data-diffs="{{ $r['diffs'] }}" @if(! $r['active']) style="opacity:.62" @endif>
                    <td>
                        <b>{{ $r['label'] }}</b>
                        <span class="badge {{ $r['custom'] ? 'b-blue' : 'b-gray' }}" style="margin-inline-start:4px">{{ $r['custom'] ? __('perm.custom') : __('perm.builtin') }}</span>
                        @if ($r['custom'] && $r['model']->cloned_from)
                            <div class="pm-muted">⧉ {{ __('perm.cloned_from', ['role' => $roleName($r['model']->cloned_from)]) }}</div>
                        @endif
                    </td>
                    <td>{{ __('enums.role.'.$r['base']) }}</td>
                    <td style="white-space:nowrap">
                        <span class="badge {{ $r['web'] ? 'b-green' : 'b-gray' }}" title="{{ __('perm.web_hint') }}">💻 {{ $r['web'] ? __('perm.web_on') : __('perm.web_off') }}</span>
                        <span class="badge {{ $r['app'] ? 'b-green' : 'b-gray' }}" title="{{ $r['custom'] ? __('perm.app_hint') : __('perm.app_builtin_hint') }}">📱 {{ $r['app'] ? __('perm.app_on') : ($r['custom'] ? __('perm.app_off') : __('perm.app_file')) }}</span>
                    </td>
                    <td class="num">{{ $r['active_users'] }}
                        @if ($r['users'] !== $r['active_users'])<span class="pm-muted" title="{{ __('perm.users_total_hint') }}">/ {{ $r['users'] }}</span>@endif
                    </td>
                    <td class="num">
                        @if ($r['diffs'] > 0)
                            <a href="{{ $tabUrl('matrix', ['role' => $k, 'only' => 1]) }}" title="{{ $r['custom'] ? __('perm.diffs_custom_hint') : __('perm.diffs_builtin_hint') }}"><b>{{ $r['diffs'] }}</b></a>
                        @else
                            <span class="pm-muted">0</span>
                        @endif
                    </td>
                    <td><span class="badge {{ $r['active'] ? 'b-green' : 'b-orange' }}">{{ $r['active'] ? __('common.active') : __('common.inactive') }}</span></td>
                    <td class="act">
                        <div class="pm-acts">
                            <a class="btn sm" href="{{ $tabUrl('matrix', ['role' => $k]) }}">🧩 {{ __('perm.open_matrix') }}</a>
                            <a class="btn sm" href="{{ $tabUrl('sheet', ['role' => $k]) }}" title="{{ __('perm.tab_sheet') }}">📄 {{ __('perm.sheet_short') }}</a>
                            <button class="btn sm" type="button" onclick="pmNewRole('clone', @js($k))">⧉ {{ __('perm.clone') }}</button>
                            @if ($r['custom'])
                                <button class="btn sm" type="button" onclick="openDlg('dlgEditRole{{ $r['model']->id }}')">✏️ {{ __('common.edit') }}</button>
                                @if ($r['active'] && $busy)
                                    <button class="btn sm" type="button" disabled title="{{ $why }}" aria-label="{{ __('perm.deactivate') }} — {{ $why }}">⏸ {{ __('perm.deactivate') }}</button>
                                @else
                                    <form method="POST" action="{{ route('erp.roles.toggle', $r['model']) }}" style="display:inline">
                                        @csrf
                                        <button class="btn sm" type="submit">{{ $r['active'] ? '⏸ '.__('perm.deactivate') : '▶ '.__('perm.activate') }}</button>
                                    </form>
                                @endif
                                @if ($busy)
                                    <button class="btn sm red" type="button" disabled title="{{ $why }}" aria-label="{{ __('common.delete') }} — {{ $why }}">🗑 {{ __('common.delete') }}</button>
                                @else
                                    <form method="POST" action="{{ route('erp.roles.destroy', $r['model']) }}" style="display:inline" data-pm-delete="{{ $k }}"
                                          onsubmit="return confirm(@js(__('perm.delete_confirm', ['role' => $r['label']])))">
                                        @csrf @method('DELETE')
                                        <button class="btn sm red" type="submit">🗑 {{ __('common.delete') }}</button>
                                    </form>
                                @endif
                            @endif
                        </div>
                        @if ($r['custom'] && $busy)
                            <div class="pm-muted" style="font-size:10.5px;margin-top:4px">🔒 {{ $why }}</div>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

{{-- ═══ رول جديد: من رول أساسي، أو نسخة من رول موجود (فورمين — كل واحد على راوته) ═══ --}}
<dialog id="dlgNewRole" class="wide">
    <div class="dlg">
        <h4>＋ {{ __('perm.new_role') }}</h4>

        <div class="pm-seg" role="radiogroup" aria-label="{{ __('perm.start_from') }}" style="margin-bottom:14px">
            <label><input type="radio" name="pmStart" value="new" onchange="pmStart('new')" checked><span class="v-">{{ __('perm.start_base') }}</span></label>
            <label><input type="radio" name="pmStart" value="clone" onchange="pmStart('clone')"><span class="v-">{{ __('perm.start_clone') }}</span></label>
        </div>

        <form method="POST" action="{{ route('erp.roles.store') }}" id="pmFormNew" data-pm-start="new">
            @csrf
            <input type="hidden" name="_form" value="new">
            <div class="frow">
                <label class="fl"><span>{{ __('perm.name_ar') }}</span>
                    <input type="text" name="name_ar" maxlength="60" required value="{{ $form === 'new' ? old('name_ar') : '' }}"></label>
                <label class="fl"><span>{{ __('perm.name_en') }}</span>
                    <input type="text" name="name_en" maxlength="60" required dir="ltr" value="{{ $form === 'new' ? old('name_en') : '' }}"></label>
            </div>
            <div class="frow">
                <label class="fl"><span>{{ __('perm.base') }}</span>
                    <select name="base_role" required data-pm-base>
                        <option value="">{{ __('ui.choose', ['x' => __('perm.base')]) }}</option>
                        {!! $baseOpts($form === 'new' ? old('base_role') : null) !!}
                    </select></label>
            </div>
            <div class="pm-muted" style="margin:-4px 0 10px">{{ __('perm.base_hint') }}</div>
            @include('erp.perms._login_flags', ['web' => $form === 'new' ? (bool) old('web_login') : true, 'app' => $form === 'new' ? (bool) old('app_login') : false])
            <div class="alert warn pm-scope" hidden role="note"><span>⚠️</span><span>{{ __('perm.scope_branch_warning') }}</span></div>
            <div class="formbar">
                <span class="formbar-sp"></span>
                <button class="btn" type="button" onclick="closeDlg('dlgNewRole')">{{ __('common.cancel') }}</button>
                <button class="btn gold" type="submit">{{ __('perm.create_role') }}</button>
            </div>
        </form>

        <form method="POST" action="{{ route('erp.roles.clone') }}" id="pmFormClone" data-pm-start="clone" hidden>
            @csrf
            <input type="hidden" name="_form" value="clone">
            <div class="frow">
                <label class="fl"><span>{{ __('perm.clone_source') }}</span>
                    <select name="source" required data-pm-source>
                        <option value="">{{ __('ui.choose', ['x' => __('perm.clone_source')]) }}</option>
                        <optgroup label="{{ __('perm.builtin_roles') }}">
                            @foreach ($roles as $k => $r)
                                @continue($r['custom'])
                                <option value="{{ $k }}" data-base="{{ $r['base'] }}" @selected($form === 'clone' && old('source') === $k)>{{ $r['label'] }}</option>
                            @endforeach
                        </optgroup>
                        @if ($customs !== [])
                            <optgroup label="{{ __('perm.custom_roles') }}">
                                @foreach ($customs as $k => $r)
                                    <option value="{{ $k }}" data-base="{{ $r['base'] }}" @selected($form === 'clone' && old('source') === $k)>{{ $r['label'] }} — {{ __('enums.role.'.$r['base']) }}</option>
                                @endforeach
                            </optgroup>
                        @endif
                    </select></label>
            </div>
            <div class="pm-muted" style="margin:-4px 0 10px" id="pmCloneBase">{{ __('perm.clone_hint') }}</div>
            <div class="frow">
                <label class="fl"><span>{{ __('perm.name_ar') }}</span>
                    <input type="text" name="name_ar" maxlength="60" required value="{{ $form === 'clone' ? old('name_ar') : '' }}"></label>
                <label class="fl"><span>{{ __('perm.name_en') }}</span>
                    <input type="text" name="name_en" maxlength="60" required dir="ltr" value="{{ $form === 'clone' ? old('name_en') : '' }}"></label>
            </div>
            @include('erp.perms._login_flags', ['web' => $form === 'clone' ? (bool) old('web_login') : true, 'app' => $form === 'clone' ? (bool) old('app_login') : false])
            <div class="alert warn pm-scope" hidden role="note"><span>⚠️</span><span>{{ __('perm.scope_branch_warning') }}</span></div>
            <div class="formbar">
                <span class="formbar-sp"></span>
                <button class="btn" type="button" onclick="closeDlg('dlgNewRole')">{{ __('common.cancel') }}</button>
                <button class="btn gold" type="submit">{{ __('perm.create_copy') }}</button>
            </div>
        </form>
    </div>
</dialog>

{{-- ═══ تعديل رول مخصص — الاسم والوصف والدخول بس (الأساس مايتغيرش بعد الإنشاء) ═══ --}}
@foreach ($customs as $k => $r)
    @php $m = $r['model']; $mine = $form === 'edit-'.$m->id; @endphp
    <dialog id="dlgEditRole{{ $m->id }}">
        <form class="dlg" method="POST" action="{{ route('erp.roles.update', $m) }}" data-pm-fixed-base="{{ $r['base'] }}">
            @csrf @method('PUT')
            <input type="hidden" name="_form" value="edit-{{ $m->id }}">
            <h4>✏️ {{ $r['label'] }}</h4>
            <div class="pm-muted" style="margin-bottom:12px">{{ __('perm.base') }}: <b>{{ __('enums.role.'.$r['base']) }}</b> — {{ __('perm.base_fixed') }}</div>
            <div class="frow">
                <label class="fl"><span>{{ __('perm.name_ar') }}</span>
                    <input type="text" name="name_ar" maxlength="60" required value="{{ $mine ? old('name_ar') : $m->name_ar }}"></label>
                <label class="fl"><span>{{ __('perm.name_en') }}</span>
                    <input type="text" name="name_en" maxlength="60" required dir="ltr" value="{{ $mine ? old('name_en') : $m->name_en }}"></label>
            </div>
            <div class="frow">
                <label class="fl"><span>{{ __('perm.desc_ar') }}</span>
                    <textarea name="description_ar" maxlength="1000" rows="2">{{ $mine ? old('description_ar') : $m->description_ar }}</textarea></label>
                <label class="fl"><span>{{ __('perm.desc_en') }}</span>
                    <textarea name="description_en" maxlength="1000" rows="2" dir="ltr">{{ $mine ? old('description_en') : $m->description_en }}</textarea></label>
            </div>
            @include('erp.perms._login_flags', ['web' => $mine ? (bool) old('web_login') : (bool) $m->web_login, 'app' => $mine ? (bool) old('app_login') : (bool) $m->app_login])
            <div class="alert warn pm-scope" hidden role="note"><span>⚠️</span><span>{{ __('perm.scope_branch_warning') }}</span></div>
            <div class="formbar">
                <span class="formbar-sp"></span>
                <button class="btn" type="button" onclick="closeDlg('dlgEditRole{{ $m->id }}')">{{ __('common.cancel') }}</button>
                <button class="btn gold" type="submit">{{ __('common.save') }}</button>
            </div>
        </form>
    </dialog>
@endforeach

<style>
.pm-acts{display:flex;flex-wrap:wrap;gap:5px;min-width:270px}
.pm-acts form{margin:0}
</style>
<script>
(function () {
    // الأساس اللي شاشاته على الويب بتوري الفرع كله — التحذير يظهر لو الويب مفتوح
    var SCOPE = @json($branchScopeBases);

    function baseOf(form) {
        if (form.dataset.pmFixedBase) return form.dataset.pmFixedBase;
        var b = form.querySelector('[data-pm-base]');
        if (b) return b.value;
        var s = form.querySelector('[data-pm-source]');
        var o = s && s.options[s.selectedIndex];
        return o ? (o.dataset.base || '') : '';
    }

    function sync(form) {
        var web = form.querySelector('input[name="web_login"][type="checkbox"]');
        var warn = form.querySelector('.pm-scope');
        if (warn) warn.hidden = !(web && web.checked && SCOPE.indexOf(baseOf(form)) !== -1);
        var cb = form.querySelector('#pmCloneBase');
        if (cb) {
            var b = baseOf(form);
            cb.textContent = b ? @json(__('perm.clone_base_is')) + ' ' + (@json(__('enums.role')))[b] : @json(__('perm.clone_hint'));
        }
    }

    document.querySelectorAll('#dlgNewRole form, dialog[id^="dlgEditRole"] form').forEach(function (f) {
        f.addEventListener('change', function () { sync(f); });
        sync(f);
    });

    window.pmStart = function (mode) {
        document.querySelectorAll('#dlgNewRole [data-pm-start]').forEach(function (f) {
            f.hidden = f.dataset.pmStart !== mode;
        });
        var r = document.querySelector('#dlgNewRole input[name="pmStart"][value="' + mode + '"]');
        if (r) r.checked = true;
    };

    window.pmNewRole = function (mode, source) {
        pmStart(mode);
        if (source) {
            var s = document.querySelector('#pmFormClone [data-pm-source]');
            s.value = source;
            s.dispatchEvent(new Event('change', { bubbles: true }));
        }
        openDlg('dlgNewRole');
    };

    // رجوع بأخطاء ⇒ نفس المودال يتفتح تاني بالقيم
    var back = @json($form);
    if (back === 'new' || back === 'clone') { window.addEventListener('load', function () { pmNewRole(back); }); }
    else if (back && back.indexOf('edit-') === 0) {
        window.addEventListener('load', function () { openDlg('dlgEditRole' + back.slice(5)); });
    }
})();
</script>
