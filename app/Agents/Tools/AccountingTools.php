<?php

namespace App\Agents\Tools;

use App\Models\Channel;
use App\Models\Client;
use App\Models\User;

/**
 * ═══════════════════════════════════════════════════════════════
 * أدوات الحسابات — دومين «حسابات» في مساعد بروماكس (قراءة فقط)
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ عقيدة الأرقام: كل أداة مرآة شاشتها بالحرف —
 *   كشف الحساب  = علاقة `$client->transactions()` (كارت العميل)
 *   الرصيد      = أعمدة `clients` المجمّعة من `recalculate()`
 *   السلسلة     = نفس كويريات `GroupController::show`
 *   أعمار الديون = `Client::aging()` بتجميع `ErpController::agingTotals`
 */
class AccountingTools
{
    use Shared;

    /** أقصى صفوف كشف الحساب في رد واحد — نفس صفحة الشاشة تقريباً */
    private const STATEMENT_ROWS = 60;

    /** أقصى مرشحين في البحث بالاسم */
    private const FIND_LIMIT = 8;

    public static function specs(): array
    {
        return [
            [
                'name' => 'find_client',
                'description' => 'البحث عن عميل بالاسم (بحث متسامح مع الأخطاء الإملائية، عربي أو إنجليزي أو اسم السلسلة). بيرجع مرشحين — لو أكتر من واحد اسأل المستخدم يختار، ولو واحد كمّل بيه.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'اسم العميل أو جزء منه'],
                    ],
                    'required' => ['name'],
                ],
            ],
            [
                'name' => 'chain_summary',
                'description' => 'ملخص سلسلة كاملة (كل فروعها): إجمالي الرصيد والمشتريات والتحصيل والمرتجعات ومبيعات النهارده + رصيد كل فرع. استخدمها لما المستخدم يسأل عن سلسلة أو مجموعة كلها مش فرع واحد.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'اسم السلسلة أو جزء منه'],
                    ],
                    'required' => ['name'],
                ],
            ],
            [
                'name' => 'client_balance',
                'description' => 'رصيد عميل وملخص حسابه (المشتريات والتحصيل والمرتجعات والرصيد الحالي) — نفس أرقام كارت العميل.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'client_id' => ['type' => 'integer', 'description' => 'رقم العميل في السيستم'],
                    ],
                    'required' => ['client_id'],
                ],
            ],
            [
                'name' => 'client_statement',
                'description' => 'كشف حساب عميل: القيود (مدين/دائن) من الأحدث للأقدم، مع فلترة اختيارية بفترة. نفس كشف الحساب اللي في كارت العميل.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'client_id' => ['type' => 'integer', 'description' => 'رقم العميل في السيستم'],
                        'from' => ['type' => 'string', 'description' => 'من تاريخ YYYY-MM-DD (اختياري)'],
                        'to' => ['type' => 'string', 'description' => 'إلى تاريخ YYYY-MM-DD (اختياري)'],
                    ],
                    'required' => ['client_id'],
                ],
            ],
            [
                'name' => 'debt_aging',
                'description' => 'أعمار المديونية الإجمالية (≤30 / 31-60 / 61-90 / 91-180 / +180 يوم) لكل العملاء اللي عليهم رصيد، مع فلترة اختيارية بقناة (كي أكاونت / أونلاين / كاش فان / جملة). نفس أرقام الداشبورد.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'channel' => ['type' => 'string', 'description' => 'اسم أو كود القناة (اختياري)'],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }

    // ═══════════════════════ التنفيذ ═══════════════════════

    public static function findClient(string $name, User $user): array
    {
        if (mb_strlen(trim($name)) < 2) {
            return ['error' => 'اكتب حرفين على الأقل من الاسم.'];
        }

        // نفس بحث شاشة العملاء: Client::search الموحّد المتسامح
        // + سكوب الفرع والمدير زي الليستة بالظبط
        $q = Client::visibleTo(\App\Models\Branch::scope(
            Client::query()->with(['group', 'channel']), $user,
        ), $user);

        $rows = Client::search($q, $name)
            ->orderBy('name')->limit(self::FIND_LIMIT + 1)->get();

        $more = $rows->count() > self::FIND_LIMIT;

        return [
            'candidates' => $rows->take(self::FIND_LIMIT)->map(fn (Client $c) => [
                'client_id' => $c->id,
                'name' => $c->fullName(),
                'code' => $c->code,
                'channel' => $c->channel?->displayName(),
                // اسم السلسلة — عشان الموديل يعرف إن دول فروع سلسلة
                // واحدة ويقترح chain_summary بدل ما يقف عند المرشحين
                'chain' => $c->group?->displayName(),
                'balance' => round((float) $c->balance, 2),
            ])->values()->all(),
            'more_exist' => $more,
        ];
    }

    /**
     * ملخص سلسلة كاملة — ⚠️ مرآة `GroupController::show` بالحرف:
     * نفس `Client::visibleTo($group->clients(), $user)` ونفس ترتيب
     * المشتريات، والإجماليات مجاميع نفس أعمدة `clients` المجمّعة
     * ومبيعات النهارده نفس كويري الشاشة.
     */
    public static function chainSummary(string $name, User $user): array
    {
        if (mb_strlen(trim($name)) < 2) {
            return ['error' => 'اكتب حرفين على الأقل من اسم السلسلة.'];
        }

        // نفس تطبيع بحث العملاء — همزات وتاء مربوطة وياء موحّدين
        $norm = Client::normalizeArabic(trim($name));

        $groups = \App\Models\ClientGroup::where('active', true)
            ->where(function ($w) use ($norm, $name) {
                $w->whereRaw(Client::normSql('name').' like ?', ["%{$norm}%"])
                    ->orWhere('name_en', 'like', '%'.trim($name).'%');
            })
            ->orderBy('name')->limit(6)->get();

        if ($groups->isEmpty()) {
            return ['error' => 'مفيش سلسلة بالاسم ده.'];
        }

        if ($groups->count() > 1) {
            return [
                'multiple_chains' => $groups->map(fn ($g) => [
                    'chain' => $g->displayName(),
                ])->values()->all(),
                'note' => 'فيه أكتر من سلسلة مطابقة — اسأل المستخدم يحدد.',
            ];
        }

        $group = $groups->first();

        // نفس صفحة السلسلة: الفروع الظاهرة لليوزر ده بس
        $branches = Client::visibleTo($group->clients(), $user)
            ->orderByDesc('purchases')->get();

        $ids = $branches->pluck('id');

        return [
            'chain_id' => $group->id,
            'chain' => $group->displayName(),
            'branches_count' => $branches->count(),
            'totals' => [
                'balance' => round((float) $branches->sum('balance'), 2),
                'purchases' => round((float) $branches->sum('purchases'), 2),
                'collections' => round((float) $branches->sum('collections'), 2),
                'returns' => round((float) $branches->sum('returns'), 2),
                // نفس كويري «مبيعات النهارده» بتاع صفحة السلسلة بالحرف
                'today_sales' => round((float) \App\Models\Invoice::whereIn('client_id', $ids)
                    ->whereDate('created_at', today())->sum('total'), 2),
            ],
            'branches' => $branches->map(fn (Client $c) => [
                'client_id' => $c->id,
                'name' => $c->fullName(),
                'code' => $c->code,
                'balance' => round((float) $c->balance, 2),
            ])->values()->all(),
        ];
    }

    public static function clientBalance(int $clientId, User $user): array
    {
        $client = self::guardedClient($clientId, $user);

        if ($client === null) {
            return self::notAvailable();
        }

        // كل الأرقام من أعمدة `clients` المجمّعة — نفس كارت العميل،
        // مصدرها الوحيد `recalculate()` من `transactions`
        return [
            'client_id' => $client->id,
            'name' => $client->fullName(),
            'code' => $client->code,
            'channel' => $client->channel?->displayName(),
            'balance' => round((float) $client->balance, 2),
            'purchases' => round((float) $client->purchases, 2),
            'collections' => round((float) $client->collections, 2),
            'returns' => round((float) $client->returns, 2),
            'balance_note' => (float) $client->balance < 0
                ? 'الرصيد سالب = العميل له فلوس عندنا (دائن)'
                : 'الرصيد الموجب = مستحق علينا تحصيله من العميل',
        ];
    }

    public static function clientStatement(int $clientId, ?string $from, ?string $to, User $user): array
    {
        $client = self::guardedClient($clientId, $user);

        if ($client === null) {
            return self::notAvailable();
        }

        // نفس كويري كشف الحساب في كارت العميل بالحرف:
        // العلاقة + reorder + الأحدث الأول — مع فلتر الفترة بس
        $q = $client->transactions()->reorder()
            ->when($from, fn ($w) => $w->whereDate('date', '>=', $from))
            ->when($to, fn ($w) => $w->whereDate('date', '<=', $to))
            ->orderByDesc('date')->orderByDesc('id');

        $total = (clone $q)->count();
        $rows = $q->limit(self::STATEMENT_ROWS)->get();

        return [
            'client_id' => $client->id,
            'name' => $client->fullName(),
            'balance_now' => round((float) $client->balance, 2),
            'from' => $from,
            'to' => $to,
            'rows_shown' => $rows->count(),
            'rows_total' => $total,
            // مجاميع نفس الصفوف المعروضة — مش حساب جديد
            'sum_debit' => round((float) $rows->sum('debit'), 2),
            'sum_credit' => round((float) $rows->sum('credit'), 2),
            'rows' => $rows->map(fn ($t) => [
                'date' => $t->date?->format('Y-m-d'),
                'memo' => $t->memo,
                'kind' => $t->kindLabel(),
                'debit' => round((float) $t->debit, 2),
                'credit' => round((float) $t->credit, 2),
                'method' => $t->methodLabel(),
            ])->values()->all(),
        ];
    }

    public static function debtAging(?string $channel, User $user): array
    {
        // ⚠️ مرآة `ErpController::agingTotals()` + سكوب الفرع كمان —
        // مدير الفرع بياخد أعمار فرعه مش الشركة
        $channelRow = null;

        if ($channel !== null && trim($channel) !== '') {
            $s = trim($channel);
            // مطابقة دقيقة الأول (كود أو اسم) وبعدها like — والنشطة بس
            $channelRow = Channel::where('active', true)
                ->where(fn ($w) => $w->where('code', $s)
                    ->orWhere('name', $s)->orWhere('name_en', $s))
                ->first()
                ?? (mb_strlen($s) >= 3
                    ? Channel::where('active', true)
                        ->where(fn ($w) => $w->where('name', 'like', "%$s%")
                            ->orWhere('name_en', 'like', "%$s%"))
                        ->first()
                    : null);

            if ($channelRow === null) {
                return ['error' => 'مفيش قناة بالاسم ده — القنوات: كي أكاونت / أونلاين / كاش فان / جملة.'];
            }
        }

        $t = ['a30' => 0.0, 'a60' => 0.0, 'a90' => 0.0, 'a180' => 0.0, 'a180p' => 0.0];
        $clients = 0;

        Client::visibleTo(\App\Models\Branch::scope(
            Client::where('balance', '>', 0), $user,
        ), $user)
            ->when($channelRow, fn ($q) => $q->where('channel_id', $channelRow->id))
            ->with(['transactions' => fn ($q) => $q->where('debit', '>', 0)])
            ->chunk(200, function ($chunk) use (&$t, &$clients) {
                foreach ($chunk as $client) {
                    $clients++;
                    foreach ($client->aging() as $k => $v) {
                        $t[$k] += $v;
                    }
                }
            });

        return [
            'channel' => $channelRow?->displayName(),
            'clients_with_debt' => $clients,
            'buckets' => [
                '<=30' => round($t['a30'], 2),
                '31-60' => round($t['a60'], 2),
                '61-90' => round($t['a90'], 2),
                '91-180' => round($t['a180'], 2),
                '180+' => round($t['a180p'], 2),
            ],
            'total' => round(array_sum($t), 2),
        ];
    }
}
