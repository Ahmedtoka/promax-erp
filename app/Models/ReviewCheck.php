<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * علامة مراجعة بند واحد على كيان واحد — «مطابق للورق» أو «فيه مشكلة».
 *
 * ⚠️ صف واحد لكل (كيان، بند): العلامة الجديدة بتكتب فوق القديمة،
 * والتاريخ الكامل (مين غيّر إيه) في سجل النشاط — الموديل في AUDITED.
 */
class ReviewCheck extends Model
{
    /**
     * علامة مراجعة مستند (فاتورة/أمر) ليها سطرها المقروء من `DocReview` —
     * فالمراقب العام مايكتبش «ReviewCheck #5» جنبه (١٢/١١).
     */
    public function quietInActivity(): bool
    {
        return in_array($this->entity_type, ['invoice', 'po', 'return'], true);
    }

    public const OK = 'ok';
    public const ISSUE = 'issue';

    /** المرحلة ١ — بيانات العميل، بترتيب العرض */
    public const DATA_ITEMS = ['name', 'address', 'chain', 'mother', 'cr', 'price_list'];

    /** المرحلة ٢ — قبل الشهور (اللي بتتولّد من `months()`) وبعدها رصيد أول المدة */
    public const MONEY_ITEMS = ['prices', 'pos'];

    public const OPENING_ITEM = 'opening';

    /** المرحلة ٣ */
    public const WAREHOUSE_ITEMS = ['zeroed', 'entered'];

    public const REP_ITEMS = ['zeroed', 'issued'];

    public const ENTITY_TYPES = ['client', 'group', 'warehouse', 'rep'];

    /** أول شهر بيتطابق — بداية الشغل على السيستم */
    public const FIRST_MONTH = '2026-08';

    protected $fillable = [
        'entity_type', 'entity_id', 'phase', 'item', 'status', 'note',
        'paper_net', 'paper_balance', 'system_net', 'system_balance', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'paper_net' => 'decimal:2',
            'paper_balance' => 'decimal:2',
            'system_net' => 'decimal:2',
            'system_balance' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * الشهور اللي بتتطابق: من أول شهر لحد آخر شهر **خلص** — الشهر الجاري
     * لسه بيتحرك فمالوش تقفيل.
     *
     * @return list<string> YYYY-MM
     */
    public static function months(): array
    {
        $out = [];
        $m = \Illuminate\Support\Carbon::createFromFormat('Y-m-d', self::FIRST_MONTH.'-01')->startOfMonth();
        $last = today()->startOfMonth()->subMonth();

        while ($m->lte($last)) {
            $out[] = $m->format('Y-m');
            $m->addMonth();
        }

        return $out;
    }

    /** كل بنود المرحلة ٢ بالترتيب */
    public static function moneyItems(): array
    {
        return array_merge(self::MONEY_ITEMS, array_map(fn ($m) => 'm:'.$m, self::months()), [self::OPENING_ITEM]);
    }

    /**
     * بنود بيانات العميل الفاضية في السيستم (٦/١١ — طلب المالك).
     *
     * البند الفاضي مش «✗ غلط» — ده «ناقص»: المراجع بيروح يكمّله وبيرجع
     * يلاقي ✓ اتفعّلت. فـ✓ مقفولة عليه لحد ما القيمة تتكتب.
     */
    public static function missingItems(\App\Models\Client $c): array
    {
        $out = [];
        if (trim((string) $c->address_ar) === '' && trim((string) $c->address) === '') {
            $out[] = 'address';
        }
        if (trim((string) $c->commercial_register) === '') {
            $out[] = 'cr';
        }
        if (\App\Services\Pricing::listRowFor($c) === null) {
            $out[] = 'price_list';
        }

        return $out;
    }

    public static function phaseOf(string $entityType, string $item): ?string
    {
        if ($entityType === 'client' && in_array($item, self::DATA_ITEMS, true)) {
            return 'data';
        }
        if (in_array($entityType, ['client', 'group'], true) && in_array($item, self::moneyItems(), true)) {
            return 'money';
        }
        if ($entityType === 'warehouse' && in_array($item, self::WAREHOUSE_ITEMS, true)) {
            return 'stock';
        }
        if ($entityType === 'rep' && in_array($item, self::REP_ITEMS, true)) {
            return 'stock';
        }

        return null;
    }

    /**
     * علامات مجموعة كيانات: [entity_id => [item => ReviewCheck]]
     *
     * @param  iterable<int>  $ids
     */
    public static function mapFor(string $entityType, iterable $ids): array
    {
        $out = [];

        foreach (static::with('user:id,name,name_en')->where('entity_type', $entityType)
            ->whereIn('entity_id', collect($ids)->all())->get() as $c) {
            $out[$c->entity_id][$c->item] = $c;
        }

        return $out;
    }
}
