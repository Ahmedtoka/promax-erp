<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * مستحقات العقود — الخصومات الدورية والرسوم وحجز الضمان
 * ═══════════════════════════════════════════════════════════════
 *
 * المشكلة اللي بيحلها: بنود العقد الدورية (خصم ربع سنوي، سنوي،
 * حافز على المحقق) كانت معروضة كنسبة على صفحة العقد وبس. محدش
 * كان بيحسب "الربع ده مستحق علينا كام لهذا العميل" ولا بيتقيّد،
 * فكان بيتحسب على ورق بره السيستم.
 *
 * الفكرة: كل بند دوري بيولّد **صف استحقاق** لكل فترة، محسوب من
 * مشتريات العميل الفعلية في الفترة دي (من كشف الحساب مش من تقدير).
 * الصف بيفضل `due` لحد ما حد يراجعه ويرحّله، وساعتها بيتعمل قيد
 * خصم تجاري على كشف الحساب.
 *
 * ⚠️ التوليد **مابيقيّدش**. الفصل ده مقصود: الحساب آلي والقرار
 * بشري. لو ولّدنا وقيّدنا في خطوة واحدة، أي غلط في نسبة أو فترة
 * بيتحول فوراً لقيد غلط في كشف حساب عميل.
 *
 * الأنواع:
 *   rebate       خصم دوري على مسحوبات الفترة  → قيد دائن على العميل
 *   fee          رسم ثابت (تكويد/افتتاح/مجلة) → التزام، بيتقيّد لما يتدفع
 *   withholding  محجوز من مستحقاتنا كضمان     → مش قيد، رصيد محجوز
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contract_dues')) {
            Schema::create('contract_dues', function (Blueprint $table) {
                $table->id();
                // ⚠️ restrict مش cascade: مسح عقد كان بيمسح استحقاقاته
                // **بما فيها المقيّدة**، فالقيد يفضل في كشف الحساب وأصله
                // يختفي. لازم اليوزر يتعامل مع الاستحقاقات الأول.
                $table->foreignId('contract_id')->constrained()->restrictOnDelete();
                // العميل اللي الاستحقاق عليه. عقد السلسلة بيولّد صف لكل فرع
                // ليه حركة في الفترة — عشان القيد يروح لكشف الحساب الصح.
                $table->foreignId('client_id')->constrained()->cascadeOnDelete();
                // البند ممكن يتمسح أو يتعاد إنشاؤه — الاستحقاق مايضيعش
                $table->foreignId('contract_clause_id')->nullable()
                    ->constrained()->nullOnDelete();

                $table->string('kind', 20);                 // rebate / fee / withholding
                $table->string('basis', 20)->default('agreed');

                $table->date('period_start');
                $table->date('period_end');

                // أساس الحساب: مشتريات العميل في الفترة (من transactions)
                $table->decimal('basis_amount', 14, 2)->default(0);
                $table->decimal('pct', 6, 4)->nullable();
                $table->decimal('amount', 14, 2)->default(0);

                // due = محسوب ومستني قرار | settled = اتقيّد | waived = اتلغى
                $table->string('status', 12)->default('due');
                $table->timestamp('settled_at')->nullable();
                $table->foreignId('settled_by')->nullable()
                    ->constrained('users')->nullOnDelete();
                $table->foreignId('transaction_id')->nullable()
                    ->constrained()->nullOnDelete();

                $table->text('note')->nullable();
                $table->timestamps();

                // ⚠️ الحارس ضد التكرار — على أعمدة **مابتبقاش NULL أبداً**.
                // كان على contract_clause_id، وده بيبقى NULL لو البند اتمسح
                // (السيدر بيمسح البنود ويعيد إنشاءها بـ id جديد كل مرة).
                // ومادام NULL، MySQL بيعتبر كل صف مختلف، فالاستحقاق بيتكرر
                // وممكن العميل ياخد خصم نفس الربع مرتين.
                $table->unique(
                    ['contract_id', 'client_id', 'kind', 'basis', 'period_start'],
                    'contract_dues_unique_period'
                );
                $table->index(['status', 'period_end']);
                $table->index(['client_id', 'kind']);
            });
        }

        // رصيد الضمان المحجوز على العميل — بيتحرك حجز وردّ
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'withheld')) {
                $table->decimal('withheld', 14, 2)->default(0)->after('balance');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_dues');

        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'withheld')) {
                $table->dropColumn('withheld');
            }
        });
    }
};
