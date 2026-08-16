<?php

namespace App\Console\Commands;

use App\Models\Zone;
use App\Support\Governorates;
use Illuminate\Console\Command;

/**
 * ═══════════════════════════════════════════════════════════════
 * ربط المناطق اليتيمة بمحافظاتها  ·  ١٥ أغسطس ٢٠٢٦
 * ═══════════════════════════════════════════════════════════════
 *
 * بلاغ المالك: الأبلكيشن بيقول «من غير محافظة» على كل مناطق المندوب،
 * والـERP بيوري المحافظات سليمة.
 *
 * ═══ السبب ═══
 *
 * `zones.governorate` فاضي في ١٨ منطقة. دول مناطق اتعملت بالإيد (زرار
 * «منطقة جديدة») أو جت من استيراد قديم، والخانة مش إجبارية وقتها.
 * المرجع الجغرافي سليم؛ اللي ناقص هو **الربط**.
 *
 * والدليل إن السيستم متوقّع الحالة دي: شاشة `/erp/zones` فيها قسم
 * `_none` بيعرض المناطق بلا محافظة لوحدها.
 *
 * ═══ ليه بيستخدم `Governorates::guessFromZone` ═══
 *
 * ⚠️ **مش قاموس جديد.** الدالة دي موجودة في `App\Support\Governorates`
 * ومستخدمة في مسارات تانية (الاستيراد، اقتراح العنوان). كتابة قاموس
 * تاني هنا كان معناه مصدرين للحقيقة يفترقوا مع أول اسم يتضاف لواحد
 * منهم — والفرق مايبانش غير لما منطقة تتصنّف صح في شاشة وغلط في
 * التانية. أي اسم ناقص يتضاف **هناك** فيستفيد منه كل المسارات.
 *
 * ⚠️ **الاسم الملتبس مابيتخمّنش**: «بولاق» أبو العلا (القاهرة) ولا
 * الدكرور (الجيزة)؟ و«الحي الثاني» في أكتوبر والشروق والعبور. الدالة
 * بترجّع `null` والأمر بيسيبها **يدوي** ويقولها بالاسم.
 *
 *   php artisan promax:zone-govs
 *   php artisan promax:zone-govs --fix
 */
class FixZoneGovernorates extends Command
{
    protected $signature = 'promax:zone-govs {--fix : نفّذ — من غيرها معاينة بس}';

    protected $description = 'ربط المناطق اللي بلا محافظة بمحافظاتها';

    public function handle(): int
    {
        $fix = (bool) $this->option('fix');

        $orphans = Zone::where(fn ($q) => $q->whereNull('governorate')
            ->orWhere('governorate', ''))->orderBy('id')->get();

        if ($orphans->isEmpty()) {
            $this->info('كل المناطق مربوطة بمحافظات. ✅');

            return self::SUCCESS;
        }

        $this->line('');
        $this->line('  مناطق بلا محافظة: '.$orphans->count());
        $this->line('');

        $plan = [];
        $manual = [];

        foreach ($orphans as $z) {
            // ⚠️ الاسمين — العربي والإنجليزي — عشان المنطقة المسمّاة
            // بالإنجليزي بس تتلقط برضه
            $key = Governorates::guessFromZone($z->name, $z->name_en);

            if ($key === null) {
                $manual[] = $z;

                continue;
            }

            $plan[] = ['zone' => $z, 'gov' => $key];

            $this->line(sprintf('  #%-5d %-28s → %s',
                $z->id, mb_substr((string) $z->name, 0, 28),
                Governorates::label($key)));
        }

        if ($manual !== []) {
            $this->line('');
            $this->warn('  ⚠ محتاجة قرار منك — الاسم ملتبس أو مش في قاموس `Governorates`:');

            foreach ($manual as $z) {
                $this->warn(sprintf('      #%-5d %s', $z->id, $z->name));
            }

            $this->warn('    حدّدها من /erp/zones، أو ضيف الاسم في');
            $this->warn('    `App\Support\Governorates::guessFromZone` فيستفيد منه كل السيستم.');
        }

        $this->line('');
        $this->line('  هيتربط: '.count($plan).'  ·  يدوي: '.count($manual));

        if (! $fix) {
            $this->comment('  (معاينة — ضيف --fix للتنفيذ)');

            return self::SUCCESS;
        }

        foreach ($plan as $p) {
            $p['zone']->update(['governorate' => $p['gov']]);
        }

        $this->info('  ✓ اتربط '.count($plan).' منطقة.');
        $this->comment('  في الأبلكيشن: اسحب لتحت (refresh) عشان يجيب الحمولة الجديدة.');

        return self::SUCCESS;
    }
}
