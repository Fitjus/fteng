<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AbonneController;
use App\Http\Controllers\AdminController;

/*
|--------------------------------------------------------------------------
| API Routes SONEB
|--------------------------------------------------------------------------
*/

// 1. Authentification Google OAuth directe & Lien magique
Route::get('/auth/google/url', [AuthController::class, 'getGoogleAuthUrl']);
Route::post('/auth/google/callback', [AuthController::class, 'handleGoogleCallback']);
Route::post('/auth/magic-link', [AuthController::class, 'sendMagicLink']);
Route::post('/auth/verify-magic-link', [AuthController::class, 'verifyMagicLink']);

// 2. Routes protégées par Sanctum (Utilisateurs connectés & Administrateurs)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Consultation & Saisie abonné (Requiert une authentification)
    Route::get('/abonnes/{reference}', [AbonneController::class, 'show']);
    Route::post('/saisies', [AbonneController::class, 'storeSaisie']);

    // Module Administrateur (Staging & Validation & Export)
    Route::prefix('admin')->group(function () {
        Route::get('/staging', [AdminController::class, 'getStaging']);
        Route::post('/staging/{id}/valider', [AdminController::class, 'valider']);
        Route::post('/staging/{id}/rejeter', [AdminController::class, 'rejeter']);
        Route::get('/export', [AdminController::class, 'export']);
    });
});
