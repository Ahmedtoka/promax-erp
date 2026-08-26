@extends('layouts.system')

{{--
    إدارة المهام — البورد (٢٦ أغسطس ٢٠٢٦):

    • «مهامي»: ٣ أعمدة — النهارده/القادمة · المتأخرة · اللي خلصت.
    • «اللي كلفتها»: جدول بمين وحالة إيه — المستنية اعتمادي منوّرة
      وعليها زراير اعتمد/ارفض سريعة من غير ما أدخل المهمة.
    • ➕ إضافة مهمة: ديالوج بموظف + عنوان + وصف + ملفات (صور/شيتات)
      + ديدلاين + أولوية.
    • داش بورد فقط (User::TASK_ROLES) — مفيش مناديب ولا سواقين.
--}}

@section('title', __('tasks.title'))

@section('actions')
    <button class="btn gold" type="button" onclick="document.getElementById('dlgAddTask').showModal()">
        ➕ {{ __('tasks.add') }}</button>
@endsection

@section('content')

@php
    $prLabel = fn ($t) => __('tasks.pr_'.$t->priority);
    $card = function ($t, $who) use ($prLabel) {
        // $who: creator (في مهامي) أو assignee (في اللي كلفتها)
        return view('erp._task_card', ['t' => $t, 'who' => $who, 'prLabel' => $prLabel])->render();
    };
@endphp

{{-- ═══ مهامي — بورد ٣ أعمدة ═══ --}}
<div class="tk-board">
    <div class="tk-col">
        <div class="tk-col-h">📌 {{ __('tasks.col_today') }} <span class="badge b-blue">{{ $today->count() }}</span></div>
        @forelse ($today as $t) {!! $card($t, 'creator') !!}
        @empty <div class="empty" style="padding:18px">{{ __('tasks.none') }}</div> @endforelse
    </div>
    <div class="tk-col tk-late">
        <div class="tk-col-h">⏰ {{ __('tasks.col_late') }} <span class="badge b-red">{{ $late->count() }}</span></div>
        @forelse ($late as $t) {!! $card($t, 'creator') !!}
        @empty <div class="empty" style="padding:18px">{{ __('tasks.none_late') }}</div> @endforelse
    </div>
    <div class="tk-col">
        <div class="tk-col-h">🏁 {{ __('tasks.col_done') }} <span class="badge b-green">{{ $done->count() }}</span></div>
        @forelse ($done as $t) {!! $card($t, 'creator') !!}
        @empty <div class="empty" style="padding:18px">{{ __('tasks.none') }}</div> @endforelse
    </div>
</div>

{{-- ═══ اللي كلفتها لغيري ═══ --}}
@if ($assigned->isNotEmpty())
<div class="card" style="margin-top:16px">
    <h3 style="margin:0 0 10px">🧑‍💼 {{ __('tasks.i_assigned') }}</h3>
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
            <tr @class(['tk-wait' => $t->status === 'submitted'])>
                <td style="text-align:start">
                    <a href="{{ route('erp.tasks.show', $t) }}" style="font-weight:800">{{ $t->title }}</a>
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

{{-- ═══ ديالوج إضافة مهمة ═══ --}}
<dialog id="dlgAddTask" style="border:none;border-radius:16px;padding:0;max-width:520px;width:94vw">
    <form method="POST" action="{{ route('erp.tasks.store') }}" enctype="multipart/form-data" style="padding:20px">
        @csrf
        <h3 style="margin:0 0 14px">➕ {{ __('tasks.add') }}</h3>

        <label class="f">{{ __('tasks.f_assignee') }}</label>
        <select name="assigned_to" required style="width:100%;margin-bottom:10px">
            <option value="">—</option>
            @foreach ($staff as $s)
                <option value="{{ $s->id }}">{{ $s->displayName() }} — {{ $s->roleLabel() }}</option>
            @endforeach
        </select>

        <label class="f">{{ __('tasks.f_title') }}</label>
        <input name="title" required maxlength="200" style="width:100%;margin-bottom:10px">

        <label class="f">{{ __('tasks.f_desc') }}</label>
        <textarea name="description" rows="4" style="width:100%;margin-bottom:10px"></textarea>

        <div style="display:flex;gap:10px;margin-bottom:10px">
            <div style="flex:1">
                <label class="f">{{ __('tasks.f_deadline') }}</label>
                <input type="datetime-local" name="deadline" style="width:100%">
            </div>
            <div style="flex:1">
                <label class="f">{{ __('tasks.f_priority') }}</label>
                <select name="priority" style="width:100%">
                    @foreach (\App\Models\Task::PRIORITIES as $p)
                        <option value="{{ $p }}" @selected($p === 'normal')>{{ __('tasks.pr_'.$p) }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <label class="f">{{ __('tasks.f_files') }}</label>
        <input type="file" name="files[]" multiple style="width:100%;margin-bottom:4px"
               accept=".jpg,.jpeg,.png,.webp,.heic,.xlsx,.xls,.csv,.pdf,.doc,.docx">
        <div class="dash-hint" style="margin-bottom:14px">{{ __('tasks.h_files') }}</div>

        <div style="display:flex;gap:10px;justify-content:flex-end">
            <button class="btn" type="button" onclick="document.getElementById('dlgAddTask').close()">{{ __('common.cancel') }}</button>
            <button class="btn gold" type="submit">📋 {{ __('tasks.submit_task') }}</button>
        </div>
    </form>
</dialog>

@endsection

@section('scripts')
<style>
.tk-board{display:flex;gap:12px;align-items:flex-start;flex-wrap:wrap}
.tk-col{flex:1;min-width:260px;background:var(--card2,#F7F7FA);border:1px solid var(--border);
    border-radius:14px;padding:12px}
.tk-col-h{display:flex;align-items:center;gap:8px;font-weight:900;font-size:13.5px;margin-bottom:10px}
.tk-late{background:#FDF6F6;border-color:#F3D6D6}
/* المستنية اعتمادي منوّرة في الجدول */
.tk-wait{background:#FFF8EC}
</style>
@endsection
