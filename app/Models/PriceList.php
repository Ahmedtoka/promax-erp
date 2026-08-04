<?php

namespace App\Models;

use App\Models\Concerns\HasBilingualName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * ═══════════════════════════════════════════════════════════════
 * قائمة سعر مسمّاة — «قائمة 1»، «قائمة 2»…
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **القايمة مابتتفعّلش إلا لما كل صنف مفعّل يبقى ليه سعر.**
 * ده مش تشدّد: عميل على قايمة وصنف مالوش سعر فيها كان هيتباع
 * بصفر أو بسعر قايمة تانية، والاتنين غلط مابيبانش غير في آخر
 * الشهر لما الفاتورة تطلع ناقصة.
 */
class PriceList extends Model
{
    use HasBilingualName;

    protected $fillable = [
        'code', 'name', 'name_en', 'active', 'is_default',
        'notes', 'created_by', 'activated_at',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'is_default' => 'boolean',
            'activated_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** العملاء اللي بيتحاسبوا بالقايمة دي */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    // ═══════════════════════════════════════════════════════════
    //  الاكتمال
    // ═══════════════════════════════════════════════════════════

    /**
     * الأصناف اللي لسه مالهاش سعر في القايمة دي.
     *
     * ⚠️ **المفعّل بس.** الصنف الموقوف مابيتباعش، فطلب سعر له بيمنع
     * تفعيل القايمة على حاجة مالهاش لازمة.
     *
     * ⚠️ **السعر صفر يعتبر ناقص.** الصف اللي اتعمل بصفر عشان يتملا
     * بعدين هو بالظبط اللي بيسبب الفاتورة بصفر، والفرق بينه وبين
     * «مافيش صف» مالوش أي معنى تجاري.
     */
    public function missing()
    {
        return Product::where('active', true)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('price_list_items')
                    ->whereColumn('price_list_items.product_id', 'products.id')
                    ->where('price_list_items.price_list_id', $this->id)
                    ->where('price_list_items.price', '>', 0);
            });
    }

    public function missingCount(): int
    {
        return $this->missing()->count();
    }

    public function isComplete(): bool
    {
        return $this->missingCount() === 0;
    }

    /**
     * تفعيل القايمة.
     *
     * @return string|null رسالة الرفض، أو null لو تمّت
     */
    public function activate(): ?string
    {
        $missing = $this->missingCount();

        if ($missing > 0) {
            return __('price.cannot_activate_incomplete', ['count' => $missing]);
        }

        $this->update(['active' => true, 'activated_at' => now()]);

        return null;
    }

    /**
     * إيقاف القايمة.
     *
     * ⚠️ **مابتتوقفش وعليها عملاء.** إيقافها بيخلّي `priceFor` ترجّع
     * صفر لكل عميل عليها — يعني فواتير بصفر من غير أي رسالة خطأ.
     */
    public function deactivate(): ?string
    {
        // ⚠️ العميل حالته في `status` مش في عمود `active`.
        $n = $this->clients()->where('status', 'active')->count();

        if ($n > 0) {
            return __('price.cannot_stop_in_use', ['count' => $n]);
        }

        if ($this->is_default) {
            return __('price.cannot_stop_default');
        }

        $this->update(['active' => false]);

        return null;
    }

    // ═══════════════════════════════════════════════════════════

    /** سعر صنف في القايمة دي — صفر لو مش متسعّر */
    public function priceFor(int|Product $product): float
    {
        $id = $product instanceof Product ? $product->id : $product;

        return (float) ($this->items->firstWhere('product_id', $id)?->price
            ?? $this->items()->where('product_id', $id)->value('price')
            ?? 0);
    }

    /**
     * القايمة الافتراضية.
     *
     * ⚠️ **واحدة بس.** `setDefault` بتشيل العلم عن الباقي في نفس
     * الترانزاكشن — قايمتين افتراضيتين معناهم إن العميل بياخد
     * واحدة منهم على مزاج ترتيب الداتابيز.
     */
    public static function default(): ?self
    {
        // ⚠️ ميمو للريكوست الواحد — listRowFor بتقع هنا لكل عميل من غير
        // قايمة خاصة، وشاشات فيها مئات العملاء كانت بتضرب نفس الكويري
        // مئات المرات. setDefault بتصفّر الكاش.
        if (! static::$defaultResolved) {
            static::$defaultResolved = true;
            static::$defaultCache = static::where('is_default', true)->where('active', true)->first()
                ?? static::where('active', true)->orderBy('id')->first();
        }

        return static::$defaultCache;
    }

    protected static ?self $defaultCache = null;

    protected static bool $defaultResolved = false;

    public function setDefault(): ?string
    {
        if (! $this->active) {
            return __('price.default_must_be_active');
        }

        DB::transaction(function () {
            static::where('is_default', true)->update(['is_default' => false]);
            $this->update(['is_default' => true]);
        });

        static::$defaultResolved = false;   // الكاش بقى قديم

        return null;
    }
}
