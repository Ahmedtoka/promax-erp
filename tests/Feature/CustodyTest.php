<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Custody;
use App\Models\CustodyItem;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════
 * العهدة — البضاعة اللي في العربية
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **ليه الملف ده مهم:** العهدة هي الجرد الحقيقي للعربية. لو الخصم
 * منها غلط، المندوب بيبيع بضاعة مش موجودة (ويقفل اليوم بعجز محدش
 * يعرف مصدره) أو بيفضل شايل بضاعة على الورق وهي اتباعت.
 *
 * ⚠️ **`deduct()` بترجّع رسالة خطأ — مابترميش استثناء ومابترجعش
 * `false`.** الشكل ده مقصود: اللي بينادي (نقطة الـAPI) بيحوّل الرسالة
 * لـ422 بنص مفهوم للمندوب. الرفض الصامت أو الاستثناء الجاف الاتنين
 * كانوا بيوصّلوا للمندوب «حصل خطأ» من غير ما يعرف إيه الناقص.
 *
 * ⚠️ **الفحص قبل الخصم، والكل أو ولا حاجة.** لو صنف واحد ناقص،
 * العملية كلها بترفض من غير ما تخصم حاجة — الخصم الجزئي بيسيب
 * العربية ناقصة وفاتورة مااتعملتش.
 */
class CustodyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * عهدة مليانة صنف واحد.
     *
     * @return array{0: Custody, 1: Product}
     */
    private function loadedVan(int $qty = 50, ?string $expiresOn = null): array
    {
        $rep = $this->makeRep();
        $product = $this->makeProduct(['cost' => 10, 'price_old' => 18, 'price_new' => 20]);
        $warehouse = $this->makeWarehouse();

        $batch = Batch::create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'batch_no' => 'B-'.uniqid(),
            'produced_on' => today()->subMonth(),
            'expires_on' => $expiresOn ?? today()->addMonths(6)->toDateString(),
            'qty_received' => $qty,
            'qty_remaining' => $qty,
            'cost' => 10,
        ]);

        $custody = Custody::create([
            'user_id' => $rep->id,
            'warehouse_id' => $warehouse->id,
            'date' => today(),
            'status' => 'open',
        ]);

        CustodyItem::create([
            'custody_id' => $custody->id,
            'product_id' => $product->id,
            'batch_id' => $batch->id,
            'assigned' => $qty,
            'sold' => 0,
        ]);

        return [$custody, $product];
    }

    /** قراية نضيفة من الداتابيز — العلاقة المحمّلة بتبقى قديمة بعد الخصم */
    private function reread(Custody $custody): Custody
    {
        return Custody::findOrFail($custody->id);
    }

    // ═══════════════ 1. الخصم الناجح ═══════════════

    /**
     * الخصم بينقّص المتاح ويزوّد المباع.
     *
     * ⚠️ **`sold` هو اللي بيتزوّد، مش `assigned` اللي بيتنقّص.**
     * المحمّل على العربية الصبح رقم ثابت بيتقفل عليه آخر اليوم؛ لو
     * غيّرناه، تصفية المندوب مابقاش ليها مرجع.
     */
    public function test_a_successful_deduction_reduces_what_is_left(): void
    {
        [$custody, $product] = $this->loadedVan(50);

        $error = $custody->deduct([$product->id => 12]);

        $this->assertNull($error, 'الخصم اترفض والعهدة فيها الكمية');

        $item = $this->reread($custody)->items->first();

        $this->assertSame(50, (int) $item->assigned, 'المحمّل الأصلي ممنوع يتغيّر');
        $this->assertSame(12, (int) $item->sold);
        $this->assertSame(38, $item->remaining());
    }

    /**
     * `remainingUnits()` بترجّع الصح بعد البيع.
     *
     * ⚠️ الرقم ده هو اللي بيبان للمندوب في الأبلكيشن وللمدير في
     * شاشة العهدة. لو مااتحدّثش، المندوب بيفتكر إن معاه بضاعة
     * وبيوعد عميل بيها.
     */
    public function test_remaining_units_follow_the_sale(): void
    {
        [$custody, $product] = $this->loadedVan(50);

        $this->assertSame(50, $this->reread($custody)->remainingUnits());

        $custody->deduct([$product->id => 12]);
        $this->assertSame(38, $this->reread($custody)->remainingUnits());

        $custody->deduct([$product->id => 8]);
        $this->assertSame(30, $this->reread($custody)->remainingUnits());
    }

    /**
     * الخصم لحد آخر وحدة مسموح.
     *
     * ⚠️ حالة الحافة: `>= ` بدل `> ` في فحص الكفاية كانت هترفض بيع
     * آخر كرتونة في العربية — والمندوب بيرجع بيها المخزن من غير سبب.
     */
    public function test_the_van_can_be_emptied_to_the_last_unit(): void
    {
        [$custody, $product] = $this->loadedVan(7);

        $this->assertNull($custody->deduct([$product->id => 7]));
        $this->assertSame(0, $this->reread($custody)->remainingUnits());
    }

    // ═══════════════ 2. الرفض ═══════════════

    /**
     * الكمية الأكبر من المتاح بترفض ومابتخصمش حاجة.
     *
     * ⚠️ **الرفض رسالة نصية مش استثناء ولا `false`.** ولازم تسمّي
     * الصنف والعجز — «العهدة ناقصة X وحدة من Y» — عشان المندوب
     * يعرف يكلّم المخزن بالرقم بدل ما يرجّع «مش راضي يبيع».
     *
     * ⚠️ **وممنوع يحصل أي خصم جزئي.** الفحص بيتم على الكميات كلها
     * الأول وبعدين بيخصم — الترتيب ده هو اللي بيمنع عربية ناقصة
     * وفاتورة مااتعملتش.
     */
    public function test_selling_more_than_the_van_holds_is_refused_and_nothing_moves(): void
    {
        [$custody, $product] = $this->loadedVan(10);

        $error = $custody->deduct([$product->id => 999]);

        $this->assertIsString($error, 'الخصم عدّى والعهدة فيها 10 بس');
        $this->assertNotSame('', $error, 'الرفض من غير رسالة = المندوب مش عارف إيه اللي حصل');
        $this->assertStringContainsString($product->displayName(), $error,
            'الرسالة لازم تسمّي الصنف الناقص');

        $fresh = $this->reread($custody);

        $this->assertSame(0, (int) $fresh->items->first()->sold, 'حصل خصم رغم الرفض');
        $this->assertSame(10, $fresh->remainingUnits());
    }

    /**
     * صنف واحد ناقص بيرفض العملية كلها.
     *
     * ⚠️ ده بالظبط سيناريو الفاتورة اللي فيها كذا صنف. لو الأول
     * اتخصم والتاني رفض، العربية بتخسر بضاعة من غير فاتورة مقابلها
     * — والفرق بيظهر آخر اليوم كعجز.
     */
    public function test_one_short_line_refuses_the_whole_deduction(): void
    {
        [$custody, $first] = $this->loadedVan(20);

        $second = $this->makeProduct(['price_new' => 30]);
        $warehouse = Warehouse::firstOrFail();

        $batch = Batch::create([
            'product_id' => $second->id,
            'warehouse_id' => $warehouse->id,
            'batch_no' => 'B-SECOND',
            'produced_on' => today()->subMonth(),
            'expires_on' => today()->addMonths(6)->toDateString(),
            'qty_received' => 3,
            'qty_remaining' => 3,
            'cost' => 15,
        ]);

        CustodyItem::create([
            'custody_id' => $custody->id,
            'product_id' => $second->id,
            'batch_id' => $batch->id,
            'assigned' => 3,
            'sold' => 0,
        ]);

        // الأول متاح والتاني ناقص
        $error = $custody->deduct([$first->id => 5, $second->id => 10]);

        $this->assertIsString($error);

        $fresh = $this->reread($custody);

        $this->assertSame(0, (int) $fresh->items->firstWhere('product_id', $first->id)->sold,
            'الصنف المتاح اتخصم رغم إن العملية كلها اترفضت');
        $this->assertSame(23, $fresh->remainingUnits(), '20 + 3 زي ما هي');
    }

    /**
     * الباتش المنتهي مابيتباعش حتى لو موجود في العربية.
     *
     * ⚠️ بضاعة منتهية بتخرج للعميل مسئولية قانونية مش مجرد خطأ
     * مخزون. الرقم بيبان «متاح» في `assigned` بس ممنوع يتحسب في
     * المتاح للبيع.
     */
    public function test_an_expired_batch_is_never_sold(): void
    {
        [$custody, $product] = $this->loadedVan(20, today()->subDay()->toDateString());

        $error = $custody->deduct([$product->id => 1]);

        $this->assertIsString($error, 'باتش منتهي اتباع');

        $fresh = $this->reread($custody);

        $this->assertSame(0, (int) $fresh->items->first()->sold);
        $this->assertSame(0, $fresh->availableFor($product->id),
            'الباتش المنتهي ممنوع يتحسب في المتاح للبيع');
    }

    /**
     * الكمية صفر أو بالسالب بتتخطّى من غير ما تكسر حاجة.
     *
     * ⚠️ الأبلكيشن ممكن يبعت سطر بصفر (المندوب مسح الكمية وبعت).
     * الرفض هنا كان بيمنع فاتورة سليمة بسبب سطر فاضي.
     */
    public function test_a_zero_quantity_line_is_simply_skipped(): void
    {
        [$custody, $product] = $this->loadedVan(10);

        $this->assertNull($custody->deduct([$product->id => 0]));
        $this->assertSame(10, $this->reread($custody)->remainingUnits());
    }

    // ═══════════════ 3. الفحص قبل الموافقة ═══════════════

    /**
     * `availableFor()` و`canCover()` بيقولوا نفس الحقيقة قبل الخصم.
     *
     * ⚠️ الدالتين دول بيتستخدموا في الموافقة على أوامر التوريد —
     * فحص من غير خصم. لو قالوا رقم غير اللي `deduct()` بيشوفه،
     * الأمر بيتوافق عليه وبيقع وقت التسليم قدام العميل.
     */
    public function test_the_check_before_the_deduction_matches_the_deduction(): void
    {
        [$custody, $product] = $this->loadedVan(15);

        $this->assertSame(15, $this->reread($custody)->availableFor($product->id));

        $ok = $this->reread($custody)->canCover([$product->id => 15]);
        $this->assertTrue($ok['ok']);
        $this->assertSame([], $ok['short']);

        $short = $this->reread($custody)->canCover([$product->id => 16]);
        $this->assertFalse($short['ok']);
        $this->assertCount(1, $short['short']);
        $this->assertSame(16, $short['short'][0]['need']);
        $this->assertSame(15, $short['short'][0]['have']);

        // ⚠️ والقرار ده لازم يطابق `deduct()` بالظبط — مش تقريباً
        $this->assertIsString($custody->deduct([$product->id => 16]));
        $this->assertNull($this->reread($custody)->deduct([$product->id => 15]));
    }

    /**
     * المتاح بينقص بعد كل بيعة.
     *
     * ⚠️ الحارس على استخدام `assigned` بدل `remaining()` في
     * `availableFor()` — الغلطة دي بتخلّي الفحص يقول «متاح» على
     * بضاعة اتباعت من ساعة.
     */
    public function test_available_for_shrinks_after_every_sale(): void
    {
        [$custody, $product] = $this->loadedVan(15);

        $custody->deduct([$product->id => 6]);

        $this->assertSame(9, $this->reread($custody)->availableFor($product->id));
    }
}
