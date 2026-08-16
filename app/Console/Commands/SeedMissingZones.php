<?php

namespace App\Console\Commands;

use App\Models\Zone;
use App\Support\Governorates;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * المناطق الناقصة  ·  ١٦ أغسطس ٢٠٢٦
 * ═══════════════════════════════════════════════════════════════
 *
 * بعد `promax:gov-zones` فضل ٧١ عميل تحت مناطق اسمها اسم محافظة.
 * السبب مش المطابقة — السبب إن **المنطقة اللي العميل فيها مش
 * موجودة في الجدول أصلاً**، فمفيش وجهة ينتقل ليها.
 *
 * الأمر ده بيعمل المناطق الناقصة، وبعده `promax:gov-zones --fix`
 * بيسكّن العملاء فيها لوحده.
 *
 * ═══ ليه ليستة مكتوبة بالإيد مش استخراج من العناوين ═══
 *
 * ⚠️ **إنشاء منطقة من نص عنوان بيولّد خردة.** «شارع 30 متفرع من
 * شارع القاهرة» و«FOOD COURT» و«CAIRO ALEX RD K.M(27)» كلها
 * عناوين حقيقية ومفيش فيها اسم منطقة. الاستخراج الآلي كان هيعمل
 * منها مناطق بأسماء متكررة ومكتوبة غلط، وتنضيفها بعدين أصعب من
 * المشكلة الأصلية.
 *
 * ⚠️ **كل صف هنا حي مصري حقيقي معروف** — مش تخمين من عنوان مقصوص.
 * اللي عنوانه مايدلّش على حي معروف **مش موجود في الليستة** وبيفضل
 * «يدوي» عن قصد.
 *
 * ⚠️ **`name_en` مش زينة.** نص العملاء أسماؤهم إنجليزي (`Manial`
 * · `Maamoura1`)، والمطابقة بتقرا الاسمين. المنطقة اللي ليها اسم
 * عربي بس مابتلقطش العميل الإنجليزي.
 *
 *   php artisan promax:seed-zones
 *   php artisan promax:seed-zones --fix
 */
class SeedMissingZones extends Command
{
    protected $signature = 'promax:seed-zones {--fix : أنشئ — من غيرها معاينة بس}';

    protected $description = 'إنشاء المناطق الناقصة اللي العملاء مستنيينها';

    /**
     * [الاسم العربي، الاسم الإنجليزي، مفتاح المحافظة]
     *
     * ⚠️ **الترتيب مقصود**: الأول اللي بيفك أكبر عدد عملاء.
     */
    private const ZONES = [
        // ═══ الطريق الصحراوي — أكبر تجمّع (١١ عميل) ═══
        //
        // ⚠️ محطات بنزين وكارتات على الطريق. مش تابعة لأي حي، ومن
        // غير منطقة ليها مايينفعش يتعمل لها خط سير — المندوب بيلفّ
        // ٢٠٠ كيلو من غير خطة. البدائل بتاعتها متسجّلة في
        // `AuditGovNamedZones::ALIASES` ومستنية الاسم ده بالحرف.
        ['طريق مصر إسكندرية الصحراوي', 'Cairo–Alex Desert Road', 'giza'],

        // ═══ القاهرة الكبرى ═══
        ['المنيل', 'Manial', 'cairo'],
        ['السيدة زينب', 'El Sayeda Zeinab', 'cairo'],
        ['السلام', 'El Salam', 'cairo'],
        ['العاصمة الإدارية', 'New Administrative Capital', 'cairo'],
        ['القرية الذكية', 'Smart Village', 'giza'],
        ['مسطرد', 'Mostorod', 'qalyubia'],

        // ═══ الإسكندرية — أحياء حقيقية مش موجودة في الجدول ═══
        ['الحضرة', 'Hadara', 'alexandria'],
        ['الورديان', 'Wardian', 'alexandria'],
        ['الفلكي', 'Falaki', 'alexandria'],
        ['ونجت', 'Wingate', 'alexandria'],
        ['رأس التين', 'Ras El Tin', 'alexandria'],
        ['المعمورة', 'Maamoura', 'alexandria'],
        ['زيزينيا', 'Zezinia', 'alexandria'],
        ['أبو سليمان', 'Abu Soliman', 'alexandria'],
    ];

    public function handle(): int
    {
        $fix = (bool) $this->option('fix');

        $this->line('');

        $todo = [];

        foreach (self::ZONES as [$ar, $en, $gov]) {
            // ⚠️ **الحارس**: منطقة بنفس الاسم (عربي أو إنجليزي)
            // موجودة خلاص؟ نسيبها. الأمر ده هيتشغّل أكتر من مرة —
            // على الستيچينج وعلى اللايف — ولازم يبقى آمن في التكرار.
            $exists = Zone::where('name', $ar)
                ->orWhere('name_en', $en)
                ->exists();

            if ($exists) {
                $this->line(sprintf('  <fg=gray>• %-28s موجودة</>', $ar));

                continue;
            }

            // ⚠️ المحافظة لازم تكون في المرجع — الخطأ المطبعي في
            // المفتاح كان هيعمل منطقة يتيمة تظهر في قسم `_none`
            if (! Governorates::has($gov)) {
                $this->error("  ✗ {$ar}: مفتاح محافظة غلط ({$gov})");

                return self::FAILURE;
            }

            $todo[] = [$ar, $en, $gov];
            $this->line(sprintf('  + %-28s %s', $ar, Governorates::label($gov)));
        }

        $this->line('');
        $this->line('  هتتعمل: '.count($todo));

        if ($todo === []) {
            $this->info('  كل المناطق موجودة. ✅');

            return self::SUCCESS;
        }

        if (! $fix) {
            $this->comment('  (معاينة — ضيف --fix للإنشاء)');

            return self::SUCCESS;
        }

        // ⚠️ **ترانزاكشن واحدة.** `nextCode()` بتقرا أكبر كود موجود؛
        // لو الأمر وقع في النص كنا هنسيب مناطق نصها متعمل وأكواد
        // متفرقة، والتشغيلة الجاية تكمّل من مكان مش متوقع.
        DB::transaction(function () use ($todo) {
            foreach ($todo as [$ar, $en, $gov]) {
                Zone::create([
                    'code' => Zone::nextCode(),
                    'name' => $ar,
                    'name_en' => $en,
                    'governorate' => $gov,
                    'active' => true,
                ]);
            }
        });

        $this->info('  ✓ اتعمل '.count($todo).' منطقة.');
        $this->comment('  دلوقتي: php artisan promax:gov-zones --fix');

        return self::SUCCESS;
    }
}
