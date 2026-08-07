<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * سجل حركة اليوزرات — للأدمن بس.
 *
 * ⚠️ **قراءة فقط.** مفيش تعديل ولا مسح من الشاشة: سجل بيتعدّل مش
 * سجل. التنضيف بيحصل من أمر مجدول بفترة احتفاظ معلومة.
 */
class AuditController extends Controller
{
    public function index(Request $request)
    {
        $q = ActivityLog::with('user:id,name,name_en,code')->latest('id');

        if ($u = $request->integer('user')) {
            $q->where('user_id', $u);
        }
        if ($e = $request->string('event')->value()) {
            $q->where('event', $e);
        }
        if ($m = $request->string('model')->value()) {
            $q->where('subject_type', $m);
        }
        if ($s = $request->string('q')->trim()->value()) {
            $q->where(fn ($w) => $w->where('title', 'like', "%$s%")
                ->orWhere('user_name', 'like', "%$s%")
                ->orWhere('url', 'like', "%$s%"));
        }
        // ⚠️ التاريخ بـwhereDate مش between على نص — «من» و«لحد»
        // بيتكتبوا Y-m-d والمقارنة النصية بتسيب أحداث اليوم الأخير بره
        if ($from = $request->date('from')) {
            $q->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->date('to')) {
            $q->whereDate('created_at', '<=', $to);
        }

        // إحصاء اليوم — بيتحسب على نفس الفلاتر عشان الكروت تطابق الجدول
        $today = ActivityLog::whereDate('created_at', today());

        return view('erp.audit', [
            'rows' => $q->paginate(60)->withQueryString(),
            'filters' => $request->only(['user', 'event', 'model', 'q', 'from', 'to']),
            'users' => User::orderBy('name')->get(['id', 'name', 'name_en', 'code']),
            'models' => ActivityLog::query()->select('subject_type')
                ->whereNotNull('subject_type')->distinct()
                ->orderBy('subject_type')->pluck('subject_type'),
            'kpi' => [
                'today' => (clone $today)->count(),
                'users_today' => (clone $today)->distinct()->count('user_id'),
                'edits_today' => (clone $today)->whereIn('event', ['created', 'updated', 'deleted'])->count(),
                'logins_today' => (clone $today)->where('event', 'login')->count(),
                'total' => ActivityLog::count(),
            ],
        ]);
    }
}
