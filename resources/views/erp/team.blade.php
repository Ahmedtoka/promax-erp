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
                        <td class="num">
                            <button class="btn sm" type="button"
                                    onclick="openPass({{ $u->id }}, @js($u->displayName()), {{ $u->isFieldUser() ? 'true' : 'false' }})">
                                🔑 {{ __('team.set_password') }}
                            </button>
                        </td>
                    @endif
                </tr>
            @endforeach
        </table>
    </div>
</div>

<div class="card">
    <h3>📍 {{ __('team.zones') }}</h3>
    <div class="tablewrap">
        <table>
            <tr><th>{{ __('common.code') }}</th><th>{{ __('team.zone') }}</th><th>{{ __('team.visit_day') }}</th><th>{{ __('team.client_count') }}</th><th>{{ __('ops.rep') }}</th></tr>
            @foreach ($zones as $z)
                <tr>
                    <td class="num">{{ $z->code }}</td>
                    <td><b>{{ $z->displayName() }}</b></td>
                    <td>{{ $z->day_label ?? '—' }}</td>
                    <td class="num">{{ $z->clients()->count() }}</td>
                    <td>{{ $z->users->pluck('name')->join(__('common.list_separator')) ?: '—' }}</td>
                </tr>
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
@endif
</script>
@endif
@endsection
