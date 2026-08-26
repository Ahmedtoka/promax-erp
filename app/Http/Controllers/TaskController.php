<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskFile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ═══════════════════════════════════════════════════════════════
 * إدارة المهام — Task Management (٢٦ أغسطس ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * الفلو: إنشاء بمكلَّف وملفات وديدلاين وأولوية → نوتفيكيشن للمكلَّف
 * → شات رايح جاي → «تم التسليم» → نوتفيكيشن للمكلِّف → اعتماد أو
 * رفض (الرفض بيرجّعها مفتوحة بسطر سيستم في الشات).
 *
 * الرؤية: طرفَي المهمة بس (Task::visibleTo) — والأدمن الكل.
 * الرولز: User::TASK_ROLES — داش بورد فقط، مفيش مناديب/سواقين.
 * النوتفيكيشن بعقيدة «لصاحبه فقط»: كل إشعار لطرف واحد محدد.
 */
class TaskController extends Controller
{
    /**
     * مرفقات مسموحة: صور + شيتات + PDF/Word — 10 ميجا للملف.
     * ⚠️ **مصفوفة مش سترينج بالبايبات** — سترينج جوه مصفوفة قواعد
     * بيتقري كقاعدة واحدة اسمها «file|max» ويرمي BadMethodCallException
     * (حصلت فعلاً على اللايف ٢٦/٨ عند إرسال صورة في الشات).
     */
    private const FILE_RULES = ['file', 'max:10240', 'mimes:jpg,jpeg,png,webp,heic,xlsx,xls,csv,pdf,doc,docx'];

    public function index()
    {
        $u = auth()->user();

        // ═══ بورد الموظف: مهامي — اليوم / متأخرة / خلصت ═══
        $mine = Task::with('creator')
            ->where('assigned_to', $u->id)
            ->orderByRaw('deadline IS NULL')->orderBy('deadline')
            ->get();

        $late = $mine->filter(fn ($t) => $t->isLate())->values();
        $today = $mine->filter(fn ($t) => $t->status === 'open' && ! $t->isLate())->values();
        $done = $mine->filter(fn ($t) => in_array($t->status, ['submitted', 'approved'], true))
            ->sortByDesc('updated_at')->take(30)->values();

        // ═══ اللي كلفتها لغيري — المستنية اعتمادي الأول ═══
        $assigned = Task::with('assignee')
            ->where('created_by', $u->id)
            ->where('assigned_to', '!=', $u->id)
            ->orderByRaw("status = 'submitted' DESC")
            ->orderByRaw("status = 'approved' ASC")
            ->latest()->take(100)->get();

        return view('erp.tasks', [
            'today' => $today,
            'late' => $late,
            'done' => $done,
            'assigned' => $assigned,
            // المكلَّفين المتاحين — رولز الداش بورد بس ومن غيري أنا
            'staff' => User::whereIn('role', User::TASK_ROLES)
                ->where('active', true)->where('id', '!=', $u->id)
                ->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
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
            'created_by' => auth()->id(),
            'priority' => $data['priority'],
            'deadline' => $data['deadline'] ?? null,
        ]);

        foreach ((array) $request->file('files', []) as $f) {
            TaskFile::create([
                'task_id' => $task->id,
                'uploaded_by' => auth()->id(),
                'path' => $f->store('tasks/'.$task->id, 'public'),
                'name' => $f->getClientOriginalName(),
            ]);
        }

        // الإشعار للمكلَّف بس — بلغته (عقيدة النوتفيكيشن)
        AppNotification::send($task->assignee,
            fn () => '📋 '.__('tasks.n_new_title'),
            fn () => __('tasks.n_new_body', ['t' => $task->title, 'by' => auth()->user()->displayName()]),
            link: AppNotification::taskLink($task->id));

        return redirect()->route('erp.tasks.show', $task)->with('ok', __('tasks.created'));
    }

    public function show(Task $task)
    {
        $this->guard($task);

        return view('erp.task_show', [
            'task' => $task->load(['assignee', 'creator', 'files', 'comments.user']),
        ]);
    }

    /**
     * رسالة في الشات — نص و/أو مرفق، والإشعار للطرف التاني بس.
     * أجاكس (٢٦/٨): بيرجع JSON بالرسالة الجاهزة فالشات بيتحدث من
     * غير ريفريش — والفورم العادي فولباك لو الجافاسكربت مقفولة.
     */
    public function comment(Request $request, Task $task)
    {
        $this->guard($task);

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:3000'],
            'file' => array_merge(['nullable'], self::FILE_RULES),
        ]);

        $file = $request->file('file');
        if (trim((string) ($data['body'] ?? '')) === '' && $file === null) {
            return $request->expectsJson()
                ? response()->json(['ok' => false, 'error' => __('tasks.empty_msg')], 422)
                : back()->withErrors(['body' => __('tasks.empty_msg')]);
        }

        $c = TaskComment::create([
            'task_id' => $task->id,
            'user_id' => auth()->id(),
            'body' => trim((string) ($data['body'] ?? '')) ?: null,
            'file_path' => $file?->store('tasks/'.$task->id, 'public'),
            'file_name' => $file?->getClientOriginalName(),
        ]);

        $this->notifyOther($task,
            fn () => '💬 '.__('tasks.n_msg_title'),
            fn () => __('tasks.n_msg_body', ['t' => $task->title, 'by' => auth()->user()->displayName()]));

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'comment' => $this->commentPayload($c)]);
        }

        return back();
    }

    /**
     * بولينج الشات (٢٦/٨): الرسايل اللي بعد آخر id عند المتصفح +
     * حالة المهمة — لو الحالة اتغيرت (اتسلمت/اتعمدت) الصفحة بتعمل
     * ريفريش عشان الأزرار تتظبط.
     */
    public function comments(Request $request, Task $task)
    {
        $this->guard($task);

        $after = (int) $request->query('after', 0);

        return response()->json([
            'status' => $task->status,
            'comments' => $task->comments()->where('id', '>', $after)->get()
                ->map(fn ($c) => $this->commentPayload($c))->values(),
        ]);
    }

    /** شكل الرسالة الموحد للأجاكس — نفس اللي البليد بيرسمه بالظبط */
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

    /** «تم التسليم» — المكلَّف بس، والإشعار للمكلِّف */
    public function submit(Task $task)
    {
        $this->guard($task);
        abort_unless($task->assigned_to === auth()->id(), 403);

        if ($task->status !== 'open') {
            return back()->withErrors(['task' => __('tasks.not_open')]);
        }

        $task->update(['status' => 'submitted', 'submitted_at' => now()]);
        $this->systemLine($task, __('tasks.sys_submitted'));

        AppNotification::send($task->creator,
            fn () => '✅ '.__('tasks.n_done_title'),
            fn () => __('tasks.n_done_body', ['t' => $task->title, 'by' => $task->assignee->displayName()]),
            link: AppNotification::taskLink($task->id));

        return back()->with('ok', __('tasks.submitted_ok'));
    }

    /** اعتماد — المكلِّف (أو الأدمن)، والإشعار للمكلَّف */
    public function approve(Task $task)
    {
        $this->guardDecide($task);

        if ($task->status !== 'submitted') {
            return back()->withErrors(['task' => __('tasks.not_submitted')]);
        }

        $task->update(['status' => 'approved', 'approved_at' => now()]);
        $this->systemLine($task, __('tasks.sys_approved'));

        AppNotification::send($task->assignee,
            fn () => '🏁 '.__('tasks.n_approved_title'),
            fn () => __('tasks.n_approved_body', ['t' => $task->title]),
            link: AppNotification::taskLink($task->id));

        return back()->with('ok', __('tasks.approved_ok'));
    }

    /** رفض — بيرجّعها مفتوحة بسبب مكتوب في الشات، والإشعار للمكلَّف */
    public function reject(Request $request, Task $task)
    {
        $this->guardDecide($task);

        if ($task->status !== 'submitted') {
            return back()->withErrors(['task' => __('tasks.not_submitted')]);
        }

        $reason = trim((string) $request->input('reason'));

        $task->update([
            'status' => 'open',
            'submitted_at' => null,
            'rejections' => $task->rejections + 1,
        ]);
        $this->systemLine($task, __('tasks.sys_rejected').($reason !== '' ? ' — '.$reason : ''));

        AppNotification::send($task->assignee,
            fn () => '↩️ '.__('tasks.n_rejected_title'),
            fn () => __('tasks.n_rejected_body', ['t' => $task->title]),
            good: false,
            link: AppNotification::taskLink($task->id));

        return back()->with('ok', __('tasks.rejected_ok'));
    }

    // ═══════════════ الحراس والمشتركات ═══════════════

    /** طرفَي المهمة بس — والأدمن. أي حد تاني 403 حتى لو معاه اللينك */
    private function guard(Task $task): void
    {
        $u = auth()->user();
        abort_unless($u->role === 'admin'
            || $task->assigned_to === $u->id
            || $task->created_by === $u->id, 403);
    }

    /** قرار الاعتماد/الرفض للمكلِّف — والأدمن يفك أي زنقة */
    private function guardDecide(Task $task): void
    {
        $u = auth()->user();
        abort_unless($u->role === 'admin' || $task->created_by === $u->id, 403);
    }

    /** سطر سيستم وسط الشات — التاريخ بيتقري من نفس التسلسل */
    private function systemLine(Task $task, string $text): void
    {
        TaskComment::create([
            'task_id' => $task->id,
            'user_id' => auth()->id(),
            'body' => $text,
            'is_system' => true,
        ]);
    }

    /** إشعار للطرف التاني في المهمة — مش لكاتب الرسالة نفسه */
    private function notifyOther(Task $task, \Closure $title, \Closure $body): void
    {
        $other = auth()->id() === $task->assigned_to ? $task->creator : $task->assignee;

        if ($other !== null && $other->id !== auth()->id()) {
            AppNotification::send($other, $title, $body,
                link: AppNotification::taskLink($task->id));
        }
    }
}
