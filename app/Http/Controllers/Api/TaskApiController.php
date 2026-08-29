<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskFile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ═══════════════════════════════════════════════════════════════
 * إدارة المهام على الموبايل (٢٨/٨/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * **مرآة `TaskController` بالحرف** — نفس الحراس (طرفَي المهمة بس
 * والأدمن)، نفس قواعد الملفات (مصفوفة مش سترينج بالبايبات — درس
 * ٢٦/٨)، نفس عقيدة النوتفيكيشن (للطرف التاني بس). الفرق الوحيد:
 * JSON بدل الفيوهات، والمرفق في الموبايل صورة من `image_picker`.
 */
class TaskApiController extends Controller
{
    private const FILE_RULES = ['file', 'max:10240', 'mimes:jpg,jpeg,png,webp,heic,xlsx,xls,csv,pdf,doc,docx'];

    /** البورد: مهامي (متأخر/مفتوح/مستني) + اللي كلفتها + المكلَّفين المتاحين */
    public function index(Request $request): JsonResponse
    {
        $u = $request->user();

        $mine = Task::with('creator')
            ->where('assigned_to', $u->id)
            ->orderByRaw('deadline IS NULL')->orderBy('deadline')
            ->get();

        $assigned = Task::with('assignee')
            ->where('created_by', $u->id)
            ->where('assigned_to', '!=', $u->id)
            ->orderByRaw("status = 'submitted' DESC")
            ->orderByRaw("status = 'approved' ASC")
            ->latest()->take(100)->get();

        return response()->json([
            'mine' => $mine->map(fn ($t) => $this->taskPayload($t))->values(),
            'assigned' => $assigned->map(fn ($t) => $this->taskPayload($t))->values(),
            'staff' => User::whereIn('role', User::TASK_ROLES)
                ->where('active', true)->where('id', '!=', $u->id)
                ->orderBy('name')->get()
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->displayName()])->values(),
        ]);
    }

    /** إنشاء مهمة — نفس فاليديشن الويب، والصور multipart `files[]` */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'assigned_to' => ['required', 'integer',
                Rule::exists('users', 'id')->whereIn('role', User::TASK_ROLES)->where('active', true)],
            'priority' => ['required', Rule::in(Task::PRIORITIES)],
            'deadline' => ['nullable', 'date'],
            'files' => ['nullable', 'array', 'max:8'],
            'files.*' => self::FILE_RULES,
        ]);

        $task = Task::create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'assigned_to' => (int) $data['assigned_to'],
            'created_by' => $request->user()->id,
            'priority' => $data['priority'],
            'deadline' => $data['deadline'] ?? null,
        ]);

        foreach ((array) $request->file('files', []) as $f) {
            TaskFile::create([
                'task_id' => $task->id,
                'uploaded_by' => $request->user()->id,
                'path' => $f->store('tasks/'.$task->id, 'public'),
                'name' => $f->getClientOriginalName(),
            ]);
        }

        AppNotification::send($task->assignee,
            fn () => '📋 '.__('tasks.n_new_title'),
            fn () => __('tasks.n_new_body', ['t' => $task->title, 'by' => $request->user()->displayName()]),
            link: AppNotification::taskLink($task->id));

        return response()->json(['ok' => true, 'task' => $this->taskPayload($task->fresh(['creator', 'assignee']))]);
    }

    /** تفاصيل المهمة + الشات كله + المرفقات */
    public function show(Request $request, Task $task): JsonResponse
    {
        $this->guard($request, $task);
        $task->load(['assignee', 'creator', 'files', 'comments.user']);

        return response()->json([
            'task' => $this->taskPayload($task),
            'files' => $task->files->map(fn ($f) => [
                'url' => $f->url(),
                'name' => $f->name,
            ])->values(),
            'comments' => $task->comments->map(fn ($c) => $this->commentPayload($c))->values(),
        ]);
    }

    /** رسالة شات — نص و/أو صورة، إشعار للطرف التاني بس */
    public function comment(Request $request, Task $task): JsonResponse
    {
        $this->guard($request, $task);

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:3000'],
            'file' => array_merge(['nullable'], self::FILE_RULES),
        ]);

        $file = $request->file('file');
        if (trim((string) ($data['body'] ?? '')) === '' && $file === null) {
            return response()->json(['ok' => false, 'error' => __('tasks.empty_msg')], 422);
        }

        $c = TaskComment::create([
            'task_id' => $task->id,
            'user_id' => $request->user()->id,
            'body' => trim((string) ($data['body'] ?? '')) ?: null,
            'file_path' => $file?->store('tasks/'.$task->id, 'public'),
            'file_name' => $file?->getClientOriginalName(),
        ]);

        $this->notifyOther($request, $task,
            fn () => '💬 '.__('tasks.n_msg_title'),
            fn () => __('tasks.n_msg_body', ['t' => $task->title, 'by' => $request->user()->displayName()]));

        return response()->json(['ok' => true, 'comment' => $this->commentPayload($c)]);
    }

    /** بولينج الشات — بعد آخر id + الحالة (زي الويب بالظبط) */
    public function comments(Request $request, Task $task): JsonResponse
    {
        $this->guard($request, $task);

        $after = (int) $request->query('after', 0);

        return response()->json([
            'status' => $task->status,
            'comments' => $task->comments()->where('id', '>', $after)->get()
                ->map(fn ($c) => $this->commentPayload($c))->values(),
        ]);
    }

    public function submit(Request $request, Task $task): JsonResponse
    {
        $this->guard($request, $task);
        abort_unless($task->assigned_to === $request->user()->id, 403);

        if ($task->status !== 'open') {
            return response()->json(['ok' => false, 'error' => __('tasks.not_open')], 422);
        }

        $task->update(['status' => 'submitted', 'submitted_at' => now()]);
        $this->systemLine($request, $task, __('tasks.sys_submitted'));

        AppNotification::send($task->creator,
            fn () => '✅ '.__('tasks.n_done_title'),
            fn () => __('tasks.n_done_body', ['t' => $task->title, 'by' => $task->assignee->displayName()]),
            link: AppNotification::taskLink($task->id));

        return response()->json(['ok' => true, 'status' => 'submitted']);
    }

    public function approve(Request $request, Task $task): JsonResponse
    {
        $this->guardDecide($request, $task);

        if ($task->status !== 'submitted') {
            return response()->json(['ok' => false, 'error' => __('tasks.not_submitted')], 422);
        }

        $task->update(['status' => 'approved', 'approved_at' => now()]);
        $this->systemLine($request, $task, __('tasks.sys_approved'));

        AppNotification::send($task->assignee,
            fn () => '🏁 '.__('tasks.n_approved_title'),
            fn () => __('tasks.n_approved_body', ['t' => $task->title]),
            link: AppNotification::taskLink($task->id));

        return response()->json(['ok' => true, 'status' => 'approved']);
    }

    public function reject(Request $request, Task $task): JsonResponse
    {
        $this->guardDecide($request, $task);

        if ($task->status !== 'submitted') {
            return response()->json(['ok' => false, 'error' => __('tasks.not_submitted')], 422);
        }

        $reason = trim((string) $request->input('reason'));

        $task->update([
            'status' => 'open',
            'submitted_at' => null,
            'rejections' => $task->rejections + 1,
        ]);
        $this->systemLine($request, $task, __('tasks.sys_rejected').($reason !== '' ? ' — '.$reason : ''));

        AppNotification::send($task->assignee,
            fn () => '↩️ '.__('tasks.n_rejected_title'),
            fn () => __('tasks.n_rejected_body', ['t' => $task->title]),
            good: false,
            link: AppNotification::taskLink($task->id));

        return response()->json(['ok' => true, 'status' => 'open']);
    }

    // ═══════════════ الحراس والمشتركات — مرآة الويب ═══════════════

    private function guard(Request $request, Task $task): void
    {
        $u = $request->user();
        abort_unless($u->role === 'admin'
            || $task->assigned_to === $u->id
            || $task->created_by === $u->id, 403);
    }

    private function guardDecide(Request $request, Task $task): void
    {
        $u = $request->user();
        abort_unless($u->role === 'admin' || $task->created_by === $u->id, 403);
    }

    private function systemLine(Request $request, Task $task, string $text): void
    {
        TaskComment::create([
            'task_id' => $task->id,
            'user_id' => $request->user()->id,
            'body' => $text,
            'is_system' => true,
        ]);
    }

    private function notifyOther(Request $request, Task $task, \Closure $title, \Closure $body): void
    {
        $me = $request->user()->id;
        $other = $me === $task->assigned_to ? $task->creator : $task->assignee;

        if ($other !== null && $other->id !== $me) {
            AppNotification::send($other, $title, $body,
                link: AppNotification::taskLink($task->id));
        }
    }

    private function taskPayload(Task $t): array
    {
        return [
            'id' => $t->id,
            'title' => $t->title,
            'description' => $t->description,
            'status' => $t->status,
            'priority' => $t->priority,
            'is_late' => $t->isLate(),
            'deadline' => $t->deadline?->format('Y-m-d'),
            'rejections' => (int) $t->rejections,
            'assigned_to' => (int) $t->assigned_to,
            'created_by' => (int) $t->created_by,
            'assignee' => $t->relationLoaded('assignee') ? $t->assignee?->displayName() : null,
            'creator' => $t->relationLoaded('creator') ? $t->creator?->displayName() : null,
            'created' => $t->created_at?->format('d/m'),
        ];
    }

    private function commentPayload(TaskComment $c): array
    {
        $isImg = $c->file_path !== null
            && in_array(strtolower(pathinfo($c->file_path, PATHINFO_EXTENSION)),
                ['jpg', 'jpeg', 'png', 'webp'], true);

        return [
            'id' => $c->id,
            'user_id' => (int) $c->user_id,
            'name' => $c->user?->displayName() ?? '—',
            'body' => $c->body,
            'file_url' => $c->fileUrl(),
            'file_name' => $c->file_name,
            'is_img' => $isImg,
            'is_system' => (bool) $c->is_system,
            't' => $c->created_at?->format('d/m h:i A'),
        ];
    }
}
