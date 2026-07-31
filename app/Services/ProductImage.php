<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Http\UploadedFile;

/**
 * ═══════════════════════════════════════════════════════════════
 * صورة الصنف — التخزين والحذف
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **صور المنتجات في القرص العام مش المحمي** — بعكس ملفات العقود.
 * الفرق مقصود: صورة بار بروتين مش سر تجاري، وبتتعرض في أبلكيشن
 * المندوب وفي شاشة البيع عشرات المرات في اليوم. تقديمها من راوت
 * محروس معناه استعلام داتابيز وفحص صلاحية على كل صورة في كل صفحة.
 *
 * (ملف العقد عكس كده تماماً: فيه أسعار وشروط تجارية، وبيتفتح مرة
 * أو مرتين، فالحراسة تستاهل.)
 */
class ProductImage
{
    /** الحد الأقصى بالكيلوبايت — 4 ميجا */
    public const MAX_KB = 4096;

    /** قاعدة التحقق — مصدر واحد للشاشتين */
    public static function rule(): array
    {
        // ⚠️ `image` **و** `mimes` مع بعض عن قصد. `image` بتفحص إن
        // الملف صورة حقيقية (بتقرا أبعادها)، و`mimes` بتقفل الأنواع
        // المسموحة. `image` لوحدها بتقبل `bmp` و`svg` — والـSVG ملف
        // نصي بيقدر يشيل سكربت، والمتصفح بينفّذه لو اتقدّم من نفس
        // الدومين.
        return ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_KB];
    }

    /**
     * تخزين صورة جديدة وحذف القديمة.
     *
     * @return string المسار النسبي جوه القرص العام
     */
    public static function store(Product $product, UploadedFile $file): string
    {
        $old = (string) $product->image_path;

        // ⚠️ **الامتداد من محتوى الملف مش من اسمه.** اسم الملف بييجي
        // من جهاز المستخدم: صورة حقيقية اسمها `x.php` كانت هتعدّي
        // التحقق (اللي بيفحص المحتوى) وتتخزن باسم `.php` جوه مجلد
        // بيتقدّم مباشرةً من الويب.
        $ext = strtolower($file->guessExtension() ?: 'jpg');

        // ⚠️ الاسم بيتولّد من كود الصنف + الوقت. كود الصنف بيخلّي
        // الملف معروف صاحبه لو حد بصّ على المجلد، والوقت بيمنع
        // المتصفح إنه يعرض الصورة القديمة من الكاش بعد الاستبدال.
        $safeCode = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $product->code) ?: 'p'.$product->id;
        $name = $safeCode.'-'.now()->format('YmdHis').'.'.$ext;

        $path = $file->storeAs('products', $name, 'public');

        self::forget($old, keep: $path);

        return $path;
    }

    /**
     * حذف صورة قديمة.
     *
     * ⚠️ من غير الحذف ده كل رفع بيسيب ملف يتيم مفيش أي صف بيشاور
     * عليه — والمجلد بيكبر بصور محدش يعرف بتاعة أنهي صنف ولا ينفع
     * تتمسح بأمان بعد كده.
     *
     * ⚠️ والمسار بيتفحص إنه **جوه مجلد المنتجات** قبل الحذف. القيمة
     * جاية من الداتابيز، وصف اتعدّل بإيد فيه `../../.env` كان هيمسح
     * ملف بره المجلد.
     */
    public static function forget(?string $path, ?string $keep = null): void
    {
        if (! $path || $path === $keep || ! str_starts_with($path, 'products/')) {
            return;
        }

        $full = storage_path('app/public/'.$path);
        $real = realpath($full);
        $root = realpath(storage_path('app/public/products'));

        if ($real !== false && $root !== false && str_starts_with($real, $root)) {
            @unlink($real);
        }
    }
}
