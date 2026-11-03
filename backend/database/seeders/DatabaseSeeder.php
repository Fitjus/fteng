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
    }
}
