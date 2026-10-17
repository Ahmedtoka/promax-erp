<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مفاتيح منع التكرار لأكشنات الموبايل (الخط ١، ١٧/١٠/٢٠٢٦).
 *
 * الدليل: INV-1167 وINV-1168 — نفس المندوب والعميل والمبلغ والورقية،
 * بينهم 37 ثانية. الشبكة فصلت والمندوب داس تاني.
 *
 * ⚠️ **جدول عام مش عمود على كل مستند** — الفاتورة والهدية وأمر التوريد
 * وأمر التجهيز وطلب الريفيل كلهم بيعدّوا على `App\Support\Idempotency`،
 * فمفيش عمود جديد على جداول المستندات.
 *
 * ⚠️ اليونيك على (المستخدم + الأكشن + المفتاح): ريكوستين بنفس المفتاح في
 * نفس اللحظة، التاني بيقف على الإندكس لحد ما الأول يخلص، وبعدها بيرجع
 * كله (مفيش خصم تاني) والكنترولر بيرجّع المستند الأول.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('idempotency_keys')) {
            return;
        }

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('action', 40);
            $table->string('idem_key', 64);
            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            // ⚠️ dateTime مش timestamp — promax-system §4
            $table->dateTime('created_at')->nullable();

            $table->unique(['user_id', 'action', 'idem_key'], 'idem_user_action_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
