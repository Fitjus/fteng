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
        if (Schema::hasTable('saisies_staging') && !Schema::hasColumn('saisies_staging', 'cip_document_path')) {
            Schema::table('saisies_staging', function (Blueprint $table) {
                $table->string('cip_document_path', 255)->nullable()->after('email');
            });
        }

        if (Schema::hasTable('polices') && !Schema::hasColumn('polices', 'cip_document_path')) {
            Schema::table('polices', function (Blueprint $table) {
                $table->string('cip_document_path', 255)->nullable()->after('email');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('saisies_staging') && Schema::hasColumn('saisies_staging', 'cip_document_path')) {
            Schema::table('saisies_staging', function (Blueprint $table) {
                $table->dropColumn('cip_document_path');
            });
        }

        if (Schema::hasTable('polices') && Schema::hasColumn('polices', 'cip_document_path')) {
            Schema::table('polices', function (Blueprint $table) {
                $table->dropColumn('cip_document_path');
            });
        }
    }
};
