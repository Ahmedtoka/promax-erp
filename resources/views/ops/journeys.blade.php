@extends('layouts.system')

@section('title', __('journey.page'))

@section('actions')
    <a class="btn" href="{{ route('ops.assignments') }}">👥 {{ __('journey.assignments') }}</a>
    <a class="btn" href="{{ route('ops.live') }}">📡 {{ __('journey.live') }}</a>
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
        <form method="POST" action="{{ route('ops.journeys.wipe') }}" style="display:inline"
              onsubmit="return confirm({!! $wipeMsg !!})">
            @csrf
            <button class="btn red" type="submit">
                🗑 {{ auth()->user()?->role === 'manager' ? __('journey.wipe_team') : __('journey.wipe_all') }}
            </button>
        </form>
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
                    <select name="rep" onchange="this.form.submit()">
                        @foreach ($reps as $r)
                            <option value="{{ $r->id }}" @selected($rep->id === $r->id)>
                                {{ $r->displayName() }} — {{ $r->roleLabel() }}{{ $r->zone ? ' · '.$r->zone->displayName() : '' }}
                            </option>
                        @endforeach
                    </select>
                </form>

                <span class="jb-weeknav">
                    <a class="btn sm" href="{{ $wLink($prevWeek) }}">‹</a>
                    <b>{{ $weekLabel }}</b>
                    <a class="btn sm" href="{{ $wLink($nextWeek) }}">›</a>
                </span>

                <span class="jb-grow"></span>

                <a class="btn" href="{{ route('ops.geo') }}">🗺️ {{ __('journey.map_btn') }}</a>
                <button type="button" class="btn" onclick="openDlg('dlgCopy')">
                    ⧉ {{ __('journey.copy_btn') }}
                </button>
                <button type="button" class="btn gold" id="jbSaveBtn" onclick="jbSave()">
                    ✓ {{ __('journey.save_btn') }}
                </button>
            </div>

            <div class="jb-statsrow">
                <span class="s" id="jbWeekStats"></span>
                <span class="jb-grow"></span>
                <span class="jb-lg"><i class="dot d-ok"></i>{{ __('journey.lg_ok') }}</span>
                <span class="jb-lg"><i class="dot d-over"></i>{{ __('journey.lg_over') }}</span>
                <span class="jb-lg"><i class="dot d-empty"></i>{{ __('journey.lg_empty') }}</span>
            </div>
        </div>

        <div class="jb-week" id="jbWeek"></div>

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
                @foreach ($weekdays as $d)
                    <div>{{ __('journey.day_'.$d) }}</div>
                @endforeach
            </div>
            <div class="jb-pulse" id="jbPulse"></div>
        </div>
    </div>
</div>

{{-- فورم الحفظ — بيتملى بالجافاسكربت ويتبعت --}}
<form method="POST" action="{{ route('ops.journeys.sync') }}" id="syncForm" style="display:none">
    @csrf
    <input type="hidden" name="user_id" value="{{ $rep->id }}">
    <div id="syncFields"></div>
</form>

{{-- ═══ انسخ من مندوب ═══ --}}
<dialog id="dlgCopy">
    <form class="dlg" method="POST" action="{{ route('ops.journeys.copy') }}">
        @csrf
        <input type="hidden" name="user_id" value="{{ $rep->id }}">
        <h4>{{ __('journey.copy_title') }}</h4>
        <div class="s" style="margin-bottom:10px">{{ __('journey.copy_hint') }}</div>
        <select name="from_id" style="width:100%" required>
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
        $dayDates[$d] = $weekStart->copy()->addDays($d)->format('j/n');
    }

    // نبضة الشهر للجافاسكربت: التاريخ ← عملاؤه (بحالة اتزار/لأ)
    $pulse = [
        'firstCol' => $monthStart->dayOfWeek,
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

    // حالة البورد كلها في المتصفح — الحفظ بيبعت الصورة النهائية مرة واحدة
    const state = {
        days: {0: [], 1: [], 2: [], 3: [], 4: [], 5: [], 6: []},
        pool: BOARD.pool.slice(),
        filter: 'un',
        q: '',
        dirty: false,
    };

    BOARD.plans.forEach(function (p) {
        state.days[p.weekday].push({
            pid: p.id,
            freq: p.every_weeks,
            time: p.time,
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
        state.dirty = true;
        const b = document.getElementById('jbSaveBtn');
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
        renderFilters();

        const q = state.q.trim().toLowerCase();
        const holder = document.getElementById('jbPool');
        let rows = [];

        const match = function (c) {
            if (q && !(c.q || '').includes(q) && !c.name.toLowerCase().includes(q)) return false;
            if (state.filter.startsWith('ch:')) return c.channel === state.filter.slice(3);
            return true;
        };

        state.pool.filter(match).forEach(function (c) { rows.push(poolRow(c)); });

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

        for (let d = 0; d <= 6; d++) {
            const items = state.days[d];
            const st = dayState(items.length);
            const over = items.length - MAX_PER_DAY;

            html += '<div class="jb-day ' + st + (d === TODAY_DOW ? ' istoday' : '') + '" data-day="' + d + '">';
            html += '<div class="jb-dayhead">' +
                '<b>' + DAY_NAMES[d] + '</b>' +
                '<span class="dt">' + DAY_DATES[d] + '</span>' +
                '<span class="cnt">' + items.length +
                (st === 'over' ? ' · ' + T.overload : '') + '</span></div>';

            html += '<div class="jb-daybody" data-day="' + d + '">';

            items.forEach(function (it, i) {
                html += '<div class="jb-chip" draggable="true" data-day="' + d + '" data-idx="' + i + '">' +
                    '<span class="num">' + (i + 1) + '</span>' +
                    '<span class="nm">' + esc(it.c.name) + '</span>' +
                    (it.freq === 2 ? '<span class="fq">' + esc(T.freq2) + '</span>' : '') +
                    (it.freq === 4 ? '<span class="fq">' + esc(T.freq4) + '</span>' : '') +
                    (it.time ? '<span class="fq">🕒 ' + esc(it.time) + '</span>' : '') +
                    '<button type="button" class="x" onclick="removeChip(' + d + ',' + i + ')">✕</button>' +
                    '</div>';
            });

            if (!items.length) {
                html += '<div class="jb-empty">' + (d === 6 ? T.dayOff : T.dragHere) + '</div>';
            }

            html += '<button type="button" class="jb-add" onclick="focusSearch()">＋ ' + esc(T.addBtn) + '</button>';

            if (st === 'over') {
                html += '<div class="jb-warn">' + tt('overHint', {n: over}) + '</div>';
            }

            html += '</div></div>';
        }

        holder.innerHTML = html;

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
            state.days[day].splice(index, 0, {pid: null, freq: 1, time: '', c: c});
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
        const it = state.days[day].splice(idx, 1)[0];
        state.pool.push(it.c);
        state.pool.sort(function (a, b) { return a.name.localeCompare(b.name); });
        markDirty();
        renderWeek();
        renderSide();
    }

    // ═══════════════ وزّع تلقائي بالمنطقة ═══════════════
    // عملاء نفس الزون في نفس اليوم، والزون بيروح لليوم الأخف —
    // السبت إجازة. توزيع محلي: راجعه وبعدين احفظ.
    function jbAutoZone() {
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
                state.days[best].push({pid: null, freq: 1, time: '', c: c});
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
.jb{display:grid;grid-template-columns:290px 1fr;gap:14px;align-items:start}
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
.jb-chip .nm{flex:1;min-width:0;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
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
