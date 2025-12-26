<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sous_commandes', function (Blueprint $table) {

            // 🔴 Supprimer anciennes foreign keys
            $table->dropForeign(['id_client']);
            $table->dropForeign(['id_plat']);
            $table->dropForeign(['id_marchand']);

            // 🟢 Rendre nullable
            $table->uuid('id_client')->nullable()->change();
            $table->uuid('id_plat')->nullable()->change();
            $table->uuid('id_marchand')->nullable()->change();

            // 🟢 Recréer les foreign keys avec SET NULL
            $table->foreign('id_client')
                ->references('id')
                ->on('users')
                ->onDelete('set null');

            $table->foreign('id_plat')
                ->references('id')
                ->on('plats')
                ->onDelete('set null');

            $table->foreign('id_marchand')
                ->references('id')
                ->on('marchands')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('sous_commandes', function (Blueprint $table) {

            // 🔴 Supprimer SET NULL
            $table->dropForeign(['id_client']);
            $table->dropForeign(['id_plat']);
            $table->dropForeign(['id_marchand']);

            // 🔁 Revenir à NOT NULL
            $table->uuid('id_client')->nullable(false)->change();
            $table->uuid('id_plat')->nullable(false)->change();
            $table->uuid('id_marchand')->nullable(false)->change();

            // 🔁 Recréer CASCADE
            $table->foreign('id_client')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->foreign('id_plat')
                ->references('id')
                ->on('plats')
                ->onDelete('cascade');

            $table->foreign('id_marchand')
                ->references('id')
                ->on('marchands')
                ->onDelete('cascade');
        });
    }
};
