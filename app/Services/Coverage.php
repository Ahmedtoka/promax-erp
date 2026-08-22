<?php

namespace App\Services;

use App\Models\Client;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * تغطية المناديب — «العميل اتسكّن، يبقى يوصل» (٢١ أغسطس ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * بلاغ المالك: عميل متسكّن للمدير ومتفعّل وله منطقة — ومع ذلك مش
 * ظاهر لا لإسلام ولا لنبيل. السبب إن ظهور العميل في الأبلكيشن محتاج
 * **سلسلة كاملة** من ٤ حلقات، وكل حلقة كانت بتتحط في شاشة مختلفة
 * بإيد بني آدم — وأول حلقة تُنسى بتخفي العميل في صمت:
 *
 *   ١. العميل `active`
 *   ٢. العميل له `zone_id`
 *   ٣. **الزون نفسه `active`**              ← بتتنسى كتير
 *   ٤. **الزون متعلّم عليه للمندوب** (`zone_user`) ← بتتنسى أكتر
 *
 * ⚠️ **الحل: التسكين بيجرّ التغطية وراه أوتوماتيك.** أي مسار بيسكّن
 * عميل (إضافة يدوية، تفعيل جماعي، توزيع، موافقة على عميل جديد من
 * الأبلكيشن، تسكين بالخريطة) بينده `Coverage::sync($client)` —
 * فالزون بيتفعّل لو موقوف، وبيتعلّم للمندوب **ولمناديب مديره كلهم**
 * (البول المشترك يعني كلهم بيزوروه فعلاً).
 *
 * ⚠️ **بنضيف بس، مابنشيلش.** إلغاء التعليم قرار إداري من شاشة
 * المناطق — الخدمة دي مالهاش حق تقطع تغطية حد.
 */
class Coverage
{
    /**
     * ظبّط تغطية عميل واحد — بيرجّع اللي اتعمل فعلاً للعرض/اللوج.
     *
     * @return array{zone_activated: bool, reps: array<int, string>}
     */
    public static function sync(?Client $client): array
    {
        $out = ['zone_activated' => false, 'reps' => []];

        if ($client === null || $client->zone_id === null) {
            return $out;
        }

        $zone = Zone::find($client->zone_id);

        if ($zone === null) {
            return $out;
        }

        // ═══ ١. الزون الموقوف بيتفعّل ═══
        // عميل شغّال في منطقة موقوفة = عميل غير موجود بالنسبة
        // للأبلكيشن (الشاشة بتعرض المناطق النشطة بس).
        if (! $zone->active) {
            $zone->update(['active' => true]);
            $out['zone_activated'] = true;
        }

        // ═══ ٢. مين المفروض يشوفه؟ ═══
        // مندوبه + (لو ليه مدير) كل مناديب المدير الميدانيين —
        // دول بيشوفوه أصلاً بالبول المشترك، فلازم منطقته تكون
        // في تغطيتهم عشان تبان في شاشة المناطق.
        $reps = collect();

        if ($client->rep_id !== null) {
            $reps->push(User::find($client->rep_id));
        }

        if ($client->manager_id !== null) {
            $reps = $reps->merge(
                User::whereIn('role', User::FIELD_WORK_ROLES)
                    ->where('manager_id', $client->manager_id)
                    ->where('active', true)
                    ->get()
            );
        }

        // ═══ ٣. تعليم الزون لكل واحد فيهم — إضافة بس ═══
        foreach ($reps->filter()->unique('id') as $rep) {
            // ⚠️ `syncWithoutDetaching` مش `sync` — التانية كانت
            // هتمسح باقي مناطق المندوب وتسيب دي بس.
            $before = $rep->zones()->where('zones.id', $zone->id)->exists();

            if (! $before) {
                $rep->zones()->syncWithoutDetaching([$zone->id]);
                $out['reps'][] = $rep->displayName();
            }
        }

        return $out;
    }

    /** نفس الشغل لمجموعة عملاء — للتفعيل والتوزيع الجماعي */
    public static function syncMany(iterable $clients): array
    {
        $zones = 0;
        $reps = [];

        foreach ($clients as $client) {
            $r = self::sync($client);

            if ($r['zone_activated']) {
                $zones++;
            }

            $reps = array_merge($reps, $r['reps']);
        }

        return ['zones' => $zones, 'reps' => array_values(array_unique($reps))];
    }

    /**
     * ═══ إصلاح شامل بأثر رجعي — لكل عملاء الشركة ═══
     *
     * الزرار اللي بيصلّح التسكينات القديمة كلها مرة واحدة: كل عميل
     * شغّال له منطقة، منطقته بتتفعّل وبتتعلّم لمندوبه ولفريق مديره.
     *
     * @return array{clients: int, zones: int, links: int}
     */
    public static function repairAll(): array
    {
        $clients = 0;
        $zonesFixed = 0;
        $links = 0;

        // ⚠️ `chunkById` — قاعدة فيها آلاف العملاء مش هتتحمّل في الذاكرة
        Client::where('status', 'active')
            ->whereNotNull('zone_id')
            ->chunkById(300, function ($rows) use (&$clients, &$zonesFixed, &$links) {
                foreach ($rows as $client) {
                    $r = self::sync($client);
                    $clients++;

                    if ($r['zone_activated']) {
                        $zonesFixed++;
                    }

                    $links += count($r['reps']);
                }
            });

        return ['clients' => $clients, 'zones' => $zonesFixed, 'links' => $links];
    }
}
