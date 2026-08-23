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
    $sel = function (string $name, ?bool $override, bool $default) {
        $inheritLabel = __('perm.inherit').' — '.($default ? __('perm.state_shown') : __('perm.state_hidden'));
        $o = fn ($v, $t, $on) => '<option value="'.$v.'"'.($on ? ' selected' : '').'>'.e($t).'</option>';

        return '<select name="perm['.e($name).']" style="width:100%;max-width:210px">'
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
            <label class="f">👥 {{ __('perm.pick_role') }}</label>
            <select name="role" onchange="this.form.submit()"
                    style="min-width:240px{{ $role !== null ? ';border-color:var(--blue,#12399B);font-weight:800' : '' }}">
                <option value="">—</option>
                @foreach ($roles as $r)
                    <option value="{{ $r }}" @selected($role === $r)>{{ __('enums.role.'.$r) }}</option>
                @endforeach
            </select>
            <div class="side" style="font-size:10.5px;margin-top:4px">{{ __('perm.role_hint') }}</div>
        </form>

        <form method="GET">
            <label class="f">🧍 {{ __('perm.pick_user') }}</label>
            <select name="user" onchange="this.form.submit()"
                    style="min-width:260px{{ $role === null ? ';border-color:var(--blue,#12399B);font-weight:800' : '' }}">
                @if ($role !== null)
                    <option value="">—</option>
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

    @foreach ($tree as $group => $g)
        <div class="card">
            <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between">
                <h3 style="margin:0">{{ __($group) }} {!! $badge($g['override'], $g['default']) !!}
                    <span class="side">{{ __('perm.group_hint') }}</span></h3>
                {!! $sel($group, $g['override'], $g['default']) !!}
            </div>

            <div class="tablewrap" style="margin-top:10px">
                <table>
                    <thead>
                        <tr>
                            <th>{{ __('perm.screen_or_action') }}</th>
                            <th style="width:110px">{{ __('perm.effective') }}</th>
                            <th style="width:230px">{{ __('perm.setting') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($g['pages'] as $p)
                            <tr>
                                <td><b>{{ $p['icon'] }} {{ __($p['label']) }}</b></td>
                                <td>{!! $badge($p['override'], $p['default']) !!}</td>
                                <td>{!! $sel($p['route'], $p['override'], $p['default']) !!}</td>
                            </tr>
                            @foreach ($p['actions'] as $a)
                                <tr>
                                    <td style="padding-inline-start:34px;color:var(--muted)">
                                        🔘 {{ __($a['label']) }}</td>
                                    <td>{!! $badge($a['override'], $a['default']) !!}</td>
                                    <td>{!! $sel($a['key'], $a['override'], $a['default']) !!}</td>
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
