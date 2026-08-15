<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * تحويل فواتير عميل من كاش لآجل  ·  ١٥ أغسطس ٢٠٢٦
 * ═══════════════════════════════════════════════════════════════
 *
 * طلب المالك: «اقلب كل فواتير العميل ده آجل، وافتحله الأوبشن بتاعه
 * كاش وآجل».
 *
 * ═══ إيه اللي بيتغيّر ═══
 *
 * الفاتورة الكاش بتعمل **قيدين**: `sale` مدين + `collection` دائن،
 * فالرصيد بيرجع صفر لأن الفلوس اتقبضت في نفس اللحظة. الفاتورة
 * الآجل بتعمل **قيد واحد** (`sale` مدين) والمديونية بتفضل مفتوحة
 * لحد التحصيل.
 *
 * فالتحويل = `invoices.payment` تبقى `credit` + **قيد التحصيل
 * المرتبط بالفاتورة يتشال** + `recalculate()`.
 *
 * ⚠️ **بيشيل قيد التحصيل بتاع الفاتورة بس** — المربوط بيها
 * (`source_type=Invoice` + `source_id`). أي تحصيل ميداني منفصل
 * (`kind=collection` من زيارة أو من الخزنة) **مابيتلمسش**: ده
 * فلوس اتقبضت فعلاً وليها صورة إثبات.
 *
 * ⚠️ **ده بيزوّد مديونية العميل** بقيمة الفواتير المحوّلة، و**بيقلّل
 * الكاش المتوقع في تصفية المندوب** بنفس القيمة. ده الصح لو الفلوس
 * ماتقبضتش فعلاً — وده اللي المالك أكّده. لو المندوب كان قابض،
 * الصح هو تحصيل منفصل مش تحويل الفاتورة.
 *
 * ⚠️ الأمر **معاينة بالافتراضي**. `--fix` هي اللي بتنفّذ.
 *
 *   php artisan promax:client-credit --client=CL-0042
 *   php artisan promax:client-credit --client=CL-0042 --terms=both --fix
 */
class ClientInvoicesToCredit extends Command
{
    protected $signature = 'promax:client-credit
        {--client= : كود العميل أو رقمه}
        {--terms= : cash|credit|both — يغيّر شروط الدفع كمان (اختياري)}
        {--fix : نفّذ — من غيرها معاينة بس}';

    protected $description = 'تحويل فواتير عميل من كاش لآجل + ضبط شروط الدفع';

    public function handle(): int
    {
        $key = trim((string) $this->option('client'));
        $terms = $this->option('terms');
        $fix = (bool) $this->option('fix');

        if ($key === '') {
            $this->error('حدد --client=CODE أو --client=ID');

            return self::FAILURE;
        }

        if ($terms !== null && ! in_array($terms, Client::PAY_TERMS, true)) {
            $this->error('--terms لازم تكون: '.implode(' | ', Client::PAY_TERMS));

            return self::FAILURE;
        }

        /** @var Client|null $client */
        $client = Client::where('code', $key)->orWhere('id', (int) $key)->first();

        if ($client === null) {
            $this->error("مفيش عميل بالكود/الرقم {$key}");

            return self::FAILURE;
        }

        $this->line('');
        $this->line('  '.$client->displayName()." ({$client->code})");
        $this->line('  شروط الدفع الحالية: '.$client->paymentTerms()
            .'  ·  المخزّن: '.($client->payment_terms ?: 'من القناة'));
        $this->line('  الرصيد الحالي: '.number_format((float) $client->balance, 2));

        // ⚠️ التصنيف `danger` بيفرض كاش مهما كان العمود — لازم يتقال
        // بصوت عالي، وإلا المالك هيغيّر الشرط ومايتغيّرش حاجة.
        if ($client->category === 'danger') {
            $this->warn('  ⚠ العميل مصنّف «خطر» — `paymentTerms()` بتفرض كاش مهما كان العمود.');
            $this->warn('    لازم تغيّر التصنيف الأول من كارت العميل عشان الآجل يشتغل.');
        }

        $cash = Invoice::where('client_id', $client->id)
            ->where('payment', 'cash')
            ->orderBy('id')
            ->get();

        if ($cash->isEmpty()) {
            $this->info('  مفيش فواتير كاش على العميل ده.');
        } else {
            $this->line('  فواتير كاش هتتحوّل لآجل: '.$cash->count());
        }

        $sum = 0.0;

        foreach ($cash as $inv) {
            $coll = Transaction::where('source_type', Invoice::class)
                ->where('source_id', $inv->id)
                ->where('kind', 'collection')
                ->get();

            $sum += (float) $inv->grand_total;

            $this->line(sprintf('    %-12s %s  %12s   قيود تحصيل مرتبطة: %d',
                $inv->number,
                $inv->created_at->format('Y-m-d'),
                number_format((float) $inv->grand_total, 2),
                $coll->count()));
        }

        if ($cash->isNotEmpty()) {
            $this->line('    ── الإجمالي اللي هيتحوّل لمديونية: '.number_format($sum, 2));
        }

        if (! $fix) {
            $this->comment('  (معاينة — ضيف --fix للتنفيذ)');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($client, $cash, $terms) {
            foreach ($cash as $inv) {
                Transaction::where('source_type', Invoice::class)
                    ->where('source_id', $inv->id)
                    ->where('kind', 'collection')
                    ->delete();

                $inv->update(['payment' => 'credit']);
            }

            if ($terms !== null) {
                $client->update(['payment_terms' => $terms]);
            }

            // ⚠️ أشهر باج في رَنبوك الأرقام: تعديل قيود من غير إعادة
            // حساب — الرصيد المخزّن بيفضل على القيمة القديمة للأبد.
            $client->recalculate();
        });

        $client->refresh();

        $this->info('  ✓ اتحوّل '.$cash->count().' فاتورة لآجل.');

        if ($terms !== null) {
            $this->info('  ✓ شروط الدفع بقت: '.$terms);
        }

        $this->info('  ✓ الرصيد الجديد: '.number_format((float) $client->balance, 2));
        $this->comment('  راجعه من كشف الحساب: لازم يساوي مجموع المدين ناقص الدائن.');

        return self::SUCCESS;
    }
}
