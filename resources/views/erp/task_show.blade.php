@extends('layouts.system')

{{--
    صفحة المهمة (٢٦ أغسطس ٢٠٢٦): التفاصيل والمرفقات فوق، الشات
    رايح جاي تحت — رسايلي يمين والطرف التاني شمال وسطور السيستم
    (اتسلمت/اتعمدت/اترفضت) في النص. الأزرار بحسب الدور:
    المكلَّف «تم التسليم» · المكلِّف «اعتمد/ارفض» لما تتسلم.
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

    {{-- مرفقات المهمة --}}
    @if ($task->files->isNotEmpty())
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;border-top:1px solid var(--border);padding-top:10px">
            @foreach ($task->files as $f)
                <a class="badge b-blue" href="{{ $f->url() }}" target="_blank" rel="noopener"
                   style="text-decoration:none">📎 {{ $f->name }}</a>
            @endforeach
        </div>
    @endif
</div>

{{-- ═══ الشات ═══ --}}
<div class="card">
    <h3 style="margin:0 0 12px">💬 {{ __('tasks.chat') }}</h3>

    <div id="tkChat" style="max-height:52vh;overflow-y:auto;display:flex;flex-direction:column;gap:8px;padding:4px">
        @forelse ($task->comments as $c)
            @if ($c->is_system)
                <div style="align-self:center;font-size:11px;color:var(--muted);background:var(--card2,#F7F7FA);
                            border:1px dashed var(--border);border-radius:999px;padding:3px 14px">
                    {{ $c->body }} · {{ $c->created_at->format('d/m h:i A') }}
                </div>
            @else
                @php $mineMsg = $c->user_id === $me; @endphp
                <div style="align-self:{{ $mineMsg ? 'flex-end' : 'flex-start' }};max-width:75%;
                            background:{{ $mineMsg ? 'var(--blue-050,#E8F1FF)' : 'var(--card2,#F7F7FA)' }};
                            border:1px solid var(--border);border-radius:12px;padding:8px 12px">
                    <div style="font-size:10.5px;font-weight:800;color:var(--muted)">
                        {{ $c->user?->displayName() }} · {{ $c->created_at->format('d/m h:i A') }}</div>
                    @if ($c->body)
                        <div style="font-size:13px;line-height:1.7;white-space:pre-line;margin-top:2px">{{ $c->body }}</div>
                    @endif
                    @if ($c->file_path)
                        <a class="badge b-blue" href="{{ $c->fileUrl() }}" target="_blank" rel="noopener"
                           style="text-decoration:none;margin-top:4px;display:inline-block">📎 {{ $c->file_name }}</a>
                    @endif
                </div>
            @endif
        @empty
            <div class="empty">{{ __('tasks.no_msgs') }}</div>
        @endforelse
    </div>

    {{-- الإرسال — مقفول بعد الاعتماد: المهمة خلصت --}}
    @if ($task->status !== 'approved')
        <form method="POST" action="{{ route('erp.tasks.comment', $task) }}" enctype="multipart/form-data"
              style="display:flex;gap:8px;margin-top:12px;align-items:flex-end;flex-wrap:wrap">
            @csrf
            <textarea name="body" rows="2" placeholder="{{ __('tasks.msg_ph') }}"
                      style="flex:1;min-width:220px"></textarea>
            <label class="btn" style="cursor:pointer">📎
                <input type="file" name="file" style="display:none"
                       accept=".jpg,.jpeg,.png,.webp,.heic,.xlsx,.xls,.csv,.pdf,.doc,.docx"
                       onchange="document.getElementById('tkFileName').textContent = this.files.length ? this.files[0].name : ''">
            </label>
            <span id="tkFileName" style="font-size:10.5px;color:var(--muted)"></span>
            <button class="btn gold" type="submit">{{ __('tasks.send') }}</button>
        </form>
    @else
        <div class="dash-hint" style="margin-top:10px">🏁 {{ __('tasks.closed_hint') }}</div>
    @endif
</div>

@endsection

@section('scripts')
<script>
// الشات ينزل لآخر رسالة على الفتح
(function () {
    'use strict';
    var c = document.getElementById('tkChat');
    if (c) c.scrollTop = c.scrollHeight;
})();
</script>
@endsection
