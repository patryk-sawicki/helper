<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('fileables', function (Blueprint $table) {
            $table->integer('position', false, true)->nullable()->default(null)->after('type')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fileables', function (Blueprint $table) {
            $table->dropColumn('position');
        });
    }
};
