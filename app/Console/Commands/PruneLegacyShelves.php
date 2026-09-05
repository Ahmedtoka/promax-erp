<?php

namespace App\Console\Commands;

use App\Models\BatchLocation;
use App\Models\Location;
use App\Models\Warehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * مسح أرفف النظام القديم (٦/٩/٢٠٢٦) — قرار المالك بعد الجرد الجديد
 * ═══════════════════════════════════════════════════════════════
 *
 * بعد ما المخزن اتنظم على استاندات A–J (promax:regard)، بلوكات
 * النظام القديم (M01 شهر · Q01 ربع سنة · Y01 سنة) وأي رف قديم زي
 * AREA05 بقوا زبالة على الشاشة. الأمر ده بيمسح من المخزن أي رف:
 *
 *   • كوده **مش** من أكواد الاستاندات (A01..J05)
 *   • **وفاضي تماماً** — رف عليه بضاعة بيترفض ويتبلغ عنه بالاسم
 *     (انقل بضاعته الأول من شاشة الأرفف وبعدين شغّل الأمر تاني).
 *
 * صفوف batch_locations بتاعته (كلها صفر) بتتمسح معاه بالـcascade،
 * وأوامر التجهيز القديمة اللي بتشاور عليه بتاخد null (nullOnDelete)
 * — التاريخ مش بيتكسر.
 *
 * التشغيل:
 *   php artisan promax:prune-shelves            ← معاينة
 *   php artisan promax:prune-shelves --apply    ← المسح الفعلي
 */
class PruneLegacyShelves extends Command
{
    protected $signature = 'promax:prune-shelves {--warehouse= : id المخزن (الافتراضي: المعادي)} {--apply : نفّذ فعلاً}';

    protected $description = 'مسح أرفف النظام القديم (M/Q/Y بلوكات، AREA…) — الفاضية بس، واستاندات A–J مالهاش دعوة';

    public function handle(): int
    {
        $wh = $this->option('warehouse')
            ? Warehouse::find((int) $this->option('warehouse'))
            : Warehouse::where('name', 'like', '%معادي%')->first();

        if ($wh === null) {
            $this->error('❌ ملقتش المخزن — حدده بـ --warehouse=ID');

            return self::FAILURE;
        }

        // أكواد الاستاندات المحمية A01..J05
        $keep = [];
        foreach (range('A', 'J') as $stand) {
            for ($lvl = 1; $lvl <= 5; $lvl++) {
                $keep[] = $stand.'0'.$lvl;
            }
        }

        $legacy = Location::where('warehouse_id', $wh->id)
            ->whereNotIn('code', $keep)
            ->orderBy('code')->get();

        if ($legacy->isEmpty()) {
            $this->info("✅ {$wh->displayName()}: مفيش أرفف قديمة — نضيف.");

            return self::SUCCESS;
        }

        $deletable = [];
        $blocked = [];

        foreach ($legacy as $loc) {
            $qty = (int) $loc->batchLocations()->sum('qty');

            if ($qty > 0) {
                $blocked[] = "{$loc->code} — عليه ".number_format($qty).' قطعة، انقلها الأول';
            } else {
                $deletable[] = $loc;
            }
        }

        $this->info("🏭 {$wh->displayName()}: ".count($deletable).' رف هيتمسح'
            .($blocked !== [] ? ' · '.count($blocked).' مترفض' : ''));

        foreach ($deletable as $loc) {
            $this->line('   🗑 '.$loc->code.($loc->life_band ? ' ('.$loc->bandLabel().')' : ''));
        }
        foreach ($blocked as $msg) {
            $this->warn('   ⛔ '.$msg);
        }

        if (! $this->option('apply')) {
            $this->comment('👁 معاينة بس — التنفيذ: php artisan promax:prune-shelves --apply');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($deletable) {
            foreach ($deletable as $loc) {
                // صفوفه في batch_locations كلها صفر (اتفحصت فوق) —
                // بتتمسح بالـcascade مع الرف
                $loc->delete();
            }
        });

        $this->info('✅ اتمسح '.count($deletable).' رف قديم.');

        return self::SUCCESS;
    }
}
