@extends('layouts.system')

{{--
    شاشة الصلاحيات (2026-08-05 / تاب الرولز 2026-08-23):

    • تاب «الرولز»: الأدمن بيظبط رول كامل — «المديرين يشوفوا عروض
      الأسعار» مرة واحدة وتسري على كل مدير حالي وجاي.
    • تاب «الموظفين»: استثناء لموظف بعينه — بيغلب استثناء الرول.

    «وراثة» في الرولز = افتراضي الكود، وفي الموظفين = افتراضي الرول
    بعد استثناءاته. التلات مستويات جوه كل تاب: قسم، صفحة، زرار.
--}}

@php
    /** سيلكت التلات حالات — وراثة / إظهار / إخفاء */
    // `$label` = اسم القسم/الصفحة/الزرار — بيتحط aria-label عشان السيلكت مايبقاش من غير اسم (٢٢/٩)
    $sel = function (string $name, ?bool $override, bool $default, string $label = '') {
        $inheritLabel = __('perm.inherit').' — '.($default ? __('perm.state_shown') : __('perm.state_hidden'));
        $o = fn ($v, $t, $on) => '<option value="'.$v.'"'.($on ? ' selected' : '').'>'.e($t).'</option>';

        return '<select name="perm['.e($name).']" aria-label="'.e(__('perm.setting').' — '.$label).'" style="width:100%;min-width:190px">'
            .$o('', $inheritLabel, $override === null)
            .$o('1', '👁️ '.__('perm.show'), $override === true)
            .$o('0', '🚫 '.__('perm.hide'), $override === false)
            .'</select>';
    };

    /** الحالة الفعلية بعد الاستثناء */
    $badge = function (?bool $override, bool $default) {
        $on = $override ?? $default;

        return '<span class="badge '.($on ? 'b-green' : 'b-gray').'">'
            .($on ? __('perm.state_shown') : __('perm.state_hidden')).'</span>';
    };
@endphp

@section('title', __('perm.permissions'))

@section('content')

<div class="card">
    <h3>🔐 {{ __('perm.permissions') }}
        <span class="side">{{ __('perm.permissions_hint') }}</span></h3>

    @if (session('ok'))
        <div class="alert good" style="margin-bottom:12px"><span>✅</span><span>{{ session('ok') }}</span></div>
    @endif

    {{-- ═══ الاختيار: رول كامل أو موظف بعينه ═══ --}}
    <div style="display:flex;flex-wrap:wrap;gap:18px;align-items:flex-end">
        <form method="GET">
            <label class="f" for="permRole">👥 {{ __('perm.pick_role') }}</label>
            <select name="role" id="permRole" onchange="this.form.submit()"
                    style="min-width:240px{{ $role !== null ? ';border-color:var(--blue,#12399B);font-weight:800' : '' }}">
                <option value="">{{ __('ui.choose', ['x' => __('uid.l_role')]) }}</option>
                @foreach ($roles as $r)
                    <option value="{{ $r }}" @selected($role === $r)>{{ __('enums.role.'.$r) }}</option>
                @endforeach
            </select>
            <div class="side" style="font-size:10.5px;margin-top:4px">{{ __('perm.role_hint') }}</div>
        </form>

        <form method="GET">
            <label class="f" for="permUser">🧍 {{ __('perm.pick_user') }}</label>
            <select name="user" id="permUser" onchange="this.form.submit()"
                    style="min-width:260px{{ $role === null ? ';border-color:var(--blue,#12399B);font-weight:800' : '' }}">
                @if ($role !== null)
                    <option value="">{{ __('ui.choose', ['x' => __('ui.l_user')]) }}</option>
                @endif
                @foreach ($users as $u2)
                    <option value="{{ $u2->id }}" @selected($role === null && $user?->id === $u2->id)>
                        {{ $u2->name }} — {{ $u2->roleLabel() }} @if($u2->code) ({{ $u2->code }}) @endif
                    </option>
                @endforeach
            </select>
            <div class="side" style="font-size:10.5px;margin-top:4px">{{ __('perm.user_hint') }}</div>
        </form>
    </div>

    @if ($role === null && $user === null)
        <div class="alert" style="margin-top:12px"><span>ℹ️</span><span>{{ __('perm.no_users') }}</span></div>
    @endif
</div>

@if ($role !== null || $user !== null)
<form method="POST"
      action="{{ $role !== null ? route('erp.perms.role.save', $role) : route('erp.perms.save', $user) }}">
    @csrf

    @if ($role !== null)
        <div class="alert" style="margin-bottom:12px">
            <span>👥</span>
            <span><b>{{ __('enums.role.'.$role) }}</b> — {{ __('perm.editing_role') }}</span>
        </div>
    @endif

    {{-- شريط ثابت (٢٢/٩): الشاشة 17 قسم ومئات السطور — من غيره لازم تنزل لآخر الصفحة عشان تحفظ
         ومفيش طريقة تلاقي بيها شاشة باسمها. بحث على كل الأقسام + المتعدّل بس + طي/فتح + حفظ. --}}
    <div class="card permbar" data-noprint>
        <label class="fl grow"><span>{{ __('uid.perm_find') }}</span>
            <input type="search" id="permQ" placeholder="🔍 {{ __('uid.perm_find_ph') }}" oninput="permFilter()"></label>
        <label style="display:flex;gap:6px;align-items:center;font-size:12px;font-weight:700;padding-bottom:9px">
            <input type="checkbox" id="permOnly" onchange="permFilter()"> {{ __('uid.perm_only_changed') }}
            <span class="badge b-orange" id="permN">0</span></label>
        <button class="btn sm" type="button" onclick="permFold(true)">▸ {{ __('uid.perm_fold_all') }}</button>
        <button class="btn sm" type="button" onclick="permFold(false)">▾ {{ __('uid.perm_open_all') }}</button>
        <button class="btn gold" type="submit">💾 {{ __('perm.save') }}</button>
    </div>
    <div class="alert good" id="permNone" style="display:none;margin-bottom:12px"><span>🔍</span><span>{{ __('uid.no_match') }}</span></div>

    @foreach ($tree as $group => $g)
        <div class="card permgrp">
            <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between">
                <h3 style="margin:0;cursor:pointer" onclick="this.closest('.permgrp').classList.toggle('folded')" title="{{ __('uid.perm_fold_tip') }}"><span class="permcaret">▾</span> {{ __($group) }} {!! $badge($g['override'], $g['default']) !!}
                    <span class="side">{{ __('perm.group_hint') }}</span></h3>
                {{-- سيلكت القسم كله — عنوانه فوقه باسم القسم (٢٢/٩) --}}
                <label class="fl wide"><span>{{ __('uid.perm_group_setting', ['x' => __($group)]) }}</span>
                    {!! $sel($group, $g['override'], $g['default'], __($group)) !!}</label>
            </div>

            <div class="tablewrap permbody" style="margin-top:10px">
                {{-- جدول إعدادات مش داتا — من غير إكسيل ولا أدوات الجدول: البحث بقى واحد فوق لكل الأقسام (٢٢/٩) --}}
                <table data-noxl data-plain>
                    <thead>
                        <tr>
                            <th>{{ __('perm.screen_or_action') }}</th>
                            <th style="width:110px">{{ __('perm.effective') }}</th>
                            <th style="width:230px">{{ __('perm.setting') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($g['pages'] as $p)
                            <tr class="permrow">
                                <td><b>{{ $p['icon'] }} {{ __($p['label']) }}</b></td>
                                <td>{!! $badge($p['override'], $p['default']) !!}</td>
                                <td>{!! $sel($p['route'], $p['override'], $p['default'], __($p['label'])) !!}</td>
                            </tr>
                            @foreach ($p['actions'] as $a)
                                {{-- data-p = اسم الصفحة الأم، عشان البحث باسم الشاشة يجيب زرايرها معاها --}}
                                <tr class="permrow" data-p="{{ __($p['label']) }}">
                                    <td style="padding-inline-start:34px;color:var(--muted)">
                                        🔘 {{ __($a['label']) }}</td>
                                    <td>{!! $badge($a['override'], $a['default']) !!}</td>
                                    <td>{!! $sel($a['key'], $a['override'], $a['default'], __($p['label']).' · '.__($a['label'])) !!}</td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach

    <div class="card" style="display:flex;gap:10px;align-items:center;justify-content:flex-end">
        <div style="font-size:12px;color:var(--muted)">
            {{ $role !== null ? __('perm.save_role_hint') : __('perm.save_hint') }}</div>
        <button class="btn gold" type="submit">💾 {{ __('perm.save') }}</button>
    </div>
</form>
@endif

@endsection

@section('scripts')
<style>
.permbar { position: sticky; top: 0; z-index: 30; display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; padding: 10px 14px; }
.permgrp.folded .permbody { display: none; }
.permgrp.folded .permcaret { display: inline-block; transform: rotate(90deg); }
.permrow.changed td { background: rgba(255, 193, 7, .13); }
</style>
<script>
/** بحث على كل الأقسام + «المتعدّل بس» — القسم اللي مفيهوش نتيجة بيختفي كله */
function permFilter() {
    const q = (document.getElementById('permQ').value || '').trim().toLowerCase();
    const only = document.getElementById('permOnly').checked;
    let shown = 0;
    document.querySelectorAll('.permgrp').forEach(g => {
        const gHit = q && g.querySelector('h3').textContent.toLowerCase().includes(q);
        let n = 0;
        g.querySelectorAll('.permrow').forEach(r => {
            const txt = (r.cells[0].textContent + ' ' + (r.dataset.p || '')).toLowerCase();
            const ok = (!q || gHit || txt.includes(q)) && (!only || r.classList.contains('changed'));
            r.style.display = ok ? '' : 'none';
            if (ok) n++;
        });
        g.style.display = n ? '' : 'none';
        if (n && (q || only)) g.classList.remove('folded');
        shown += n;
    });
    document.getElementById('permNone').style.display = shown ? 'none' : '';
}
function permFold(on) { document.querySelectorAll('.permgrp').forEach(g => g.classList.toggle('folded', on)); }
/** السطر اللي عليه استثناء (مش «وراثة») بيتلوّن — وعدّاد فوق */
function permMark() {
    let n = 0;
    document.querySelectorAll('.permrow').forEach(r => {
        const s = r.querySelector('select'); const on = !!(s && s.value !== '');
        r.classList.toggle('changed', on); if (on) n++;
    });
    const b = document.getElementById('permN'); if (b) b.textContent = n;
}
document.addEventListener('change', e => { if (e.target.closest('.permrow')) permMark(); });
permMark();
</script>
@endsection
