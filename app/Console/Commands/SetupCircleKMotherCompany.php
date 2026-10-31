<?php

namespace App\Console\Commands;

use App\Models\ClientGroup;
use App\Models\Contract;
use App\Models\MotherCompany;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * الشركة الأم Circle K وسلاسلها (٣١ أكتوبر ٢٠٢٦)
 * ═══════════════════════════════════════════════════════════════
 *
 *   php artisan promax:circle-k-mother            ← معاينة بس
 *   php artisan promax:circle-k-mother --apply    ← تنفيذ
 *
 * بيعمل الشركة الأم، وبيعمل كل سلسلة من قايمة المالك بتفاصيل سلسلة
 * Circle K الحقيقية (القناة والقسم والملاحظات والحالة والمراجعة) ونسخة
 * من عقدها ببنوده، وبيربطها بالشركة.
 *
 * ⚠️ العقد بيتنسخ عشان الفرع اللي يتنقل بعدين لسلسلة جديدة يفضل وارث نفس
 * القايمة والشروط — من غيره سعره بيتغير في صمت.
 *
 * ⚠️ مابيلمسش السلسلة الأصلية ولا عقدها ولا أي عميل. السلسلة اللي اسمها
 * موجود أصلاً مابتتعدلش تفاصيلها، بتتربط بس لو مش تابعة لشركة تانية —
 * فتكرار التشغيل آمن.
 */
class SetupCircleKMotherCompany extends Command
{
    protected $signature = 'promax:circle-k-mother
                            {--apply : التنفيذ الفعلي — من غيره معاينة بس}';

    protected $description = 'الشركة الأم Circle K + سلاسلها بتفاصيل وعقد سلسلة Circle K الأصلية';

    private const MOTHER = 'Circle K';

    /** بالحرف من قايمة المالك — «Circle K Trade Top» كانت مكررة فيها */
    private const CHAINS = [
        'Circle K Top Seven', 'Circle K Mexi Retail', 'Circle K Trade More', 'Circle K TD',
        'Circle K Mega Crop', 'Circle K Trade Top', 'Circle K YNWA', 'Circle K Trillium',
        'Circle K Sira', 'Circle K Dolfen', 'Circle K LUCA', 'Circle K Lavida',
        'Circle K Cloud Trade', 'Circle K AON', 'Circle K Trans Jet', 'Circle K Trade Well',
        'Circle K MMC', 'Circle K SA Group', 'Circle K Rawasy', 'Circle K Mid Town',
        'Circle K El Amid', 'Circle K Perfectra', 'Circle K Triangle', 'Circle K My Mart',
        'Circle K BSG Accadimy', 'Circle K Appel Green', 'Circle K Jador', 'Circle K Fathy Mondy Co.',
        'Circle K Lego Hospitality', 'Circle K Volos Café', 'Circle K Trade Mix - Mix Trade', 'Circle K GT',
        'Circle K Auto Land', 'Circle K TMT',
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        if (! Schema::hasTable('mother_companies') || ! Schema::hasColumn('client_groups', 'mother_company_id')) {
            $this->error('شغّل مايجريشن 2026_10_31_000100_mother_companies الأول.');

            return self::FAILURE;
        }

        $sources = ClientGroup::where('name', self::MOTHER)->orWhere('name_en', self::MOTHER)->get();

        if ($sources->count() !== 1) {
            $this->error('لازم سلسلة واحدة بالظبط اسمها Circle K — لقيت '.$sources->count().'. مفيش حاجة اتغيرت.');

            return self::FAILURE;
        }

        $source = $sources->first();
        $contract = $source->contract()->with('contractClauses')->first();

        $this->info($apply ? '═══ تنفيذ ═══' : '═══ معاينة — مفيش حاجة هتتكتب ═══');
        $this->line("السلسلة الأصلية: #{$source->id} {$source->code} — {$source->name} / {$source->name_en}");
        $this->line('القناة: '.($source->channel_id ?? '—').' · القسم: '.($source->sub_channel ?? '—').' · نشطة: '.($source->active ? 'نعم' : 'لا'));
        $this->line($contract
            ? "العقد: {$contract->number} · بند: {$contract->contractClauses->count()} · خصم: {$contract->discount} · قايمة: ".($contract->price_list_id ?? $contract->price_list ?? '—').' · ينتهي: '.($contract->ends_at?->toDateString() ?? '—')
            : '⚠️ السلسلة الأصلية مالهاش عقد — السلاسل هتتعمل من غير عقد.');

        $counts = ['created' => 0, 'linked' => 0, 'already' => 0, 'conflict' => 0];

        DB::transaction(function () use ($apply, $source, $contract, &$counts) {
            $mother = MotherCompany::where('name', self::MOTHER)->orWhere('name_en', self::MOTHER)->first();

            if ($mother === null) {
                $this->line('شركة أم جديدة: '.self::MOTHER);

                if ($apply) {
                    $mother = MotherCompany::create([
                        'code' => MotherCompany::nextCode(self::MOTHER),
                        'name' => self::MOTHER, 'name_en' => self::MOTHER, 'active' => true,
                    ]);
                }
            }

            foreach (self::CHAINS as $name) {
                $existing = ClientGroup::where('name', $name)->orWhere('name_en', $name)->first();

                if ($existing === null) {
                    $counts['created']++;
                    $this->line("  + سلسلة جديدة: $name");

                    if ($apply) {
                        $this->createChain($name, $source, $contract, $mother);
                    }
                } elseif ($mother !== null && (int) $existing->mother_company_id === (int) $mother->id) {
                    $counts['already']++;
                    $this->line("  = مربوطة خلاص: $name");
                } elseif ($existing->mother_company_id !== null) {
                    $counts['conflict']++;
                    $this->warn("  ! تابعة لشركة تانية، اتسابت: $name");
                } else {
                    $counts['linked']++;
                    $this->line("  ~ موجودة، هتتربط بس: $name");

                    if ($apply) {
                        $existing->update(['mother_company_id' => $mother->id]);
                    }
                }
            }
        });

        $this->newLine();
        $this->info("جديدة: {$counts['created']} · اتربطت: {$counts['linked']} · مربوطة خلاص: {$counts['already']} · تعارض: {$counts['conflict']}");

        if (! $apply) {
            $this->warn('معاينة بس. للتنفيذ زوّد --apply');
        }

        return self::SUCCESS;
    }

    private function createChain(string $name, ClientGroup $source, ?Contract $contract, MotherCompany $mother): void
    {
        $chain = ClientGroup::create([
            'code' => ClientGroup::nextCode($name),
            'name' => $name,
            'name_en' => $name,
            'channel_id' => $source->channel_id,
            'mother_company_id' => $mother->id,
            'sub_channel' => $source->sub_channel,
            'notes' => $source->notes,
            'active' => $source->active,
            'reviewed_at' => $source->reviewed_at,
        ]);

        if ($contract === null) {
            return;
        }

        // رقم مستند جديد واسم السلسلة الجديدة — باقي الشروط زي الأصل بالحرف
        $copy = $contract->replicate();
        $copy->fill(['group_id' => $chain->id, 'number' => Contract::nextNumber(), 'chain' => $name, 'chain_en' => $name]);
        $copy->save();

        foreach ($contract->contractClauses as $clause) {
            $row = $clause->replicate();
            $row->contract_id = $copy->id;
            $row->save();
        }
    }
}
