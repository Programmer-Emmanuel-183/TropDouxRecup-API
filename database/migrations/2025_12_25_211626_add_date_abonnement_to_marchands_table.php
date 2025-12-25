<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('marchands', function (Blueprint $table) {
            $table->dateTime('date_abonnement')
                  ->nullable()
                  ->after('id_abonnement');
        });
    }

    public function down(): void
    {
        Schema::table('marchands', function (Blueprint $table) {
            $table->dropColumn('date_abonnement');
        });
    }
};
