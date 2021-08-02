<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateFilesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('files', function (Blueprint $table) {
            $table->id();

            $table->string('name', 127);
            $table->string('type', 7);
            $table->string('file', 255);

            $table->smallInteger('width')->unsigned()->nullable()->default(null)->index();
            $table->smallInteger('height')->unsigned()->nullable()->default(null)->index();
            $table->index(['width', 'height']);

            $table->string('model_type', 255)->nullable()->default(null)->index();
            $table->bigInteger('model_id')->unsigned()->nullable()->default(null)->index();
            $table->index(['model_type', 'model_id']);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('files');
    }
}
