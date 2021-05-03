<?php

use App\Models\File;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTableFileModel extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('fileables', function (Blueprint $table) {
            $table->foreignIdFor(File::class)->constrained();

            $table->string('fileable_type', 255)->nullable()->default(null)->index();
            $table->bigInteger('fileable_id')->unsigned()->nullable()->default(null)->index();
            $table->index(['fileable_type', 'fileable_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('fileables');
    }
}
