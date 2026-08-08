<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Http\Request;

/**
 * ═══════════════════════════════════════════════════════════════
 * تحصيلات الميدان — كل قيود `collection` بطرقها وصور إثباتها
 * (٩ أغسطس ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **شاشة عرض ومطابقة، مش تسجيل.** التسجيل من مكانين بس:
 * الأبلكيشن أثناء زيارة مفتوحة (`FieldApiController::collect`)
 * وصفحة العميل في الـERP (`OpsController::collect`). المحاسب هنا
 * بيطابق الشيكات والتحويلات على صورها ومراجعها.
 */
class CollectionController extends Controller
{
    public function index(Request $request)
    {
        $method = (string) $request->query('method', '');
        $repId = (int) $request->query('rep', 0);
        $from = $request->query('from');
        $to = $request->query('to');
        $user = $request->user();

        // ═══ سكوب واحد للصفوف والإجماليات (إصلاح تدقيق ٩/٨) ═══
        //
        // ⚠️ **`method IS NOT NULL` مش زخرفة.** القيود المولّدة
        // أوتوماتيك (مقابل فاتورة الكاش، تحصيل تسليم الـPO، الداتا
        // المستوردة) كلها `collection` من غير طريقة — عرضها هنا كان
        // بيغرق الشاشة بصفوف «مكتب/—» مجموعها مش في أي كارت فوق.
        // الشاشة دي **للتحصيلات المسجّلة بإيد حد**: ميدان أو مكتب.
        //
        // ⚠️ **وسكوب المدير في الكويري مش بعد الـpaginate.**
        // `setCollection` بعد `paginate(50)` كان بيسيب العدّاد
        // والصفحات محسوبين من الشركة كلها — صفحة فاضية مكتوب عليها
        // «1–50 من 4,200». والأخطر: كروت الإجماليات كانت من غير
        // السكوب خالص، فمدير القناة بيشوف فلوس عملاء غيره —
        // مخالفة مباشرة لدوكترين سكوب الفريق (٨ أغسطس).
        $scoped = function () use ($user, $method, $repId, $from, $to) {
            return Transaction::where('kind', 'collection')
                ->whereNotNull('method')
                ->when(in_array($method, Transaction::METHODS, true),
                    fn ($q) => $q->where('method', $method))
                ->when($from, fn ($q) => $q->whereDate('date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('date', '<=', $to))
                // فلتر المندوب عبر مرساة الزيارة — تحصيل الميدان
                // مصدره `Visit`، وتحصيل المكتب مصدره null
                ->when($repId > 0, fn ($q) => $q
                    ->where('source_type', Visit::class)
                    ->whereIn('source_id', Visit::where('user_id', $repId)->select('id')))
                ->when(! in_array($user->role, ['admin', 'accountant'], true),
                    fn ($q) => $q->whereIn('client_id',
                        Client::visibleTo(Client::query(), $user)->select('id')));
        };

        $rows = $scoped()->with(['client.group'])->latest()
            ->paginate(50)->withQueryString();

        // مين حصّل — زيارة ← مندوبها، وإلا «المكتب». دفعة واحدة
        // بدل كويري لكل صف.
        $visitIds = $rows->getCollection()
            ->where('source_type', Visit::class)->pluck('source_id')->unique();
        $repByVisit = Visit::with('user:id,name,code')->whereIn('id', $visitIds)
            ->get()->keyBy('id');

        // الإجماليات من **نفس السكوب** — الكروت لازم تساوي مجموع
        // الجدول اللي تحتها، وإلا الشاشة بتكدب على المحاسب.
        $totals = $scoped()
            ->selectRaw('method, SUM(credit) total, COUNT(*) cnt')
            ->groupBy('method')
            ->get()->keyBy('method');

        return view('erp.collections', [
            'rows' => $rows,
            'repByVisit' => $repByVisit,
            'totals' => $totals,
            'method' => $method,
            'repId' => $repId,
            'from' => $from,
            'to' => $to,
            'reps' => User::whereIn('role', User::FIELD_ROLES)
                ->where('active', true)->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }
}
