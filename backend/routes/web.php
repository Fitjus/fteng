<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;
use App\Models\Police;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

Route::get('/', function () {
    return response()->json([
        'status'  => 'online',
        'service' => 'SONEB Backend API',
        'version' => '1.0.0',
    ]);
});

// Route d'initialisation et peuplement direct des abonnés dans Supabase
Route::get('/init-data', function () {
    // 1. Assurer l'administrateur
    User::updateOrCreate(
        ['email' => 'agbahide73@gmail.com'],
        [
            'name'     => 'Administrateur SONEB',
            'password' => Hash::make('admin123'),
            'is_admin' => true,
        ]
    );

    // 2. Importer le fichier polalada.xlsx
    $excelPath = database_path('data/polalada.xlsx');
    if (!file_exists($excelPath)) {
        return response()->json([
            'success' => false,
            'message' => 'Fichier polalada.xlsx introuvable sur le serveur.',
        ], 404);
    }

    $countBefore = Police::count();
    Artisan::call('soneb:import-police', [
        'file' => $excelPath,
    ]);
    $artisanOutput = Artisan::output();
    $countAfter = Police::count();

    return response()->json([
        'success'      => true,
        'message'      => 'Initialisation des données terminée avec succès.',
        'count_before' => $countBefore,
        'count_after'  => $countAfter,
        'details'      => $artisanOutput,
    ]);
});

// Route de test d'envoi d'e-mail direct et de diagnostic des ports
Route::get('/test-email', function (\Illuminate\Http\Request $request) {
    $to = $request->query('to', 'agbahide73@gmail.com');

    // Diagnostic des ports SMTP depuis Render
    $ports = [465, 587, 2525];
    $connectivity = [];
    foreach ($ports as $port) {
        $t0 = microtime(true);
        $fp = @fsockopen('smtp.gmail.com', $port, $errno, $errstr, 3);
        $dt = round((microtime(true) - $t0) * 1000);
        if ($fp) {
            fclose($fp);
            $connectivity[$port] = "OPEN ({$dt}ms)";
        } else {
            $connectivity[$port] = "BLOCKED / TIMEOUT: {$errstr} ({$errno})";
        }
    }

    try {
        \Illuminate\Support\Facades\Mail::html('<h3>Test SONEB Email</h3><p>Le service SMTP fonctionne parfaitement depuis Render !</p>', function ($m) use ($to) {
            $m->to($to)->subject('Test d\'envoi SONEB');
        });
        return response()->json([
            'status'        => 'success',
            'message'       => "E-mail de test envoyé avec succès à {$to}",
            'connectivity'  => $connectivity,
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'status'        => 'error',
            'message'       => $e->getMessage(),
            'connectivity'  => $connectivity,
        ], 500);
    }
});

