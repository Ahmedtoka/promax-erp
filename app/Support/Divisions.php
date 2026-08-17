<?php

namespace App\Support;

/**
 * ═══════════════════════════════════════════════════════════════
 * الديفيجنز — التقسيمة التجارية للعملاء  ·  ١٧ أغسطس ٢٠٢٦
 * ═══════════════════════════════════════════════════════════════
 *
 * قرار المالك (جدول بالحرف): ١١ قسم تجاري، وكل قسم ليه **طريقة
 * تعامل** واحدة بتحدد إزاي البضاعة بتوصل:
 *
 *   • cashvan  — عهدة بتتحمّل + خط سير بيغطي مناطق
 *   • delivery — PO بيتبعت من العميل أو من عندنا
 *   • online   — كوريير أونلاين
 *
 * ⚠️⚠️ **الديفيجن مش بديل القناة.** القناة (`channels`) هي محرك
 * **التسعير والسكوبينج**: خصم القناة، مدير القناة، عملاء البروموتر
 * — كلها فلوهات في flow-guard ممنوع تتكسر. الديفيجن هو **التصنيف
 * التجاري وطريقة التعامل** فوقها. دمجهم في كيان واحد كان معناه
 * إعادة بناء التسعير والسكوبينج كلهم في يوم واحد — والفصل بيخلّي
 * كل بُعد يتعدّل لوحده.
 *
 * ⚠️ **مصدر واحد.** نفس درس `Governorates` بالحرف: القايمة دي
 * ماتتكتبش تاني في أي كنترولر ولا بليد — أي شاشة بتقرا من هنا.
 */
final class Divisions
{
    public const FULFILLMENT_CASHVAN = 'cashvan';
    public const FULFILLMENT_DELIVERY = 'delivery';
    public const FULFILLMENT_ONLINE = 'online';

    /**
     * القسم ⇒ طريقة التعامل — **جدول المالك بالحرف** (١٧/٨).
     *
     * ⚠️ الترتيب هو ترتيب العرض في الشاشات — زي ما هو في الجدول.
     */
    public const ROWS = [
        'modern_trade' => self::FULFILLMENT_DELIVERY,
        'convenience' => self::FULFILLMENT_CASHVAN,
        'gyms' => self::FULFILLMENT_CASHVAN,
        'supplement_stores' => self::FULFILLMENT_DELIVERY,
        'ecommerce' => self::FULFILLMENT_ONLINE,
        'quick_commerce' => self::FULFILLMENT_DELIVERY,
        'cafe_chains' => self::FULFILLMENT_CASHVAN,
        'pharmacies' => self::FULFILLMENT_CASHVAN,
        'hotels' => self::FULFILLMENT_CASHVAN,
        'traditional_grocery' => self::FULFILLMENT_CASHVAN,
        'horeca' => self::FULFILLMENT_CASHVAN,
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::ROWS);
    }

    public static function has(?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::ROWS);
    }

    /** قاعدة الفاليديشن — مصدر واحد بدل ما كل كنترولر يكتب الليستة */
    public static function rule(): string
    {
        return 'in:'.implode(',', self::keys());
    }

    public static function label(?string $key): string
    {
        return self::has($key) ? __('client.div_'.$key) : '—';
    }

    /** طريقة التعامل بتاعة القسم — `null` لقسم مش معروف */
    public static function fulfillmentOf(?string $key): ?string
    {
        return self::ROWS[$key] ?? null;
    }

    public static function fulfillmentLabel(?string $key): string
    {
        $f = self::fulfillmentOf($key);

        return $f === null ? '—' : __('client.ff_'.$f);
    }

    /** لون شارة طريقة التعامل — من كلاسات البادج الموجودة */
    public static function fulfillmentBadge(?string $key): string
    {
        return match (self::fulfillmentOf($key)) {
            self::FULFILLMENT_CASHVAN => 'b-green',
            self::FULFILLMENT_DELIVERY => 'b-purple',
            self::FULFILLMENT_ONLINE => 'b-blue',
            default => 'b-gray',
        };
    }

    /**
     * مفتاح ⇒ اسم معروض — للقوايم المنسدلة.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $out = [];

        foreach (self::keys() as $key) {
            $out[$key] = self::label($key);
        }

        return $out;
    }
}
