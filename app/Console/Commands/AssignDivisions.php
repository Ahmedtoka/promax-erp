<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Client;
use App\Support\Divisions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * التسكين الأوتوماتيك في الديفيجنز  ·  ١٧ أغسطس ٢٠٢٦
 * ═══════════════════════════════════════════════════════════════
 *
 * طلب المالك: «اعمل حاجة تخلينا نسكّن كل العملاء دي أوتوماتيك
 * بسكريبت».
 *
 * ═══ ترتيب القواعد — الأقوى الأول ═══
 *
 *   1. **القناة والقسم الحاليين** — تصنيف اتعمل بالإيد قبل كده:
 *      `sub_channel = chain`       → مودرن تريد
 *      `sub_channel = convenience` → كونفينيانس
 *      قناة أونلاين                → كوماندات الاسم بتفرّق
 *      (كويك كوميرس ولا إيكوميرس)
 *   2. **اسم العميل/السلسلة** — قواميس بالبراندات المعروفة عندنا
 *      + كلمات النشاط (چيم، صيدلية، فندق، كافيه…).
 *   3. **اللي ماتعرفش** يفضل «بدون قسم» ويتقال بالاسم.
 *
 * ⚠️ **التخمين الغلط أسوأ من الفاضي** (نفس دوكترين المحافظات):
 * الفاضي بيبان في الشاشة ويتسكّن بالإيد؛ الغلط بيتحاسب بيه العميل
 * بطريقة تعامل مش بتاعته ومحدش ياخد باله.
 *
 * ⚠️ `--default=traditional_grocery` لو عايز الباقي كله يقع على
 * البقالة التقليدية — **مش الافتراضي** عن قصد.
 *
 *   php artisan promax:assign-divisions
 *   php artisan promax:assign-divisions --fix
 *   php artisan promax:assign-divisions --fix --default=traditional_grocery
 */
class AssignDivisions extends Command
{
    protected $signature = 'promax:assign-divisions
        {--fix : نفّذ — من غيرها معاينة بس}
        {--default= : قسم للي ماتعرفش (اختياري)}
        {--force : أعد تسكين اللي متسكّن خلاص كمان}';

    protected $description = 'تسكين العملاء في الديفيجنز الـ11 أوتوماتيك';

    /**
     * براندات ⇒ قسم. **بالاحتواء** بعد تطبيع، والأطول بيتفحص الأول.
     *
     * ⚠️ القواميس دي من `ChannelSeeder` (البراندات اللي عندنا فعلاً)
     * + كلمات النشاط العامة. أي براند جديد يتضاف **هنا** فيتسكّن في
     * التشغيلة الجاية.
     */
    private const BRANDS = [
        // ═══ كويك كوميرس — ديلفري ═══
        'quick_commerce' => ['rabbit', 'breadfast', 'instashop', 'talabat', 'رابيت', 'بريدفاست'],
        // ═══ إيكوميرس — أونلاين كوريير ═══
        'ecommerce' => ['amazon', 'jumia', 'noon', 'امازون', 'أمازون'],
        // ═══ مودرن تريد — ديلفري ═══
        'modern_trade' => ['gourrmet', 'gourmet', 'seoudi', 'metro', 'kazyon', 'spinneys',
            'carrefour', 'ragab', 'hyper', 'zahran', 'kheir zaman', 'fresh food', 'oscar',
            'bounjour', 'flamingo', 'exception', 'grab and go', 'خير زمان', 'سعودي', 'كازيون',
            'كارفور', 'مترو', 'راجب', 'سبينس', 'زهران',
            // إضافات بعد أول معاينة (١٧/٨) — من قايمة «بدون قسم»
            'دايلي مارت', 'ديلي مارت', 'daily mart', 'dailymart', 'dail mart', 'dail  mart',
            // ⚠️ الحسيني **مش هنا عن قصد** — ماعرفش نشاطه، والتخمين
            // الغلط بيدّي طريقة تعامل غلط. اتسكّن من صفحة سلسلته.
            'بونجور', 'جراب اند جو', 'holly mart'],
        // ═══ كونفينيانس ومحطات — كاش فان ═══
        'convenience' => ['circle k', 'on the run', 'speerr', 'kwak', 'traffic', 'grease',
            'master on the go', 'way to go', 'pickup', 'chillout', 'شل اوت', 'سيركل',
            'محطه', 'محطة', 'بنزينة', 'petrol', 'station'],
        // ═══ مكملات — ديلفري ═══
        'supplement_stores' => ['max muscle', 'supplement', 'مكملات', 'ماكس ماسل'],
        // ═══ چيمات — كاش فان ═══
        'gyms' => ['gym', 'fitness', 'golds', 'جيم', 'چيم', 'فيتنس', 'لياقة',
            'crossfit', 'كروس فيت', 'health', 'باديل', 'padel',
            // إضافات بعد أول معاينة — «muscle» بييجي **بعد** قاموس
            // المكملات، فـMax Muscle بياخد قسمه الأدق الأول
            'muscle', 'power fit', 'h20'],
        // ═══ صيدليات — كاش فان ═══
        'pharmacies' => ['pharmacy', 'pharma', 'صيدلية', 'صيدليه'],
        // ═══ فنادق — كاش فان ═══
        'hotels' => ['hotel', 'فندق', 'اوتيل', 'أوتيل', 'resort', 'منتجع'],
        // ═══ كافيهات — كاش فان ═══
        'cafe_chains' => ['cafe', 'caffe', 'coffee', 'كافيه', 'كوفي', 'قهوة', 'كافي'],
        // ═══ مطاعم وكاترينج — كاش فان ═══
        'horeca' => ['restaurant', 'catering', 'مطعم', 'كاترينج', 'كافتيريا', 'cafeteria'],
        // ═══ بقالة تقليدية وجملة — كاش فان ═══
        'traditional_grocery' => ['gomla', 'جملة', 'جمله', 'بقالة', 'بقاله', 'سوبر ماركت',
            'supermarket', 'market', 'ماركت', 'grocery'],
    ];

    public function handle(): int
    {
        $fix = (bool) $this->option('fix');
        $default = $this->option('default');

        if ($default !== null && ! Divisions::has($default)) {
            $this->error("  «{$default}» مش قسم معروف. الأقسام: ".implode(' · ', Divisions::keys()));

            return self::FAILURE;
        }

        $q = Client::with(['channel', 'group'])
            ->where('status', '!=', 'rejected');

        if (! $this->option('force')) {
            // ⚠️ المتسكّن خلاص مايتلمسش — التسكين اليدوي قرار إداري
            // والسكريبت مايدهسش عليه (زي دوكترين الاستيراد بالحرف)
            $q->whereNull('division');
        }

        $clients = $q->get();

        $this->line('');
        $this->line('  عملاء للتسكين: '.$clients->count());
        $this->line('');

        $plan = [];
        $manual = [];

        foreach ($clients as $c) {
            $div = $this->classify($c) ?? $default;

            if ($div === null) {
                $manual[] = $c;

                continue;
            }

            $plan[$div][] = $c;
        }

        // ═══ المعاينة — بالقسم وطريقة التعامل ═══
        foreach (Divisions::keys() as $key) {
            $rows = $plan[$key] ?? [];

            if ($rows === []) {
                continue;
            }

            $this->line(sprintf('  ══ %s  ·  %s  ·  %d عميل ══',
                Divisions::label($key), Divisions::fulfillmentLabel($key), count($rows)));

            foreach (array_slice($rows, 0, 8) as $c) {
                $this->line('      '.mb_substr($c->displayName(), 0, 40));
            }

            if (count($rows) > 8) {
                $this->line('      … و'.(count($rows) - 8).' كمان');
            }
        }

        $planned = array_sum(array_map('count', $plan));
        $this->line('');
        $this->line('  هيتسكّن: '.$planned.'  ·  بدون قسم: '.count($manual));

        if ($manual !== []) {
            $this->warn('  ⚠ دول ماتعرفوش — اتسكّنهم بالإيد أو ضيف براندهم في القاموس:');

            foreach (array_slice($manual, 0, 20) as $c) {
                $this->warn('      #'.$c->id.'  '.mb_substr($c->displayName(), 0, 40));
            }

            if (count($manual) > 20) {
                $this->warn('      … و'.(count($manual) - 20).' كمان');
            }
        }

        if (! $fix) {
            $this->comment('  (معاينة — ضيف --fix للتسكين)');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($plan) {
            foreach ($plan as $div => $rows) {
                Client::whereIn('id', collect($rows)->pluck('id'))
                    ->update(['division' => $div]);
            }
        });

        $this->info('  ✓ اتسكّن '.$planned.' عميل.');
        $this->comment('  الشاشة: /erp/divisions');

        return self::SUCCESS;
    }

    /**
     * تصنيف عميل واحد — `null` لو مفيش قاعدة واثقة.
     *
     * ⚠️⚠️ **البراند أولاً، و`sub_channel` احتياطي** (تصحيح بعد أول
     * تشغيلة ١٧/٨). الترتيب الأول كان بالعكس — و`sub_channel=chain`
     * متكتوب في الداتا على **أي حاجة ليها فروع** (سيركل · ماكس
     * ماسل · الحسيني) بمعنى «سلسلة» مش «هايبر ماركت». النتيجة:
     * ٦١٤ من ٧٠١ راحوا مودرن تريد، والمكملات خدت **صفر** وسيركل
     * كيه الـ١٩٩ فرع اتبلعوا. الاسم أدق من تصنيف قديم اتكتب بمعنى
     * مختلف.
     */
    private function classify(Client $c): ?string
    {
        // ═══ 1. البراند — بتاعه وبتاع سلسلته ═══
        $hay = mb_strtolower(implode(' ', array_filter([
            $c->name, $c->name_en, $c->group?->name, $c->group?->name_en,
        ])));

        foreach (self::BRANDS as $div => $words) {
            foreach ($words as $w) {
                if (str_contains($hay, mb_strtolower($w))) {
                    return $div;
                }
            }
        }

        // ═══ 2. قناة أونلاين من غير براند معروف → إيكوميرس ═══
        if ($c->channel?->code === Channel::ONLINE) {
            return 'ecommerce';
        }

        // ═══ 3. `sub_channel` كاحتياطي أخير ═══
        //
        // ⚠️ `chain` **مش** إشارة مودرن تريد في الداتا دي — بيتساب
        // للاسم. `convenience` بس اللي لسه إشارة موثوقة.
        if ($c->sub_channel === 'convenience') {
            return 'convenience';
        }

        return null;
    }
}
