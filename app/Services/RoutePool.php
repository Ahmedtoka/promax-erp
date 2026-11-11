<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Client;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * ═══ مصدر واحد لعملاء خط السير (١١/١١ — راسم خط السير للعملاء) ═══
 *
 * البورد والراسم والمتابعة بيعرضوا نفس العميل بنفس الشكل — فالكويري
 * (بول المندوب، مناديب الفريق) والصف الجاهز للجافاسكربت هنا مرة واحدة.
 */
class RoutePool
{
    /** مناديب الشاشة: فريق الشارع المرئي + المدير على نفسه */
    public static function reps(?User $viewer): Collection
    {
        $reps = User::fieldVisibleTo(Branch::scope(User::with('zone')), $viewer)
            ->whereIn('role', User::FIELD_WORK_ROLES)
            ->where('active', true)
            ->orderBy('name')
            ->get();

        if ($viewer !== null && $viewer->role === 'manager'
            && ! $reps->contains('id', $viewer->id)) {
            $reps->push($viewer->load('zone'));
        }

        return $reps->values();
    }

    /**
     * بول المندوب — عملاؤه الأساسيين + بول مديره + زونه.
     * ⚠️ نفس شرط البورد القديم بالحرف (مافيش توسيع للسكوب).
     */
    public static function poolQuery(User $rep, ?User $viewer)
    {
        return Client::visibleTo(
            Client::with(['zone', 'group', 'channel'])->where('status', 'active')
                ->where(function ($q) use ($rep) {
                    if ($rep->role === 'manager') {
                        $q->where('manager_id', $rep->id);

                        return;
                    }

                    $q->where('rep_id', $rep->id);

                    if ($rep->manager_id !== null) {
                        $q->orWhere('manager_id', $rep->manager_id);
                    }

                    if ($rep->zone_id) {
                        $q->orWhere('zone_id', $rep->zone_id);
                    }
                }),
            $viewer
        );
    }

    /** آخر زيارة لكل عميل — كويري مجمّع واحد */
    public static function lastVisits(Collection $clientIds): Collection
    {
        $ids = $clientIds->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return Visit::whereIn('client_id', $ids->all())
            ->whereNotNull('checked_in_at')
            ->selectRaw('client_id, MAX(checked_in_at) as t')
            ->groupBy('client_id')
            ->pluck('t', 'client_id');
    }

    /** صف العميل للجافاسكربت */
    public static function row(Client $c, Collection $lastVisits): array
    {
        $t = $lastVisits->get($c->id);

        return [
            'id' => $c->id,
            'code' => $c->code,
            'name' => $c->fullName(),
            'zone' => $c->zone?->displayName(),
            'gov' => $c->zone?->governorateLabel() ?: $c->governorateLabel(),
            'channel' => $c->channel?->displayName(),
            'chain' => $c->group?->displayName(),
            'balance' => (float) $c->balance,
            'rep_id' => $c->rep_id,
            'lat' => $c->lat !== null ? (float) $c->lat : null,
            'lng' => $c->lng !== null ? (float) $c->lng : null,
            'q' => mb_strtolower(trim($c->fullName().' '.$c->name_en.' '.$c->code.' '
                .($c->group?->name_en ?? '').' '.($c->zone?->displayName() ?? ''))),
            'last_days' => $t !== null ? (int) Carbon::parse($t)->diffInDays(now()) : null,
        ];
    }
}
