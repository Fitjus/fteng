<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Police;
use App\Models\SaisieStaging;

class AbonneController extends Controller
{
    /**
     * Recherche d'un abonné par sa référence (14 car.) ou ancienne référence (fallback)
     */
    public function show(Request $request, string $reference)
    {
        $ref = strtoupper(trim($reference));

        // 1. Recherche par référence active
        $abonne = Police::where('reference', $ref)->first();

        // 2. Fallback par ancienne référence (Réf.préc.)
        if (!$abonne) {
            $abonne = Police::where('ref_prec', $ref)->first();
        }

        // Si introuvable : Message strict du cahier des charges
        if (!$abonne) {
            return response()->json([
                'found'   => false,
                'message' => 'Abonné inexistant. Vérifiez votre référence.',
            ], 404);
        }

        // 3. Vérifier si une saisie est déjà en cours dans le staging
        $staging = SaisieStaging::where('reference', $abonne->reference)
            ->where('statut', 'en_attente')
            ->latest()
            ->first();

        return response()->json([
            'found'         => true,
            'reference'     => $abonne->reference,
            'ref_prec'      => $abonne->ref_prec,
            'departement'   => $abonne->departement,
            'agence'        => $abonne->agence,
            'centre'        => $abonne->centre,
            'police'        => $abonne->police,
            'etat_abo'      => $abonne->etat_abo,
            'etat_comp'     => $abonne->etat_comp,
            'nom_abonne'    => $abonne->nom_abonne,
            'ville'         => $abonne->ville,
            
            // Données officielles déjà consolidées
            'cip'           => $abonne->cip,
            'telephone'     => $abonne->telephone,
            'email'         => $abonne->email,
            'datesais'      => $abonne->datesais?->format('Y-m-d'),
            'nomop'         => $abonne->nomop,

            // Statut de staging en cours
            'has_staging_pending' => $staging !== null,
            'staging_pending'     => $staging ? [
                'cip'       => $staging->cip,
                'telephone' => $staging->telephone,
                'email'     => $staging->email,
                'datesais'  => $staging->datesais->format('Y-m-d'),
                'nomop'     => $staging->nomop,
            ] : null,
        ]);
    }

    /**
     * Enregistrement des informations dans le système intermédiaire (staging)
     */
    public function storeSaisie(Request $request)
    {
        $validated = $request->validate([
            'reference'    => 'required|string|size:14|exists:polices,reference',
            'cip'          => 'required|string|max:15|regex:/^[0-9]+$/',
            'telephone'    => 'required|string|min:8|max:15|regex:/^[0-9]+$/',
            'email'        => 'nullable|email|max:100',
            'cip_document' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ], [
            'cip.regex'          => 'Le CIP doit être composé uniquement de chiffres (sans espaces ni caractères spéciaux, max 15).',
            'telephone.regex'    => 'Le numéro de téléphone ne doit contenir aucun espace ni caractère spécial, uniquement des chiffres.',
            'telephone.min'      => 'Le numéro de téléphone doit comporter au moins 8 chiffres.',
            'telephone.max'      => 'Le numéro de téléphone ne doit pas dépasser 15 chiffres.',
            'email.email'        => 'L\'adresse e-mail est invalide.',
            'cip_document.mimes' => 'Le document de la carte CIP doit être au format PDF ou image (JPG, JPEG, PNG).',
            'cip_document.max'   => 'Le fichier de la carte CIP ne doit pas dépasser 10 Mo.',
        ]);

        $abonne = Police::where('reference', $validated['reference'])->firstOrFail();

        // Enregistrement sécurisé du scan de la carte CIP si fourni (facultatif)
        $cipDocPath = null;
        if ($request->hasFile('cip_document') && $request->file('cip_document')->isValid()) {
            $cipDocPath = $request->file('cip_document')->store('cip_documents', 'public');
        }

        // Récupérer l'utilisateur connecté s'il y en a un (nom véritable + email sans 'Abonné')
        $user = auth('sanctum')->user() ?? $request->user();
        if ($user) {
            $cleanName = trim(preg_replace('/^Abonné\s+/iu', '', $user->name));
            $nomop = $user->email ? "{$cleanName} ({$user->email})" : $cleanName;
        } else {
            $nomop = 'En ligne';
        }
        $userId = $user ? $user->id : null;

        // Créer l'enregistrement dans la table tampon de staging
        $staging = SaisieStaging::create([
            'reference'         => $abonne->reference,
            'cip'               => $validated['cip'],
            'telephone'         => $validated['telephone'],
            'email'             => $validated['email'] ?? null,
            'cip_document_path' => $cipDocPath,
            'datesais'          => now()->toDateString(),                        // Date du jour automatique
            'nomop'             => mb_substr($nomop, 0, 250),                    // Nom véritable et email lié
            'user_id'           => $userId,
            'statut'            => 'en_attente',
        ]);

        // Envoi de la notification par e-mail à l'adresse inscrite parmi les coordonnées saisies
        $emailNotified = false;
        $destinataireEmail = !empty($validated['email']) ? trim($validated['email']) : null;

        if ($destinataireEmail) {
            try {
                $titulaire = htmlspecialchars($abonne->nom_abonne ?: 'Cher(e) Client(e)');
                $reference = htmlspecialchars($abonne->reference);
                $police = htmlspecialchars($abonne->police ?: '—');
                $ville = htmlspecialchars($abonne->ville ?: '—');
                $cip = htmlspecialchars($validated['cip']);
                $tel = htmlspecialchars($validated['telephone']);
                $dateSaisie = date('d/m/Y à H:i');
                $operateur = htmlspecialchars($nomop);

                $htmlContent = "
                    <div style='font-family: Arial, Helvetica, sans-serif; color: #1e293b; line-height: 1.6; max-width: 600px; margin: 0 auto; border: 1px solid #cbd5e1; border-radius: 12px; padding: 25px; background-color: #ffffff;'>
                        <div style='text-align: center; border-bottom: 2px solid #005ea2; padding-bottom: 15px; margin-bottom: 20px;'>
                            <h2 style='color: #005ea2; margin: 0; font-size: 20px;'>Société Nationale des Eaux du Bénin (SONEB)</h2>
                            <p style='color: #64748b; font-size: 13px; margin: 4px 0 0 0;'>Direction Commerciale et Clientèle</p>
                        </div>

                        <p style='font-size: 14px;'>Bonjour <strong>{$titulaire}</strong>,</p>

                        <p style='font-size: 14px;'>
                            Nous vous confirmons que votre adresse e-mail (<strong>{$destinataireEmail}</strong>) vient d'être enregistrée avec succès lors de la mise à jour des coordonnées pour votre abonnement SONEB.
                        </p>

                        <div style='background-color: #f8fafc; border-left: 4px solid #005ea2; padding: 15px; margin: 20px 0; border-radius: 6px;'>
                            <p style='margin: 4px 0; font-size: 13px;'><strong>Référence Abonné :</strong> <span style='font-family: monospace; color: #005ea2; font-weight: bold;'>{$reference}</span></p>
                            <p style='margin: 4px 0; font-size: 13px;'><strong>Numéro de Police :</strong> {$police}</p>
                            <p style='margin: 4px 0; font-size: 13px;'><strong>Titulaire :</strong> {$titulaire}</p>
                            <p style='margin: 4px 0; font-size: 13px;'><strong>Ville :</strong> {$ville}</p>
                            <p style='margin: 4px 0; font-size: 13px;'><strong>Numéro CIP enregistré :</strong> {$cip}</p>
                            <p style='margin: 4px 0; font-size: 13px;'><strong>Téléphone enregistré :</strong> {$tel}</p>
                            <p style='margin: 4px 0; font-size: 13px;'><strong>Scan pièce CIP :</strong> " . ($cipDocPath ? "<span style='color: #16a34a; font-weight: bold;'>Pièce scannée jointe</span>" : "<span style='color: #94a3b8;'>Non fourni (facultatif)</span>") . "</p>
                            <p style='margin: 4px 0; font-size: 13px;'><strong>Date et Heure :</strong> {$dateSaisie}</p>
                            <p style='margin: 4px 0; font-size: 13px; color: #64748b;'><strong>Opéré par :</strong> {$operateur}</p>
                        </div>

                        <p style='font-size: 13px; color: #334155;'>
                            Vos coordonnées sont actuellement transmises au service de validation intermédiaire de la SONEB pour confirmation officielle dans notre base centrale.
                        </p>

                        <div style='border-top: 1px solid #e2e8f0; padding-top: 15px; margin-top: 25px;'>
                            <p style='font-size: 12px; color: #94a3b8; font-style: italic; margin: 0;'>
                                Si vous n'êtes pas à l'origine de cette mise à jour, veuillez contacter sans délai votre agence SONEB locale ou notre service d'assistance.
                            </p>
                            <p style='margin-top: 15px; font-weight: bold; color: #005ea2; font-size: 13px;'>
                                Société Nationale des Eaux du Bénin (SONEB)
                            </p>
                        </div>
                    </div>
                ";

                $subject = "SONEB — Confirmation d'enregistrement de vos coordonnées ({$reference})";
                $result = \App\Services\EmailService::sendEmail($destinataireEmail, $subject, $htmlContent, $titulaire);
                $emailNotified = $result['success'] ?? false;
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("Erreur envoi email notification mise a jour : " . $e->getMessage());
            }
        }

        return response()->json([
            'message' => 'Vos informations ont été enregistrées avec succès dans le sas de validation.',
            'staging' => $staging,
            'email_notified' => $emailNotified,
            'notified_email' => $destinataireEmail,
        ], 201);
    }
}
