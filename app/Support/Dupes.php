<?php

namespace App\Support;

use App\Models\Client;

/**
 * ═══════════════════════════════════════════════════════════════
 * كشف تكرار العملاء — مصدر واحد للمنطق
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ المستورد وشاشة الإنشاء اليدوي **لازم يحكموا بنفس الطريقة** —
 * لما كان لكل مسار منطقه، العميل اللي الاستيراد بيرفضه كانت الشاشة
 * بتقبله والعكس. أي تعديل على التطبيع هنا بيسري على الاتنين تلقائياً.
 */
final class Dupes
{
    /**
     * مفتاح مطابقة للاسم: صغير، همزات وتاء مربوطة موحدة، من غير
     * «ال» و«فرع»، والأرقام العربي بقت إنجليزي — «المعادى ١» و
     * «فرع المعادي 1» نفس العميل.
     */
    public static function nameKey(?string $name): string
    {
        $s = mb_strtolower(trim((string) $name), 'UTF-8');
        $s = str_replace(['أ', 'إ', 'آ'], 'ا', $s);
        $s = str_replace('ة', 'ه', $s);
        $s = str_replace('ى', 'ي', $s);
        $s = preg_replace('/[\x{064B}-\x{0652}]/u', '', $s) ?? $s;   // تشكيل
        $s = preg_replace('/[()\-_.،,\/]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        $s = preg_replace('/^(فرع\s+)/u', '', trim($s)) ?? $s;
        $s = preg_replace('/(^|\s)ال/u', '$1', $s) ?? $s;
        $s = strtr($s, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);

        return trim($s);
    }

    /** تليفون للمقارنة: أرقام بس، وكود الدولة 20 بيتحول لصفر محلي */
    public static function phoneKey(?string $phone): string
    {
        $p = preg_replace('/\D+/', '', (string) $phone) ?? '';

        return preg_replace('/^20(1\d{9})$/', '0$1', $p) ?? $p;
    }

    /**
     * فيه عميل موجود بنفس الاسم (مطبّع) أو نفس التليفون؟
     *
     * @return array{client: Client, by: 'name'|'phone'}|null
     */
    public static function existing(?string $name, ?string $phone, ?int $ignoreId = null): ?array
    {
        $nk = self::nameKey($name);
        $pk = self::phoneKey($phone);

        // العملاء مئات مش ملايين — المقارنة المطبّعة في PHP أدق من LIKE
        foreach (Client::query()->get(['id', 'code', 'name', 'phone']) as $c) {
            if ($c->id === $ignoreId) {
                continue;
            }

            if ($nk !== '' && self::nameKey($c->name) === $nk) {
                return ['client' => $c, 'by' => 'name'];
            }

            if ($pk !== '' && self::phoneKey($c->phone) === $pk) {
                return ['client' => $c, 'by' => 'phone'];
            }
        }

        return null;
    }
}
