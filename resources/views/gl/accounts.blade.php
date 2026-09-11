@extends('layouts.system')

{{--
    شجرة الحسابات (١٢/٩/٢٠٢٦) — الشجرة كلها في صفحة واحدة برصيد كل
    عقدة (شامل أحفادها) على النافذة المختارة. الورقة لينك لكشف حسابها.

    ⚠️ حساب النظام اسمه بس اللي بيتعدّل — الكود بيظهر في كل تقرير
    مطبوع وقواعد الترحيل بتشاور عليه بمفتاحه مش بكوده.
--}}

@section('title', __('gl.accounts'))

@php
    $canPost = \App\Support\Access::action(auth()->user(), 'act.gl.post');
@endphp

@section('actions')
    @if ($canPost)
        <button class="btn gold" type="button" onclick="glNewAccount()">＋ {{ __('gl.new_account') }}</button>
    @endif
@endsection

@section('content')

<div class="card">
    <h3>🌳 {{ __('gl.accounts') }} <span class="side">{{ __('gl.tree_sub') }}</span></h3>

    <form method="GET" class="frow" style="margin-bottom:12px" data-noprint>
        <div>
            <label class="f">{{ __('common.from') }}</label>
            <input type="date" name="from" value="{{ $range->fromValue() }}" onchange="this.form.submit()">
        </div>
        <div>
            <label class="f">{{ __('common.to') }}</label>
            <input type="date" name="to" value="{{ $range->toValue() }}" onchange="this.form.submit()">
        </div>
        <div style="align-self:flex-end;font-size:11px;color:var(--muted)">
            {{ $range->isOpen() ? __('gl.balance_all_time') : $range->fromValue().' → '.$range->toValue() }}
        </div>
    </form>

    <div class="tablewrap">
        @forelse ($roots as $root)
            @include('gl._tree_node', ['node' => $root, 'children' => $children, 'balances' => $balances, 'canPost' => $canPost])
        @empty
            <div style="color:var(--muted);font-size:12px">{{ __('gl.no_tree') }}</div>
        @endforelse
    </div>
</div>

{{-- ═══════════ حساب جديد / تعديل حساب — الديالوج جوه content ═══════════ --}}
@if ($canPost)
<dialog id="dlgAccount">
    <form class="dlg" method="POST" id="accForm" action="{{ route('gl.accounts.store') }}">
        @csrf
        <h4 id="accTitle">＋ {{ __('gl.new_account') }}</h4>

        <div class="alert info" id="accSysHint" hidden style="margin-bottom:12px">
            <span>ℹ️</span><span>{{ __('gl.system_account_hint') }}</span>
        </div>

        <div id="accTreeBox">
            <label class="f">{{ __('gl.parent') }} <b class="req-star">*</b></label>
            <select name="parent_id" id="accParent" required style="width:100%">
                <option value="">— {{ __('common.pick') }} —</option>
                @foreach ($parents as $p)
                    <option value="{{ $p->id }}" @selected(old('parent_id') == $p->id)>{{ $p->code }} · {{ $p->displayName() }}</option>
                @endforeach
            </select>
        </div>

        <div class="frow" style="margin-top:10px">
            <div id="accCodeBox">
                <label class="f">{{ __('gl.code') }} <b class="req-star">*</b></label>
                <input type="text" name="code" id="accCode" required maxlength="20" dir="ltr"
                       value="{{ old('code') }}" style="width:100%">
                <div style="font-size:10.5px;color:var(--muted);margin-top:4px">{{ __('gl.code_hint') }}</div>
            </div>
            <div>
                <label class="f">{{ __('gl.name') }} (AR) <b class="req-star">*</b></label>
                <input type="text" name="name" id="accName" required maxlength="120"
                       value="{{ old('name') }}" style="width:100%">
            </div>
        </div>

        <div style="margin-top:10px">
            <label class="f">{{ __('gl.name') }} (EN)</label>
            <input type="text" name="name_en" id="accNameEn" maxlength="120" dir="ltr"
                   value="{{ old('name_en') }}" style="width:100%">
        </div>

        <div style="margin-top:10px" id="accActiveWrap" hidden>
            <label class="f"><input type="checkbox" name="active" id="accActive" value="1"> {{ __('gl.active') }}</label>
        </div>

        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgAccount')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>
@endif

<script>
const ACC_STORE = @js(route('gl.accounts.store'));
const ACC_UPDATE = @js($canPost ? route('gl.accounts.update', ['account' => '__ID__']) : '');
const ACC_NEW = @js('＋ '.__('gl.new_account'));
const ACC_EDIT = @js(__('gl.edit_account'));

// حساب جديد — الأب والكود مفتوحين، والإيقاف مالوش معنى قبل ما يتولد
function glNewAccount() {
    const form = document.getElementById('accForm');
    if (!form) return;
    form.action = ACC_STORE;
    document.getElementById('accTitle').textContent = ACC_NEW;
    document.getElementById('accSysHint').hidden = true;
    document.getElementById('accActiveWrap').hidden = true;
    document.getElementById('accParent').disabled = false;
    document.getElementById('accCode').disabled = false;
    document.getElementById('accParent').value = '';
    document.getElementById('accCode').value = '';
    document.getElementById('accName').value = '';
    document.getElementById('accNameEn').value = '';
    openDlg('dlgAccount');
}

// ⚠️ حساب النظام: الأب والكود **بيتقفلوا** (`disabled` مش بيتبعت
// أصلاً) — السيرفر بيتجاهلهم برضه، بس الشاشة لازم تقول ليه قبل ما
// المحاسب يكتب كود جديد ويلاقيه ما اتغيّرش من غير رسالة
function glEditAccount(a) {
    const form = document.getElementById('accForm');
    if (!form) return;
    form.action = ACC_UPDATE.replace('__ID__', a.id);
    document.getElementById('accTitle').textContent = ACC_EDIT + ' — ' + a.code;
    document.getElementById('accSysHint').hidden = !a.is_system;
    document.getElementById('accActiveWrap').hidden = !!a.is_system;
    document.getElementById('accParent').disabled = !!a.is_system;
    document.getElementById('accCode').disabled = !!a.is_system;
    document.getElementById('accParent').value = a.parent_id || '';
    document.getElementById('accCode').value = a.code || '';
    document.getElementById('accName').value = a.name || '';
    document.getElementById('accNameEn').value = a.name_en || '';
    document.getElementById('accActive').checked = !!a.active;
    openDlg('dlgAccount');
    document.getElementById('accName').focus();
}

@if ($canPost)
{{-- الفاليديشن رفضت الحساب الجديد؟ افتح الديالوج تاني باللي اتكتب --}}
@if ($errors->any() && old('code') !== null)
document.addEventListener('DOMContentLoaded', function () {
    openDlg('dlgAccount');
});
@endif
@endif
</script>

@endsection
