@extends('layouts.system')

{{--
    صفحة المهمة (تحسين ٢٦ أغسطس ٢٠٢٦ — بعد بلاغ إيرور رفع الصورة):

    • الشات بقى **أجاكس بالكامل**: الإرسال fetch من غير ريفريش،
      لودينج على الزرار وقت الرفع، وبولينج كل ٦ ثواني بيجيب رسايل
      الطرف التاني (وسطور السيستم) أول بأول — ولو حالة المهمة
      اتغيرت (اتسلمت/اتعمدت) الصفحة بتترفرش لوحدها عشان الأزرار.
    • الصور بتترندر **ثمبنيل جوه الشات** (مش بادج) وبتتفتح بضغطة —
      وقبل الإرسال فيه بريفيو للصورة المختارة مع ✕ للإلغاء.
    • كل الرسايل (الأولية والجاية أجاكس) بتترسم بنفس دالة JS واحدة
      (renderMsg) — مفيش نسختين ماركاب يفترقوا مع الوقت.
--}}

@section('title', __('tasks.one').' #'.$task->id)

@section('actions')
    <a class="btn" href="{{ route('erp.tasks') }}">← {{ __('tasks.title') }}</a>
@endsection

@section('content')

@php
    $me = auth()->id();
    $isAssignee = $task->assigned_to === $me;
    $canDecide = auth()->user()->role === 'admin' || $task->created_by === $me;
    $imgExts = ['jpg', 'jpeg', 'png', 'webp'];
@endphp

{{-- ═══ رأس المهمة ═══ --}}
<div class="card" style="margin-bottom:14px">
    <div style="display:flex;gap:12px;align-items:flex-start;flex-wrap:wrap">
        <div style="flex:1;min-width:240px">
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                <h2 style="margin:0;font-size:18px">{{ $task->title }}</h2>
                <span class="badge {{ $task->priorityBadge() }}">{{ __('tasks.pr_'.$task->priority) }}</span>
                @if ($task->status === 'submitted')
                    <span class="badge b-orange">⏳ {{ __('tasks.st_submitted') }}</span>
                @elseif ($task->status === 'approved')
                    <span class="badge b-green">✓ {{ __('tasks.st_approved') }}</span>
                @elseif ($task->isLate())
                    <span class="badge b-red">⏰ {{ __('tasks.late') }}</span>
                @else
                    <span class="badge b-gray">{{ __('tasks.st_open') }}</span>
                @endif
            </div>
            @if ($task->description)
                <div style="font-size:13px;line-height:1.8;margin-top:8px;white-space:pre-line">{{ $task->description }}</div>
            @endif
            <div style="display:flex;gap:14px;font-size:11.5px;color:var(--muted);margin-top:10px;flex-wrap:wrap">
                <span>👤 {{ __('tasks.f_assignee') }}: <b>{{ $task->assignee?->displayName() }}</b></span>
                <span>🧑‍💼 {{ __('tasks.by') }}: <b>{{ $task->creator?->displayName() }}</b></span>
                @if ($task->deadline)
                    <span dir="ltr" @if($task->isLate()) style="color:var(--red,#DC2626);font-weight:800" @endif>
                        🗓 {{ $task->deadline->format('Y-m-d H:i') }}</span>
                @endif
                @if ($task->rejections > 0)
                    <span>↩️ {{ __('tasks.rejected_n', ['n' => $task->rejections]) }}</span>
                @endif
            </div>
        </div>

        {{-- أزرار القرار --}}
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            @if ($isAssignee && $task->status === 'open')
                <form method="POST" action="{{ route('erp.tasks.submit', $task) }}"
                      onsubmit="return confirm({!! json_encode(__('tasks.submit_confirm'), JSON_UNESCAPED_UNICODE) !!})">
                    @csrf
                    <button class="btn gold" type="submit">✅ {{ __('tasks.mark_done') }}</button>
                </form>
            @endif
            @if ($canDecide && $task->status === 'submitted')
                <form method="POST" action="{{ route('erp.tasks.approve', $task) }}">@csrf
                    <button class="btn gold" type="submit">🏁 {{ __('tasks.approve') }}</button>
                </form>
                <form method="POST" action="{{ route('erp.tasks.reject', $task) }}"
                      onsubmit="var r = prompt({!! json_encode(__('tasks.reject_why'), JSON_UNESCAPED_UNICODE) !!}); if (r === null) return false; this.reason.value = r; return true;">
                    @csrf
                    <input type="hidden" name="reason" value="">
                    <button class="btn" type="submit">↩️ {{ __('tasks.reject') }}</button>
                </form>
            @endif
        </div>
    </div>

    {{-- مرفقات المهمة — الصور ثمبنيلز والباقي بادجات --}}
    @if ($task->files->isNotEmpty())
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;border-top:1px solid var(--border);padding-top:10px">
            @foreach ($task->files as $f)
                @if (in_array(strtolower(pathinfo($f->path, PATHINFO_EXTENSION)), $imgExts, true))
                    <a href="{{ $f->url() }}" target="_blank" rel="noopener" title="{{ $f->name }}">
                        <img src="{{ $f->url() }}" alt="" class="tk-thumb"></a>
                @else
                    <a class="badge b-blue" href="{{ $f->url() }}" target="_blank" rel="noopener"
                       style="text-decoration:none">📎 {{ $f->name }}</a>
                @endif
            @endforeach
        </div>
    @endif
</div>

{{-- ═══ الشات ═══ --}}
<div class="card">
    <h3 style="margin:0 0 12px">💬 {{ __('tasks.chat') }}</h3>

    <div id="tkChat" style="max-height:52vh;overflow-y:auto;display:flex;flex-direction:column;gap:8px;padding:4px">
        <div class="empty" id="tkNoMsgs" style="display:none">{{ __('tasks.no_msgs') }}</div>
    </div>

    {{-- الإرسال — مقفول بعد الاعتماد: المهمة خلصت --}}
    @if ($task->status !== 'approved')
        {{-- بريفيو الصورة قبل الإرسال --}}
        <div id="tkPreview" style="display:none;margin-top:10px;position:relative;width:fit-content">
            <img id="tkPreviewImg" src="" alt="" class="tk-thumb" style="width:110px;height:110px">
            <span id="tkPreviewName" class="badge b-blue" style="display:none">📎</span>
            <button type="button" id="tkPreviewX" title="✕"
                    style="position:absolute;top:-8px;inset-inline-end:-8px;width:22px;height:22px;border-radius:50%;
                           border:none;background:var(--red,#DC2626);color:#fff;font-weight:900;cursor:pointer;line-height:1">✕</button>
        </div>

        <form id="tkForm" method="POST" action="{{ route('erp.tasks.comment', $task) }}" enctype="multipart/form-data"
              style="display:flex;gap:8px;margin-top:12px;align-items:flex-end;flex-wrap:wrap">
            @csrf
            <textarea name="body" id="tkBody" rows="2" placeholder="{{ __('tasks.msg_ph') }}"
                      style="flex:1;min-width:220px"></textarea>
            <label class="btn" style="cursor:pointer" title="{{ __('tasks.pick_files') }}">📎
                <input type="file" name="file" id="tkFile" style="display:none"
                       accept=".jpg,.jpeg,.png,.webp,.heic,.xlsx,.xls,.csv,.pdf,.doc,.docx">
            </label>
            <button class="btn gold" type="submit" id="tkSend">{{ __('tasks.send') }}</button>
        </form>
        <div id="tkErr" style="display:none;color:var(--red,#DC2626);font-size:12px;font-weight:700;margin-top:6px"></div>
    @else
        <div class="dash-hint" style="margin-top:10px">🏁 {{ __('tasks.closed_hint') }}</div>
    @endif
</div>

@endsection

@section('scripts')
<style>
.tk-thumb{width:88px;height:88px;object-fit:cover;border-radius:10px;border:1px solid var(--border);
    background:#fff;display:block;transition:.12s}
.tk-thumb:hover{transform:scale(1.03);box-shadow:0 3px 12px rgba(0,0,0,.15)}
.tk-msg{max-width:75%;border:1px solid var(--border);border-radius:12px;padding:8px 12px}
.tk-msg img{max-width:220px;max-height:180px;width:auto;height:auto;object-fit:contain;
    border-radius:10px;border:1px solid var(--border);margin-top:5px;display:block;cursor:zoom-in}
@keyframes tkIn{0%{opacity:0;transform:translateY(6px)}100%{opacity:1;transform:none}}
.tk-msg,.tk-sys{animation:tkIn .18s ease}
.tk-sys{align-self:center;font-size:11px;color:var(--muted);background:var(--card2,#F7F7FA);
    border:1px dashed var(--border);border-radius:999px;padding:3px 14px}
/* لودينج زرار الإرسال */
#tkSend.busy{opacity:.6;pointer-events:none}
#tkSend.busy::after{content:'';display:inline-block;width:11px;height:11px;margin-inline-start:7px;
    border:2px solid #fff;border-top-color:transparent;border-radius:50%;vertical-align:-2px;
    animation:tkSpin .7s linear infinite}
@keyframes tkSpin{100%{transform:rotate(360deg)}}
</style>
@php
    // الرسايل الأولية بنفس شكل بايلود الأجاكس — دالة رسم واحدة للكل
    $initialMsgs = $task->comments->map(fn ($c) => [
        'id' => $c->id,
        'user_id' => (int) $c->user_id,
        'name' => $c->user?->displayName() ?? '—',
        'body' => $c->body,
        'file_url' => $c->fileUrl(),
        'file_name' => $c->file_name,
        'is_img' => $c->file_path !== null
            && in_array(strtolower(pathinfo($c->file_path, PATHINFO_EXTENSION)), $imgExts, true),
        'is_system' => (bool) $c->is_system,
        't' => $c->created_at?->format('d/m h:i A'),
    ])->values();
@endphp
<script>
(function () {
    'use strict';

    var MSGS = {!! json_encode($initialMsgs, JSON_UNESCAPED_UNICODE) !!};
    var ME = {{ (int) $me }};
    var POLL_URL = {!! json_encode(route('erp.tasks.comments', $task), JSON_UNESCAPED_UNICODE) !!};
    var STATUS = {!! json_encode($task->status) !!};
    var SEND_ERR = {!! json_encode(__('tasks.send_err'), JSON_UNESCAPED_UNICODE) !!};

    var chat = document.getElementById('tkChat');
    var noMsgs = document.getElementById('tkNoMsgs');
    var lastId = 0;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    }

    /* دالة الرسم الواحدة — الأولية والأجاكس والبولينج كلهم منها */
    function renderMsg(c) {
        if (c.id > lastId) lastId = c.id;
        var el = document.createElement('div');

        if (c.is_system) {
            el.className = 'tk-sys';
            el.innerHTML = esc(c.body) + ' · ' + esc(c.t);
        } else {
            var mine = c.user_id === ME;
            el.className = 'tk-msg';
            el.style.alignSelf = mine ? 'flex-end' : 'flex-start';
            el.style.background = mine ? 'var(--blue-050,#E8F1FF)' : 'var(--card2,#F7F7FA)';

            var html = '<div style="font-size:10.5px;font-weight:800;color:var(--muted)">'
                + esc(c.name) + ' · ' + esc(c.t) + '</div>';
            if (c.body) {
                html += '<div style="font-size:13px;line-height:1.7;white-space:pre-line;margin-top:2px">'
                    + esc(c.body) + '</div>';
            }
            if (c.file_url) {
                html += c.is_img
                    ? '<a href="' + esc(c.file_url) + '" target="_blank" rel="noopener"><img src="'
                        + esc(c.file_url) + '" alt=""></a>'
                    : '<a class="badge b-blue" href="' + esc(c.file_url) + '" target="_blank" rel="noopener" '
                        + 'style="text-decoration:none;margin-top:4px;display:inline-block">📎 '
                        + esc(c.file_name) + '</a>';
            }
            el.innerHTML = html;
        }

        chat.appendChild(el);
        noMsgs.style.display = 'none';
    }

    function scrollDown() { chat.scrollTop = chat.scrollHeight; }

    if (MSGS.length) { MSGS.forEach(renderMsg); } else { noMsgs.style.display = ''; }
    scrollDown();

    /* ═══ البولينج كل ٦ ثواني — رسايل الطرف التاني + تغير الحالة ═══ */
    setInterval(function () {
        if (document.hidden) return;
        fetch(POLL_URL + '?after=' + lastId, { headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                // الحالة اتغيرت (اتسلمت/اتعمدت/اترفضت) → ريفريش يظبط الأزرار
                if (d.status !== STATUS) { location.reload(); return; }
                if (d.comments && d.comments.length) {
                    var stick = chat.scrollHeight - chat.scrollTop - chat.clientHeight < 60;
                    d.comments.forEach(renderMsg);
                    if (stick) scrollDown();
                }
            })
            .catch(function () { /* نتة نت — المحاولة الجاية */ });
    }, 6000);

    /* ═══ الإرسال أجاكس + بريفيو الصورة + لودينج ═══ */
    var form = document.getElementById('tkForm');
    if (!form) return;   // المهمة معتمدة — مفيش فورم

    var body = document.getElementById('tkBody');
    var file = document.getElementById('tkFile');
    var send = document.getElementById('tkSend');
    var err = document.getElementById('tkErr');
    var prev = document.getElementById('tkPreview');
    var prevImg = document.getElementById('tkPreviewImg');
    var prevName = document.getElementById('tkPreviewName');

    file.addEventListener('change', function () {
        err.style.display = 'none';
        if (!file.files.length) { prev.style.display = 'none'; return; }
        var f = file.files[0];
        prev.style.display = 'block';
        if (/^image\//.test(f.type)) {
            prevImg.src = URL.createObjectURL(f);
            prevImg.style.display = 'block';
            prevName.style.display = 'none';
        } else {
            prevImg.style.display = 'none';
            prevName.style.display = 'inline-block';
            prevName.textContent = '📎 ' + f.name;
        }
    });

    document.getElementById('tkPreviewX').addEventListener('click', function () {
        file.value = '';
        prev.style.display = 'none';
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        err.style.display = 'none';

        if (!body.value.trim() && !file.files.length) return;

        send.classList.add('busy');
        var fd = new FormData(form);

        fetch(form.action, {
            method: 'POST',
            body: fd,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(function (r) { return r.json().then(function (d) { return { s: r.status, d: d }; }); })
            .then(function (res) {
                send.classList.remove('busy');
                if (!res.d.ok) {
                    err.textContent = res.d.error || (res.d.message || SEND_ERR);
                    err.style.display = 'block';
                    return;
                }
                renderMsg(res.d.comment);
                scrollDown();
                body.value = '';
                file.value = '';
                prev.style.display = 'none';
            })
            .catch(function () {
                send.classList.remove('busy');
                err.textContent = SEND_ERR;
                err.style.display = 'block';
            });
    });

    // إنتر بيبعت — شيفت+إنتر سطر جديد (سلوك الشاتات المتعارف)
    body.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            form.requestSubmit();
        }
    });
})();
</script>
@endsection
