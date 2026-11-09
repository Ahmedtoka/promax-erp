<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الرولز المخصصة (٩/١١/٢٠٢٦) — رول بيتعمل من الشاشة فوق رول أساسي.
 * `base_role` بيحدد نطاق البيانات والسلوك، والمصفوفة بتحدد الشاشات بس.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('name_ar', 60);
            $t->string('name_en', 60);
            $t->string('base_role', 20);
            $t->string('cloned_from', 30)->nullable();
            $t->boolean('web_login')->default(false);
            $t->boolean('app_login')->default(false);
            $t->text('description_ar')->nullable();
            $t->text('description_en')->nullable();
            $t->boolean('active')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
