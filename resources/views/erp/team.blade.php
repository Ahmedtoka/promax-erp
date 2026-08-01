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
@endphp

@section('content')

<div class="card">
    <h3>🧑‍💼 {{ __('team.users_and_roles') }}</h3>
    <div class="tablewrap">
        <table>
            <tr><th>{{ __('common.name') }}</th><th>{{ __('common.code') }}</th><th>{{ __('team.role') }}</th><th>{{ __('team.email') }}</th><th>{{ __('team.zone') }}</th><th>{{ __('branch.branch') }}</th><th>{{ __('branch.plate') }}</th><th>{{ __('common.status') }}</th><th>{{ __('team.app_token') }}</th></tr>
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

@endsection
