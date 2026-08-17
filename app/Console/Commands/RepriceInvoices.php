<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\Invoice;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * إعادة تسعير فواتير بخصم جديد  ·  ١٧ أغسطس ٢٠٢٦
 * ═══════════════════════════════════════════════════════════════
 *
 * طلب المالك: «عاوز أعيد الفواتير اللي اتعملت بنسب الخصم القديمة
 * وتاخد الجديدة — علشان يبقى الريفرنس الهارد كوبي زي السيستم».
 *
 * ═══ إيه اللي بيتحرّك مع كل فاتورة ═══
 *
 * الفاتورة مش صف لوحده. تغيير خصمها بيمسّ **أربع حاجات**:
 *
 *   1. سطور الفاتورة  — السعر والإجمالي والضريبة لكل سطر
 *   2. رؤوس الفاتورة  — discount / total / tax_total / grand_total
 *   3. قيد كشف الحساب — `debit` بالإجمالي شامل الضريبة
 *   4. رصيد العميل    — `recalculate()` من القيود
 *
 * ⚠️ **اللي ينسى رقم ٣ و٤ بيسيب كشف حساب بيقول رقم والفاتورة
 * بتقول رقم تاني** — وده أسوأ من إن الخصم يفضل قديم.
 *
 * ═══ اللي الأداة دي **مابتلمسهوش** ═══
 *
 * ⚠️ **`list_price` و`qty` و`unit_cost` زي ما هما.** بنطبّق الخصم
 * الجديد على سعر القايمة **المخزَّن على السطر** — مابنعيدش التسعير
 * من `Pricing::quote()`. الفرق مهم: `quote()` بتقرا قايمة الأسعار
 * **النهاردة**، فلو سعر منتج اتغيّر بعد الفاتورة كانت هتعيد كتابة
 * السعر كمان — والمالك طلب **الخصم** بس.
 *
 * ⚠️ **التكلفة مابتتغيّرش** — الخصم بيغيّر اللي العميل بيدفعه، مش
 * اللي إحنا دفعناه. لمس `unit_cost` كان هيزوّر الربحية التاريخية.
 *
 * ⚠️ **الفاتورة المرفوعة للضرائب بتتخطّى** (قرار المالك): حالة
 * `exported` أو `submitted` معناها إن الرقم ده عند المصلحة.
 * تعديله عندنا في صمت = دفترك غير دفترهم.
 *
 *   php artisan promax:reprice-invoices --group=5
 *   php artisan promax:reprice-invoices --group=5 --fix
 */
class RepriceInvoices extends Command
{
    protected $signature = 'promax:reprice-invoices
        {--group= : رقم السلسلة — كل فروعها}
        {--client= : رقم عميل واحد بدل السلسلة}
        {--fix : نفّذ — من غيرها معاينة بس}';

    protected $description = 'إعادة حساب فواتير بالخصم السارِي حالياً';

    /** حالات الرفع اللي ممنوع نلمس فاتورتها */
    private const ETA_LOCKED = ['exported', 'submitted'];

    public function handle(): int
    {
        $fix = (bool) $this->option('fix');

        $clients = $this->targets();

        if ($clients === null) {
            return self::FAILURE;
        }

        if ($clients->isEmpty()) {
            $this->error('  مفيش عملاء في النطاق ده.');

            return self::FAILURE;
        }

        $this->line('');
        $this->line('  عملاء في النطاق: '.$clients->count());
        $this->line('');

        $plan = [];
        $locked = [];
        $same = 0;

        foreach ($clients as $client) {
            // ⚠️ الخصم السارِي **دلوقتي** — نفس الدالة اللي الفاتورة
            // بتستخدمها وقت الإنشاء. مفيش حساب موازي هنا.
            $newPct = $client->effectiveDiscount();

            $invoices = Invoice::with('items.product')
                ->where('client_id', $client->id)
                ->get();

            foreach ($invoices as $inv) {
                // مقارنة بأربع خانات عشرية — `discount_pct` مخزّنة كسر
                if (abs((float) $inv->discount_pct - $newPct) < 0.00005) {
                    $same++;

                    continue;
                }

                if (in_array((string) $inv->eta_status, self::ETA_LOCKED, true)) {
                    $locked[] = $inv;

                    continue;
                }

                $plan[] = ['invoice' => $inv, 'client' => $client, 'pct' => $newPct];
            }
        }

        if ($plan === [] && $locked === []) {
            $this->info("  كل الفواتير بخصمها الصح خلاص ({$same} فاتورة). ✅");

            return self::SUCCESS;
        }

        // ═══ المعاينة ═══
        $deltaTotal = 0.0;

        foreach ($plan as $row) {
            $inv = $row['invoice'];
            $calc = $this->recalc($inv, $row['client'], $row['pct']);
            $row['calc'] = $calc;
            $delta = $calc['grand'] - (float) $inv->grand_total;
            $deltaTotal += $delta;

            $this->line(sprintf(
                '  %-12s %-26s %6s%% ← %6s%%   %12s ← %12s   %s%s',
                $inv->number,
                mb_substr($row['client']->displayName(), 0, 26),
                $this->pct($row['pct']),
                $this->pct((float) $inv->discount_pct),
                number_format($calc['grand'], 2),
                number_format((float) $inv->grand_total, 2),
                $delta >= 0 ? '+' : '',
                number_format($delta, 2),
            ));
        }

        $this->line('');
        $this->line('  هتتعاد: '.count($plan)
            .'  ·  بخصمها الصح: '.$same
            .'  ·  مقفولة ضريبياً: '.count($locked));
        $this->line('  فرق الإجمالي: '.number_format($deltaTotal, 2));

        if ($locked !== []) {
            $this->line('');
            $this->warn('  ⚠ الفواتير دي **اترفعت لمصلحة الضرائب** فماتلمستش:');

            foreach ($locked as $inv) {
                $this->warn(sprintf('      %-12s  %s  (%s)',
                    $inv->number, number_format((float) $inv->grand_total, 2), $inv->eta_status));
            }

            $this->warn('    لو عايز تعدّلها لازم تتعدّل في المنظومة كمان.');
        }

        if (! $fix) {
            $this->line('');
            $this->comment('  (معاينة — ضيف --fix للتنفيذ)');

            return self::SUCCESS;
        }

        // ═══ نسخة احتياطية قبل أي كتابة ═══
        //
        // ⚠️ **بتتكتب جوّه الترانزاكشن وبترمي لو فشلت.** ملف باك أب
        // مش موجود بيتكتشف بعد ما الأرقام تتغيّر — وساعتها مفيش رجوع.
        $stamp = now()->format('Ymd_His');
        $path = storage_path("app/backups/reprice_{$stamp}.json");

        DB::transaction(function () use ($plan, $path) {
            $backup = [];

            foreach ($plan as $row) {
                $inv = $row['invoice'];

                $backup[] = [
                    'invoice_id' => $inv->id,
                    'number' => $inv->number,
                    'before' => $inv->only([
                        'discount_pct', 'discount_source', 'discount',
                        'total', 'tax_total', 'grand_total',
                    ]),
                    'items' => $inv->items->map(fn ($i) => $i->only([
                        'id', 'price', 'total', 'tax_rate', 'tax',
                    ]))->all(),
                ];
            }

            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }

            if (file_put_contents($path, json_encode($backup,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
                throw new \RuntimeException('فشل كتابة النسخة الاحتياطية — اتوقف قبل أي تعديل.');
            }

            $touched = [];

            foreach ($plan as $row) {
                /** @var Invoice $inv */
                $inv = $row['invoice'];
                $client = $row['client'];
                $calc = $this->recalc($inv, $client, $row['pct']);

                foreach ($calc['lines'] as $line) {
                    $line['model']->update([
                        'price' => $line['price'],
                        'total' => $line['total'],
                        'tax_rate' => $line['tax_rate'],
                        'tax' => $line['tax'],
                    ]);
                }

                $inv->update([
                    'discount_pct' => $row['pct'],
                    'discount_source' => $client->discountSourceKey(),
                    'discount' => $calc['discount'],
                    'total' => $calc['net'],
                    'tax_total' => $calc['tax'],
                    'grand_total' => $calc['grand'],
                ]);

                // ═══ القيد في كشف الحساب ═══
                //
                // ⚠️ **بنعدّل الموجود مش بنضيف قيد تسوية.** المالك
                // عايز الهارد كوبي = السيستم؛ قيد تسوية جنبه كان
                // هيخلّي الكشف يقول رقمين لفاتورة واحدة.
                //
                // ⚠️ **الأمانة (`consignment`) قيدها بصفر مدين** —
                // ممنوع نكتب فيها الإجمالي، وإلا بنولّد مديونية
                // على بضاعة لسه ملك بروماكس.
                foreach (Transaction::where('source_type', Invoice::class)
                    ->where('source_id', $inv->id)->get() as $t) {
                    if ($t->kind === 'consignment') {
                        continue;
                    }

                    $t->update($t->kind === 'collection'
                        ? ['credit' => $calc['grand'], 'tax' => $calc['tax']]
                        : ['debit' => $calc['grand'], 'tax' => $calc['tax']]);
                }

                $touched[$client->id] = $client;
            }

            // ⚠️ **`recalculate()` مرة واحدة لكل عميل بعد كل فواتيره**
            // — نداؤها جوّه اللوب على عميل له ٤٠ فاتورة معناه ٤٠
            // إعادة حساب كاملة لنفس الرصيد.
            foreach ($touched as $client) {
                $client->recalculate();
            }
        });

        $this->info('  ✓ اتعادت '.count($plan).' فاتورة.');
        $this->comment('  النسخة الاحتياطية: '.$path);

        return self::SUCCESS;
    }

    /** العملاء المستهدفين — سلسلة أو عميل واحد */
    private function targets()
    {
        if ($id = $this->option('client')) {
            $c = Client::find((int) $id);

            if ($c === null) {
                $this->error("  مفيش عميل رقم {$id}.");

                return null;
            }

            return collect([$c]);
        }

        if ($id = $this->option('group')) {
            $g = ClientGroup::find((int) $id);

            if ($g === null) {
                $this->error("  مفيش سلسلة رقم {$id}.");

                return null;
            }

            $this->line('  السلسلة: '.$g->displayName());

            return $g->clients()->get();
        }

        $this->error('  لازم --group أو --client.');

        return null;
    }

    /**
     * حساب الفاتورة بالخصم الجديد — **من سعر القايمة المخزَّن**.
     *
     * ⚠️ نفس حساب `Pricing::quote()` بالحرف:
     * `round($listPrice * (1 - $pct), 2)` بعدين `round($price * $qty, 2)`.
     * أي اختلاف في التقريب معناه فاتورة معادة بقرش مختلف عن اللي
     * كانت هتطلع لو اتعملت من الأول بالخصم ده.
     */
    private function recalc(Invoice $inv, Client $client, float $pct): array
    {
        $rows = [];
        $lines = [];
        $subtotal = 0.0;

        foreach ($inv->items as $item) {
            $listPrice = (float) $item->list_price;
            $qty = (int) $item->qty;

            $price = round($listPrice * (1 - $pct), 2);
            $total = round($price * $qty, 2);

            // ⚠️ الضريبة بتتحسب على الصافي **بعد الخصم** سطر بسطر —
            // نفس `storeInvoice`. الفاتورة ممكن تجمع صنف خاضع ومعفى.
            $taxRate = $item->product
                ? \App\Services\Tax::rate($client, $item->product)
                : (float) $item->tax_rate;

            $tax = $item->product
                ? \App\Services\Tax::on($total, $client, $item->product)
                : round($total * $taxRate, 2);

            $subtotal += round($listPrice * $qty, 2);

            $rows[] = ['total' => $total, 'tax' => $tax];
            $lines[] = [
                'model' => $item,
                'price' => $price,
                'total' => $total,
                'tax_rate' => $taxRate,
                'tax' => $tax,
            ];
        }

        $sums = \App\Services\Tax::totals($rows);

        return [
            'lines' => $lines,
            'discount' => round($subtotal - $sums['net'], 2),
            'net' => $sums['net'],
            'tax' => $sums['tax'],
            'grand' => $sums['grand'],
        ];
    }

    /** كسر ← نسبة معروضة من غير أصفار زايدة */
    private function pct(float $v): string
    {
        return rtrim(rtrim(number_format($v * 100, 2, '.', ''), '0'), '.');
    }
}
