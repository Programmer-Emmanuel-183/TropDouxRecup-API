<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('abonnements', function (Blueprint $table) {
            $table->string('icon_url')->nullable();
            $table->string('icon_bg_color')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('abonnements', function (Blueprint $table) {
            $table->dropColumn([
                'icon_url',
                'icon_bg_color',
            ]);
        });
    }
};
