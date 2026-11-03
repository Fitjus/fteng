<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Réinitialiser les autres comptes en non-admin par sécurité
        User::where('email', '!=', 'agbahide73@gmail.com')->update(['is_admin' => false]);

        // 1. Compte Administrateur unique SONEB
        User::updateOrCreate(
            ['email' => 'agbahide73@gmail.com'],
            [
                'name'     => 'Administrateur SONEB',
                'password' => Hash::make('admin123'),
                'is_admin' => true,
            ]
        );

        // 2. Importer automatiquement les abonnés depuis polalada.xlsx si la table est vide
        $excelPath = database_path('data/polalada.xlsx');
        if (file_exists($excelPath) && \App\Models\Police::count() === 0) {
            \Illuminate\Support\Facades\Artisan::call('soneb:import-police', [
                'path' => $excelPath,
            ]);
        }
    }
}
