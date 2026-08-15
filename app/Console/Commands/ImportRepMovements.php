<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * إدخال حركات مندوب من ملف — بتواريخها  ·  ١٥ أغسطس ٢٠٢٦
 * ═══════════════════════════════════════════════════════════════
 *
 * بعد `promax:wipe-rep`، المالك بيبعت الحركات حاجة حاجة بتواريخها
 * الحقيقية. الأمر ده بيدخّلها من ملف JSON — مش SQL يدوي — عشان:
 *   · كل دفعة تبقى مراجَعة قبل التنفيذ (`--dry-run` هو الافتراضي)
 *   · تتعاد لو حصل غلط
 *   · يفضل عندك سجل مكتوب باللي اتدخّل
 *
 * ═══ شكل الملف ═══
 *
 * {
 *   "rep_id": 17,
 *   "collections": [
 *     {"client": "ورلد جيم", "at": "2026-08-15 16:44", "amount": 3360,
 *      "method": "cash", "reference": null}
 *   ]
 * }
 *
 * ⚠️ `client` ممكن يكون **الاسم أو الكود أو الرقم**. الأمر بيدوّر
 * بالتلاتة، ولو لقى **أكتر من عميل** بنفس الاسم بيقف ويطلب الرقم
 * صراحةً — مايختارش لوحده. (فيه شبهة تكرار عملاء في الداتا:
 * «Hammam Gym» و«همام جيم».)
 *
 * ═══ التاريخ والوقت ═══
 *
 * ⚠️ `transactions.date` عمود **DATE** بس، والوقت اللي بيبان في
 * الشاشات جاي من `created_at`. فالأمر بيكتب الاتنين: `date` من
 * اليوم، و`created_at`/`updated_at` من الوقت الكامل — وإلا كل
 * الحركات هتتصف تحت بعض بترتيب غلط في كشف الحساب واللايف.
 *
 * ═══ الربط بالزيارة ═══
 *
 * التحصيل الميداني بيترسي على الزيارة (`source_type=Visit`) عشان
 * يبان «تحصيل ميداني أثناء زيارة». الأمر بيدوّر على زيارة نفس
 * المندوب لنفس العميل في نفس اليوم؛ لو لقاها بيربط، ولو لأ بيسيب
 * المصدر فاضي — تحصيل مكتبي، وده أصدق من ربط بزيارة غلط.
 *
 *   php artisan promax:import-moves --file=storage/app/imports/coll.json
 *   php artisan promax:import-moves --file=... --fix
 */
class ImportRepMovements extends Command
{
    protected $signature = 'promax:import-moves
        {--file= : مسار ملف الـJSON}
        {--fix : نفّذ — من غيرها معاينة بس}';

    protected $description = 'إدخال تحصيلات/حركات مندوب من ملف JSON بتواريخها الحقيقية';

    public function handle(): int
    {
        $file = (string) $this->option('file');
        $fix = (bool) $this->option('fix');

        if (! is_file($file)) {
            $this->error("مفيش ملف: {$file}");

            return self::FAILURE;
        }

        $data = json_decode((string) file_get_contents($file), true);

        if (! is_array($data)) {
            $this->error('الملف مش JSON صالح.');

            return self::FAILURE;
        }

        $rep = User::find((int) ($data['rep_id'] ?? 0));

        if ($rep === null) {
            $this->error('`rep_id` مش موجود أو غلط.');

            return self::FAILURE;
        }

        // ═══ الفواتير لو الملف فيه قسم `invoices` ═══
        if (($data['invoices'] ?? []) !== []) {
            return $this->importInvoices($rep, $data['invoices'], $fix);
        }

        $rows = $data['collections'] ?? [];

        if ($rows === []) {
            $this->warn('مفيش `collections` ولا `invoices` في الملف.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('  المندوب: '.$rep->displayName()." (#{$rep->id})");
        $this->line('  تحصيلات في الملف: '.count($rows));
        $this->line('');

        $plan = [];
        $bad = 0;
        $sum = 0.0;

        foreach ($rows as $n => $r) {
            $line = $n + 1;
            $key = trim((string) ($r['client'] ?? ''));
            $amount = round((float) ($r['amount'] ?? 0), 2);
            $method = (string) ($r['method'] ?? 'cash');
            $ref = $r['reference'] ?? null;

            $matches = $this->findClients($key);

            if ($matches->isEmpty()) {
                $this->error("  #{$line}  مفيش عميل بالاسم/الكود: «{$key}»");
                $bad++;

                continue;
            }

            if ($matches->count() > 1) {
                $this->error("  #{$line}  «{$key}» بيطابق ".$matches->count().' عملاء — حدّد الرقم:');

                foreach ($matches as $m) {
                    $this->error("        #{$m->id} · {$m->code} · {$m->displayName()}");
                }

                $bad++;

                continue;
            }

            $client = $matches->first();

            try {
                $at = Carbon::parse((string) ($r['at'] ?? ''), 'Africa/Cairo');
            } catch (\Throwable) {
                $this->error("  #{$line}  تاريخ غلط: «".($r['at'] ?? '')."»");
                $bad++;

                continue;
            }

            if ($amount <= 0) {
                $this->error("  #{$line}  مبلغ غلط: {$amount}");
                $bad++;

                continue;
            }

            if (! in_array($method, Transaction::METHODS, true)) {
                $this->error("  #{$line}  طريقة غلط: «{$method}» — المسموح: "
                    .implode('|', Transaction::METHODS));
                $bad++;

                continue;
            }

            // ⚠️ غير النقدي **لازم** ريفرنس — نفس قاعدة شاشة التحصيل،
            // من غيره المطابقة مع البنك مستحيلة.
            if ($method !== Transaction::METHOD_CASH && ! $ref) {
                $this->error("  #{$line}  طريقة «{$method}» محتاجة `reference`.");
                $bad++;

                continue;
            }

            // ⚠️ **حارس التكرار** — نفس سبب حارس الفواتير: تحصيل
            // لنفس العميل بنفس الثانية ونفس المبلغ = نسخة.
            $dupe = Transaction::where('client_id', $client->id)
                ->where('kind', 'collection')
                ->where('created_at', $at)
                ->whereRaw('ROUND(credit, 2) = ?', [round($amount, 2)])
                ->exists();

            if ($dupe) {
                $this->error("  #{$line}  **متدخّل قبل كده**: "
                    .$client->displayName().' · '.$at->format('Y-m-d H:i')
                    .' · '.number_format($amount, 2));
                $bad++;

                continue;
            }

            $visit = Visit::where('user_id', $rep->id)
                ->where('client_id', $client->id)
                ->whereDate('checked_in_at', $at->toDateString())
                ->orderBy('checked_in_at')
                ->first();

            $plan[] = compact('client', 'at', 'amount', 'method', 'ref', 'visit');
            $sum += $amount;

            $this->line(sprintf('  #%-3d %-26s #%-6d %11s  %-9s %s',
                $line, mb_substr($client->displayName(), 0, 26), $client->id,
                number_format($amount, 2), $method,
                $visit ? 'مربوط بزيارة #'.$visit->id : 'من غير زيارة'));
        }

        $this->line('');
        $this->line('  الإجمالي: '.number_format($sum, 2).'  ·  صفوف بها مشاكل: '.$bad);

        if ($bad > 0) {
            $this->error('  فيه صفوف غلط — صلّحها في الملف. مفيش إدخال جزئي.');

            return self::FAILURE;
        }

        if (! $fix) {
            $this->comment('  (معاينة — ضيف --fix للتنفيذ)');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($plan) {
            foreach ($plan as $p) {
                $t = Transaction::create([
                    'client_id' => $p['client']->id,
                    // ⚠️ `date` يوم بس — الوقت في `created_at` تحت
                    'date' => $p['at']->toDateString(),
                    'memo' => __('flash.memo_field_collection'),
                    'debit' => 0,
                    'credit' => $p['amount'],
                    'kind' => 'collection',
                    'method' => $p['method'],
                    'reference' => $p['ref'],
                    'source_type' => $p['visit'] ? Visit::class : null,
                    'source_id' => $p['visit']?->id,
                ]);

                // ⚠️ **الوقت الحقيقي** — `created_at` هو اللي الشاشات
                // بتعرضه وبترتّب بيه. من غير السطر ده كل الحركات
                // هتتسجّل بوقت التنفيذ وتترتّب غلط.
                $t->forceFill([
                    'created_at' => $p['at'],
                    'updated_at' => $p['at'],
                ])->saveQuietly();
            }

            // ⚠️ إعادة الحساب لكل عميل اتأثر — أشهر باج في الرَنبوك
            collect($plan)->pluck('client')->unique('id')
                ->each(fn ($c) => $c->recalculate());
        });

        $this->info('  ✓ اتدخّل '.count($plan).' تحصيل.');
        $this->line('');
        $this->line('  الأرصدة بعد الإدخال:');

        foreach (collect($plan)->pluck('client')->unique('id') as $c) {
            $c->refresh();
            $this->line(sprintf('    #%-6d %-28s %12s',
                $c->id, mb_substr($c->displayName(), 0, 28),
                number_format((float) $c->balance, 2)));
        }

        return self::SUCCESS;
    }

    /**
     * ═══════════════════════════════════════════════════════════
     * إدخال الفواتير بتواريخها
     * ═══════════════════════════════════════════════════════════
     *
     * ⚠️ **الأسعار من `Pricing` مش من الملف.** عقيدة الأرقام: «`Pricing`
     * هو المكان الوحيد اللي بيحسب سعر بيع». الملف بيقول الصنف
     * والكمية بس، والسعر بيتحسب من قايمة العميل وخصمه — وده اللي
     * بيخلّي الفاتورة المُدخلة تطابق اللي المندوب عمله فعلاً
     * (INV-1003 كانت ٤٥ للوحدة = ٦٠ قايمة − ٢٥٪ خصم).
     *
     * ⚠️ **مابتخصمش من العهدة.** العهدة لسه ماتدخلتش (بتيجي بعد
     * كده)، والخصم دلوقتي هيقع. أداة إدخال العهدة هي اللي هتحسب
     * `sold` من الفواتير دي — فمعادلة التصفية بتقفل من غير خصم
     * مزدوج (نفس الفخ اللي عمل «الرصيد بايظ» أصلاً).
     *
     * ⚠️ **الوحدات بتتحوّل لقطع من الكتالوج**: `unit: "case"`
     * بيتضرب في `units_per_case` الحقيقي، و`"box"` في `box_units`.
     * مافيش أرقام تحويل مكتوبة بالإيد.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function importInvoices(User $rep, array $rows, bool $fix): int
    {
        $this->line('  فواتير في الملف: '.count($rows));
        $this->line('');

        $plan = [];
        $bad = 0;

        foreach ($rows as $n => $r) {
            $line = $n + 1;
            $matches = $this->findClients(trim((string) ($r['client'] ?? '')));

            if ($matches->count() !== 1) {
                $this->error("  فاتورة #{$line}: العميل «".($r['client'] ?? '')
                    .'» '.($matches->isEmpty() ? 'مش موجود' : 'بيطابق أكتر من سجل'));

                foreach ($matches as $m) {
                    $this->error("        #{$m->id} · {$m->code} · {$m->displayName()}");
                }

                $bad++;

                continue;
            }

            $client = $matches->first();
            $payment = (string) ($r['payment'] ?? 'credit');

            if (! in_array($payment, ['cash', 'credit'], true)) {
                $this->error("  فاتورة #{$line}: `payment` لازم cash أو credit");
                $bad++;

                continue;
            }

            try {
                $at = Carbon::parse((string) ($r['at'] ?? ''), 'Africa/Cairo');
            } catch (\Throwable) {
                $this->error("  فاتورة #{$line}: تاريخ غلط");
                $bad++;

                continue;
            }

            $lines = [];
            $bust = false;

            foreach (($r['items'] ?? []) as $it) {
                $code = trim((string) ($it['code'] ?? ''));
                $product = \App\Models\Product::where('code', $code)
                    ->orWhere('barcode', $code)->first();

                if ($product === null) {
                    $this->error("  فاتورة #{$line}: صنف مش موجود «{$code}»");
                    $bust = true;

                    continue;
                }

                // ═══ تحويل الوحدة لقطع من الكتالوج ═══
                $unit = (string) ($it['unit'] ?? 'piece');
                $mult = match ($unit) {
                    'case' => max((int) $product->units_per_case, 1),
                    'box' => max((int) $product->box_units, 1),
                    default => 1,
                };

                $qty = (int) ($it['qty'] ?? 0) * $mult;

                if ($qty <= 0) {
                    $this->error("  فاتورة #{$line}: كمية غلط للصنف {$code}");
                    $bust = true;

                    continue;
                }

                $batch = null;

                if (! empty($it['batch'])) {
                    $batch = \App\Models\Batch::where('batch_no', $it['batch'])
                        ->where('product_id', $product->id)->first();

                    if ($batch === null) {
                        $this->warn("  فاتورة #{$line}: باتش «{$it['batch']}» مش موجود — هيتساب فاضي");
                    }
                }

                // ⚠️ السعر من `Pricing` — قايمة العميل وخصمه
                $q = \App\Services\Pricing::quote($client, $product, $batch, $qty);

                $lines[] = ['product' => $product, 'batch' => $batch, 'qty' => $qty, 'q' => $q];
            }

            if ($bust || $lines === []) {
                $bad++;

                continue;
            }

            $sub = round(array_sum(array_map(fn ($l) => $l['q']['list_price'] * $l['qty'], $lines)), 2);
            $net = round(array_sum(array_map(fn ($l) => $l['q']['line_total'], $lines)), 2);
            $cost = round(array_sum(array_map(fn ($l) => $l['q']['line_cost'], $lines)), 2);

            // ⚠️ **حارس التكرار** — أُضيف بعد ما الاستيراد اتشغّل مرتين
            // فالأرصدة اتضاعفت. فاتورة لنفس العميل بنفس **الثانية**
            // ونفس الإجمالي = مستحيل تكون حقيقية مرتين.
            $exists = \App\Models\Invoice::where('client_id', $client->id)
                ->where('user_id', $rep->id)
                ->where('created_at', $at)
                ->whereRaw('ROUND(grand_total, 2) = ?', [round($net, 2)])
                ->first();

            if ($exists !== null) {
                $this->error("  فاتورة #{$line}: **متدخّلة قبل كده** كـ{$exists->number}"
                    .' ('.$at->format('Y-m-d H:i').' · '.number_format($net, 2).')');
                $bad++;

                continue;
            }

            $plan[] = compact('client', 'at', 'payment', 'lines', 'sub', 'net', 'cost');

            $this->line(sprintf('  فاتورة #%d  %s  #%d %s  %s  %s',
                $line, $at->format('Y-m-d H:i'), $client->id,
                mb_substr($client->displayName(), 0, 22), $payment,
                number_format($net, 2)));

            foreach ($lines as $l) {
                $this->line(sprintf('       %-40s %5d ×%8s = %10s',
                    mb_substr($l['product']->displayName(), 0, 40), $l['qty'],
                    number_format($l['q']['unit_price'], 2),
                    number_format($l['q']['line_total'], 2)));
            }
        }

        if ($bad > 0) {
            $this->error('  فيه صفوف غلط — مفيش إدخال جزئي.');

            return self::FAILURE;
        }

        $this->line('');
        $this->line('  إجمالي الفواتير: '
            .number_format(array_sum(array_column($plan, 'net')), 2));

        if (! $fix) {
            $this->comment('  (معاينة — ضيف --fix للتنفيذ)');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($plan, $rep) {
            foreach ($plan as $p) {
                $client = $p['client'];
                $disc = round($p['sub'] - $p['net'], 2);

                $inv = \App\Models\Invoice::create([
                    'number' => \App\Models\Invoice::nextNumber(),
                    'client_id' => $client->id,
                    'user_id' => $rep->id,
                    'payment' => $p['payment'],
                    'price_list' => $p['lines'][0]['q']['list'],
                    'subtotal' => $p['sub'],
                    'discount_pct' => $p['lines'][0]['q']['discount_pct'],
                    'discount_source' => $p['lines'][0]['q']['discount_source'],
                    'discount' => $disc,
                    'total' => $p['net'],
                    // ⚠️ الضريبة نايمة في السيستم — صفر عن قصد
                    'tax_total' => 0,
                    'grand_total' => $p['net'],
                    'cost_total' => $p['cost'],
                ]);

                foreach ($p['lines'] as $l) {
                    $inv->items()->create([
                        'product_id' => $l['product']->id,
                        'batch_id' => $l['batch']?->id,
                        'qty' => $l['qty'],
                        'list_price' => $l['q']['list_price'],
                        'price' => $l['q']['unit_price'],
                        'unit_cost' => $l['q']['unit_cost'],
                        'total' => $l['q']['line_total'],
                        'tax_rate' => 0,
                        'tax' => 0,
                    ]);
                }

                $inv->forceFill(['created_at' => $p['at'], 'updated_at' => $p['at']])->saveQuietly();

                // ═══ القيد: مدين دايماً، وقيد تحصيل للكاش بس ═══
                $sale = Transaction::create([
                    'client_id' => $client->id,
                    'date' => $p['at']->toDateString(),
                    'memo' => __('flash.memo_invoice', [
                        'number' => $inv->number,
                        'user' => $rep->displayName(),
                    ]),
                    'debit' => $p['net'],
                    'credit' => 0,
                    'kind' => 'sale',
                    'source_type' => \App\Models\Invoice::class,
                    'source_id' => $inv->id,
                ]);

                $sale->forceFill(['created_at' => $p['at'], 'updated_at' => $p['at']])->saveQuietly();

                if ($p['payment'] === 'cash') {
                    $coll = Transaction::create([
                        'client_id' => $client->id,
                        'date' => $p['at']->toDateString(),
                        'memo' => __('flash.memo_cash_with_invoice', ['number' => $inv->number]),
                        'debit' => 0,
                        'credit' => $p['net'],
                        'kind' => 'collection',
                        'method' => Transaction::METHOD_CASH,
                        'source_type' => \App\Models\Invoice::class,
                        'source_id' => $inv->id,
                    ]);

                    $coll->forceFill(['created_at' => $p['at'], 'updated_at' => $p['at']])->saveQuietly();
                }
            }

            collect($plan)->pluck('client')->unique('id')->each(fn ($c) => $c->recalculate());
        });

        $this->info('  ✓ اتدخّل '.count($plan).' فاتورة.');

        foreach (collect($plan)->pluck('client')->unique('id') as $c) {
            $c->refresh();
            $this->line(sprintf('    #%-6d %-28s %12s',
                $c->id, mb_substr($c->displayName(), 0, 28),
                number_format((float) $c->balance, 2)));
        }

        $this->comment('  ⚠ العهدة مااتخصمتش — هتتحسب لما تدخّل العهدة.');

        return self::SUCCESS;
    }

    /** بحث بالاسم العربي/الإنجليزي أو الكود أو الرقم */
    private function findClients(string $key)
    {
        if (ctype_digit($key)) {
            return Client::where('id', (int) $key)->get();
        }

        return Client::where('code', $key)
            ->orWhere('name', $key)
            ->orWhere('name_en', $key)
            ->get();
    }
}
