<?php

namespace App\Agents;

use App\Models\Channel;
use App\Models\Client;
use App\Models\User;

/**
 * ═══════════════════════════════════════════════════════════════
 * أدوات مساعد بروماكس — قراءة فقط (المرحلة الأولى ٧/٩/٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️⚠️ عقيدة الأرقام: كل أداة بتقرا من نفس المصدر اللي الشاشة
 * الأصلية بتقرا منه بالحرف — صفر SQL خام وصفر حسابات جديدة:
 *   - كشف الحساب  = علاقة `$client->transactions()` (نفس كارت العميل)
 *   - الرصيد      = أعمدة `clients` المجمّعة من `recalculate()`
 *   - أعمار الديون = `Client::aging()` بتجميع `ErpController::agingTotals` بالحرف
 *
 * ⚠️ السكوب: كل أداة بتمشي من نفس حراس الشاشات — `canSeeBranch` +
 * `visibleBy` للعميل الواحد، و`Client::visibleTo` + `Branch::scope`
 * للقوايم. العميل بره النطاق = «غير متاح» مش الرقم.
 *
 * ⚠️ قراءة فقط — ممنوع أي أداة تكتب أو تعدّل.
 */
class Tools
{
    /** أقصى صفوف كشف الحساب في رد واحد — نفس صفحة الشاشة تقريباً */
    private const STATEMENT_ROWS = 60;

    /** أقصى مرشحين في البحث بالاسم */
    private const FIND_LIMIT = 8;

    /**
     * تعريفات الأدوات بصيغة Anthropic tool use.
     *
     * @return array<int, array<string, mixed>>
     */
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
                        'channel' => ['type' => 'string', 'description' => 'اسم أو كود القناة (اختياري) — مثلاً cash_van أو كاش فان'],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }

    /**
     * تنفيذ أداة باسمها — بترجع مصفوفة بتتحول JSON للموديل.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function call(string $name, array $args, User $user): array
    {
        // ⚠️⚠️ بوابة الصلاحيات (مراجعة ٧/٩): الشات متاح لكل مسجّل
        // دخول، بس كل أداة متحرسة بنفس مفتاح شاشتها في `Access` —
        // أمين المخزن ممنوع من كارت العميل، يبقى ممنوع من أدواته
        // هنا برضو. من غير البوابة دي الشات كان باب خلفي للليدجر.
        $gate = match ($name) {
            'find_client', 'client_balance', 'client_statement' => 'erp.clients.show',
            'debt_aging' => 'erp.overview',
            default => null,
        };

        if ($gate !== null && ! \App\Support\Access::allows($user, $gate)) {
            return self::notAvailable();
        }

        // ⚠️ باراميترز الموديل مش موثوقة — أي حاجة مش سكالر بتتداس
        $scalar = fn ($v) => is_scalar($v) ? trim((string) $v) : null;

        return match ($name) {
            'find_client' => self::findClient((string) ($scalar($args['name'] ?? null) ?? ''), $user),
            'client_balance' => self::clientBalance((int) ($scalar($args['client_id'] ?? null) ?? 0), $user),
            'client_statement' => self::clientStatement(
                (int) ($scalar($args['client_id'] ?? null) ?? 0),
                self::validDate($scalar($args['from'] ?? null)),
                self::validDate($scalar($args['to'] ?? null)),
                $user,
            ),
            'debt_aging' => self::debtAging($scalar($args['channel'] ?? null), $user),
            default => ['error' => 'unknown_tool'],
        };
    }

    /** تاريخ صالح YYYY-MM-DD وإلا null — «last month» وأشباهها بتتداس */
    private static function validDate(?string $v): ?string
    {
        return ($v !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : null;
    }

    // ═══════════════════════ الحراس ═══════════════════════

    /**
     * العميل جوه نطاق اليوزر؟ — نفس حارسي كارت العميل بالحرف
     * (`ErpController::client`): canSeeBranch + visibleBy.
     */
    private static function guardedClient(int $clientId, User $user): ?Client
    {
        $client = Client::with(['group', 'channel'])->find($clientId);

        if ($client === null
            || ! $user->canSeeBranch($client->branch_id)
            || ! $client->visibleBy($user)) {
            return null;
        }

        return $client;
    }

    /** رد موحّد للعميل الغايب أو اللي بره النطاق — من غير ما نفرّق */
    private static function notAvailable(): array
    {
        return ['not_available' => true,
            'note' => 'العميل ده مش متاح ليك — يا إما مش موجود يا إما بره نطاقك.'];
    }

    // ═══════════════════════ الأدوات ═══════════════════════

    private static function findClient(string $name, User $user): array
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
                'balance' => round((float) $c->balance, 2),
            ])->values()->all(),
            'more_exist' => $more,
        ];
    }

    private static function clientBalance(int $clientId, User $user): array
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

    private static function clientStatement(int $clientId, ?string $from, ?string $to, User $user): array
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

    private static function debtAging(?string $channel, User $user): array
    {
        // ⚠️ مرآة `ErpController::agingTotals()` بالحرف — نفس السكوب
        // (visibleTo) ونفس التحميل المسبق ونفس `Client::aging()`،
        // مضاف عليها فلتر القناة بس (فلتر مش حساب جديد)
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

        // ⚠️ مرآة `agingTotals` + سكوب الفرع كمان — مدير الفرع بياخد
        // أعمار فرعه مش الشركة (الشاشة نفسها سايبة الثغرة دي —
        // متسجلة في التسليم كملاحظة عليها)
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
