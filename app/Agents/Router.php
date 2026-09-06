<?php

namespace App\Agents;

use App\Models\User;

/**
 * ═══════════════════════════════════════════════════════════════
 * راوتر الإيجنتات — بيحدد الدومين ويوجّه الطلب لإيجنته
 * ═══════════════════════════════════════════════════════════════
 *
 * الرؤية: فريق إيجنتات (حسابات / عملاء / مخزون / ميداني / موردين /
 * تطوير) كل واحد بأدواته. في المرحلة الأولى كل حاجة بتروح لإيجنت
 * الحسابات، وهو اللي بيرفض بأدب أي طلب بره نطاقه (بيبدأ رده
 * بعلامة الرفض ⛔ → بتتسجل status=refused في `agent_runs`).
 *
 * لما إيجنت جديد يتضاف: ضيفه هنا في `agents()` وحدّد قواعد
 * التوجيه — من غير ما الكنترولر ولا الواجهة يتغيروا.
 */
class Router
{
    /**
     * @param  array<int, array{user: string, assistant: string}>  $history
     * @param  array<string, mixed>  $context
     * @return array{agent: string, result: array}
     */
    public function dispatch(string $message, array $history, array $context, User $user): array
    {
        // المرحلة الأولى: إيجنت واحد — الحسابات
        $agent = new AccountingAgent();

        return [
            'agent' => AccountingAgent::NAME,
            'result' => $agent->handle($message, $history, $context, $user),
        ];
    }
}
