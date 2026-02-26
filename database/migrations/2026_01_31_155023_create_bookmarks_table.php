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
        Schema::create('bookmarks', function (Blueprint $table) {
            $table->id();
            // الربط بالمستخدم
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            // الربط بالمقال
            $table->foreignId('article_id')->constrained()->onDelete('cascade');
            $table->timestamps();
    
            // السطر ده مهم جداً عشان يمنع المستخدم إنه يحفظ نفس المقال مرتين
            $table->unique(['user_id', 'article_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookmarks');
    }
};
