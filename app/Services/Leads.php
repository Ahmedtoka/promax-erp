<?php

namespace App\Services;

use App\Exceptions\Rejected;
use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * تحويل العميل المحتمل لعميل فعلي.
 *
 * ⚠️ **المكان الوحيد.** التحويل بينشئ عميل بكود جديد ويقفل الليد.
 * لو اتعمل في كنترولر، أول ما نضيف حقل للعميل هننساه هنا وهيتولد
 * عميل ناقص بيانات محدش واخد باله.
 */
class Leads
{
    public static function convert(Lead $lead, User $by, array $overrides = []): Client
    {
        // ⚠️ الفحص جوه الترانزاكشن وبقفل — ضغطتين على الزرار في نفس
        // اللحظة كانوا هيعملوا عميلين بنفس الاسم وكودين مختلفين.
        return DB::transaction(function () use ($lead, $by, $overrides) {
            $locked = Lead::whereKey($lead->id)->lockForUpdate()->first();

            if ($locked === null) {
                throw new Rejected(__('lead.not_found'));
            }

            if ($locked->client_id !== null) {
                throw new Rejected(__('lead.already_converted', [
                    'client' => $locked->client?->displayName() ?? '',
                ]));
            }

            // اسم مكرر معناه غالباً الليد ده اتفتح يدوي قبل كده
            $name = $overrides['name'] ?? $locked->name;
            $clash = Client::where('name', $name)->first();

            if ($clash) {
                throw new Rejected(__('lead.name_taken', ['code' => $clash->code]));
            }

            $client = Client::create([
                'code' => Client::nextCode(),
                'name' => $name,
                'name_en' => $locked->name_en,
                'phone' => $locked->phone,
                'address' => $locked->address,
                'zone_id' => $overrides['zone_id'] ?? $locked->zone_id,
                'channel_id' => $overrides['channel_id'] ?? $locked->channel_id,
                // المندوب اللي شغّال على الليد بياخد العميل
                'rep_id' => $locked->assigned_to,
                'lat' => $locked->lat,
                'lng' => $locked->lng,
                // ⚠️ عميل جديد = `ok` مش `grow`. التصنيف التجاري بيتحدد
                // من سلوك شراء فعلي، وإدّينا `grow` من غير مشتريات
                // بيخلّي تقارير النمو تكدب من أول يوم.
                'category' => 'ok',
                'status' => 'active',
                'is_new' => true,
                // ⚠️ من غير خصم خاص — بياخد خصم قناته لحد ما يتعمل له عقد
                'discount' => 0,
                'uses_channel_discount' => true,
                'price_list' => 'new',
                'created_by' => $by->id,
            ]);

            $locked->update([
                'status' => 'won',
                'client_id' => $client->id,
                'converted_at' => now(),
            ]);

            return $client;
        });
    }
}
