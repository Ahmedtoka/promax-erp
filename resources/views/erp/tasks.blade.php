@extends('layouts.system')

{{--
    إدارة المهام — البورد (إعادة تحسين ٢٦ أغسطس ٢٠٢٦ بعد سكرين المالك):

    • صف إحصائيات فوق: مفتوحة / متأخرة / مستنية اعتمادي / خلصت.
    • بار بحث + فلاتر (أولوية + حالة) — فلترة لايف في المتصفح على
      كروت البورد وجدول «اللي كلفتها» مع بعض.
    • البورد ٣ أعمدة والكروت أوضح، والديالوج اتظبط ستايله.
      ⚠️ **أي input في الديالوج لازم type صريح** — ستايل السيستم
      بيمسك input[type=text] بالاسم، وإنبت من غير type بيطلع خام
      (ده اللي بوّظ البوب أب في أول نسخة).
    • الرؤية زي ما هي: كل موظف شايف مهامه واللي كلّفها بس —
      Task::visibleTo والحارس في الكنترولر.
--}}

@section('title', __('tasks.title'))

@section('actions')
    <button class="btn gold" type="button" onclick="document.getElementById('dlgAddTask').showModal()">
        ➕ {{ __('tasks.add') }}</button>
@endsection

@section('content')

@php
    $prLabel = fn ($t) => __('tasks.pr_'.$t->priority);
    $card = fn ($t) => view('erp._task_card', ['t' => $t, 'who' => 'creator', 'prLabel' => $prLabel])->render();
    $waiting = $assigned->where('status', 'submitted')->count();
@endphp

{{-- ═══ صف الإحصائيات — الرقم فوق واسمه تحته (نفس نمط الداشبورد) ═══ --}}
<div class="kpis dash-kpis" style="margin-bottom:14px">
    <div class="kpi"><div class="val big">{{ $today->count() }}</div><div class="lbl">📌 {{ __('tasks.col_today') }}</div></div>
    <div class="kpi"><div class="val big {{ $late->count() ? 'neg' : '' }}">{{ $late->count() }}</div><div class="lbl">⏰ {{ __('tasks.col_late') }}</div></div>
    <div class="kpi"><div class="val big {{ $waiting ? 'mid' : '' }}">{{ $waiting }}</div><div class="lbl">⏳ {{ __('tasks.k_waiting') }}</div></div>
    <div class="kpi"><div class="val big pos">{{ $done->count() }}</div><div class="lbl">🏁 {{ __('tasks.col_done') }}</div></div>
</div>

{{-- ═══ البحث والفلاتر — لايف على الكروت والجدول مع بعض ═══ --}}
<div class="card" style="margin-bottom:14px;padding:12px 16px">
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <input type="search" id="tkSearch" placeholder="🔎 {{ __('tasks.search_ph') }}"
               style="flex:1;min-width:220px">
        <select id="tkPrFilter" style="flex:0 0 150px">
            <option value="">{{ __('tasks.all_priorities') }}</option>
            @foreach (\App\Models\Task::PRIORITIES as $p)
                <option value="{{ $p }}">{{ __('tasks.pr_'.$p) }}</option>
            @endforeach
        </select>
        <select id="tkStFilter" style="flex:0 0 170px">
            <option value="">{{ __('tasks.all_statuses') }}</option>
            <option value="open">{{ __('tasks.st_open') }}</option>
            <option value="late">{{ __('tasks.late') }}</option>
            <option value="submitted">{{ __('tasks.st_submitted') }}</option>
            <option value="approved">{{ __('tasks.st_approved') }}</option>
        </select>
        <span id="tkCount" style="font-size:11px;color:var(--muted)"></span>
    </div>
</div>

{{-- ═══ مهامي — بورد ٣ أعمدة ═══ --}}
<div class="tk-board">
    <div class="tk-col">
        <div class="tk-col-h">📌 {{ __('tasks.col_today') }} <span class="badge b-blue">{{ $today->count() }}</span></div>
        @forelse ($today as $t) {!! $card($t) !!}
        @empty <div class="tk-empty">{{ __('tasks.none') }}</div> @endforelse
    </div>
    <div class="tk-col tk-late">
        <div class="tk-col-h">⏰ {{ __('tasks.col_late') }} <span class="badge b-red">{{ $late->count() }}</span></div>
        @forelse ($late as $t) {!! $card($t) !!}
        @empty <div class="tk-empty">{{ __('tasks.none_late') }}</div> @endforelse
    </div>
    <div class="tk-col tk-done">
        <div class="tk-col-h">🏁 {{ __('tasks.col_done') }} <span class="badge b-green">{{ $done->count() }}</span></div>
        @forelse ($done as $t) {!! $card($t) !!}
        @empty <div class="tk-empty">{{ __('tasks.none') }}</div> @endforelse
    </div>
</div>

{{-- ═══ اللي كلفتها لغيري ═══ --}}
@if ($assigned->isNotEmpty())
<div class="card" style="margin-top:16px">
    <h3 style="margin:0 0 10px">🧑‍💼 {{ __('tasks.i_assigned') }}
        @if ($waiting)<span class="badge b-orange">{{ $waiting }} {{ __('tasks.k_waiting') }}</span>@endif
    </h3>
    <table data-plain>
        <thead>
        <tr>
            <th style="text-align:start">{{ __('tasks.f_title') }}</th>
            <th>{{ __('tasks.f_assignee') }}</th>
            <th>{{ __('tasks.f_priority') }}</th>
            <th>{{ __('tasks.f_deadline') }}</th>
            <th>{{ __('tasks.f_status') }}</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        @foreach ($assigned as $t)
            @php
                $stKey = $t->isLate() ? 'late' : $t->status;
            @endphp
            <tr @class(['tk-wait' => $t->status === 'submitted']) class="tk-row-f"
                data-search="{{ mb_strtolower($t->title.' '.($t->assignee?->displayName() ?? '')) }}"
                data-pr="{{ $t->priority }}" data-st="{{ $stKey }}">
                <td style="text-align:start">
                    <a href="{{ route('erp.tasks.show', $t) }}" style="font-weight:800">{{ $t->title }}</a>
                    @if ($t->rejections > 0)<span class="badge b-red" style="font-size:9px">↩️ ×{{ $t->rejections }}</span>@endif
                </td>
                <td>{{ $t->assignee?->displayName() ?? '—' }}</td>
                <td><span class="badge {{ $t->priorityBadge() }}">{{ $prLabel($t) }}</span></td>
                <td dir="ltr">{{ $t->deadline?->format('Y-m-d H:i') ?? '—' }}
                    @if ($t->isLate())<span class="badge b-red" style="font-size:9px">{{ __('tasks.late') }}</span>@endif
                </td>
                <td>
                    @if ($t->status === 'submitted')
                        <span class="badge b-orange">⏳ {{ __('tasks.st_submitted') }}</span>
                    @elseif ($t->status === 'approved')
                        <span class="badge b-green">✓ {{ __('tasks.st_approved') }}</span>
                    @else
                        <span class="badge b-gray">{{ __('tasks.st_open') }}</span>
                    @endif
                </td>
                <td style="white-space:nowrap">
                    @if ($t->status === 'submitted')
                        <form method="POST" action="{{ route('erp.tasks.approve', $t) }}" style="display:inline">@csrf
                            <button class="btn sm" type="submit">✅ {{ __('tasks.approve') }}</button>
                        </form>
                        <form method="POST" action="{{ route('erp.tasks.reject', $t) }}" style="display:inline"
                              onsubmit="var r = prompt({!! json_encode(__('tasks.reject_why'), JSON_UNESCAPED_UNICODE) !!}); if (r === null) return false; this.reason.value = r; return true;">
                            @csrf
                            <input type="hidden" name="reason" value="">
                            <button class="btn sm" type="submit">↩️ {{ __('tasks.reject') }}</button>
                        </form>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif

{{-- ═══ ديالوج إضافة مهمة — كل input بـtype صريح (شوف الترويسة) ═══ --}}
<dialog id="dlgAddTask" class="tk-dlg">
    <form method="POST" action="{{ route('erp.tasks.store') }}" enctype="multipart/form-data">
        @csrf
        <div class="tk-dlg-h">➕ {{ __('tasks.add') }}</div>

        <div class="tk-dlg-b">
            <div>
                <label class="f">👤 {{ __('tasks.f_assignee') }}</label>
                <select name="assigned_to" required>
                    <option value="">— {{ __('tasks.pick_staff') }} —</option>
                    @foreach ($staff as $s)
                        <option value="{{ $s->id }}">{{ $s->displayName() }} — {{ $s->roleLabel() }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="f">📝 {{ __('tasks.f_title') }}</label>
                <input type="text" name="title" required maxlength="200"
                       placeholder="{{ __('tasks.title_ph') }}">
            </div>

            <div>
                <label class="f">📄 {{ __('tasks.f_desc') }}</label>
                <textarea name="description" rows="4" placeholder="{{ __('tasks.desc_ph') }}"></textarea>
            </div>

            <div class="tk-dlg-row">
                <div>
                    <label class="f">🗓 {{ __('tasks.f_deadline') }}</label>
                    <input type="datetime-local" name="deadline">
                </div>
                <div>
                    <label class="f">🚦 {{ __('tasks.f_priority') }}</label>
                    <select name="priority">
                        @foreach (\App\Models\Task::PRIORITIES as $p)
                            <option value="{{ $p }}" @selected($p === 'normal')>{{ __('tasks.pr_'.$p) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div>
                <label class="f">📎 {{ __('tasks.f_files') }}</label>
                {{-- الليبل ستايل زرار — إنبت الملفات الخام شكله مكسور في كل المتصفحات --}}
                <label class="btn" style="cursor:pointer;display:inline-flex;align-items:center;gap:6px">
                    📎 {{ __('tasks.pick_files') }}
                    <input type="file" name="files[]" multiple style="display:none"
                           accept=".jpg,.jpeg,.png,.webp,.heic,.xlsx,.xls,.csv,.pdf,.doc,.docx"
                           onchange="document.getElementById('tkFilesCount').textContent = this.files.length ? ('✓ ' + this.files.length) : ''">
                </label>
                <span id="tkFilesCount" style="font-size:12px;font-weight:800;color:var(--royal-blue,#12399B)"></span>
                <div class="dash-hint" style="margin-top:4px">{{ __('tasks.h_files') }}</div>
            </div>
        </div>

        <div class="tk-dlg-f">
            <button class="btn" type="button" onclick="document.getElementById('dlgAddTask').close()">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">📋 {{ __('tasks.submit_task') }}</button>
        </div>
    </form>
</dialog>

@endsection

@section('scripts')
<style>
/* ═══ البورد ═══ */
.tk-board{display:flex;gap:12px;align-items:flex-start;flex-wrap:wrap}
.tk-col{flex:1;min-width:270px;background:var(--card2,#F7F7FA);border:1px solid var(--border);
    border-radius:14px;padding:12px;border-top:3px solid var(--royal-blue,#12399B)}
.tk-col-h{display:flex;align-items:center;gap:8px;font-weight:900;font-size:13.5px;margin-bottom:10px}
.tk-late{border-top-color:var(--red,#DC2626);background:#FDF7F7}
.tk-done{border-top-color:#0F7A38}
.tk-empty{padding:22px 10px;text-align:center;font-size:12px;color:var(--muted)}
.tk-card{transition:.12s}
.tk-card:hover{transform:translateY(-1px);box-shadow:0 3px 10px rgba(18,57,155,.08);
    border-color:var(--royal-blue,#12399B)}
/* المستنية اعتمادي منوّرة في الجدول */
.tk-wait{background:#FFF8EC}

/* ═══ الديالوج — الإصلاح الأساسي ═══ */
.tk-dlg{border:none;border-radius:18px;padding:0;max-width:560px;width:94vw;
    box-shadow:0 20px 60px rgba(0,0,0,.25)}
.tk-dlg::backdrop{background:rgba(10,10,20,.45);backdrop-filter:blur(2px)}
.tk-dlg-h{background:linear-gradient(135deg,#12399B,#602D90);color:#fff;
    font-weight:900;font-size:16px;padding:16px 22px}
.tk-dlg-b{padding:18px 22px;display:flex;flex-direction:column;gap:14px}
.tk-dlg-b input[type=text],.tk-dlg-b input[type=datetime-local],
.tk-dlg-b select,.tk-dlg-b textarea{width:100%}
.tk-dlg-row{display:flex;gap:12px}
.tk-dlg-row>div{flex:1}
.tk-dlg-f{display:flex;gap:10px;justify-content:flex-end;padding:0 22px 18px}
</style>
<script>
// ═══ البحث والفلاتر — لايف على كروت البورد وصفوف الجدول مع بعض ═══
(function () {
    'use strict';

    var q = document.getElementById('tkSearch');
    var pr = document.getElementById('tkPrFilter');
    var st = document.getElementById('tkStFilter');
    var count = document.getElementById('tkCount');
    var LBL = {!! json_encode(__('tasks.showing'), JSON_UNESCAPED_UNICODE) !!};

    function apply() {
        var needle = q.value.trim().toLowerCase();
        var wantPr = pr.value;
        var wantSt = st.value;
        var shown = 0, total = 0;

        document.querySelectorAll('.tk-card, .tk-row-f').forEach(function (el) {
            total++;
            var ok = (!needle || (el.dataset.search || '').indexOf(needle) !== -1)
                && (!wantPr || el.dataset.pr === wantPr)
                && (!wantSt || el.dataset.st === wantSt);
            el.style.display = ok ? '' : 'none';
            if (ok) shown++;
        });

        count.textContent = (needle || wantPr || wantSt) ? LBL.replace(':n', shown).replace(':t', total) : '';

        // عمود فضي بالكامل بعد الفلترة يوري رسالته
        document.querySelectorAll('.tk-col').forEach(function (col) {
            var any = Array.prototype.some.call(col.querySelectorAll('.tk-card'),
                function (c) { return c.style.display !== 'none'; });
            var empty = col.querySelector('.tk-empty');
            if (empty) empty.style.display = any ? 'none' : '';
        });
    }

    q.addEventListener('input', apply);
    pr.addEventListener('change', apply);
    st.addEventListener('change', apply);
    apply();
})();
</script>
@endsection
