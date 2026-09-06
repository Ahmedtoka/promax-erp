<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\Client;
use App\Models\TrackEvent;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * التحصيل اليدوي — القلب المشترك (٧/٩/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * اتسحب من `ManualDocController::storeCollection` عشان يبقى مصدر
 * واحد للعملية: المستند اليدوي **ومساعد بروماكس** (أكشن التحصيل
 * بموافقة) بينفذوا نفس الكود بالحرف — قيد `collection` بتاريخ
 * الورقة باسم المندوب + إعادة حساب الرصيد ذرّياً + إشعار المحاسبين
 * لغير الكاش. أي تعديل هنا بيسري على الاتنين.
 *
 * ⚠️ الفاليديشن والحراس (Scope) مسؤولية اللي بينده — السيرفس
 * بتفترض إن البيانات اتفحصت.
 */
class ManualCollection
{
    /**
     * تسجيل تحصيل يدوي باسم مندوب بتاريخ رجعي.
     *
     * @return Transaction القيد اللي اتسجل
     */
    public static function record(
        User $actor,
        User $rep,
        Client $client,
        Carbon $date,
        float $amount,
        string $method,
        ?string $reference = null,
        ?string $chequeBank = null,
        ?string $chequeDue = null,
        ?string $note = null,
    ): Transaction {
        $amount = round($amount, 2);

        $tx = DB::transaction(function () use (
            $actor, $rep, $client, $date, $amount, $method,
            $reference, $chequeBank, $chequeDue, $note,
        ) {
            $tx = Transaction::create([
                'client_id' => $client->id,
                'date' => $date->toDateString(),
                'memo' => $note
                    ?: __('ops.md_collect_memo', [
                        'rep' => $rep->displayName(),
                        'user' => $actor->displayName(),
                    ]),
                'debit' => 0,
                'credit' => $amount,
                'kind' => 'collection',
                'method' => $method,
                'reference' => $reference,
                'cheque_bank' => $method === Transaction::METHOD_CHEQUE ? $chequeBank : null,
                'cheque_due' => $method === Transaction::METHOD_CHEQUE ? $chequeDue : null,
                // نسبة التحصيل للمندوب — شاشة التحصيلات بتعرضه بيها
                'source_type' => User::class,
                'source_id' => $rep->id,
            ]);

            // التاريخ الرجعي — `created_at` مش fillable، وده المسار الوحيد
            Transaction::whereKey($tx->id)->update(['created_at' => $date]);

            $client->recalculate();

            return $tx;
        });

        TrackEvent::log($rep, 'collect',
            __('field.event_collect', [
                'amount' => number_format($amount, 2),
                'client' => $client->displayName(),
            ]),
            __('ops.md_by_admin', ['user' => $actor->displayName()]));

        // ⚠️ غير الكاش بيتبلّغ للمحاسبين — نفس قاعدة تحصيل الأبلكيشن
        if ($method !== Transaction::METHOD_CASH) {
            foreach (User::where('role', 'accountant')->where('active', true)->get() as $acc) {
                AppNotification::send(
                    $acc,
                    fn () => __('ops.md_collect_notif_title'),
                    fn () => __('ops.md_collect_notif_body', [
                        'amount' => number_format($amount, 2),
                        'client' => $client->displayName(),
                        'method' => $tx->methodLabel(),
                    ]),
                    false,
                );
            }
        }

        return $tx;
    }
}
