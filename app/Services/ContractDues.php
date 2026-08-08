<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractClause;
use App\Models\ContractDue;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * حساب مستحقات العقود — المكان الوحيد اللي بيولّد استحقاق
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ الأساس هو **مشتريات العميل الفعلية** في الفترة، مقروءة من
 * `transactions` (kind='sale')، مش تقدير ولا نسبة من رصيد.
 * ده نفس مصدر الحقيقة اللي كل أرقام الفلوس في السيستم بترجع له.
 *
 * ⚠️ التوليد idempotent: فيه unique على (بند، عميل، بداية الفترة)
 * فتشغيل الأمر عشر مرات بيدي نفس النتيجة. ولو الاستحقاق **اتقيّد**
 * خلاص، بنسيبه زي ما هو ومابنلمسوش — القيد اتعمل ومايتغيرش.
 */
class ContractDues
{
    /** البنود اللي بتولّد استحقاق دوري */
    private const PERIODIC = ['monthly', 'quarterly', 'annual'];

    /**
     * توليد الاستحقاقات لكل العقود السارية لحد تاريخ معيّن.
     *
     * @return array{created: int, skipped: int, contracts: int}
     */
    public static function generate(?Carbon $upTo = null): array
    {
        $upTo = $upTo ?? today();
        $created = $skipped = $contracts = 0;

        Contract::with(['contractClauses', 'client', 'group.clients'])
            ->where('active', true)
            ->chunkById(50, function ($chunk) use ($upTo, &$created, &$skipped, &$contracts) {
                foreach ($chunk as $contract) {
                    if ($contract->isExpired()) {
                        continue;
                    }

                    $clients = self::clientsOf($contract);
                    if ($clients->isEmpty()) {
                        continue;
                    }

                    $contracts++;

                    foreach ($contract->contractClauses as $clause) {
                        if (! self::generatesDue($clause)) {
                            continue;
                        }

                        foreach (self::periodsOf($contract, $clause, $upTo) as [$start, $end]) {
                            foreach ($clients as $client) {
                                $r = self::raise($contract, $clause, $client, $start, $end);
                                $r ? $created++ : $skipped++;
                            }
                        }
                    }
                }
            });

        return ['created' => $created, 'skipped' => $skipped, 'contracts' => $contracts];
    }

    /** البند بيولّد استحقاق؟ لازم يكون خصم دوري بنسبة، ومحسوب فعلاً */
    private static function generatesDue(ContractClause $clause): bool
    {
        return $clause->counts()
            && $clause->kind === 'rebate'
            && in_array($clause->basis, self::PERIODIC, true)
            && (float) $clause->pct > 0;
    }

    /**
     * العملاء اللي العقد بيغطيهم.
     * عقد السلسلة بيغطي كل فروعها — والاستحقاق بيتحسب لكل فرع على
     * مشترياته هو، مش على إجمالي السلسلة، عشان القيد يروح لكشف
     * الحساب الصح.
     */
    private static function clientsOf(Contract $contract): Collection
    {
        // ⚠️ من العلاقة المحمّلة مسبقاً — كان بيعمل كويري جديدة لكل عقد
        if ($contract->group_id) {
            return $contract->group?->clients ?? collect();
        }

        return collect(array_filter([$contract->client]));
    }

    /**
     * الفترات المكتملة من بداية العقد لحد التاريخ.
     * ⚠️ الفترة الجارية **مابتتولّدش** — استحقاق نص ربع رقم مضلّل.
     *
     * @return array<int, array{0: Carbon, 1: Carbon}>
     */
    private static function periodsOf(Contract $contract, ContractClause $clause, Carbon $upTo): array
    {
        // ⚠️ عقد من غير تاريخ بداية مايتحسبش. الرجوع لـ created_at كان
        // بيدي تاريخ النهارده تقريباً، فكل الفترات تطلع في المستقبل
        // والعقد مايولّدش استحقاق أبداً **في صمت**.
        if ($contract->starts_at === null) {
            \Illuminate\Support\Facades\Log::warning('ContractDues: عقد من غير تاريخ بداية', [
                'contract' => $contract->id,
                'number' => $contract->number,
            ]);

            return [];
        }

        $start = $contract->starts_at->copy();

        $months = match ($clause->basis) {
            'monthly' => 1,
            'quarterly' => 3,
            default => 12,
        };

        $end = $contract->ends_at && $contract->ends_at->lt($upTo)
            ? $contract->ends_at->copy()
            : $upTo->copy();

        $out = [];

        // ⚠️ كل فترة بتتحسب من **تاريخ بداية العقد** مباشرة، مش بالتراكم على
        // الفترة اللي قبلها. التراكم كان بيزحزح حدود الربع يومين كل دورة
        // (30 نوفمبر → 2 مارس → 2 يونيو)، فالربع اللي في السيستم مابقاش
        // مطابق للربع اللي العميل بيحاسبنا عليه.
        //
        // ⚠️ addMonthsNoOverflow مش addMonths: Carbon الافتراضي بيطفح،
        // فـ 31 يناير + شهر = 3 مارس. NoOverflow بيقصّها لآخر يوم في الشهر.
        //
        // حارس 200 فترة: يغطي عقد شهري 16 سنة. لو اتخطى، فيه غلط في
        // التواريخ ولازم نعرف.
        for ($i = 0; $i < 200; $i++) {
            $periodStart = $start->copy()->addMonthsNoOverflow($months * $i);
            $periodEnd = $start->copy()->addMonthsNoOverflow($months * ($i + 1))->subDay();

            if ($periodEnd->gt($end)) {
                return $out;
            }

            $out[] = [$periodStart, $periodEnd];
        }

        \Illuminate\Support\Facades\Log::warning('ContractDues: تجاوز حد الفترات', [
            'contract' => $contract->id,
            'clause' => $clause->id,
            'start' => $start->toDateString(),
        ]);

        return $out;
    }

    /** استحقاق واحد — بيرجع true لو اتعمل، false لو موجود قبل كده */
    private static function raise(
        Contract $contract,
        ContractClause $clause,
        Client $client,
        Carbon $start,
        Carbon $end,
    ): bool {
        // ⚠️ البحث بنفس مفتاح الـ unique index بالظبط — مش بـ clause_id
        // اللي ممكن يكون اتغيّر أو بقى NULL بعد إعادة إنشاء البنود.
        $key = [
            'contract_id' => $contract->id,
            'client_id' => $client->id,
            'kind' => ContractDue::KIND_REBATE,
            'basis' => $clause->basis,
            'period_start' => $start->toDateString(),
        ];

        $existing = ContractDue::where($key)->first();

        // ⚠️ اتقيّد خلاص؟ ممنوع نلمسه. القيد موجود في كشف الحساب.
        if ($existing && ! $existing->isDue()) {
            return false;
        }

        $basis = self::purchasesIn($client, $start, $end);
        $pct = (float) $clause->pct;
        $amount = round($basis * $pct, 2);

        // مفيش مشتريات في الفترة = مفيش استحقاق. مابنعملش صف بصفر.
        if ($amount <= 0) {
            $existing?->delete();

            return false;
        }

        $payload = [
            'contract_id' => $contract->id,
            'client_id' => $client->id,
            'contract_clause_id' => $clause->id,
            'kind' => ContractDue::KIND_REBATE,
            'basis' => $clause->basis,
            'period_start' => $start,
            'period_end' => $end,
            'basis_amount' => $basis,
            'pct' => $pct,
            'amount' => $amount,
            'status' => ContractDue::STATUS_DUE,
        ];

        // ⚠️ updateOrCreate مش SELECT-then-INSERT: تشغيلين في نفس الوقت
        // كانوا بيفوتوا الفحص الاتنين ويحصل تصادم على الـ unique index
        // فالأمر كله بيقع. المفتاح هنا لازم يطابق الإندكس بالظبط.
        $created = ! $existing;

        ContractDue::updateOrCreate($key, $payload);

        return $created;
    }

    /**
     * صافي مسحوبات العميل في الفترة — الأساس اللي الخصم بيتحسب عليه.
     *
     * ⚠️ ممنوع نجمع debit بتاع kind='sale' لوحده. كشف الحساب بيشتغل كده:
     *   1. آخر الشهر بيتسجل استحقاق تقديري  → sale مدين
     *   2. لما تطلع الفاتورة الضريبية       → sale مدين تاني
     *   3. وفي نفس اللحظة الاستحقاق بيترجّع → transfer دائن
     * فمجموع الـ sale لوحده بيعدّ نفس البضاعة **مرتين**. 38 عميل من الـ 103
     * عندهم النمط ده، والفرق كان بيوصل لخصم 18.8% على بند نسبته 10%.
     *
     * وكمان المرتجعات لازم تتخصم — العميل مايستحقش خصم على بضاعة رجّعها.
     *
     * الصافي = (مبيعات + أي مدين تجاري) − (مرتجعات + قيود التحويل العكسية).
     * ⚠️ التحصيل والضرايب والخصومات نفسها **مستبعدة** — دي مش مسحوبات.
     */
    private static function purchasesIn(Client $client, Carbon $start, Carbon $end): float
    {
        // ⚠️ **الضريبة بتتطرح.** القيد بيتسجّل بالإجمالي شامل الضريبة
        // (وده صح للمديونية)، بس أساس العمولة هو **صافي المبيعات**.
        // من غير الطرح ده، بند عمولة 10% على عميل خاضع لـ 14% بيدفع
        // 11.4% — فرق بيخرج كاش من الشركة في كل تسوية ومحدش بيلاحظه
        // لأن الرقم بيبان معقول. عمود `transactions.tax` بيحمل نصيب
        // الضريبة من كل قيد عشان الحساب ده يبقى في كويري واحدة.
        // ⚠️ **الضريبة لازم تتطرح بإشارة القيد** (تدقيق ٨/٨/٢٠٢٦).
        // `SUM(tax)` بيجمع ضريبة المدين والدائن بنفس الإشارة الموجبة،
        // والقيد الدائن أصلاً داخل بالسالب في `net` — فضريبة المرتجع
        // كانت **بتتطرح مرتين**:
        //
        //     مرتجع بإجمالي 114 (صافي 100 + ضريبة 14)
        //     الغلط:  (0 − 114) − 14 = −128   ← أقل بـ 28 = 2× الضريبة
        //     الصح:   (0 − 114) − (−14) = −100
        //
        // النتيجة: أي عميل خاضع للضريبة رجّع بضاعة كان أساس عمولته
        // بيقل بـ ٢× ضريبة المرتجع — يعني الشركة بتدفع خصم أقل من
        // المستحق والعميل بيكتشفها في المطابقة.
        $row = Transaction::where('client_id', $client->id)
            ->whereIn('kind', ['sale', 'return', 'transfer'])
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as net')
            ->selectRaw('COALESCE(SUM(CASE WHEN credit > 0 THEN -tax ELSE tax END), 0) as tax')
            ->first();

        $net = (float) ($row->net ?? 0) - (float) ($row->tax ?? 0);

        // فترة صافيها سالب (مرتجعات أكتر من مبيعات) = مفيش خصم مستحق
        return max(0.0, round($net, 2));
    }

    /**
     * ترحيل استحقاق: قيد خصم تجاري دائن على العميل + إعادة حساب رصيده.
     *
     * ⚠️ الخصم الدوري بيقلّل مديونية العميل (إشعار خصم)، فهو **دائن**.
     * وبيتقيّد مرة واحدة بس: الحالة بتتغير لـ settled والقيد بيتربط،
     * فأي ضغطة تانية على الزرار مابتعملش حاجة.
     */
    public static function settle(ContractDue $due, ?int $userId = null): ?string
    {
        if ((float) $due->amount <= 0) {
            return __('client.due_zero');
        }

        $error = null;

        DB::transaction(function () use ($due, $userId, &$error) {
            // ⚠️ الفحص لازم يبقى **جوه** الترانزاكشن ومع قفل. لو بره،
            // ضغطتين في نفس اللحظة بيعدّوا الاتنين ويترحّل الخصم مرتين.
            $locked = ContractDue::whereKey($due->id)->lockForUpdate()->first();

            if ($locked === null || ! $locked->isDue()) {
                $error = __('client.due_already_settled');

                return;
            }

            $due = $locked;
            $due->loadMissing('client');

            $txn = Transaction::create([
                'client_id' => $due->client_id,
                'date' => $due->period_end,
                // ⚠️ الميمو بيتخزن مرة واحدة وبيتقرا للأبد. لو خزّنّاه
                // بلغة الواجهة، اللي رحّل بالعربي بيسيب نص عربي في كشف
                // حساب بيتقرا بالإنجليزي. بنخزّن مرجع محايد، والعرض
                // بيركّب النص من البند نفسه.
                'memo' => $due->contract_clause_id
                    ? 'CLAUSE#'.$due->contract_clause_id.' '.$due->periodLabel()
                    : 'DUE#'.$due->id.' '.$due->periodLabel(),
                'debit' => 0,
                'credit' => $due->amount,
                'kind' => 'rebate',
                'source_type' => ContractDue::class,
                'source_id' => $due->id,
            ]);

            $due->update([
                'status' => ContractDue::STATUS_SETTLED,
                'settled_at' => now(),
                'settled_by' => $userId,
                'transaction_id' => $txn->id,
            ]);

            $due->client?->recalculate();
        });

        return $error;
    }

    /** إلغاء استحقاق — بيفضل مسجّل للتاريخ بس مش هيتقيّد */
    public static function waive(ContractDue $due, ?string $note = null, ?int $userId = null): ?string
    {
        if (! $due->isDue()) {
            return __('client.due_already_settled');
        }

        $due->update([
            'status' => ContractDue::STATUS_WAIVED,
            'note' => $note,
            'settled_at' => now(),
            'settled_by' => $userId,
        ]);

        return null;
    }
}
