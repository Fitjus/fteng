<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\MagicLoginToken;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;

class AuthController extends Controller
{
    /**
     * E-mail unique de l'administrateur
     */
    const ADMIN_EMAIL = 'agbahide73@gmail.com';

    /**
     * Envoi d'un lien d'accès sécurisé (Magic Link) par e-mail
     */
    public function sendMagicLink(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ], [
            'email.required' => 'Veuillez saisir votre adresse e-mail.',
            'email.email'    => 'L\'adresse e-mail saisie est invalide.',
        ]);

        $email = strtolower(trim($request->email));
        $isAdmin = ($email === self::ADMIN_EMAIL);

        try {
            // 1. Rechercher ou créer le compte utilisateur
            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'name'     => $isAdmin ? 'Administrateur SONEB' : ucfirst(explode('@', $email)[0]),
                    'password' => Hash::make(Str::random(32)),
                    'is_admin' => $isAdmin,
                ]
            );

            // Garantir strictement le statut admin
            if ($user->is_admin !== $isAdmin) {
                $user->is_admin = $isAdmin;
                $user->save();
            }

            // 2. Révoquer les anciens jetons non utilisés pour cet email
            MagicLoginToken::where('email', $email)->where('used', false)->delete();

            // 3. Générer un jeton cryptographiquement sécurisé
            $token = Str::random(64);
            MagicLoginToken::create([
                'email'      => $email,
                'token'      => $token,
                'expires_at' => Carbon::now()->addMinutes(30),
                'used'       => false,
            ]);
        } catch (\Illuminate\Database\QueryException | \PDOException $e) {
            Log::error("Erreur base de données sendMagicLink : " . $e->getMessage());
            return response()->json([
                'message' => 'La base de données MySQL est inaccessible. Veuillez vous assurer que le service MySQL est démarré dans le panneau XAMPP.',
            ], 503);
        }

        // 4. Construire l'URL du lien magique
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:5173');
        $magicUrl = "{$frontendUrl}?token={$token}";

        // 5. Tenter l'envoi de l'e-mail
        try {
            Mail::raw(
                "Bonjour,\n\nVoici votre lien d'accès sécurisé pour la plateforme SONEB :\n\n{$magicUrl}\n\nCe lien est strictement personnel et expire dans 30 minutes.\n\nCordialement,\nSociété Nationale des Eaux du Bénin (SONEB)",
                function ($message) use ($email) {
                    $message->to($email)->subject("Votre lien d'accès sécurisé — SONEB");
                }
            );
        } catch (\Throwable $e) {
            Log::warning("Envoi d'e-mail Magic Link non effectué (mode local / SMTP non configuré) : " . $e->getMessage());
        }

        return response()->json([
            'message'   => 'Un lien d\'accès sécurisé a été envoyé à votre adresse e-mail.',
            'email'     => $email,
            'magic_url' => $magicUrl, // Fourni en local pour faciliter les tests directs
        ]);
    }

    /**
     * Vérification du lien magique et connexion de l'utilisateur
     */
    public function verifyMagicLink(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        try {
            $magicToken = MagicLoginToken::where('token', $request->token)
                ->where('used', false)
                ->where('expires_at', '>', Carbon::now())
                ->first();

            if (!$magicToken) {
                return response()->json([
                    'message' => 'Ce lien de connexion est expiré ou a déjà été utilisé. Veuillez demander un nouveau lien.',
                ], 401);
            }

            // Marquer le jeton comme utilisé
            $magicToken->update(['used' => true]);

            // Récupérer l'utilisateur
            $email = $magicToken->email;
            $isAdmin = ($email === self::ADMIN_EMAIL);

            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'name'     => $isAdmin ? 'Administrateur SONEB' : ucfirst(explode('@', $email)[0]),
                    'password' => Hash::make(Str::random(32)),
                    'is_admin' => $isAdmin,
                ]
            );

            if ($user->is_admin !== $isAdmin) {
                $user->is_admin = $isAdmin;
                $user->save();
            }

            // Régénérer le token Sanctum
            $user->tokens()->delete();
            $authToken = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message' => 'Connexion réussie',
                'token'   => $authToken,
                'user'    => [
                    'id'       => $user->id,
                    'name'     => $user->name,
                    'email'    => $user->email,
                    'is_admin' => (bool)$user->is_admin,
                ],
            ]);
        } catch (\Illuminate\Database\QueryException | \PDOException $e) {
            Log::error("Erreur base de données verifyMagicLink : " . $e->getMessage());
            return response()->json([
                'message' => 'La base de données MySQL est inaccessible. Veuillez vous assurer que le service MySQL est démarré dans le panneau XAMPP.',
            ], 503);
        }
    }

    /**
     * Profil de l'utilisateur connecté
     */
    public function me(Request $request)
    {
        $user = $request->user();
        return response()->json([
            'user' => [
                'id'       => $user->id,
                'name'     => $user->name,
                'email'    => $user->email,
                'is_admin' => (bool)$user->is_admin,
            ],
        ]);
    }

    /**
     * Déconnexion
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Déconnexion effectuée avec succès',
        ]);
    }

    /**
     * Générer l'URL de redirection directe vers Google avec login_hint
     */
    public function getGoogleAuthUrl(Request $request)
    {
        $clientId = env('GOOGLE_CLIENT_ID');
        $redirectUri = $request->query('redirect_uri') ?: env('GOOGLE_REDIRECT_URI', 'http://localhost:5173');

        if (!$clientId) {
            return response()->json([
                'message' => 'L\'identifiant client Google (GOOGLE_CLIENT_ID) n\'est pas encore configuré dans le fichier .env.',
            ], 400);
        }

        $email = $request->query('email', '');

        $params = [
            'client_id'             => $clientId,
            'redirect_uri'          => $redirectUri,
            'response_type'         => 'code',
            'scope'                 => 'openid email profile',
            'access_type'           => 'offline',
            'include_granted_scopes'=> 'true',
            'prompt'                => 'select_account',
        ];

        // login_hint permet d'atterrir DIRECTEMENT sur la saisie du mot de passe pour cet e-mail
        if (!empty($email)) {
            $params['login_hint'] = trim($email);
        }

        $url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);

        return response()->json([
            'url' => $url,
        ]);
    }

    /**
     * Traiter le retour de Google avec le code d'autorisation
     */
    public function handleGoogleCallback(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        $clientId = env('GOOGLE_CLIENT_ID');
        $clientSecret = env('GOOGLE_CLIENT_SECRET');
        $redirectUri = $request->input('redirect_uri') ?: env('GOOGLE_REDIRECT_URI', 'http://localhost:5173');

        // 1. Échanger le code contre un jeton d'accès auprès de Google avec mécanisme de reprise (retry)
        $tokenResponse = null;
        $connectionError = null;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $tokenResponse = \Illuminate\Support\Facades\Http::withoutVerifying()
                    ->withOptions([
                        'connect_timeout' => 10,
                        'curl' => [
                            CURLOPT_IPRESOLVE       => CURL_IPRESOLVE_V4,
                            CURLOPT_FRESH_CONNECT   => true,
                            CURLOPT_FORBID_REUSE    => true,
                            CURLOPT_SSL_VERIFYPEER  => false,
                            CURLOPT_SSL_VERIFYHOST  => 0,
                        ],
                    ])
                    ->timeout(30)
                    ->asForm()
                    ->post('https://oauth2.googleapis.com/token', [
                        'client_id'     => $clientId,
                        'client_secret' => $clientSecret,
                        'code'          => $request->code,
                        'grant_type'    => 'authorization_code',
                        'redirect_uri'  => $redirectUri,
                    ]);

                // Si la requête a abouti (succès ou erreur HTTP de Google)
                if ($tokenResponse) {
                    $connectionError = null;
                    break;
                }
            } catch (\Illuminate\Http\Client\ConnectionException | \Exception $e) {
                $connectionError = $e->getMessage();
                Log::warning("Tentative {$attempt}/3 échange token Google échouée : " . $connectionError);
                if ($attempt < 3) {
                    usleep(500000); // Pause de 500ms avant nouvelle tentative
                }
            }
        }

        // Si toutes les tentatives de connexion réseau ont échoué
        if ($tokenResponse === null) {
            Log::error('Échec définitif connexion Google token : ' . $connectionError);
            return response()->json([
                'message' => 'Délai de connexion dépassé avec les serveurs Google. Veuillez vérifier votre connexion Internet et réessayer.',
            ], 504);
        }

        // Si Google a renvoyé un code d'erreur HTTP (ex: 400 invalid_grant car le code a déjà été utilisé ou est expiré)
        if (!$tokenResponse->successful()) {
            $errorBody = $tokenResponse->json();
            $errorType = $errorBody['error'] ?? 'unknown';
            Log::error('Erreur échange token Google (' . $tokenResponse->status() . '): ' . $tokenResponse->body());

            if ($errorType === 'invalid_grant') {
                return response()->json([
                    'message' => 'Le code d\'authentification Google a expiré ou a déjà été utilisé. Veuillez relancer la connexion.',
                ], 400);
            }

            return response()->json([
                'message' => 'La validation auprès de Google a échoué. Veuillez réessayer.',
            ], 400);
        }

        $responseData = $tokenResponse->json();
        $idToken = $responseData['id_token'] ?? null;
        $accessToken = $responseData['access_token'] ?? null;

        $email = null;
        $realName = null;

        // 2. Extraire directement les informations certifiées depuis id_token (JWT Google)
        // Ceci évite un appel réseau supplémentaire vers www.googleapis.com susceptible d'expirer
        if ($idToken) {
            $jwtParts = explode('.', $idToken);
            if (isset($jwtParts[1])) {
                $payloadJson = base64_decode(strtr($jwtParts[1], '-_', '+/'));
                $payload = json_decode($payloadJson, true);
                if (!empty($payload['email'])) {
                    $email = strtolower(trim($payload['email']));
                    $realName = !empty($payload['name']) 
                        ? trim($payload['name']) 
                        : ucfirst(explode('@', $email)[0]);
                }
            }
        }

        // Si l'id_token n'a pas pu être lu, appel de secours vers userinfo
        if (empty($email) && !empty($accessToken)) {
            try {
                $userinfoResponse = \Illuminate\Support\Facades\Http::withoutVerifying()
                    ->withOptions([
                        'connect_timeout' => 8,
                        'curl' => [
                            CURLOPT_IPRESOLVE       => CURL_IPRESOLVE_V4,
                            CURLOPT_FRESH_CONNECT   => true,
                            CURLOPT_FORBID_REUSE    => true,
                            CURLOPT_SSL_VERIFYPEER  => false,
                            CURLOPT_SSL_VERIFYHOST  => 0,
                        ],
                    ])
                    ->timeout(20)
                    ->withToken($accessToken)
                    ->get('https://www.googleapis.com/oauth2/v3/userinfo');

                if ($userinfoResponse->successful()) {
                    $googleUser = $userinfoResponse->json();
                    $email = strtolower(trim($googleUser['email']));
                    $realName = !empty($googleUser['name']) 
                        ? trim($googleUser['name']) 
                        : ucfirst(explode('@', $email)[0]);
                }
            } catch (\Exception $e) {
                Log::warning('Fallback userinfo failed: ' . $e->getMessage());
            }
        }

        if (empty($email)) {
            return response()->json([
                'message' => 'Impossible de récupérer l\'adresse e-mail associée au compte Google. Veuillez réessayer.',
            ], 400);
        }

        $isAdmin = ($email === self::ADMIN_EMAIL);

        // 3. Rechercher ou créer le compte dans notre base avec le véritable nom Google
        try {
            $user = User::where('email', $email)->first();
            if (!$user) {
                $user = User::create([
                    'email'    => $email,
                    'name'     => $isAdmin ? 'Administrateur SONEB' : $realName,
                    'password' => Hash::make(Str::random(32)),
                    'is_admin' => $isAdmin,
                ]);
            } else {
                // Mettre à jour avec le véritable nom Google et statut admin
                if (!$isAdmin && !empty($realName)) {
                    $user->name = $realName;
                }
                if ($user->is_admin !== $isAdmin) {
                    $user->is_admin = $isAdmin;
                }
                $user->save();
            }

            // 4. Générer le token de session Sanctum
            $user->tokens()->delete();
            $authToken = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message' => 'Connexion Google réussie',
                'token'   => $authToken,
                'user'    => [
                    'id'       => $user->id,
                    'name'     => $user->name,
                    'email'    => $user->email,
                    'is_admin' => (bool)$user->is_admin,
                ],
            ]);
        } catch (\Illuminate\Database\QueryException | \PDOException $e) {
            Log::error("Erreur base de données handleGoogleCallback : " . $e->getMessage());
            return response()->json([
                'message' => 'La base de données MySQL est inaccessible. Veuillez vous assurer que le service MySQL est démarré dans le panneau XAMPP puis réessayez.',
            ], 503);
        }
    }
}
