@extends('layouts.system')

@section('title', __('journey.geo_page'))

@php
    $fmt = fn ($n) => number_format((float) $n);
    $me = auth()->user();

    // ⚠️ **`Access::action` مش `Access::allows`** — كتابة خط السير
    // زرار حساس تحت `act.field.plan` زي شاشة التسكين بالظبط.
    // `allows()` بتقرا استثناء **الصفحة** الأول (خطوة ٢ في
    // `userOverride`) وبتخلص قبل ما توصل لاستثناء الأكشن (خطوة ٣)،
    // فمنح `ops.geo` كصفحة كان بيدي زراير الحفظ لواحد الأدمن منع
    // عنه `act.field.plan` صراحةً.
    $canPlan = \App\Support\Access::action($me, 'act.field.plan');
    $canUnplan = $canPlan;

    $dayOpts = [];
    foreach (\App\Models\JourneyPlan::WEEKDAYS as $w) {
        $dayOpts[] = ['v' => $w, 't' => __('journey.day_'.$w)];
    }

    $freqOpts = [];
    foreach (\App\Models\JourneyPlan::FREQUENCIES as $f) {
        $freqOpts[] = ['v' => $f, 't' => __('journey.freq_'.$f)];
    }

    // ⚠️ حمولة واحدة بـ json_encode هنا — دايركتيف الجيسون بمصفوفة
    // متعددة السطور بيكسّر بارسر بليد، والفلاجات بتمنع أي وسم أو
    // كوتيشن يتسرّب جوه بلوك السكربت.
    $geoJs = json_encode([
        'zoneUrl' => route('ops.geo.zone', ['zone' => '__ZID__']),
        'planUrl' => route('ops.geo.plan'),
        'unplanUrl' => route('ops.geo.unplan'),
        'manager' => $picked?->id,
        // ⚠️ نفس فلتر البحث بيتبعت للوحة — الأعداد في الشجرة مفلترة
        // بيه، ولوحة مش مفلترة بتخلّي «اختيار الكل» يخطّط لمحلات
        // اللي بيبص مشافهاش
        'q' => $filters['q'],
        'canPlan' => $canPlan,
        'canUnplan' => $canUnplan,
        'days' => $dayOpts,
        'freqs' => $freqOpts,
        'next' => $nextDue,
        't' => [
            'loading' => __('common.loading'),
            'fail' => __('common.something_wrong'),
            'saving' => __('common.loading'),
            'none' => __('journey.geo_none_picked'),
            'empty' => __('journey.geo_zone_empty'),
            'notPlanned' => __('journey.geo_not_planned'),
            'noReps' => __('journey.geo_no_reps_for'),
            'noLoc' => __('journey.geo_no_location_badge'),
            'remove' => __('journey.geo_remove'),
            'removeConfirm' => __('journey.geo_remove_confirm'),
            'firstVisit' => __('journey.geo_first_visit'),
            'foreign' => __('journey.geo_plan_other_team'),
            'client' => __('journey.geo_client'),
            'code' => __('journey.geo_code'),
            'category' => __('journey.geo_category'),
            'status' => __('journey.geo_status'),
            'rep' => __('journey.geo_rep'),
            'day' => __('journey.geo_day'),
            'freq' => __('journey.geo_freq'),
            'include' => __('journey.geo_include'),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
@endphp

@section('actions')
    <a class="btn" href="{{ route('ops.assignments') }}">👥 {{ __('nav.assignments') }}</a>
    <a class="btn" href="{{ route('ops.journeys') }}">🗺️ {{ __('journey.page') }}</a>
@endsection

@section('content')

{{-- رسالة نجاح الحفظ بعد الريلود — بتتخزن في sessionStorage قبل
     ما الصفحة تتحمّل من أول، لأن الحفظ AJAX مفيهوش flash سيرفر. --}}
<div class="alert good" id="geoFlash" style="display:none"></div>

{{-- ═══════════════════════ شريط الأرقام ═══════════════════════ --}}
<div class="kpis">
    <div class="kpi">
        <div class="lbl">{{ __('journey.geo_kpi_clients') }}</div>
        <div class="val num">{{ $fmt($total) }}</div>
        <div class="sub2">{{ $picked?->displayName() ?? __('journey.geo_all_teams') }}</div>
    </div>
    <div class="kpi">
        <div class="lbl">{{ __('journey.geo_kpi_govs') }}</div>
        <div class="val num">{{ $fmt($govCount) }}</div>
        <div class="sub2">{{ __('journey.geo_kpi_zones') }}: {{ $fmt($zoneCount) }}</div>
    </div>
    <div class="kpi {{ $noPlanTotal > 0 ? 'mid' : 'pos' }}">
        <div class="lbl">{{ __('journey.geo_kpi_noplan') }}</div>
        <div class="val num">{{ $fmt($noPlanTotal) }}</div>
        <div class="sub2">{{ __('journey.geo_unplanned') }}</div>
    </div>
    <div class="kpi {{ $noRepTotal > 0 ? 'neg' : 'pos' }}">
        <div class="lbl">{{ __('journey.geo_kpi_norep') }}</div>
        <div class="val num">{{ $fmt($noRepTotal) }}</div>
        <div class="sub2"><a href="{{ route('ops.assignments', ['only' => 'orphans']) }}">{{ __('journey.geo_fix_rep') }}</a></div>
    </div>
    <div class="kpi {{ $noLocTotal > 0 ? 'mid' : 'pos' }}">
        <div class="lbl">{{ __('journey.geo_kpi_noloc') }}</div>
        <div class="val num">{{ $fmt($noLocTotal) }}</div>
        <div class="sub2"><a href="{{ route('erp.client_locations', ['show' => 'no_location']) }}">{{ __('journey.geo_fix_loc') }}</a></div>
    </div>
</div>

{{-- ═══════════════════════ فرق المديرين ═══════════════════════ --}}
<div class="card">
    <h3>👔 {{ __('journey.geo_teams') }} <span class="side">{{ __('journey.geo_sub') }}</span></h3>
    <div class="alert info">{{ __('journey.geo_teams_hint') }}</div>

    @if ($teams === [])
        <div class="alert warn">{{ __('journey.geo_no_teams') }}</div>
    @else
        @if ($picked !== null)
            <div class="alert warn">
                {{ __('journey.geo_filtered_on', ['name' => $picked->displayName()]) }}
                — <a href="{{ route('ops.geo', array_filter(['q' => $filters['q']])) }}">{{ __('journey.geo_clear_filter') }}</a>
            </div>
        @endif

        <div class="geo-teams">
            @foreach ($teams as $t)
                {{-- الكارت كله لينك: الفلترة بباراميتر عشان الأرقام
                     تحت تتحسب في السيرفر وتفضل صادقة مع 670+ عميل --}}
                <a class="geo-team {{ $picked && $picked->id === $t['manager']->id ? 'on' : '' }}"
                   href="{{ route('ops.geo', array_filter(['manager' => $t['manager']->id, 'q' => $filters['q']])) }}">
                    <div class="geo-team-head">
                        @include('partials._avatar', ['u' => $t['manager'], 'size' => 42, 'ring' => '#602D90'])
                        <div style="min-width:0">
                            <b style="display:block">{{ $t['manager']->displayName() }}</b>
                            <span class="s" style="color:var(--muted)">{{ $t['manager']->roleLabel() }}</span>
                        </div>
                        <div class="geo-team-n num">{{ $fmt($t['clients']) }}</div>
                    </div>

                    <div class="geo-team-kpis">
                        <span class="badge b-green">🏪 {{ $fmt($t['clients']) }} {{ __('journey.geo_team_clients') }}</span>
                        <span class="badge b-blue">🗺️ {{ $fmt($t['govs']) }} {{ __('journey.geo_team_govs') }}</span>
                        <span class="badge b-purple">📍 {{ $fmt($t['zones']) }} {{ __('journey.geo_team_zones') }}</span>
                    </div>

                    <div class="geo-chips">
                        @forelse ($t['reps'] as $tr)
                            <span class="geo-chip">
                                @include('partials._avatar', ['u' => $tr['user'], 'size' => 24])
                                <span>{{ $tr['user']->displayName() }}</span>
                                <b class="num">{{ $fmt($tr['clients']) }}</b>
                            </span>
                        @empty
                            <span class="s" style="color:var(--muted)">{{ __('journey.pool_no_reps') }}</span>
                        @endforelse

                        @if ($t['own'] > 0)
                            <span class="geo-chip" style="border-color:#602D90">
                                @include('partials._avatar', ['u' => $t['manager'], 'size' => 24])
                                <span>{{ __('journey.geo_manager_own') }}</span>
                                <b class="num">{{ $fmt($t['own']) }}</b>
                            </span>
                        @endif

                        {{-- ⚠️ شيب «بدون مندوب أساسي» لازم يبان — من غيره
                             مجموع الشيبس مايطابقش الرقم الكبير فوق واللي
                             بيقرا يفتكر فيه أرقام ضايعة. --}}
                        @if ($t['no_rep'] > 0)
                            <span class="geo-chip" style="border-color:#EA8C1C">
                                <span>⚠️ {{ __('journey.no_rep') }}</span>
                                <b class="num">{{ $fmt($t['no_rep']) }}</b>
                            </span>
                        @endif
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>

{{-- ═══════════════════════ الشجرة الجغرافية ═══════════════════════ --}}
<div class="card">
    <h3>🧭 {{ __('journey.geo_tree') }} <span class="side">{{ $fmt($total) }} {{ __('journey.geo_shops') }}</span></h3>
    <div class="alert info">{{ __('journey.geo_tree_hint') }}</div>

    {{-- ⚠️ فورم GET عشان Enter يبعت البحث للسيرفر (اسم/كود المحل
         مش موجود في الشجرة أصلاً)، و oninput بيفلتر المحافظات
         والمناطق المعروضة فوراً من غير رحلة للسيرفر. --}}
    <form method="GET" action="{{ route('ops.geo') }}" class="searchbar">
        @if ($picked !== null)
            <input type="hidden" name="manager" value="{{ $picked->id }}">
        @endif
        <div style="flex:1;min-width:260px">
            <label class="f">{{ __('common.search') }}</label>
            <input type="search" name="q" value="{{ $filters['q'] }}" style="width:100%"
                   placeholder="{{ __('journey.geo_search_ph') }}" oninput="geoTreeFilter(this.value)">
        </div>
        <button class="btn gold" type="submit">🔍 {{ __('common.search') }}</button>
        @if ($filters['q'] !== '')
            <a class="btn" href="{{ route('ops.geo', array_filter(['manager' => $picked?->id])) }}">{{ __('common.clear') }}</a>
        @endif
    </form>

    @if ($tree === [])
        <div class="alert warn">{{ __('journey.geo_empty') }}</div>
    @else
        <div class="geo-tree">
            @foreach ($tree as $g)
                @php $pct = $total > 0 ? round($g['clients'] / $total * 100, 1) : 0; @endphp
                <details class="geo-gov" data-txt="{{ mb_strtolower($g['label'].' '.$g['key']) }}">
                    <summary>
                        <span class="zg-plus" aria-hidden="true"></span>
                        <span class="geo-gname">{{ $g['label'] }}</span>
                        <span class="badge b-green">🏪 {{ $fmt($g['clients']) }}</span>
                        <span class="badge b-gray">📍 {{ count($g['zones']) }} {{ __('journey.geo_zones_n') }}</span>
                        @if ($g['no_plan'] > 0)
                            <span class="badge b-orange">🕳️ {{ $fmt($g['no_plan']) }} {{ __('journey.geo_unplanned') }}</span>
                        @endif
                        <span class="geo-bar" title="{{ __('journey.geo_share') }}"><i style="width:{{ $pct }}%"></i></span>
                        <span class="s num geo-pct">{{ $pct }}%</span>
                    </summary>

                    {{-- ⚠️ `data-plain`: أدوات الجداول العامة (بحث/ترتيب/
                         صف إجماليات) بتتطبق على كل `.tablewrap`، وهنا
                         الجدول جوه أكورديون محافظة — يعني خانة بحث
                         وصف إجماليات مكررين لكل محافظة. البحث موحّد
                         فوق، والإجماليات في رأس المحافظة نفسه. --}}
                    <div class="tablewrap">
                        <table data-plain>
                            <tr>
                                <th>{{ __('geo.zone') }}</th>
                                <th data-nosum>{{ __('journey.geo_shops') }}</th>
                                <th data-nosum>{{ __('journey.geo_unplanned') }}</th>
                                <th data-nosum>{{ __('journey.geo_covered_by') }}</th>
                                <th data-nosum></th>
                            </tr>
                            @foreach ($g['zones'] as $z)
                                <tr class="geo-zrow" data-txt="{{ mb_strtolower($z['name'].' '.($z['code'] ?? '')) }}">
                                    <td>
                                        <b>{{ $z['name'] }}</b>
                                        @if ($z['code'])
                                            <span class="s" style="color:var(--muted)">{{ $z['code'] }}</span>
                                        @endif
                                        @if ($z['id'] === null)
                                            <div class="s" style="color:var(--orange,#EA8C1C)">{{ __('journey.geo_no_zone_hint') }}</div>
                                        @endif
                                    </td>
                                    <td class="num"><b>{{ $fmt($z['clients']) }}</b></td>
                                    <td class="num">
                                        @if ($z['no_plan'] > 0)
                                            <span class="badge b-orange">{{ $fmt($z['no_plan']) }}</span>
                                        @else
                                            <span class="badge b-green">✓</span>
                                        @endif
                                    </td>
                                    <td>
                                        @forelse ($z['reps'] as $zr)
                                            <span class="geo-chip">
                                                @include('partials._avatar', ['u' => $zr['user'], 'size' => 22])
                                                <span>{{ $zr['user']->displayName() }}</span>
                                                <b class="num">{{ $fmt($zr['n']) }}</b>
                                            </span>
                                        @empty
                                            <span class="badge b-gray">{{ __('journey.no_rep') }}</span>
                                        @endforelse
                                    </td>
                                    <td class="num">
                                        @if ($z['id'] !== null)
                                            <button type="button" class="btn sm gold"
                                                    onclick="geoOpenZone({{ $z['id'] }}, this.dataset.zn)"
                                                    data-zn="{{ $z['name'] }}">
                                                🧭 {{ __('journey.geo_plan_btn') }}
                                            </button>
                                        @else
                                            <span class="s" style="color:var(--muted)">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </table>
                    </div>
                </details>
            @endforeach
        </div>
    @endif
</div>

{{-- ═══════════════════════ لوحة المنطقة ═══════════════════════ --}}
<dialog id="zoneDlg" class="geo-dlg">
    <div class="dlg">
        <h4>🧭 {{ __('journey.geo_panel') }} — <span id="zoneTitle">—</span>
            <span class="side s" id="zoneSub"></span></h4>

        <div class="alert" id="zoneMsg" style="display:none"></div>

        <div id="zoneBody" style="max-height:58vh;overflow:auto"></div>

        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:12px">
            @if ($canPlan)
                <label style="font-size:12.5px;display:inline-flex;align-items:center;gap:7px">
                    <input type="checkbox" id="zonePickAll" onchange="geoPickAll(this)">
                    {{ __('journey.geo_select_all') }}
                </label>
            @endif
            <div style="margin-inline-start:auto;display:flex;gap:8px">
                <button class="btn" type="button" onclick="closeDlg('zoneDlg')">{{ __('common.close') }}</button>
                @if ($canPlan)
                    <button class="btn gold" type="button" id="zoneSave" onclick="geoSaveRoute()">
                        💾 {{ __('journey.geo_save') }}
                    </button>
                @endif
            </div>
        </div>
    </div>
</dialog>

@endsection

@section('scripts')
<style>
    /* ═══ كروت فرق المديرين ═══ */
    .geo-teams{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px}
    .geo-team{display:block;padding:12px;border:1px solid var(--border);border-radius:12px;
      background:var(--card);text-decoration:none;color:inherit}
    .geo-team:hover{box-shadow:var(--shadow)}
    .geo-team.on{border-color:var(--royal-blue);box-shadow:0 0 0 2px rgba(18,57,155,.14)}
    .geo-team-head{display:flex;align-items:center;gap:10px;margin-bottom:9px}
    .geo-team-n{margin-inline-start:auto;font-weight:900;font-size:20px;color:var(--royal-blue)}
    .geo-team-kpis{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:9px}
    .geo-chips{display:flex;gap:6px;flex-wrap:wrap}
    .geo-chip{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--border);
      border-radius:20px;padding:2px 9px 2px 3px;font-size:12px;background:var(--card2)}
    [dir=rtl] .geo-chip{padding:2px 3px 2px 9px}
    .geo-chip b{color:var(--royal-blue)}

    /* ═══ الشجرة — نفس أسلوب أكورديون المناطق في شاشة التسكين ═══ */
    .geo-gov{border-bottom:1px solid var(--border)}
    .geo-gov:last-child{border-bottom:none}
    .geo-gov>summary{display:flex;align-items:center;gap:8px;padding:9px 3px;font-size:13px;
      font-weight:900;color:var(--royal-blue);cursor:pointer;list-style:none;user-select:none;border-radius:7px}
    .geo-gov>summary::-webkit-details-marker{display:none}
    .geo-gov>summary:hover{background:var(--card2)}
    .geo-gname{min-width:120px}
    .zg-plus{width:18px;height:18px;flex-shrink:0;display:grid;place-items:center;
      border:1px solid var(--border);border-radius:6px;background:var(--card);font-size:13px;line-height:1}
    .zg-plus::before{content:'+'}
    .geo-gov[open] .zg-plus::before{content:'-'}
    .geo-bar{margin-inline-start:auto;width:120px;height:8px;border-radius:6px;
      background:var(--card2);overflow:hidden;flex-shrink:0}
    .geo-bar>i{display:block;height:100%;background:linear-gradient(135deg,#12399B 0%,#602D90 100%)}
    .geo-pct{width:48px;text-align:end;color:var(--muted);font-weight:700}

    /* ═══ لوحة المنطقة ═══
       ⚠️ العرض على الابن المباشر مش على الـdialog — القاعدة العامة
       `dialog>div{width:min(620px,92vw)}` بتغلب أي عرض على الغلاف،
       واللوحة فيها 8 أعمدة مالهاش تتزنق في 620px. */
    .geo-dlg>.dlg{width:min(1120px,96vw)}
    .geo-dlg select{min-width:118px}
    .geo-next{font-size:11.5px;color:var(--muted);white-space:nowrap}
</style>
<script>
(function () {
    'use strict';

    var GEO = {!! $geoJs !!};

    var body = document.getElementById('zoneBody');
    var titleEl = document.getElementById('zoneTitle');
    var subEl = document.getElementById('zoneSub');
    var msgEl = document.getElementById('zoneMsg');
    var saveBtn = document.getElementById('zoneSave');

    var rows = [];   // حالة الصفوف المعروضة دلوقتي

    function say(text, kind) {
        msgEl.className = 'alert' + (kind ? ' ' + kind : '');
        msgEl.textContent = text || '';
        msgEl.style.display = text ? '' : 'none';
    }

    function el(tag, cls, txt) {
        var e = document.createElement(tag);
        if (cls) { e.className = cls; }
        if (txt !== undefined && txt !== null) { e.textContent = txt; }
        return e;
    }

    /* ⚠️ `data-nosearch` عن قصد: مُحسّن السيلكت العام بيشتغل مرة واحدة
       على DOMContentLoaded، والسيلكتات دي بتتولد بعد الفتح — فكانت
       هتفضل خام جنب سيلكتات متحسّنة والشكل يتفرّق. وكمان لوحة بحث
       عايمة جوه جدول بيتسكرول تجربة وحشة. */
    function mkSelect(opts, value) {
        var s = document.createElement('select');
        s.dataset.nosearch = '1';
        opts.forEach(function (o) {
            var op = document.createElement('option');
            op.value = o.v;
            op.textContent = o.t;
            if (String(o.v) === String(value)) { op.selected = true; }
            s.appendChild(op);
        });
        return s;
    }

    function nextLabel(day, freq) {
        return GEO.next[day + '-' + freq] || '-';
    }

    function render(data) {
        rows = [];
        body.innerHTML = '';

        // ⚠️ تصفير «اختيار الكل» مع كل فتح — من غيره اللوحة الجديدة
        // بتفتح والمربع متعلّم وكل الصفوف فاضية، فالمستخدم لازم
        // يدوس مرتين عشان يعلّم
        var all = document.getElementById('zonePickAll');
        if (all) { all.checked = false; }

        subEl.textContent = data.zone.governorate + ' · ' + data.zone.code
            + ' · ' + data.clients.length;

        if (!data.clients.length) {
            body.appendChild(el('div', 'alert warn', GEO.t.empty));
            return;
        }

        var wrap = el('div', 'tablewrap');
        var table = el('table');
        var head = document.createElement('tr');
        var cols = [];

        if (GEO.canPlan) { cols.push(GEO.t.include); }
        cols.push(GEO.t.client, GEO.t.code, GEO.t.category, GEO.t.status);
        if (GEO.canPlan) { cols.push(GEO.t.rep, GEO.t.day, GEO.t.freq, GEO.t.firstVisit); }
        cols.push('');

        cols.forEach(function (c) {
            var th = el('th', null, c);
            th.setAttribute('data-nosum', '1');
            head.appendChild(th);
        });
        table.appendChild(head);

        data.clients.forEach(function (c) {
            var tr = document.createElement('tr');
            var state = { id: c.id, pick: null, rep: null, day: null, freq: null, plans: c.plans };
            var plan = c.plans.length ? c.plans[0] : null;

            if (GEO.canPlan) {
                var tdPick = el('td');
                var pick = document.createElement('input');
                pick.type = 'checkbox';
                pick.className = 'geo-pick';
                pick.disabled = !c.reps.length;
                state.pick = pick;
                tdPick.appendChild(pick);
                tr.appendChild(tdPick);
            }

            var tdName = el('td');
            tdName.appendChild(el('b', null, c.name));
            if (!c.has_location) {
                tdName.appendChild(document.createTextNode(' '));
                tdName.appendChild(el('span', 'badge b-orange', GEO.t.noLoc));
            }
            tr.appendChild(tdName);

            tr.appendChild(el('td', 's', c.code));

            var tdCat = el('td');
            tdCat.appendChild(el('span', 'badge ' + c.category_class, c.category));
            tr.appendChild(tdCat);

            var tdStatus = el('td');
            if (plan) {
                c.plans.forEach(function (p) {
                    tdStatus.appendChild(el('span', 'badge b-green', p.label));
                    tdStatus.appendChild(document.createElement('br'));
                });
            } else if (!c.foreign.length) {
                tdStatus.appendChild(el('span', 'badge b-gray', GEO.t.notPlanned));
            }
            // خطط فرق تانية — للعلم بس، مفيش زرار شيل ولا تعديل
            c.foreign.forEach(function (lbl) {
                var b = el('span', 'badge b-purple', '🔒 ' + lbl);
                b.title = GEO.t.foreign;
                tdStatus.appendChild(b);
                tdStatus.appendChild(document.createElement('br'));
            });
            tr.appendChild(tdStatus);

            if (GEO.canPlan) {
                var tdRep = el('td');
                if (c.reps.length) {
                    // الافتراضي: مندوب الخطة الحالية، وإلا المسؤول
                    // الأساسي، وإلا أول مندوب مسموح
                    var want = plan ? plan.user_id : c.rep_id;
                    state.rep = mkSelect(
                        c.reps.map(function (r) { return { v: r.id, t: r.name }; }),
                        want
                    );
                    tdRep.appendChild(state.rep);
                } else {
                    tdRep.appendChild(el('span', 'badge b-red', GEO.t.noReps));
                }
                tr.appendChild(tdRep);

                var tdDay = el('td');
                state.day = mkSelect(GEO.days, plan ? plan.weekday : GEO.days[0].v);
                tdDay.appendChild(state.day);
                tr.appendChild(tdDay);

                var tdFreq = el('td');
                state.freq = mkSelect(GEO.freqs, plan ? plan.every_weeks : GEO.freqs[0].v);
                tdFreq.appendChild(state.freq);
                tr.appendChild(tdFreq);

                var tdNext = el('td');
                var nextEl = el('span', 'geo-next', nextLabel(state.day.value, state.freq.value));
                tdNext.appendChild(nextEl);
                tr.appendChild(tdNext);

                // معاينة «أول زيارة» بتتحدث مع أي تغيير — الخريطة
                // محسوبة في السيرفر من dueOn نفسها، فمفيش منطق تردد
                // متكرر في الجافاسكربت
                var sync = function () {
                    nextEl.textContent = nextLabel(state.day.value, state.freq.value);
                    if (state.pick && !state.pick.disabled) { state.pick.checked = true; }
                };
                state.day.addEventListener('change', sync);
                state.freq.addEventListener('change', sync);
                if (state.rep) { state.rep.addEventListener('change', sync); }
            }

            var tdAct = el('td', 'num');
            if (GEO.canUnplan && c.plans.length) {
                var rm = el('button', 'btn sm', GEO.t.remove);
                rm.type = 'button';
                rm.addEventListener('click', function () {
                    if (!window.confirm(GEO.t.removeConfirm)) { return; }
                    post(GEO.unplanUrl, {
                        plan_ids: c.plans.map(function (p) { return p.id; })
                    });
                });
                tdAct.appendChild(rm);
            }
            tr.appendChild(tdAct);

            table.appendChild(tr);
            rows.push(state);
        });

        wrap.appendChild(table);
        body.appendChild(wrap);
    }

    function openZone(id, name) {
        titleEl.textContent = name;
        subEl.textContent = '';
        body.innerHTML = '';
        rows = [];
        say(GEO.t.loading, 'info');
        openDlg('zoneDlg');

        var url = GEO.zoneUrl.replace('__ZID__', encodeURIComponent(id));
        var qs = [];
        if (GEO.manager) { qs.push('manager=' + encodeURIComponent(GEO.manager)); }
        if (GEO.q) { qs.push('q=' + encodeURIComponent(GEO.q)); }
        if (qs.length) { url += '?' + qs.join('&'); }

        fetch(url, { headers: { 'Accept': 'application/json' } })
            .then(function (r) {
                if (!r.ok) { throw new Error('http'); }
                return r.json();
            })
            .then(function (j) {
                render(j);
                say('');
            })
            .catch(function () { say(GEO.t.fail, 'warn'); });
    }

    function post(url, payload) {
        say(GEO.t.saving, 'info');
        if (saveBtn) { saveBtn.disabled = true; }

        var token = document.querySelector('meta[name="csrf-token"]');

        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': token ? token.content : ''
            },
            body: JSON.stringify(payload)
        })
            .then(function (r) {
                return r.json().catch(function () { return {}; }).then(function (j) {
                    return { ok: r.ok, body: j };
                });
            })
            .then(function (res) {
                if (saveBtn) { saveBtn.disabled = false; }
                if (!res.ok) {
                    say(res.body.message || GEO.t.fail, 'warn');
                    return;
                }
                // ⚠️ ريلود مقصود — الأعداد في الشجرة والـKPIs بتتغير
                // بالحفظ، وتحديثها في المتصفح معناه نسخة تانية من
                // منطق العدّ تفرق عن السيرفر أول تعديل
                try { sessionStorage.setItem('geoFlash', res.body.message || ''); } catch (e) { /* خاص */ }
                window.location.reload();
            })
            .catch(function () {
                if (saveBtn) { saveBtn.disabled = false; }
                say(GEO.t.fail, 'warn');
            });
    }

    function saveRoute() {
        var payload = [];

        rows.forEach(function (r) {
            if (!r.pick || !r.pick.checked || !r.rep) { return; }
            payload.push({
                client_id: r.id,
                user_id: parseInt(r.rep.value, 10),
                weekday: parseInt(r.day.value, 10),
                every_weeks: parseInt(r.freq.value, 10)
            });
        });

        if (!payload.length) { say(GEO.t.none, 'warn'); return; }

        post(GEO.planUrl, { rows: payload });
    }

    function pickAll(box) {
        rows.forEach(function (r) {
            if (r.pick && !r.pick.disabled) { r.pick.checked = box.checked; }
        });
    }

    /* فلترة فورية للشجرة بالاسم — المحافظة أو المنطقة. البحث باسم
       أو كود المحل بيروح للسيرفر بـEnter لأن أسماء المحلات مش
       معروضة في الشجرة أصلاً. */
    function treeFilter(raw) {
        var q = (raw || '').trim().toLowerCase();

        document.querySelectorAll('.geo-gov').forEach(function (d) {
            if (q === '') {
                d.querySelectorAll('.geo-zrow').forEach(function (r) { r.style.display = ''; });
                d.style.display = '';
                if ('wasOpen' in d.dataset) {
                    d.open = d.dataset.wasOpen === '1';
                    delete d.dataset.wasOpen;
                }
                return;
            }

            if (!('wasOpen' in d.dataset)) { d.dataset.wasOpen = d.open ? '1' : '0'; }

            var govHit = (d.dataset.txt || '').indexOf(q) !== -1;
            var any = false;

            d.querySelectorAll('.geo-zrow').forEach(function (r) {
                var hit = govHit || (r.dataset.txt || '').indexOf(q) !== -1;
                r.style.display = hit ? '' : 'none';
                if (hit) { any = true; }
            });

            d.style.display = any ? '' : 'none';
            d.open = any;
        });
    }

    window.geoOpenZone = openZone;
    window.geoSaveRoute = saveRoute;
    window.geoPickAll = pickAll;
    window.geoTreeFilter = treeFilter;

    document.addEventListener('DOMContentLoaded', function () {
        var flash = null;
        try { flash = sessionStorage.getItem('geoFlash'); } catch (e) { flash = null; }
        if (flash) {
            var box = document.getElementById('geoFlash');
            box.textContent = flash;
            box.style.display = '';
            try { sessionStorage.removeItem('geoFlash'); } catch (e) { /* خاص */ }
        }
    });
})();
</script>
@endsection
