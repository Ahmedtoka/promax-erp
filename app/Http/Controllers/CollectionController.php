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

        $rows = Transaction::where('kind', 'collection')
            ->with(['client.group'])
            ->when(in_array($method, Transaction::METHODS, true),
                fn ($q) => $q->where('method', $method))
            ->when($from, fn ($q) => $q->whereDate('date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('date', '<=', $to))
            // ⚠️ فلتر المندوب عبر مرساة الزيارة — التحصيل الميداني
            // مصدره `Visit`، وتحصيل المكتب مصدره null
            ->when($repId > 0, fn ($q) => $q
                ->where('source_type', Visit::class)
                ->whereIn('source_id', Visit::where('user_id', $repId)->select('id')))
            ->latest()
            ->paginate(50)
            ->withQueryString();

        // مين حصّل — زيارة ← مندوبها، وإلا «المكتب». دفعة واحدة
        // بدل كويري لكل صف.
        $visitIds = $rows->getCollection()
            ->where('source_type', Visit::class)->pluck('source_id')->unique();
        $repByVisit = Visit::with('user:id,name,code')->whereIn('id', $visitIds)
            ->get()->keyBy('id');

        // ⚠️ سكوب المدير على عملاءه — نفس دوكترين باقي الشاشات
        $user = $request->user();
        if ($user->role !== 'admin' && $user->role !== 'accountant') {
            $visible = Client::visibleTo(Client::query(), $user)->pluck('id')->flip();
            $rows->setCollection($rows->getCollection()
                ->filter(fn ($t) => isset($visible[$t->client_id]))->values());
        }

        // إجماليات النافذة المعروضة حسب الطريقة — للمطابقة السريعة
        $totals = Transaction::where('kind', 'collection')
            ->when($from, fn ($q) => $q->whereDate('date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('date', '<=', $to))
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
