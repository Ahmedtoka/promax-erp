<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * منع التكرار لأكشنات الموبايل — `idem_key` (الخط ١، ١٧/١٠/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * نفس فكرة `collect` والمرتجع: الأبلكيشن بيولّد مفتاح للمحاولة،
 * وإعادة الإرسال بنفس المفتاح بترجّع نفس المستند بدل مستند تاني.
 *
 *   1. `find()` قبل أي حاجة — لو المفتاح اتسجل، رجّع مستنده.
 *   2. `remember()` **جوه** ترانزاكشن المستند، بعد ما يتعمل. لو ريكوست
 *      تاني بنفس المفتاح سبق، اليونيك بيرمي والترانزاكشن كلها بترجع
 *      (مفيش خصم تاني ولا قيد تاني).
 *   3. `isDuplicate()` في الـcatch — يرجّع المستند الأول بدل 500.
 *
 * ⚠️ المفتاح اختياري: الأبلكيشن القديم اللي مابيبعتش مفتاح شغال زي ما هو.
 */
final class Idempotency
{
    public const INVOICE = 'invoice';

    public const GIFT = 'gift';

    public const PO_DELIVER = 'po_deliver';

    public const PICK_RECEIVE = 'pick_receive';

    public const REPLENISH_ASSIGN = 'replenish_assign';

    public const REFILL = 'refill';

    /** بدء / انتهاء أمر إنتاج (التصنيع ١٩/١٠) — الويب والأبلكيشن نفس الخدمة */
    public const MFG_START = 'mfg_start';

    public const MFG_FINISH = 'mfg_finish';

    /** قواعد الفاليديشن — نفس اللي في `collect` */
    public const RULES = ['nullable', 'string', 'max:64'];

    /**
     * المستند اللي اتسجل بالمفتاح ده، أو null.
     *
     * @template T of Model
     *
     * @param  class-string<T>  $class
     * @return T|null
     */
    public static function find(User $user, string $action, ?string $key, string $class): ?Model
    {
        if ($key === null || $key === '') {
            return null;
        }

        $row = DB::table('idempotency_keys')
            ->where('user_id', $user->id)
            ->where('action', $action)
            ->where('idem_key', $key)
            ->first();

        if ($row === null || $row->subject_id === null) {
            return null;
        }

        return $class::query()->find($row->subject_id);
    }

    /** بيتنده جوه ترانزاكشن المستند — اليونيك هو اللي بيمسك السباق */
    public static function remember(User $user, string $action, ?string $key, Model $subject): void
    {
        if ($key === null || $key === '') {
            return;
        }

        DB::table('idempotency_keys')->insert([
            'user_id' => $user->id,
            'action' => $action,
            'idem_key' => $key,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'created_at' => now(),
        ]);
    }

    /** خطأ اليونيك بتاع الجدول ده بالذات (MySQL 1062) */
    public static function isDuplicate(QueryException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062
            && str_contains($e->getMessage(), 'idem_user_action_key_unique');
    }
}
