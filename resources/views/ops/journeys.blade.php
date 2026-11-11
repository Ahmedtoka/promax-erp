@extends('layouts.system')

@section('title', __('journey.page'))

@section('actions')
    <a class="btn" href="{{ route('ops.geo', array_filter(['rep' => $rep?->id])) }}">🧭 {{ __('journey.builder_btn') }}</a>
    <a class="btn" href="{{ route('ops.journeys.week', ['week' => $weekStart->toDateString()]) }}">👁 {{ __('journey.follow_btn') }}</a>
    <a class="btn" href="{{ route('ops.assignments') }}">👥 {{ __('journey.assignments') }}</a>
    {{-- زرار الخطر: مسح كل الخطط والبدء من أول وجديد. الأدمن بيمسح
         الكل والمدير فريقه بس. آمن على الزيارات: FK بتاع
         visits.journey_plan_id بيصفّر مش بيمسح. --}}
    @if (($wipeCount ?? 0) > 0)
        @php
            $wipeMsg = json_encode(
                __('journey.wipe_confirm', ['count' => number_format($wipeCount)]),
                JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP,
            );
        @endphp
        @if (\App\Support\Access::action(auth()->user(), 'act.field.wipe'))
        <form method="POST" action="{{ route('ops.journeys.wipe') }}" style="display:inline"
              onsubmit="return confirm({!! $wipeMsg !!})">
            @csrf
            <button class="btn red" type="submit">
                🗑 {{ auth()->user()?->role === 'manager' ? __('journey.wipe_team') : __('journey.wipe_all') }}
            </button>
        </form>
        @endif
    @endif
@endsection

@section('content')


@if ($rep === null)
    <div class="card"><div class="alert warn">{{ __('journey.no_reps') }}</div></div>
@else

@php
    // ═══ لابل الأسبوع «17 — 23 أغسطس 2026» — أسماء الشهور من Carbon ═══
    $weekEnd = $weekStart->copy()->addDays(6);
    $loc = app()->getLocale();
    $weekLabel = $weekStart->month === $weekEnd->month
        ? $weekStart->day.' — '.$weekEnd->copy()->locale($loc)->translatedFormat('j F Y')
        : $weekStart->copy()->locale($loc)->translatedFormat('j F')
            .' — '.$weekEnd->copy()->locale($loc)->translatedFormat('j F Y');

    $prevWeek = $weekStart->copy()->subWeek()->toDateString();
    $nextWeek = $weekStart->copy()->addWeek()->toDateString();
    $wLink = fn ($w) => route('ops.journeys', ['rep' => $rep->id, 'week' => $w]);

    $todayKey = today()->toDateString();
    $pulseTitle = $monthStart->copy()->locale($loc)->translatedFormat('F Y');
@endphp

<div class="jb">

    {{-- ═══════════════ السايدبار — عملاء المندوب ═══════════════ --}}
    <aside class="jb-side card">
        <div class="jb-side-head">
            <b>{{ __('journey.board_clients', ['name' => $rep->displayName()]) }}</b>
            <span class="s" id="jbPoolCount"></span>
        </div>

        <input type="search" id="jbSearch" placeholder="{{ __('journey.board_search') }}"
               onkeydown="if (event.key === 'Enter') event.preventDefault()">

        {{-- محافظة ← منطقة، بالأعداد (طلب المالك ٢٨/٨) — المنطقة
             بتتفلتر بالمحافظة المختارة، والأعداد بتتحدث مع بعض --}}
        <div class="jb-geo">
            {{-- (٢٢/٩) عنوان فوق كل قايمة — الاختيار الأول «كل …» بيتبني في renderGeo() --}}
            <label class="fl"><span>{{ __('ui.l_gov') }}</span>
                <select id="jbGov" onchange="setGov(this.value)"></select></label>
            <label class="fl"><span>{{ __('ui.l_zone') }}</span>
                <select id="jbZone" onchange="setZone(this.value)"></select></label>
        </div>

        <div class="jb-filters" id="jbFilters"></div>

        <div class="jb-pool" id="jbPool"></div>

        <button type="button" class="btn" style="width:100%" onclick="jbAutoZone()">
            🧭 {{ __('journey.auto_zone') }}
        </button>
    </aside>

    {{-- ═══════════════ البورد ═══════════════ --}}
    <div class="jb-main">

        <div class="card jb-top">
            <div class="jb-toprow">
                <form method="GET" action="{{ route('ops.journeys') }}" style="display:inline-flex">
                    <input type="hidden" name="week" value="{{ $weekStart->toDateString() }}">
                    {{-- البورد دايماً على مندوب واحد — فمفيش اختيار «الكل»، العنوان بس --}}
                    <label class="fl wide"><span>{{ __('ui.l_rep') }}</span>
                    <select name="rep" onchange="this.form.submit()">
                        @foreach ($reps as $r)
                            <option value="{{ $r->id }}" @selected($rep->id === $r->id)>
                                {{ $r->displayName() }} — {{ $r->roleLabel() }}{{ $r->zone ? ' · '.$r->zone->displayName() : '' }}
                            </option>
                        @endforeach
                    </select></label>
                </form>

                <span class="jb-weeknav">
                    <a class="btn sm" href="{{ $wLink($prevWeek) }}">‹</a>
                    <b>{{ $weekLabel }}</b>
                    <a class="btn sm" href="{{ $wLink($nextWeek) }}">›</a>
                </span>

                <span class="jb-grow"></span>

                <a class="btn sm" href="{{ route('ops.rep_day', $rep->id) }}">👤 {{ __('ui.l_rep') }} ↗</a>
                <a class="btn sm" href="{{ route('ops.geo', ['rep' => $rep->id]) }}">🧭 {{ __('journey.builder_btn') }}</a>
                @if (\App\Support\Access::action(auth()->user(), 'act.field.plan'))
                <button type="button" class="btn" onclick="openDlg('dlgCopy')">
                    ⧉ {{ __('journey.copy_btn') }}
                </button>
                <button type="button" class="btn gold" id="jbSaveBtn" onclick="jbSave()">
                    ✓ {{ __('journey.save_btn') }}
                </button>
                @endif
            </div>

            <div class="dash-hint" style="margin-top:8px">{{ __('journey.arm_hint') }}</div>
            <div class="jb-statsrow">
                <span class="s" id="jbWeekStats"></span>
                <span class="jb-grow"></span>
                <span class="jb-lg"><i class="dot d-ok"></i>{{ __('journey.lg_ok') }}</span>
                <span class="jb-lg"><i class="dot d-over"></i>{{ __('journey.lg_over') }}</span>
                <span class="jb-lg"><i class="dot d-empty"></i>{{ __('journey.lg_empty') }}</span>
            </div>
        </div>

        <div class="jb-week" id="jbWeek"></div>

        {{-- ═══ جدول الخطة (١١/١١) — نفس البورد كجدول عشان الإكسيل ═══ --}}
        <div class="card" style="margin-bottom:14px">
            <h3 style="margin:0 0 8px">📋 {{ __('journey.plan_table') }} — {{ $rep->displayName() }}</h3>
            <table id="jbTable">
                <thead><tr>
                    <th>{{ __('journey.col_day') }}</th><th>{{ __('journey.col_no') }}</th>
                    <th>{{ __('journey.col_code') }}</th><th>{{ __('journey.col_client') }}</th>
                    <th>{{ __('journey.col_zone') }}</th><th>{{ __('journey.col_freq') }}</th>
                    <th>{{ __('journey.col_first') }}</th><th>{{ __('journey.col_time') }}</th>
                    <th>{{ __('journey.col_due') }}</th>
                </tr></thead>
                <tbody>
                    @foreach ([6, 0, 1, 2, 3, 4, 5] as $d)
                        @foreach (collect($board['plans'])->where('weekday', $d)->sortBy('sort')->values() as $i => $p)
                            <tr>
                                <td>{{ __('journey.day_'.$d) }}</td><td>{{ $i + 1 }}</td>
                                <td>{{ $p['client']['code'] }}</td><td>{{ $p['client']['name'] }}</td>
                                <td>{{ $p['client']['zone'] ?? '—' }}</td>
                                <td>{{ __('journey.freq_'.$p['every_weeks']) }}</td>
                                <td>{{ $p['starts_on'] ?? '—' }}</td><td>{{ $p['time'] ?: '—' }}</td>
                                <td>{{ $p['due'] ? '✓' : __('journey.not_due') }}</td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- ═══════════ نبضة الشهر — النمط مفرود على التواريخ ═══════════ --}}
        <div class="card">
            <h3>📅 {{ $pulseTitle }} — {{ __('journey.pulse_title') }}
                <span class="side">{{ __('journey.pulse_hint') }}</span>
            </h3>
            <div class="jb-pulse-lg">
                <span class="jb-lg"><i class="pcell p1"></i>1–3</span>
                <span class="jb-lg"><i class="pcell p2"></i>4–6</span>
                <span class="jb-lg"><i class="pcell p3"></i>7+</span>
                <span class="jb-lg"><i class="pcell pmiss"></i>{{ __('journey.pulse_missed_lg') }}</span>
            </div>

            <div class="jb-pulse-head">
                @foreach ([6, 0, 1, 2, 3, 4, 5] as $d)
                    <div>{{ __('journey.day_'.$d) }}</div>
                @endforeach
            </div>
            <div class="jb-pulse" id="jbPulse"></div>
        </div>
    </div>
</div>

{{-- فورم الحفظ — بيتملى بالجافاسكربت ويتبعت --}}
@if (\App\Support\Access::action(auth()->user(), 'act.field.plan'))
<form method="POST" action="{{ route('ops.journeys.sync') }}" id="syncForm" style="display:none">
    @csrf
    <input type="hidden" name="user_id" value="{{ $rep->id }}">
    <div id="syncFields"></div>
</form>
@endif

{{-- ═══ انسخ من مندوب ═══ --}}
@if (\App\Support\Access::action(auth()->user(), 'act.field.plan'))
<dialog id="dlgCopy">
    <form class="dlg" method="POST" action="{{ route('ops.journeys.copy') }}">
        @csrf
        <input type="hidden" name="user_id" value="{{ $rep->id }}">
        <h4>{{ __('journey.copy_title') }}</h4>
        <div class="s" style="margin-bottom:10px">{{ __('journey.copy_hint') }}</div>
        <label class="f">{{ __('ui.l_rep') }}</label>
        <select name="from_id" style="width:100%" required>
            <option value="">{{ __('ui.choose', ['x' => __('ui.l_rep')]) }}</option>
            @foreach ($reps as $r)
                @continue($r->id === $rep->id)
                <option value="{{ $r->id }}">{{ $r->displayName() }} — {{ $r->roleLabel() }}</option>
            @endforeach
        </select>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
            <button class="btn" type="button" onclick="closeDlg('dlgCopy')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">{{ __('journey.copy_btn') }}</button>
        </div>
    </form>
</dialog>
@endif

{{-- ═══ خريطة اليوم (زي جدولة المحتملين) — عرض بس ═══ --}}
<dialog id="dlgDayMap" class="wide">
    <div class="dlg">
        <h4 style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
            🗺️ <span id="dmTitle"></span> <span class="badge b-blue" id="dmCount"></span>
        </h4>
        <div id="dmMap" style="height:52vh;border-radius:12px;border:1px solid var(--border)"></div>
        <div id="dmList" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px"></div>
        <div style="display:flex;justify-content:flex-end;margin-top:12px">
            <button class="btn" type="button" onclick="closeDlg('dlgDayMap')">{{ __('common.close') }}</button>
        </div>
    </div>
</dialog>

{{-- ═══ تعديل محطة: التردد / أول زيارة / الوقت (١١/١١) ═══ --}}
<dialog id="dlgStop">
    <div class="dlg">
        <h4 id="stTitle">{{ __('journey.edit_title') }}</h4>
        <label class="fl"><span>{{ __('journey.col_freq') }}</span>
            <select id="stFreq" style="width:100%">
                @foreach ($frequencies as $fq)
                    <option value="{{ $fq }}">{{ __('journey.freq_'.$fq) }}</option>
                @endforeach
            </select></label>
        <label class="fl" style="margin-top:8px"><span>{{ __('journey.col_first') }}</span>
            <input type="date" id="stFirst" style="width:100%"></label>
        <div class="dash-hint">{{ __('journey.edit_first_hint') }}</div>
        <label class="fl" style="margin-top:8px"><span>{{ __('journey.col_time') }}</span>
            <input type="time" id="stTime" style="width:100%"></label>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
            <button class="btn" type="button" onclick="closeDlg('dlgStop')">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="button" onclick="stopApply()">{{ __('journey.edit_apply') }}</button>
        </div>
    </div>
</dialog>

{{-- ═══ تفاصيل يوم من النبضة ═══ --}}
<dialog id="dlgPulse">
    <div class="dlg">
        <h4 id="pulseDlgTitle"></h4>
        <div id="pulseDlgBody" style="max-height:340px;overflow-y:auto"></div>
        <div style="display:flex;justify-content:flex-end;margin-top:12px">
            <button class="btn" type="button" onclick="closeDlg('dlgPulse')">{{ __('common.close') }}</button>
        </div>
    </div>
</dialog>

@endif

@endsection

@section('scripts')
@if ($rep !== null)
@php
    $jsFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP;

    $dayNames = [];
    $dayDates = [];
    foreach ($weekdays as $d) {
        $dayNames[$d] = __('journey.day_'.$d);
        $dayDates[$d] = $weekStart->copy()->addDays(($d - 6 + 7) % 7)->format('j/n');
    }

    // نبضة الشهر للجافاسكربت: التاريخ ← عملاؤه (بحالة اتزار/لأ)
    $pulse = [
        // السبت أول عمود (١١/١١)
        'firstCol' => ($monthStart->dayOfWeek + 1) % 7,
        'days' => collect($calendar)->map(fn ($rows, $key) => [
            'date' => $key,
            'rows' => $rows,
        ])->values()->all(),
        'today' => $todayKey,
    ];

    $jsT = [
        'unassigned' => __('journey.f_unassigned'),
        'all' => __('journey.f_all'),
        'unassignedN' => __('journey.board_unassigned_n'),
        'owes' => __('journey.board_owes'),
        'late' => __('journey.board_late'),
        'dragHere' => __('journey.drag_here'),
        'dayOff' => __('journey.day_off'),
        'overload' => __('journey.overload'),
        'overHint' => __('journey.over_hint'),
        'weekStats' => __('journey.week_stats'),
        'autoDone' => __('journey.auto_done'),
        'unsaved' => __('journey.unsaved'),
        'freq2' => __('journey.freq_2'),
        'freq4' => __('journey.freq_4'),
        'visitsN' => __('journey.pulse_visits_n'),
        'missedN' => __('journey.pulse_missed_n'),
        'todayLbl' => __('journey.pulse_today'),
        'addBtn' => __('journey.board_add'),
        // فصل البول (٢٨/٨): فروع السلاسل سكشن والكاش فان سكشن
        'secChains' => __('journey.sec_chains'),
        'secCash' => __('journey.sec_cash'),
        // محافظة ← منطقة بالأعداد (٢٨/٨)
        'allGovs' => __('journey.all_govs'),
        'allZones' => __('journey.all_zones'),
        'notDue' => __('journey.not_due'),
        'dayMap' => __('journey.day_map'),
    ];
@endphp
<script>
    const BOARD = {!! json_encode($board, $jsFlags) !!};
    const DAY_NAMES = {!! json_encode($dayNames, $jsFlags) !!};
    const DAY_DATES = {!! json_encode($dayDates, $jsFlags) !!};
    const PULSE = {!! json_encode($pulse, $jsFlags) !!};
    const T = {!! json_encode($jsT, $jsFlags) !!};
    const TODAY_DOW = {{ (int) $today }};
    const MAX_PER_DAY = 8;
    // ترتيب الأعمدة من السبت للجمعة (زي جدولة المحتملين)
    const ORDER = [6, 0, 1, 2, 3, 4, 5];
    // المتسلّح من البول — دوسة على يوم بتنزله آخره
    let armed = null;
    // من غير act.field.plan البورد قراءة بس: مفيش سحب ولا «فيه تعديل مش محفوظ»
    const CAN_PLAN = @json(\App\Support\Access::action(auth()->user(), 'act.field.plan'));

    // حالة البورد كلها في المتصفح — الحفظ بيبعت الصورة النهائية مرة واحدة
    const state = {
        days: {0: [], 1: [], 2: [], 3: [], 4: [], 5: [], 6: []},
        pool: BOARD.pool.slice(),
        filter: 'un',
        q: '',
        // محافظة ← منطقة (٢٨/٨) — فاضي = الكل
        gov: '',
        zone: '',
        dirty: false,
    };

    BOARD.plans.forEach(function (p) {
        state.days[p.weekday].push({
            pid: p.id,
            freq: p.every_weeks,
            time: p.time,
            at: p.visit_at || '',
            first: p.starts_on || '',
            due: p.due,
            c: p.client,
        });
    });
    Object.keys(state.days).forEach(function (d) {
        state.days[d].sort(function (a, b) { return 0; });
    });

    function fmt(n) {
        return Number(n).toLocaleString('en-US', {maximumFractionDigits: 0});
    }

    function tt(key, vars) {
        let s = T[key] || key;
        Object.keys(vars || {}).forEach(function (k) {
            s = s.replaceAll(':' + k, vars[k]);
        });
        return s;
    }

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function markDirty() {
        if (! CAN_PLAN) { return; }
        state.dirty = true;
        const b = document.getElementById('jbSaveBtn');
        if (! b) { return; }
        b.classList.add('pulsing');
        b.title = T.unsaved;
    }

    window.addEventListener('beforeunload', function (e) {
        if (state.dirty) { e.preventDefault(); e.returnValue = ''; }
    });

    // ═══════════════ السايدبار ═══════════════

    function channels() {
        const set = new Set();
        state.pool.forEach(function (c) { if (c.channel) set.add(c.channel); });
        Object.values(state.days).flat().forEach(function (it) {
            if (it.c.channel) set.add(it.c.channel);
        });
        return Array.from(set);
    }

    function plannedList() {
        const out = [];
        Object.keys(state.days).forEach(function (d) {
            state.days[d].forEach(function (it) { out.push({day: +d, c: it.c}); });
        });
        return out;
    }

    function renderFilters() {
        const holder = document.getElementById('jbFilters');
        const total = state.pool.length + plannedList().length;
        let html = '';

        html += chipBtn('un', T.unassigned + ' · ' + state.pool.length, state.filter === 'un');
        html += chipBtn('all', T.all + ' · ' + total, state.filter === 'all');
        channels().forEach(function (ch) {
            html += chipBtn('ch:' + ch, esc(ch), state.filter === 'ch:' + ch);
        });

        holder.innerHTML = html;
    }

    function chipBtn(val, label, on) {
        return '<button type="button" class="jb-fchip' + (on ? ' on' : '') + '" ' +
            'onclick="setFilter(\'' + val.replace(/'/g, "\\'") + '\')">' + label + '</button>';
    }

    function setFilter(v) {
        state.filter = v;
        renderSide();
    }

    // ═══ محافظة ← منطقة بالأعداد (٢٨/٨) ═══

    /// كل الصفوف (البول + المتوزع) — الأعداد بتتحسب عليهم كلهم
    function allRows() {
        return state.pool.concat(plannedList().map(function (r) { return r.c; }));
    }

    function setGov(v) {
        state.gov = v;
        // تغيير المحافظة بيصفّر المنطقة — منطقة من محافظة تانية
        // فلتر كاذب بيرجّع صفر صفوف من غير سبب باين
        state.zone = '';
        renderSide();
    }

    function setZone(v) {
        state.zone = v;
        renderSide();
    }

    function renderGeo() {
        const rows = allRows();

        // المحافظات بأعدادها
        const govs = {};
        rows.forEach(function (c) {
            const g = c.gov || '—';
            govs[g] = (govs[g] || 0) + 1;
        });

        let gh = '<option value="">' + T.allGovs + ' · ' + rows.length + '</option>';
        Object.keys(govs).sort().forEach(function (g) {
            gh += '<option value="' + esc(g) + '"' + (state.gov === g ? ' selected' : '') + '>'
                + esc(g) + ' · ' + govs[g] + '</option>';
        });
        document.getElementById('jbGov').innerHTML = gh;

        // المناطق — جوه المحافظة المختارة بس، بأعدادها
        const inGov = state.gov
            ? rows.filter(function (c) { return (c.gov || '—') === state.gov; })
            : rows;
        const zones = {};
        inGov.forEach(function (c) {
            const z = c.zone || '—';
            zones[z] = (zones[z] || 0) + 1;
        });

        let zh = '<option value="">' + T.allZones + ' · ' + inGov.length + '</option>';
        Object.keys(zones).sort().forEach(function (z) {
            zh += '<option value="' + esc(z) + '"' + (state.zone === z ? ' selected' : '') + '>'
                + esc(z) + ' · ' + zones[z] + '</option>';
        });
        document.getElementById('jbZone').innerHTML = zh;
    }

    function poolRow(c, day) {
        const late = c.last_days !== null && c.last_days > 14;
        const meta = [];
        if (c.zone) meta.push(esc(c.zone));
        if (c.balance > 0) meta.push(tt('owes', {n: fmt(c.balance)}));

        return '<div class="jb-crow" draggable="true" data-cid="' + c.id + '"' +
            (day !== undefined ? ' data-fromday="' + day + '"' : '') + '>' +
            '<div class="jb-crow-in">' +
            '<div class="nm">' + esc(c.name) +
            (late ? ' <span class="latechip">' + T.late + '</span>' : '') +
            (day !== undefined ? ' <span class="daychip">' + DAY_NAMES[day] + '</span>' : '') +
            '</div>' +
            '<div class="s">' + meta.join(' · ') + '</div>' +
            '</div>' +
            '<span class="draghint">⠿</span>' +
            '</div>';
    }

    function renderSide() {
        renderGeo();
        renderFilters();

        const q = state.q.trim().toLowerCase();
        const holder = document.getElementById('jbPool');
        let rows = [];

        const match = function (c) {
            if (q && !(c.q || '').includes(q) && !c.name.toLowerCase().includes(q)) return false;
            // محافظة ← منطقة (٢٨/٨)
            if (state.gov && (c.gov || '—') !== state.gov) return false;
            if (state.zone && (c.zone || '—') !== state.zone) return false;
            if (state.filter.startsWith('ch:')) return c.channel === state.filter.slice(3);
            return true;
        };

        // ═══ الفصل (طلب المالك ٢٨/٨): فروع السلاسل سكشن لوحدهم —
        // مجمّعين بالسلسلة — وبعديهم عملاء الكاش فان/الفرادى.
        // العناوين بتظهر بس لما البول مخلوط، فبول من نوع واحد
        // بيتعرض زي زمان من غير دوشة.
        const chainRows = state.pool.filter(function (c) { return match(c) && c.chain; });
        const soloRows = state.pool.filter(function (c) { return match(c) && !c.chain; });

        const secHead = function (label, n) {
            return '<div class="jb-sec">' + label +
                ' <span class="jb-sec-n">' + n + '</span></div>';
        };

        if (chainRows.length && soloRows.length) {
            rows.push(secHead('🔗 ' + T.secChains, chainRows.length));
        }

        // تجميع فروع السلسلة الواحدة ورا بعض — بعنوان صغير لكل سلسلة
        const byChain = {};
        chainRows.forEach(function (c) { (byChain[c.chain] = byChain[c.chain] || []).push(c); });
        Object.keys(byChain).sort().forEach(function (ch) {
            if (Object.keys(byChain).length > 1) {
                rows.push('<div class="jb-chainhead">' + esc(ch) +
                    ' · ' + byChain[ch].length + '</div>');
            }
            byChain[ch].forEach(function (c) { rows.push(poolRow(c)); });
        });

        if (chainRows.length && soloRows.length) {
            rows.push(secHead('🚐 ' + T.secCash, soloRows.length));
        }

        soloRows.forEach(function (c) { rows.push(poolRow(c)); });

        if (state.filter !== 'un') {
            plannedList().forEach(function (r) {
                if (match(r.c)) rows.push(poolRow(r.c, r.day));
            });
        }

        holder.innerHTML = rows.join('') ||
            '<div class="s" style="padding:14px;text-align:center">—</div>';

        document.getElementById('jbPoolCount').textContent =
            tt('unassignedN', {n: state.pool.length});

        holder.querySelectorAll('.jb-crow').forEach(bindDragRow);
        holder.querySelectorAll('.jb-crow:not([data-fromday])').forEach(function (el) {
            el.addEventListener('click', function () {
                if (! CAN_PLAN) { return; }
                const cid = +el.dataset.cid;
                armed = armed === cid ? null : cid;
                renderSide();
                document.querySelectorAll('.jb-day').forEach(function (d) { d.classList.toggle('armed', armed !== null); });
            });
            if (+el.dataset.cid === armed) el.classList.add('armed');
        });
    }

    // ═══════════════ الأعمدة ═══════════════

    function dayState(n) {
        if (n === 0) return 'empty';
        if (n > MAX_PER_DAY) return 'over';
        return 'ok';
    }

    function renderWeek() {
        const holder = document.getElementById('jbWeek');
        let html = '';

        ORDER.forEach(function (d) {
            const items = state.days[d];
            // ⚠️ العدّ والتحذير على **المستحق** في الأسبوع المعروض (قرار §10.8)
            const dueN = items.filter(function (it) { return it.due !== false; }).length;
            const st = dayState(dueN);
            const over = dueN - MAX_PER_DAY;

            html += '<div class="jb-day ' + st + (d === TODAY_DOW ? ' istoday' : '') + (armed !== null ? ' armed' : '') + '" data-day="' + d + '">';
            html += '<div class="jb-dayhead">' +
                '<b>' + DAY_NAMES[d] + '</b>' +
                '<span class="dt">' + DAY_DATES[d] + '</span>' +
                '<span class="jb-dmap" title="' + esc(T.dayMap) + '" onclick="event.stopPropagation();dayMapOpen(' + d + ')">🗺</span>' +
                '<span class="cnt">' + dueN + (dueN !== items.length ? '/' + items.length : '') +
                (st === 'over' ? ' · ' + T.overload : '') + '</span></div>';

            html += '<div class="jb-daybody" data-day="' + d + '">';

            items.forEach(function (it, i) {
                html += '<div class="jb-chip' + (it.due === false ? ' notdue' : '') + '" draggable="true" data-day="' + d + '" data-idx="' + i + '"' +
                    (it.due === false ? ' title="' + esc(T.notDue) + '"' : '') + '>' +
                    '<span class="num">' + (i + 1) + '</span>' +
                    '<span class="nm" onclick="event.stopPropagation();stopEdit(' + d + ',' + i + ')">' + esc(it.c.name) + '</span>' +
                    (it.first ? '<span class="fq">📅 ' + esc(it.first.slice(5)) + '</span>' : '') +
                    (it.freq === 2 ? '<span class="fq">' + esc(T.freq2) + '</span>' : '') +
                    (it.freq === 4 ? '<span class="fq">' + esc(T.freq4) + '</span>' : '') +
                    (it.time ? '<span class="fq">🕒 ' + esc(it.time) + '</span>' : '') +
                    '<button type="button" class="x" onclick="event.stopPropagation();removeChip(' + d + ',' + i + ')">✕</button>' +
                    '</div>';
            });

            if (!items.length) {
                html += '<div class="jb-empty">' + (d === 6 ? T.dayOff : T.dragHere) + '</div>';
            }

            html += '<button type="button" class="jb-add" onclick="event.stopPropagation();focusSearch()">＋ ' + esc(T.addBtn) + '</button>';

            if (st === 'over') {
                html += '<div class="jb-warn">' + tt('overHint', {n: over}) + '</div>';
            }

            html += '</div></div>';
        });

        holder.innerHTML = html;
        holder.querySelectorAll('.jb-day').forEach(function (el) {
            el.addEventListener('click', function () { dropArmed(+el.dataset.day); });
        });

        holder.querySelectorAll('.jb-chip').forEach(bindDragChip);
        holder.querySelectorAll('.jb-daybody').forEach(bindDropZone);

        renderStats();
    }

    function renderStats() {
        let total = 0, activeDays = 0;
        for (let d = 0; d <= 6; d++) {
            total += state.days[d].length;
            if (state.days[d].length) activeDays++;
        }
        const avg = activeDays ? Math.round(total / activeDays * 10) / 10 : 0;
        document.getElementById('jbWeekStats').textContent =
            tt('weekStats', {t: total, a: avg});
    }

    function focusSearch() {
        const el = document.getElementById('jbSearch');
        el.focus();
        el.scrollIntoView({behavior: 'smooth', block: 'center'});
    }

    // ═══════════════ السحب والإفلات ═══════════════

    let drag = null;

    function bindDragRow(el) {
        el.addEventListener('dragstart', function (e) {
            if (! CAN_PLAN) { e.preventDefault(); return; }
            const cid = +el.dataset.cid;
            drag = el.dataset.fromday !== undefined
                ? {type: 'chip', day: +el.dataset.fromday,
                   idx: state.days[+el.dataset.fromday].findIndex(function (it) { return it.c.id === cid; })}
                : {type: 'pool', cid: cid};
            e.dataTransfer.effectAllowed = 'move';
        });
    }

    function bindDragChip(el) {
        el.addEventListener('dragstart', function (e) {
            if (! CAN_PLAN) { e.preventDefault(); return; }
            drag = {type: 'chip', day: +el.dataset.day, idx: +el.dataset.idx};
            e.dataTransfer.effectAllowed = 'move';
            e.stopPropagation();
        });
        el.addEventListener('dragover', function (e) {
            e.preventDefault();
            e.stopPropagation();
            el.classList.add('dover');
        });
        el.addEventListener('dragleave', function () { el.classList.remove('dover'); });
        el.addEventListener('drop', function (e) {
            e.preventDefault();
            e.stopPropagation();
            el.classList.remove('dover');
            dropAt(+el.dataset.day, +el.dataset.idx);
        });
    }

    function bindDropZone(el) {
        el.addEventListener('dragover', function (e) {
            e.preventDefault();
            el.closest('.jb-day').classList.add('dover');
        });
        el.addEventListener('dragleave', function () {
            el.closest('.jb-day').classList.remove('dover');
        });
        el.addEventListener('drop', function (e) {
            e.preventDefault();
            el.closest('.jb-day').classList.remove('dover');
            dropAt(+el.dataset.day, state.days[+el.dataset.day].length);
        });
    }

    function dropAt(day, index) {
        if (!drag) return;

        if (drag.type === 'pool') {
            const i = state.pool.findIndex(function (c) { return c.id === drag.cid; });
            if (i < 0) return;
            const c = state.pool.splice(i, 1)[0];
            state.days[day].splice(index, 0, {pid: null, freq: 1, time: '', at: '', first: '', due: true, c: c});
        } else {
            const from = state.days[drag.day];
            if (drag.idx < 0 || drag.idx >= from.length) return;
            const it = from.splice(drag.idx, 1)[0];
            if (drag.day === day && drag.idx < index) index--;
            state.days[day].splice(index, 0, it);
        }

        drag = null;
        markDirty();
        renderWeek();
        renderSide();
    }

    function removeChip(day, idx) {
        if (! CAN_PLAN) { return; }
        const it = state.days[day].splice(idx, 1)[0];
        state.pool.push(it.c);
        state.pool.sort(function (a, b) { return a.name.localeCompare(b.name); });
        markDirty();
        renderWeek();
        renderSide();
    }

    // ═══ تسليح وإنزال (فلو جدولة المحتملين) ═══
    function dropArmed(day) {
        if (armed === null || ! CAN_PLAN) return;
        const i = state.pool.findIndex(function (c) { return c.id === armed; });
        armed = null;
        if (i < 0) { renderWeek(); renderSide(); return; }
        const c = state.pool.splice(i, 1)[0];
        state.days[day].push({pid: null, freq: 1, time: '', at: '', first: '', due: true, c: c});
        markDirty();
        renderWeek();
        renderSide();
    }

    // ═══ تعديل محطة (التردد / أول زيارة / الوقت) ═══
    let editing = null;
    function stopEdit(day, idx) {
        if (! CAN_PLAN) return;
        const it = state.days[day][idx];
        editing = {day: day, idx: idx};
        document.getElementById('stTitle').textContent = it.c.name;
        document.getElementById('stFreq').value = String(it.freq);
        document.getElementById('stFirst').value = it.first || '';
        document.getElementById('stTime').value = it.at || '';
        openDlg('dlgStop');
    }
    function stopApply() {
        if (!editing) return;
        const it = state.days[editing.day][editing.idx];
        it.freq = +document.getElementById('stFreq').value;
        it.first = document.getElementById('stFirst').value;
        it.at = document.getElementById('stTime').value;
        it.time = it.at;
        let day = editing.day;
        // أول زيارة بتحدد اليوم — المحطة بتتنقل لعمودها
        if (it.first) {
            const wd = new Date(it.first + 'T00:00:00').getDay();
            if (wd !== day) {
                state.days[day].splice(editing.idx, 1);
                state.days[wd].push(it);
                day = wd;
            }
        }
        editing = null;
        closeDlg('dlgStop');
        markDirty();
        renderWeek();
    }

    // ═══ خريطة اليوم — أرقام ١ ٢ ٣ متوصلين بخط ═══
    let dmMap = null, dmLayer = null;
    function dayMapOpen(day) {
        const list = state.days[day] || [];
        document.getElementById('dmTitle').textContent = DAY_NAMES[day] + ' ' + DAY_DATES[day];
        document.getElementById('dmCount').textContent = list.length;
        const box = document.getElementById('dmList');
        box.innerHTML = '';
        list.forEach(function (it, i) {
            const chip = document.createElement('span');
            chip.className = 'badge ' + (it.c.lat != null ? 'b-blue' : 'b-gray');
            chip.style.cssText = 'font-size:11px;padding:4px 9px';
            chip.innerHTML = '<b>' + (i + 1) + '</b> ' + esc(it.c.name) + (it.c.lat != null ? '' : ' 📍✕');
            box.appendChild(chip);
        });
        openDlg('dlgDayMap');
        if (dmMap === null) {
            dmMap = L.map('dmMap', {scrollWheelZoom: false});
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19, attribution: '&copy; OpenStreetMap',
            }).addTo(dmMap);
            dmLayer = L.layerGroup().addTo(dmMap);
            dmMap.on('click', function () { dmMap.scrollWheelZoom.enable(); });
            dmMap.on('mouseout', function () { dmMap.scrollWheelZoom.disable(); });
        }
        dmLayer.clearLayers();
        const pts = [];
        list.forEach(function (it, i) {
            if (it.c.lat == null || it.c.lng == null) return;
            const m = L.marker([it.c.lat, it.c.lng], {icon: L.divIcon({
                className: '', iconSize: [28, 28], iconAnchor: [14, 14],
                html: '<div style="width:28px;height:28px;border-radius:50%;background:#12399B;border:2.5px solid #fff;'
                    + 'box-shadow:0 2px 5px rgba(0,0,0,.35);color:#fff;font:900 12px Cairo,Inter,sans-serif;'
                    + 'display:flex;align-items:center;justify-content:center">' + (i + 1) + '</div>',
            })});
            m.bindTooltip('<b>' + (i + 1) + '. ' + esc(it.c.name) + '</b>', {direction: 'top', offset: [0, -12]});
            dmLayer.addLayer(m);
            pts.push([it.c.lat, it.c.lng]);
        });
        if (pts.length > 1) {
            dmLayer.addLayer(L.polyline(pts, {color: '#12399B', weight: 3.5, dashArray: '8 8', opacity: .9}));
        }
        setTimeout(function () {
            dmMap.invalidateSize();
            if (pts.length === 1) dmMap.setView(pts[0], 14);
            else if (pts.length) dmMap.fitBounds(L.latLngBounds(pts).pad(0.2));
            else dmMap.setView([30.05, 31.4], 10);
        }, 120);
    }

    // ═══════════════ وزّع تلقائي بالمنطقة ═══════════════
    // عملاء نفس الزون في نفس اليوم، والزون بيروح لليوم الأخف —
    // السبت إجازة. توزيع محلي: راجعه وبعدين احفظ.
    function jbAutoZone() {
        if (! CAN_PLAN) { return; }
        if (!state.pool.length) return;

        const byZone = {};
        state.pool.forEach(function (c) {
            const z = c.zone || '—';
            (byZone[z] = byZone[z] || []).push(c);
        });

        const zones = Object.keys(byZone)
            .sort(function (a, b) { return byZone[b].length - byZone[a].length; });

        zones.forEach(function (z) {
            let best = 0, min = Infinity;
            for (let d = 0; d <= 5; d++) {
                if (state.days[d].length < min) { min = state.days[d].length; best = d; }
            }
            byZone[z].forEach(function (c) {
                state.days[best].push({pid: null, freq: 1, time: '', at: '', first: '', due: true, c: c});
            });
        });

        state.pool = [];
        markDirty();
        renderWeek();
        renderSide();
        alert(T.autoDone);
    }

    // ═══════════════ الحفظ ═══════════════

    function jbSave() {
        const fields = document.getElementById('syncFields');
        fields.innerHTML = '';
        let i = 0;

        const add = function (name, value) {
            const inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = name;
            inp.value = value;
            fields.appendChild(inp);
        };

        for (let d = 0; d <= 6; d++) {
            state.days[d].forEach(function (it, idx) {
                if (it.pid) add('rows[' + i + '][id]', it.pid);
                add('rows[' + i + '][client_id]', it.c.id);
                add('rows[' + i + '][weekday]', d);
                add('rows[' + i + '][sort]', idx + 1);
                add('rows[' + i + '][every_weeks]', it.freq || 1);
                add('rows[' + i + '][starts_on]', it.first || '');
                add('rows[' + i + '][visit_at]', it.at || '');
                i++;
            });
        }

        state.dirty = false;
        document.getElementById('syncForm').submit();
    }

    // ═══════════════ نبضة الشهر ═══════════════

    function renderPulse() {
        const holder = document.getElementById('jbPulse');
        let html = '';

        for (let i = 0; i < PULSE.firstCol; i++) html += '<div class="pday out"></div>';

        PULSE.days.forEach(function (day) {
            const n = day.rows.length;
            const isToday = day.date === PULSE.today;
            const isPast = day.date < PULSE.today;
            const missed = isPast ? day.rows.filter(function (r) { return !r.done; }).length : 0;

            let cls = 'p0';
            if (n >= 7) cls = 'p3'; else if (n >= 4) cls = 'p2'; else if (n >= 1) cls = 'p1';
            if (missed > 0) cls = 'pmiss';

            const dnum = +day.date.slice(-2);
            let sub = '';
            if (missed > 0) sub = tt('missedN', {n: missed});
            else if (n > 0) sub = tt('visitsN', {n: n});

            html += '<div class="pday ' + cls + (isToday ? ' ptoday' : '') + '" data-date="' + day.date + '">' +
                '<b>' + dnum + (isToday ? ' · ' + T.todayLbl : '') + '</b>' +
                (sub ? '<span>' + sub + '</span>' : '') +
                '</div>';
        });

        holder.innerHTML = html;

        holder.querySelectorAll('.pday[data-date]').forEach(function (el) {
            el.addEventListener('click', function () { openPulseDay(el.dataset.date); });
        });
    }

    function openPulseDay(date) {
        const day = PULSE.days.find(function (d) { return d.date === date; });
        if (!day || !day.rows.length) return;

        const isPast = date < PULSE.today;
        document.getElementById('pulseDlgTitle').textContent = date;
        document.getElementById('pulseDlgBody').innerHTML = day.rows.map(function (r) {
            const mark = r.done ? '✓' : (isPast ? '✗' : '•');
            const color = r.done ? 'var(--green)' : (isPast ? 'var(--red)' : 'var(--ink)');
            return '<div style="padding:5px 2px;font-size:12.5px;color:' + color + '">' +
                mark + ' ' + esc(r.name) + '</div>';
        }).join('');
        openDlg('dlgPulse');
    }

    document.getElementById('jbSearch').addEventListener('input', function () {
        state.q = this.value;
        renderSide();
    });

    renderSide();
    renderWeek();
    renderPulse();
</script>
<style>
/* (٢٢/٩) minmax(0,1fr): العمود كان بيتمدد بعرض أيام الأسبوع السبعة فالصفحة كلها بتطلع بره الشاشة */
.jb{display:grid;grid-template-columns:290px minmax(0,1fr);gap:14px;align-items:start}
@media(max-width:1100px){.jb{grid-template-columns:1fr}}
.jb-grow{flex:1}

/* ═══ السايدبار ═══ */
.jb-side{position:sticky;top:14px;display:flex;flex-direction:column;gap:9px;max-height:calc(100vh - 28px)}
.jb-side-head{display:flex;flex-direction:column;gap:2px}
.jb-side-head .s{color:var(--muted);font-size:11px}
.jb-side input[type=search]{width:100%}
.jb-filters{display:flex;flex-wrap:wrap;gap:5px}
.jb-fchip{border:1px solid var(--border);background:var(--card);border-radius:20px;
  padding:4px 10px;font-size:11px;font-weight:800;cursor:pointer;font-family:inherit;color:var(--ink)}
.jb-fchip.on{background:var(--royal-blue);border-color:var(--royal-blue);color:#fff}
.jb-pool{flex:1;overflow-y:auto;min-height:120px;border:1px solid var(--border);
  border-radius:12px;padding:5px}
/* محافظة ← منطقة (٢٨/٨) */
.jb-geo{display:flex;gap:6px;margin:6px 0}
.jb-geo .fl{flex:1;min-width:0}
.jb-geo select{flex:1;min-width:0;padding:6px 8px;border:1px solid var(--border);
    border-radius:9px;font-size:11.5px;background:#fff}
/* سكشنات فصل البول (٢٨/٨): السلاسل فوق والكاش فان تحت */
.jb-sec{display:flex;align-items:center;gap:6px;font-size:12px;font-weight:800;
    padding:9px 4px 4px;color:#374151}
.jb-sec-n{background:#E5E7EB;border-radius:20px;padding:1px 8px;font-size:10.5px}
.jb-chainhead{font-size:10.5px;font-weight:800;color:var(--muted);
    padding:5px 6px 2px}
.jb-crow{display:flex;align-items:center;gap:6px;padding:8px 9px;border-radius:10px;
  cursor:grab;border-bottom:1px solid var(--border)}
.jb-crow:last-child{border-bottom:none}
.jb-crow:hover{background:var(--blue-050)}
.jb-crow .nm{font-size:12px;font-weight:800}
.jb-crow .s{font-size:10.5px;color:var(--muted);margin-top:1px}
.jb-crow-in{flex:1;min-width:0}
.jb-crow .draghint{color:var(--muted);opacity:.45;font-size:13px}
.latechip{color:var(--red);font-size:10px;font-weight:900;margin-inline-start:4px}
.daychip{background:var(--blue-050);color:var(--royal-blue);border-radius:14px;
  padding:1px 7px;font-size:9.5px;font-weight:900;margin-inline-start:4px}

/* ═══ الشريط العلوي ═══ */
.jb-top{padding:12px 14px}
.jb-toprow{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.jb-weeknav{display:inline-flex;align-items:center;gap:8px;font-size:13px}
.jb-statsrow{display:flex;align-items:center;gap:12px;margin-top:9px;
  padding-top:9px;border-top:1px solid var(--border)}
.jb-statsrow .s{font-size:11.5px;color:var(--muted)}
.jb-lg{display:inline-flex;align-items:center;gap:5px;font-size:10.5px;color:var(--muted)}
.jb-lg .dot{width:9px;height:9px;border-radius:3px;display:inline-block}
.d-ok{background:var(--royal-blue)}
.d-over{background:#E8890C}
.d-empty{background:var(--border)}
#jbSaveBtn.pulsing{box-shadow:0 0 0 3px rgba(230,167,0,.35)}

/* ═══ أعمدة الأسبوع ═══ */
.jb-week{display:grid;grid-template-columns:repeat(7,minmax(140px,1fr));gap:9px;margin:14px 0;overflow-x:auto}
@media(max-width:1400px){.jb-week{grid-template-columns:repeat(4,minmax(150px,1fr))}}
@media(max-width:800px){.jb-week{grid-template-columns:repeat(2,minmax(140px,1fr))}}
.jb-day{background:var(--card);border:1px solid var(--border);border-radius:14px;
  padding:9px;min-width:0;transition:box-shadow .12s}
.jb-day.istoday{box-shadow:0 0 0 2px rgba(18,57,155,.20)}
.jb-day.dover{box-shadow:0 0 0 2px var(--royal-blue);background:var(--blue-050)}
.jb-day.empty{background:var(--card2)}
.jb-day.over{border-color:#F0B429}
.jb-day.over .jb-dayhead{color:#B45309}
.jb-dayhead{display:flex;align-items:center;gap:5px;font-size:12px;font-weight:900;
  color:var(--royal-blue);padding-bottom:7px;margin-bottom:7px;border-bottom:1px solid var(--border)}
.jb-dayhead .dt{font-size:9.5px;color:var(--muted);font-weight:700}
.jb-dayhead .cnt{margin-inline-start:auto;background:var(--royal-blue);color:#fff;
  border-radius:20px;padding:1px 8px;font-size:10px;white-space:nowrap}
.jb-day.over .jb-dayhead .cnt{background:#E8890C}
.jb-day.empty .jb-dayhead .cnt{background:var(--muted)}
.jb-daybody{min-height:60px}
.jb-chip{display:flex;align-items:center;gap:6px;background:var(--card);
  border:1px solid var(--border);border-radius:9px;padding:6px 7px;margin-bottom:5px;
  cursor:grab;font-size:11.5px}
.jb-chip.dover{border-color:var(--royal-blue);box-shadow:0 -2px 0 var(--royal-blue)}
.jb-chip .num{font-size:9.5px;font-weight:900;color:#fff;background:var(--royal-blue);
  border-radius:50%;min-width:17px;height:17px;display:inline-flex;align-items:center;
  justify-content:center;flex-shrink:0}
.jb-day.over .jb-chip .num{background:#E8890C}
.jb-chip .nm{flex:1;min-width:0;font-weight:800;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;line-height:1.35;word-break:break-word}
.jb-chip{flex-wrap:wrap}
.jb-chip .fq{font-size:9px;color:var(--royal-blue);background:var(--blue-050);
  border-radius:10px;padding:1px 6px;flex-shrink:0}
.jb-chip .x{background:none;border:none;color:var(--muted);cursor:pointer;
  font-size:11px;padding:1px 3px;font-family:inherit;flex-shrink:0}
.jb-chip .x:hover{color:var(--red)}
.jb-empty{border:1.5px dashed var(--border);border-radius:9px;padding:16px 8px;
  text-align:center;font-size:10.5px;color:var(--muted);margin-bottom:5px}
.jb-add{width:100%;background:none;border:1px dashed var(--border);border-radius:9px;
  color:var(--muted);font-size:10.5px;padding:5px;cursor:pointer;font-family:inherit}
.jb-add:hover{color:var(--royal-blue);border-color:var(--royal-blue)}
.jb-warn{background:#FFF6E0;border:1px solid #F0D48A;color:#8A6D00;border-radius:9px;
  padding:7px 8px;font-size:10px;font-weight:800;margin-top:6px;line-height:1.6}

/* ═══ (١١/١١) تسليح + غير مستحق + خريطة اليوم ═══ */
.jb-day.armed{border-color:#0F7A38;box-shadow:0 0 0 3px rgba(15,122,56,.15);cursor:pointer}
.jb-crow.armed{background:#EFFAF3;box-shadow:inset 0 0 0 1.5px #0F7A38}
.jb-chip.notdue{opacity:.5;border-style:dashed}
.jb-chip .nm{cursor:pointer}
.jb-dmap{cursor:pointer;font-size:13px;opacity:.75}
.jb-dmap:hover{opacity:1}

/* ═══ نبضة الشهر ═══ */
.jb-pulse-lg{display:flex;gap:14px;margin-bottom:10px}
.jb-pulse-lg .pcell{width:12px;height:12px;border-radius:4px;display:inline-block}
.jb-pulse-head{display:grid;grid-template-columns:repeat(7,1fr);gap:6px;
  font-size:11px;font-weight:800;color:var(--muted);text-align:center;margin-bottom:6px}
.jb-pulse{display:grid;grid-template-columns:repeat(7,1fr);gap:6px}
.pday{border-radius:10px;padding:8px 9px;min-height:52px;font-size:10px;
  display:flex;flex-direction:column;gap:2px;cursor:pointer;border:1px solid var(--border)}
.pday.out{background:transparent;border:none;cursor:default}
.pday b{font-size:11.5px}
.pday.p0{background:var(--card2);color:var(--muted);cursor:default}
.pday.p1{background:#DBE7FF;color:var(--royal-blue);border-color:#C4D6FA}
.pday.p2{background:#7FA4EE;color:#fff;border-color:#6D93E2}
.pday.p3{background:var(--royal-blue);color:#fff;border-color:var(--royal-blue)}
.pday.pmiss{background:#FDE7EA;color:var(--red);border-color:#F5C6CC}
.pday.ptoday{box-shadow:0 0 0 2.5px var(--brand-yellow)}
</style>
@endif
@endsection
