<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * مدير القناة المسؤول عن العميل + جهات التواصل عنده
 * ═══════════════════════════════════════════════════════════════
 *
 * ⚠️ **مدير القناة غير المندوب.** المندوب (`rep_id`) بيتخصص من شاشة
 * توزيع المناطق، وبيتغيّر مع خط السير. مدير القناة هو المسؤول
 * التجاري عن الحساب — هو اللي بيتفاوض ويوافق على الشروط. خلطهم في
 * عمود واحد كان معناه إن إعادة توزيع خط سير بتغيّر مين المسؤول
 * تجارياً عن سيركل كيه.
 *
 * ⚠️ **جهات التواصل على العميل مش على العقد.** العقد ممكن يكون
 * للسلسلة كلها (عقد Circle K واحد لـ44 فرع)، والأكاونت مانجر بتاع
 * فرع دجلة غير بتاع فرع التجمع. لو اتخزنوا على العقد، الـ44 فرع
 * هيشوفوا نفس الاسم والتليفون.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'manager_id')) {
                // ⚠️ `nullOnDelete` مش `cascade` — خروج موظف من الشركة
                // مايمسحش عملاءه.
                $table->foreignId('manager_id')->nullable()
                    ->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('clients', 'contacts')) {
                // [{ name, role, phone }] — مرجع بشري بس، مفيش منطق عليه
                $table->json('contacts')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'contacts')) {
                $table->dropColumn('contacts');
            }

            if (Schema::hasColumn('clients', 'manager_id')) {
                $table->dropConstrainedForeignId('manager_id');
            }
        });
    }
};
