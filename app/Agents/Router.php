<?php

namespace App\Agents;

use App\Models\User;

/**
 * ═══════════════════════════════════════════════════════════════
 * راوتر الإيجنتات — التوجيه ونسب الدومين (٧/٩/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * الرؤية: فريق إيجنتات يشغّل بيزنس بروماكس. حالياً `PromaxAgent`
 * المنسق شايف كل الأدوات (حسابات/مبيعات/مخزون/ميداني/أكشن) —
 * فالأسئلة اللي بتلمس أكتر من دومين بتتجاوب في محادثة واحدة.
 *
 * نسب الدومين بيتسجل في `agent_runs.agent_name` من أول أداة
 * اتنادت (accounting/sales/inventory/field/action) — وده اللي
 * شاشة المراجعة بتحلل بيه مين بيسأل عن إيه.
 *
 * لما إيجنت متخصص يتضاف: يورث `BaseAgent` ويتسجل هنا — من غير
 * ما الكنترولر ولا الواجهة يتغيروا.
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
        $agent = new PromaxAgent();

        $result = $agent->handle($message, $history, $context, $user);

        // نسب التشغيلة: دومين أول أداة — من غير أدوات = عام
        $name = $result['refused'] ? 'refused' : ($result['domain'] ?? 'general');

        return ['agent' => $name, 'result' => $result];
    }
}
