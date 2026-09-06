<?php

namespace App\Agents\Tools;

use App\Models\AgentAction;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * ═══════════════════════════════════════════════════════════════
 * أدوات الأكشنات — المرحلة التانية: اقتراح بموافقة (٧/٩/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️⚠️ الأداة هنا **بتجهّز بس** — بتعمل صف `agent_actions` حالته
 * pending وبيطلع للمستخدم كارت تأكيد في الشات. التنفيذ الفعلي في
 * `AgentChatController::confirmAction` وقت ضغطة التأكيد، بنفس
 * سيرفس المستند اليدوي (`ManualCollection`). الإيجنت عمره ما
 * يقول إن العملية اتنفذت — لسه اقتراح.
 */
class ActionTools
{
    use Shared;

    public static function specs(): array
    {
        return [
            [
                'name' => 'propose_collection',
                'description' => 'تجهيز تسجيل تحصيل يدوي من عميل باسم مندوب (بيطلع للمستخدم كارت تأكيد — مش بيتسجل غير لما يأكّد). محتاج client_id (من find_client) وrep_id (من find_rep) والمبلغ والطريقة. الفيزا/الشيك/التحويل محتاجين مرجع، والشيك محتاج بنك وتاريخ استحقاق.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'client_id' => ['type' => 'integer', 'description' => 'رقم العميل'],
                        'rep_id' => ['type' => 'integer', 'description' => 'رقم المندوب اللي التحصيل باسمه'],
                        'amount' => ['type' => 'number', 'description' => 'المبلغ المحصّل'],
                        'method' => ['type' => 'string', 'enum' => ['cash', 'card', 'cheque', 'transfer'],
                            'description' => 'طريقة التحصيل'],
                        'date' => ['type' => 'string', 'description' => 'تاريخ التحصيل YYYY-MM-DD (الافتراضي النهارده)'],
                        'reference' => ['type' => 'string', 'description' => 'المرجع — إجباري لغير الكاش'],
                        'cheque_bank' => ['type' => 'string', 'description' => 'بنك الشيك — للشيك بس'],
                        'cheque_due' => ['type' => 'string', 'description' => 'تاريخ استحقاق الشيك YYYY-MM-DD'],
                        'note' => ['type' => 'string', 'description' => 'ملاحظة (اختياري)'],
                    ],
                    'required' => ['client_id', 'rep_id', 'amount', 'method'],
                ],
            ],
        ];
    }

    /**
     * التجهيز — فحص كامل دلوقتي عشان كارت التأكيد مايوعدش بحاجة
     * هتترفض وقت التنفيذ.
     */
    public static function proposeCollection(array $args, User $user): array
    {
        $client = self::guardedClient((int) ($args['client_id'] ?? 0), $user);

        if ($client === null) {
            return self::notAvailable();
        }

        $rep = self::guardedRep((int) ($args['rep_id'] ?? 0), $user);

        if ($rep === null) {
            return ['error' => 'المندوب ده مش متاح ليك — دور بـfind_rep واختار من نتايجه.'];
        }

        $amount = round((float) ($args['amount'] ?? 0), 2);

        if ($amount < 0.01 || $amount > 99999999) {
            return ['error' => 'المبلغ لازم يكون أكبر من صفر.'];
        }

        $method = (string) ($args['method'] ?? '');

        if (! in_array($method, Transaction::METHODS, true)) {
            return ['error' => 'الطريقة لازم تكون: cash أو card أو cheque أو transfer.'];
        }

        $reference = isset($args['reference']) && is_scalar($args['reference'])
            ? trim((string) $args['reference']) : null;

        if (in_array($method, Transaction::METHODS_NEED_REF, true) && ($reference === null || $reference === '')) {
            return ['error' => 'الطريقة دي محتاجة مرجع (رقم العملية/الشيك) — اسأل المستخدم عليه.'];
        }

        $chequeBank = isset($args['cheque_bank']) && is_scalar($args['cheque_bank'])
            ? trim((string) $args['cheque_bank']) : null;
        $chequeDue = self::validDate(isset($args['cheque_due']) ? (string) $args['cheque_due'] : null);

        if ($method === Transaction::METHOD_CHEQUE && ($chequeBank === null || $chequeBank === '' || $chequeDue === null)) {
            return ['error' => 'الشيك محتاج اسم البنك وتاريخ الاستحقاق — اسأل المستخدم عليهم.'];
        }

        $date = self::validDate(isset($args['date']) ? (string) $args['date'] : null)
            ?? today()->toDateString();

        if (Carbon::parse($date)->isAfter(today())) {
            return ['error' => 'تاريخ التحصيل مينفعش يكون في المستقبل.'];
        }

        $action = AgentAction::create([
            'user_id' => $user->id,
            'type' => AgentAction::TYPE_COLLECTION,
            'payload' => [
                'client_id' => $client->id,
                'client_name' => $client->fullName(),
                'rep_id' => $rep->id,
                'rep_name' => $rep->displayName(),
                'amount' => $amount,
                'method' => $method,
                'date' => $date,
                'reference' => $reference ?: null,
                'cheque_bank' => $chequeBank ?: null,
                'cheque_due' => $chequeDue,
                'note' => isset($args['note']) && is_scalar($args['note'])
                    ? mb_substr(trim((string) $args['note']), 0, 200) : null,
            ],
        ]);

        return [
            'action_id' => $action->id,
            'proposed' => true,
            'summary' => "تحصيل {$amount} ج من «{$client->fullName()}» باسم المندوب {$rep->displayName()} بتاريخ {$date} — مستني تأكيد المستخدم.",
            'note' => 'اعرض للمستخدم إن كارت التأكيد ظهر تحت وإن العملية مش هتتسجل غير لما يدوس تأكيد.',
        ];
    }
}
