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
        Schema::table('verifications', function (Blueprint $table) {
            // 1. حذف عمود النسبة لأنه لم يعد مطلوباً
            $table->dropColumn('percentage');           
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('verifications', function (Blueprint $table) {
            // في حالة التراجع: نعيد عمود النسبة ونحذف الهاش
            $table->integer('percentage')->default(0);            
        });
    }
};
