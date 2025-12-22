<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('marchands', function (Blueprint $table) {
            $table->decimal('solde_marchand', 12, 2)
                  ->default(0)
                  ->change();
        });
    }

    public function down(): void
    {
        Schema::table('marchands', function (Blueprint $table) {
            $table->integer('solde_marchand')
                  ->default(0)
                  ->change();
        });
    }
};
