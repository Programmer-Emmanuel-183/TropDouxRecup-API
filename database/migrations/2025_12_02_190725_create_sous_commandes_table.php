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
        Schema::create('sous_commandes', function (Blueprint $table) {
            $table->uuid('id');
            $table->string('commission');

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
            
            $table->uuid('id_plat');
            $table->foreign('id_plat')
                ->references('id')
                ->on('plats')
                ->onDelete('cascade');  
            
            $table->integer('quantite_plat');

            $table->uuid('id_marchand');
            $table->foreign('id_marchand')
                ->references('id')
                ->on('marchands')
                ->onDelete('cascade');    
            
            $table->string('statut');
            $table->string('code_commande');
            $table->text('code_qr');
            $table->string('date_de_recuperation')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sous_commandes');
    }
};
