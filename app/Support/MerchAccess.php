<?php

namespace App\Support;

use App\Models\Channel;
use App\Models\Client;
use App\Models\User;
use App\Services\Journeys;

/**
 * ═══════════════════════════════════════════════════════════════
 * البروموتر يقدر يشتغل على الفرع ده؟ — القاعدة الواحدة (١٥ سبتمبر ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * كانت مكتوبة مرتين بشكلين مختلفين: بدء زيارة الرف (`PromoterApiController`)
 * بيحكم بالقناة + الزون أو فروع خطة النهارده، و«تأكيد عنوان الفرع» من جوه
 * الزيارة (`FieldApiController::ownsClient`) بيحكم بقاعدة المندوب (البول
 * والزون بالـpivot). النتيجة: البروموتر واقف في فرع من خطة النهارده،
 * الزيارة مفتوحة، ويدوس «تأكيد العنوان» فيترمي 403 «مش عميلك».
 *
 * ⚠️ نفس الفلتر اللي بيبني قايمة الفروع في بوت ستراب البروموتر —
 * القايمة اللي بيشوفها هي اللي مسموح له بيها. لو الفلتر هناك اتغيّر،
 * غيّر هنا.
 */
final class MerchAccess
{
    public static function allows(User $user, Client $client): bool
    {
        // القناة: بروموتر بلا قناة = كي أكاونت، وإلا قناته بالحرف
        $allowed = $user->channel_id === null
            ? $client->channel?->code === Channel::KEY_ACCOUNT
            : (int) $client->channel_id === (int) $user->channel_id;

        // الزون: لو البروموتر مسكّن على زون، الفرع لازم يكون فيه
        if ($user->zone_id !== null && (int) $client->zone_id !== (int) $user->zone_id) {
            $allowed = false;
        }

        // ⚠️ **وفروع خطة النهارده كمان** (٢٨/٨): المالك اللي حط الفرع في
        // الخطة، فهو مسموح له بالزيارة حتى لو بره زونه.
        if (! $allowed) {
            $allowed = Journeys::forDay($user)
                ->contains(fn ($r) => (int) $r['client']->id === (int) $client->id);
        }

        return $allowed;
    }
}
