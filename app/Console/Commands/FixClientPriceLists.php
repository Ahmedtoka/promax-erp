<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Contract;
use App\Models\PriceList;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * تصحيح قوايم أسعار العملاء (2026-08-07)
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **ليه الأمر ده موجود:** فورم العميل كان بيحفظ عمود `price_list`
 * النصي (`old`/`new`) بس ومبيكتبش `price_list_id` أبداً، بينما
 * الفاتورة بتتحاسب من `price_list_id` (عبر `Pricing::listRowFor`).
 * يعني كل عميل اتعمل بعد مايجريشن القوايم المسمّاة بيبقى `id` بتاعه
 * فاضي — والفاتورة بتاخد القايمة الافتراضية بدل اللي المستخدم
 * اختارها. الفورم اتصلح، والأمر ده بيصلّح اللي اتسجّل قبل الإصلاح.
 *
 * بيملّي `price_list_id` من عمود `price_list` النصي للعملاء والعقود
 * اللي الـid بتاعهم فاضي. مابيلمسش أي صف الـid بتاعه متملّي —
 * الاختيار الصريح أقوى من الاستنتاج.
 *
 *   php artisan promax:fix-price-lists            # تقرير بس
 *   php artisan promax:fix-price-lists --apply    # التنفيذ
 */
class FixClientPriceLists extends Command
{
    protected $signature = 'promax:fix-price-lists {--apply : التنفيذ الفعلي}';

    protected $description = 'ملء price_list_id للعملاء والعقود من عمود القايمة النصي';

    public function handle(): int
    {
        $lists = PriceList::all()->keyBy('code');
        $default = PriceList::default();

        if ($default === null) {
            $this->error('مفيش قايمة أسعار افتراضية — اعمل واحدة الأول من شاشة التسعير.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $this->line('  القايمة الافتراضية: '.$default->displayName().' ('.$default->code.')');
        $this->newLine();

        $fixed = ['clients' => 0, 'contracts' => 0];
        $fallback = 0;

        DB::transaction(function () use ($lists, $default, $apply, &$fixed, &$fallback) {
            // ═══ العملاء ═══
            foreach (Client::whereNull('price_list_id')->get() as $client) {
                $row = $lists->get($client->price_list) ?? $default;

                if (! $lists->has($client->price_list)) {
                    $fallback++;
                }

                $this->line(sprintf(
                    '     %s %-28s %s ← %s',
                    $client->code,
                    mb_substr($client->name, 0, 28),
                    $client->price_list ?: '—',
                    $row->displayName(),
                ));

                if ($apply) {
                    // ⚠️ `updateQuietly` — الملء ده تصحيح تقني مش حركة
                    // يوزر، وتسجيله في سجل الحركة كان هيغرقه بمئات
                    // الصفوف «قايمة السعر اتغيرت» في ثانية واحدة.
                    $client->updateQuietly(['price_list_id' => $row->id]);
                }

                $fixed['clients']++;
            }

            // ═══ العقود — بنفس المنطق، والعقد بيغلب على العميل ═══
            foreach (Contract::whereNull('price_list_id')->whereNotNull('price_list')->get() as $contract) {
                $row = $lists->get($contract->price_list);

                if ($row === null) {
                    continue;   // نص مش معروف — سيبه للمراجعة اليدوية
                }

                if ($apply) {
                    $contract->updateQuietly(['price_list_id' => $row->id]);
                }

                $fixed['contracts']++;
            }
        });

        $this->newLine();
        $this->info(sprintf(
            '  %s: %d عميل و %d عقد.',
            $apply ? 'اتصلح' : 'محتاج تصحيح',
            $fixed['clients'],
            $fixed['contracts'],
        ));

        if ($fallback > 0) {
            $this->warn("  ⚠ $fallback عميل عمود القايمة بتاعه فاضي أو مش معروف — اتحطّوا على الافتراضية. راجعهم.");
        }

        if (! $apply && ($fixed['clients'] + $fixed['contracts']) > 0) {
            $this->newLine();
            $this->line('  💡 ده تقرير بس. التنفيذ: <fg=yellow>--apply</>');
        }

        return self::SUCCESS;
    }
}
