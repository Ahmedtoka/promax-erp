<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * رولز المكتب + ملف العربيات والكيلومترات
 * ═══════════════════════════════════════════════════════════════
 *
 * حاجتين:
 *
 * 1. **أمين المخزن بيتربط بمخزنه** (`users.warehouse_id`). من غير
 *    العمود ده، أمين مخزن المعادي بيفتح مخزن المصنع ويجرد فيه —
 *    والفرق بيطلع بعد أسبوع في تسوية محدش عارف مصدرها.
 *
 * 2. **العربية بقى ليها عداد وتاريخ.** كان فيه `vehicles` بالرقم
 *    والنوع بس، والسواق متخزن في عمود واحد `driver_id` بيتدعس عليه
 *    مع كل نقل. يعني السؤال «العربية دي كانت مع مين الشهر اللي فات
 *    ومشيت كام كيلو؟» ماكانش ليه إجابة أصلاً.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ═══════════ 1. أمين المخزن ← مخزنه ═══════════
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'warehouse_id')) {
                $table->foreignId('warehouse_id')->nullable()->after('branch_id')
                    ->constrained('warehouses')->nullOnDelete();
            }
        });

        // ⚠️ الديفولت القديم للعمود `role` هو `'rep'` — رول اتشال من
        // زمان. أي يوزر بيتعمل من غير رول صريح بياخده وبيبقى مالوش
        // أي صلاحية ومش ظاهر في أي فلتر. بنصلّحه لأوضح حاجة.
        if (Schema::hasColumn('users', 'role')) {
            DB::statement("ALTER TABLE users MODIFY role VARCHAR(20) NOT NULL DEFAULT 'sales_agent'");
        }

        // ═══════════ 2. عداد العربية ═══════════
        Schema::table('vehicles', function (Blueprint $table) {
            if (! Schema::hasColumn('vehicles', 'odometer')) {
                // ⚠️ `unsignedInteger` مش decimal. الكيلومتر رقم صحيح،
                // والعشري بيفتح الباب لقراية `120,5` تتخزن `120.50`
                // ويطلع فرق نص كيلو في كل تقرير.
                $table->unsignedInteger('odometer')->default(0)->after('is_fridge');
            }

            if (! Schema::hasColumn('vehicles', 'odometer_at')) {
                $table->timestamp('odometer_at')->nullable()->after('odometer');
            }

            if (! Schema::hasColumn('vehicles', 'model_year')) {
                $table->unsignedSmallInteger('model_year')->nullable()->after('kind_en');
            }
        });

        // ═══════════ 3. هيستوري تسكين السواقين ═══════════
        // ⚠️ **`vehicles.driver_id` عمود «دلوقتي» مش تاريخ.** كل نقل
        // كان بيدوس على اللي قبله، فالعربية اللي اتكسرت مع سواق الشهر
        // اللي فات مابقاش ليها أي أثر بيقول إنها كانت معاه.
        if (! Schema::hasTable('vehicle_assignments')) {
            Schema::create('vehicle_assignments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

                $table->date('from_date');
                // ⚠️ `null` = التسكين الجاري. الفهرس تحت بيضمن إن
                // مايكونش فيه سواقين على نفس العربية في نفس الوقت.
                $table->date('to_date')->nullable();

                // العداد وقت التسليم والاستلام — الفرق = كيلومترات الفترة
                $table->unsignedInteger('odometer_start')->default(0);
                $table->unsignedInteger('odometer_end')->nullable();

                $table->string('note', 300)->nullable();
                $table->timestamps();

                $table->index(['vehicle_id', 'from_date']);
                $table->index(['user_id', 'from_date']);
            });
        }

        // ═══════════ 4. قراءات العداد ═══════════
        // ⚠️ جدول منفصل عن التسكين عن قصد: القراية بتتاخد يومياً
        // (بداية اليوم ونهايته)، والتسكين بيفضل شهور. لو حطّينا
        // القرايات في التسكين، مانقدرش نحسب كيلومترات يوم واحد.
        if (! Schema::hasTable('odometer_readings')) {
            Schema::create('odometer_readings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('custody_id')->nullable()->constrained()->nullOnDelete();

                $table->date('read_on');
                $table->unsignedInteger('km');
                // 'start' أول اليوم | 'end' آخر اليوم | 'manual' إدخال يدوي
                $table->string('kind', 12)->default('manual');
                $table->string('note', 300)->nullable();
                $table->timestamps();

                // ⚠️ قراية واحدة لكل عربية/يوم/نوع. من غير القيد ده،
                // السواق اللي بيدوس «تسجيل» مرتين بيضاعف كيلومترات يومه.
                $table->unique(['vehicle_id', 'read_on', 'kind'], 'odo_unique_day');
                $table->index(['vehicle_id', 'read_on']);
            });
        }

        // ═══════════ 5. نقل التسكين الحالي لجدول الهيستوري ═══════════
        // ⚠️ من غير النقل ده، الشاشة الجديدة بتفتح فاضية والعربيات
        // اللي عليها سواقين فعلاً بتبان كأنها مش مسكّنة لحد.
        if (Schema::hasTable('vehicle_assignments') && Schema::hasColumn('vehicles', 'driver_id')) {
            $rows = DB::table('vehicles')
                ->whereNotNull('driver_id')
                ->get(['id', 'driver_id', 'odometer', 'created_at']);

            foreach ($rows as $v) {
                $exists = DB::table('vehicle_assignments')
                    ->where('vehicle_id', $v->id)
                    ->whereNull('to_date')
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('vehicle_assignments')->insert([
                    'vehicle_id' => $v->id,
                    'user_id' => $v->driver_id,
                    'from_date' => $v->created_at ? date('Y-m-d', strtotime((string) $v->created_at)) : now()->toDateString(),
                    'to_date' => null,
                    'odometer_start' => (int) ($v->odometer ?? 0),
                    'odometer_end' => null,
                    'note' => 'نُقل من التسكين القديم',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('odometer_readings');
        Schema::dropIfExists('vehicle_assignments');

        Schema::table('vehicles', function (Blueprint $table) {
            foreach (['odometer', 'odometer_at', 'model_year'] as $col) {
                if (Schema::hasColumn('vehicles', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'warehouse_id')) {
                $table->dropConstrainedForeignId('warehouse_id');
            }
        });
    }
};
