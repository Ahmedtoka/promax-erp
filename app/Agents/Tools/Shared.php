<?php

namespace App\Agents\Tools;

use App\Models\Client;
use App\Models\User;

/**
 * أدوات مشتركة بين موديولات أدوات مساعد بروماكس — الحراس والتطبيع.
 */
trait Shared
{
    /**
     * العميل جوه نطاق اليوزر؟ — نفس حارسي كارت العميل بالحرف
     * (`ErpController::client`): canSeeBranch + visibleBy.
     */
    protected static function guardedClient(int $clientId, User $user): ?Client
    {
        $client = Client::with(['group', 'channel'])->find($clientId);

        if ($client === null
            || ! $user->canSeeBranch($client->branch_id)
            || ! $client->visibleBy($user)) {
            return null;
        }

        return $client;
    }

    /**
     * المندوب جوه نطاق اليوزر؟ — نفس حارسي صفحة المندوب بالحرف
     * (`OpsController::rep`): canSeeBranch + سكوب فريق المدير.
     */
    protected static function guardedRep(int $repId, User $user): ?User
    {
        $rep = User::whereIn('role', User::FIELD_WORK_ROLES)->find($repId);

        if ($rep === null
            || ! $user->canSeeBranch($rep->branch_id)
            || ($user->role === 'manager' && (int) $rep->manager_id !== (int) $user->id)) {
            return null;
        }

        return $rep;
    }

    /** رد موحّد للغايب أو اللي بره النطاق — من غير ما نفرّق */
    protected static function notAvailable(): array
    {
        return ['not_available' => true,
            'note' => 'ده مش متاح ليك — يا إما مش موجود يا إما بره نطاقك.'];
    }

    /** تاريخ صالح YYYY-MM-DD وإلا null — «last month» وأشباهها بتتداس */
    protected static function validDate(?string $v): ?string
    {
        return ($v !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : null;
    }
}
