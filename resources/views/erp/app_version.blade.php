@extends('layouts.system')

{{--
    إصدار الأبلكيشن (2026-08-07) — أدمن بس.

    ⚠️ الشاشة دي بتتحكم في تليفونات المناديب اللي في الشارع. رفع
    «أقل إصدار» بيقفل الأبلكيشن على كل واحد لسه ما حدّثش — عشان كده
    الخانة دي متفصولة عن «آخر إصدار» ومكتوب جنبها تحذير صريح.
--}}

@section('title', __('appver.title'))

@section('content')

@if (session('ok'))
    <div class="alert good" style="margin-bottom:12px"><span>✅</span><span>{{ session('ok') }}</span></div>
@endif

@if ($errors->any())
    <div class="alert bad" style="margin-bottom:12px">
        <span>⚠️</span><span>{{ $errors->first() }}</span>
    </div>
@endif

<form method="POST" action="{{ route('erp.app_version.save') }}" enctype="multipart/form-data">
    @csrf

    <div class="card">
        <h3>📲 {{ __('appver.title') }}
            <span class="side">{{ __('appver.hint') }}</span></h3>

        <div class="frow">
            <div>
                <label class="f">{{ __('appver.latest') }}</label>
                <input type="text" name="app_version" required dir="ltr" pattern="\d+\.\d+\.\d+"
                       value="{{ old('app_version', $version) }}"
                       style="width:100%;text-align:center;font-weight:800">
                <div class="side" style="font-size:11px">{{ __('appver.latest_hint') }}</div>
            </div>
            <div>
                <label class="f">{{ __('appver.minimum') }}</label>
                <input type="text" name="app_min_version" required dir="ltr" pattern="\d+\.\d+\.\d+"
                       value="{{ old('app_min_version', $minVersion) }}"
                       style="width:100%;text-align:center;font-weight:800;color:#B00020">
                <div class="side" style="font-size:11px;color:#B00020">{{ __('appver.minimum_hint') }}</div>
            </div>
        </div>

        <div style="margin-top:12px">
            <label class="f">{{ __('appver.note') }}</label>
            <input type="text" name="app_update_note" maxlength="300"
                   value="{{ old('app_update_note', $note) }}" style="width:100%"
                   placeholder="{{ __('appver.note_ph') }}">
            <div class="side" style="font-size:11px">{{ __('appver.note_hint') }}</div>
        </div>
    </div>

    <div class="card">
        <h3>📦 {{ __('appver.apk') }}</h3>

        @if ($apkExists)
            <div class="alert good" style="margin-bottom:10px">
                <span>✅</span>
                <span>{{ __('appver.apk_on_server', [
                    'size' => number_format($apkSize / 1048576, 1),
                    'at' => $apkAt,
                ]) }}</span>
            </div>
            <a href="{{ $apkUrl ?: url('app/promax.apk') }}" class="btn" style="margin-bottom:10px">
                ⬇️ {{ __('appver.download') }}
            </a>
        @else
            <div class="alert bad" style="margin-bottom:10px">
                <span>⚠️</span><span>{{ __('appver.apk_missing') }}</span>
            </div>
        @endif

        <label class="f">{{ __('appver.apk_upload') }}</label>
        <input type="file" name="apk" accept=".apk,application/vnd.android.package-archive">
        <div class="side" style="font-size:11px">{{ __('appver.apk_hint') }}</div>
    </div>

    <button class="btn primary" type="submit">{{ __('common.save') }}</button>
</form>

<div class="card" style="margin-top:14px">
    <h3>📱 {{ __('appver.devices') }}
        <span class="side">{{ __('appver.devices_hint') }}</span></h3>

    @if (empty($devices))
        <div class="empty">{{ __('appver.no_devices') }}</div>
    @else
        <div class="tablewrap">
        <table>
            <thead>
                <tr>
                    <th>{{ __('appver.installed') }}</th>
                    <th>{{ __('appver.count') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($devices as $v => $n)
                    <tr>
                        <td dir="ltr" style="font-weight:800">{{ $v }}</td>
                        <td>{{ $n }}</td>
                        <td>
                            @if ($v === $version)
                                <span class="pill good">{{ __('appver.up_to_date') }}</span>
                            @elseif ($v === '—')
                                <span class="pill">{{ __('appver.unknown') }}</span>
                            @else
                                <span class="pill warn">{{ __('appver.outdated') }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    @endif
</div>

@endsection
