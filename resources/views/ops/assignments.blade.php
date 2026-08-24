@extends('layouts.system')

{{--
    تخصيص العملاء والمناطق — الويزارد (إعادة بناء ٢٤ أغسطس ٢٠٢٦
    من موكاب المالك): مندوب واحد · خطوة واحدة · مفيش حفظ من غير
    ما تشوف الأثر.

    ٤ خطوات جافاسكربت خالص على داتا محمّلة مرة واحدة:
      ١) اختار المندوب (كروت بالحمولة الحقيقية)
      ٢) حدّد مناطقه (شجرة محافظات بتشيك + أثر لايف)
      ٣) راجع العملاء (فلاتر: بدون مندوب/داخل مناطقه/مسؤول عنهم/تعارض)
      ٤) الملخّص والحفظ (قبل/بعد + اللي هيتنفّذ) ← POST واحد

    وجنبها حمولة الفريق وصحة التغطية — «مين معاه إيه» في نظرة.
--}}

@section('title', __('assign.title'))

@section('actions')
    <a class="btn" href="{{ route('ops.journeys') }}">📘 {{ __('assign.journeys') }}</a>
    <a class="btn" href="{{ route('ops.live') }}">🖥️ {{ __('assign.live') }}</a>
@endsection

@section('content')

@if ($manager === null)
    <div class="card"><div class="alert"><span>ℹ️</span><span>{{ __('assign.no_managers') }}</span></div></div>
@else

<div style="margin-bottom:4px;color:var(--muted);font-size:11.5px">{{ __('assign.subtitle') }}</div>

{{-- ═══ الشريط العلوي: تابات المديرين + إجماليات الفريق ═══ --}}
<div class="card" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
    <b style="font-size:12.5px">{{ __('assign.manager') }}</b>
    @foreach ($managers as $m)
        <a class="btn sm {{ $m->id === $manager->id ? 'gold' : '' }}"
           href="{{ route('ops.assignments', ['manager' => $m->id]) }}">
            {{ $m->name }}
            <span class="badge {{ $m->id === $manager->id ? 'b-blue' : 'b-gray' }}"
                  style="margin-inline-start:6px">{{ number_format((int) ($mgrCounts[$m->id] ?? 0)) }}</span>
        </a>
    @endforeach
    <span style="margin-inline-start:auto;font-size:12px;color:var(--muted)">
        {{ __('assign.team_now') }}
        <b style="color:var(--ink)">{{ __('assign.n_reps', ['n' => $totals['team']]) }}</b> ·
        <b style="color:var(--ink)">{{ __('assign.n_clients', ['n' => number_format($totals['clients'])]) }}</b> ·
        <b style="color:var(--ink)">{{ __('assign.n_zones', ['n' => $totals['zones']]) }}</b>
    </span>
</div>

{{-- ═══ بانر «من غير مندوب» ═══ --}}
@if ($health['orphans'] > 0)
    <div class="card" style="border:1.5px solid #F2C063;background:#FFF9EC;display:flex;
                             flex-wrap:wrap;gap:12px;align-items:center">
        <span style="font-size:22px">⚠️</span>
        <div style="flex:1;min-width:220px">
            <div style="font-weight:900;font-size:15px">{{ __('assign.orphans_title', ['n' => $health['orphans']]) }}</div>
            <div style="font-size:11.5px;color:var(--muted)">{{ __('assign.orphans_sub') }}</div>
        </div>
        <form method="POST" action="{{ route('ops.assignments.auto') }}"
              onsubmit="return confirm(@js(__('assign.auto_confirm')))">
            @csrf
            <input type="hidden" name="manager_id" value="{{ $manager->id }}">
            <button class="btn gold" type="submit">⚡ {{ __('assign.auto_btn') }}</button>
        </form>
        <button class="btn" type="button" onclick="asgOpenOrphans()">{{ __('assign.open_list') }}</button>
    </div>
@endif

@if ($totals['team'] === 0)
    <div class="card"><div class="alert"><span>ℹ️</span><span>{{ __('assign.no_team') }}</span></div></div>
@else

<div style="display:flex;gap:14px;align-items:flex-start;flex-wrap:wrap">

    {{-- ═══════════ الويزارد ═══════════ --}}
    <div style="flex:1;min-width:600px">
        <div class="card">
            {{-- شريط الخطوات --}}
            <div class="asg-steps">
                @foreach ([1 => 'step1', 2 => 'step2', 3 => 'step3', 4 => 'step4'] as $n => $key)
                    <button type="button" class="asg-step" id="asgTab{{ $n }}" onclick="goStep({{ $n }})">
                        <span class="num">{{ $n }}</span>
                        <span class="tt">
                            <b>{{ __('assign.'.$key) }}</b>
                            <i id="asgSub{{ $n }}"></i>
                        </span>
                    </button>
                @endforeach
            </div>

            {{-- ═══ خطوة ١ — كروت المناديب ═══ --}}
            <div id="asgStep1" class="asg-pane">
                <div class="asg-hint">{{ __('assign.step1_hint') }}</div>
                <div class="asg-grid" id="asgReps"></div>
            </div>

            {{-- ═══ خطوة ٢ — شجرة المناطق + الأثر ═══ --}}
            <div id="asgStep2" class="asg-pane" style="display:none">
                <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:10px">
                    <b id="asgZoneHead" style="font-size:13px">📍</b>
                    <span class="asg-hint" style="margin:0;flex:1">{{ __('assign.step2_hint') }}</span>
                    <button class="btn sm" type="button" onclick="asgClearZones()">{{ __('assign.clear_all') }}</button>
                </div>
                <div style="display:flex;gap:12px;align-items:flex-start;flex-wrap:wrap">
                    <div id="asgTree" style="flex:1;min-width:320px;max-height:56vh;overflow-y:auto;
                                             border:1px solid var(--border);border-radius:12px"></div>
                    <div style="width:230px;flex-shrink:0">
                        <div style="border:1px solid var(--border);border-radius:12px;padding:12px 14px">
                            <b style="font-size:12px">{{ __('assign.effect_title') }}</b>
                            <div class="asg-eff"><b id="asgEffZones" style="color:var(--royal-blue,#12399B)">0</b><span>{{ __('assign.effect_zones') }}</span></div>
                            <div class="asg-eff"><b id="asgEffClients" style="color:#602D90">0</b><span>{{ __('assign.effect_clients') }}</span></div>
                            <div class="asg-eff"><b id="asgEffOrphans" style="color:#EA8C1C">0</b><span>{{ __('assign.effect_orphans') }}</span></div>
                            <div class="asg-eff"><b id="asgEffOverlap">0</b><span>{{ __('assign.effect_overlap') }}</span></div>
                            <div style="font-size:10.5px;color:var(--muted);line-height:1.7;margin-top:8px">{{ __('assign.effect_note') }}</div>
                        </div>
                        <button class="btn gold" type="button" style="width:100%;margin-top:10px"
                                onclick="goStep(3)">{{ __('assign.go_review') }}</button>
                    </div>
                </div>
            </div>

            {{-- ═══ خطوة ٣ — مراجعة العملاء ═══ --}}
            <div id="asgStep3" class="asg-pane" style="display:none">
                <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:10px">
                    <span id="asgChips"></span>
                    <input type="search" id="asgQ" placeholder="🔍 {{ __('assign.search_ph') }}"
                           style="flex:1;min-width:220px" oninput="asgRenderClients()">
                </div>
                <div class="tablewrap" style="max-height:52vh;overflow-y:auto">
                    <table>
                        <thead>
                            <tr>
                                <th style="width:34px"></th>
                                <th style="text-align:start">{{ __('assign.c_client') }}</th>
                                <th>{{ __('assign.c_zone') }}</th>
                                <th>{{ __('assign.c_resp') }}</th>
                                <th>{{ __('assign.c_after') }}</th>
                            </tr>
                        </thead>
                        <tbody id="asgCliBody"></tbody>
                    </table>
                </div>
                <div id="asgCap" style="font-size:11px;color:var(--muted);margin-top:6px"></div>
                <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:10px">
                    <button class="btn sm" type="button" onclick="asgMarkVisible()">{{ __('assign.mark_visible') }}</button>
                    <span id="asgMarked" style="font-size:12px;color:var(--muted)"></span>
                    <button class="btn gold" type="button" style="margin-inline-start:auto"
                            onclick="goStep(4)">{{ __('assign.go_summary') }}</button>
                </div>
            </div>

            {{-- ═══ خطوة ٤ — الملخّص والحفظ ═══ --}}
            <div id="asgStep4" class="asg-pane" style="display:none">
                <b id="asgSumHead" style="font-size:13.5px">🧾</b>
                <div style="display:flex;gap:12px;flex-wrap:wrap;margin:12px 0">
                    <div class="asg-ba"><div class="l">{{ __('assign.before') }}</div>
                        <div class="r"><span>{{ __('assign.sum_clients') }}</span><b id="asgB1">0</b></div>
                        <div class="r"><span>{{ __('assign.sum_zones') }}</span><b id="asgB2">0</b></div>
                    </div>
                    <div style="align-self:center;font-size:20px;color:var(--muted)">←</div>
                    <div class="asg-ba on"><div class="l">{{ __('assign.after') }}</div>
                        <div class="r"><span>{{ __('assign.sum_clients') }}</span><b id="asgA1">0</b></div>
                        <div class="r"><span>{{ __('assign.sum_zones') }}</span><b id="asgA2">0</b></div>
                    </div>
                </div>
                <div style="border:1px solid var(--border);border-radius:12px;padding:12px 15px">
                    <b style="font-size:12px">{{ __('assign.will_run') }}</b>
                    <div id="asgRunList" style="font-size:12px;line-height:2.1;margin-top:6px"></div>
                </div>
                <form id="asgForm" method="POST" action="{{ route('ops.assignments.apply') }}"
                      onsubmit="return asgSubmit()">
                    @csrf
                    <input type="hidden" name="rep_id" id="asgRepId">
                    <div id="asgHid"></div>
                    <button class="btn gold" type="submit" id="asgSaveBtn"
                            style="width:100%;margin-top:14px;font-size:14px;padding:12px">
                        💾 {{ __('assign.save') }}</button>
                </form>
            </div>
        </div>
    </div>

    {{-- ═══════════ السايدبار: الحمولة والصحة ═══════════ --}}
    <div style="width:290px;flex-shrink:0">
        <div class="card">
            <h3 style="font-size:13px">👥 {{ __('assign.load_title') }}</h3>
            @php $maxLoad = max(1, collect($team)->max('clients')); @endphp
            @foreach ($team as $r)
                <div style="margin-top:8px">
                    <div style="display:flex;justify-content:space-between;font-size:11.5px">
                        <b>{{ $r['name'] }}</b>
                        <b style="color:var(--royal-blue,#12399B)" dir="ltr">{{ $r['clients'] }}</b>
                    </div>
                    <div class="asg-bar"><i style="width:{{ round($r['clients'] / $maxLoad * 100) }}%"></i></div>
                </div>
            @endforeach
            @php
                $loads = collect($team)->pluck('clients');
                $avg = $loads->isEmpty() ? 0 : (int) round($loads->avg());
                $gap = $loads->isEmpty() ? 0 : ($loads->max() - $loads->min());
            @endphp
            <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--muted);margin-top:10px">
                <span>{{ __('assign.avg_line') }}</span><b style="color:var(--ink)">{{ $avg }}</b>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--muted)">
                <span>{{ __('assign.gap_line') }}</span>
                <b style="color:#EA8C1C">{{ __('assign.gap_clients', ['n' => $gap]) }}</b>
            </div>
        </div>

        <div class="card">
            <h3 style="font-size:13px">🩺 {{ __('assign.health_title') }}</h3>
            @foreach ([
                ['h_orphans', $health['orphans'], '#EA8C1C'],
                ['h_unmarked', $health['unmarked'], $health['unmarked'] > 0 ? '#DC2626' : '#16A34A'],
                ['h_empty', $health['empty'], '#6B6B7B'],
                ['h_nozone', $health['nozone'], $health['nozone'] > 0 ? '#DC2626' : '#16A34A'],
            ] as [$k, $v, $clr])
                <div style="display:flex;justify-content:space-between;align-items:center;
                            border-bottom:1px solid var(--border);padding:6px 0;font-size:11.5px">
                    <span>● {{ __('assign.'.$k) }}</span>
                    <b style="color:{{ $clr }}" dir="ltr">{{ $v }}</b>
                </div>
            @endforeach
            <div style="margin-top:10px;background:#F2ECFF;border-radius:10px;padding:9px 11px;
                        font-size:10.5px;line-height:1.8;color:#602D90">{{ __('assign.rule') }}</div>
        </div>
    </div>
</div>

@endif
@endif

@endsection

@section('scripts')
@if ($manager !== null && $totals['team'] > 0)
@php
    $jsFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP;
@endphp
<script>
const TEAM = {!! json_encode($team, $jsFlags) !!};
const ZONES = {!! json_encode($zoneRows, $jsFlags) !!};
const CLIENTS = {!! json_encode($clientRows, $jsFlags) !!};
const REPZONES = {!! json_encode((object) $repZones, $jsFlags) !!};
const TOTALS = {!! json_encode($totals, $jsFlags) !!};
const MGR = @json($manager->name);
const T = {
    pickFirst: @json(__('assign.pick_first')),
    cClients: @json(__('assign.c_clients')), cZones: @json(__('assign.c_zones')), cVisits: @json(__('assign.c_visits')),
    loadEmpty: @json(__('assign.load_empty')), loadOver: @json(__('assign.load_over')), loadOk: @json(__('assign.load_ok')),
    zoneHead: @json(__('assign.step2_head', ['name' => '__N__'])),
    govLine: @json(__('assign.gov_line', ['z' => '__Z__', 'c' => '__C__'])),
    withRep: @json(__('assign.with_rep', ['name' => '__N__'])),
    fAll: @json(__('assign.f_all')), fOrphans: @json(__('assign.f_orphans')),
    fInzones: @json(__('assign.f_inzones')), fResp: @json(__('assign.f_resp')), fConflict: @json(__('assign.f_conflict')),
    poolOf: @json(__('assign.pool_of', ['name' => '__N__'])),
    inZones: @json(__('assign.in_zones')), outZones: @json(__('assign.out_zones')), noZone: @json(__('assign.no_zone')),
    noChange: @json(__('assign.no_change')), willMove: @json(__('assign.will_move', ['name' => '__N__'])),
    markedLine: @json(__('assign.marked_line', ['n' => '__N__'])),
    moreHidden: @json(__('assign.more_hidden', ['n' => '__N__', 'cap' => '__C__'])),
    sumHead: @json(__('assign.sum_head', ['name' => '__N__'])),
    runClients: @json(__('assign.run_clients', ['n' => '__N__', 'name' => '__R__'])),
    runZones: @json(__('assign.run_zones', ['n' => '__N__'])),
    runUnmark: @json(__('assign.run_unmark', ['n' => '__N__'])),
    runCoverage: @json(__('assign.run_coverage')),
    runNoCross: @json(__('assign.run_no_cross')),
    saveConfirm: @json(__('assign.save_confirm', ['name' => '__N__'])),
    step2Sub: @json(__('assign.step2_sub', ['n' => '__N__'])),
    step3Sub: @json(__('assign.step3_sub', ['n' => '__N__'])),
    step4Sub: @json(__('assign.step4_sub')),
};

const st = {step: 1, rep: null, zones: new Set(), clis: new Set(), filter: 'all', open: new Set()};

const esc = s => String(s ?? '').replace(/[&<>"']/g,
    ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
const fmt = n => Number(n).toLocaleString('en-US');
const rep = () => TEAM.find(r => r.id === st.rep);
const tpl = (s, m) => Object.entries(m).reduce((o, kv) => o.replaceAll(kv[0], kv[1]), s);

function goStep(n) {
    if (n > 1 && st.rep === null) { alert(T.pickFirst); return; }
    st.step = n;
    for (let i = 1; i <= 4; i++) {
        document.getElementById('asgStep' + i).style.display = i === n ? '' : 'none';
        const tab = document.getElementById('asgTab' + i);
        tab.classList.toggle('on', i === n);
        tab.classList.toggle('done', i < n && st.rep !== null);
    }
    if (n === 4) renderSummary();
}

function subs() {
    document.getElementById('asgSub1').textContent = rep() ? rep().name : '';
    document.getElementById('asgSub2').textContent = tpl(T.step2Sub, {'__N__': st.zones.size});
    document.getElementById('asgSub3').textContent = tpl(T.step3Sub, {'__N__': st.clis.size});
    document.getElementById('asgSub4').textContent = T.step4Sub;
}

{{-- ═══ خطوة ١ ═══ --}}
function renderReps() {
    const avg = TEAM.reduce((t, r) => t + r.clients, 0) / Math.max(1, TEAM.length);
    const mx = Math.max(1, ...TEAM.map(r => r.clients));

    document.getElementById('asgReps').innerHTML = TEAM.map(function (r) {
        const load = r.clients === 0 ? ['loadEmpty', 'var(--muted)']
            : (r.clients > avg * 1.3 ? ['loadOver', '#EA8C1C'] : ['loadOk', '#16A34A']);

        return '<div class="asg-rep' + (r.id === st.rep ? ' sel' : '') + '" onclick="pickRep(' + r.id + ')">' +
            '<div style="display:flex;justify-content:space-between;align-items:center">' +
                '<div><b style="font-size:13px">' + esc(r.name) + '</b>' +
                '<div style="font-size:10.5px;color:var(--muted)">' + esc(r.label) + '</div></div>' +
                '<span class="asg-av">' + esc(r.name.slice(0, 2)) + '</span></div>' +
            '<div class="asg-nums">' +
                '<span><b>' + fmt(r.clients) + '</b><i>' + esc(T.cClients) + '</i></span>' +
                '<span><b>' + fmt(r.zones) + '</b><i>' + esc(T.cZones) + '</i></span>' +
                '<span><b>' + fmt(r.visits) + '</b><i>' + esc(T.cVisits) + '</i></span></div>' +
            '<div class="asg-bar"><i style="width:' + Math.round(r.clients / mx * 100) + '%"></i></div>' +
            '<div style="font-size:10.5px;font-weight:800;color:' + load[1] + ';margin-top:4px">' + esc(T[load[0]]) + '</div>' +
        '</div>';
    }).join('');
}

function pickRep(id) {
    st.rep = id;
    st.zones = new Set(REPZONES[id] || []);
    st.clis.clear();
    renderReps(); renderTree(); asgRenderClients(); subs();
    goStep(2);
}

{{-- ═══ خطوة ٢ ═══ --}}
function govs() {
    const map = {};
    ZONES.forEach(z => { (map[z.gov || '—'] = map[z.gov || '—'] || []).push(z); });
    return Object.entries(map).sort((a, b) => b[1].reduce((t, z) => t + z.clients, 0) - a[1].reduce((t, z) => t + z.clients, 0));
}

function renderTree() {
    document.getElementById('asgZoneHead').textContent = '📍 ' + tpl(T.zoneHead, {'__N__': rep() ? rep().name : ''});

    document.getElementById('asgTree').innerHTML = govs().map(function (g) {
        const zs = g[1];
        const sel = zs.filter(z => st.zones.has(z.id)).length;
        const open = st.open.has(g[0]);
        const all = sel === zs.length && zs.length > 0;

        let html = '<div class="asg-gov">' +
            '<button type="button" class="x" onclick="asgGov(' + JSON.stringify(g[0]).replaceAll('"', '&quot;') + ')">' + (open ? '−' : '+') + '</button>' +
            '<input type="checkbox"' + (all ? ' checked' : '') + ' onchange="asgGovAll(' + JSON.stringify(g[0]).replaceAll('"', '&quot;') + ', this.checked)">' +
            (sel > 0 && ! all ? '<span class="badge b-green">' + sel + ' ✓</span>' : '') +
            '<b style="flex:1">' + esc(g[0]) + '</b>' +
            '<span style="font-size:10.5px;color:var(--muted)">' + esc(tpl(T.govLine, {'__Z__': zs.length, '__C__': fmt(zs.reduce((t, z) => t + z.clients, 0))})) + '</span>' +
        '</div>';

        if (open) {
            html += zs.map(function (z) {
                const others = (z.marked_names || []).filter((n, i) => z.marked_by[i] !== st.rep);

                return '<div class="asg-zone">' +
                    '<input type="checkbox"' + (st.zones.has(z.id) ? ' checked' : '') + ' onchange="asgZone(' + z.id + ')">' +
                    '<b style="flex:1">' + esc(z.name) + '</b>' +
                    others.map(n => '<span class="badge b-gray" style="font-size:9.5px">' + esc(tpl(T.withRep, {'__N__': n})) + '</span>').join(' ') +
                    '<b dir="ltr" style="min-width:34px;text-align:end;color:var(--royal-blue,#12399B)">' + fmt(z.clients) + '</b>' +
                '</div>';
            }).join('');
        }

        return html;
    }).join('');

    effect();
    subs();
}

function asgGov(name) { st.open.has(name) ? st.open.delete(name) : st.open.add(name); renderTree(); }
function asgGovAll(name, on) {
    ZONES.filter(z => (z.gov || '—') === name).forEach(z => { on ? st.zones.add(z.id) : st.zones.delete(z.id); });
    renderTree(); asgRenderClients();
}
function asgZone(id) { st.zones.has(id) ? st.zones.delete(id) : st.zones.add(id); renderTree(); asgRenderClients(); }
function asgClearZones() { st.zones.clear(); renderTree(); asgRenderClients(); }

function effect() {
    const inz = CLIENTS.filter(c => c.zone_id && st.zones.has(c.zone_id));
    document.getElementById('asgEffZones').textContent = fmt(st.zones.size);
    document.getElementById('asgEffClients').textContent = fmt(inz.length);
    document.getElementById('asgEffOrphans').textContent = fmt(inz.filter(c => ! c.rep_id).length);
    document.getElementById('asgEffOverlap').textContent =
        fmt(ZONES.filter(z => st.zones.has(z.id) && (z.marked_by || []).some(u => u !== st.rep)).length);
}

{{-- ═══ خطوة ٣ ═══ --}}
function cliFiltered() {
    const q = (document.getElementById('asgQ').value || '').trim().toLowerCase();

    return CLIENTS.filter(function (c) {
        if (st.filter === 'orphans' && c.rep_id) return false;
        if (st.filter === 'inzones' && ! (c.zone_id && st.zones.has(c.zone_id))) return false;
        if (st.filter === 'resp' && c.rep_id !== st.rep) return false;
        if (st.filter === 'conflict' && ! (c.rep_id && c.rep_id !== st.rep && c.zone_id && st.zones.has(c.zone_id))) return false;
        if (q && ! ((c.name || '').toLowerCase().includes(q) || (c.en || '').toLowerCase().includes(q))) return false;
        return true;
    });
}

function asgSetFilter(f) { st.filter = f; asgRenderClients(); }

function asgRenderClients() {
    const counts = {
        all: CLIENTS.length,
        orphans: CLIENTS.filter(c => ! c.rep_id).length,
        inzones: CLIENTS.filter(c => c.zone_id && st.zones.has(c.zone_id)).length,
        resp: CLIENTS.filter(c => c.rep_id === st.rep).length,
        conflict: CLIENTS.filter(c => c.rep_id && c.rep_id !== st.rep && c.zone_id && st.zones.has(c.zone_id)).length,
    };

    document.getElementById('asgChips').innerHTML = [
        ['all', T.fAll], ['orphans', T.fOrphans], ['inzones', T.fInzones], ['resp', T.fResp], ['conflict', T.fConflict],
    ].map(f => '<button type="button" class="btn sm' + (st.filter === f[0] ? ' gold' : '') + '" onclick="asgSetFilter(\'' + f[0] + '\')">' +
        esc(f[1]) + ' <span class="badge ' + (st.filter === f[0] ? 'b-blue' : 'b-gray') + '">' + fmt(counts[f[0]]) + '</span></button>').join(' ');

    const rows = cliFiltered();

    document.getElementById('asgCliBody').innerHTML = rows.slice(0, 400).map(function (c) {
        const inz = c.zone_id && st.zones.has(c.zone_id);
        const zoneBadge = c.zone_id
            ? '<span class="badge ' + (inz ? 'b-green' : 'b-orange') + '" style="font-size:9.5px">' + esc(inz ? T.inZones : T.outZones) + '</span>'
            : '<span class="badge b-red" style="font-size:9.5px">' + esc(T.noZone) + '</span>';
        const resp = c.rep_id
            ? '<span class="badge b-gray">' + esc(c.rep) + '</span>'
            : '<span class="badge b-gold">🪙 ' + esc(tpl(T.poolOf, {'__N__': MGR})) + '</span>';
        const after = st.clis.has(c.id)
            ? '<b style="color:#16A34A">' + esc(tpl(T.willMove, {'__N__': rep() ? rep().name : ''})) + '</b>'
            : '<span style="color:var(--muted)">' + esc(T.noChange) + '</span>';

        return '<tr class="clickable" onclick="asgCli(' + c.id + ')">' +
            '<td><input type="checkbox"' + (st.clis.has(c.id) ? ' checked' : '') + ' style="pointer-events:none"></td>' +
            '<td style="text-align:start"><b>' + esc(c.name) + '</b>' +
                (c.en ? '<div style="font-size:10px;color:var(--muted)">' + esc(c.en) + '</div>' : '') + '</td>' +
            '<td style="font-size:11.5px">' + esc(c.zone || '') + '<div>' + zoneBadge + '</div></td>' +
            '<td>' + resp + '</td>' +
            '<td style="font-size:11.5px">' + after + '</td>' +
        '</tr>';
    }).join('') || '<tr><td colspan="5" style="text-align:center;color:var(--muted);padding:22px">—</td></tr>';

    const hidden = TOTALS.clients - TOTALS.loaded;
    document.getElementById('asgCap').textContent = hidden > 0
        ? tpl(T.moreHidden, {'__N__': fmt(hidden), '__C__': fmt(TOTALS.loaded)}) : '';

    document.getElementById('asgMarked').innerHTML =
        esc(tpl(T.markedLine, {'__N__': st.clis.size})) + ' <b>' + esc(rep() ? rep().name : '') + '</b>';

    subs();
}

function asgCli(id) { st.clis.has(id) ? st.clis.delete(id) : st.clis.add(id); asgRenderClients(); }
function asgMarkVisible() { cliFiltered().forEach(c => st.clis.add(c.id)); asgRenderClients(); }
function asgOpenOrphans() {
    if (st.rep === null && TEAM.length) pickRep(TEAM[0].id);
    st.filter = 'orphans';
    asgRenderClients();
    goStep(3);
}

{{-- ═══ خطوة ٤ ═══ --}}
function renderSummary() {
    const r = rep();
    if (! r) return;

    const moving = [...st.clis].filter(id => (CLIENTS.find(c => c.id === id) || {}).rep_id !== r.id);
    const before = REPZONES[r.id] || [];
    const removed = before.filter(z => ! st.zones.has(z)).length;
    const added = [...st.zones].filter(z => ! before.includes(z)).length;

    document.getElementById('asgSumHead').textContent = '🧾 ' + tpl(T.sumHead, {'__N__': r.name});
    document.getElementById('asgB1').textContent = fmt(r.clients);
    document.getElementById('asgB2').textContent = fmt(before.length);
    document.getElementById('asgA1').textContent = fmt(r.clients + moving.length);
    document.getElementById('asgA2').textContent = fmt(st.zones.size);

    let list = '<div>👥 ' + esc(tpl(T.runClients, {'__N__': fmt(moving.length), '__R__': r.name})) + '</div>' +
        '<div>📍 ' + esc(tpl(T.runZones, {'__N__': fmt(added)})) + '</div>';
    if (removed > 0) list += '<div style="color:#DC2626">➖ ' + esc(tpl(T.runUnmark, {'__N__': fmt(removed)})) + '</div>';
    list += '<div>🔗 ' + esc(T.runCoverage) + '</div>' +
        '<div>🚧 ' + esc(T.runNoCross) + '</div>';
    document.getElementById('asgRunList').innerHTML = list;
}

function asgSubmit() {
    const r = rep();
    if (! r) return false;
    if (! confirm(tpl(T.saveConfirm, {'__N__': r.name}))) return false;

    document.getElementById('asgRepId').value = r.id;
    const hid = document.getElementById('asgHid');
    hid.innerHTML = '';

    [...st.zones].forEach(function (z) {
        const i = document.createElement('input');
        i.type = 'hidden'; i.name = 'zones[]'; i.value = z;
        hid.appendChild(i);
    });
    [...st.clis].forEach(function (c) {
        const i = document.createElement('input');
        i.type = 'hidden'; i.name = 'clients[]'; i.value = c;
        hid.appendChild(i);
    });

    return true;
}

renderReps();
subs();
goStep(1);
</script>
@endif
<style>
.asg-steps{display:flex;border-bottom:2px solid var(--border);margin-bottom:14px;flex-wrap:wrap}
.asg-step{flex:1;min-width:130px;display:flex;gap:8px;align-items:center;background:none;border:none;
  border-bottom:3px solid transparent;padding:9px 10px;cursor:pointer;font-family:inherit;text-align:start}
.asg-step .num{width:24px;height:24px;border-radius:50%;background:var(--card2,#F1F1F4);color:var(--muted);
  display:inline-flex;align-items:center;justify-content:center;font-weight:900;font-size:12px;flex-shrink:0}
.asg-step.on{border-bottom-color:var(--royal-blue,#12399B)}
.asg-step.on .num{background:var(--royal-blue,#12399B);color:#fff}
.asg-step.done .num{background:#16A34A;color:#fff}
.asg-step .tt{display:flex;flex-direction:column;min-width:0}
.asg-step .tt b{font-size:12px}
.asg-step .tt i{font-style:normal;font-size:10px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.asg-hint{font-size:11.5px;color:var(--muted);margin-bottom:12px;line-height:1.8}
.asg-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(215px,1fr));gap:12px}
.asg-rep{border:1.5px solid var(--border);border-radius:14px;padding:12px 14px;cursor:pointer;background:#fff}
.asg-rep:hover{border-color:var(--royal-blue,#12399B)}
.asg-rep.sel{border-color:var(--royal-blue,#12399B);background:var(--blue-050,#E8F1FF)}
.asg-av{width:34px;height:34px;border-radius:50%;background:var(--brand-gradient,linear-gradient(135deg,#12399B,#602D90));
  color:#fff;display:inline-flex;align-items:center;justify-content:center;font-weight:900;font-size:12px;flex-shrink:0}
.asg-nums{display:flex;gap:8px;margin-top:9px}
.asg-nums span{flex:1;background:var(--card2,#F1F1F4);border-radius:9px;padding:5px 4px;text-align:center;
  display:flex;flex-direction:column}
.asg-nums b{font-size:13px}
.asg-nums i{font-style:normal;font-size:9.5px;color:var(--muted)}
.asg-bar{height:5px;background:var(--card2,#F1F1F4);border-radius:99px;margin-top:8px;overflow:hidden}
.asg-bar i{display:block;height:100%;background:var(--brand-gradient,linear-gradient(135deg,#12399B,#602D90));border-radius:99px}
.asg-gov{display:flex;gap:8px;align-items:center;padding:8px 12px;background:var(--card2,#F7F7FA);
  border-bottom:1px solid var(--border);font-size:12px}
.asg-gov .x{width:22px;height:22px;border:1px solid var(--border);border-radius:7px;background:#fff;
  cursor:pointer;font-weight:900;flex-shrink:0}
.asg-zone{display:flex;gap:8px;align-items:center;padding:7px 12px 7px 12px;padding-inline-start:32px;
  border-bottom:1px solid var(--border);font-size:11.5px;background:#fff}
.asg-gov input[type=checkbox],.asg-zone input[type=checkbox]{width:16px;height:16px;flex-shrink:0}
.asg-eff{display:flex;justify-content:space-between;align-items:center;font-size:11.5px;
  border-bottom:1px dashed var(--border);padding:6px 0}
.asg-eff b{font-size:15px}
.asg-ba{flex:1;min-width:170px;border:1.5px solid var(--border);border-radius:12px;padding:10px 14px}
.asg-ba.on{border-color:var(--royal-blue,#12399B);background:var(--blue-050,#E8F1FF)}
.asg-ba .l{font-size:10.5px;color:var(--muted);margin-bottom:5px}
.asg-ba .r{display:flex;justify-content:space-between;font-size:12px;padding:2px 0}
.asg-ba .r b{color:var(--royal-blue,#12399B)}
</style>
@endsection
