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
 * ═════════════════════════════════════════════════
 * التحصيل اليدوي — القلب المشترك (٧/٩/٢٠٢٦ · مباشر ٩/٩)
 * ═════════════════════════════════════════════════
 *
 * اتسحب من `ManualDocController::storeCollection` عشان يبقى مصدر
 * واحد للعملية: المستند اليدوي **ومساعد بروماكس** (أكشن التحصيل
 * بموافقة) **وتحصيل كارت العميل** بينفذوا نفس الكود بالحرف — قيد
 * `collection` بتاريخ الورقة + إعادة حساب الرصيد ذرّياً + إشعار
 * المحاسبين لغير الكاش. أي تعديل هنا بيسري على الكل.
 *
 * ⭐ **التحصيل المباشر (٩/٩/٢٠٢٦):** `$rep = null` — العميل حوّل على
 * البنك أو بعت شيك من غير أي مندوب. القيد بلا `source` (بيبان «إدخال
 * مكتبي» في شاشة التحصيلات) ومعاه صورة الإثبات، ولو العميل خصم
 * **ضرايب تحت الحساب** من التحويل بيتسجّل لها قيد `taxded` دائن منفصل
 * بنفس التاريخ والإثبات — فالعميل يتصفّى بالمبلغ الأصلي كله (المحوَّل +
 * المخصوم للمصلحة)، والمحاسب يلاقي المخصوم لوحده وقت التسوية.
 *
 * ⚠️ الفاليديشن والحراس (Scope) مسؤولية اللي بينده — السيرفس
 * بتفترض إن البيانات اتفحصت.
 */
class ManualCollection
{
    /**
     * تسجيل تحصيل بتاريخ معيّن — باسم مندوب أو مباشر من العميل.
     *
     * @param  ?User  $rep  المندوب المنسوب له التحصيل — `null` = مباشر بلا مندوب
     * @param  ?string  $proofPath  مسار صورة الإثبات على ديسك `public`
     * @param  float  $taxWithheld  ضرايب خصمها العميل تحت الحساب — قيد `taxded` منفصل لو > 0
     * @return Transaction قيد التحصيل (قيد الضريبة مربوط بنفس التاريخ والمرجع)
     */
    public static function record(
        User $actor,
        ?User $rep,
        Client $client,
        Carbon $date,
        float $amount,
        string $method,
        ?string $reference = null,
        ?string $chequeBank = null,
        ?string $chequeDue = null,
        ?string $note = null,
        ?string $proofPath = null,
        float $taxWithheld = 0.0,
    ): Transaction {
        $amount = round($amount, 2);
        $taxWithheld = round(max($taxWithheld, 0), 2);

        $memo = $note ?: ($rep !== null
            ? __('ops.md_collect_memo', ['rep' => $rep->displayName(), 'user' => $actor->displayName()])
            : __('ops.md_direct_memo', ['user' => $actor->displayName()]));

        $tx = DB::transaction(function () use (
            $actor, $rep, $client, $date, $amount, $method,
            $reference, $chequeBank, $chequeDue, $memo, $proofPath, $taxWithheld,
        ) {
            $tx = Transaction::create([
                'client_id' => $client->id,
                'date' => $date->toDateString(),
                'memo' => $memo,
                'debit' => 0,
                'credit' => $amount,
                'kind' => 'collection',
                'method' => $method,
                'reference' => $reference,
                'cheque_bank' => $method === Transaction::METHOD_CHEQUE ? $chequeBank : null,
                'cheque_due' => $method === Transaction::METHOD_CHEQUE ? $chequeDue : null,
                'proof_path' => $proofPath,
                // نسبة التحصيل للمندوب — شاشة التحصيلات بتعرضه بيها.
                // المباشر بلا مصدر = «إدخال مكتبي».
                'source_type' => $rep !== null ? User::class : null,
                'source_id' => $rep?->id,
            ]);

            // التاريخ الرجعي — `created_at` مش fillable، وده المسار الوحيد
            Transaction::whereKey($tx->id)->update(['created_at' => $date]);

            // ⚠️ الضريبة المخصومة تحت الحساب قيد **منفصل** دائن: العميل
            // دفع المبلغ كله فعلاً (جزء لنا وجزء للمصلحة باسمنا)، فرصيده
            // ينقص بالاتنين — والمحاسب يشوف المخصوم لوحده في كشفه.
            if ($taxWithheld > 0) {
                $taxTx = Transaction::create([
                    'client_id' => $client->id,
                    'date' => $date->toDateString(),
                    'memo' => __('ops.md_taxded_memo', [
                        'ref' => $reference ?: $date->toDateString(),
                        'user' => $actor->displayName(),
                    ]),
                    'debit' => 0,
                    'credit' => $taxWithheld,
                    'kind' => 'taxded',
                    'reference' => $reference,
                    'proof_path' => $proofPath,
                    'source_type' => $rep !== null ? User::class : null,
                    'source_id' => $rep?->id,
                ]);
                Transaction::whereKey($taxTx->id)->update(['created_at' => $date]);
            }

            $client->recalculate();

            return $tx;
        });

        if ($rep !== null) {
            TrackEvent::log($rep, 'collect',
                __('field.event_collect', [
                    'amount' => number_format($amount, 2),
                    'client' => $client->displayName(),
                ]),
                __('ops.md_by_admin', ['user' => $actor->displayName()]));
        }

        // ⚠️ غير الكاش بيتبلّغ للمحاسبين — نفس قاعدة تحصيل الأبلكيشن.
        // المحاسب نفسه لو هو اللي سجّل مايتبلّغش بنفسه.
        if ($method !== Transaction::METHOD_CASH) {
            foreach (User::where('role', 'accountant')->where('active', true)->where('id', '!=', $actor->id)->get() as $acc) {
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
