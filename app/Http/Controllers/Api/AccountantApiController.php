<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\Visit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ═══════════════════════════════════════════════════════════════
 * API المحاسب على الموبايل (٩ أغسطس ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **إشعار «تحصيل غير نقدي» كان بيوصله ومايفتحش على حاجة** —
 * لوحة صمّاء بتقوله «افتح الويب». الشاشة دي بتوريه التحصيلات
 * بطرقها وصور إثباتها **قراءة بس**: يشوف الشيك من موبايله ويطابق
 * التحويل، والتسجيل والاعتماد فاضلين للويب عن قصد.
 */
class AccountantApiController extends Controller
{
    /** GET /api/accountant/collections — آخر التحصيلات المسجّلة بإيد حد */
    public function collections(Request $request): JsonResponse
    {
        // ⚠️ `whereNotNull('method')` — نفس قاعدة شاشة الويب: القيود
        // المولّدة أوتوماتيك (مقابل فاتورة الكاش/تسليم الـPO) من غير
        // طريقة، وعرضها هنا ضجيج مالوش قيمة مطابقة.
        $rows = Transaction::where('kind', 'collection')
            ->whereNotNull('method')
            ->with(['client.group'])
            ->latest()->take(60)->get();

        // مين حصّل — دفعة واحدة بدل كويري لكل صف
        $visitIds = $rows->where('source_type', Visit::class)
            ->pluck('source_id')->unique();
        $repByVisit = Visit::with('user:id,name,name_en,code')
            ->whereIn('id', $visitIds)->get()->keyBy('id');

        return response()->json([
            'collections' => $rows->map(function (Transaction $t) use ($repByVisit) {
                $visit = $t->source_type === Visit::class
                    ? $repByVisit->get($t->source_id)
                    : null;

                return [
                    'id' => $t->id,
                    'client' => $t->client?->fullName() ?? '—',
                    'amount' => (float) $t->credit,
                    'method' => $t->method,
                    'method_label' => $t->methodLabel(),
                    'reference' => $t->reference,
                    'cheque_bank' => $t->cheque_bank,
                    'cheque_due' => $t->cheque_due?->toDateString(),
                    'proof_url' => $t->proofUrl(),
                    'collected_by' => $visit?->user?->displayName(),
                    'time' => $t->created_at->toIso8601String(),
                ];
            })->values()->all(),
        ]);
    }
}
