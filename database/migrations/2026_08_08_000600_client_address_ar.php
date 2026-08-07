<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * العنوان بالعربي + تأكيد اللوكيشن (2026-08-08).
 *
 * ⚠️ **`address_ar` مش `address_en`** — والسبب مش مزاج.
 *
 * باقي السيستم ماشي على `name` (عربي) + `name_en` (إنجليزي). بس
 * عمود `address` هنا **إنجليزي أصلاً**: الفورم مكتوب عليه «العنوان
 * · EN» و`dir="ltr"`، والداتا الموجودة فيه كلها إنجليزي. تسميته
 * `address_en` كانت تحتاج إعادة تسمية عمود عليه داتا حية في 300
 * عميل + كل كود بيقراه — مخاطرة مالهاش أي مكسب.
 *
 * فالقاعدة هنا: `address` = إنجليزي (زي ما هو)، `address_ar` = عربي،
 * و`Client::displayAddress()` هي اللي بتختار حسب اللغة. أي كود بيقرا
 * `->address` مباشرةً بيبقى بياخد الإنجليزي — وده اللي كان بيعمله
 * من الأول.
 *
 * ⚠️ و`location_confirmed_at` مش مجرد تاريخ: وجودها معناه إن **بني
 * آدم** راجع النقطة دي وأكّدها. الإحداثيات اللي جاية من الاستيراد
 * أو من الجيوكودينج التقريبي بتفضل `null` — والفرق ده هو اللي
 * هيخلّي الفيريفاي بعدين يفرّق بين نقطة موثوقة وتخمين.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'address_ar')) {
                $table->string('address_ar', 190)->nullable()->after('address');
            }

            if (! Schema::hasColumn('clients', 'location_confirmed_at')) {
                $table->timestamp('location_confirmed_at')->nullable()->after('lng');
            }

            if (! Schema::hasColumn('clients', 'location_confirmed_by')) {
                $table->foreignId('location_confirmed_by')->nullable()
                    ->after('location_confirmed_at')
                    ->constrained('users')->nullOnDelete();
            }

            // ⚠️ **مصدر النقطة**: زيارة مندوب فعلية، ولا لينك خرايط،
            // ولا جيوكودينج تقريبي. من غيره مفيش طريقة تعرف بعد سنة
            // إذا كانت النقطة دي حد وقف عندها فعلاً ولا لأ.
            if (! Schema::hasColumn('clients', 'location_source')) {
                $table->string('location_source', 20)->nullable()
                    ->after('location_confirmed_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'location_confirmed_by')) {
                $table->dropConstrainedForeignId('location_confirmed_by');
            }

            foreach (['location_source', 'location_confirmed_at', 'address_ar'] as $col) {
                if (Schema::hasColumn('clients', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
