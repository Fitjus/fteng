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
        if (Schema::hasTable('saisies_staging')) {
            Schema::table('saisies_staging', function (Blueprint $table) {
                if (!Schema::hasColumn('saisies_staging', 'cip_document_data')) {
                    $table->longText('cip_document_data')->nullable()->after('cip_document_path');
                }
                if (!Schema::hasColumn('saisies_staging', 'cip_document_mime')) {
                    $table->string('cip_document_mime', 100)->nullable()->after('cip_document_data');
                }
                if (!Schema::hasColumn('saisies_staging', 'cip_document_name')) {
                    $table->string('cip_document_name', 255)->nullable()->after('cip_document_mime');
                }
            });
        }

        if (Schema::hasTable('polices')) {
            Schema::table('polices', function (Blueprint $table) {
                if (!Schema::hasColumn('polices', 'cip_document_data')) {
                    $table->longText('cip_document_data')->nullable()->after('cip_document_path');
                }
                if (!Schema::hasColumn('polices', 'cip_document_mime')) {
                    $table->string('cip_document_mime', 100)->nullable()->after('cip_document_data');
                }
                if (!Schema::hasColumn('polices', 'cip_document_name')) {
                    $table->string('cip_document_name', 255)->nullable()->after('cip_document_mime');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('saisies_staging')) {
            Schema::table('saisies_staging', function (Blueprint $table) {
                if (Schema::hasColumn('saisies_staging', 'cip_document_name')) {
                    $table->dropColumn('cip_document_name');
                }
                if (Schema::hasColumn('saisies_staging', 'cip_document_mime')) {
                    $table->dropColumn('cip_document_mime');
                }
                if (Schema::hasColumn('saisies_staging', 'cip_document_data')) {
                    $table->dropColumn('cip_document_data');
                }
            });
        }

        if (Schema::hasTable('polices')) {
            Schema::table('polices', function (Blueprint $table) {
                if (Schema::hasColumn('polices', 'cip_document_name')) {
                    $table->dropColumn('cip_document_name');
                }
                if (Schema::hasColumn('polices', 'cip_document_mime')) {
                    $table->dropColumn('cip_document_mime');
                }
                if (Schema::hasColumn('polices', 'cip_document_data')) {
                    $table->dropColumn('cip_document_data');
                }
            });
        }
    }
};
