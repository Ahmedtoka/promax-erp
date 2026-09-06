<?php

namespace App\Agents;

use App\Agents\Tools\AccountingTools;
use App\Agents\Tools\ActionTools;
use App\Agents\Tools\FieldTools;
use App\Agents\Tools\InventoryTools;
use App\Agents\Tools\SalesTools;
use App\Models\User;

/**
 * ═══════════════════════════════════════════════════════════════
 * ريجستري أدوات مساعد بروماكس — كل الدومينات (٧/٩/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * التنفيذ في موديولات `app/Agents/Tools/` بالدومين:
 *   AccountingTools — حسابات · SalesTools — مبيعات ·
 *   InventoryTools — مخزون · FieldTools — ميداني ·
 *   ActionTools — أكشنات بموافقة (المرحلة التانية)
 *
 * ⚠️⚠️ بوابة الصلاحيات: كل أداة متحرسة بنفس مفتاح شاشتها في
 * `Access` — اللي ممنوع من الشاشة ممنوع من أداتها. من غير البوابة
 * دي الشات باب خلفي.
 *
 * ⚠️ كل أدوات القراءة مرايا شاشاتها بالحرف (عقيدة الأرقام)،
 * وأداة الأكشن بتجهّز بس — التنفيذ بموافقة صريحة.
 */
class Tools
{
    /** أداة → [بوابة الصلاحية (مفتاح شاشتها) ، الدومين] */
    private const MAP = [
        'find_client' => ['erp.clients.show', 'accounting'],
        'client_balance' => ['erp.clients.show', 'accounting'],
        'client_statement' => ['erp.clients.show', 'accounting'],
        'chain_summary' => ['erp.clients.show', 'accounting'],
        'debt_aging' => ['erp.overview', 'accounting'],

        'sales_summary' => ['erp.overview', 'sales'],
        'top_products' => ['erp.overview', 'sales'],

        'product_stock' => ['erp.stock', 'inventory'],
        'expiring_batches' => ['wh.expiry', 'inventory'],
        'van_stock' => ['ops.rep', 'inventory'],

        'find_rep' => ['ops.rep', 'field'],
        'attendance_today' => ['erp.attendance', 'field'],
        'rep_activity' => ['ops.rep', 'field'],

        'propose_collection' => ['ops.manual', 'action'],
    ];

    /** كل تعريفات الأدوات — بس اللي اليوزر ده مسموحله بيها */
    public static function specs(User $user): array
    {
        $all = array_merge(
            AccountingTools::specs(),
            SalesTools::specs(),
            InventoryTools::specs(),
            FieldTools::specs(),
            ActionTools::specs(),
        );

        // ⚠️ الأداة الممنوعة ماتتبعتش للموديل أصلاً — أنضف من إنه
        // يشوفها ويناديها وتترفض
        return array_values(array_filter($all, function ($spec) use ($user) {
            $gate = self::MAP[$spec['name']][0] ?? null;

            return $gate === null || \App\Support\Access::allows($user, $gate);
        }));
    }

    /** دومين الأداة — لنسب التشغيلة في `agent_runs.agent_name` */
    public static function domainOf(string $name): ?string
    {
        return self::MAP[$name][1] ?? null;
    }

    /**
     * تنفيذ أداة باسمها.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function call(string $name, array $args, User $user): array
    {
        $gate = self::MAP[$name][0] ?? null;

        if ($gate !== null && ! \App\Support\Access::allows($user, $gate)) {
            return ['not_available' => true,
                'note' => 'ده مش متاح ليك — بره صلاحياتك.'];
        }

        // ⚠️ باراميترز الموديل مش موثوقة — أي حاجة مش سكالر بتتداس
        $s = fn ($v) => is_scalar($v) ? trim((string) $v) : null;
        $d = fn ($v) => ($x = $s($v)) !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $x) ? $x : null;

        return match ($name) {
            // ═══ حسابات ═══
            'find_client' => AccountingTools::findClient((string) ($s($args['name'] ?? null) ?? ''), $user),
            'chain_summary' => AccountingTools::chainSummary((string) ($s($args['name'] ?? null) ?? ''), $user),
            'client_balance' => AccountingTools::clientBalance((int) ($s($args['client_id'] ?? null) ?? 0), $user),
            'client_statement' => AccountingTools::clientStatement(
                (int) ($s($args['client_id'] ?? null) ?? 0),
                $d($args['from'] ?? null), $d($args['to'] ?? null), $user,
            ),
            'debt_aging' => AccountingTools::debtAging($s($args['channel'] ?? null), $user),

            // ═══ مبيعات ═══
            'sales_summary' => SalesTools::salesSummary(
                $d($args['from'] ?? null), $d($args['to'] ?? null),
                ($v = $s($args['rep_id'] ?? null)) !== null ? (int) $v : null, $user,
            ),
            'top_products' => SalesTools::topProducts(
                $d($args['from'] ?? null), $d($args['to'] ?? null), $user,
            ),

            // ═══ مخزون ═══
            'product_stock' => InventoryTools::productStock((string) ($s($args['query'] ?? null) ?? ''), $user),
            'expiring_batches' => InventoryTools::expiringBatches(
                ($v = $s($args['days'] ?? null)) !== null ? (int) $v : null, $user,
            ),
            'van_stock' => InventoryTools::vanStock((int) ($s($args['rep_id'] ?? null) ?? 0), $user),

            // ═══ ميداني ═══
            'find_rep' => FieldTools::findRep((string) ($s($args['name'] ?? null) ?? ''), $user),
            'attendance_today' => FieldTools::attendanceToday($user),
            'rep_activity' => FieldTools::repActivity(
                (int) ($s($args['rep_id'] ?? null) ?? 0),
                $d($args['from'] ?? null), $d($args['to'] ?? null), $user,
            ),

            // ═══ أكشن بموافقة ═══
            'propose_collection' => ActionTools::proposeCollection(
                array_map(fn ($v) => is_scalar($v) ? $v : null, $args), $user,
            ),

            default => ['error' => 'unknown_tool'],
        };
    }
}
