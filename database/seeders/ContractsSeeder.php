<?php

namespace Database\Seeders;

use App\Models\Channel;
use App\Models\Client;
use App\Models\ClientGroup;
use App\Models\Contract;
use App\Models\ContractClause;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * العقود الموقّعة — 22 عقد اتقروا من الـ PDF وتحوّلوا لبنود مصنّفة
 * ═══════════════════════════════════════════════════════════════
 *
 * المصدر: storage/app/data/contracts.json (متولّد من مجلد Contracts)
 * الملفات: storage/app/contracts/cNN.pdf
 *
 * ⚠️ العقد بيتربط بالسلسلة لو ليها مجموعة (Circle K، On The Run،
 * Rabbit، علوش) فكل فروعها بتورثه. وإلا بيتربط بالعميل نفسه.
 * العقود اللي مالهاش عميل في السيستم بتتسجل من غير ربط وبتظهر في
 * شاشة العقود بعلامة "محتاج ربط" — أحسن من إننا نخفيها.
 *
 * ⚠️ contracts.discount = **خصم الفاتورة بس**. الخصومات الدورية
 * والتحصيل والمساحات بنود مستقلة، ومجموعها في total_deduction_pct.
 * لو حد حط الإجمالي في discount، العميل هياخد خصمه مرتين.
 */
class ContractsSeeder extends Seeder
{
    private const SOURCE = 'data/contracts.json';

    /**
     * اسم العقد ← اسم السلسلة في شيتات 2026.
     *
     * ⚠️ **العقود اتكتبت قبل استيراد الـ455 فرع.** زمان كل سلسلة كانت
     * عميل واحد فالعقد اتربط بالاسم زي ما هو مكتوب في الـPDF. دلوقتي
     * «On The Run» سلسلة بـ33 فرع اسمها في الشيتات مختلف عن اللي في
     * العقد («Pickup» ↔ «Pick Up»، «Kwake 24» ↔ «Quick 24»…) —
     * فالمطابقة بالاسم الحرفي كانت بتسيب 14 عقد يتيم والسيستم كله
     * يقول «من غير عقد».
     *
     * ⚠️ **الربط بالسلسلة مش بفرع** — الفروع بتورث عقد سلسلتها من
     * `liveContract()`. الأسماء اللي مش هنا (رابيت، رويال هاوس،
     * ماكس ماسل…) مش سلاسل كي أكاونت في الشيتات، فبتاخد المسار
     * القديم: عميل بالاسم أو `create_client` أو يتيم يتربط بالإيد.
     */
    private const GROUP_ALIASES = [
        'On The Run' => 'On The Run',
        'بيت الجملة سوبر ماركت' => 'Bait El Gomla',
        'Circle K' => 'Circle K',
        'A Market' => 'A Market',
        'Marhaba Market' => 'Marhba',
        'Flamingo Haiper Market' => 'Flamingo',
        'Kwake 24' => 'Quick 24',
        'Life Lines' => 'Live Lines',
        'Oscar Market' => 'Oscar',
        'Pickup' => 'Pick Up',
        'W Mart' => 'W Mart',
        'ZoneMart' => 'Zone Mart',
        'الحسيني للتجارة والتوزيع' => 'Al Hussiny & New Benni',
        'Healthy Milk' => 'Healthy Elite',
    ];

    public function run(): void
    {
        $path = storage_path('app/'.self::SOURCE);

        if (! is_file($path)) {
            $this->command->warn('   ! مفيش ملف عقود — تخطّينا ContractsSeeder');

            return;
        }

        $data = json_decode((string) file_get_contents($path), true);
        $rows = $data['contracts'] ?? [];

        $made = $clauses = $linked = $orphan = 0;

        foreach ($rows as $row) {
            [$clientId, $groupId] = $this->resolveTarget($row);

            if ($clientId === null && $groupId === null) {
                $orphan++;
            } else {
                $linked++;
            }

            DB::transaction(function () use ($row, $clientId, $groupId, &$made, &$clauses) {
                // ⚠️ PromaxImportSeeder بيعمل عقد مبدئي لكل عميل من promax.json
                // (بخصم تقريبي ومن غير بنود). لو سيبناه، العميل يبقى له صفّين
                // في contracts، و hasOne بترجع الأقدم — يعني الخصم القديم هو
                // اللي بيتطبق والعقد الحقيقي بتاعنا بيتجاهل تماماً.
                // بنمسح المبدئي: هو اللي مالوش file_path.
                if ($clientId !== null) {
                    Contract::where('client_id', $clientId)->whereNull('file_path')->delete();
                }

                // العقد بيتعرّف بملفه — عشان إعادة التشغيل ماتكررش
                $contract = Contract::firstOrNew(['file_path' => 'contracts/'.$row['file']]);

                // ⚠️ **الاستب بيتشال قبل الحقيقي ما يتسجل.** أمر
                // `promax:clients --contracts` بيعمل عقد ملف-بس موقوف
                // (CTR-<code>، من غير بنود) على السلسلة. لو فضل، السلسلة
                // يبقى ليها عقدين و`hasOne` بترجّع الأقدم — يعني الاستب
                // الفاضي هو اللي بيتورث والحقيقي بيتجاهل في صمت.
                if ($groupId !== null) {
                    Contract::where('group_id', $groupId)
                        ->where('file_path', '!=', 'contracts/'.$row['file'])
                        ->where('active', false)
                        ->whereDoesntHave('contractClauses')
                        ->delete();
                }

                $contract->fill([
                    'number' => $contract->number ?? Contract::nextNumber(),
                    'client_id' => $clientId,
                    'group_id' => $groupId,
                    'chain' => $row['chain'],
                    'chain_en' => $this->shorten($row['chain_en'] ?? $row['chain'], 190),
                    'type' => $row['type'],
                    'type_key' => $this->shorten($row['type_key'] ?? null, 30),
                    'discount' => $row['discount'],
                    'total_deduction_pct' => $row['total_deduction_pct'],
                    'withholding_pct' => $row['withholding_pct'],
                    'settlement_mode' => $row['settlement_mode'],
                    'payment_days' => $row['payment_days'],
                    'terms' => $this->shorten($row['terms'], 100),
                    'starts_at' => $row['starts_at'],
                    'ends_at' => $row['ends_at'],
                    'auto_renew' => (bool) $row['auto_renew'],
                    'notice_days' => $row['notice_days'],
                    'signed_ok' => (bool) $row['signed_ok'],
                    'renewal_note' => $row['renewal_note'],
                    'termination' => $row['termination'],
                    'note' => $this->note($row),
                    // البنود النصية (اللي مش مالية) بتفضل في clauses القديم
                    'clauses' => $row['key_clauses'],
                    'active' => true,
                ])->save();

                // ⚠️ ممنوع نمسح بند ليه استحقاق **مقيّد**: المسح بيخلّي
                // contract_clause_id في الاستحقاق NULL، فالمولّد مابيلاقيهوش
                // ويعمل صف جديد لنفس الفترة — والعميل ياخد الخصم مرتين.
                // بنمسح اللي مالوش استحقاق مقيّد بس.
                $contract->contractClauses()
                    ->whereDoesntHave('dues', fn ($q) => $q->where('status', 'settled'))
                    ->delete();

                foreach ($row['clauses'] as $i => $c) {
                    ContractClause::create([
                        'contract_id' => $contract->id,
                        'kind' => $c['kind'],
                        'label' => $this->shorten($c['label'], 400),
                        'label_en' => $this->shorten($c['label_en'] ?? null, 400),
                        'pct' => $c['pct'],
                        'amount' => $c['amount'],
                        'basis' => $c['basis'],
                        'raw_amount' => $this->shorten($c['raw_amount'], 190),
                        'raw_amount_en' => $this->shorten($c['raw_amount_en'] ?? null, 190),
                        'note' => $c['note'],
                        'note_en' => $c['note_en'] ?? null,
                        'is_alternative' => (bool) $c['is_alternative'],
                        'is_uncertain' => (bool) $c['is_uncertain'],
                        'sort' => $i,
                    ]);
                    $clauses++;
                }

                $made++;
            });
        }

        $this->command->info("   • $made عقد بـ $clauses بند");
        $this->command->info("   • مربوط: $linked | محتاج ربط: $orphan");

        // الاستحقاقات الدورية على الفترات المكتملة — محسوبة ومش مقيّدة
        $dues = \App\Services\ContractDues::generate();
        $this->command->info("   • {$dues['created']} استحقاق دوري محسوب (مستني ترحيل)");

        // ⚠️ حجز الضمان بيتحسب في recalculate() — لازم نعيدها للعملاء
        // اللي عقودهم فيها حجز، وإلا العمود يفضل صفر لحد أول حركة.
        $held = 0;
        // ⚠️ الشرطين لازم يتلمّوا في where واحدة. chunkById بيضيف
        // `and id > ?` على المستوى الأعلى، ومع orWhereHas العارية
        // بيبقى: exists(A) OR (exists(B) AND id > ?) — يعني مؤشر
        // الصفحات بيقيّد فرع واحد بس واللوب مابينتهيش.
        Client::where(function ($w) {
            $w->whereHas('contract', fn ($q) => $q->where('withholding_pct', '>', 0))
                ->orWhereHas('group.contract', fn ($q) => $q->where('withholding_pct', '>', 0));
        })
            ->chunkById(100, function ($chunk) use (&$held) {
                foreach ($chunk as $client) {
                    $client->recalculate();
                    $held += (float) $client->withheld > 0 ? 1 : 0;
                }
            });
        $this->command->info("   • $held عميل عليهم حجز ضمان");
    }

    /**
     * السلسلة الأول — عقد واحد لكل فروعها. وإلا العميل نفسه.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function resolveTarget(array $row): array
    {
        // ⚠️ **جدول الأسماء الأول — قبل أي مطابقة حرفية.** لو السلسلة
        // موجودة في شيتات 2026، العقد بيتربط بيها حتى لو الـJSON قال
        // `client`: العميل الواحد بتاع زمان بقى سلسلة بفروع.
        if ($alias = self::GROUP_ALIASES[$row['link_name']] ?? null) {
            $id = ClientGroup::where('name_en', $alias)
                ->orWhere('name', $alias)
                ->value('id');

            if ($id !== null) {
                return [null, $id];
            }

            $this->command->warn('   ! سلسلة في الجدول مش في الداتابيز: '.$alias);
        }

        if ($row['link_kind'] === 'group') {
            $id = ClientGroup::where('name', $row['link_name'])->value('id');
            if ($id === null) {
                $this->command->warn('   ! سلسلة مش موجودة: '.$row['link_name']);
            }

            return [null, $id];
        }

        if ($row['link_kind'] === 'client') {
            $id = Client::where('name', $row['link_name'])->value('id');

            // ⚠️ فيه عقود موقّعة لعملاء مش في قايمة الـ 103 اللي جت من
            // الداتا التاريخية (عملاء جداد أو تعامل بدأ بعد التصدير).
            // بنعملهم عميل بالبيانات اللي في العقد بدل ما العقد يفضل معلّق.
            // ده مهم بالذات لعقود الأمانة: من غير عميل، أي توريد ليهم
            // بيتسجل مديونية كاملة وده غلط.
            if ($id === null && ! empty($row['create_client'])) {
                $id = $this->createClient($row['create_client']);
                $this->command->info('   + عميل جديد من عقده: '.$row['link_name']);
            }

            if ($id === null) {
                $this->command->warn('   ! عميل مش موجود: '.$row['link_name']);
            }

            return [$id, null];
        }

        return [null, null];
    }

    /** عميل جديد من بيانات عقده */
    private function createClient(array $info): int
    {
        $channelId = Channel::where('code', $info['channel'])->value('id');

        $client = Client::create([
            'code' => Client::nextCode(),
            'name' => $info['name'],
            'name_en' => $info['name_en'] ?? null,
            'address' => $info['address'] ?? null,
            'channel_id' => $channelId,
            'sub_channel' => $info['sub'] ?? null,
            // ⚠️ عميل من عقد بس من غير حركة في كشف الحساب — تصنيفه
            // "خامل" لحد ما تنزل عليه أول فاتورة. ممنوع نحطه "منتظم"
            // وهو معندوش ولا حركة، ده بيلخبط تقارير المخاطر.
            'category' => 'idle',
            'status' => 'active',
            'discount' => 0,
            'uses_channel_discount' => true,
            'price_list' => 'new',
            'is_new' => true,
        ]);

        return $client->id;
    }

    private function note(array $row): ?string
    {
        $parts = array_filter([
            $row['signed_note'] ? '⚠️ '.$row['signed_note'] : null,
            $row['type_full'] ?? null,
            $row['note'] ?? null,
        ]);

        return $parts ? $this->shorten(implode(' — ', $parts), 1000) : null;
    }

    /** القص بالحروف مش بالبايتات — النص عربي والقص الخام بيكسّر الحرف */
    private function shorten(?string $s, int $max): ?string
    {
        if ($s === null) {
            return null;
        }
        $s = trim($s);

        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1).'…' : $s;
    }
}
