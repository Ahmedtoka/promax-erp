<?php

namespace App\Services\Mfg;

use App\Exceptions\Rejected;
use App\Models\AppNotification;
use App\Models\Product;
use App\Models\ProductSpec;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * مواصفات المنتجات (التصنيع ١٩/١٠، spec §3.5) — دورة الإصدار.
 *
 * مسودة ← تحت المراجعة ← معتمدة (والمعتمدة القديمة تتأرشف) — أو ترجع مسودة.
 *
 * ⚠️ **المعتمدة ماتتعدلش.** أي تعديل = `newVersion()` منسوخة منها، والإنتاج
 * بيفضل على المعتمدة القديمة لحد ما الجديدة تتعتمد (الأدمن بس، D1).
 * ⚠️ المواصفة للمنتج التام بس — الخامة والتعبئة مالهمش مواصفة هنا.
 */
class Specs
{
    /** مكان المرفقات على ديسك `local` (مش public — التنزيل بيعدّي على الراوت) */
    public const DIR = 'mfg/specs';

    /**
     * إصدار جديد (مسودة) للمنتج — رقمه = أكبر إصدار + 1.
     *
     * ⚠️ إصدار مفتوح واحد (مسودة أو تحت المراجعة) لكل منتج — اتنين مسودة
     * لنفس المنتج بيخلّوا الاعتماد يختار بينهم بالصدفة.
     */
    public static function newVersion(Product $p, User $by, ?ProductSpec $copyFrom = null): ProductSpec
    {
        return DB::transaction(function () use ($p, $by, $copyFrom) {
            // قفل صف المنتج = إصدارين في نفس اللحظة مايطلعوش بنفس الرقم
            $product = Product::query()->whereKey($p->id)->lockForUpdate()->first();

            if ($product === null || $product->kind !== Product::KIND_FINISHED) {
                throw new Rejected(__('mfg.spec_finished_only'));
            }

            if ($copyFrom !== null && $copyFrom->product_id !== $product->id) {
                throw new Rejected(__('mfg.spec_copy_other_product'));
            }

            $open = ProductSpec::query()->where('product_id', $product->id)
                ->whereIn('status', ['draft', 'under_review'])->exists();

            if ($open) {
                throw new Rejected(__('mfg.spec_open_exists'));
            }

            $version = (int) ProductSpec::query()->where('product_id', $product->id)->max('version') + 1;

            $spec = new ProductSpec([
                'product_id' => $product->id,
                'version' => $version,
                'status' => 'draft',
                'created_by' => $by->id,
            ]);

            if ($copyFrom !== null) {
                $spec->fill($copyFrom->only(['net_weight_g', 'shelf_life_days', 'nutrition', 'qc_limits', 'notes']));

                // المرفق بيتنسخ لملف جديد — لو اتغيّر في المسودة، المعتمد يفضل زي ما هو
                $disk = Storage::disk('local');
                if ($copyFrom->attachment_path && $disk->exists($copyFrom->attachment_path)) {
                    $ext = pathinfo($copyFrom->attachment_path, PATHINFO_EXTENSION);
                    $path = self::DIR.'/'.Str::uuid().($ext !== '' ? '.'.$ext : '');
                    $disk->copy($copyFrom->attachment_path, $path);
                    $spec->attachment_path = $path;
                    $spec->attachment_name = $copyFrom->attachment_name;
                }
            }

            $spec->save();

            return $spec;
        });
    }

    /**
     * تعديل المسودة — المعتمدة وتحت المراجعة مقفولين.
     *
     * `$fields` جاية من الكنترولر بعد الفاليديشن والتنضيف؛ المرفق (لو فيه)
     * اتخزن قبل الترانزاكشن، ولو اترفض التعديل الكنترولر بيمسحه.
     *
     * @param  array<string, mixed>  $fields
     */
    public static function update(ProductSpec $s, array $fields): void
    {
        DB::transaction(function () use ($s, $fields) {
            $row = self::locked($s);

            if ($row->status !== 'draft') {
                throw new Rejected(__('mfg.spec_bad_state'));
            }

            $row->fill($fields)->save();
            $s->setRawAttributes($row->getAttributes(), true);
        });
    }

    /** مسودة ← تحت المراجعة — الصلاحية والوزن الصافي إجباريين */
    public static function submit(ProductSpec $s, User $by): void
    {
        DB::transaction(function () use ($s) {
            $row = self::locked($s);

            if ($row->status !== 'draft') {
                throw new Rejected(__('mfg.spec_bad_state'));
            }

            if ($row->shelf_life_days === null || $row->net_weight_g === null) {
                throw new Rejected(__('mfg.spec_submit_needs'));
            }

            $row->forceFill(['status' => 'under_review', 'submitted_at' => now()])->save();
            $s->setRawAttributes($row->getAttributes(), true);
        });
    }

    /**
     * تحت المراجعة ← معتمدة، والمعتمدة السابقة للمنتج ← أرشيف (ترانزاكشن واحدة).
     */
    public static function approve(ProductSpec $s, User $by): void
    {
        DB::transaction(function () use ($s, $by) {
            $row = self::locked($s);

            if ($row->status !== 'under_review') {
                throw new Rejected(__('mfg.spec_bad_state'));
            }

            ProductSpec::query()->where('product_id', $row->product_id)
                ->where('status', 'approved')->whereKeyNot($row->id)
                ->lockForUpdate()->get()
                ->each(fn (ProductSpec $old) => $old->forceFill(['status' => 'archived'])->save());

            $row->forceFill([
                'status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now(),
            ])->save();
            $s->setRawAttributes($row->getAttributes(), true);
        });
    }

    /** تحت المراجعة ← مسودة — والملاحظة بتوصل لكاتب المواصفة إشعار */
    public static function sendBack(ProductSpec $s, User $by, ?string $note): void
    {
        DB::transaction(function () use ($s) {
            $row = self::locked($s);

            if ($row->status !== 'under_review') {
                throw new Rejected(__('mfg.spec_bad_state'));
            }

            $row->forceFill(['status' => 'draft', 'submitted_at' => null])->save();
            $s->setRawAttributes($row->getAttributes(), true);
        });

        $s->loadMissing(['product', 'creator']);
        $product = $s->product;
        $version = $s->version;
        $note = trim((string) $note);

        AppNotification::send(
            $s->creator,
            fn () => __('mfg.spec_sent_back_title', ['product' => $product?->displayName() ?? '', 'version' => $version]),
            $note !== '' ? $note : null,
            good: false,
        );
    }

    private static function locked(ProductSpec $s): ProductSpec
    {
        $row = ProductSpec::query()->whereKey($s->id)->lockForUpdate()->first();

        if ($row === null) {
            throw new Rejected(__('mfg.spec_bad_state'));
        }

        return $row;
    }
}
