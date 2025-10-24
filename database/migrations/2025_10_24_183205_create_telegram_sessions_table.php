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
        Schema::create('telegram_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('telegram_id')->unique();
            $table->string('step')->nullable();
            $table->text('data')->nullable(); // JSON formatda ma'lumotlar
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('telegram_sessions');
    }
};
