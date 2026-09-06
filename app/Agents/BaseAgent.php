<?php

namespace App\Agents;

use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * ═══════════════════════════════════════════════════════════════
 * الأساس المشترك لإيجنتات مساعد بروماكس (٧/٩/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * بيشيل لوب الـtool use على Anthropic Messages API + تجميع التوكنز
 * + تنضيف الماركداون + بناء بلوك البيانات (عبر `Presenter`) من
 * نتيجة الأداة نفسها مش من كلام الموديل — عشان رقم الشات = رقم
 * الشاشة بالقرش. الإيجنت الوارث بيحدد اسمه وبرومبته بس.
 *
 * ⚠️ المفتاح من config('agents.key') — عمره ما يوصل للفرونت.
 */
abstract class BaseAgent
{
    /** علامة الرفض — الموديل بيبدأ بيها لما الطلب بره نطاقه */
    public const REFUSAL_MARK = '⛔';

    private const API_URL = 'https://api.anthropic.com/v1/messages';

    abstract public function name(): string;

    abstract protected function systemPrompt(User $user, array $context): string;

    /**
     * @param  array<int, array{user: string, assistant: string}>  $history
     * @param  array<string, mixed>  $context
     * @return array{text: string, data: ?array, link: ?array, action: ?array,
     *               refused: bool, domain: ?string, tools_called: array,
     *               tokens_in: int, tokens_out: int}
     */
    public function handle(string $message, array $history, array $context, User $user): array
    {
        $messages = [];

        foreach ($history as $h) {
            $messages[] = ['role' => 'user', 'content' => $h['user']];
            $messages[] = ['role' => 'assistant', 'content' => $h['assistant']];
        }

        $messages[] = ['role' => 'user', 'content' => $message];

        $toolsCalled = [];
        $tokensIn = 0;
        $tokensOut = 0;
        $lastTool = null;       // آخر أداة نجحت — بلوك البيانات بيتبني منها
        $lastResult = null;
        $text = '';
        $finished = false;      // اللوب خلص برد نهائي ولا خلصت اللفّات؟
        $domain = null;         // دومين أول أداة — نسب التشغيلة
        $actionResult = null;   // اقتراح أكشن (لو حصل) — كارته لازم يظهر

        // ═══ لوب الـtool use — بسقف لفّات عشان مايلفّش للأبد ═══
        for ($round = 0; $round < (int) config('agents.max_tool_rounds'); $round++) {
            $body = $this->post($messages, $user, $context);

            $tokensIn += (int) ($body['usage']['input_tokens'] ?? 0);
            $tokensOut += (int) ($body['usage']['output_tokens'] ?? 0);

            $content = $body['content'] ?? [];
            $text = collect($content)->where('type', 'text')->pluck('text')->implode("\n");

            if (($body['stop_reason'] ?? '') !== 'tool_use') {
                $finished = true;

                break;
            }

            // نفّذ كل نداءات الأدوات في الرد ده ورجّع نتايجها
            $messages[] = ['role' => 'assistant', 'content' => $content];
            $results = [];

            foreach ($content as $block) {
                if (($block['type'] ?? '') !== 'tool_use') {
                    continue;
                }

                $args = (array) ($block['input'] ?? []);
                $out = Tools::call($block['name'], $args, $user);

                $toolsCalled[] = ['name' => $block['name'], 'args' => $args];
                $domain ??= Tools::domainOf($block['name']);

                if (! isset($out['error'])) {
                    // ⚠️ الأكشن المقترح بيتحفظ لوحده (مراجعة ٧/٩) — لو
                    // الموديل ندا أداة قراية بعده، كارت التأكيد كان
                    // بيضيع والصف pending معلّق في الداتابيز
                    if ($block['name'] === 'propose_collection') {
                        $actionResult = $out;
                    } else {
                        $lastTool = $block['name'];
                        $lastResult = $out;
                    }
                }

                $results[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $block['id'],
                    'content' => json_encode($out, JSON_UNESCAPED_UNICODE),
                ];
            }

            $messages[] = ['role' => 'user', 'content' => $results];
        }

        // خلصت اللفّات والموديل لسه عايز أدوات؟ — مفيش رد نص نص
        if (! $finished) {
            throw new AgentException(__('agent.err_generic'));
        }

        // ⚠️ تنضيف الماركداون — الموديل ساعات بيبعت ** رغم التعليمات
        $text = preg_replace('/\*\*(.+?)\*\*/s', '$1', $text);
        $text = preg_replace('/^#{1,4}\s*/m', '', $text);
        $text = str_replace('`', '', $text);

        $refused = str_starts_with(trim($text), self::REFUSAL_MARK);

        [$data, $link, $action] = $refused
            ? [null, null, null]
            : Presenter::present($lastTool, $lastResult);

        // كارت الأكشن من نتيجته المحفوظة — مش من آخر أداة
        if (! $refused && $actionResult !== null) {
            [, , $action] = Presenter::present('propose_collection', $actionResult);
        }

        return [
            'text' => trim($text) !== '' ? trim($text) : __('agent.empty_reply'),
            'data' => $data,
            'link' => $link,
            'action' => $action,
            'refused' => $refused,
            'domain' => $domain,
            'tools_called' => $toolsCalled,
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
        ];
    }

    /** @return array<string, mixed> */
    private function post(array $messages, User $user, array $context): array
    {
        $key = (string) config('agents.key');

        if ($key === '') {
            throw new AgentException(__('agent.err_not_configured'));
        }

        $resp = Http::withHeaders([
            'x-api-key' => $key,
            'anthropic-version' => '2023-06-01',
        ])
            ->timeout((int) config('agents.timeout'))
            ->post(self::API_URL, [
                'model' => (string) config('agents.model'),
                'max_tokens' => (int) config('agents.max_tokens'),
                'system' => $this->systemPrompt($user, $context),
                'tools' => Tools::specs($user),
                'messages' => $messages,
            ]);

        if (! $resp->successful()) {
            // نص خطأ الـAPI للّوج بس — المستخدم بياخد رسالة لطيفة
            logger()->warning('agent api error', [
                'status' => $resp->status(),
                'body' => mb_substr($resp->body(), 0, 300),
            ]);

            throw new AgentException(__('agent.err_api'));
        }

        return (array) $resp->json();
    }
}
