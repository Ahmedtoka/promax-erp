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
            'agent_name' => \App\Agents\AccountingAgent::NAME,
            'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
            'status' => AgentRun::STATUS_FAILED,
            'error' => Str::limit($error, 500),
        ]);
    }
}
