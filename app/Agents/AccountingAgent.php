<?php

namespace App\Agents;

use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * ═══════════════════════════════════════════════════════════════
 * إيجنت الحسابات — مساعد بروماكس (المرحلة الأولى ٧/٩/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * بيكلّم Anthropic Messages API بـtool use على أدوات القراءة في
 * `Tools`. كل الأرقام من الأدوات — الـsystem prompt بيمنع التخمين،
 * وبلوك البيانات اللي بيتعرض في الشات بيتبني هنا في السيرفر من
 * نتيجة الأداة نفسها (مش من كلام الموديل) عشان رقم الشات = رقم
 * الشاشة بالقرش.
 *
 * ⚠️ المفتاح من config('agents.key') — عمره ما يوصل للفرونت.
 */
class AccountingAgent
{
    public const NAME = 'accounting';

    /** علامة الرفض — الموديل بيبدأ بيها لما الطلب بره نطاقه */
    public const REFUSAL_MARK = '⛔';

    private const API_URL = 'https://api.anthropic.com/v1/messages';

    /**
     * @param  array<int, array{user: string, assistant: string}>  $history
     * @param  array<string, mixed>  $context  سياق الشاشة الحالية
     * @return array{text: string, data: ?array, link: ?array, refused: bool,
     *               tools_called: array, tokens_in: int, tokens_out: int}
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

                if (! isset($out['error'])) {
                    $lastTool = $block['name'];
                    $lastResult = $out;
                }

                $results[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $block['id'],
                    'content' => json_encode($out, JSON_UNESCAPED_UNICODE),
                ];
            }

            $messages[] = ['role' => 'user', 'content' => $results];
        }

        // خلصت اللفّات والموديل لسه عايز أدوات؟ — مفيش رد نص نص:
        // لا نص ولا جدول، عشان مايتعرضش جدول تحت «معنديش إجابة»
        if (! $finished) {
            throw new AgentException(__('agent.err_generic'));
        }

        $refused = str_starts_with(trim($text), self::REFUSAL_MARK);

        [$data, $link] = $refused
            ? [null, null]
            : $this->presentation($lastTool, $lastResult);

        return [
            'text' => trim($text) !== '' ? trim($text) : __('agent.empty_reply'),
            'data' => $data,
            'link' => $link,
            'refused' => $refused,
            'tools_called' => $toolsCalled,
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
        ];
    }

    // ═══════════════════════ نداء الـAPI ═══════════════════════

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
                'tools' => Tools::specs(),
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

    private function systemPrompt(User $user, array $context): string
    {
        $screen = '';

        if (($context['client_id'] ?? null) !== null) {
            $screen = "\n- المستخدم واقف دلوقتي على شاشة العميل رقم {$context['client_id']}"
                .(isset($context['client_name']) ? " ({$context['client_name']})" : '')
                .' — لو سأل عن «العميل ده» أو من غير ما يحدد عميل، استخدم الرقم ده على طول.';
        }

        return <<<PROMPT
انت «مساعد بروماكس» — إيجنت الحسابات في سيستم PROMAX لتوزيع المواد الغذائية.
بتتكلم مصري مهني مختصر، والأرقام بالجنيه المصري (ج).

نطاقك في المرحلة دي (قراءة فقط):
- كشف حساب عميل (client_statement)
- رصيد عميل وملخص حسابه (client_balance)
- أعمار المديونية إجمالاً أو لقناة (debt_aging)
- البحث عن عميل بالاسم (find_client)

قواعد صارمة:
1. ⚠️ ممنوع تخمّن أو تأّلف أي رقم — أي رقم في ردك لازم يكون جاي حرفياً من نتيجة أداة. لو الأداة مرجعتش الرقم، قول إنك مش قادر تجيبه.
2. لو المستخدم ذكر اسم عميل مش رقم، استخدم find_client الأول. لو رجعت أكتر من مرشح، اعرضهم واسأله يختار مين فيهم — متفترضش.
3. لو الأداة رجعت not_available، قول للمستخدم إن العميل ده غير متاح ليه — من غير تفاصيل زيادة.
4. أي طلب بره النطاق ده (مخزون، مبيعات، مناديب، تعديل بيانات، أي كتابة...) ابدأ ردك بالعلامة ⛔ وبعدها: «ده مش في نطاقي حالياً — أقدر أساعدك في كشف حساب عميل، رصيده، أو أعمار الديون.»
5. ردك نص عربي مختصر بيلخص النتيجة — الجدول التفصيلي بيتعرض تحت ردك أوتوماتيك، فمتكررش كل الصفوف في الكلام. اذكر الخلاصة والأرقام المهمة بس.
6. التواريخ بصيغة YYYY-MM-DD. النهارده: {$this->today()}.

سياق:
- المستخدم: {$user->name} (دوره: {$user->role}){$screen}
PROMPT;
    }

    private function today(): string
    {
        return now()->toDateString();
    }

    // ═══════════════ بلوك البيانات — من نتيجة الأداة مش من الموديل ═══════════════

    /**
     * @return array{0: ?array, 1: ?array}  [data, link]
     */
    private function presentation(?string $tool, ?array $r): array
    {
        if ($tool === null || $r === null || isset($r['not_available'])) {
            return [null, null];
        }

        return match ($tool) {
            'client_statement' => [
                [
                    'type' => 'table',
                    'title' => __('agent.d_statement', ['name' => $r['name']]),
                    'columns' => [__('common.date'), __('agent.c_memo'), __('agent.c_kind'),
                        __('agent.c_debit'), __('agent.c_credit')],
                    'rows' => collect($r['rows'])->map(fn ($t) => [
                        $t['date'], $t['memo'], $t['kind'],
                        $t['debit'] ? number_format($t['debit'], 2) : '—',
                        $t['credit'] ? number_format($t['credit'], 2) : '—',
                    ])->all(),
                    'footer' => __('agent.d_stmt_footer', [
                        'shown' => $r['rows_shown'], 'total' => $r['rows_total'],
                        'debit' => number_format($r['sum_debit'], 2),
                        'credit' => number_format($r['sum_credit'], 2),
                    ]),
                ],
                $this->clientLink($r['client_id']),
            ],
            'client_balance' => [
                [
                    'type' => 'card',
                    'title' => $r['name'],
                    'rows' => [
                        [__('agent.c_balance'), __('agent.money', ['n' => number_format($r['balance'], 2)])],
                        [__('agent.c_purchases'), __('agent.money', ['n' => number_format($r['purchases'], 2)])],
                        [__('agent.c_collections'), __('agent.money', ['n' => number_format($r['collections'], 2)])],
                        [__('agent.c_returns'), __('agent.money', ['n' => number_format($r['returns'], 2)])],
                    ],
                ],
                $this->clientLink($r['client_id']),
            ],
            'debt_aging' => [
                [
                    'type' => 'table',
                    'title' => $r['channel']
                        ? __('agent.d_aging_ch', ['channel' => $r['channel']])
                        : __('agent.d_aging'),
                    'columns' => [__('report.days_0_30'), __('report.days_31_60'),
                        __('report.days_61_90'), __('report.days_91_180'), __('report.days_180_plus')],
                    'rows' => [array_map(
                        fn ($v) => number_format($v, 2),
                        array_values($r['buckets']),
                    )],
                    'footer' => __('agent.d_aging_footer', [
                        'total' => number_format($r['total'], 2),
                        'clients' => $r['clients_with_debt'],
                    ]),
                ],
                ['label' => __('agent.open_dashboard'), 'url' => route('erp.overview')],
            ],
            'find_client' => [
                count($r['candidates'] ?? []) > 1 ? [
                    'type' => 'table',
                    'title' => __('agent.d_candidates'),
                    'columns' => ['#', __('client.client'), __('common.code'), __('agent.c_balance')],
                    'rows' => collect($r['candidates'])->map(fn ($c) => [
                        $c['client_id'], $c['name'], $c['code'],
                        number_format($c['balance'], 2),
                    ])->all(),
                    'footer' => null,
                ] : null,
                count($r['candidates'] ?? []) === 1
                    ? $this->clientLink($r['candidates'][0]['client_id'])
                    : null,
            ],
            default => [null, null],
        };
    }

    private function clientLink(int $clientId): array
    {
        return [
            'label' => __('agent.open_client'),
            'url' => route('erp.clients.show', $clientId),
        ];
    }
}
