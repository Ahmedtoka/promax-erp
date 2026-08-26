@extends('layouts.system')

{{--
    أجهزة تتبع العربيات — iTrack (٢٦ أغسطس ٢٠٢٦):

    • كارت حساب المنصة (أدمن): أكاونت + باسورد (بيتخزن md5 بس) +
      حالة التوكن وآخر خطأ.
    • أزرار: سحب الأجهزة من المنصة · تحديث المواقع دلوقتي.
    • جدول الأجهزة: ربط كل IMEI بمندوب/سواق + اللوحة + التفعيل +
      آخر إشارة وحالة وسرعة وبطارية وكيلومترات اليوم.
    • البولينج الفعلي أمر مجدول كل دقيقة (promax:itrack-poll).
--}}

@section('title', __('gps.title'))

@section('content')

@php
    $isAdmin = auth()->user()->role === 'admin';
    $fresh = fn ($d) => $d->gps_time && $d->gps_time->gt(now()->subMinutes(15));
@endphp

{{-- ═══ كارت الحساب — أدمن بس ═══ --}}
@if ($isAdmin)
<div class="card" style="margin-bottom:14px">
    <div style="display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap">
        <form method="POST" action="{{ route('ops.gps.credentials') }}"
              style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;flex:1">
            @csrf
            <div style="flex:0 1 220px">
                <label class="f">{{ __('gps.account') }}</label>
                <input name="account" value="{{ $account }}" required dir="ltr">
            </div>
            <div style="flex:0 1 220px">
                <label class="f">{{ __('gps.password') }}
                    @if ($hasPassword)<span class="badge b-green" style="font-size:9px">✓</span>@endif
                </label>
                {{-- فاضي = سيب المتخزن زي ما هو --}}
                <input name="password" type="password" dir="ltr"
                       placeholder="{{ $hasPassword ? '••••••••' : '' }}" autocomplete="new-password">
            </div>
            <button class="btn gold" type="submit">💾 {{ __('gps.save_creds') }}</button>
        </form>

        <form method="POST" action="{{ route('ops.gps.sync') }}">@csrf
            <button class="btn" type="submit">📥 {{ __('gps.sync_btn') }}</button>
        </form>
        <form method="POST" action="{{ route('ops.gps.poll') }}">@csrf
            <button class="btn" type="submit">🔄 {{ __('gps.poll_btn') }}</button>
        </form>
    </div>

    <div style="display:flex;gap:14px;margin-top:10px;font-size:11.5px;flex-wrap:wrap">
        <span>{{ __('gps.token_state') }}:
            <b class="{{ $tokenOk ? 'pos' : '' }}">{{ $tokenOk ? __('gps.token_ok') : __('gps.token_none') }}</b>
        </span>
        @if ($lastError)
            <span style="color:var(--red,#DC2626)">⚠️ {{ __('gps.last_error') }}: <b dir="ltr">{{ $lastError }}</b></span>
        @endif
    </div>
    <div class="dash-hint" style="margin-top:6px">{{ __('gps.h_creds') }}</div>
</div>
@endif

{{-- ═══ جدول الأجهزة ═══ --}}
<div class="card">
    @if ($devices->isEmpty())
        <div class="empty">{{ __('gps.empty') }}</div>
    @else
    <form method="POST" action="{{ route('ops.gps.save') }}">
        @csrf
        {{-- الجدول بياخد ستايل اللياوت العام — مفيش كلاس (زي invoices) --}}
        <table>
            <thead>
            <tr>
                <th>IMEI</th>
                <th>{{ __('gps.plate') }}</th>
                <th>{{ __('gps.linked_user') }}</th>
                <th>{{ __('gps.last_signal') }}</th>
                <th>{{ __('gps.state') }}</th>
                <th>{{ __('gps.speed') }}</th>
                <th>{{ __('gps.today_km') }}</th>
                <th>🔋</th>
                <th>{{ __('common.active') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($devices as $d)
                <tr>
                    <td dir="ltr" style="font-weight:700">{{ $d->imei }}
                        @if ($d->name)<div style="font-size:10px;color:var(--muted)">{{ $d->name }}</div>@endif
                    </td>
                    <td><input name="d[{{ $d->id }}][plate]" value="{{ $d->plate }}"
                               style="width:110px" @disabled(! $isAdmin)></td>
                    <td>
                        <select name="d[{{ $d->id }}][user_id]" @disabled(! $isAdmin)>
                            <option value="">—</option>
                            @foreach ($field as $f)
                                <option value="{{ $f->id }}" @selected($d->user_id === $f->id)>
                                    {{ $f->displayName() }} ({{ $f->code }})</option>
                            @endforeach
                        </select>
                    </td>
                    <td dir="ltr" style="white-space:nowrap">
                        @if ($d->gps_time)
                            <b class="{{ $fresh($d) ? 'pos' : '' }}">{{ $d->gps_time->format('h:i A') }}</b>
                            <div style="font-size:10px;color:var(--muted)">{{ $d->gps_time->format('d/m') }}</div>
                        @else — @endif
                    </td>
                    <td>
                        @php $sk = $d->statusKey(); @endphp
                        <span class="badge {{ $sk === 'online' ? 'b-green' : ($sk === 'offline' ? 'b-red' : 'b-gray') }}">
                            {{ __('gps.st_'.$sk) }}</span>
                        @if ((int) $d->acc === 1)<span class="badge b-blue" style="font-size:9.5px">{{ __('gps.acc_on') }}</span>@endif
                    </td>
                    <td dir="ltr">{{ $d->speed !== null ? $d->speed.' km/h' : '—' }}</td>
                    <td dir="ltr">{{ $d->today_km !== null ? number_format($d->today_km, 1) : '—' }}</td>
                    <td dir="ltr">{{ $d->battery !== null && $d->battery >= 0 ? $d->battery.'%' : '—' }}</td>
                    {{-- التشيك بوكس مع رفيق مخفي بـ0 — عشان الفورم يبعت القيمة دايماً --}}
                    <td><input type="checkbox" name="d[{{ $d->id }}][active]" value="1"
                               @checked($d->active) @disabled(! $isAdmin)></td>
                </tr>
            @endforeach
            </tbody>
        </table>

        @if ($isAdmin)
            <div style="margin-top:12px">
                <button class="btn gold" type="submit">💾 {{ __('gps.save_links') }}</button>
            </div>
        @endif
    </form>
    @endif

    <div class="dash-hint" style="margin-top:8px">{{ __('gps.h_table') }}</div>
</div>

@endsection
