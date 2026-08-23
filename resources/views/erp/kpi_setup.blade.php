@extends('layouts.system')

@section('title', __('kpi.setup_title'))

{{-- ═══ إعدادات العمولات والـKPI (٢٣ أغسطس ٢٠٢٦) — شيت Setup كشاشة ═══
     كل قيمة في النموذج قابلة للتعديل: نسب القنوات وتارجتاتها، سياسة
     الحافز، شرائح المعامل والنسب، أوزان ومستهدفات المؤشرات، وأصناف
     التركيز — مع فحوصات حية (Σ الأوزان = 100 · سقف القناة ≤ 3%). --}}

@php
    $fmt = fn ($n) => number_format((float) $n);
    // النسب بتتعرض % للبني آدم وبتتخزن كسور — التحويل في الجافاسكربت
    $p100 = fn ($v) => rtrim(rtrim(number_format((float) $v * 100, 3), '0'), '.');
@endphp

@section('actions')
    <a class="btn" href="{{ route('erp.kpi') }}">← {{ __('kpi.title') }}</a>
@endsection

@section('content')

<form method="POST" action="{{ route('erp.kpi.setup.save') }}" id="kpiSetupForm"
      onsubmit="return kpiPrepare()">
    @csrf

    {{-- ═══ ١. القنوات — النسب الأساسية والتارجتات ═══ --}}
    <div class="card">
        <h3>🎯 {{ __('kpi.channels_title') }} <span class="side">{{ __('kpi.channels_sub') }}</span></h3>
        <div class="tablewrap">
            <table>
                <thead><tr>
                    <th style="text-align:start">{{ __('kpi.c_channel') }}</th>
                    <th>{{ __('kpi.ch_manager') }}</th>
                    <th class="num">{{ __('kpi.rep_gate') }}</th>
                    <th class="num">{{ __('kpi.rep_rate') }} %</th>
                    <th class="num">{{ __('kpi.manager_gate') }}</th>
                    <th class="num">{{ __('kpi.manager_rate') }} %</th>
                    <th class="num">{{ __('kpi.director_gate') }}</th>
                    <th class="num">{{ __('kpi.director_rate') }} %</th>
                    <th class="num">{{ __('kpi.max_cost') }}</th>
                </tr></thead>
                <tbody>
                @foreach ($channels as $i => $ch)
                    <tr>
                        <td style="text-align:start"><b>{{ $ch->displayName() }}</b>
                            <input type="hidden" name="channels[{{ $i }}][id]" value="{{ $ch->id }}"></td>
                        <td>
                            <select name="channels[{{ $i }}][manager_id]">
                                <option value="">—</option>
                                @foreach ($managers as $m)
                                    <option value="{{ $m->id }}" @selected($ch->manager_id === $m->id)>{{ $m->displayName() }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td class="num"><input type="number" step="1000" min="0" dir="ltr" style="width:120px"
                            name="channels[{{ $i }}][rep_gate]" value="{{ 0 + $ch->rep_gate }}"></td>
                        <td class="num"><input type="number" step="0.05" min="0" max="10" dir="ltr" style="width:80px"
                            class="pctIn chCost" data-name="channels[{{ $i }}][rep_max_rate]" data-ch="{{ $i }}"
                            value="{{ $p100($ch->rep_max_rate) }}"></td>
                        <td class="num"><input type="number" step="1000" min="0" dir="ltr" style="width:120px"
                            name="channels[{{ $i }}][manager_gate]" value="{{ 0 + $ch->manager_gate }}"></td>
                        <td class="num"><input type="number" step="0.05" min="0" max="10" dir="ltr" style="width:80px"
                            class="pctIn chCost" data-name="channels[{{ $i }}][manager_rate]" data-ch="{{ $i }}"
                            value="{{ $p100($ch->manager_rate) }}"></td>
                        <td class="num"><input type="number" step="1000" min="0" dir="ltr" style="width:120px"
                            name="channels[{{ $i }}][director_gate]" value="{{ 0 + $ch->director_gate }}"></td>
                        <td class="num"><input type="number" step="0.05" min="0" max="10" dir="ltr" style="width:80px"
                            class="pctIn chCost" data-name="channels[{{ $i }}][director_rate]" data-ch="{{ $i }}"
                            value="{{ $p100($ch->director_rate) }}"></td>
                        <td class="num"><span class="badge b-green" id="chCost{{ $i }}">—</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="dash-hint">{{ __('kpi.h_channels_setup') }}</div>
    </div>

    {{-- ═══ ٢. سياسة الحافز ═══ --}}
    <div class="card">
        <h3>⚡ {{ __('kpi.policy_title') }} <span class="side">{{ __('kpi.policy_sub') }}</span></h3>
        <div class="searchbar" style="flex-wrap:wrap;align-items:flex-end">
            <div><label class="f">{{ __('kpi.p_rep_rate') }} %</label>
                <input type="number" step="0.05" min="0" max="10" dir="ltr" class="pctIn" style="width:90px"
                       data-name="policy[rep_rate]" value="{{ $p100($policy['rep_rate']) }}"></div>
            <div><label class="f">{{ __('kpi.p_manager_rate') }} %</label>
                <input type="number" step="0.05" min="0" max="10" dir="ltr" class="pctIn" style="width:90px"
                       data-name="policy[manager_rate]" value="{{ $p100($policy['manager_rate']) }}"></div>
            <div><label class="f">{{ __('kpi.p_director_rate') }} %</label>
                <input type="number" step="0.05" min="0" max="10" dir="ltr" class="pctIn" style="width:90px"
                       data-name="policy[director_rate]" value="{{ $p100($policy['director_rate']) }}"></div>
            <div><label class="f">{{ __('kpi.p_min_score') }}</label>
                <input type="number" step="1" min="0" max="100" dir="ltr" style="width:90px"
                       name="policy[min_score]" value="{{ 0 + $policy['min_score'] }}"></div>
            <div><label class="f">{{ __('kpi.p_gate') }} %</label>
                <input type="number" step="1" min="0" max="100" dir="ltr" class="pctIn" style="width:90px"
                       data-name="policy[gate]" value="{{ $p100($policy['gate']) }}"></div>
            <div><label class="f">{{ __('kpi.p_require_gate') }}</label>
                <select name="policy[require_gate]" style="width:120px">
                    <option value="1" @selected($policy['require_gate'])>{{ __('common.yes') }}</option>
                    <option value="0" @selected(! $policy['require_gate'])>{{ __('common.no') }}</option>
                </select></div>
        </div>
        <div class="dash-hint">{{ __('kpi.h_policy') }}</div>
    </div>

    {{-- ═══ ٣. الشرائح ═══ --}}
    <div class="dash-grid2" style="grid-template-columns:1fr 1fr">
        <div class="card">
            <h3>📉 {{ __('kpi.mult_title') }} <span class="side">{{ __('kpi.mult_sub') }}</span></h3>
            <div id="multBands">
                @foreach ($multBands as $bi => $b)
                    <div class="kband">
                        <span>{{ __('kpi.score_from') }}</span>
                        <input type="number" step="1" min="0" max="100" dir="ltr" name="mult_bands[{{ $bi }}][from]" value="{{ 0 + $b->from_value }}">
                        <span>←</span>
                        <span>{{ __('kpi.c_mult') }} ×</span>
                        <input type="number" step="0.05" min="0" max="2" dir="ltr" name="mult_bands[{{ $bi }}][value]" value="{{ 0 + $b->value }}">
                    </div>
                @endforeach
            </div>
            <div class="dash-hint">{{ __('kpi.h_mult') }}</div>
        </div>

        <div class="card">
            <h3>📈 {{ __('kpi.rate_title') }} <span class="side">{{ __('kpi.rate_sub') }}</span></h3>
            @php $rbi = 0; @endphp
            @foreach ($channels as $ch)
                <b class="s" style="display:block;margin:6px 0 4px">{{ $ch->displayName() }}</b>
                @foreach ($rateBands->where('kpi_channel_id', $ch->id) as $b)
                    <div class="kband">
                        <input type="hidden" name="rate_bands[{{ $rbi }}][channel_id]" value="{{ $ch->id }}">
                        <span>{{ __('kpi.ach_from') }} %</span>
                        <input type="number" step="1" min="0" max="500" dir="ltr" class="pctIn"
                               data-name="rate_bands[{{ $rbi }}][from]" value="{{ $p100($b->from_value) }}">
                        <span>←</span>
                        <span>{{ __('kpi.c_base_rate') }} %</span>
                        <input type="number" step="0.05" min="0" max="10" dir="ltr" class="pctIn"
                               data-name="rate_bands[{{ $rbi }}][value]" value="{{ $p100($b->value) }}">
                    </div>
                    @php $rbi++; @endphp
                @endforeach
            @endforeach
            <div class="dash-hint">{{ __('kpi.h_rate') }}</div>
        </div>
    </div>

    {{-- ═══ ٤. المؤشرات — المندوب والإدارة ═══ --}}
    @php $mi = 0; @endphp
    @foreach ([['rep', $repMetrics, __('kpi.rep_metrics')], ['leader', $leaderMetrics, __('kpi.leader_metrics')]] as [$scope, $metrics, $title])
        <div class="card">
            <h3>📋 {{ $title }}
                <span class="side">{{ __('kpi.weights_sum') }}:
                    <span class="badge b-green" id="wsum_{{ $scope }}">100</span> / 100</span></h3>
            <div class="tablewrap">
                <table>
                    <thead><tr>
                        <th style="text-align:start">{{ __('kpi.c_metric') }}</th>
                        <th class="num">{{ __('kpi.c_weight') }}</th>
                        <th>{{ __('kpi.c_direction') }}</th>
                        @if ($scope === 'rep')
                            @foreach ($channels as $ch)
                                <th class="num">{{ __('kpi.c_target') }} — {{ $ch->displayName() }}</th>
                            @endforeach
                        @else
                            <th class="num">{{ __('kpi.c_target') }}</th>
                        @endif
                    </tr></thead>
                    <tbody>
                    @foreach ($metrics as $m)
                        <tr>
                            <td style="text-align:start"><b>{{ $m->displayName() }}</b>
                                <div class="s" style="color:var(--muted)" dir="ltr">{{ $m->key }}</div>
                                <input type="hidden" name="metrics[{{ $mi }}][id]" value="{{ $m->id }}"></td>
                            <td class="num"><input type="number" step="0.5" min="0" max="100" dir="ltr" style="width:75px"
                                class="wIn" data-scope="{{ $scope }}"
                                name="metrics[{{ $mi }}][weight]" value="{{ 0 + $m->weight }}"></td>
                            <td>
                                <select name="metrics[{{ $mi }}][direction]" style="width:120px">
                                    <option value="higher" @selected($m->direction === 'higher')>{{ __('kpi.dir_higher') }}</option>
                                    <option value="lower" @selected($m->direction === 'lower')>{{ __('kpi.dir_lower') }}</option>
                                </select>
                            </td>
                            @if ($scope === 'rep')
                                @foreach ($channels as $ci => $ch)
                                    <td class="num">
                                        <input type="number" step="0.01" dir="ltr" style="width:95px"
                                               name="metrics[{{ $mi }}][targets][{{ $ch->id }}]"
                                               value="{{ 0 + $m->targetFor($ch->id) }}">
                                        @if ($ci === 0)
                                            <input type="hidden" name="metrics[{{ $mi }}][target]" value="{{ 0 + $m->targetFor($ch->id) }}">
                                        @endif
                                    </td>
                                @endforeach
                            @else
                                <td class="num"><input type="number" step="0.01" dir="ltr" style="width:95px"
                                    name="metrics[{{ $mi }}][target]" value="{{ 0 + $m->target }}"></td>
                            @endif
                        </tr>
                        @php $mi++; @endphp
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach

    {{-- ═══ ٥. أصناف التركيز — مؤشر المزيج ═══ --}}
    <div class="card">
        <h3>🍫 {{ __('kpi.focus_title') }} <span class="side">{{ __('kpi.focus_sub') }}</span></h3>
        <input type="search" id="focusSearch" style="width:100%;margin-bottom:8px"
               placeholder="🔍 {{ __('common.search') }}…" oninput="focusFilter(this.value)">
        <div class="kfocus" id="focusList">
            @foreach ($focusProducts as $p)
                <label class="kf" data-txt="{{ mb_strtolower($p->name.' '.$p->name_en.' '.$p->code) }}">
                    <input type="checkbox" name="focus_ids[]" value="{{ $p->id }}" @checked($p->is_focus)>
                    <span>{{ app()->getLocale() === 'ar' ? $p->name : ($p->name_en ?: $p->name) }}</span>
                    <i>{{ $p->code }}</i>
                </label>
            @endforeach
        </div>
        <div class="dash-hint">{{ __('kpi.h_focus') }}</div>
    </div>

    <button class="btn gold" type="submit" style="width:100%;padding:14px;font-size:14px">
        💾 {{ __('kpi.save_all') }}
    </button>
</form>

@endsection

@section('scripts')
<style>
.kband{display:flex;align-items:center;gap:8px;margin-bottom:6px;font-size:12px}
.kband input{width:85px}
.kfocus{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:6px;max-height:260px;overflow-y:auto;
  border:1px solid var(--border);border-radius:10px;padding:9px}
.kf{display:flex;align-items:center;gap:7px;font-size:12px;padding:4px 6px;border-radius:7px;cursor:pointer}
.kf:hover{background:var(--blue-050)}
.kf i{font-style:normal;color:var(--muted);font-size:10px;margin-inline-start:auto}
</style>
<script>
    // ═══ فحص سقف القناة الحي — Σ النسب التلاتة، أخضر ≤3% وأحمر فوقها ═══
    function chCostSync() {
        document.querySelectorAll('[id^=chCost]').forEach(function (badge) {
            const i = badge.id.replace('chCost', '');
            let sum = 0;
            document.querySelectorAll('.chCost[data-ch="' + i + '"]').forEach(el => {
                sum += parseFloat(el.value || '0');
            });
            badge.textContent = sum.toFixed(2) + '%';
            badge.className = 'badge ' + (sum <= 3.0001 ? 'b-green' : 'b-red');
        });
    }

    // ═══ فحص الأوزان الحي — لازم 100 بالظبط لكل مجموعة ═══
    function wSync() {
        ['rep', 'leader'].forEach(function (scope) {
            let sum = 0;
            document.querySelectorAll('.wIn[data-scope="' + scope + '"]').forEach(el => {
                sum += parseFloat(el.value || '0');
            });
            const badge = document.getElementById('wsum_' + scope);
            badge.textContent = sum.toFixed(1);
            badge.className = 'badge ' + (Math.abs(sum - 100) < 0.01 ? 'b-green' : 'b-red');
        });
    }

    document.querySelectorAll('.chCost').forEach(el => el.addEventListener('input', chCostSync));
    document.querySelectorAll('.wIn').forEach(el => el.addEventListener('input', wSync));
    chCostSync();
    wSync();

    // بحث أصناف التركيز
    function focusFilter(q) {
        q = q.trim().toLowerCase();
        document.querySelectorAll('.kf').forEach(function (l) {
            l.style.display = !q || (l.dataset.txt || '').includes(q) ? '' : 'none';
        });
    }

    // ═══ قبل الإرسال: النسب المعروضة % بتتحول كسور في حقول مخفية ═══
    // (المستخدم بيكتب 1.5 والداتابيز بتخزن 0.015 — نفس عقيدة القنوات)
    function kpiPrepare() {
        const form = document.getElementById('kpiSetupForm');
        form.querySelectorAll('.pct-h').forEach(e => e.remove());

        let ok = true;
        document.querySelectorAll('.pctIn').forEach(function (el) {
            const inp = document.createElement('input');
            inp.type = 'hidden';
            inp.className = 'pct-h';
            inp.name = el.dataset.name;
            inp.value = (parseFloat(el.value || '0') / 100).toString();
            form.appendChild(inp);
        });

        // منع الحفظ والأوزان مش 100 — نفس فحص السيرفر بس بدري
        ['rep', 'leader'].forEach(function (scope) {
            let sum = 0;
            document.querySelectorAll('.wIn[data-scope="' + scope + '"]').forEach(el => {
                sum += parseFloat(el.value || '0');
            });
            if (Math.abs(sum - 100) > 0.01) ok = false;
        });

        if (!ok) alert(@js(__('kpi.weights_alert')));

        return ok;
    }
</script>
@endsection
