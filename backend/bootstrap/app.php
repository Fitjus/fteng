<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (\Illuminate\Database\QueryException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*')) {
                if ($e->getCode() == 2002 || str_contains($e->getMessage(), '2002') || str_contains($e->getMessage(), 'refusée')) {
                    return response()->json([
                        'message' => 'La base de données MySQL est inaccessible. Veuillez vous assurer que le service MySQL est bien démarré dans le panneau XAMPP Control Panel.',
                    ], 503);
                }
            }
        });

        $exceptions->render(function (\Illuminate\Http\Client\ConnectionException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Délai de connexion dépassé avec les serveurs externes (Google). Veuillez vérifier votre connexion Internet et réessayer.',
                ], 504);
            }
        });
    })->create();
