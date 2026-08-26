<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\TrackEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ═══════════════════════════════════════════════════════════════
 * ليدات المندوب في الأبلكيشن (بايبلاين ٢٦/٨ — مرحلة ٣)
 * ═══════════════════════════════════════════════════════════════
 *
 * تاب «العملاء المحتملين»: المندوب بيشوف **ليداته هو بس** بالمناطق،
 * يأكّد البيانات من الميدان (النقطة الأولى)، يحدّث الحالة، ولما
 * يقنعه يفتح أكاونت من فورم طلب العميل الجديد الموجود بمرساة
 * `lead_id` — والاعتماد بيقفل الليد «كسبناه» (النقطة التانية).
 *
 * ⚠️ الليد مش عميل: مفيش بيع ولا زيارة رسمية عليه — التأكيد
 * والحالة بس، والباقي بيمشي في فلو العملاء الطبيعي بعد التحويل.
 */
class LeadApiController extends Controller
{
    /** ليداتي بالمناطق — المفتوحة + اللي كسبتها الشهر ده (للحماس) */
    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();

        $leads = Lead::with('zone')
            ->where('assigned_to', $user->id)
            ->where(fn ($w) => $w->whereIn('status', Lead::OPEN_STATUSES)
                ->orWhere(fn ($v) => $v->where('status', 'won')
                    ->where('converted_at', '>=', now()->startOfMonth())))
            ->orderByDesc('score')
            ->get();

        // ═══ مجدولين النهارده (سكشن المحتملين ٢٦/٨) — من خطة المدير،
        // بترتيبه: «بكره روح ده وده» بتظهر هنا يومها ═══
        $today = \App\Models\LeadPlan::with('lead.zone')
            ->where('user_id', $user->id)
            ->whereDate('plan_date', today())
            ->orderBy('sort')
            ->get()
            ->filter(fn ($p) => $p->lead !== null)
            ->map(fn ($p) => $this->payload($p->lead))
            ->values();

        // مجمّعة بالزون — نفس روح تاب المناطق
        $zones = $leads->groupBy('zone_id')->map(fn ($g) => [
            'zone_id' => $g->first()->zone_id,
            'zone' => $g->first()->zone?->displayName() ?? __('lead.no_zone'),
            'leads' => $g->map(fn (Lead $l) => $this->payload($l))->values(),
        ])->sortByDesc(fn ($z) => count($z['leads']))->values();

        return response()->json([
            'today' => $today,
            'zones' => $zones,
            'counts' => [
                'open' => $leads->whereIn('status', Lead::OPEN_STATUSES)->count(),
                'confirmed' => $leads->whereNotNull('confirmed_at')->count(),
                'won_month' => $leads->where('status', 'won')->count(),
            ],
        ]);
    }

    /**
     * تأكيد البيانات من الميدان — «العميل موجود فعلاً وده وضعه».
     * بيكمّل البيانات + بياخد نقطة المندوب الحالية + بيختم
     * `confirmed_at` (النقطة الأولى في الحصاد) وبيرفع الحالة لـ«اتزار».
     */
    public function confirm(Request $request, Lead $lead): JsonResponse
    {
        $this->guard($request, $lead);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30'],
            'contact_name' => ['nullable', 'string', 'max:190'],
            'address' => ['nullable', 'string', 'max:190'],
            'governorate' => ['nullable', 'string', 'max:60'],
            'zone_id' => ['nullable', 'integer', 'exists:zones,id'],
            'note' => ['nullable', 'string', 'max:500'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            // صورة المكان — multipart (فلو الليد المطور ٢٦/٨)
            'photo' => ['nullable', 'file', 'image', 'max:8192'],
        ]);

        $photoPath = $request->hasFile('photo')
            ? $request->file('photo')->store('leads/photos', 'public')
            : null;

        $lead->update(array_filter([
            'name' => $data['name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'contact_name' => $data['contact_name'] ?? null,
            'address' => $data['address'] ?? null,
            'governorate' => $data['governorate'] ?? null,
            'zone_id' => $data['zone_id'] ?? null,
            'photo_path' => $photoPath,
            // نقطة المندوب وهو واقف قدام المحل — أدق من نقطة الدليل
            'lat' => $data['lat'] ?? null,
            'lng' => $data['lng'] ?? null,
        ], fn ($v) => $v !== null) + [
            // ملاحظة الميدان بتتضاف فوق القديم — مش بتدوس عليه
            'notes' => trim(($lead->notes ? $lead->notes."\n" : '')
                .(trim((string) ($data['note'] ?? '')) !== ''
                    ? now()->format('d/m').' — '.trim($data['note']) : '')) ?: $lead->notes,
            'confirmed_at' => $lead->confirmed_at ?? now(),
            'confirmed_by' => $lead->confirmed_by ?? $request->user()->id,
            'status' => in_array($lead->status, ['new', 'contacted'], true) ? 'visited' : $lead->status,
        ]);

        TrackEvent::log($request->user(), 'lead', __('lead.trk_confirmed'),
            $lead->name, $data['lat'] ?? null, $data['lng'] ?? null);

        return response()->json(['ok' => true, 'lead' => $this->payload($lead->fresh('zone'))]);
    }

    /**
     * ═══ فتح أكاونت فوري (فلو الليد المطور ٢٦/٨ — قرار المالك) ═══
     *
     * «يأكد ويفتح ويبيع على طول» — من غير موافقة المدير: التحويل
     * بيمر بـ`Leads::convert()` (المكان الوحيد المقدس) وبعده بنكمّل
     * اللي التحويل مش بيكتبه: المدير بالوراثة من المندوب + كاش وآجل
     * (قرار المالك: عملاء البايبلاين كلهم كده) + صورة الميدان +
     * `Coverage::sync` عشان يظهر في تاب المناطق فوراً.
     *
     * ⚠️ التأكيد شرط — مفيش فتح لعميل ماتأكدش من الميدان الأول.
     */
    public function openAccount(Request $request, Lead $lead): JsonResponse
    {
        $this->guard($request, $lead);

        if ($lead->confirmed_at === null) {
            abort(422, __('lead.open_needs_confirm'));
        }

        $user = $request->user();

        try {
            $client = \Illuminate\Support\Facades\DB::transaction(function () use ($lead, $user) {
                $client = \App\Services\Leads::convert($lead, $user);

                // اللي convert مش بيكتبه — عشان العميل مايتولدش يتيم
                // (درس اعتماد الطلبات ٨/٨) ويبقى كاش وآجل من أول يوم
                // ⚠️ مفيش contact_name على clients — ده عمود client_groups
                $client->update([
                    'manager_id' => $user->manager_id,
                    'branch_id' => $user->branch_id,
                    'channel_id' => $client->channel_id ?? $user->channel_id,
                    'payment_terms' => 'both',
                    'photo_path' => $lead->photo_path,
                ]);

                \App\Services\Coverage::sync($client);

                return $client;
            });
        } catch (\App\Exceptions\Rejected $e) {
            abort(422, $e->getMessage());
        }

        // إشعار لمدير المندوب بس — عرف إن اتفتح أكاونت من الميدان
        $manager = $user->manager_id !== null
            ? \App\Models\User::find($user->manager_id) : null;

        if ($manager !== null) {
            \App\Models\AppNotification::send($manager,
                fn () => '🟢 '.__('lead.n_opened_title'),
                fn () => __('lead.n_opened_body', ['name' => $client->displayName(), 'by' => $user->displayName()]));
        }

        return response()->json([
            'ok' => true,
            'client' => [
                'id' => $client->id,
                'code' => $client->code,
                'name' => $client->displayName(),
            ],
        ]);
    }

    /** تحديث الحالة — اتكلمنا/بنتفاوض/خسرناه بسبب */
    public function setStatus(Request $request, Lead $lead): JsonResponse
    {
        $this->guard($request, $lead);

        $data = $request->validate([
            'status' => ['required', 'in:contacted,negotiating,lost'],
            'lost_reason' => ['required_if:status,lost', 'nullable', 'string', 'max:190'],
        ]);

        $lead->update([
            'status' => $data['status'],
            'lost_reason' => $data['status'] === 'lost' ? ($data['lost_reason'] ?? null) : null,
        ]);

        return response()->json(['ok' => true, 'lead' => $this->payload($lead->fresh('zone'))]);
    }

    // ═══════════════ المشتركات ═══════════════

    /** ليد المندوب نفسه والمفتوح بس — غير كده 403/422 */
    private function guard(Request $request, Lead $lead): void
    {
        abort_unless($lead->assigned_to === $request->user()->id, 403);

        if (! in_array($lead->status, Lead::OPEN_STATUSES, true)) {
            abort(422, __('lead.closed_lead'));
        }
    }

    /** @return array<string, mixed> */
    private function payload(Lead $l): array
    {
        return [
            'id' => $l->id,
            'number' => $l->number,
            'name' => $l->displayName(),
            'phone' => $l->phone,
            'contact_name' => $l->contact_name,
            'address' => $l->address,
            'category' => $l->category_raw,
            'score' => (int) $l->score,
            'status' => $l->status,
            'zone' => $l->zone?->displayName(),
            'lat' => $l->lat !== null ? (float) $l->lat : null,
            'lng' => $l->lng !== null ? (float) $l->lng : null,
            'confirmed' => $l->confirmed_at !== null,
            'notes' => $l->notes,
        ];
    }
}
