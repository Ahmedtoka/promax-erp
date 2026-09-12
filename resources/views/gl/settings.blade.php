@extends('layouts.system')

{{--
    إعدادات الدفتر (١٢/٩/٢٠٢٦) — الشاشة للمحاسب يقرا منها ويقفل
    الشهر، والكتابة الباقية (القواعد، السويتش، فتح شهر مقفول، إعادة
    البناء) أدمن بس: دي قرارات بتحرّك أرصدة الشركة كلها مش صف واحد.
--}}

@section('title', __('gl.settings'))

@php
    $fmt = fn ($n) => number_format((float) $n, 2);
    $canPost = \App\Support\Access::action(auth()->user(), 'act.gl.post');
    $canAdmin = \App\Support\Access::action(auth()->user(), 'act.gl.admin');
@endphp

@section('content')

@if (! empty($rebuildError))
    <div class="alert bad" style="margin-bottom:12px"><span>⛔</span><span>{{ $rebuildError }}</span></div>
@endif

{{-- ═══════════ قواعد واقفة — الترحيل هيقف وإعادة البناء هترفض ═══════════ --}}
@if (! empty($brokenRules))
    <div class="alert warn" style="margin-bottom:12px">
        <span>⚠️</span>
        <span>
            <b>{{ __('gl.rules_broken_warn') }}</b>
            <div style="font-size:11.5px;margin-top:4px">
                @foreach ($brokenRules as $b)
                    <div>• {{ $b['label'] }} <span dir="ltr" style="color:var(--muted)">({{ $b['key'] }})</span> — {{ $b['why'] }}</div>
                @endforeach
            </div>
        </span>
    </div>
@endif

{{-- ═══════════ الفحص الثابت — الشجرة مقابل الدفتر ═══════════ --}}
<div class="kpis">
    @if ($invariants === null)
        <div class="kpi">
            <div class="lbl">{{ __('gl.invariants') }}</div>
            <div class="val neg">—</div>
            <div class="sub2">{{ __('gl.no_tree') }}</div>
        </div>
    @else
        @foreach (['receivables', 'payables'] as $k)
            <div class="kpi">
                <div class="lbl">{{ __('gl.invariant_'.$k) }}</div>
                <div class="val {{ $invariants[$k]['ok'] ? '' : 'neg' }}">{{ $fmt($invariants[$k]['gl']) }}</div>
                <div class="sub2">
                    {{ __('gl.expected') }}: {{ $fmt($invariants[$k]['expected']) }}
                    <span class="badge {{ $invariants[$k]['ok'] ? 'b-green' : 'b-red' }}">{{ $invariants[$k]['ok'] ? '✓' : '✕' }}</span>
                </div>
            </div>
        @endforeach
    @endif
    <div class="kpi">
        <div class="lbl">{{ __('gl.enabled') }}</div>
        <div class="val">{{ $general['gl_enabled'] ? '✓' : '✕' }}</div>
        <div class="sub2">{{ __('gl.start_date') }}: {{ $general['gl_start_date'] ?: '—' }}</div>
    </div>
</div>

{{-- ═══════════ تقرير إعادة البناء ═══════════ --}}
@if ($report)
<div class="card">
    <h3>🔁 {{ __('gl.rebuild_result') }}
        <span class="side">{{ $report->dryRun ? __('gl.rebuild_preview') : __('gl.rebuild') }}</span>
        <span class="badge {{ $report->ok ? 'b-green' : 'b-red' }}">{{ $report->ok ? __('gl.rebuild_ok') : __('gl.not_balanced') }}</span>
    </h3>

    <div class="kpis">
        <div class="kpi">
            <div class="lbl">{{ __('gl.rebuild_deleted') }}</div>
            <div class="val">{{ number_format($report->deleted) }}</div>
            <div class="sub2">{{ __('gl.rebuild_created') }}: {{ number_format($report->created) }}</div>
        </div>
        <div class="kpi">
            <div class="lbl">{{ __('gl.rebuild_keep_overrides') }}</div>
            <div class="val">{{ number_format($report->overridesKept) }}</div>
            <div class="sub2">{{ __('gl.rebuild_overrides_dropped') }}: {{ number_format($report->overridesDropped) }}</div>
        </div>
    </div>

    <div class="tablewrap">
        <table id="glRebuildDiff">
            <tr>
                {{-- ⚠️ data-nosum — كود الحساب نص مش مبلغ --}}
                <th data-nosum>{{ __('gl.code') }}</th>
                <th class="num">{{ __('gl.before') }}</th>
                <th class="num">{{ __('gl.after') }}</th>
                <th class="num">{{ __('gl.rebuild_diff') }}</th>
            </tr>
            @forelse ($report->accountDiff as $code => $d)
                <tr>
                    <td dir="ltr"><b>{{ $code }}</b></td>
                    <td class="num">{{ $fmt($d['before']) }}</td>
                    <td class="num">{{ $fmt($d['after']) }}</td>
                    <td class="num"><b>{{ $fmt($d['after'] - $d['before']) }}</b></td>
                </tr>
            @empty
                <tr><td colspan="4" style="color:var(--muted);font-size:12px">{{ __('gl.rebuild_no_diff') }}</td></tr>
            @endforelse
        </table>
    </div>

    <div class="tablewrap" style="margin-top:12px">
        <table>
            <tr>
                <th style="text-align:start">{{ __('gl.invariants') }}</th>
                <th class="num" data-nosum>{{ __('gl.gl_side') }}</th>
                <th class="num" data-nosum>{{ __('gl.expected') }}</th>
                <th>{{ __('gl.status') }}</th>
            </tr>
            @foreach ($report->invariants as $key => $inv)
                @php
                    // ⚠️ `entries` بيعدّ قيود مش فلوس — `2.00 قيد` رقم
                    // بيخلّي القارئ يدوّر على مليم مش موجود
                    $isMoney = array_key_exists('gl', $inv);
                @endphp
                <tr>
                    <td style="text-align:start">
                        {{ $key }}
                        @if ($key === 'entries')
                            <div style="font-size:10.5px;color:var(--muted)">
                                {{ __('gl.rebuild_missing') }}: {{ number_format((int) ($inv['missing'] ?? 0)) }}
                            </div>
                        @endif
                    </td>
                    <td class="num">{{ $isMoney ? $fmt($inv['gl']) : number_format((int) ($inv['deleted'] ?? 0)) }}</td>
                    <td class="num">{{ $isMoney ? $fmt($inv['expected']) : number_format((int) ($inv['created'] ?? 0)) }}</td>
                    <td><span class="badge {{ $inv['ok'] ? 'b-green' : 'b-red' }}">{{ $inv['ok'] ? '✓' : '✕' }}</span></td>
                </tr>
            @endforeach
        </table>
    </div>

    @if (! empty($report->missingSources))
        <div style="font-size:11px;color:var(--muted);margin-top:8px" dir="ltr">
            {{ __('gl.rebuild_missing_sources') }}: {{ implode(', ', $report->missingSources) }}
        </div>
    @endif
</div>
@endif

{{-- ═══════════ الإعدادات العامة ═══════════ --}}
<div class="card">
    <h3>⚙️ {{ __('gl.settings') }} <span class="side">{{ __('gl.enabled_hint') }}</span></h3>

    @if ($canAdmin)
        <form method="POST" action="{{ route('gl.settings.general') }}">
            @csrf
            <div class="frow">
                <div>
                    <label class="f">{{ __('gl.start_date') }}</label>
                    <input type="date" name="gl_start_date" value="{{ $general['gl_start_date'] }}" style="width:100%">
                </div>
                <div>
                    <label class="f">{{ __('gl.bank_name') }}</label>
                    <input type="text" name="gl_bank_name" maxlength="80" value="{{ $general['gl_bank_name'] }}" style="width:100%">
                </div>
                <div style="align-self:flex-end">
                    <label class="f">
                        <input type="checkbox" name="gl_enabled" value="1" @checked($general['gl_enabled'])>
                        {{ __('gl.enabled') }}
                    </label>
                </div>
            </div>
            <div style="display:flex;justify-content:flex-end;margin-top:12px">
                <button class="btn gold" type="submit">{{ __('common.save') }}</button>
            </div>
        </form>
    @else
        <div style="font-size:12px;color:var(--muted)">
            {{ __('gl.start_date') }}: <b>{{ $general['gl_start_date'] ?: '—' }}</b> ·
            {{ __('gl.bank_name') }}: <b>{{ $general['gl_bank_name'] ?: '—' }}</b> ·
            {{ __('gl.enabled') }}: <b>{{ $general['gl_enabled'] ? '✓' : '✕' }}</b>
            <div style="margin-top:6px">{{ __('gl.admin_only_hint') }}</div>
        </div>
    @endif
</div>

{{-- ═══════════ قواعد الترحيل ═══════════ --}}
<div class="card">
    <h3>🧭 {{ __('gl.rules') }} <span class="side">{{ __('gl.rules_sub') }}</span></h3>

    @if ($canAdmin)
        <form method="POST" action="{{ route('gl.settings.rules') }}">
            @csrf
            <div class="tablewrap">
                <table>
                    <tr>
                        {{-- ⚠️ data-nosum — المفاتيح والأكواد نصوص مش مبالغ --}}
                        <th data-nosum style="text-align:start">{{ __('gl.rule') }}</th>
                        <th data-nosum>{{ __('gl.rule_debit') }}</th>
                        <th data-nosum>{{ __('gl.rule_credit') }}</th>
                        <th data-nosum>{{ __('gl.rule_tax') }}</th>
                        <th>{{ __('gl.active') }}</th>
                    </tr>
                    @foreach ($rules as $r)
                        <tr>
                            <td style="text-align:start">
                                <b>{{ $r->label }}</b>
                                <div style="font-size:10.5px;color:var(--muted)" dir="ltr">{{ $r->key }}</div>
                            </td>
                            @foreach (['debit_key', 'credit_key', 'tax_key'] as $slot)
                                @php
                                    // الطرف المدين في قواعد المصروفات جاي من السند نفسه —
                                    // غير كده الطرفين مطلوبين، فخيار «—» بيتشال من القايمة
                                    // بدل ما المحاسب يحفظ قاعدة نص ويكتشف بعدين إن الترحيل واقف
                                    $required = $slot !== 'tax_key' && ! ($slot === 'debit_key' && str_starts_with($r->key, 'expense.'));
                                    // حسابات الترحيل بس + `rep_cash` في الخانات اللي بتفهمه؛
                                    // والقيمة المحفوظة دايماً موجودة عشان الحفظ مايغيّرش
                                    // مفتاح قديم من غير ما المحاسب يقصد
                                    $opts = $keys->filter(fn ($k) => $k->is_postable
                                        || $r->{$slot} === $k->system_key
                                        || ($k->system_key === 'rep_cash' && in_array($slot, $repCashSlots[$r->key] ?? [], true)));
                                @endphp
                                <td>
                                    <select name="rules[{{ $r->key }}][{{ $slot }}]" style="width:100%" @required($required)>
                                        @unless ($required)
                                            <option value="">—</option>
                                        @endunless
                                        @foreach ($opts as $k)
                                            <option value="{{ $k->system_key }}" @selected($r->{$slot} === $k->system_key)>{{ $k->code }} · {{ $k->displayName() }}</option>
                                        @endforeach
                                    </select>
                                </td>
                            @endforeach
                            <td>
                                <input type="checkbox" name="rules[{{ $r->key }}][active]" value="1" @checked($r->active)>
                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>
            <div style="display:flex;justify-content:flex-end;margin-top:12px">
                <button class="btn gold" type="submit">{{ __('common.save') }}</button>
            </div>
        </form>
    @else
        <div class="tablewrap">
            <table>
                <tr>
                    <th data-nosum style="text-align:start">{{ __('gl.rule') }}</th>
                    <th data-nosum>{{ __('gl.rule_debit') }}</th>
                    <th data-nosum>{{ __('gl.rule_credit') }}</th>
                    <th data-nosum>{{ __('gl.rule_tax') }}</th>
                    <th>{{ __('gl.active') }}</th>
                </tr>
                @foreach ($rules as $r)
                    <tr>
                        <td style="text-align:start"><b>{{ $r->label }}</b></td>
                        <td dir="ltr">{{ $r->debit_key ?: '—' }}</td>
                        <td dir="ltr">{{ $r->credit_key ?: '—' }}</td>
                        <td dir="ltr">{{ $r->tax_key ?: '—' }}</td>
                        <td><span class="badge {{ $r->active ? 'b-green' : 'b-gray' }}">{{ $r->active ? '✓' : '✕' }}</span></td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif
</div>

{{-- ═══════════ الفترات ═══════════ --}}
<div class="card">
    <h3>📆 {{ __('gl.periods') }} <span class="side">{{ __('gl.periods_sub') }}</span></h3>

    <div class="tablewrap">
        <table>
            <tr>
                {{-- ⚠️ data-nosum — مفتاح الشهر نص مش مبلغ --}}
                <th data-nosum>{{ __('gl.period') }}</th>
                <th>{{ __('gl.status') }}</th>
                <th></th>
            </tr>
            @foreach ($periods as $p)
                <tr>
                    <td dir="ltr"><b>{{ $p['key'] }}</b></td>
                    <td>
                        <span class="badge {{ $p['closed'] ? 'b-red' : 'b-green' }}">
                            {{ $p['closed'] ? __('gl.period_closed_badge') : __('gl.period_open') }}
                        </span>
                    </td>
                    <td>
                        @if ($p['closed'])
                            @if ($canAdmin)
                                <form method="POST" action="{{ route('gl.periods.reopen', $p['key']) }}" style="display:inline"
                                      onsubmit="return confirm(@js(__('gl.confirm_reopen')))">
                                    @csrf
                                    <button class="btn sm" type="submit">🔓 {{ __('gl.reopen_period') }}</button>
                                </form>
                            @endif
                        @elseif ($canPost)
                            <form method="POST" action="{{ route('gl.periods.close', $p['key']) }}" style="display:inline"
                                  onsubmit="return confirm(@js(__('gl.confirm_close')))">
                                @csrf
                                <button class="btn sm" type="submit">🔒 {{ __('gl.close_period') }}</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    </div>
</div>

{{-- ═══════════ إعادة البناء — أدمن بس ═══════════ --}}
@if ($canAdmin)
<div class="card">
    <h3>🔁 {{ __('gl.rebuild') }} <span class="side">{{ __('gl.rebuild_sub') }}</span></h3>

    <div class="alert warn" style="margin-bottom:12px">
        <span>⚠️</span><span>{{ __('gl.rebuild_warn') }}</span>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap">
        <form method="POST" action="{{ route('gl.rebuild.preview') }}">
            @csrf
            <label class="f"><input type="checkbox" name="keep_overrides" value="1" checked> {{ __('gl.rebuild_keep_overrides') }}</label>
            <button class="btn" type="submit">👁️ {{ __('gl.rebuild_preview') }}</button>
        </form>
        <form method="POST" action="{{ route('gl.rebuild') }}" onsubmit="return confirm(@js(__('gl.confirm_rebuild')))">
            @csrf
            <label class="f"><input type="checkbox" name="keep_overrides" value="1" checked> {{ __('gl.rebuild_keep_overrides') }}</label>
            <button class="btn gold" type="submit">🔁 {{ __('gl.rebuild') }}</button>
        </form>
    </div>
</div>
@endif

@endsection
