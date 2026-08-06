<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadPing;
use App\Models\RepSettlement;
use App\Models\Setting;
use App\Models\TrackEvent;
use App\Services\RepKpis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ═══════════════════════════════════════════════════════════════
 * API الحوافز والليدز — الأبديت الكبير (2026-08-06)
 * ═══════════════════════════════════════════════════════════════
 *
 * - `appOpen`: بينج فتح الأبلكيشن — TrackEvent نوع `open` (بيتعد
 *   في «فتح الأبلكيشن كام مرة» في لوحة الأداء).
 * - `nearbyLeads`: العملاء المحتملين في نطاق الإعدادات حوالين
 *   المندوب — أليرت نمط أوبر. اللي قَبله أو رفضه قبل كده مش بيرجع.
 * - `leadAction`: قبول (بيتسكّن عليه ويبدأ يتحرك له) أو رفض.
 * - `myIncentives`: شاشة التشجيع — تارجتات وتحقيق ونقاط وعمولة
 *   ورصيد التصفية.
 */
class IncentiveApiController extends Controller
{
    /** بينج فتح الأبلكيشن — بيتنده مرة مع كل فتحة من الموبايل */
    public function appOpen(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        TrackEvent::log(
            $request->user(),
            'open',
            __('field.event_app_open'),
            null,
            isset($data['lat']) ? (float) $data['lat'] : null,
            isset($data['lng']) ? (float) $data['lng'] : null,
        );

        return response()->json(['ok' => true]);
    }

    /**
     * الليدز القريبة — في نطاق `lead_alert_km` من موقع المندوب.
     * اللي عليه قرار (قبول/رفض) من المندوب ده مش بيتعرض تاني،
     * و«ظهر له» بيتسجل مرة واحدة بس عشان الإحصاء.
     */
    public function nearbyLeads(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $user = $request->user();
        $km = (float) Setting::read('lead_alert_km', '1');

        // اللي المندوب قرر فيه خلاص — مش بينوّر تاني
        $decided = LeadPing::where('user_id', $user->id)
            ->whereIn('action', ['accepted', 'rejected'])
            ->pluck('lead_id');

        // فلترة مبدئية بمربع إحداثيات (درجة ≈ 111 كم) — وبعدين
        // المسافة الدقيقة بهافرساين في PHP على المرشحين القليلين
        $delta = $km / 111 * 1.5;

        $leads = Lead::whereNotNull('lat')->whereNotNull('lng')
            ->whereNotIn('status', ['won', 'lost'])
            ->whereNotIn('id', $decided)
            ->whereBetween('lat', [(float) $data['lat'] - $delta, (float) $data['lat'] + $delta])
            ->whereBetween('lng', [(float) $data['lng'] - $delta, (float) $data['lng'] + $delta])
            ->get()
            ->map(function (Lead $lead) use ($data) {
                $d = RepKpis::haversine((float) $data['lat'], (float) $data['lng'], (float) $lead->lat, (float) $lead->lng);

                return ['lead' => $lead, 'km' => $d];
            })
            ->filter(fn ($r) => $r['km'] <= $km)
            ->sortBy('km')
            ->take(5)
            ->values();

        // «ظهر له» بيتسجل مرة واحدة — الإحصاء: عدّى جمب كام ليد
        foreach ($leads as $r) {
            LeadPing::firstOrCreate(
                ['lead_id' => $r['lead']->id, 'user_id' => $user->id, 'action' => 'shown'],
            );
        }

        return response()->json([
            'leads' => $leads->map(fn ($r) => [
                'id' => $r['lead']->id,
                'name' => $r['lead']->displayName(),
                'phone' => $r['lead']->phone,
                'address' => $r['lead']->address,
                'lat' => (float) $r['lead']->lat,
                'lng' => (float) $r['lead']->lng,
                'distance_m' => (int) round($r['km'] * 1000),
                'expected_monthly' => (float) $r['lead']->expected_monthly,
            ]),
        ]);
    }

    /** قرار المندوب في الليد: accepted يتسكّن عليه · rejected مش بينوّر تاني */
    public function leadAction(Request $request, Lead $lead): JsonResponse
    {
        $data = $request->validate(['action' => ['required', 'in:accepted,rejected']]);
        $user = $request->user();

        LeadPing::create([
            'lead_id' => $lead->id,
            'user_id' => $user->id,
            'action' => $data['action'],
        ]);

        // القبول بيسكّن الليد على المندوب (لو مش متسكّن) ويعلّمه «اتزار»
        if ($data['action'] === 'accepted') {
            $lead->update([
                'assigned_to' => $lead->assigned_to ?? $user->id,
                'status' => in_array($lead->status, ['new', 'contacted'], true) ? 'visited' : $lead->status,
            ]);
        }

        return response()->json([
            'ok' => true,
            'lat' => (float) $lead->lat,
            'lng' => (float) $lead->lng,
        ]);
    }

    /** شاشة التشجيع — كل حوافز المندوب للشهر الحالي + رصيد تصفيته */
    public function myIncentives(Request $request): JsonResponse
    {
        $user = $request->user();
        $k = RepKpis::forMonth($user, now());
        $t = $k['target'];
        $last = RepSettlement::lastFor($user->id);

        return response()->json([
            'month' => now()->format('Y-m'),
            'targets' => [
                'money' => ['target' => (float) ($t?->money_target ?? 0), 'done' => $k['net_sales'], 'pct' => $k['money_pct']],
                'visits' => ['target' => (int) ($t?->visits_target ?? 0), 'done' => $k['visits'], 'pct' => $k['visits_pct']],
                'new_clients' => ['target' => (int) ($t?->new_clients_target ?? 0), 'done' => $k['new_clients'], 'pct' => $k['clients_pct']],
                'pieces' => ['target' => (int) ($t?->pieces_target ?? 0), 'done' => $k['pieces'], 'pct' => $k['pieces_pct']],
            ],
            'points' => [
                'auto' => $k['auto_points'],
                'manual' => $k['manual_points'],
                'total' => $k['points'],
                'money' => $k['points_money'],
            ],
            'commission' => [
                'rate_pct' => round($k['commission_rate'] * 100, 2),
                'amount' => $k['commission'],
            ],
            'settlement' => $last ? [
                'number' => $last->number,
                'date' => $last->to_at->toDateString(),
                'balance' => (float) $last->balance,
            ] : null,
            // إحصاء الليدز بتاعته — عدّى جمب كام وقَبل كام ورفض كام
            'leads' => [
                'shown' => LeadPing::where('user_id', $user->id)->where('action', 'shown')->count(),
                'accepted' => LeadPing::where('user_id', $user->id)->where('action', 'accepted')->count(),
                'rejected' => LeadPing::where('user_id', $user->id)->where('action', 'rejected')->count(),
            ],
        ]);
    }
}
