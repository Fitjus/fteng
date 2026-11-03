<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'status'  => 'online',
        'service' => 'SONEB Backend API',
        'version' => '1.0.0',
    ]);
});

