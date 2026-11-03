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
        DB::statement('ALTER TABLE saisies_staging MODIFY nomop VARCHAR(255) NOT NULL DEFAULT \'Abonné (En ligne)\'');
        DB::statement('ALTER TABLE saisies_staging MODIFY email VARCHAR(100) NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE saisies_staging MODIFY nomop VARCHAR(50) NOT NULL DEFAULT \'Abonné (En ligne)\'');
        DB::statement('ALTER TABLE saisies_staging MODIFY email VARCHAR(30) NULL');
    }
};
