<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class EmailService
{
    /**
     * Envoi d'un email transactionnel via HTTPS API (Resend / Brevo) ou SMTP classique
     */
    public static function sendEmail(string $to, string $subject, string $htmlContent, ?string $toName = null): array
    {
        $brevoKey  = env('BREVO_API_KEY', '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');
        $resendKey = env('RESEND_API_KEY', 're_eGMCerg9_8uHYjYiGBPkxwHC3w16nu1Bp');

        // 1. Essai prioritaire via Brevo API (HTTPS Port 443 - Envoie à TOUTES les adresses sans restriction de domaine)
        if (!empty($brevoKey)) {
            try {
                $response = Http::withHeaders([
                    'api-key'      => trim($brevoKey),
                    'Content-Type' => 'application/json',
                    'accept'       => 'application/json',
                ])->timeout(15)->post('https://api.brevo.com/v3/smtp/email', [
                    'sender'      => [
                        'name'  => env('MAIL_FROM_NAME', 'SONEB Bénin'),
                        'email' => env('MAIL_FROM_ADDRESS', 'sonebupdate@gmail.com'),
                    ],
                    'to'          => [
                        ['email' => $to, 'name' => $toName ?: $to]
                    ],
                    'subject'     => $subject,
                    'htmlContent' => $htmlContent,
                ]);

                if ($response->successful()) {
                    Log::info("E-mail envoyé avec succès via Brevo API à {$to}");
                    return [
                        'success'  => true,
                        'provider' => 'Brevo API (HTTPS)',
                        'details'  => $response->json(),
                    ];
                }

                Log::warning("Brevo API warning (" . $response->status() . "): " . $response->body());
            } catch (\Throwable $e) {
                Log::warning("Brevo API exception: " . $e->getMessage());
            }
        }

        // 2. Essai de secours via Resend API (HTTPS Port 443)
        if (!empty($resendKey)) {
            try {
                $fromSender = env('RESEND_FROM', 'SONEB <onboarding@resend.dev>');
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . trim($resendKey),
                    'Content-Type'  => 'application/json',
                ])->timeout(15)->post('https://api.resend.com/emails', [
                    'from'    => $fromSender,
                    'to'      => [$to],
                    'subject' => $subject,
                    'html'    => $htmlContent,
                ]);

                if ($response->successful()) {
                    Log::info("E-mail envoyé avec succès via Resend API à {$to}");
                    return [
                        'success'  => true,
                        'provider' => 'Resend API (HTTPS)',
                        'details'  => $response->json(),
                    ];
                }

                $errBody = $response->json();
                Log::warning("Resend API warning (" . $response->status() . "): " . $response->body());
                $resendError = $errBody['message'] ?? $response->body();
            } catch (\Throwable $e) {
                Log::warning("Resend API exception: " . $e->getMessage());
                $resendError = $e->getMessage();
            }
        }

        // 3. Repli vers SMTP classique
        try {
            Mail::html($htmlContent, function ($message) use ($to, $subject) {
                $sender = env('MAIL_FROM_ADDRESS', 'sonebupdate@gmail.com');
                $name   = env('MAIL_FROM_NAME', 'SONEB Bénin');
                $message->from($sender, $name)
                    ->replyTo($sender, $name)
                    ->to($to)
                    ->subject($subject);
            });
            return [
                'success'  => true,
                'provider' => 'SMTP',
                'details'  => 'Sent via SMTP',
            ];
        } catch (\Throwable $e) {
            Log::error("Échec définitif envoi email: " . $e->getMessage());
            return [
                'success'  => false,
                'provider' => 'none',
                'error'    => $resendError ?? $e->getMessage(),
            ];
        }
    }
}
