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
        Schema::create('favoris_plats', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('id_plat');

            $table->foreign('id_plat')
                ->references('id')
                ->on('plats')
                ->onDelete('cascade');

            
            $table->uuid('id_client');
            $table->foreign('id_client')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');   
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('favoris_plats');
    }
};
