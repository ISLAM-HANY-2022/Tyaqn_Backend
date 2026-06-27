<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
{
    Schema::table('verifications', function (Blueprint $table) {
        // إضافة حقول النسب بعد حقل الشرح
        $table->decimal('ai_percentage', 5, 2)->default(0)->after('description_result');
        $table->decimal('real_percentage', 5, 2)->default(0)->after('ai_percentage');
    });
}

public function down()
{
    Schema::table('verifications', function (Blueprint $table) {
        $table->dropColumn(['ai_percentage', 'real_percentage']);
    });
}
};
