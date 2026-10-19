@extends('layouts.system')

{{--
    ربط الحسابات (١٩/١٠ — D1). كارتين: مفاتيح النظام (كل مفتاح على أنهي
    حساب معتمد) وقواعد الترحيل (كل طرف: الحساب اللي بيتحل له + تثبيت
    اختياري). الشاشة للمحاسب يقرا منها، والحفظ أدمن بس (`act.gl.admin`).
--}}

@section('title', __('gl.map_title'))

@php
    $canAdmin = \App\Support\Access::action(auth()->user(), 'act.gl.admin');
    $acctLabel = fn ($a) => $a ? $a->code.' · '.$a->displayName() : '—';
@endphp

@section('content')

@if ($errors->count() > 1)
    <div class="alert warn" style="margin-bottom:12px">
        <span>⛔</span>
        <span style="font-size:12px">
            @foreach ($errors->all() as $err)
                <div>• {{ $err }}</div>
            @endforeach
        </span>
    </div>
@endif

@unless ($adopted)
    <div class="alert info" style="margin-bottom:12px"><span>ℹ️</span><span>{{ __('gl.map_adopt_first') }}</span></div>
@endunless

@if ($problems !== [])
    <div class="alert warn" style="margin-bottom:12px">
        <span>{{ $adopted ? '⛔' : '⚠️' }}</span>
        <span>
            <b>{{ $adopted ? __('gl.map_problems_title') : __('gl.map_problems_pre') }}</b>
            <div style="font-size:11.5px;margin-top:4px">
                @foreach ($problems as $p)
                    <div>• {{ $p }}</div>
                @endforeach
            </div>
        </span>
    </div>
@else
    <div class="alert good" style="margin-bottom:12px"><span>✓</span><span>{{ __('gl.map_all_ok') }}</span></div>
@endif

{{-- ═══════════ حسابات النظام ═══════════ --}}
<div class="card">
    <h3>🔑 {{ __('gl.map_keys_title') }} <span class="side">{{ __('gl.map_keys_sub') }}</span></h3>

    @if ($canAdmin)<form method="POST" action="{{ route('gl.mapping.keys') }}">@csrf @endif
    <div class="tablewrap">
        <table id="glMapKeys">
            <tr>
                <th data-nosum style="text-align:start">{{ __('gl.map_key') }}</th>
                <th data-nosum>{{ __('gl.map_current') }}</th>
                @if ($canAdmin)<th data-nosum>{{ __('gl.map_new') }}</th>@endif
                <th>{{ __('gl.map_status') }}</th>
            </tr>
            @foreach ($keys as $k)
                @php $pool = $k['key'] === 'rep_cash' ? $repCashPool : $postable; @endphp
                <tr>
                    <td style="text-align:start">
                        <b>{{ \App\Services\Gl\GlMapping::keyLabel($k['key']) }}</b>
                        <div style="font-size:10.5px;color:var(--muted)" dir="ltr">{{ $k['key'] }}</div>
                    </td>
                    <td>{{ $acctLabel($k['account']) }}</td>
                    @if ($canAdmin)
                        <td>
                            <select name="keys[{{ $k['key'] }}]" style="width:100%">
                                @if ($k['account'] === null)<option value="">—</option>@endif
                                @if ($k['account'] && ! $pool->contains('id', $k['account']->id))
                                    <option value="{{ $k['account']->id }}" selected>{{ $acctLabel($k['account']) }}</option>
                                @endif
                                @foreach ($pool as $a)
                                    <option value="{{ $a->id }}" @selected($k['account']?->id === $a->id)>{{ $acctLabel($a) }}</option>
                                @endforeach
                            </select>
                        </td>
                    @endif
                    <td>
                        <span class="badge {{ $k['ok'] ? 'b-green' : 'b-red' }}">{{ $k['ok'] ? __('gl.map_ok') : __('gl.map_bad') }}</span>
                        @if ($k['why'])<div style="font-size:10.5px;color:var(--muted)">{{ $k['why'] }}</div>@endif
                    </td>
                </tr>
            @endforeach
        </table>
    </div>
    @if ($canAdmin)
        <div style="display:flex;justify-content:flex-end;margin-top:12px">
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
    @endif
</div>

{{-- ═══════════ قواعد الترحيل ═══════════ --}}
<div class="card">
    <h3>🧭 {{ __('gl.map_rules_title') }} <span class="side">{{ __('gl.map_rules_sub') }}</span></h3>

    @if ($canAdmin)<form method="POST" action="{{ route('gl.mapping.rules') }}">@csrf @endif
    <div class="tablewrap">
        <table id="glMapRules">
            <tr>
                <th data-nosum style="text-align:start">{{ __('gl.rule') }}</th>
                <th data-nosum>{{ __('gl.rule_debit') }}</th>
                <th data-nosum>{{ __('gl.rule_credit') }}</th>
                <th data-nosum>{{ __('gl.rule_tax') }}</th>
                <th>{{ __('gl.map_status') }}</th>
            </tr>
            @foreach ($rules as $r)
                @php
                    $rule = $r['rule'];
                    $ok = $rule->active && collect($r['slots'])->every(fn ($s) => $s['ok']);
                @endphp
                <tr>
                    <td style="text-align:start">
                        <b>{{ $rule->label ?: $rule->key }}</b>
                        <div style="font-size:10.5px;color:var(--muted)" dir="ltr">{{ $rule->key }}</div>
                    </td>
                    @foreach (\App\Services\Gl\GlMapping::SIDES as $side)
                        @php $s = $r['slots'][$side] ?? null; @endphp
                        <td>
                            @if ($s === null)
                                <span style="color:var(--muted)">—</span>
                            @elseif (\App\Services\Gl\GlMapping::fromDocument($rule->key, $side))
                                <span style="color:var(--muted)">{{ __('gl.map_from_document') }}</span>
                            @else
                                <div style="font-size:11.5px">
                                    {{ $acctLabel($s['account']) }}
                                    @if ($s['pinned'])<span class="badge b-gold">📌 {{ __('gl.map_pinned') }}</span>@endif
                                    @unless ($s['ok'])<span class="badge b-red" title="{{ $s['why'] }}">✕</span>@endunless
                                </div>
                                @if ($s['key'])<div style="font-size:10.5px;color:var(--muted)" dir="ltr">{{ $s['key'] }}</div>@endif
                                @if ($canAdmin && $s['key'] !== 'rep_cash')
                                    <select name="pins[{{ $rule->key }}][{{ $side }}]" style="width:100%;margin-top:4px">
                                        <option value="">{{ __('gl.map_from_key') }}</option>
                                        @if ($s['pinned'] && $s['account'] && ! $postable->contains('id', $s['account']->id))
                                            <option value="{{ $s['account']->id }}" selected>{{ $acctLabel($s['account']) }}</option>
                                        @endif
                                        @foreach ($postable as $a)
                                            <option value="{{ $a->id }}" @selected($s['pinned'] && $s['account']?->id === $a->id)>{{ $acctLabel($a) }}</option>
                                        @endforeach
                                    </select>
                                @endif
                            @endif
                        </td>
                    @endforeach
                    <td>
                        @if (! $rule->active)
                            <span class="badge b-gray">{{ __('gl.map_inactive') }}</span>
                        @else
                            <span class="badge {{ $ok ? 'b-green' : 'b-red' }}">{{ $ok ? __('gl.map_ok') : __('gl.map_bad') }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    </div>
    @if ($canAdmin)
        <div style="display:flex;justify-content:flex-end;margin-top:12px">
            <button class="btn gold" type="submit">{{ __('common.save') }}</button>
        </div>
    </form>
    @else
        <div style="font-size:12px;color:var(--muted);margin-top:8px">{{ __('gl.admin_only_hint') }}</div>
    @endif
</div>

@endsection
