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
        Schema::create('saisies_staging', function (Blueprint $table) {
            $table->id();

            // Référence de l'abonné concerné
            $table->string('reference', 14)->index();

            // Données saisies
            $table->string('cip', 15);
            $table->string('telephone', 15);
            $table->string('email', 100)->nullable();

            // Métadonnées de la saisie
            $table->date('datesais');                           // Date du jour automatique
            $table->string('nomop', 255)->default('Abonné (En ligne)'); // Nom de l'utilisateur connecté ou Abonné
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Sas de validation par l'administrateur
            $table->enum('statut', ['en_attente', 'valide', 'rejete'])->default('en_attente')->index();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->string('motif_rejet', 255)->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('saisies_staging');
    }
};
