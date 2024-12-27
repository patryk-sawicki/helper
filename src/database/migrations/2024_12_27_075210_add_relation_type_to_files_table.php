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
        Schema::table('files', function (Blueprint $table) {
            $table->string('relation_type', 63)->nullable()->default(null)->after('model_id');
        });

        $fileClass = config('filesSettings.fileClass');

        if ($fileClass) {
            DB::table('files')
                ->where('model_type', '=', $fileClass)
                ->update(['relation_type' => 'thumbnails']);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropColumn('relation_type');
        });
    }
};
