<?php

namespace App\Services;

use App\Models\Client;
use App\Models\JourneyPlan;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * ═══════════════════════════════════════════════════════════════
 * خطط السير — توليد زيارات اليوم ومتابعة الالتزام
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ الزيارات **مابتتخزنش مقدماً**. الخطة نمط أسبوعي، وزيارات أي
 * يوم بتتحسب منه وقت الطلب. توليد صفوف لكل عميل لكل يوم معناه
 * جدول بيكبر للأبد وبيحتاج كرون ينضّفه، وأول ما الكرون يقف
 * المناديب بيفتحوا الأبلكيشن ويلاقوه فاضي.
 *
 * ⚠️ الحالة بتتقارن بـ `visits` **بتاريخ اليوم**، مش بعمود على
 * الخطة. عمود «اتزارت» على الخطة لازم يتصفّر كل يوم، وأي يوم
 * الصفر مايحصلش فيه كل المناديب بيبانوا خلصوا.
 */
class Journeys
{
    /**
     * خطة يوم واحد لمندوب — بالحالة الفعلية لكل عميل.
     *
     * @return Collection<int, array{
     *   plan: JourneyPlan, client: Client, visit: ?Visit,
     *   status: string, sort: int
     * }>
     */
    public static function forDay(User $user, ?Carbon $date = null): Collection
    {
        $date = $date ? $date->copy() : today();

        // ⚠️ `contract` و `group.contract` مش مطلوبين هنا — الصفوف
        // اللي بترجع مابتعرضش سعر ولا خصم. تحميلهم كان بيضيف
        // كويريين لكل مندوب في الشاشة اللايف اللي بتترفرش كل دقيقة.
        $plans = JourneyPlan::with(['client.channel'])
            ->where('user_id', $user->id)
            ->where('active', true)
            ->where('weekday', $date->dayOfWeek)
            ->orderBy('sort')
            ->get()
            // ⚠️ التردد بيتفلتر هنا مش في الكويري — `weekOfYear`
            // حسابه في SQL بيختلف من نسخة MySQL للتانية
            ->filter(fn (JourneyPlan $p) => $p->dueOn($date))
            ->values();

        if ($plans->isEmpty()) {
            return collect();
        }

        // زيارة واحدة لكل عميل في اليوم — لو فيه أكتر بناخد الأحدث
        $visits = Visit::where('user_id', $user->id)
            ->whereDate('created_at', $date->toDateString())
            ->whereIn('client_id', $plans->pluck('client_id'))
            ->latest()
            ->get()
            ->keyBy('client_id');

        return $plans->map(function (JourneyPlan $plan) use ($visits) {
            $visit = $visits->get($plan->client_id);

            return [
                'plan' => $plan,
                'client' => $plan->client,
                'visit' => $visit,
                'status' => self::visitStatus($visit),
                'sort' => $plan->sort,
            ];
        });
    }

    /**
     * زيارات خارج الخطة — المندوب زار عميل مش في خطة يومه.
     *
     * ⚠️ لازم تبان. من غيرها نسبة الإنجاز بتبان 60% والمندوب فعلاً
     * اشتغل، بس على عملاء تانيين — وده تقييم ظالم بيخلّي الشاشة
     * تفقد مصداقيتها عند المشرفين.
     *
     * @return Collection<int, Visit>
     */
    public static function offPlan(User $user, ?Carbon $date = null, ?Collection $rows = null): Collection
    {
        $date = $date ? $date->copy() : today();

        // ⚠️ المستثنى = عملاء **خطة النهارده الفعلية** مش كل خطط
        // اليوم ده. عميل كل أسبوعين في أسبوعه الفاضي كان بيتشال من
        // الاتنين: مش في المخطط ولا في «بره الخطة» — الزيارة كانت
        // بتختفي من التقرير كأنها مااتعملتش.
        //
        // `$rows` بتيجي جاهزة من `summary()` عشان مانحسبش الخطة مرتين
        $planned = ($rows ?? self::forDay($user, $date))->map(fn ($r) => $r['client']->id);

        return Visit::with('client')
            ->where('user_id', $user->id)
            ->whereDate('created_at', $date->toDateString())
            ->whereNotIn('client_id', $planned)
            ->get();
    }

    /**
     * ملخص يوم المندوب.
     *
     * @return array{planned: int, done: int, in_visit: int, pending: int, off_plan: int, pct: float}
     */
    public static function summary(User $user, ?Carbon $date = null): array
    {
        $rows = self::forDay($user, $date);

        $done = $rows->where('status', 'done')->count();
        $planned = $rows->count();

        return [
            'planned' => $planned,
            'done' => $done,
            'in_visit' => $rows->where('status', 'in_visit')->count(),
            'pending' => $rows->where('status', 'pending')->count(),
            'off_plan' => self::offPlan($user, $date, $rows)->count(),
            'pct' => $planned > 0 ? round($done / $planned * 100, 1) : 0.0,
        ];
    }

    /**
     * الخطة الأسبوعية الكاملة لمندوب — للشاشة اللي بتوزّع.
     *
     * @return array<int, Collection<int, JourneyPlan>> مفتاحها رقم اليوم
     */
    public static function week(User $user): array
    {
        $plans = JourneyPlan::with('client')
            ->where('user_id', $user->id)
            ->where('active', true)   // الموقوفة مالهاش لازمة في الشبكة
            // ⚠️ `id` كاسر تعادل — الصفوف القديمة كلها sort=0 وكانت
            // بتترتب على مزاج الداتابيز لحد أول ضغطة سهم.
            ->orderBy('sort')->orderBy('id')
            ->get()
            ->groupBy('weekday');

        $out = [];
        foreach (JourneyPlan::WEEKDAYS as $day) {
            $out[$day] = $plans->get($day, collect());
        }

        return $out;
    }

    private static function visitStatus(?Visit $visit): string
    {
        if ($visit === null) {
            return 'pending';
        }

        return $visit->checked_out_at === null ? 'in_visit' : 'done';
    }
}
