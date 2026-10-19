<?php

namespace App\Services\Gl;

use App\Models\Gl\CoaDraftAccount;
use App\Models\Gl\GlAccount;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * اعتماد شجرة المحاسب في دفتر الأستاذ (D2 / D3)
 * ═══════════════════════════════════════════════════════════════
 *
 * بيكتب **هيكل** `gl_accounts` بس — مفيش قيود ولا سطور (دي شغل `Ledger` لوحده).
 *
 * - كل صف مسودة ↔ حساب دفتر بـ`coa_draft_id`: موجود ⇒ يتحدّث، مش موجود ⇒ يتعمل.
 *   فالاعتماد التاني والتالت بيحدّث، مابيكررش.
 * - الحساب اللي مالوش أب في المسودة بيتعلّق تحت جذر نوعه المتولد (`1`…`5`) عشان
 *   الميزانية والتقارير ومنطق النوع تفضل شغالة. لو الجذر مش موجود ⇒ يبقى جذر.
 * - الورقة (مالهاش ولاد) بتترحّل، والتجميعي لأ.
 * - حساب نظام متولد (`is_system` ومن غير `coa_draft_id`) كوده نفس كود في المسودة ⇒
 *   يتنقل لـ`S-<code>` الأول (قبل التشغيل — مفيش تاريخ بيضيع؛ `system_key` بيفضل زي ما هو).
 * - حساب اتعتمد قبل كده وصفه اتمسح من المسودة ⇒ يتقفل (`active=false`) لو مالوش سطور، وإلا يفضل.
 *
 * ⚠️ ممنوع طول ما الفحص فيه أحمر (`CoaHasRed`).
 */
class CoaAdopter
{
    private const NAME_MAX = 120;

    private const CODE_MAX = 40;

    /**
     * @return array{created:int, updated:int, deactivated:int, recoded:list<string>}
     */
    public static function adopt(User $by): array
    {
        $red = CoaChecks::run()['red'];
        if ($red > 0) {
            throw new CoaHasRed($red);
        }

        return DB::transaction(function () use ($by) {
            $out = ['created' => 0, 'updated' => 0, 'deactivated' => 0, 'recoded' => []];

            $drafts = CoaDraftAccount::orderBy('sort')->orderBy('id')->get();
            $byId = $drafts->keyBy('id');
            $kids = $drafts->groupBy(fn ($d) => $d->parent_id && $byId->has($d->parent_id) ? (int) $d->parent_id : 0);

            // جذور الأنواع المتولدة — بالـid قبل أي ترقيم عشان ماتضيعش لو كودها اتنقل
            $roots = GlAccount::whereNull('parent_id')->whereNull('coa_draft_id')
                ->whereIn('code', array_map('strval', array_keys(GlAccount::ROOT_TYPES)))
                ->get()->mapWithKeys(fn ($g) => [GlAccount::ROOT_TYPES[$g->code] => $g->id]);

            // ═══ ترقيم حسابات النظام المتصادمة ═══
            $codes = $drafts->map(fn ($d) => trim((string) $d->code))->filter()->unique()->values();
            $clash = GlAccount::where('is_system', true)->whereNull('coa_draft_id')
                ->whereIn('code', $codes->all())->orderBy('code')->get();
            foreach ($clash as $g) {
                $old = $g->code;
                $g->update(['code' => self::freeCode('S-'.$old)]);
                $out['recoded'][] = $old;
            }

            // ═══ المشي من فوق لتحت — الأب بيتعمل قبل ولاده ═══
            $existing = GlAccount::whereNotNull('coa_draft_id')->get()->keyBy('coa_draft_id');
            $glOf = [];   // draft id ⇒ gl id
            $queue = [[0, null]];
            while ($queue !== []) {
                [$parentDraft, $parentGl] = array_shift($queue);
                foreach ($kids[$parentDraft] ?? [] as $d) {
                    $type = CoaChecks::typeFor($d, $byId);   // الأحمر `no_type` اتمنع فوق
                    $name = trim((string) $d->name);
                    $attrs = [
                        'code' => mb_substr(trim((string) $d->code), 0, self::CODE_MAX),
                        'name' => mb_substr(trim((string) $d->name_ar) ?: $name, 0, self::NAME_MAX),
                        'name_en' => mb_substr($name, 0, self::NAME_MAX),
                        'type' => $type,
                        'normal_side' => GlAccount::normalSideFor($type),
                        'parent_id' => $parentGl ?? ($roots[$type] ?? null),
                        'is_postable' => ! isset($kids[$d->id]),
                        'is_system' => false,
                        'active' => true,
                        'coa_draft_id' => $d->id,
                    ];
                    $g = $existing[$d->id] ?? null;
                    if ($g) {
                        $g->fill($attrs)->save();
                        $out['updated']++;
                    } else {
                        $g = GlAccount::create($attrs);
                        $out['created']++;
                    }
                    $glOf[$d->id] = $g->id;
                    $queue[] = [$d->id, $g->id];
                }
            }

            // ═══ حسابات صفها اتمسح من المسودة ═══
            $orphans = $existing->reject(fn ($g, $draftId) => isset($glOf[$draftId]))->where('active', true);
            if ($orphans->isNotEmpty()) {
                $withLines = DB::table('gl_lines')->whereIn('account_id', $orphans->pluck('id')->all())
                    ->distinct()->pluck('account_id')->flip();
                foreach ($orphans as $g) {
                    if (! isset($withLines[$g->id])) {
                        $g->update(['active' => false]);
                        $out['deactivated']++;
                    }
                }
            }

            Setting::writeMany([
                'gl_coa_adopted_at' => now()->format('Y-m-d H:i:s'),
                'gl_coa_adopted_by' => (string) $by->id,
            ]);

            return $out;
        });
    }

    public static function adopted(): bool
    {
        return Setting::read('gl_coa_adopted_at') !== null;
    }

    /** `S-1101`، ولو مستخدم `S-1101.2`… */
    private static function freeCode(string $code): string
    {
        $code = mb_substr($code, 0, self::CODE_MAX);
        $try = $code;
        $n = 1;
        while (GlAccount::where('code', $try)->exists()) {
            $try = mb_substr($code, 0, self::CODE_MAX - 4).'.'.(++$n);
        }

        return $try;
    }
}
