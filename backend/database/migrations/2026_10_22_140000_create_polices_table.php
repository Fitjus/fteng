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
        Schema::create('polices', function (Blueprint $table) {
            $table->id();

            // Clé de référence active (14 caractères alphanumériques : L+B+N+R+K+S)
            $table->string('reference', 14)->unique()->index();

            // Ancienne référence (Col 131 Réf.préc., pour fallback)
            $table->string('ref_prec', 14)->nullable()->index();

            // Données administratives SONEB
            $table->unsignedTinyInteger('departement');         // Col 7 (D)
            $table->unsignedSmallInteger('agence');             // Col 8 (A)
            $table->unsignedSmallInteger('centre');             // Col 9 (C)

            // Informations compteur / contrat
            $table->string('police', 8)->nullable();            // Col 73 (N°Panneau)
            $table->char('etat_abo', 1)->nullable();            // Col 62 (Etat abt)
            $table->char('etat_comp', 1)->nullable();           // Col 63 (Etat cpt)

            // Informations d'identification supplémentaires
            $table->string('nom_abonne', 150)->nullable();       // Col 22 (Nom raison sociale)
            $table->string('ville', 100)->nullable();            // Col 26 (Ville)

            // Champs consolidés après validation définitive
            $table->string('cip', 15)->nullable();
            $table->string('telephone', 15)->nullable();
            $table->string('email', 100)->nullable();
            $table->date('datesais')->nullable();
            $table->string('nomop', 255)->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('polices');
    }
};
