<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ═══ سجل مرتجعات الأونلاين + إعادة الشحن (١/١٠/٢٠٢٦) ═══
 *
 * طلب المالك: «زرار إعادة شحن للأوردرات اللي رجعت كلها، وصفحة
 * للمرتجعات عشان السايكل تبقى كاملة».
 *
 * المرتجع كان رقم على البند بس (`online_order_items.returned_qty`) —
 * وإعادة الشحن لازم تصفّره، فمن غير سجل التاريخ كان هيضيع. كل مرتجع
 * بقى صف هنا: الأوردر · الشيت اللي رجع منه · البنود · القطع · القيمة ·
 * واتشحن تاني ولا لسه في المخزن.
 *
 * ⚠️ محروسة · الأعمدة الزمنية `dateTime` مش `timestamp` (فخ ON UPDATE).
 * ⚠️ الملء بأثر رجعي للمرتجعات القديمة: صف لكل أوردر عليه مرتجع،
 * والتاريخ تقريبي (`updated_at` بتاع الأوردر) — مكتوب في `notes`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('online_returns')) {
            Schema::create('online_returns', function (Blueprint $table) {
                $table->id();
                $table->foreignId('online_order_id')->constrained('online_orders')->cascadeOnDelete();
                $table->foreignId('pickup_id')->nullable()->constrained('online_pickups')->nullOnDelete();
                $table->string('kind', 10)->default('partial');      // full | partial
                $table->unsignedInteger('pieces')->default(0);
                $table->decimal('value', 14, 2)->default(0);
                $table->json('lines')->nullable();                     // [{item_id, title, qty}]
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('reshipped_at')->nullable();
                $table->foreignId('reshipped_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('reship_pick_order_id')->nullable()->constrained('pick_orders')->nullOnDelete();
                $table->string('notes', 250)->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('updated_at')->nullable();

                $table->index('created_at');
            });
        }

        // ═══ الملء بأثر رجعي — مرة واحدة (لو الجدول فاضي) ═══
        if (DB::table('online_returns')->exists()) {
            return;
        }

        $orders = DB::table('online_orders')
            ->where(fn ($q) => $q->where('returned_total', '>', 0)->orWhere('status', 'returned'))
            ->get(['id', 'pickup_id', 'status', 'returned_total', 'updated_at']);

        foreach ($orders as $o) {
            $items = DB::table('online_order_items')->where('online_order_id', $o->id)
                ->where('returned_qty', '>', 0)->get(['id', 'title', 'returned_qty', 'units_per']);

            if ($items->isEmpty()) {
                continue;
            }

            DB::table('online_returns')->insert([
                'online_order_id' => $o->id,
                'pickup_id' => $o->pickup_id,
                'kind' => $o->status === 'returned' ? 'full' : 'partial',
                'pieces' => (int) $items->sum(fn ($i) => (int) $i->returned_qty * max((int) $i->units_per, 1)),
                'value' => (float) $o->returned_total,
                'lines' => json_encode($items->map(fn ($i) => [
                    'item_id' => $i->id, 'title' => $i->title, 'qty' => (int) $i->returned_qty,
                ])->values()->all(), JSON_UNESCAPED_UNICODE),
                'notes' => 'backfill',
                'created_at' => $o->updated_at,
                'updated_at' => $o->updated_at,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('online_returns');
    }
};
