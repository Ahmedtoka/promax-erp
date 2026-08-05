@extends('layouts.system')

@section('title', __('team.team'))

@php
    // ⚠️ خريطة العربيات بتتبني مرة واحدة — لوب بيسأل عن عربية كل
    // موظف بيعمل كويري لكل صف في الجدول.
    $vanOf = [];
    foreach ($vehicles as $v) {
        if ($v->rep_id) { $vanOf[$v->rep_id] = $v; }
        if ($v->driver_id) { $vanOf[$v->driver_id] = $v; }
    }

    // ⚠️ زرار الباسورد للأدمن بس — الراوت نفسه `role:admin`، ومدير
    // القنوات لو شافه هياخد 403 (وأصلاً مينفعش يقدر يغيّر باسورد
    // الأدمن ويستلم السيستم).
    $canSetPassword = auth()->user()->isAdmin();
@endphp

@section('actions')
    <a class="btn" href="{{ route('ops.assignments') }}">👥 {{ __('journey.assignments') }}</a>
    <a class="btn" href="{{ route('ops.journeys') }}">🗺️ {{ __('journey.page') }}</a>
    @if ($canSetPassword)
        <button class="btn gold" onclick="openUser(null)">+ {{ __('team.new_user') }}</button>
    @endif
@endsection

@section('content')

<div class="card">
    <h3>🧑‍💼 {{ __('team.users_and_roles') }}</h3>
    <div class="tablewrap">
        <table>
            <tr><th>{{ __('common.name') }}</th><th>{{ __('common.code') }}</th><th>{{ __('team.role') }}</th><th>{{ __('team.email') }}</th><th>{{ __('team.zone') }}</th><th>{{ __('branch.branch') }}</th><th>{{ __('branch.plate') }}</th><th>{{ __('common.status') }}</th><th>{{ __('team.app_token') }}</th>@if ($canSetPassword)<th></th>@endif</tr>
            @foreach ($users as $u)
                <tr>
                    <td><b>{{ $u->displayName() }}</b></td>
                    <td class="num">{{ $u->code ?? '—' }}</td>
                    <td><span class="badge {{ match($u->role) { 'admin' => 'b-red', 'manager' => 'b-purple', 'branch_manager' => 'b-gold', 'driver' => 'b-blue', 'promoter' => 'b-orange', default => 'b-green' } }}">{{ $u->roleLabel() }}</span></td>
                    <td style="color:var(--muted)">{{ $u->email }}</td>
                    <td>{{ $u->zone?->displayName() ?? '—' }}</td>
                    <td class="s">{{ $u->branch?->displayName() ?? __('branch.central') }}</td>
                    <td class="num s">
                        @php $van = $vanOf[$u->id] ?? null; @endphp
                        @if ($van)
                            {{ $van->plate }}
                            @if ($van->is_fridge)
                                <br><span class="badge b-blue">{{ __('branch.fridge') }}</span>
                            @endif
                        @else — @endif
                    </td>
                    <td>
                        @if ($u->active)
                            <span class="badge b-green">{{ __('team.active') }}</span>
                        @else
                            <span class="badge b-gray">{{ __('team.inactive') }}</span>
                        @endif
                    </td>
                    <td>
                        @if ($u->isFieldUser())
                            <span class="badge b-gold">{{ __('team.token_countable', ['count' => $u->tokens()->count()]) }}</span>
                        @else — @endif
                    </td>
                    @if ($canSetPassword)
                        @php
                            // ⚠️ **مش `@json([...])` متعدد الأسطر** — ده الفخ
                            // المسجّل في سكيل المشروع: بيكسر بارسر Blade
                            // بـ«Unclosed [». والـHEX flags عشان الأسماء اللي
                            // فيها ' أو " ماتكسرش خاصية onclick.
                            $uPayload = json_encode([
                                'id' => $u->id, 'name' => $u->name, 'name_en' => $u->name_en,
                                'email' => $u->email, 'code' => $u->code, 'phone' => $u->phone,
                                'role' => $u->role, 'branch_id' => $u->branch_id,
                                'zone_id' => $u->zone_id, 'warehouse_id' => $u->warehouse_id,
                                'active' => (bool) $u->active,
                                'self' => $u->id === auth()->id(),
                            ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
                        @endphp
                        <td class="num" style="white-space:nowrap">
                            <button class="btn sm" type="button"
                                    onclick='openUser({!! $uPayload !!})'>✎ {{ __('common.edit') }}</button>
                            <button class="btn sm" type="button"
                                    onclick="openPass({{ $u->id }}, @js($u->displayName()), {{ $u->isFieldUser() ? 'true' : 'false' }})">
                                🔑
                            </button>
                        </td>
                    @endif
                </tr>
            @endforeach
        </table>
    </div>
</div>

<div class="card">
    <h3>📍 {{ __('team.zones') }}
        <span class="side">
            <button class="btn sm" onclick="openDlg('dlgZone')">+ {{ __('team.new_zone') }}</button>
        </span>
    </h3>
    <div class="tablewrap">
        <table>
            <tr><th>{{ __('common.code') }}</th><th>{{ __('team.zone') }}</th><th>{{ __('team.visit_day') }}</th><th>{{ __('team.client_count') }}</th><th>{{ __('ops.rep') }}</th></tr>
            {{-- ⚠️ مجمّعة بالمحافظة — رأس لكل محافظة ومناطقها تحته
                 بالترتيب الجغرافي، والـ«بدون محافظة» في الآخر. --}}
            @php $zByGov = $zones->groupBy(fn ($z) => $z->governorate ?: '_none'); @endphp
            @foreach (array_merge(\App\Support\Governorates::keys(), ['_none']) as $gk)
                @continue(! ($zGroup = $zByGov->get($gk)) || $zGroup->isEmpty())
                <tr>
                    <td colspan="5" style="background:var(--card2);font-weight:900;color:var(--royal-blue);font-size:12px">
                        📍 {{ $gk === '_none' ? __('geo.no_governorate') : \App\Support\Governorates::label($gk) }}
                        <span style="color:var(--muted);font-weight:400">· {{ $zGroup->count() }}</span>
                    </td>
                </tr>
                @foreach ($zGroup->sortBy(fn ($z) => $z->displayName()) as $z)
                    <tr>
                        <td class="num">{{ $z->code }}</td>
                        <td><b>{{ $z->displayName() }}</b></td>
                        <td>{{ $z->day_label ?? '—' }}</td>
                        <td class="num">{{ $z->clients()->count() }}</td>
                        <td>{{ $z->users->pluck('name')->join(__('common.list_separator')) ?: '—' }}</td>
                    </tr>
                @endforeach
            @endforeach
        </table>
    </div>
</div>

{{-- ⚠️ **بلوك «بيانات الدخول» اتشال خالص.**
     كان بيطبع الباسورد `promax123` بالنص، فوق جدول فيه إيميل وكود
     ورول كل واحد في الشركة. يعني أي مدير فرع — أقل رول بيوصل
     للصفحة دي — كان معاه بيانات دخول الأدمن كاملة، وسكرين شوت
     واحدة للصفحة بتسلّم الشركة كلها.

     ⚠️ **وممنوع يرجع.** السيستم مايعرفش الباسوردات أصلاً (متخزنة
     مشفّرة)، فأي رقم بيتكتب هنا هو رقم متبتّت في الكود — وده تعريف
     الباب الخلفي. --}}
<div class="card">
    <h3>📱 {{ __('team.app_access') }}</h3>
    <div class="alert good"><div>{{ __('team.app_login_note') }}</div></div>
    <div class="alert info" style="margin-top:8px">
        <div>{{ __('team.password_reset_note') }}</div>
    </div>
</div>

@if ($canSetPassword)
{{-- ═══════════ يوزر جديد / تعديل يوزر ═══════════ --}}
<dialog id="dlgUser">
    <form class="dlg" method="POST" id="userForm" action="{{ route('erp.team.store') }}"
          style="max-height:88vh;overflow-y:auto;min-width:min(560px,92vw)">
        @csrf
        <input type="hidden" name="_method" id="userMethod" value="POST">
        <input type="hidden" name="_editing" id="userEditing" value="">
        <h4 id="userTitle">{{ __('team.new_user') }}</h4>

        @if ($errors->any())
            <div class="alert" style="margin-bottom:10px;flex-direction:column;align-items:stretch;gap:4px">
                @foreach ($errors->all() as $msg)
                    <div class="errline" style="margin:0">{{ $msg }}</div>
                @endforeach
            </div>
        @endif

        <div class="frow">
            <div>
                <label class="f">{{ __('client.name_en_field') }}</label>
                <input type="text" name="name_en" id="uNameEn" dir="ltr" maxlength="190" style="width:100%">
            </div>
            <div>
                <label class="f">{{ __('client.name_ar_field') }} *</label>
                <input type="text" name="name" id="uName" required maxlength="190" style="width:100%">
            </div>
        </div>
        <div class="frow">
            <div>
                <label class="f">{{ __('team.email') }} *</label>
                <input type="email" name="email" id="uEmail" required dir="ltr" maxlength="190" style="width:100%">
            </div>
            <div>
                <label class="f">{{ __('common.code') }}</label>
                <input type="text" name="code" id="uCode" dir="ltr" maxlength="30" style="width:100%"
                       placeholder="SLS-003">
            </div>
        </div>
        <div class="frow">
            <div>
                <label class="f">{{ __('team.role') }} *</label>
                <select name="role" id="uRole" required style="width:100%" onchange="userRoleSync()">
                    @foreach (array_keys(\App\Models\User::ROLES) as $rk)
                        <option value="{{ $rk }}">{{ __('enums.role.'.$rk) }}</option>
                    @endforeach
                </select>
                <div style="font-size:11px;color:var(--muted);margin-top:4px" id="uSelfNote" hidden>
                    {{ __('team.cannot_change_own_role') }}
                </div>
            </div>
            <div>
                <label class="f">{{ __('common.phone') }}</label>
                <input type="text" name="phone" id="uPhone" dir="ltr" maxlength="30" style="width:100%">
            </div>
        </div>
        <div class="frow">
            <div>
                <label class="f">{{ __('branch.branch') }}</label>
                <select name="branch_id" id="uBranch" style="width:100%">
                    <option value="">{{ __('branch.central') }}</option>
                    @foreach ($branches as $b)
                        <option value="{{ $b->id }}">{{ $b->displayName() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="f">{{ __('team.zone') }}</label>
                @include('partials._zone_select', [
                    'zones' => $zones,
                    'name' => 'zone_id',
                    'style' => 'width:100%',
                    'attrs' => 'id="uZone"',
                ])
            </div>
        </div>
        <div class="frow">
            <div id="uWarehouseBox">
                <label class="f">{{ __('stock.warehouse') }} <span id="uWhStar" hidden>*</span></label>
                <select name="warehouse_id" id="uWarehouse" style="width:100%">
                    <option value="">—</option>
                    @foreach ($warehouses as $w)
                        <option value="{{ $w->id }}">{{ $w->displayName() }}</option>
                    @endforeach
                </select>
                <div style="font-size:11px;color:var(--muted);margin-top:4px">{{ __('team.warehouse_hint') }}</div>
            </div>
            <div id="uPassBox">
                <label class="f">{{ __('team.new_password') }} *</label>
                <input type="password" name="password" id="uPass" minlength="8" dir="ltr"
                       autocomplete="new-password" style="width:100%">
                <div style="font-size:11px;color:var(--muted);margin-top:4px">{{ __('team.password_min_hint') }}</div>
            </div>
        </div>

        <label style="display:flex;gap:8px;align-items:center;font-size:12.5px;font-weight:800;margin-top:12px;cursor:pointer">
            <input type="hidden" name="active" value="0">
            <input type="checkbox" name="active" value="1" id="uActive" checked>
            {{ __('team.active') }}
        </label>

        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgUser')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>

{{-- ═══════════ منطقة جديدة ═══════════ --}}
@endif

<dialog id="dlgZone">
    <form class="dlg" method="POST" action="{{ route('erp.zones.store') }}">
        @csrf
        <h4>📍 {{ __('team.new_zone') }}</h4>
        <div class="frow">
            <div>
                <label class="f">{{ __('client.name_en_field') }}</label>
                <input type="text" name="name_en" dir="ltr" maxlength="190" style="width:100%">
            </div>
            <div>
                <label class="f">{{ __('client.name_ar_field') }} *</label>
                <input type="text" name="name" required maxlength="190" style="width:100%">
            </div>
        </div>
        <div class="frow">
            <div>
                <label class="f">{{ __('geo.governorate') }}</label>
                <select name="governorate" style="width:100%">
                    <option value="">—</option>
                    @foreach (\App\Support\Governorates::options() as $gk => $gLabel)
                        <option value="{{ $gk }}">{{ $gLabel }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="f">{{ __('team.visit_day') }}</label>
                <input type="text" name="day_label" maxlength="60" style="width:100%"
                       placeholder="{{ __('team.visit_day_ph') }}">
            </div>
        </div>
        {{-- الكود بيتولّد لوحده — زي المناطق اللي بتتعمل من فورم العميل --}}
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgZone')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>

@if ($canSetPassword)
<dialog id="dlgPass">
    <form class="dlg" method="POST" id="passForm" action="">
        @csrf
        {{-- ⚠️ بيرجعوا مع رفض الفاليديشن — من غيرهم الديالوج بيتفتح
             تاني بفورم من غير هدف: الاسم فاضي والحفظ بيروح لصفحة
             الفريق نفسها بـ405. --}}
        <input type="hidden" name="_user" id="passUser" value="">
        <input type="hidden" name="_user_name" id="passUserName" value="">
        <input type="hidden" name="_is_field" id="passIsField" value="">
        <h4>🔑 {{ __('team.set_password_for') }} <span id="passName"></span></h4>

        @if ($errors->any())
            <div class="alert" style="margin-bottom:10px;flex-direction:column;align-items:stretch;gap:4px">
                @foreach ($errors->all() as $msg)
                    <div class="errline" style="margin:0">{{ $msg }}</div>
                @endforeach
            </div>
        @endif

        <div>
            <label class="f">{{ __('team.new_password') }}</label>
            <div style="display:flex;gap:6px">
                <input type="password" name="password" id="passField" required minlength="8"
                       autocomplete="new-password" style="flex:1" dir="ltr">
                <button class="btn sm" type="button" onclick="togglePass()">👁</button>
            </div>
            <div style="font-size:11px;color:var(--muted);margin-top:4px">{{ __('team.password_min_hint') }}</div>
        </div>

        <div style="margin-top:10px">
            <label class="f">{{ __('team.confirm_password') }}</label>
            <input type="password" name="password_confirmation" id="passConfirm" required minlength="8"
                   autocomplete="new-password" style="width:100%" dir="ltr">
        </div>

        {{-- ⚠️ اللي معاه أبلكيشن بيتطرد منه — التوكينات بتتلغي مع
             التغيير، فلازم اللي بيغيّر يعرف إن المندوب هيسجّل دخول
             تاني. --}}
        <div class="alert warn" id="passAppNote" style="margin-top:10px;display:none">
            <span>📱</span><span>{{ __('team.password_logs_out_app') }}</span>
        </div>

        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
            <button class="btn" type="button" onclick="closeDlg('dlgPass')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>
@endif

@endsection

@section('scripts')
@if ($canSetPassword)
<script>
const PASS_URL = @js(route('erp.team.password', ['user' => '__ID__']));
const USER_URL = @js(route('erp.team.update', ['user' => '__ID__']));
const STORE_URL = @js(route('erp.team.store'));
const T_NEW = @js(__('team.new_user'));
const T_EDIT = @js(__('team.edit_user'));

/**
 * فتح ديالوج اليوزر — `null` = جديد، أوبجكت = تعديل.
 *
 * ⚠️ الباسورد بيظهر في الجديد بس — تعديله ليه زرار 🔑 لوحده،
 * وخانة باسورد فاضية في فورم تعديل كانت هتتبعت وتغيّره بالغلط.
 */
function openUser(u) {
    const editing = u !== null;
    const form = document.getElementById('userForm');

    form.action = editing ? USER_URL.replace('__ID__', u.id) : STORE_URL;
    document.getElementById('userMethod').value = editing ? 'PUT' : 'POST';
    document.getElementById('userEditing').value = editing ? String(u.id) : '';
    document.getElementById('userTitle').textContent = editing ? T_EDIT + ' — ' + (u.name_en || u.name) : T_NEW;

    document.getElementById('uName').value = editing ? (u.name || '') : '';
    document.getElementById('uNameEn').value = editing ? (u.name_en || '') : '';
    document.getElementById('uEmail').value = editing ? (u.email || '') : '';
    document.getElementById('uCode').value = editing ? (u.code || '') : '';
    document.getElementById('uPhone').value = editing ? (u.phone || '') : '';
    document.getElementById('uRole').value = editing ? u.role : 'sales_agent';
    document.getElementById('uBranch').value = editing && u.branch_id ? String(u.branch_id) : '';
    document.getElementById('uZone').value = editing && u.zone_id ? String(u.zone_id) : '';
    document.getElementById('uWarehouse').value = editing && u.warehouse_id ? String(u.warehouse_id) : '';
    document.getElementById('uActive').checked = editing ? !!u.active : true;

    // الباسورد للجديد بس
    const passBox = document.getElementById('uPassBox');
    passBox.hidden = editing;
    document.getElementById('uPass').required = !editing;
    document.getElementById('uPass').disabled = editing;
    document.getElementById('uPass').value = '';

    // ⚠️ الأدمن مايغيّرش رول نفسه ولا يوقف نفسه — السيرفر بيتجاهلهم
    // برضه، بس القفل هنا بيمنع «حفظت ومتغيّرش» المحيّرة.
    const self = editing && u.self;
    document.getElementById('uRole').disabled = self;
    document.getElementById('uActive').disabled = self;
    document.getElementById('uSelfNote').hidden = !self;

    userRoleSync();
    openDlg('dlgUser');
}

// أمين المخزن لازم له مخزن — النجمة بتظهر مع الرول
function userRoleSync() {
    const isKeeper = document.getElementById('uRole').value === 'warehouse_keeper';
    document.getElementById('uWhStar').hidden = !isKeeper;
    document.getElementById('uWarehouse').required = isKeeper;
}

function openPass(id, name, isField) {
    document.getElementById('passForm').action = PASS_URL.replace('__ID__', id);
    document.getElementById('passName').textContent = name;
    document.getElementById('passAppNote').style.display = isField ? '' : 'none';
    document.getElementById('passUser').value = id;
    document.getElementById('passUserName').value = name;
    document.getElementById('passIsField').value = isField ? '1' : '';
    document.getElementById('passField').value = '';
    document.getElementById('passConfirm').value = '';
    openDlg('dlgPass');
    document.getElementById('passField').focus();
}

function togglePass() {
    ['passField', 'passConfirm'].forEach(function (id) {
        const el = document.getElementById(id);
        el.type = el.type === 'password' ? 'text' : 'password';
    });
}

{{-- الديالوج بيرجع مفتوح لو الفاليديشن رفضت — من غير كده الرسالة
     الحمرا جوه ديالوج مقفول ومحدش شايفها --}}
@if ($errors->any() && old('_user'))
document.addEventListener('DOMContentLoaded', function () {
    openPass(@js((int) old('_user')), @js(old('_user_name', '')), @js((bool) old('_is_field')));
});
@elseif ($errors->any() && old('email') !== null)
{{-- فورم اليوزر اترفض — بنفتحه تاني باللي المستخدم كتبه.
     ⚠️ `openUser` بتتنادى بالأوبجكت دايماً عشان القيم ترجع؛ لو كان
     «جديد» بنرجّع الفورم لوضع الإضافة بعدها (أكشن POST وخانة
     الباسورد ظاهرة). --}}
document.addEventListener('DOMContentLoaded', function () {
    const wasEditing = @js((bool) old('_editing'));

    openUser({
        id: @js((int) old('_editing')),
        name: @js(old('name', '')),
        name_en: @js(old('name_en', '')),
        email: @js(old('email', '')),
        code: @js(old('code', '')),
        phone: @js(old('phone', '')),
        role: @js(old('role', 'sales_agent')),
        branch_id: @js(old('branch_id')),
        zone_id: @js(old('zone_id')),
        warehouse_id: @js(old('warehouse_id')),
        active: @js((bool) old('active', true)),
        self: false,
    });

    if (! wasEditing) {
        const form = document.getElementById('userForm');
        form.action = STORE_URL;
        document.getElementById('userMethod').value = 'POST';
        document.getElementById('userEditing').value = '';
        document.getElementById('userTitle').textContent = T_NEW;
        document.getElementById('uPassBox').hidden = false;
        document.getElementById('uPass').required = true;
        document.getElementById('uPass').disabled = false;
    }
});
@endif
</script>
@endif
@endsection
