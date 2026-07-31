<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * الفروع + العربيات
 * ═══════════════════════════════════════════════════════════════
 *
 * **الفرع بُعد جديد في السيستم.** لحد دلوقتي كان فيه بُعدين للتقسيم:
 * القناة (`channels`) والمنطقة (`zones`). الفرع تالت، وهو **الأشمل**:
 * فرع المعادي عنده مخزنه ومناطقه وفريقه وعملاؤه، ومدير الفرع بيشوف
 * ويتحكم في كل ده — بس في فرعه هو بس.
 *
 * ⚠️ **الفرع NULL معناه «كل الفروع»** مش «بلا فرع». الداتا الموجودة
 * كلها هتفضل NULL وهتبان للكل، وده صح: الشركة كانت فرع واحد فعلياً.
 * لو خلّينا NULL معناه محروم، كل حاجة قديمة هتختفي فجأة.
 *
 * ⚠️ العربية **مش** نفس العهدة. العهدة بضاعة يوم واحد، والعربية أصل
 * ثابت ليه رقم ونوع وبيتنقل بين مندوب وتاني. الخلط بينهم بيخلّي
 * رقم السيارة يتكرر في كل صف عهدة ومحدش يعرف يغيّره من مكان واحد.
 */
return new class extends Migration
{
    /** الجداول اللي بتاخد `branch_id` — كلها بنفس القاعدة */
    private const SCOPED = ['warehouses', 'zones', 'users', 'clients'];

    public function up(): void
    {
        // ═══════════ الفروع ═══════════
        if (! Schema::hasTable('branches')) {
            Schema::create('branches', function (Blueprint $table) {
                $table->id();
                $table->string('code', 20)->unique();
                $table->string('name');
                $table->string('name_en')->nullable();
                $table->string('address')->nullable();
                $table->string('phone', 30)->nullable();

                // مدير الفرع — مرجع للعرض، والصلاحية بتتحدد من
                // `users.branch_id` مش من هنا
                $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();

                $table->decimal('lat', 10, 7)->nullable();
                $table->decimal('lng', 10, 7)->nullable();
                $table->boolean('active')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        // ═══════════ ربط كل حاجة بالفرع ═══════════
        foreach (self::SCOPED as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'branch_id')) {
                    // ⚠️ `nullOnDelete` مش `cascade` — مسح فرع بالغلط
                    // مايمسحش عملاءه ومخزنه معاه.
                    $table->foreignId('branch_id')->nullable()
                        ->constrained('branches')->nullOnDelete();
                }
            });
        }

        // ═══════════ العربيات ═══════════
        if (! Schema::hasTable('vehicles')) {
            Schema::create('vehicles', function (Blueprint $table) {
                $table->id();

                // ⚠️ رقم اللوحة فريد — عربيتين بنفس الرقم معناه إن
                // العهدة والتتبع بيتخلطوا وأول مطابقة بتطلع غلط.
                $table->string('plate', 30)->unique();
                $table->string('kind')->nullable();          // GMC ربع نقل ثلاجة
                $table->string('kind_en')->nullable();

                $table->boolean('is_fridge')->default(false); // ثلاجة؟ مهم للأصناف الحساسة
                $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

                // المندوب والسواق المخصصين حالياً — بيتغيروا
                $table->foreignId('rep_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();

                $table->boolean('active')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        // العهدة بتعرف خرجت بأنهي عربية
        Schema::table('custodies', function (Blueprint $table) {
            if (! Schema::hasColumn('custodies', 'vehicle_id')) {
                $table->foreignId('vehicle_id')->nullable()
                    ->constrained('vehicles')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('custodies', function (Blueprint $table) {
            if (Schema::hasColumn('custodies', 'vehicle_id')) {
                // ⚠️ الـ FK قبل العمود — العكس بيرمي خطأ MySQL
                $table->dropConstrainedForeignId('vehicle_id');
            }
        });

        Schema::dropIfExists('vehicles');

        foreach (array_reverse(self::SCOPED) as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (Schema::hasColumn($tableName, 'branch_id')) {
                    $table->dropConstrainedForeignId('branch_id');
                }
            });
        }

        Schema::dropIfExists('branches');
    }
};
