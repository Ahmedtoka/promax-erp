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
        {{-- ⚠️ الفورم بيخدم الإضافة والتعديل، والـaction بيتكتب من
             الجافاسكربت — فبعد فاليديشن راجعة لازم نعرف كنا بنعدّل مين
             (وهل هو حساب نظام) عشان الديالوج مايرجعش «حساب جديد» --}}
        <input type="hidden" name="_edit_id" id="accEditId" value="{{ old('_edit_id') }}">
        <input type="hidden" name="_is_system" id="accIsSystem" value="{{ old('_is_system') }}">
        <h4 id="accTitle">＋ {{ __('gl.new_account') }}</h4>

        <div class="alert info" id="accSysHint" hidden style="margin-bottom:12px">
            <span>ℹ️</span><span>{{ __('gl.system_account_hint') }}</span>
        </div>

        <div id="accTreeBox">
            <label class="f">{{ __('gl.parent') }} <b class="req-star">*</b></label>
            <select name="parent_id" id="accParent" required style="width:100%">
                <option value="">— {{ __('common.pick') }} —</option>
                @foreach ($parents as $p)
                    {{-- data-root = رقم الجذر، والجافاسكربت بيخفي غير بتاع
                         الحساب اللي بيتعدّل (نقل بين الجذور مرفوض في السيرفر) --}}
                    <option value="{{ $p->id }}" data-root="{{ $p->code[0] }}" @selected(old('parent_id') == $p->id)>{{ $p->code }} · {{ $p->displayName() }}</option>
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

// ⚠️ **فورم واحد للإضافة والتعديل.** حساب النظام: الأب والكود
// **بيتقفلوا** (`disabled` مش بيتبعت أصلاً) — السيرفر بيتجاهلهم برضه،
// بس الشاشة لازم تقول ليه قبل ما المحاسب يكتب كود جديد ويلاقيه ما
// اتغيّرش من غير رسالة.
// ⚠️ الأب في التعديل بيتفلتر على **نفس الجذر** — نقل حساب مصروف تحت
// الأصول بيرفضه السيرفر، فماينفعش نعرضه كخيار من الأصل.
function glOpenAccount(a) {
    const form = document.getElementById('accForm');
    if (!form) return;

    const data = a || {};
    const editing = !!data.id;
    const sys = !!data.is_system;
    const root = (data.code || '').charAt(0);

    form.action = editing ? ACC_UPDATE.replace('__ID__', data.id) : ACC_STORE;
    document.getElementById('accEditId').value = editing ? String(data.id) : '';
    document.getElementById('accIsSystem').value = sys ? '1' : '';
    document.getElementById('accTitle').textContent = editing ? ACC_EDIT + ' — ' + (data.code || '') : ACC_NEW;
    document.getElementById('accSysHint').hidden = !sys;
    document.getElementById('accActiveWrap').hidden = !editing || sys;
    document.getElementById('accParent').disabled = sys;
    document.getElementById('accCode').disabled = sys;

    glFilterParents(editing ? root : '');

    document.getElementById('accParent').value = data.parent_id || '';
    document.getElementById('accCode').value = data.code || '';
    document.getElementById('accName').value = data.name || '';
    document.getElementById('accNameEn').value = data.name_en || '';
    document.getElementById('accActive').checked = editing ? !!data.active : true;

    openDlg('dlgAccount');
    document.getElementById('accName').focus();
}

// قايمة الآباء: في التعديل جذر واحد بس، وفي الإضافة كلهم
function glFilterParents(root) {
    const sel = document.getElementById('accParent');
    if (!sel) return;
    Array.prototype.forEach.call(sel.options, function (opt) {
        if (!opt.value) return;
        opt.hidden = !!root && opt.dataset.root !== root;
        opt.disabled = opt.hidden;
    });
}

function glNewAccount() {
    glOpenAccount(null);
}

function glEditAccount(a) {
    glOpenAccount(a);
}

@if ($canPost && $errors->any() && old('name') !== null)
{{-- الفاليديشن رفضت؟ افتح الديالوج تاني على **نفس** الحاجة اللي كانت
     مفتوحة (تعديل ولا إضافة) باللي المحاسب كتبه --}}
document.addEventListener('DOMContentLoaded', function () {
    glOpenAccount(@js([
        'id' => old('_edit_id') ? (int) old('_edit_id') : null,
        'code' => old('code'),
        'name' => old('name'),
        'name_en' => old('name_en'),
        'parent_id' => old('parent_id'),
        'is_system' => (bool) old('_is_system'),
        'active' => (bool) old('active'),
    ]));
});
@endif
</script>

@endsection
