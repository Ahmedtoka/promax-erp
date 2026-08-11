@extends('layouts.system')

{{-- التارجيت السنوي الهرمي: شركة ← مديرين ← مناديب ← عملاء (١١ أغسطس ٢٠٢٦). --}}
{{-- المحقق من القيود عن طريق TargetProgress — اليدوي بيغلب في عقدته وفرقه بيصعّد لأبوه. --}}
{{-- كارت المديرين فيه أكورديون لكل مدير: شهوره + يدوي الماضي (أدمن بس). --}}

@section('title', __('targets.title'))

@php
    $viewer = auth()->user();
    $isAdmin = $viewer->isAdmin();
    $fmt = fn ($n) => number_format((float) $n, 2);
    $pctOf = fn ($a, $t) => $t > 0 ? round($a / $t * 100, 1) : 0.0;
    $hasLockedMonths = \App\Models\Target::monthLocked($year, 1);
    $curMonth = $year === (int) now()->year ? (int) now()->month : 0;
    $monthsLeft = $year > (int) now()->year ? 12 : ($year === (int) now()->year ? 13 - (int) now()->month : 0);
    // تلوين المحقق بنسبته: أخضر ≥ ١٠٠٪ · برتقالي ≥ ٧٠٪ · أحمر أقل
    $achClass = fn ($p) => $p >= 100 ? 'pos' : ($p >= 70 ? 'mid' : 'neg');
    $curBg = fn ($m) => $m === $curMonth ? 'background:rgba(18,57,155,.08);' : '';
@endphp

@section('actions')
    <a class="btn" href="{{ route('erp.targets') }}">🎯 {{ __('nav.targets') }}</a>
@endsection

@section('content')

@if (session('ok'))
    <div class="alert good" style="margin-bottom:12px"><span>✅</span><span>{{ session('ok') }}</span></div>
@endif

{{-- ═══ السنة + إنشاء/تعديل تارجيت الشركة ═══ --}}
<div class="card">
    <h3>📈 {{ __('targets.title') }} <span class="side">{{ __('targets.hint') }}</span></h3>
    <div class="searchbar" style="margin-bottom:0">
        <a class="btn" href="{{ route('erp.targets.annual', ['year' => $year - 1]) }}">◀ {{ $year - 1 }}</a>
        <span class="badge b-blue" style="font-size:14px;padding:6px 14px">{{ $year }}</span>
        <a class="btn" href="{{ route('erp.targets.annual', ['year' => $year + 1]) }}">{{ $year + 1 }} ▶</a>
        @if ($isAdmin)
            <button class="btn gold" type="button" onclick="openDlg('dlgCompany')" style="margin-inline-start:auto">
                {{ $company ? '✏️ '.__('targets.edit_company') : '➕ '.__('targets.create_for', ['year' => $year]) }}
            </button>
        @endif
    </div>
</div>

@if ($company === null)
    <div class="alert info" style="margin-top:12px">
        <span>🎯</span>
        <span><b>{{ __('targets.no_company') }}</b> —
            {{ $isAdmin ? __('targets.no_company_hint_admin') : __('targets.no_company_hint_manager') }}</span>
    </div>
@elseif ($grid === null)
    {{-- مدير من غير عقدة السنة دي --}}
    <div class="alert warn" style="margin-top:12px">
        <span>⏳</span><span>{{ __('targets.no_manager_node') }}</span>
    </div>
@endif

{{-- ═══ الـ KPIs + الجريد الشهري — الأدمن شركة، والمدير عقدته ═══ --}}
@if ($grid !== null)
    @php
        $gridPct = $pctOf($grid['achieved_total'], $grid['annual']);
        $gridRemaining = round($grid['annual'] - $grid['achieved_total'], 2);
        $pace = $monthsLeft > 0 && $gridRemaining > 0 ? round($gridRemaining / $monthsLeft, 2) : null;
        $tgSum = round(array_sum($grid['targets']), 2);
        $remSum = round($tgSum - $grid['achieved_total'], 2);
    @endphp

    <div class="kpis" style="margin-top:12px">
        <div class="kpi">
            <div class="lbl">{{ __('targets.kpi_target') }}</div>
            <div class="val num">{{ $fmt($grid['annual']) }}</div>
            <div class="sub2">{{ $year }}</div>
        </div>
        <div class="kpi">
            <div class="lbl">{{ __('targets.kpi_achieved') }}</div>
            <div class="val num pos">{{ $fmt($grid['achieved_total']) }}</div>
        </div>
        <div class="kpi">
            <div class="lbl">{{ __('targets.kpi_remaining') }}</div>
            <div class="val num {{ $gridRemaining > 0 ? 'mid' : 'pos' }}">{{ $fmt(max($gridRemaining, 0)) }}</div>
            @if ($pace !== null)
                <div class="sub2">⚡ {{ __('targets.need_monthly', ['amount' => $fmt($pace)]) }}</div>
            @endif
        </div>
        <div class="kpi">
            <div class="lbl">{{ __('targets.kpi_pct') }}</div>
            <div class="val num">{{ $gridPct }}%</div>
            <div style="background:var(--card2);border:1px solid var(--border);border-radius:6px;height:9px;overflow:hidden;margin-top:6px">
                <div style="height:100%;width:{{ $gridPct > 0 ? max(min($gridPct, 100), 2) : 0 }}%;min-width:{{ $gridPct > 0 ? 2 : 0 }}px;background:linear-gradient(135deg,var(--royal-blue),var(--purple-heart))"></div>
            </div>
        </div>
    </div>

    <div class="card" style="margin-top:12px">
        <h3>🗓️ {{ __('targets.months_title') }}
            <span class="side">{{ $isAdmin ? __('targets.months_hint_admin') : __('targets.months_hint_manager') }}</span></h3>

        <div class="tablewrap">
            <table>
                <tr>
                    <th style="text-align:start">{{ __('targets.month_col') }}</th>
                    @for ($m = 1; $m <= 12; $m++)
                        <th data-nosum style="{{ $curBg($m) }}">{{ __('targets.m'.$m) }}@if (\App\Models\Target::monthLocked($year, $m)) 🔒@elseif ($m === $curMonth) ⭐@endif</th>
                    @endfor
                    <th data-nosum style="border-inline-start:2px solid var(--border)">{{ __('targets.year_total') }}</th>
                </tr>

                <tr>
                    <td style="text-align:start"><b>{{ __('targets.month_target') }}</b></td>
                    @for ($m = 1; $m <= 12; $m++)
                        @php $locked = \App\Models\Target::monthLocked($year, $m); @endphp
                        <td class="num" style="{{ $curBg($m) }}">
                            @if ($isAdmin && ! $locked && $m < 12)
                                <input type="number" step="0.01" min="0" dir="ltr"
                                       value="{{ $grid['targets'][$m] + 0 }}" data-month="{{ $m }}"
                                       data-action="{{ route('erp.targets.annual.rebalance', $grid['node_id']) }}"
                                       onchange="rbSubmit(this)"
                                       style="width:92px;text-align:center;font-weight:700">
                            @else
                                {{ $fmt($grid['targets'][$m]) }}
                            @endif
                        </td>
                    @endfor
                    <td class="num" style="border-inline-start:2px solid var(--border)"><b>{{ $fmt($tgSum) }}</b></td>
                </tr>

                <tr>
                    <td style="text-align:start"><b>{{ __('targets.month_achieved') }}</b></td>
                    @for ($m = 1; $m <= 12; $m++)
                        @php
                            $locked = \App\Models\Target::monthLocked($year, $m);
                            $mp = $pctOf($grid['achieved'][$m], $grid['targets'][$m]);
                        @endphp
                        <td class="num" style="{{ $curBg($m) }}">
                            @if ($isAdmin && $locked)
                                <input type="number" step="0.01" min="0" dir="ltr" form="manualForm"
                                       name="manual[{{ $m }}]"
                                       value="{{ $grid['manuals'][$m] !== null ? $grid['manuals'][$m] + 0 : '' }}"
                                       placeholder="{{ $fmt($grid['computed'][$m]) }}"
                                       style="width:92px;text-align:center">
                                @if ($grid['manuals'][$m] !== null)
                                    <div><span class="badge b-purple" style="margin-top:3px">{{ __('targets.manual_badge') }}</span></div>
                                @endif
                            @elseif ($locked || $m === $curMonth)
                                <span class="{{ $achClass($mp) }}">{{ $fmt($grid['achieved'][$m]) }}</span>
                            @else
                                {{ $fmt($grid['achieved'][$m]) }}
                            @endif
                        </td>
                    @endfor
                    <td class="num" style="border-inline-start:2px solid var(--border)"><b class="pos">{{ $fmt($grid['achieved_total']) }}</b></td>
                </tr>

                <tr>
                    <td style="text-align:start"><b>{{ __('targets.month_remaining') }}</b></td>
                    @for ($m = 1; $m <= 12; $m++)
                        @php
                            $locked = \App\Models\Target::monthLocked($year, $m);
                            $rem = round($grid['targets'][$m] - $grid['achieved'][$m], 2);
                        @endphp
                        <td class="num {{ ($locked || $m === $curMonth) ? ($rem <= 0 ? 'pos' : 'neg') : '' }}" style="{{ $curBg($m) }}">{{ $fmt($rem) }}</td>
                    @endfor
                    <td class="num {{ $remSum <= 0 ? 'pos' : 'neg' }}" style="border-inline-start:2px solid var(--border)"><b>{{ $fmt($remSum) }}</b></td>
                </tr>

                <tr>
                    <td style="text-align:start"><b>{{ __('targets.month_pct') }}</b></td>
                    @for ($m = 1; $m <= 12; $m++)
                        @php $mp = $pctOf($grid['achieved'][$m], $grid['targets'][$m]); @endphp
                        <td class="num {{ $achClass($mp) }}" style="{{ $curBg($m) }}">{{ $mp }}%</td>
                    @endfor
                    <td class="num {{ $achClass($gridPct) }}" style="border-inline-start:2px solid var(--border)"><b>{{ $gridPct }}%</b></td>
                </tr>
            </table>
        </div>

        @if ($isAdmin && $hasLockedMonths)
            <div style="display:flex;align-items:center;gap:10px;margin-top:10px;flex-wrap:wrap">
                <span style="font-size:11px;color:var(--muted)">✍️ {{ __('targets.manual_hint') }}</span>
                <button class="btn" type="submit" form="manualForm" style="margin-inline-start:auto">💾 {{ __('targets.save_manual') }}</button>
            </div>
        @endif
    </div>

    {{-- فورمات الجريد — برّه الجدول عشان مفيش تداخل فورمات --}}
    @if ($isAdmin)
        <form id="rbForm" method="POST" action="{{ route('erp.targets.annual.rebalance', $grid['node_id']) }}">
            @csrf
            <input type="hidden" name="year" value="{{ $year }}">
            <input type="hidden" name="month" id="rbMonth">
            <input type="hidden" name="amount" id="rbAmount">
        </form>
        @if ($hasLockedMonths)
            <form id="manualForm" method="POST" action="{{ route('erp.targets.annual.manual', $grid['node_id']) }}">
                @csrf
                <input type="hidden" name="year" value="{{ $year }}">
            </form>
        @endif
    @endif
@endif

{{-- ═══ كارت توزيع المديرين — أدمن بس ═══ --}}
{{-- الجدول data-plain: جواه أكورديون وجداول متداخلة، وأدوات الجداول --}}
{{-- العامة (سورت/تجميع) هتلخبطه — الإجماليات مكتوبة سيرفر في tfoot. --}}
@if ($managersCard !== null)
    @php
        $mgCols = $curMonth > 0 ? 8 : 7;
        $mgSumAmt = 0.0;
        $mgSumAch = 0.0;
        $mgSumRem = 0.0;
        foreach ($managersCard['rows'] as $mgRow) {
            $mgSumAch += $mgRow['achieved'];
            if ($mgRow['amount'] !== null) {
                $mgSumAmt += $mgRow['amount'];
                $mgSumRem += $mgRow['amount'] - $mgRow['achieved'];
            }
        }
        $mgSumAmt = round($mgSumAmt, 2);
        $mgSumAch = round($mgSumAch, 2);
        $mgSumRem = round($mgSumRem, 2);
    @endphp

    <div class="card" style="margin-top:12px">
        <h3>🧑‍💼 {{ __('targets.managers_title') }}
            <span class="side">{{ __('targets.managers_hint') }}</span></h3>

        <form method="POST" action="{{ route('erp.targets.annual.managers') }}" data-split>
            @csrf
            <input type="hidden" name="year" value="{{ $year }}">
            <div class="tablewrap">
                <table data-plain data-annual="{{ $managersCard['annual'] + 0 }}">
                    <tr>
                        <th style="text-align:start">{{ __('targets.manager') }}</th>
                        <th style="width:90px">%</th>
                        <th style="width:150px">{{ __('targets.amount') }}</th>
                        <th class="num">{{ __('targets.achieved') }}</th>
                        <th class="num">{{ __('targets.month_remaining') }}</th>
                        @if ($curMonth > 0)
                            <th class="num" style="width:130px">{{ __('targets.current_month') }}</th>
                        @endif
                        <th style="width:170px">{{ __('targets.progress') }}</th>
                        <th style="width:46px"></th>
                    </tr>
                    @forelse ($managersCard['rows'] as $row)
                        @php
                            $amt = $row['amount'];
                            $mgPct = $amt !== null && $managersCard['annual'] > 0
                                ? round($amt / $managersCard['annual'] * 100, 1) : null;
                            $mgProg = $pctOf($row['achieved'], (float) ($amt ?? 0));
                            $mgRem = $amt !== null ? round($amt - $row['achieved'], 2) : null;
                            $curT = $curMonth > 0 && $row['targets'] !== null ? $row['targets'][$curMonth] : null;
                            $curA = $curMonth > 0 && $row['achieved_months'] !== null ? $row['achieved_months'][$curMonth] : null;
                        @endphp
                        <tr>
                            <td style="text-align:start">
                                @include('partials._avatar', ['u' => $row['user'], 'size' => 28])
                                <b style="margin-inline-start:6px">{{ $row['user']->displayName() }}</b>
                            </td>
                            <td><input class="sp-pct" type="number" step="0.1" min="0" dir="ltr"
                                       value="{{ $mgPct !== null ? $mgPct : '' }}"
                                       style="width:100%;text-align:center"></td>
                            <td><input class="sp-amt" type="number" name="rows[{{ $row['user']->id }}]"
                                       step="0.01" min="0" dir="ltr"
                                       value="{{ $amt !== null ? $amt + 0 : '' }}"
                                       style="width:100%;text-align:center;font-weight:800"></td>
                            <td class="num">{{ $fmt($row['achieved']) }}</td>
                            <td class="num {{ $mgRem === null ? '' : ($mgRem <= 0 ? 'pos' : 'neg') }}">
                                {{ $mgRem === null ? '—' : $fmt($mgRem) }}
                            </td>
                            @if ($curMonth > 0)
                                <td class="num" style="font-size:10.5px">
                                    @if ($curT !== null)
                                        <div>🎯 {{ $fmt($curT) }}</div>
                                        <div class="{{ $achClass($pctOf((float) $curA, (float) $curT)) }}">{{ $fmt((float) $curA) }}</div>
                                    @else
                                        —
                                    @endif
                                </td>
                            @endif
                            <td>
                                <div style="background:var(--card2);border:1px solid var(--border);border-radius:6px;height:9px;overflow:hidden">
                                    <div style="height:100%;width:{{ $mgProg > 0 ? max(min($mgProg, 100), 2) : 0 }}%;min-width:{{ $mgProg > 0 ? 2 : 0 }}px;background:linear-gradient(135deg,var(--royal-blue),var(--purple-heart))"></div>
                                </div>
                                <div class="num" style="font-size:10px;color:var(--muted)">{{ $mgProg }}%</div>
                            </td>
                            <td>
                                @if ($row['node_id'] !== null)
                                    <button class="btn sm" type="button" aria-label="{{ __('targets.months_title') }}"
                                            onclick="tgAcc(this, 'tgAcc{{ $row['node_id'] }}')">▼</button>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>

                        {{-- أكورديون شهور المدير: تارجيت (rebalance) + محقق (يدوي للماضي) + نسبة --}}
                        @if ($row['node_id'] !== null)
                            <tr id="tgAcc{{ $row['node_id'] }}" hidden>
                                <td colspan="{{ $mgCols }}" style="background:var(--card2);padding:10px 12px;text-align:start">
                                    @php
                                        $accTgSum = round(array_sum($row['targets']), 2);
                                        $accAchSum = $row['achieved'];
                                        $accPct = $pctOf($accAchSum, $accTgSum);
                                    @endphp
                                    <table data-plain style="width:100%;background:var(--card)">
                                        <tr>
                                            <th style="text-align:start">{{ __('targets.month_col') }}</th>
                                            @for ($m = 1; $m <= 12; $m++)
                                                <th style="{{ $curBg($m) }}">{{ __('targets.m'.$m) }}@if (\App\Models\Target::monthLocked($year, $m)) 🔒@elseif ($m === $curMonth) ⭐@endif</th>
                                            @endfor
                                            <th style="border-inline-start:2px solid var(--border)">{{ __('targets.year_total') }}</th>
                                        </tr>
                                        <tr>
                                            <td style="text-align:start"><b>{{ __('targets.month_target') }}</b></td>
                                            @for ($m = 1; $m <= 12; $m++)
                                                @php $locked = \App\Models\Target::monthLocked($year, $m); @endphp
                                                <td class="num" style="{{ $curBg($m) }}">
                                                    @if ($isAdmin && ! $locked && $m < 12)
                                                        <input type="number" step="0.01" min="0" dir="ltr"
                                                               value="{{ $row['targets'][$m] + 0 }}" data-month="{{ $m }}"
                                                               data-action="{{ route('erp.targets.annual.rebalance', $row['node_id']) }}"
                                                               onchange="rbSubmit(this)"
                                                               style="width:88px;text-align:center;font-weight:700">
                                                    @else
                                                        {{ $fmt($row['targets'][$m]) }}
                                                    @endif
                                                </td>
                                            @endfor
                                            <td class="num" style="border-inline-start:2px solid var(--border)"><b>{{ $fmt($accTgSum) }}</b></td>
                                        </tr>
                                        <tr>
                                            <td style="text-align:start"><b>{{ __('targets.month_achieved') }}</b></td>
                                            @for ($m = 1; $m <= 12; $m++)
                                                @php
                                                    $locked = \App\Models\Target::monthLocked($year, $m);
                                                    $mp = $pctOf($row['achieved_months'][$m], $row['targets'][$m]);
                                                @endphp
                                                <td class="num" style="{{ $curBg($m) }}">
                                                    @if ($isAdmin && $locked)
                                                        <input type="number" step="0.01" min="0" dir="ltr"
                                                               form="mgrManual{{ $row['node_id'] }}"
                                                               name="manual[{{ $m }}]"
                                                               value="{{ $row['manuals'][$m] !== null ? $row['manuals'][$m] + 0 : '' }}"
                                                               placeholder="{{ $fmt($row['computed'][$m]) }}"
                                                               style="width:88px;text-align:center">
                                                        @if ($row['manuals'][$m] !== null)
                                                            <div><span class="badge b-purple" style="margin-top:3px">{{ __('targets.manual_badge') }}</span></div>
                                                        @endif
                                                    @elseif ($locked || $m === $curMonth)
                                                        <span class="{{ $achClass($mp) }}">{{ $fmt($row['achieved_months'][$m]) }}</span>
                                                    @else
                                                        {{ $fmt($row['achieved_months'][$m]) }}
                                                    @endif
                                                </td>
                                            @endfor
                                            <td class="num" style="border-inline-start:2px solid var(--border)"><b class="pos">{{ $fmt($accAchSum) }}</b></td>
                                        </tr>
                                        <tr>
                                            <td style="text-align:start"><b>{{ __('targets.month_pct') }}</b></td>
                                            @for ($m = 1; $m <= 12; $m++)
                                                @php $mp = $pctOf($row['achieved_months'][$m], $row['targets'][$m]); @endphp
                                                <td class="num {{ $achClass($mp) }}" style="{{ $curBg($m) }}">{{ $mp }}%</td>
                                            @endfor
                                            <td class="num {{ $achClass($accPct) }}" style="border-inline-start:2px solid var(--border)"><b>{{ $accPct }}%</b></td>
                                        </tr>
                                    </table>
                                    @if ($isAdmin && $hasLockedMonths)
                                        <div style="display:flex;align-items:center;gap:10px;margin-top:8px;flex-wrap:wrap">
                                            <span style="font-size:11px;color:var(--muted)">✍️ {{ __('targets.manual_hint') }}</span>
                                            <button class="btn sm" type="submit" form="mgrManual{{ $row['node_id'] }}"
                                                    style="margin-inline-start:auto">💾 {{ __('targets.save_manual') }}</button>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="{{ $mgCols }}" style="text-align:center;color:var(--muted);padding:18px">{{ __('targets.no_managers') }}</td></tr>
                    @endforelse

                    @if (count($managersCard['rows']) > 0)
                        <tfoot>
                            <tr>
                                <td style="text-align:start"><b>Σ {{ __('targets.total_row') }}</b></td>
                                <td></td>
                                <td class="num"><b>{{ $fmt($mgSumAmt) }}</b></td>
                                <td class="num"><b>{{ $fmt($mgSumAch) }}</b></td>
                                <td class="num {{ $mgSumRem <= 0 ? 'pos' : 'neg' }}"><b>{{ $fmt($mgSumRem) }}</b></td>
                                @if ($curMonth > 0)
                                    <td></td>
                                @endif
                                <td></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
            <div style="display:flex;align-items:center;gap:10px;margin-top:10px">
                <span class="sp-hint badge b-gray"></span>
                <button class="btn gold" type="submit" style="margin-inline-start:auto">💾 {{ __('common.save') }}</button>
            </div>
        </form>
    </div>

    {{-- فورمات اليدوي بتاعة المديرين — برّه فورم التوزيع (ممنوع تداخل فورمات) --}}
    @if ($isAdmin && $hasLockedMonths)
        @foreach ($managersCard['rows'] as $row)
            @if ($row['node_id'] !== null)
                <form id="mgrManual{{ $row['node_id'] }}" method="POST"
                      action="{{ route('erp.targets.annual.manual', $row['node_id']) }}">
                    @csrf
                    <input type="hidden" name="year" value="{{ $year }}">
                </form>
            @endif
        @endforeach
    @endif
@endif

{{-- ═══ بلوكات توزيع الفرق — الأدمن كل المديرين، والمدير نفسه ═══ --}}
@foreach ($blocks as $b)
    @php
        $bSumAmt = 0.0;
        $bSumAch = 0.0;
        $bSumRem = 0.0;
        foreach ($b['rows'] as $bRow) {
            $bSumAch += $bRow['achieved'];
            if ($bRow['amount'] !== null) {
                $bSumAmt += $bRow['amount'];
                $bSumRem += $bRow['amount'] - $bRow['achieved'];
            }
        }
        $bSumAmt = round($bSumAmt, 2);
        $bSumAch = round($bSumAch, 2);
        $bSumRem = round($bSumRem, 2);
    @endphp
    <div class="card" style="margin-top:12px">
        <h3>👥 {{ __('targets.reps_title', ['name' => $b['manager']->displayName()]) }}
            <span class="side num">{{ $fmt($b['achieved']) }} / {{ $fmt($b['annual']) }}</span></h3>

        <form method="POST" action="{{ route('erp.targets.annual.reps', $b['node_id']) }}" data-split>
            @csrf
            <input type="hidden" name="year" value="{{ $year }}">
            <div class="tablewrap">
                <table data-annual="{{ $b['annual'] + 0 }}">
                    <tr>
                        <th style="text-align:start">{{ __('targets.rep') }}</th>
                        <th data-nosum style="width:160px">{{ __('targets.amount') }}</th>
                        <th>{{ __('targets.achieved') }}</th>
                        <th>{{ __('targets.month_remaining') }}</th>
                        <th data-nosum style="width:90px">%</th>
                        <th data-nosum style="width:150px"></th>
                    </tr>
                    @forelse ($b['rows'] as $row)
                        @php
                            $rpPct = $pctOf($row['achieved'], (float) ($row['amount'] ?? 0));
                            $rpRem = $row['amount'] !== null ? round($row['amount'] - $row['achieved'], 2) : null;
                        @endphp
                        <tr>
                            <td style="text-align:start">
                                @include('partials._avatar', ['u' => $row['user'], 'size' => 28])
                                <b style="margin-inline-start:6px">{{ $row['user']->displayName() }}</b>
                                <span class="badge b-gray" style="margin-inline-start:6px">{{ __('enums.role.'.$row['user']->role) }}</span>
                            </td>
                            <td><input class="sp-amt" type="number" name="rows[{{ $row['user']->id }}]"
                                       step="0.01" min="0" dir="ltr"
                                       value="{{ $row['amount'] !== null ? $row['amount'] + 0 : '' }}"
                                       style="width:100%;text-align:center;font-weight:800"></td>
                            <td class="num">{{ $fmt($row['achieved']) }}</td>
                            <td class="num {{ $rpRem === null ? '' : ($rpRem <= 0 ? 'pos' : 'neg') }}">
                                {{ $rpRem === null ? '—' : $fmt($rpRem) }}
                            </td>
                            <td class="num {{ $achClass($rpPct) }}">{{ $rpPct }}%</td>
                            <td>
                                <a class="btn sm" href="{{ route('erp.targets.annual.rep', ['user' => $row['user']->id, 'year' => $year]) }}">
                                    🏪 {{ __('targets.clients_split') }}
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:18px">{{ __('targets.no_reps') }}</td></tr>
                    @endforelse

                    @if (count($b['rows']) > 0)
                        <tfoot>
                            <tr>
                                <td style="text-align:start"><b>Σ {{ __('targets.total_row') }}</b></td>
                                <td class="num"><b>{{ $fmt($bSumAmt) }}</b></td>
                                <td class="num"><b>{{ $fmt($bSumAch) }}</b></td>
                                <td class="num {{ $bSumRem <= 0 ? 'pos' : 'neg' }}"><b>{{ $fmt($bSumRem) }}</b></td>
                                <td></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
            <div style="display:flex;align-items:center;gap:10px;margin-top:10px">
                <span class="sp-hint badge b-gray"></span>
                <button class="btn gold" type="submit" style="margin-inline-start:auto">💾 {{ __('common.save') }}</button>
            </div>
        </form>
    </div>
@endforeach

{{-- ═══ ديالوج إنشاء/تعديل تارجيت الشركة ═══ --}}
@if ($isAdmin)
    <dialog id="dlgCompany">
        <form class="dlg" method="POST" action="{{ route('erp.targets.annual.company') }}">
            @csrf
            <h4>{{ $company ? __('targets.edit_company') : __('targets.create_for', ['year' => $year]) }}</h4>
            <input type="hidden" name="year" value="{{ $year }}">
            <div class="frow">
                <div>
                    <label class="f">{{ __('targets.annual_amount') }}</label>
                    <input type="number" name="amount" step="0.01" min="0" dir="ltr" required
                           value="{{ $company ? (float) $company->amount : '' }}">
                </div>
            </div>
            <p style="font-size:11px;color:var(--muted);margin:8px 0 0">{{ __('targets.company_dlg_hint') }}</p>
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
                <button class="btn" type="button" onclick="closeDlg('dlgCompany')">{{ __('common.cancel') }}</button>
                <button class="btn gold" type="submit">💾 {{ __('common.save') }}</button>
            </div>
        </form>
    </dialog>
@endif

@endsection

@section('scripts')
@php
    $tjsPayload = json_encode([
        'distributed' => __('targets.distributed'),
        'of' => __('targets.of'),
        'left' => __('targets.left'),
        'over' => __('targets.over'),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
@endphp
<script>
(function () {
  var TJS = {!! $tjsPayload !!};

  function fmt(n) {
    return (Math.round(n * 100) / 100).toLocaleString('en-US', {
      minimumFractionDigits: 2, maximumFractionDigits: 2
    });
  }

  // تعديل تارجيت شهر — كل خانة شايلة راوتها في data-action
  // (جريد الشركة وأكورديونات المديرين بيشاركوا نفس الفورم المخفي)
  window.rbSubmit = function (el) {
    var f = document.getElementById('rbForm');
    if (!f) return;
    var act = el.getAttribute('data-action');
    if (act) f.setAttribute('action', act);
    document.getElementById('rbMonth').value = el.getAttribute('data-month');
    document.getElementById('rbAmount').value = el.value === '' ? '0' : el.value;
    f.submit();
  };

  // أكورديون شهور المدير — فتح/قفل من غير أي تنقل
  window.tgAcc = function (btn, id) {
    var row = document.getElementById(id);
    if (!row) return;
    var opening = row.hasAttribute('hidden');
    if (opening) {
      row.removeAttribute('hidden');
    } else {
      row.setAttribute('hidden', '');
    }
    btn.textContent = opening ? '▲' : '▼';
  };

  // كروت التوزيع: ربط % بالمبلغ + تلميح «الموزَّع من الإجمالي»
  function bindSplit(form) {
    var table = form.querySelector('table[data-annual]');
    var hint = form.querySelector('.sp-hint');
    if (!table || !hint) return;

    var annual = parseFloat(table.getAttribute('data-annual') || '0') || 0;
    var amts = Array.prototype.slice.call(table.querySelectorAll('input.sp-amt'));
    var pcts = Array.prototype.slice.call(table.querySelectorAll('input.sp-pct'));

    function refresh() {
      var sum = 0;
      amts.forEach(function (i) { sum += parseFloat(i.value || '0') || 0; });
      var left = Math.round((annual - sum) * 100) / 100;
      hint.textContent = TJS.distributed + ': ' + fmt(sum) + ' ' + TJS.of + ' ' + fmt(annual)
        + ' — ' + (left >= 0 ? TJS.left : TJS.over) + ' ' + fmt(Math.abs(left));
      hint.className = 'sp-hint badge ' + (Math.abs(left) < 0.01 ? 'b-green' : (left > 0 ? 'b-orange' : 'b-red'));
    }

    amts.forEach(function (a, idx) {
      a.addEventListener('input', function () {
        if (pcts[idx] && annual > 0) {
          pcts[idx].value = ((parseFloat(a.value || '0') || 0) / annual * 100).toFixed(1);
        }
        refresh();
      });
    });

    pcts.forEach(function (p, idx) {
      p.addEventListener('input', function () {
        if (amts[idx]) {
          amts[idx].value = (annual * (parseFloat(p.value || '0') || 0) / 100).toFixed(2);
        }
        refresh();
      });
    });

    refresh();
  }

  document.querySelectorAll('form[data-split]').forEach(bindSplit);
})();
</script>
@endsection
