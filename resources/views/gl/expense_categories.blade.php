@extends('layouts.system')

{{--
    بنود المصروف (Lane 4، L4-D8) — قايمة إعدادات: كل بند بنوعه وحسابه
    الافتراضي، والأصل بعمره، والبنزين مربوط بالعربية. المحاسب يقرا،
    والإضافة والتعديل أدمن بس (`act.gl.admin`).
--}}

@section('title', __('cash.categories'))

@php
    $canEdit = \App\Support\Access::action(auth()->user(), 'act.gl.admin');
    $cols = $canEdit ? 8 : 7;
@endphp

@section('actions')
    <a class="btn sm green" href="{{ request()->fullUrlWithQuery(['export' => 1]) }}">⬇ {{ __('ui.export_all') }}</a>
    <a class="btn sm" href="{{ route('gl.expenses') }}">🧾 {{ __('gl.expenses') }}</a>
    @if ($canEdit)
        <button class="btn gold" type="button" onclick="openDlg('dlgCatNew')">＋ {{ __('cash.cat_new') }}</button>
    @endif
@endsection

@section('content')

@if ($errors->any())
    <div class="alert bad" style="margin-bottom:12px"><span>⛔</span><span>{{ $errors->first() }}</span></div>
@endif

<div class="kpis">
    @foreach ($types as $t)
        @php $n = $rows->where('type', $t)->count(); @endphp
        <a class="kpi" href="{{ request()->fullUrlWithQuery(['type' => request('type') === $t ? null : $t]) }}#cat-table" title="{{ __('ui.click_to_filter') }}">
            <div class="lbl">{{ __('cash.cat_type_'.$t) }}</div>
            <div class="val">{{ number_format($n) }}</div>
            <div class="sub2">{{ __('cash.cat_expenses') }}: {{ number_format($rows->where('type', $t)->sum('expenses_count')) }}</div>
        </a>
    @endforeach
</div>

<div class="card" id="cat-table">
    <h3>🏷️ {{ __('cash.categories') }} <span class="side">{{ __('cash.categories_sub') }}</span></h3>

    <form method="GET" class="searchbar" data-noprint>
        <label class="fl"><span>{{ __('cash.cat_type') }}</span>
            <select name="type" onchange="this.form.submit()">
                <option value="">{{ __('cash.all_types') }}</option>
                @foreach ($types as $t)
                    <option value="{{ $t }}" @selected(request('type') === $t)>{{ __('cash.cat_type_'.$t) }}</option>
                @endforeach
            </select></label>
        <a class="btn" href="{{ route('gl.expense_categories') }}">{{ __('common.clear') }}</a>
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                <th data-nosum>{{ __('cash.code') }}</th>
                <th style="text-align:start">{{ __('cash.name') }}</th>
                <th>{{ __('cash.cat_type') }}</th>
                <th style="text-align:start">{{ __('gl.account') }}</th>
                <th class="num" data-nosum>{{ __('cash.cat_life') }}</th>
                <th>{{ __('cash.cat_links_vehicle') }}</th>
                <th class="num">{{ __('cash.cat_expenses') }}</th>
                @if ($canEdit)<th></th>@endif
            </tr>
            @forelse ($rows as $c)
                <tr @if (! $c->active) style="opacity:.6" @endif>
                    <td><b dir="ltr">{{ $c->code }}</b></td>
                    <td style="text-align:start">{{ $c->displayName() }}
                        @if (! $c->active) <span class="badge b-red">{{ __('cash.inactive') }}</span> @endif
                    </td>
                    <td><span class="badge {{ $c->type === 'asset' ? 'b-purple' : ($c->type === 'factory' ? 'b-orange' : ($c->type === 'payroll' ? 'b-blue' : 'b-gray')) }}">{{ __('cash.cat_type_'.$c->type) }}</span></td>
                    <td style="text-align:start">
                        @if ($c->account)<a href="{{ route('gl.accounts.show', $c->account) }}">{{ $c->account->code }} · {{ $c->account->displayName() }}</a>@else — @endif
                    </td>
                    <td class="num">{{ $c->life_months ?: '—' }}</td>
                    <td>{{ $c->links_vehicle ? '⛽ '.__('cash.yes') : '—' }}</td>
                    <td class="num"><a href="{{ route('gl.expenses', ['category' => $c->id]) }}">{{ number_format($c->expenses_count) }}</a></td>
                    @if ($canEdit)
                        <td>
                            <button class="btn sm" type="button" onclick="catEdit({{ json_encode([
                                'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'name_en' => $c->name_en, 'type' => $c->type,
                                'account_id' => $c->account_id, 'life_months' => $c->life_months, 'links_vehicle' => $c->links_vehicle, 'active' => $c->active,
                            ]) }})">✎ {{ __('cash.edit') }}</button>
                        </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $cols }}" style="color:var(--muted);font-size:12px">{{ __('cash.cat_none') }}</td></tr>
            @endforelse
        </table>
    </div>
</div>

@if ($canEdit)
<dialog id="dlgCatNew">
    <form class="dlg" method="POST" id="formNewCat" action="{{ route('gl.expense_categories.store') }}">
        @csrf
        <h4>🏷️ {{ __('cash.cat_new') }}</h4>
        <div class="frow">
            <div>
                <label class="f">{{ __('cash.code') }} <b class="req-star">*</b></label>
                <input type="text" name="code" required maxlength="30" pattern="[A-Za-z0-9_\-]+" dir="ltr" value="{{ old('code') }}" style="width:100%">
                <div style="font-size:10.5px;color:var(--muted);margin-top:4px">{{ __('cash.code_hint') }}</div>
            </div>
            <div>
                <label class="f">{{ __('cash.cat_type') }} <b class="req-star">*</b></label>
                <select name="type" required onchange="catTypeToggle(this.form)" style="width:100%">
                    @foreach ($types as $t)
                        <option value="{{ $t }}" @selected(old('type') === $t)>{{ __('cash.cat_type_'.$t) }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        @include('gl._expense_category_fields')
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgCatNew')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>

{{-- الـaction بيتحط من `catEdit()` — متسجّل في `FormStarIntegrityTest::DYNAMIC_FORMS` --}}
<dialog id="dlgCatEdit">
    <form class="dlg" method="POST" id="formEditCat">
        @csrf
        <h4>🏷️ {{ __('cash.cat_edit') }} <span id="edCatCode" dir="ltr"></span></h4>
        <div>
            <label class="f">{{ __('cash.cat_type') }} <b class="req-star">*</b></label>
            <select name="type" required onchange="catTypeToggle(this.form)" style="width:100%">
                @foreach ($types as $t)
                    <option value="{{ $t }}">{{ __('cash.cat_type_'.$t) }}</option>
                @endforeach
            </select>
        </div>
        @include('gl._expense_category_fields')
        <label style="display:flex;gap:6px;align-items:center;margin-top:10px">
            <input type="hidden" name="active" value="0">
            <input type="checkbox" name="active" value="1"> {{ __('cash.active') }}
        </label>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgCatEdit')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>

<script>
// العمر للأصل بس — بند تاني الخانة بتختفي وتتفضّى
function catTypeToggle(f) {
    if (!f) return;
    var asset = f.querySelector('[name="type"]').value === 'asset';
    var box = f.querySelector('[data-cat-life]');
    if (!box) return;
    box.hidden = !asset;
    if (!asset) box.querySelector('input').value = '';
}

function catEdit(c) {
    var f = document.getElementById('formEditCat');
    f.action = @json(url('gl/expense-categories')) + '/' + c.id;
    document.getElementById('edCatCode').textContent = c.code;
    ['name', 'name_en', 'type', 'account_id', 'life_months'].forEach(function (k) {
        f.querySelector('[name="' + k + '"]').value = c[k] == null ? '' : c[k];
    });
    f.querySelector('input[type="checkbox"][name="links_vehicle"]').checked = !!c.links_vehicle;
    f.querySelector('input[type="checkbox"][name="active"]').checked = !!c.active;
    catTypeToggle(f);
    openDlg('dlgCatEdit');
}

catTypeToggle(document.getElementById('formNewCat'));
@if ($errors->any() && old('code'))
    openDlg('dlgCatNew');
@endif
</script>
@endif

@endsection
