<?php

namespace App\Agents\Tools;

use App\Models\AttendanceDay;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * ═══════════════════════════════════════════════════════════════
 * أدوات الميدان — دومين «ميداني» في مساعد بروماكس (قراءة فقط)
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ المصادر: الحضور من `attendance_days` (نفس بورد الحضور)،
 * ونشاط المندوب بنفس كويريات صفحة المندوب (`OpsController::rep`)
 * بالحرف. سكوب الفريق بـ`User::fieldVisibleTo` زي كل الشاشات.
 */
class FieldTools
{
    use Shared;

    public static function specs(): array
    {
        return [
            [
                'name' => 'find_rep',
                'description' => 'البحث عن مندوب/موظف ميداني بالاسم — بيرجع مرشحين بأرقامهم. استخدمها قبل أي أداة محتاجة rep_id.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'اسم المندوب أو جزء منه'],
                    ],
                    'required' => ['name'],
                ],
            ],
            [
                'name' => 'attendance_today',
                'description' => 'مين اتحرك النهارده ومين لسه: كل الفريق الميداني — اللي عمل تشيك إن (وامتى) واللي لسه محركش.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                    'required' => [],
                ],
            ],
            [
                'name' => 'rep_activity',
                'description' => 'نشاط مندوب في فترة (الافتراضي النهارده): الزيارات والفواتير والتحصيل الميداني والمرتجعات — نفس أرقام صفحة المندوب. محتاج rep_id من find_rep.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'rep_id' => ['type' => 'integer', 'description' => 'رقم المندوب'],
                        'from' => ['type' => 'string', 'description' => 'من تاريخ YYYY-MM-DD (الافتراضي النهارده)'],
                        'to' => ['type' => 'string', 'description' => 'إلى تاريخ YYYY-MM-DD (الافتراضي النهارده)'],
                    ],
                    'required' => ['rep_id'],
                ],
            ],
        ];
    }

    public static function findRep(string $name, User $user): array
    {
        $s = trim($name);

        if (mb_strlen($s) < 2) {
            return ['error' => 'اكتب حرفين على الأقل من الاسم.'];
        }

        $norm = \App\Models\Client::normalizeArabic($s);

        // نفس سكوب فريق الميدان بتاع كل الشاشات
        // ⚠️ سكوب الفرع كمان (مراجعة ٧/٩) — مدير الفرع مايشوفش
        // مناديب فرع تاني حتى كأسامي مرشحين
        $rows = User::fieldVisibleTo(\App\Models\Branch::scope(
            User::whereIn('role', User::FIELD_WORK_ROLES)->where('active', true), $user,
        ), $user)
            ->where(function ($w) use ($s, $norm) {
                $w->where('code', 'like', "%$s%")
                    ->orWhereRaw(\App\Models\Client::normSql('name').' like ?', ["%{$norm}%"]);
            })
            ->orderBy('name')->limit(8)->get(['id', 'name', 'name_en', 'code', 'role']);

        return [
            'candidates' => $rows->map(fn (User $u) => [
                'rep_id' => $u->id,
                'name' => $u->displayName(),
                'code' => $u->code,
                'role' => __('enums.role.'.$u->role),
            ])->values()->all(),
        ];
    }

    public static function attendanceToday(User $user): array
    {
        // الفريق الظاهر لليوزر ده — نفس سكوب بورد الحضور
        $team = User::fieldVisibleTo(\App\Models\Branch::scope(
            User::whereIn('role', User::FIELD_WORK_ROLES)->where('active', true), $user,
        ), $user)->orderBy('name')->get(['id', 'name', 'name_en', 'code']);

        // نفس مصدر البورد: صف `attendance_days` بتاريخ النهارده
        $days = AttendanceDay::whereDate('date', today())
            ->whereIn('user_id', $team->pluck('id'))
            ->get()->keyBy('user_id');

        $in = [];
        $absent = [];

        foreach ($team as $u) {
            $d = $days->get($u->id);

            if ($d !== null && $d->first_in_at !== null) {
                $in[] = [
                    'rep_id' => $u->id,
                    'name' => $u->displayName(),
                    'in_at' => Carbon::parse($d->first_in_at)->format('h:i A'),
                    'out_at' => $d->last_out_at ? Carbon::parse($d->last_out_at)->format('h:i A') : null,
                ];
            } else {
                $absent[] = ['rep_id' => $u->id, 'name' => $u->displayName()];
            }
        }

        return [
            'date' => today()->toDateString(),
            'team_count' => $team->count(),
            'checked_in' => $in,
            'not_yet' => $absent,
        ];
    }

    public static function repActivity(int $repId, ?string $from, ?string $to, User $user): array
    {
        $rep = self::guardedRep($repId, $user);

        if ($rep === null) {
            return self::notAvailable();
        }

        $a = Carbon::parse($from ?? today())->startOfDay();
        $b = Carbon::parse($to ?? today())->endOfDay();

        // ⚠️ نفس كويريات صفحة المندوب (`OpsController::rep`) بالحرف

        $inv = \App\Models\Invoice::where('user_id', $rep->id)
            ->whereBetween('created_at', [$a, $b])
            ->selectRaw("COUNT(*) AS n, COALESCE(SUM(grand_total), 0) AS grand,
                COALESCE(SUM(CASE WHEN payment = 'cash' THEN grand_total ELSE 0 END), 0) AS cash")
            ->first();

        $po = \App\Models\PurchaseOrder::where('assigned_to', $rep->id)
            ->where('status', 'delivered')
            ->whereBetween('delivered_at', [$a, $b])
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(grand_total), 0) AS grand')->first();

        $coll = \App\Models\Transaction::where('kind', 'collection')
            ->where('source_type', \App\Models\Visit::class)
            ->whereIn('source_id', \App\Models\Visit::where('user_id', $rep->id)->select('id'))
            ->whereBetween('created_at', [$a, $b])
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(credit), 0) AS total')->first();

        $rets = \App\Models\ClientReturn::where('user_id', $rep->id)
            ->whereBetween('created_at', [$a, $b])
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(grand_total), 0) AS grand')->first();

        $visits = \App\Models\Visit::where('user_id', $rep->id)
            ->whereBetween('checked_in_at', [$a, $b])
            ->selectRaw('COUNT(*) AS n,
                COALESCE(SUM(CASE WHEN checked_out_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS done,
                COUNT(DISTINCT client_id) AS clients')->first();

        return [
            'rep_id' => $rep->id,
            'rep' => $rep->displayName(),
            'from' => $a->toDateString(),
            'to' => $b->toDateString(),
            'visits' => ['count' => (int) $visits->n, 'done' => (int) $visits->done,
                'clients' => (int) $visits->clients],
            'invoices' => ['count' => (int) $inv->n, 'grand' => round((float) $inv->grand, 2),
                'cash' => round((float) $inv->cash, 2)],
            'delivered_pos' => ['count' => (int) $po->n, 'grand' => round((float) $po->grand, 2)],
            'field_collections' => ['count' => (int) $coll->n, 'total' => round((float) $coll->total, 2)],
            'returns' => ['count' => (int) $rets->n, 'grand' => round((float) $rets->grand, 2)],
        ];
    }
}
