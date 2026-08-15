<?php

use App\Models\PickOrder;
use App\Models\PurchaseOrder;
use App\Models\ReplenishmentRequest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * فكّ طلبات الريفيل المفتوحة من أوامر التوريد  ·  ١٥ أغسطس ٢٠٢٦
 * ═══════════════════════════════════════════════════════════════
 *
 * قرار المالك: «طلبات الريفيل تبقى ريبلانشمنت عادي وماتاخدش PO …
 * القدام اللي اتعملوا عاوز أرجعهم في الفلو ونشيل الـPO بتاعتهم».
 * وعلى سؤال الأوامر المتسلّمة رد: **«سيبهم زي ما هم»**.
 *
 * ═══ الخط الفاصل: هل اتسلّم ولا لأ ═══
 *
 * ⚠️ **الأمر المتسلّم مابيتلمسش إطلاقاً.** تسليم أمر التوريد بيكتب
 * قيد `sale` على حساب الفرع (وقيد `collection` للعميل الكاش)، وده
 * بيدخل كشف الحساب وتصفية المندوب والضريبة. البضاعة راحت للفرع
 * والفلوس اتحصّلت فعلاً — فالأمر ده حقيقة تاريخية، وشيله كان هيحرّك
 * أرصدة عملاء وتصفيات مقفولة بأثر رجعي. قرار المالك بالنص.
 *
 * ⚠️ **الأمر المعلّق (`pending`) مالوش أي قيد** — اتأكدت ستاتيك:
 * `Transaction::create` بتتنده في مسار `deliver` بس، مافيش قيد
 * بيتكتب عند الإنشاء ولا عند موافقة الحسابات. فإلغاؤه آمن مالياً
 * تماماً، وده اللي الملف ده بيعمله.
 *
 * ═══ اللي بيحصل لكل طلب مفتوح ═══
 *
 * ١. أمر التوريد → `cancelled` بسبب مكتوب.
 * ٢. أمر التجهيز المربوط:
 *      · لسه ماخرجش بضاعة (`requested`/`picking`) → يتلغي، و`cancel()`
 *        بترجّع أي كراتين اتسحبت للرف بتاعها.
 *      · خرج بضاعة فعلاً (`ready`/`handed`) → **يتساب** وبس يتفك من
 *        أمر التوريد. البضاعة اتحرّكت على الأرض؛ إلغاء ورقي هيخلّي
 *        المخزن غلط.
 * ٣. الطلب نفسه → يرجع `pending` عشان المدير يوافق تاني بالفلو
 *    الجديد. إلا لو المندوب استلم فعلاً → `delivered` (خلص).
 *
 * ⚠️ **مايجريشن محروسة**: `hasTable`/`hasColumn` قبل أي لمسة،
 * والسيرفر مش ريبو جيت.
 *
 * ⚠️ `down()` فاضية بقصد — إعادة توليد أوامر توريد اتلغت بقرار
 * إداري مش تراجع تقني.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['purchase_orders', 'replenishment_requests', 'pick_orders'] as $t) {
            if (! Schema::hasTable($t)) {
                return;
            }
        }

        if (! Schema::hasColumn('replenishment_requests', 'purchase_order_id')) {
            return;
        }

        // الطلبات اللي ليها أمر توريد **لسه معلّق** (مفيش قيود عليه)
        $requests = ReplenishmentRequest::query()
            ->whereNotNull('purchase_order_id')
            ->whereIn('status', ['assigned', 'pending'])
            ->whereHas('purchaseOrder', fn ($q) => $q->where('status', 'pending'))
            ->with(['purchaseOrder', 'pickOrder'])
            ->get();

        foreach ($requests as $rpl) {
            DB::transaction(function () use ($rpl) {
                /** @var PurchaseOrder|null $po */
                $po = $rpl->purchaseOrder;

                // ═══ ١. أمر التوريد يتلغي بسبب مكتوب ═══
                if ($po !== null && $po->status === 'pending') {
                    $po->update([
                        'status' => 'cancelled',
                        'abort_reason' => 'الريفيل مابقاش ياخد أمر توريد — قرار ١٥ أغسطس ٢٠٢٦',
                    ]);
                }

                // ═══ ٢. أمر التجهيز حسب هل البضاعة اتحرّكت ═══
                /** @var PickOrder|null $pick */
                $pick = $rpl->pickOrder
                    ?? ($po !== null
                        ? PickOrder::where('purchase_order_id', $po->id)->first()
                        : null);

                $handed = $pick !== null && $pick->status === 'handed';

                if ($pick !== null) {
                    if (in_array($pick->status, ['requested', 'picking'], true)) {
                        // `cancel()` بترجّع الكراتين المسحوبة للرف
                        $pick->cancel();
                    } else {
                        // بضاعة خرجت فعلاً — الورقة تفضل، بس تتفك من
                        // أمر التوريد الملغي وتترسي على الطلب.
                        $pick->update([
                            'purchase_order_id' => null,
                            'replenishment_request_id' => $rpl->id,
                        ]);
                    }
                }

                // ═══ ٣. الطلب يرجع للفلو الجديد ═══
                $rpl->update([
                    'purchase_order_id' => null,
                    'status' => $handed ? 'delivered' : 'pending',
                    'assigned_to' => $handed ? $rpl->assigned_to : null,
                    'assigned_at' => $handed ? $rpl->assigned_at : null,
                ]);
            });
        }
    }

    public function down(): void
    {
        // بقصد فاضية — شوف الشرح في رأس الملف.
    }
};
