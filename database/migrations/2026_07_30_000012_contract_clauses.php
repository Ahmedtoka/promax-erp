<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ═══════════════════════════════════════════════════════════════
 * بنود العقود — العقد بقى مجموعة بنود مصنّفة مش رقم خصم واحد
 * ═══════════════════════════════════════════════════════════════
 *
 * الواقع اللي في العقود الموقّعة: كل عقد فيه 6 أنواع نسب مختلفة
 * وعشرات الرسوم الثابتة. السيستم كان بيختزل ده كله في `discount`
 * واحد — يعني كنا بنخصم على الفاتورة حاجات المفروض تتسوّى بعدين،
 * أو بنتجاهل تكاليف حقيقية خالص.
 *
 * التقسيم:
 *   invoice_discount  بيتشال على الفاتورة وقت البيع  ← Pricing بيطبقه
 *   rebate            خصم دوري بيتسوّى بعدين (ربع سنوي/سنوي)
 *   collection        بيتخصم لحظة التحصيل (شيك/ضريبة منبع)
 *   rent              إيجار مساحة عرض
 *   listing_fee       تكويد وتسجيل أصناف
 *   opening_fee       دعم افتتاح فروع
 *   marketing         مجلات وإعلانات ومهرجانات
 *   penalty           غرامة مشروطة — مابتدخلش أي إجمالي متوقع
 *   withholding       حجز ضمان من المستحقات
 *   returns           سياسة المرتجعات
 *   credit            أجل الائتمان
 *
 * ⚠️ العقد بقى يقدر يتربط بسلسلة (client_groups) بدل عميل واحد،
 * فكل فروع Circle K مثلاً يورثوا نفس العقد بدل ما نكرره 40 مرة.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------- 1. أعمدة جديدة على العقد ----------
        Schema::table('contracts', function (Blueprint $table) {
            if (! Schema::hasColumn('contracts', 'group_id')) {
                // العقد بتاع السلسلة كلها — كل فروعها بتورثه
                $table->foreignId('group_id')->nullable()->after('client_id')
                    ->constrained('client_groups')->nullOnDelete();
            }
            if (! Schema::hasColumn('contracts', 'file_path')) {
                // مسار الـ PDF الأصلي جوه storage/app/contracts
                $table->string('file_path', 190)->nullable()->after('note');
            }
            if (! Schema::hasColumn('contracts', 'settlement_mode')) {
                // invoice = مديونية وقت التوريد | consignment = بيع بالمبيع
                $table->string('settlement_mode', 20)->default('invoice')->after('price_list');
            }
            if (! Schema::hasColumn('contracts', 'withholding_pct')) {
                // نسبة محجوزة من المستحقات كضمان (Circle K 25%)
                $table->decimal('withholding_pct', 5, 4)->default(0)->after('discount');
            }
            if (! Schema::hasColumn('contracts', 'total_deduction_pct')) {
                // إجمالي كل نسب الخصم الحقيقية — للربحية، مش للفاتورة
                $table->decimal('total_deduction_pct', 5, 4)->default(0)->after('withholding_pct');
            }
            if (! Schema::hasColumn('contracts', 'auto_renew')) {
                $table->boolean('auto_renew')->default(false)->after('ends_at');
            }
            if (! Schema::hasColumn('contracts', 'notice_days')) {
                // مهلة الإخطار بعدم التجديد — بنحسب منها ميعاد القرار
                $table->unsignedSmallInteger('notice_days')->nullable()->after('auto_renew');
            }
            if (! Schema::hasColumn('contracts', 'signed_ok')) {
                // فيه عقود ناقصة توقيع أو ختم طرف — لازم نعرف
                $table->boolean('signed_ok')->default(true)->after('notice_days');
            }
            if (! Schema::hasColumn('contracts', 'termination')) {
                $table->text('termination')->nullable()->after('note');
            }
            if (! Schema::hasColumn('contracts', 'renewal_note')) {
                $table->text('renewal_note')->nullable()->after('termination');
            }
        });

        // ---------- 2. client_id يبقى اختياري (العقد ممكن يبقى للسلسلة) ----------
        // ⚠️ ممنوع نغيّر nullable على عمود عليه FK بـ Blueprint في MySQL من غير
        // ما نفك الـ FK. أسهل وأأمن: DDL مباشر — العمود موجود من 000001.
        if (Schema::hasColumn('contracts', 'client_id')) {
            \Illuminate\Support\Facades\DB::statement(
                'ALTER TABLE contracts MODIFY client_id BIGINT UNSIGNED NULL'
            );
        }

        // ---------- 3. البنود ----------
        if (! Schema::hasTable('contract_clauses')) {
            Schema::create('contract_clauses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
                $table->string('kind', 24);
                $table->string('label', 400);
                $table->string('label_en', 400)->nullable();

                // نسبة أو مبلغ — واحد منهم على الأقل
                $table->decimal('pct', 6, 4)->nullable();
                $table->decimal('amount', 12, 2)->nullable();

                // per_invoice / monthly / quarterly / annual / one_off
                // per_item / per_branch / on_event / agreed
                $table->string('basis', 20)->default('agreed');

                // النص الأصلي زي ما هو في العقد — عشان نرجع له عند أي شك
                $table->string('raw_amount', 190)->nullable();
                $table->text('note')->nullable();

                // بند بديل لبند تاني (مش إضافة عليه) — زي credit note الفرانشايز
                $table->boolean('is_alternative')->default(false);
                // قراءة غير مؤكدة من العقد الممسوح — بيتعرض ومابيتحسبش
                $table->boolean('is_uncertain')->default(false);

                $table->unsignedSmallInteger('sort')->default(0);
                $table->timestamps();

                $table->index(['contract_id', 'kind']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_clauses');

        Schema::table('contracts', function (Blueprint $table) {
            foreach (['file_path', 'settlement_mode', 'withholding_pct', 'total_deduction_pct',
                'auto_renew', 'notice_days', 'signed_ok', 'termination', 'renewal_note'] as $column) {
                if (Schema::hasColumn('contracts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        if (Schema::hasColumn('contracts', 'group_id')) {
            Schema::table('contracts', function (Blueprint $table) {
                $table->dropConstrainedForeignId('group_id');
            });
        }

        // نرجّع client_id NOT NULL زي ما كان — بس بعد ما نمسح العقود اللي
        // مالهاش عميل، وإلا الـ ALTER هيفشل على صفوف NULL موجودة.
        if (Schema::hasColumn('contracts', 'client_id')) {
            \Illuminate\Support\Facades\DB::table('contracts')->whereNull('client_id')->delete();
            \Illuminate\Support\Facades\DB::statement(
                'ALTER TABLE contracts MODIFY client_id BIGINT UNSIGNED NOT NULL'
            );
        }
    }
};
