{{--
    ═══ تاب المصفوفة: رول (أساسي/مخصص) أو موظف بعينه ═══
    قسم ← صفحة ← شاشاتها وزرايرها. كل سطر: سماح/منع/وراثة، الفعلي، المصدر،
    الخطورة، والوصف. الحفظ: «راجع» ⇒ erp.perms.diff ⇒ مودال الفرق ⇒ تأكيد.
    ⚠️ الجافاسكربت بيبعت المتغير بس (perm[key]=''|'0'|'1') — عشان حفظ أدمن تاني
    في نفس الوقت مايترجعش. من غير جافاسكربت الفورم بيبعت الكل وبيشتغل برضه.
--}}
@php
    use App\Http\Controllers\PermissionController as PC;
    use App\Support\PermissionCatalog;

    $isUser = $subjectUser !== null;
    $flat = PC::flatRows($tree);
    $overrides = count(array_filter($flat, fn ($r) => $r['override'] !== null));
    $subjectInfo = $isUser ? null : $roles[$subjectRole];

    /** التحكم التلاتي — `checked` لازق في `value` عن قصد (التيستات بتدور عليه) */
    $seg = function (string $key, ?bool $override, string $label) {
        $cur = $override === null ? '' : ($override ? '1' : '0');
        $html = '<div class="pm-seg" role="radiogroup" aria-label="'.e($label).'">';

        foreach (['1' => 'perm.seg_allow', '0' => 'perm.seg_deny', '' => 'perm.seg_inherit'] as $v => $t) {
            $v = (string) $v;
            $html .= '<label><input type="radio" name="perm['.e($key).']" value="'.$v.'"'.($cur === $v ? ' checked' : '')
                .'><span class="v-'.$v.'">'.e(__($t)).'</span></label>';
        }

        return $html.'</div>';
    };

    /** نص البحث للسطر: الاسم + المفتاح + الوصف + البيانات */
    $q = fn (array $r) => mb_strtolower($r['label'].' '.$r['key'].' '.($r['what'] ?? '').' '.($r['data'] ?? ''));
    $ovAttr = fn (?bool $v) => $v === null ? '' : ($v ? '1' : '0');
    $riskLabels = array_combine(PermissionCatalog::LEVELS, array_map(fn ($l) => PermissionCatalog::riskLabel($l), PermissionCatalog::LEVELS));
@endphp

{{-- ═══ مين اللي بنظبطه ═══ --}}
<div class="card" data-noprint>
    <div style="display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end">
        <form method="GET" action="{{ route('erp.perms') }}">
            <input type="hidden" name="tab" value="matrix">
            <label class="fl wide"><span>👥 {{ __('perm.pick_role') }}</span>
                <select name="role" onchange="this.form.submit()">
                    <optgroup label="{{ __('perm.builtin_roles') }}">
                        @foreach ($roles as $k => $r)
                            @continue($r['custom'])
                            <option value="{{ $k }}" @selected(! $isUser && $subjectRole === $k)>{{ $r['label'] }}</option>
                        @endforeach
                    </optgroup>
                    @if (collect($roles)->contains('custom', true))
                        <optgroup label="{{ __('perm.custom_roles') }}">
                            @foreach ($roles as $k => $r)
                                @continue(! $r['custom'])
                                <option value="{{ $k }}" @selected(! $isUser && $subjectRole === $k)>{{ $r['label'] }} — {{ __('enums.role.'.$r['base']) }}</option>
                            @endforeach
                        </optgroup>
                    @endif
                </select></label>
        </form>
        <span class="pm-muted" style="padding-bottom:10px">{{ __('perm.or') }}</span>
        <form method="GET" action="{{ route('erp.perms') }}">
            <input type="hidden" name="tab" value="matrix">
            <label class="fl wide"><span>🧍 {{ __('perm.pick_user') }}</span>
                <select name="user" onchange="this.form.submit()">
                    <option value="">{{ __('ui.choose', ['x' => __('ui.l_user')]) }}</option>
                    @foreach ($users as $u2)
                        <option value="{{ $u2->id }}" @selected($isUser && $subjectUser->id === $u2->id)>{{ $u2->name }} — {{ $u2->roleLabel() }}@if($u2->code) ({{ $u2->code }})@endif</option>
                    @endforeach
                </select></label>
        </form>
    </div>
</div>

<div class="card">
    <h3>🧩 {{ $isUser ? $subjectUser->name : $subjectInfo['label'] }}
        @if ($isUser)
            <span class="badge b-orange">{{ __('perm.user_exception') }}</span>
        @else
            <span class="badge {{ $subjectInfo['custom'] ? 'b-blue' : 'b-gray' }}">{{ $subjectInfo['custom'] ? __('perm.custom') : __('perm.builtin') }}</span>
        @endif
        <span class="side">{{ __('perm.overrides_n', ['n' => $overrides]) }}</span></h3>

    <div style="display:flex;flex-wrap:wrap;gap:8px 18px;align-items:center;font-size:12.5px">
        @if ($isUser)
            <span>{{ __('perm.c_role') }}: <b>{{ $subjectUser->roleLabel() }}</b></span>
            <span class="pm-muted">{{ __('perm.user_hint') }}</span>
        @else
            <span>{{ __('perm.base') }}: <b>{{ __('enums.role.'.$subjectInfo['base']) }}</b></span>
            <span>{{ __('perm.c_users') }}: <b>{{ $subjectInfo['active_users'] }}</b></span>
            <span class="pm-muted">{{ $subjectInfo['custom'] ? __('perm.editing_custom') : __('perm.editing_role') }}</span>
            <span style="margin-inline-start:auto;display:flex;gap:6px;flex-wrap:wrap">
                <a class="btn sm" href="{{ $tabUrl('sheet', ['role' => $subjectRole]) }}">📄 {{ __('perm.tab_sheet') }}</a>
                @if ($subjectInfo['custom'])
                    <a class="btn sm" href="{{ $tabUrl('compare', ['a' => $subjectRole, 'b' => $subjectInfo['base'], 'only' => 1]) }}">⚖️ {{ __('perm.compare_with_base') }}</a>
                @endif
            </span>
        @endif
    </div>

    <div class="pm-legend">
        <span><b>{{ __('perm.legend') }}:</b></span>
        @foreach (['code', 'base', 'role', 'user'] as $s)
            @continue($s === 'base' && ! $isUser && ! $subjectInfo['custom'])
            @continue($s === 'user' && ! $isUser)
            <span>{!! $src($s) !!} {{ __('perm.src_hint_'.$s) }}</span>
        @endforeach
        <span>{!! $dot(true) !!} / {!! $dot(false) !!} {{ __('perm.legend_effective') }}</span>
        <span><b>{{ __('perm.seg_inherit') }}</b> = {{ $isUser ? __('perm.inherit_user') : ($subjectInfo['custom'] ? __('perm.inherit_custom') : __('perm.inherit_builtin')) }}</span>
    </div>
</div>

<form method="POST" id="pmForm"
      action="{{ $isUser ? route('erp.perms.save', $subjectUser) : route('erp.perms.role.save', $subjectRole) }}"
      data-diff="{{ route('erp.perms.diff') }}"
      data-subject-field="{{ $isUser ? 'user' : 'role' }}"
      data-subject="{{ $isUser ? $subjectUser->id : $subjectRole }}">
    @csrf

    {{-- ═══ الأدوات: بحث + فلاتر + طي/فتح ═══ --}}
    <div class="card pm-bar">
        <label class="fl grow"><span>{{ __('perm.search') }}</span>
            <input type="search" id="pmQ" placeholder="🔍 {{ __('perm.search_ph') }}" autocomplete="off"></label>
        <label class="pm-chk"><input type="checkbox" id="pmOnly" @checked(request()->boolean('only'))> {{ __('perm.only_diff') }}</label>
        <label class="pm-chk"><input type="checkbox" id="pmHigh"> {{ __('perm.only_high') }}</label>
        <button class="btn sm" type="button" data-pm-fold="1">▸ {{ __('perm.fold_all') }}</button>
        <button class="btn sm" type="button" data-pm-fold="0">▾ {{ __('perm.open_all') }}</button>
    </div>
    <div class="alert info" id="pmNone" hidden style="margin-bottom:12px"><span>🔍</span><span>{{ __('perm.no_match') }}</span></div>

    @foreach ($tree as $g)
        @php
            $pagesOn = count(array_filter($g['pages'], fn ($p) => $p['effective']));
        @endphp
        <section class="card pm-grp" data-pm-grp>
            <div class="pm-ghead">
                <button type="button" class="pm-gtitle" data-pm-gtoggle aria-expanded="true">
                    <span class="pm-caret" aria-hidden="true">▾</span>
                    <span class="pm-gico" aria-hidden="true">{{ $g['icon'] }}</span>
                    <span><b>{{ $g['label'] }}</b>
                        <span class="pm-muted">{{ __('perm.pages_on', ['on' => $pagesOn, 'all' => count($g['pages'])]) }}</span></span>
                </button>
                <div class="pm-gmeta">
                    {!! $dot($g['effective']) !!}
                    @if (! $g['virtual']) {!! $src($g['source']) !!} @endif
                </div>
                <div class="pm-gctl" data-pm-row data-ov="{{ $ovAttr($g['override']) }}" data-q="{{ mb_strtolower($g['label'].' '.$g['key']) }}" data-risk="{{ $g['risk'] }}" data-kind="group">
                    @if ($g['virtual'])
                        <span class="pm-lock">{{ __('perm.public_group_hint') }}</span>
                    @else
                        {!! $seg($g['key'], $g['override'], __('perm.group_setting', ['x' => $g['label']])) !!}
                    @endif
                </div>
            </div>
            <div class="pm-gbody">
                <div class="pm-bulk">
                    <span class="pm-muted">{{ $g['virtual'] ? __('perm.public_pages_hint') : __('perm.group_hint') }}</span>
                    <span style="margin-inline-start:auto;display:flex;gap:5px;flex-wrap:wrap" role="group" aria-label="{{ __('perm.bulk', ['x' => $g['label']]) }}">
                        <span class="pm-muted" style="align-self:center">{{ __('perm.bulk_all') }}</span>
                        <button class="btn sm" type="button" data-pm-bulk="1">{{ __('perm.seg_allow') }}</button>
                        <button class="btn sm" type="button" data-pm-bulk="0">{{ __('perm.seg_deny') }}</button>
                        <button class="btn sm" type="button" data-pm-bulk="">{{ __('perm.seg_inherit') }}</button>
                    </span>
                </div>

                @foreach ($g['pages'] as $p)
                    @php $kids = array_merge($p['screens'], $p['actions']); @endphp
                    <div class="pm-page" data-pm-page>
                        @include('erp.perms._row', ['r' => $p, 'depth' => 1, 'kids' => $kids])
                        @if ($kids !== [])
                            <div class="pm-kids" hidden>
                                @foreach ($kids as $c)
                                    @include('erp.perms._row', ['r' => $c + ['page_key' => $p['key'], 'page_what' => $p['what']], 'depth' => 2, 'kids' => []])
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @endforeach

    {{-- ═══ شريط الحفظ الثابت ═══ --}}
    <div class="card pm-save" role="region" aria-label="{{ __('perm.save') }}">
        <span id="pmDirty" class="pm-dirtyn" aria-live="polite">{{ __('perm.no_changes') }}</span>
        <span class="pm-muted" style="flex:1 1 240px">{{ $isUser ? __('perm.save_hint') : __('perm.save_role_hint') }}</span>
        <button class="btn sm" type="button" id="pmReset" disabled>↺ {{ __('perm.reset') }}</button>
        <button class="btn gold" type="button" id="pmReview" disabled>🔍 {{ __('perm.review_save') }}</button>
        <noscript><button class="btn gold" type="submit">💾 {{ __('perm.save') }}</button></noscript>
    </div>
</form>

{{-- ═══ مودال الفرق — قبل ← بعد بالخطورة، والحرج متعلّم ═══ --}}
<dialog id="pmDiffDlg" class="wide" aria-labelledby="pmDiffTitle">
    <div class="dlg">
        <h4 id="pmDiffTitle">🔍 {{ __('perm.diff_title') }}</h4>
        <div class="pm-muted" style="margin-bottom:10px">{{ __('perm.diff_sub', ['x' => $isUser ? $subjectUser->name : $subjectInfo['label']]) }}</div>
        <div id="pmDiffBody"><div class="pm-muted">{{ __('perm.diff_loading') }}</div></div>
        <div class="formbar">
            <span class="formbar-sp"></span>
            <button class="btn" type="button" onclick="closeDlg('pmDiffDlg')">{{ __('perm.back_edit') }}</button>
            <button class="btn gold" type="button" id="pmConfirm" disabled>💾 {{ __('perm.confirm_save') }}</button>
        </div>
    </div>
</dialog>

<style>
.pm-grp{padding:0;overflow:hidden}
.pm-ghead{display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:12px;align-items:center;padding:12px 16px;background:var(--card);border-bottom:1px solid var(--border)}
.pm-gtitle{display:flex;gap:10px;align-items:center;background:none;border:0;padding:0;font:inherit;color:var(--text);cursor:pointer;text-align:start;font-size:14px}
.pm-gtitle:focus-visible{outline:2px solid var(--royal-blue);outline-offset:3px;border-radius:6px}
.pm-gico{font-size:18px}
.pm-caret{display:inline-block;width:12px;color:var(--muted);transition:transform .15s}
.pm-grp.folded .pm-caret{transform:rotate(90deg)}
[dir=rtl] .pm-grp.folded .pm-caret{transform:rotate(-90deg)}
.pm-grp.folded .pm-gbody{display:none}
.pm-grp.folded .pm-ghead{border-bottom:0}
.pm-gmeta{display:flex;gap:10px;align-items:center}
.pm-bulk{display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:9px 16px;background:var(--card2);font-size:12px}
.pm-row{display:grid;grid-template-columns:minmax(0,1fr) auto 22px auto;gap:6px 14px;align-items:center;padding:10px 16px;border-top:1px solid var(--border)}
.pm-row.d2{padding-inline-start:46px;background:#FBFBFC}
.pm-row.dirty{background:#FFF8E1}
.pm-row.dirty .pm-name::after{content:attr(data-dirty);margin-inline-start:8px;font-size:10px;font-weight:800;color:#9A5A08;background:#FDF1E3;border-radius:20px;padding:1px 8px}
.pm-name{display:flex;flex-wrap:wrap;gap:4px 8px;align-items:baseline;font-size:13px}
.pm-what{font-size:12px;color:var(--muted);line-height:1.6;margin-top:2px;max-width:78ch}
.pm-more,.pm-car{background:none;border:0;padding:0 2px;font:inherit;font-size:11.5px;font-weight:700;color:var(--royal-blue);cursor:pointer}
.pm-more:focus-visible,.pm-car:focus-visible{outline:2px solid var(--royal-blue);border-radius:4px}
.pm-chev{display:inline-block;transition:transform .15s}
[dir=rtl] .pm-chev{transform:scaleX(-1)}
.pm-car[aria-expanded=true] .pm-chev{transform:rotate(90deg)}
.pm-data{margin-top:6px;font-size:11.5px;line-height:1.7;background:var(--card2);border-radius:8px;padding:7px 10px;max-width:78ch}
.pm-meta{display:flex;gap:6px;align-items:center;justify-content:flex-end;flex-wrap:wrap}
.pm-save{position:sticky;bottom:0;z-index:30;display:flex;flex-wrap:wrap;gap:10px;align-items:center;box-shadow:0 -6px 18px rgba(10,10,15,.08)}
.pm-dirtyn{font-weight:800;font-size:13px}
.pm-dirtyn.has{color:#9A5A08}
#pmDiffBody table{width:100%}
#pmDiffBody tr.crit td{background:#FDECEC}
#pmDiffBody .pm-sum{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:10px}
@media (max-width:900px){
  .pm-row{grid-template-columns:auto 22px minmax(0,1fr);grid-template-areas:"name name name" "meta dot ctl"}
  .pm-row>:nth-child(1){grid-area:name}
  .pm-row>:nth-child(3){grid-area:dot}
  .pm-row .pm-meta{grid-area:meta;justify-content:flex-start}
  .pm-row .pm-ctl{grid-area:ctl;justify-self:end}
  .pm-ghead{grid-template-columns:minmax(0,1fr) auto}
  .pm-gctl{grid-column:1 / -1}
}
</style>

<script>
(function () {
    var form = document.getElementById('pmForm');
    if (!form) return;
    var T = {
        none: @json(__('perm.no_changes')),
        n: @json(__('perm.changes_n')),
        allow: @json(__('perm.seg_allow')), deny: @json(__('perm.seg_deny')), inherit: @json(__('perm.seg_inherit')),
        allowed: @json(__('perm.allowed')), denied: @json(__('perm.denied')),
        failed: @json(__('perm.diff_failed')), empty: @json(__('perm.diff_empty')),
        cItem: @json(__('perm.c_item')), cSetting: @json(__('perm.c_setting')), cEff: @json(__('perm.effective')), cRisk: @json(__('perm.c_risk')),
        sameEff: @json(__('perm.diff_same_effective')), crit: @json(__('perm.diff_critical_warn')),
        total: @json(__('perm.diff_total')), leave: @json(__('perm.leave_warn')),
        risk: @json($riskLabels)
    };
    var rows = Array.prototype.slice.call(form.querySelectorAll('[data-pm-row]'));
    var submitting = false;

    function current(row) {
        var c = row.querySelector('input[type=radio]:checked');
        return c ? c.value : null;
    }
    function isDirty(row) {
        var v = current(row);
        return v !== null && v !== row.dataset.ov;
    }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

    // ═══ المتغير + العدّاد ═══
    function mark() {
        var n = 0;
        rows.forEach(function (r) {
            var d = isDirty(r);
            r.classList.toggle('dirty', d);
            if (d) n++;
        });
        var lbl = document.getElementById('pmDirty');
        lbl.textContent = n ? T.n.replace(':n', n) : T.none;
        lbl.classList.toggle('has', n > 0);
        document.getElementById('pmReview').disabled = n === 0;
        document.getElementById('pmReset').disabled = n === 0;
        return n;
    }
    form.addEventListener('change', function (e) {
        if (e.target.matches('input[type=radio]')) { mark(); if (document.getElementById('pmOnly').checked) filter(); }
    });

    // ═══ البحث والفلاتر ═══
    function rowHit(r, q, only, high) {
        if (!r) return false;
        if (q && r.dataset.q.indexOf(q) === -1) return false;
        if (only && current(r) === '' && !isDirty(r) && r.dataset.ov === '') return false;
        if (high && r.dataset.risk !== 'high' && r.dataset.risk !== 'critical') return false;
        return true;
    }
    function filter() {
        var q = document.getElementById('pmQ').value.trim().toLowerCase();
        var only = document.getElementById('pmOnly').checked;
        var high = document.getElementById('pmHigh').checked;
        var any = q || only || high;
        var shown = 0;

        form.querySelectorAll('[data-pm-grp]').forEach(function (g) {
            var gRow = g.querySelector('.pm-gctl');
            var gHit = any && rowHit(gRow, q, only, high);
            var gn = 0;
            g.querySelectorAll('[data-pm-page]').forEach(function (p) {
                var pr = p.querySelector('.pm-row.d1');
                var kids = Array.prototype.slice.call(p.querySelectorAll('.pm-row.d2'));
                var kn = 0;
                kids.forEach(function (k) {
                    var ok = !any || rowHit(k, q, only, high);
                    k.hidden = !ok;
                    if (ok) kn++;
                });
                var pOk = !any || rowHit(pr, q, only, high);
                var show = pOk || kn > 0 || (gHit && !only && !high);
                p.hidden = !show;
                var box = p.querySelector('.pm-kids');
                if (box) {
                    if (any) {
                        // صفحة ظاهرة بسبب ولادها ⇒ افتحهم؛ والصفحة نفسها نتيجة ⇒ كل ولادها
                        if (pOk && !kn) kids.forEach(function (k) { k.hidden = only || high; });
                        setKids(p, kn > 0);
                    } else {
                        kids.forEach(function (k) { k.hidden = false; });
                    }
                }
                if (show) gn++;
            });
            var gShow = !any || gn > 0 || gHit;
            g.hidden = !gShow;
            if (any && gShow) g.classList.remove('folded');
            if (gShow) shown++;
        });
        document.getElementById('pmNone').hidden = shown > 0;
    }
    var timer;
    document.getElementById('pmQ').addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(filter, 120); });
    document.getElementById('pmOnly').addEventListener('change', filter);
    document.getElementById('pmHigh').addEventListener('change', filter);

    // ═══ طي الأقسام وفتح ولاد الصفحة ═══
    function setKids(p, open) {
        var box = p.querySelector('.pm-kids'), btn = p.querySelector('.pm-car');
        if (!box) return;
        box.hidden = !open;
        if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    form.addEventListener('click', function (e) {
        var t = e.target.closest('[data-pm-gtoggle]');
        if (t) {
            var g = t.closest('[data-pm-grp]');
            g.classList.toggle('folded');
            t.setAttribute('aria-expanded', g.classList.contains('folded') ? 'false' : 'true');
            return;
        }
        var car = e.target.closest('.pm-car');
        if (car) { var p = car.closest('[data-pm-page]'); setKids(p, p.querySelector('.pm-kids').hidden); return; }
        var more = e.target.closest('.pm-more');
        if (more) {
            var d = more.closest('.pm-row').querySelector('.pm-data');
            d.hidden = !d.hidden;
            more.setAttribute('aria-expanded', d.hidden ? 'false' : 'true');
            return;
        }
        var f = e.target.closest('[data-pm-fold]');
        if (f) {
            var fold = f.dataset.pmFold === '1';
            form.querySelectorAll('[data-pm-grp]').forEach(function (g) {
                g.classList.toggle('folded', fold);
                g.querySelector('[data-pm-gtoggle]').setAttribute('aria-expanded', fold ? 'false' : 'true');
                if (!fold) g.querySelectorAll('[data-pm-page]').forEach(function (p) { setKids(p, true); });
            });
            return;
        }
        // ═══ تعديل جماعي للقسم: كل صفحاته وشاشاته وزرايره (القسم نفسه ليه تحكمه) ═══
        var b = e.target.closest('[data-pm-bulk]');
        if (b) {
            var v = b.dataset.pmBulk;
            b.closest('[data-pm-grp]').querySelectorAll('.pm-gbody [data-pm-row]').forEach(function (r) {
                var inp = r.querySelector('input[type=radio][value="' + v + '"]');
                if (inp) inp.checked = true;
            });
            mark();
        }
    });

    document.getElementById('pmReset').addEventListener('click', function () {
        rows.forEach(function (r) {
            var inp = r.querySelector('input[type=radio][value="' + r.dataset.ov + '"]');
            if (inp) inp.checked = true;
        });
        mark(); filter();
    });

    // ═══ راجع ⇒ الفرق من السيرفر ⇒ مودال ═══
    function changedData() {
        var fd = new FormData();
        fd.append('_token', form.querySelector('input[name=_token]').value);
        fd.append(form.dataset.subjectField, form.dataset.subject);
        rows.forEach(function (r) {
            if (!isDirty(r)) return;
            var c = r.querySelector('input[type=radio]:checked');
            fd.append(c.name, c.value);
        });
        return fd;
    }
    function ovText(v) { return v === null ? T.inherit : (v ? T.allow : T.deny); }
    function effText(v) {
        return '<span class="pm-state ' + (v ? 'on' : 'off') + '">' + (v ? '✓ ' + esc(T.allowed) : '✗ ' + esc(T.denied)) + '</span>';
    }
    function render(changes) {
        var body = document.getElementById('pmDiffBody');
        if (!changes.length) { body.innerHTML = '<div class="alert info"><span>ℹ️</span><span>' + esc(T.empty) + '</span></div>'; return false; }
        var order = { critical: 0, high: 1, medium: 2, low: 3 };
        changes.sort(function (a, b) { return order[a.risk] - order[b.risk]; });
        var counts = {};
        changes.forEach(function (c) { counts[c.risk] = (counts[c.risk] || 0) + 1; });
        var h = '<div class="pm-sum"><b>' + esc(T.total.replace(':n', changes.length)) + '</b>';
        ['critical', 'high', 'medium', 'low'].forEach(function (k) {
            if (counts[k]) h += '<span class="pm-risk r-' + k + '">' + esc(T.risk[k]) + ' × ' + counts[k] + '</span>';
        });
        h += '</div>';
        if (counts.critical) h += '<div class="alert" style="margin-bottom:10px"><span>⛔</span><span>' + esc(T.crit) + '</span></div>';
        h += '<div class="tablewrap" style="max-height:52vh;overflow-y:auto"><table data-plain data-noxl><thead><tr><th>' + esc(T.cItem) + '</th><th>' + esc(T.cSetting) + '</th><th>' + esc(T.cEff) + '</th><th>' + esc(T.cRisk) + '</th></tr></thead><tbody>';
        changes.forEach(function (c) {
            var same = c.effective_before === c.effective_after;
            h += '<tr class="' + (c.risk === 'critical' ? 'crit' : '') + '"><td><b>' + esc(c.label) + '</b><div class="pm-key">' + esc(c.key) + '</div></td>'
                + '<td style="white-space:nowrap"><s class="pm-muted">' + esc(ovText(c.before)) + '</s> → <b>' + esc(ovText(c.after)) + '</b></td>'
                + '<td style="white-space:nowrap">' + (same ? effText(c.effective_after) + ' <span class="pm-muted">' + esc(T.sameEff) + '</span>' : effText(c.effective_before) + ' → ' + effText(c.effective_after)) + '</td>'
                + '<td><span class="pm-risk r-' + esc(c.risk) + '">' + esc(T.risk[c.risk] || c.risk) + '</span></td></tr>';
        });
        body.innerHTML = h + '</tbody></table></div>';
        return true;
    }
    document.getElementById('pmReview').addEventListener('click', function () {
        var btn = document.getElementById('pmConfirm');
        btn.disabled = true;
        document.getElementById('pmDiffBody').innerHTML = '<div class="pm-muted">' + esc(@json(__('perm.diff_loading'))) + '</div>';
        openDlg('pmDiffDlg');
        fetch(form.dataset.diff, { method: 'POST', body: changedData(), headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
            .then(function (j) { btn.disabled = !render(j.changes || []); })
            .catch(function () { document.getElementById('pmDiffBody').innerHTML = '<div class="alert"><span>⚠️</span><span>' + esc(T.failed) + '</span></div>'; });
    });
    // تأكيد ⇒ يتبعت المتغير بس (اللي ماتغيرش بيتقفل قبل الإرسال)
    document.getElementById('pmConfirm').addEventListener('click', function () {
        rows.forEach(function (r) {
            if (!isDirty(r)) r.querySelectorAll('input[type=radio]').forEach(function (i) { i.disabled = true; });
        });
        submitting = true;
        this.disabled = true;
        form.submit();
    });
    window.addEventListener('beforeunload', function (e) {
        if (!submitting && mark() > 0) { e.preventDefault(); e.returnValue = T.leave; }
    });

    mark();
    if (document.getElementById('pmOnly').checked) filter();
})();
</script>
