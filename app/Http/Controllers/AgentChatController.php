<?php

namespace App\Http\Controllers;

use App\Agents\AgentException;
use App\Agents\Router;
use App\Models\AgentConversation;
use App\Models\AgentRun;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;

/**
 * ═══════════════════════════════════════════════════════════════
 * مساعد بروماكس — نقطة دخول الشات (المرحلة الأولى ٧/٩/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * POST /agent/ask (auth) — بياخد الرسالة وسياق الشاشة الحالية،
 * بيوجّه للراوتر، وبيسجّل كل run في `agent_runs` بالتوكنز والوقت
 * والأدوات والحالة. قراءة فقط — مفيش أي كتابة على بيانات البيزنس.
 */
class AgentChatController extends Controller
{
    public function ask(Request $request)
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'integer'],
            'current_route' => ['nullable', 'string', 'max:120'],
            'route_params' => ['nullable', 'array'],
        ]);

        $user = $request->user();

        // ⚠️ المحادثة لازم تبقى بتاعة نفس اليوزر — id متلاعب فيه
        // بيتجاهل وبيتفتح محادثة جديدة، مش 403 يقطع الشات
        $conv = null;

        if (($data['conversation_id'] ?? null) !== null) {
            $conv = AgentConversation::where('user_id', $user->id)
                ->find($data['conversation_id']);
        }

        $conv ??= AgentConversation::create([
            'user_id' => $user->id,
            'title' => Str::limit(trim($data['message']), 60, '…'),
        ]);

        // ═══ السياق من المحادثة — آخر كام سؤال وجواب نصي ═══
        $history = $conv->runs()
            ->where('status', '!=', AgentRun::STATUS_FAILED)
            ->latest('id')->limit((int) config('agents.history_runs'))
            ->get()->reverse()
            ->map(fn (AgentRun $r) => [
                'user' => $r->user_message,
                'assistant' => (string) ($r->response['text'] ?? ''),
            ])
            ->filter(fn ($h) => $h['assistant'] !== '')
            ->values()->all();

        // ═══ سياق الشاشة: واقف على كارت عميل؟ الـid بيتعبّى لوحده ═══
        // ⚠️ بنفس حراس الشاشة — مايتبعتش للإيجنت غير عميل اليوزر
        // أصلاً شايفه، عشان السياق نفسه مايبقاش تسريب
        $context = [];

        if (($data['current_route'] ?? '') === 'erp.clients.show') {
            $cid = (int) ($data['route_params']['client'] ?? 0);
            $client = $cid > 0 ? Client::find($cid) : null;

            if ($client !== null
                && $user->canSeeBranch($client->branch_id)
                && $client->visibleBy($user)) {
                $context = [
                    'client_id' => $client->id,
                    'client_name' => $client->fullName(),
                ];
            }
        }

        $t0 = microtime(true);

        try {
            ['agent' => $agentName, 'result' => $res] =
                (new Router())->dispatch(trim($data['message']), $history, $context, $user);

            $run = AgentRun::create([
                'conversation_id' => $conv->id,
                'user_message' => trim($data['message']),
                'agent_name' => $agentName,
                'tools_called' => $res['tools_called'],
                'response' => [
                    'text' => $res['text'],
                    'data' => $res['data'],
                    'link' => $res['link'],
                    'action' => $res['action'] ?? null,
                ],
                'tokens_in' => $res['tokens_in'],
                'tokens_out' => $res['tokens_out'],
                'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
                'status' => $res['refused'] ? AgentRun::STATUS_REFUSED : AgentRun::STATUS_OK,
            ]);

            return response()->json([
                'conversation_id' => $conv->id,
                'run_id' => $run->id,
                // علامة الرفض بتتشال من العرض — الجملة نفسها كفاية
                'text' => ltrim($res['text'], "⛔ \n"),
                'data' => $res['data'],
                'link' => $res['link'],
                'action' => $res['action'] ?? null,
            ]);
        } catch (AgentException $e) {
            $this->logFailure($conv->id, $data['message'], $t0, $e->getMessage());

            // ⚠️ مفتاح الخطأ `message` — نفس عقد الأخطاء في السيستم
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ConnectionException) {
            $this->logFailure($conv->id, $data['message'], $t0, 'timeout');

            return response()->json(['message' => __('agent.err_timeout')], 422);
        } catch (\Throwable $e) {
            report($e);
            $this->logFailure($conv->id, $data['message'], $t0, get_class($e).': '.$e->getMessage());

            return response()->json(['message' => __('agent.err_generic')], 422);
        }
    }

    private function logFailure(int $convId, string $message, float $t0, string $error): void
    {
        AgentRun::create([
            'conversation_id' => $convId,
            'user_message' => trim($message),
            'agent_name' => \App\Agents\PromaxAgent::NAME,
            'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
            'status' => AgentRun::STATUS_FAILED,
            'error' => Str::limit($error, 500),
        ]);
    }

    // ═══════════════ الأكشنات بموافقة (المرحلة التانية ٧/٩) ═══════════════

    /**
     * POST /agent/actions/{action}/confirm — تنفيذ أكشن مقترح.
     *
     * ⚠️ التنفيذ بنفس مسار كود الشاشة الأصلية: التحصيل بيمر
     * بـ`ManualCollection` — نفس سيرفس المستند اليدوي بالحرف —
     * وبنفس حراس `Scope` اللي الشاشة بتستخدمهم.
     */
    public function confirmAction(Request $request, \App\Models\AgentAction $action)
    {
        $user = $request->user();

        // ⚠️ الأكشن لصاحبه بس
        abort_unless((int) $action->user_id === (int) $user->id, 403);

        // نفس بوابة المستند اليدوي — اللي ممنوع من الشاشة ممنوع هنا
        abort_unless(\App\Support\Access::allows($user, 'ops.manual'), 403);

        // ⚠️⚠️ حجز ذرّي (مراجعة ٧/٩): دبل كليك أو ريتراي متزامنين
        // كانوا بيعدوا فحص الحالة الاتنين ويسجلوا قيدين حقيقيين.
        // UPDATE مشروط واحد — اللي يكسبه ينفذ واللي يخسره بياخد 422
        $claimed = \App\Models\AgentAction::whereKey($action->id)
            ->where('status', \App\Models\AgentAction::STATUS_PENDING)
            ->update(['status' => \App\Models\AgentAction::STATUS_RUNNING]);

        if ($claimed === 0) {
            return response()->json(['message' => __('agent.act_already')], 422);
        }

        try {
            $result = match ($action->type) {
                \App\Models\AgentAction::TYPE_COLLECTION => $this->executeCollection($user, $action),
                default => throw new \RuntimeException('unknown action type'),
            };

            $action->update([
                'status' => \App\Models\AgentAction::STATUS_CONFIRMED,
                'result' => $result,
                'confirmed_at' => now(),
            ]);

            return response()->json(['message' => $result['message']]);
        } catch (\Throwable $e) {
            report($e);
            $action->update([
                'status' => \App\Models\AgentAction::STATUS_FAILED,
                'error' => Str::limit(get_class($e).': '.$e->getMessage(), 500),
            ]);

            return response()->json(['message' => __('agent.act_failed')], 422);
        }
    }

    /** POST /agent/actions/{action}/cancel — إلغاء اقتراح */
    public function cancelAction(Request $request, \App\Models\AgentAction $action)
    {
        abort_unless((int) $action->user_id === (int) $request->user()->id, 403);

        if ($action->status === \App\Models\AgentAction::STATUS_PENDING) {
            $action->update(['status' => \App\Models\AgentAction::STATUS_CANCELLED]);
        }

        return response()->json(['message' => __('agent.act_cancelled')]);
    }

    /** @return array{message: string, transaction_id: int} */
    private function executeCollection(\App\Models\User $user, \App\Models\AgentAction $action): array
    {
        $p = $action->payload;

        $rep = \App\Models\User::findOrFail($p['rep_id']);
        $client = Client::findOrFail($p['client_id']);

        // ⚠️ نفس حراس المستند اليدوي (`anchors`) بالحرف — بيترمّوا
        // تاني وقت التنفيذ لأن النطاق ممكن يكون اتغير بعد الاقتراح
        \App\Support\Scope::assertRep($user, $rep);
        \App\Support\Scope::assertClient($user, $client);

        $date = \Illuminate\Support\Carbon::parse($p['date'])->setTime(12, 0);

        $tx = \App\Services\ManualCollection::record(
            actor: $user,
            rep: $rep,
            client: $client,
            date: $date,
            amount: (float) $p['amount'],
            method: $p['method'],
            reference: $p['reference'] ?? null,
            chequeBank: $p['cheque_bank'] ?? null,
            chequeDue: $p['cheque_due'] ?? null,
            note: $p['note'] ?? null,
        );

        return [
            'transaction_id' => $tx->id,
            'message' => __('flash.md_collect_done', [
                'amount' => number_format((float) $p['amount'], 2),
                'client' => $client->displayName(),
            ]),
        ];
    }
}
