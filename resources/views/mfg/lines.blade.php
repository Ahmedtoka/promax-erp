@extends('layouts.system')

@section('title', __('mfg.lines_title'))

@section('content')
@php
    $user = auth()->user();
    $canManage = \App\Support\Access::action($user, 'act.mfg.lines_manage');
    $canSettings = \App\Support\Access::action($user, 'act.mfg.lines_settings');
@endphp

@if ($canManage)
{{-- خط جديد = مركز تكلفة نوعه `line` تحت المصنع FAC (بيتعمل لو مش موجود) --}}
<div class="card" data-noprint>
    <h3>➕ {{ __('mfg.new_line') }} <span class="side">{{ __('mfg.new_line_hint') }}</span></h3>
    <form method="POST" action="{{ route('mfg.lines.store') }}" class="searchbar">
        @csrf
        <label class="fl"><span>{{ __('mfg.col_code') }} *</span>
            <input type="text" name="code" maxlength="20" required value="{{ old('code') }}"></label>
        <label class="fl"><span>{{ __('mfg.col_name') }} *</span>
            <input type="text" name="name" maxlength="120" required value="{{ old('name') }}"></label>
        <label class="fl"><span>{{ __('mfg.col_name_en') }}</span>
            <input type="text" name="name_en" maxlength="120" value="{{ old('name_en') }}"></label>
        <button class="btn" type="submit">{{ __('mfg.new_line') }}</button>
    </form>
</div>
@endif

<div class="card">
    <h3>🛤️ {{ __('mfg.lines_title') }}
        <span class="side">{{ __('ui.rows_n', ['n' => $lines->count()]) }}</span>
    </h3>

    {{-- فلتر «من — إلى» على `finished_at` بتاع الأوامر — ساعات الخط في الفترة (الديفولت الشهر ده) --}}
    <form class="searchbar" method="GET" data-noprint>
        @include('partials._range', ['from' => $range->fromValue(), 'to' => $range->toValue()])
        <button class="btn">{{ __('common.search') }}</button>
        <a class="btn" href="{{ route('mfg.lines') }}">{{ __('common.clear') }}</a>
        <a class="btn sm green" href="{{ request()->fullUrlWithQuery(['export' => 1]) }}">⬇ {{ __('ui.export_all') }}</a>
    </form>

    <div class="tablewrap">
        <table>
            <tr>
                <th data-nosum>{{ __('mfg.col_code') }}</th>
                <th>{{ __('mfg.col_name') }}</th>
                <th>{{ __('mfg.col_name_en') }}</th>
                <th>{{ __('mfg.col_active') }}</th>
                <th class="num">{{ __('mfg.col_hours') }}</th>
                @if ($canManage)<th data-noexport></th>@endif
            </tr>
            @forelse ($lines as $l)
                <tr>
                    <td class="num"><b>{{ $l->code }}</b></td>
                    @if ($canManage)
                        {{-- تعديل في نفس الصف — فورم لكل خط بـ`form=` عشان الجدول يفضل جدول --}}
                        <td><input type="text" name="name" form="lineForm{{ $l->id }}" maxlength="120" required value="{{ $l->name }}"></td>
                        <td><input type="text" name="name_en" form="lineForm{{ $l->id }}" maxlength="120" value="{{ $l->name_en }}"></td>
                        <td>
                            <input type="hidden" name="active" value="0" form="lineForm{{ $l->id }}">
                            <label><input type="checkbox" name="active" value="1" form="lineForm{{ $l->id }}" @checked($l->active)> {{ __('mfg.col_active') }}</label>
                        </td>
                    @else
                        <td>{{ $l->name }}</td>
                        <td>{{ $l->name_en ?? '—' }}</td>
                        <td><span class="badge {{ $l->active ? 'b-green' : 'b-gray' }}">{{ $l->active ? __('mfg.yes') : __('mfg.no') }}</span></td>
                    @endif
                    <td class="num">{{ number_format((float) $l->hours_in_range, 2) }}</td>
                    @if ($canManage)
                        <td data-noexport>
                            <form method="POST" action="{{ route('mfg.lines.update', $l) }}" id="lineForm{{ $l->id }}">
                                @csrf
                                <button class="btn sm" type="submit">{{ __('common.save') }}</button>
                            </form>
                        </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $canManage ? 6 : 5 }}" style="text-align:center;color:var(--muted);padding:28px">{{ __('mfg.no_lines') }}</td></tr>
            @endforelse
        </table>
    </div>
</div>

@if ($canSettings)
{{-- المخازن الافتراضية لأوامر الإنتاج (D10) — فاضي = أول مخزن مصنع نشط --}}
<div class="card" data-noprint>
    <h3>⚙️ {{ __('mfg.lines_settings_title') }} <span class="side">{{ __('mfg.lines_settings_hint') }}</span></h3>
    <form method="POST" action="{{ route('mfg.lines.settings') }}" class="searchbar">
        @csrf
        <label class="fl"><span>{{ __('mfg.f_raw_warehouse') }}</span>
        <select name="mfg_raw_warehouse_id">
            <option value="">{{ __('mfg.default_factory_wh') }}</option>
            @foreach ($warehouses as $w)
                <option value="{{ $w->id }}" @selected($rawId === $w->id)>{{ $w->displayName() }}</option>
            @endforeach
        </select></label>
        <label class="fl"><span>{{ __('mfg.f_fg_warehouse') }}</span>
        <select name="mfg_fg_warehouse_id">
            <option value="">{{ __('mfg.default_factory_wh') }}</option>
            @foreach ($warehouses as $w)
                <option value="{{ $w->id }}" @selected($fgId === $w->id)>{{ $w->displayName() }}</option>
            @endforeach
        </select></label>
        <button class="btn" type="submit">{{ __('common.save') }}</button>
    </form>
</div>
@endif
@endsection
