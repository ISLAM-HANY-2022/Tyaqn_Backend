<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            // 1. إضافة الربط مع جدول التحققات
            // التأكد أولاً إن العمود مش موجود عشان ميعملش Error تاني
            if (!Schema::hasColumn('articles', 'verification_id')) {
                $table->foreignId('verification_id')
                      ->nullable()
                      ->after('category_id')
                      ->constrained('verifications')
                      ->onDelete('set null');
            }
    
            // ملاحظة: شيلنا كل كود الـ user_id لأنه مش موجود في الجدول أصلاً
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropForeign(['verification_id']);
            $table->dropColumn('verification_id');
        });
    }
};
