<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE saisies_staging MODIFY nomop VARCHAR(255) NOT NULL DEFAULT \'Abonné (En ligne)\'');
            DB::statement('ALTER TABLE saisies_staging MODIFY email VARCHAR(100) NULL');
        } else {
            Schema::table('saisies_staging', function (Blueprint $table) {
                $table->string('nomop', 255)->default('Abonné (En ligne)')->change();
                $table->string('email', 100)->nullable()->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE saisies_staging MODIFY nomop VARCHAR(50) NOT NULL DEFAULT \'Abonné (En ligne)\'');
            DB::statement('ALTER TABLE saisies_staging MODIFY email VARCHAR(30) NULL');
        } else {
            Schema::table('saisies_staging', function (Blueprint $table) {
                $table->string('nomop', 50)->default('Abonné (En ligne)')->change();
                $table->string('email', 30)->nullable()->change();
            });
        }
    }
};
