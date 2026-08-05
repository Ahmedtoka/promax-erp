@extends('layouts.system')

@section('title', __('team.zones_and_govs'))

@php
    use App\Support\Governorates;

    $fmt = fn ($n) => number_format((float) $n);
    $byGov = $zones->groupBy(fn ($z) => $z->governorate ?: '_none');
    // المحافظات اللي ليها مناطق الأول (بالترتيب الجغرافي)، والفاضية مش بتتعرض
    $sep = __('common.list_separator');
@endphp

@section('actions')
    <a class="btn" href="{{ route('ops.assignments') }}">👥 {{ __('journey.assignments') }}</a>
    <button class="btn gold" onclick="openZone(null)">+ {{ __('team.new_zone') }}</button>
@endsection

@section('content')

<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ __('geo.governorate') }}</div>
        {{-- ⚠️ مش `except()` — على كولكشن Eloquent بتفلتر بمفاتيح
             الموديلز وبتنادي getKey() على المجموعات = 500 --}}
        <div class="val">{{ $byGov->keys()->reject(fn ($k) => $k === '_none')->count() }}</div>
        <div class="sub2">{{ __('team.of_27_governorates') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('team.zones') }}</div>
        <div class="val">{{ $zones->count() }}</div>
        <div class="sub2">{{ $zones->where('active', true)->count() }} {{ __('common.active') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('client.clients') }}</div>
        <div class="val">{{ $fmt($zones->sum('active_clients')) }}</div>
        <div class="sub2">{{ __('team.in_zones_hint') }}</div>
    </div>
    @if (($none = $byGov->get('_none')) && $none->isNotEmpty())
        <div class="kpi">
            <div class="lbl">{{ __('geo.no_governorate') }}</div>
            <div class="val neg">{{ $none->count() }}</div>
            <div class="sub2">{{ __('team.no_gov_hint') }}</div>
        </div>
    @endif
</div>

@if ($errors->any())
    <div class="card"><div class="alert" style="flex-direction:column;align-items:stretch;gap:4px">
        @foreach ($errors->all() as $msg)
            <div class="errline" style="margin:0">{{ $msg }}</div>
        @endforeach
    </div></div>
@endif

{{-- ═══════════ المحافظات نفسها: تعديل الأسماء + إضافة (2026-08-05) ═══════════ --}}
@php $canGov = \App\Support\Access::action(auth()->user(), 'act.org.manage'); @endphp
<div class="card">
    <h3>🗺️ {{ __('geo.govs') }}
        <span class="side">{{ __('geo.govs_hint') }}</span>
        @if ($canGov)
            <button class="btn gold sm" style="margin-inline-start:auto" onclick="govDlg()">+ {{ __('geo.new_gov') }}</button>
        @endif
    </h3>
    <div style="display:flex;flex-wrap:wrap;gap:6px">
        @foreach ($govRows as $g)
            @php $gPayload = json_encode(['id' => $g->id, 'name' => $g->name, 'name_en' => $g->name_en],
                JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); @endphp
            <span class="badge b-gray" style="font-size:11.5px;display:inline-flex;gap:6px;align-items:center">
                {{ $g->name }} <span dir="ltr" style="color:var(--muted)">{{ $g->name_en }}</span>
                @if ($canGov)
                    <a href="#" onclick='govDlg({!! $gPayload !!}); return false' title="{{ __('geo.edit_gov') }}">✎</a>
                @endif
            </span>
        @endforeach
    </div>
</div>

@if ($canGov)
<dialog id="dlgGov">
    <form class="dlg" method="POST" id="govForm" action="{{ route('erp.govs.store') }}">
        @csrf
        <input type="hidden" name="_method" id="govMethod" value="POST">
        <h4 id="govTitle">{{ __('geo.new_gov') }}</h4>
        <div class="alert info" style="margin-bottom:12px">
            <span>ℹ️</span><span>{{ __('geo.gov_form_hint') }}</span>
        </div>
        <div class="frow">
            <div>
                <label class="f">{{ __('geo.governorate') }} (AR) <b class="req-star">*</b></label>
                <input type="text" name="name" id="govName" required maxlength="120" style="width:100%">
            </div>
            <div>
                <label class="f">{{ __('geo.governorate') }} (EN) <b class="req-star">*</b></label>
                <input type="text" name="name_en" id="govNameEn" required maxlength="120" dir="ltr" style="width:100%">
            </div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgGov')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>
@endif

{{-- ═══════════ محافظة ← مناطقها ═══════════ --}}
@foreach (array_merge(Governorates::keys(), ['_none']) as $gk)
    @continue(! ($group = $byGov->get($gk)) || $group->isEmpty())
    <div class="card">
        <h3>📍 {{ $gk === '_none' ? __('geo.no_governorate') : Governorates::label($gk) }}
            <span class="side">{{ $group->count() }} {{ __('journey.zone_countable') }}
                · {{ $fmt($group->sum('active_clients')) }} {{ __('client.clients') }}</span>
        </h3>
        <div class="tablewrap">
            <table>
                <tr>
                    <th>{{ __('common.code') }}</th>
                    <th>{{ __('team.zone') }} (AR)</th>
                    <th>{{ __('team.zone') }} (EN)</th>
                    <th>{{ __('team.visit_day') }}</th>
                    <th class="num">{{ __('team.client_count') }}</th>
                    <th>{{ __('ops.rep') }}</th>
                    <th>{{ __('common.status') }}</th>
                    <th></th>
                </tr>
                @foreach ($group->sortBy(fn ($z) => $z->displayName()) as $z)
                    <tr>
                        <td class="num">{{ $z->code }}</td>
                        <td><b>{{ $z->name }}</b></td>
                        <td dir="ltr"><b>{{ $z->name_en ?: '—' }}</b></td>
                        <td class="s">{{ $z->day_label ?: '—' }}</td>
                        <td class="num">{{ $fmt($z->active_clients) }}</td>
                        <td class="s">{{ $z->users->map(fn ($u) => $u->displayName())->join($sep) ?: '—' }}</td>
                        <td>
                            @if ($z->active)
                                <span class="badge b-green">{{ __('common.active') }}</span>
                            @else
                                <span class="badge b-gray">{{ __('team.inactive') }}</span>
                            @endif
                        </td>
                        <td class="num">
                            @php
                                // ⚠️ مش @json متعدد الأسطر — الفخ المعروف
                                $zPayload = json_encode([
                                    'id' => $z->id, 'name' => $z->name, 'name_en' => $z->name_en,
                                    'governorate' => $z->governorate, 'day_label' => $z->day_label,
                                    'active' => (bool) $z->active,
                                ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
                            @endphp
                            <button class="btn sm" type="button" onclick='openZone({!! $zPayload !!})'>✎ {{ __('common.edit') }}</button>
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>
    </div>
@endforeach

{{-- ═══════════ إضافة / تعديل منطقة ═══════════ --}}
<dialog id="dlgZone">
    <form class="dlg" method="POST" id="zoneForm" action="{{ route('erp.zones.store') }}">
        @csrf
        <input type="hidden" name="_method" id="zMethod" value="POST">
        <input type="hidden" name="_editing" id="zEditing" value="">
        <h4 id="zTitle">+ {{ __('team.new_zone') }}</h4>

        {{-- ⚠️ **الخانتين موجودتين دايماً** — عربي وإنجليزي جنب بعض،
             زي كل شاشات الداتا انتري. من أي واجهة (عربي أو إنجليزي)
             بتعدّل اللغتين مع بعض. --}}
        <div class="frow">
            <div>
                <label class="f">{{ __('client.name_en_field') }}</label>
                <input type="text" name="name_en" id="zNameEn" dir="ltr" maxlength="190" style="width:100%">
            </div>
            <div>
                <label class="f">{{ __('client.name_ar_field') }} *</label>
                <input type="text" name="name" id="zName" required maxlength="190" style="width:100%">
            </div>
        </div>
        <div class="frow" style="margin-top:10px">
            <div>
                <label class="f">{{ __('geo.governorate') }}</label>
                {{-- كل الـ27 محافظة — بالترتيب الجغرافي --}}
                <select name="governorate" id="zGov" style="width:100%">
                    <option value="">{{ __('geo.pick_governorate') }}</option>
                    @foreach (Governorates::options() as $gk => $gLabel)
                        <option value="{{ $gk }}">{{ $gLabel }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="f">{{ __('team.visit_day') }}</label>
                <input type="text" name="day_label" id="zDay" maxlength="60" style="width:100%"
                       placeholder="{{ __('team.visit_day_ph') }}">
            </div>
        </div>

        <label style="display:flex;gap:8px;align-items:center;font-size:12.5px;font-weight:800;margin-top:12px;cursor:pointer"
               id="zActiveWrap" hidden>
            <input type="hidden" name="active" value="0">
            <input type="checkbox" name="active" value="1" id="zActive" checked>
            {{ __('common.active') }}
        </label>

        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px">
            <button class="btn" type="button" onclick="closeDlg('dlgZone')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
</dialog>

@endsection

@section('scripts')
<script>
// ═══ دايالوج المحافظات — إضافة/تعديل الأسماء (المفتاح ثابت) ═══
const G_STORE = @js(route('erp.govs.store'));
const G_UPDATE = @js(route('erp.govs.update', ['governorate' => '__ID__']));
const G_NEW = @js(__('geo.new_gov'));
const G_EDIT = @js(__('geo.edit_gov'));

function govDlg(g) {
    const form = document.getElementById('govForm');
    if (!form) return;

    document.getElementById('govTitle').textContent = g ? G_EDIT : G_NEW;
    document.getElementById('govMethod').value = g ? 'PUT' : 'POST';
    form.action = g ? G_UPDATE.replace('__ID__', g.id) : G_STORE;
    document.getElementById('govName').value = g ? g.name : '';
    document.getElementById('govNameEn').value = g ? (g.name_en || '') : '';
    openDlg('dlgGov');
}

const Z_STORE = @js(route('erp.zones.store'));
const Z_UPDATE = @js(route('erp.zones.update', ['zone' => '__ID__']));
const Z_NEW = @js('+ '.__('team.new_zone'));
const Z_EDIT = @js(__('team.edit_zone'));

function openZone(z) {
    const editing = z !== null;
    const form = document.getElementById('zoneForm');

    form.action = editing ? Z_UPDATE.replace('__ID__', z.id) : Z_STORE;
    document.getElementById('zMethod').value = editing ? 'PUT' : 'POST';
    document.getElementById('zEditing').value = editing ? String(z.id) : '';
    document.getElementById('zTitle').textContent = editing ? Z_EDIT + ' — ' + z.name : Z_NEW;

    document.getElementById('zName').value = editing ? (z.name || '') : '';
    document.getElementById('zNameEn').value = editing ? (z.name_en || '') : '';
    document.getElementById('zGov').value = editing ? (z.governorate || '') : '';
    document.getElementById('zDay').value = editing ? (z.day_label || '') : '';
    document.getElementById('zActive').checked = editing ? !!z.active : true;
    // الإيقاف للتعديل بس — منطقة جديدة موقوفة مالهاش معنى
    document.getElementById('zActiveWrap').hidden = !editing;

    openDlg('dlgZone');
    document.getElementById('zName').focus();
}

{{-- الفاليديشن رفضت؟ افتح الديالوج تاني باللي المستخدم كتبه --}}
@if ($errors->any() && old('name') !== null)
document.addEventListener('DOMContentLoaded', function () {
    openZone(@js(old('_editing') ? [
        'id' => (int) old('_editing'), 'name' => old('name'), 'name_en' => old('name_en'),
        'governorate' => old('governorate'), 'day_label' => old('day_label'),
        'active' => (bool) old('active', true),
    ] : null));
    @if (! old('_editing'))
    ['zName', 'zNameEn', 'zDay'].forEach(function (id, i) {
        document.getElementById(id).value = [@js(old('name', '')), @js(old('name_en', '')), @js(old('day_label', ''))][i];
    });
    document.getElementById('zGov').value = @js(old('governorate', ''));
    @endif
});
@endif
</script>
@endsection
