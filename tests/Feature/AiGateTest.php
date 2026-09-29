<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Support\AiGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══ مساعد الذكاء الاصطناعي لجاد بس (قرار المالك ٢٩/٩/٢٠٢٦) ═══
 */
class AiGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_jad_sees_and_reaches_the_assistant(): void
    {
        $jad = $this->makeAdmin(['code' => 'ADM-001', 'name' => 'جاد']);
        $saad = $this->makeAdmin(['code' => 'ADM-002', 'name' => 'سعد']);

        $this->assertTrue(AiGate::allows($jad));
        $this->assertFalse(AiGate::allows($saad));

        // الفقاعة بتترسم لجاد بس
        $this->actingAs($jad)->get(route('erp.clients'))->assertOk()->assertSee('id="pmxAgentBtn"', false);
        $this->actingAs($saad)->get(route('erp.clients'))->assertOk()->assertDontSee('id="pmxAgentBtn"', false);

        // الراوت نفسه بيرفض غير جاد حتى بالنداء المباشر — والمحاولة بتتسجل
        $this->actingAs($saad)->postJson(route('agent.ask'), ['message' => 'اعمل تحصيل'])
            ->assertStatus(403)->assertJson(['message' => __('agent.not_allowed')]);
        $this->assertTrue(ActivityLog::where('user_id', $saad->id)->where('title', 'ai_blocked')->exists());

        // أكشن مقترح حقيقي باسم سعد (من قبل القفل) — التأكيد نفسه مرفوض ومفيش حاجة بتتنفذ
        $action = \App\Models\AgentAction::create(['user_id' => $saad->id, 'type' => 'collection',
            'payload' => ['amount' => 100], 'status' => 'pending']);
        $this->actingAs($saad)->postJson(route('agent.action.confirm', $action))->assertStatus(403);
        $this->assertSame('pending', $action->fresh()->status);

        // جاد بيعدّي البوابة (الرد نفسه على حسب قفل كلمة السر والـAPI — المهم مش 403)
        $this->assertNotSame(403, $this->actingAs($jad)->postJson(route('agent.ask'), ['message' => 'hi'])->status());
    }

    public function test_the_allowed_list_can_change_without_a_deploy(): void
    {
        $saad = $this->makeAdmin(['code' => 'ADM-002']);
        $this->assertFalse(AiGate::allows($saad));

        Setting::writeMany(['ai_allowed_codes' => 'ADM-001, ADM-002']);
        Setting::flushCache();

        $this->assertTrue(AiGate::allows($saad->fresh()));
        $this->assertFalse(AiGate::allows($saad->fresh()->forceFill(['active' => false])));
    }
}
