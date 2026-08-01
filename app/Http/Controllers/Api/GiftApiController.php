<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Custody;
use App\Models\GiftHandout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * توزيع الهدايا — المندوب بيقول اداها لمين
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **من غير الشاشة دي، «صرفنا 200 عينة» رقم مالوش تفصيل.**
 * السؤال اللي بيتسأل بعد أي حملة هو «اداها لمين» — والإجابة لازم
 * تكون صف لكل توزيعة مش عدّاد.
 */
class GiftApiController extends Controller
{
    /** الهدايا اللي في عهدة المندوب النهارده */
    public function index(Request $request): JsonResponse
    {
        $custody = $this->custody($request);

        if ($custody === null) {
            return response()->json(['items' => [], 'handouts' => []]);
        }

        $custody->load(['items.product']);

        return response()->json([
            'custody_id' => $custody->id,
            'items' => $custody->items
                // ⚠️ اللي مالوش هدايا مابيبانش — الشاشة بتبقى قايمة
                // الهدايا مش قايمة العهدة كلها.
                ->filter(fn ($i) => (int) $i->gift_assigned > 0)
                ->map(fn ($i) => [
                    'product_id' => $i->product_id,
                    'name' => $i->product?->displayName(),
                    'code' => $i->product?->code,
                    'unit' => $i->product?->unitLabel(),
                    'assigned' => (int) $i->gift_assigned,
                    'given' => (int) $i->gift_given,
                    'left' => $i->giftLeft(),
                ])->values(),
            'handouts' => GiftHandout::where('custody_id', $custody->id)
                ->with(['product', 'client'])
                ->latest()->take(50)->get()
                ->map(fn ($h) => [
                    'id' => $h->id,
                    'product' => $h->product?->displayName(),
                    'client' => $h->client?->displayName(),
                    'qty' => (int) $h->qty,
                    'reason' => $h->reason,
                    'time' => $h->created_at->toIso8601String(),
                ])->values(),
        ]);
    }

    /** تسجيل توزيعة */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'qty' => ['required', 'integer', 'min:1'],
            // ⚠️ العميل اختياري: العينة ممكن تتوزّع في معرض أو على
            // المارّة. إجباره كان هيخلّي المندوب يختار أي عميل عشان
            // يعدّي الشاشة، والرقم يبقى كدب.
            'client_id' => ['nullable', 'exists:clients,id'],
            'visit_id' => ['nullable', 'exists:visits,id'],
            'reason' => ['nullable', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $custody = $this->custody($request);

        if ($custody === null) {
            return response()->json(['error' => __('field.no_open_custody')], 422);
        }

        $error = null;

        DB::transaction(function () use ($data, $custody, $request, &$error) {
            // ⚠️ **القفل قبل الفحص.** توزيعتين في نفس اللحظة كانوا
            // بيقروا نفس الرصيد ويوزّعوا الاتنين، فالمندوب يوزّع
            // أكتر من اللي معاه والرقم يطلع بالسالب.
            $line = $custody->items()
                ->where('product_id', $data['product_id'])
                ->lockForUpdate()
                ->first();

            if ($line === null || $line->giftLeft() < $data['qty']) {
                $error = __('field.gift_not_enough', [
                    'left' => $line?->giftLeft() ?? 0,
                ]);

                return;
            }

            $line->increment('gift_given', $data['qty']);

            GiftHandout::create([
                'custody_id' => $custody->id,
                'user_id' => $request->user()->id,
                'product_id' => $data['product_id'],
                'client_id' => $data['client_id'] ?? null,
                'visit_id' => $data['visit_id'] ?? null,
                'batch_id' => $line->batch_id,
                'qty' => $data['qty'],
                'reason' => $data['reason'] ?? null,
                'note' => $data['note'] ?? null,
            ]);
        });

        if ($error !== null) {
            return response()->json(['error' => $error], 422);
        }

        return response()->json(['ok' => true]);
    }

    private function custody(Request $request): ?Custody
    {
        return Custody::where('user_id', $request->user()->id)
            ->whereDate('date', today())
            ->where('status', 'open')
            ->first();
    }
}
