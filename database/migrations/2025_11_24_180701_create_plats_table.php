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
        Schema::create('plats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nom_plat');
            $table->text('description_plat');
            $table->string('image_couverture');
            $table->json('autre_image')->nullable();
            $table->integer('prix_origine');
            $table->integer('prix_reduit');
            $table->integer('quantite_plat');
            $table->integer('quantite_disponible');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->uuid('id_marchand');

            $table->foreign('id_marchand')
                ->references('id')
                ->on('marchands')
                ->onDelete('cascade');

            $table->uuid('id_categorie');

            $table->foreign('id_categorie')
                ->references('id')
                ->on('categories')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plats');
    }
};
