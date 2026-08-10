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
                ->with(['product', 'client', 'clientRequest'])
                ->latest()->take(50)->get()
                ->map(fn ($h) => [
                    'id' => $h->id,
                    'product' => $h->product?->displayName(),
                    // المستلم أياً كان: عميل، طلب جديد، أو توزيع عام
                    'client' => $h->recipientName(),
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
            // طلب عميل جديد — تحت الموافقة أو اتوافق عليه ولسه ماتحوّلش
            'client_request_id' => ['nullable', 'exists:client_requests,id'],
            'visit_id' => ['nullable', 'exists:visits,id'],
            'reason' => ['nullable', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        // ⚠️ الطلب لازم يكون **بتاعه هو** — من غير الفحص ده أي مندوب
        // يقدر يسجل هدايا على طلبات زمايله ويبوّظ حساب الحملة
        if (isset($data['client_request_id'])) {
            $req = \App\Models\ClientRequest::find($data['client_request_id']);

            if ($req === null || (int) $req->created_by !== (int) $request->user()->id) {
            // ⚠️ **المفتاح `message` مش `error`** (تدقيق ٨/٨/٢٠٢٦).
            // الأبلكيشن بيقرا `message` في كل رد (`Api._decode`)، فأي
            // رد بمفتاح `error` كان بيوصل للموظف كرسالة HTTP عامة
            // مالهاش معنى بدل السبب الحقيقي.
                return response()->json(['message' => __('field.gift_not_your_request')], 422);
            }
        }

        $custody = $this->custody($request);

        if ($custody === null) {
            return response()->json(['message' => __('field.no_open_custody')], 422);
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
                'client_request_id' => $data['client_request_id'] ?? null,
                'visit_id' => $data['visit_id'] ?? null,
                'batch_id' => $line->batch_id,
                'qty' => $data['qty'],
                'reason' => $data['reason'] ?? null,
                'note' => $data['note'] ?? null,
            ]);
        });

        if ($error !== null) {
            return response()->json(['message' => $error], 422);
        }

        // ⚠️ **الهدية حركة زي البيع بالظبط** (2026-08-07): بضاعة خرجت
        // من العربية. كانت الشاشة الوحيدة اللي مابتسجّلش حدث تتبع
        // خالص، فالمدير يشوف الرصيد ناقص في غرفة التحكم من غير أي
        // سطر يقول راح فين. الحدث ده هو الرابط.
        $product = \App\Models\Product::find($data['product_id']);
        $client = isset($data['client_id']) ? Client::find($data['client_id']) : null;

        \App\Models\TrackEvent::log(
            $request->user(),
            'gift',
            __('field.track_gift', [
                'qty' => $data['qty'],
                'product' => $product?->displayName() ?? '',
            ]),
            $client?->displayName(),
            ($request->float('lat') ?: null) ?? ($client?->lat !== null ? (float) $client->lat : null),
            ($request->float('lng') ?: null) ?? ($client?->lng !== null ? (float) $client->lng : null),
        );

        return response()->json(['ok' => true]);
    }

    private function custody(Request $request): ?Custody
    {
        // ⚠️ **العهدة المفتوحة مش عهدة النهارده** (إصلاح ١٠/٨) —
        // فلتر النهارده كان بيخلّي توزيع الهدايا يقف الساعة ١٢
        // بالليل رغم إن البضاعة لسه في العربية. نفس عقيدة
        // `User::currentCustody()`.
        return Custody::where('user_id', $request->user()->id)
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '<>', 'closed'))
            ->orderByDesc('date')
            ->first();
    }
}
