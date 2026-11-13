<?php

namespace App\Services;

use App\Models\Client;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * تاريخ قايمة أسعار العميل — آخر تغيير **يدوي** (من شاشة) للقايمة الحالية
 * (قرار أحمد ٢٧ — ١٣/١١/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * د. عمار نقل سلاسل مترو/فريش فود/خير زمان لقايمة «مترو ماركت» من شاشة
 * السلاسل، وقرار أحمد: **تسعير جديد من وقت التغيير** — اللي اتسلّم قبله
 * بيفضل بسعره القديم. المصدر: `activity_logs` (event=updated، changes فيها
 * price_list_id = [قديم، جديد]) على العميل نفسه أو سلسلته.
 *
 * «يدوي» = طلب من شاشة (route موجود أو POST/PUT/PATCH). تشغيل الكونسول
 * (`prices:apply-sheet`) بيتسجّل بـroute فاضي وGET — مش بيتحسب، عشان
 * إعادة تسعير ١٠–١٢/١١ «طابق الورق» تفضل تتدقّق كأخطاء لو مش مطابقة.
 *
 * بيرجّع آخر تغيير قيمته الجديدة = قايمة العميل الحالية:
 *   ['at' => Carbon, 'from' => int|null, 'to' => int, 'by' => string, 'on' => 'client'|'chain']
 */
class ListHistory
{
    /** @var array<int, array|null> */
    private static array $cache = [];

    public static function flush(): void
    {
        self::$cache = [];
    }

    public static function lastManualChange(Client $c): ?array
    {
        if (array_key_exists($c->id, self::$cache)) {
            return self::$cache[$c->id];
        }
        $current = (int) $c->price_list_id;
        if ($current === 0) {
            return self::$cache[$c->id] = null;
        }

        $rows = DB::table('activity_logs')
            ->where('event', 'updated')
            ->where('changes', 'like', '%"price_list_id"%')
            ->where(fn ($q) => $q->whereNotNull('route')->orWhereIn('method', ['POST', 'PUT', 'PATCH']))
            ->where(function ($q) use ($c) {
                $q->where(fn ($w) => $w->where('subject_type', 'Client')->where('subject_id', $c->id));
                if ($c->group_id) {
                    $q->orWhere(fn ($w) => $w->where('subject_type', 'ClientGroup')->where('subject_id', $c->group_id));
                }
            })
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get(['subject_type', 'changes', 'user_name', 'created_at']);

        foreach ($rows as $r) {
            $ch = json_decode((string) $r->changes, true);
            $pl = $ch['price_list_id'] ?? null;
            if (! is_array($pl) || count($pl) !== 2 || (int) $pl[1] !== $current) {
                continue;
            }

            return self::$cache[$c->id] = [
                'at' => Carbon::parse($r->created_at),
                'from' => $pl[0] === null ? null : (int) $pl[0],
                'to' => $current,
                'by' => (string) $r->user_name,
                'on' => $r->subject_type === 'ClientGroup' ? 'chain' : 'client',
            ];
        }

        return self::$cache[$c->id] = null;
    }

    /** نفس العميل في الذاكرة بالقايمة السابقة — لحساب «كان هيتسعّر بكام» من غير كتابة */
    public static function asBefore(Client $c, array $change): ?Client
    {
        if ($change['from'] === null) {
            return null;
        }
        $code = DB::table('price_lists')->where('id', $change['from'])->value('code');
        $clone = $c->replicate();
        $clone->id = $c->id;
        $clone->exists = true;
        $clone->price_list_id = $change['from'];
        if ($code !== null) {
            $clone->price_list = $code;
        }
        $clone->setRelations($c->getRelations());
        $clone->unsetRelation('priceListRow'); // لازم تتحمّل من القايمة السابقة

        return $clone;
    }
}
