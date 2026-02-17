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
        Schema::create('paiement_commandes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->json('data')->nullable();
            $table->integer('prix');
            $table->string('statut')->default('pending');

            $table->uuid('id_marchand');
            $table->foreign('id_marchand')
                ->references('id')
                ->on('marchands')
                ->onDelete('cascade');

            $table->uuid('id_commande');
            $table->foreign('id_commande')
                ->references('id')
                ->on('commandes')
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
        Schema::dropIfExists('paiement_commandes');
    }
};
