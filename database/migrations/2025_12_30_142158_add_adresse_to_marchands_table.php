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
        Schema::table('marchands', function (Blueprint $table) {
            $table->string('adresse_marchand')->nullable()->after('tel_marchand');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::table('marchands', function (Blueprint $table) {
            $table->dropColumn('adresse_marchand');
        });
    }
};
