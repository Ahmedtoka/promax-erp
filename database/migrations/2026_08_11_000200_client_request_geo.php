<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إحداثيات وعنوان عربي لطلب العميل الجديد (١١ أغسطس ٢٠٢٦).
 *
 * المندوب بيلتقط نقطته وهو واقف عند المحل، والمدير في فورم الاعتماد
 * بيكشف العنوان منها (زرار «اكتشف من الموقع») ويسكّن المنطقة. من غير
 * الأعمدة دي كانت النقطة بتضيع بين تسجيل الطلب واعتماده.
 *
 * ⚠️ **`address` إنجليزي و`address_ar` عربي** — نفس قاعدة `clients`.
 * ⚠️ محروسة — السيرفر اللايف بيترفع بالإيد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('client_requests', 'address_ar')) {
                $table->string('address_ar', 190)->nullable()->after('address');
            }

            if (! Schema::hasColumn('client_requests', 'lat')) {
                $table->decimal('lat', 10, 7)->nullable()->after('zone_id');
            }

            if (! Schema::hasColumn('client_requests', 'lng')) {
                $table->decimal('lng', 10, 7)->nullable()->after('lat');
            }
        });
    }

    public function down(): void
    {
        Schema::table('client_requests', function (Blueprint $table) {
            foreach (['lng', 'lat', 'address_ar'] as $col) {
                if (Schema::hasColumn('client_requests', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
