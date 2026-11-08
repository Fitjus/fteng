<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Police;
use App\Models\SaisieStaging;
use App\Services\Db2Service;
use Illuminate\Support\Facades\Log;

class AbonneController extends Controller
{
    protected Db2Service $db2;

    public function __construct(Db2Service $db2)
    {
        $this->db2 = $db2;
    }

    /**
     * Recherche d'un abonné par sa référence (14 car.) ou ancienne référence (fallback)
     */
    public function show(Request $request, string $reference)
    {
        $ref = strtoupper(trim($reference));

        // Tentative 1 : Interrogation directe d'IBM Db2 (Fichier POLPF) si configuré
        if (env('DB2_HOST') && $this->db2->isExtensionLoaded()) {
            try {
                $abonneDb2 = $this->db2->findAbonne($ref);
                if ($abonneDb2) {
                    return response()->json(array_merge(['found' => true], $abonneDb2));
                }
            } catch (\Throwable $e) {
                Log::warning("Recherche Db2 POLPF échouée ({$ref}), fallback local : " . $e->getMessage());
            }
        }

        // Tentative 2 : Recherche locale / Supabase (Fallback)
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

        // 3. Vérifier si les coordonnées ont déjà été saisies (déjà validées ou en attente)
        $isOfficiallyUpdated = !empty($abonne->telephone) || !empty($abonne->cip);

        $stagingPending = SaisieStaging::where('reference', $abonne->reference)
            ->where('statut', 'en_attente')
            ->latest()
            ->first();

        $stagingValide = SaisieStaging::where('reference', $abonne->reference)
            ->where('statut', 'valide')
            ->latest()
            ->first();

        $stagingRejete = SaisieStaging::where('reference', $abonne->reference)
            ->where('statut', 'rejete')
            ->latest()
            ->first();

        $alreadySubmitted = $isOfficiallyUpdated || ($stagingPending !== null) || ($stagingValide !== null);
        $submissionStatus = ($isOfficiallyUpdated || $stagingValide) ? 'valide' : ($stagingPending ? 'en_attente' : ($stagingRejete ? 'rejete' : null));

        // Déterminer les coordonnées actuelles à afficher
        $currentCip = $abonne->cip ?: $stagingPending?->cip ?: $stagingValide?->cip;
        $currentTel = $abonne->telephone ?: $stagingPending?->telephone ?: $stagingValide?->telephone;
        $currentEmail = $abonne->email ?: $stagingPending?->email ?: $stagingValide?->email;
        $currentDate = ($abonne->datesais ?: $stagingPending?->datesais ?: $stagingValide?->datesais)?->format('d/m/Y');
        $currentNomop = $abonne->nomop ?: $stagingPending?->nomop ?: $stagingValide?->nomop;

        return response()->json([
            'found'               => true,
            'reference'           => $abonne->reference,
            'ref_prec'            => $abonne->ref_prec,
            'departement'         => $abonne->departement,
            'agence'              => $abonne->agence,
            'centre'              => $abonne->centre,
            'police'              => $abonne->police,
            'etat_abo'            => $abonne->etat_abo,
            'etat_comp'           => $abonne->etat_comp,
            'nom_abonne'          => $abonne->nom_abonne,
            'ville'               => $abonne->ville,
            
            // Coordonnées et statut d'enregistrement anti-doublon
            'cip'                 => $currentCip,
            'telephone'           => $currentTel,
            'email'               => $currentEmail,
            'datesais'            => $currentDate,
            'nomop'               => $currentNomop,
            'already_submitted'   => $alreadySubmitted,
            'submission_status'   => $submissionStatus,
            'has_staging_pending' => $stagingPending !== null,
            'motif_rejet'         => ($submissionStatus === 'rejete') ? $stagingRejete?->motif_rejet : null,

            'staging_pending'     => $stagingPending ? [
                'cip'       => $stagingPending->cip,
                'telephone' => $stagingPending->telephone,
                'email'     => $stagingPending->email,
                'datesais'  => $stagingPending->datesais->format('d/m/Y'),
                'nomop'     => $stagingPending->nomop,
            ] : null,
        ]);
    }

    /**
     * Enregistrement des informations dans le système intermédiaire (staging)
     */
    public function storeSaisie(Request $request)
    {
        $validated = $request->validate([
            'reference'    => 'required|string|size:14',
            'cip'          => 'required|string|size:15|regex:/^[0-9]{15}$/',
            'telephone'    => 'required|string|size:10|regex:/^[0-9]{10}$/',
            'email'        => ['nullable', 'string', 'email:rfc', 'max:100', 'regex:/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/'],
            'cip_document' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ], [
            'cip.required'       => 'Le numéro CIP est obligatoire.',
            'cip.size'           => 'Le numéro CIP doit comporter exactement 15 chiffres (ex : 123456789012345).',
            'cip.regex'          => 'Le numéro CIP ne doit contenir aucun espace ni caractère spécial, uniquement 15 chiffres.',
            'telephone.required' => 'Le numéro de téléphone est obligatoire.',
            'telephone.size'     => 'Le numéro de téléphone doit comporter exactement 10 chiffres (ex : 0197000000).',
            'telephone.regex'    => 'Le numéro de téléphone ne doit contenir aucun espace ni caractère spécial, uniquement 10 chiffres.',
            'email.email'        => 'L\'adresse e-mail est invalide.',
            'email.regex'        => 'L\'adresse e-mail doit comporter un nom de domaine valide (ex : exemple@domaine.com, .fr, .bj, etc.).',
            'cip_document.mimes' => 'Le document de la carte CIP doit être au format PDF ou image (JPG, JPEG, PNG).',
            'cip_document.max'   => 'Le fichier de la carte CIP ne doit pas dépasser 10 Mo.',
        ]);

        $ref = strtoupper(trim($validated['reference']));

        // Recherche de l'abonné (Local/Supabase ou Db2 POLPF)
        $abonne = Police::where('reference', $ref)->first();
        $abonneDb2 = null;

        if (!$abonne && env('DB2_HOST') && $this->db2->isExtensionLoaded()) {
            try {
                $abonneDb2 = $this->db2->findAbonne($ref);
            } catch (\Throwable $e) {
                Log::warning("Recherche Db2 storeSaisie : " . $e->getMessage());
            }
        }

        if (!$abonne && !$abonneDb2) {
            return response()->json([
                'message' => 'Abonné inexistant. Vérifiez votre référence.',
            ], 404);
        }

        // 1. Empêcher toute double saisie :
        // a) Vérification dans POLPFZ (Table de staging AS/400)
        $existingDb2Staging = null;
        if (env('DB2_HOST') && $this->db2->isExtensionLoaded()) {
            try {
                $existingDb2Staging = $this->db2->findExistingSaisie($ref);
            } catch (\Throwable $e) {
                // Ignore
            }
        }

        // b) Vérification locale / Supabase
        $existingPending = SaisieStaging::where('reference', $ref)
            ->where('statut', 'en_attente')
            ->first();

        $isOfficiallyUpdated = (!empty($abonne?->telephone) || !empty($abonne?->cip))
            || (!empty($abonneDb2['telephone']) || !empty($abonneDb2['cip']));

        $existingValide = SaisieStaging::where('reference', $ref)
            ->where('statut', 'valide')
            ->first();

        if ($existingDb2Staging || $existingPending || $isOfficiallyUpdated || $existingValide) {
            return response()->json([
                'message'           => 'Les coordonnées de cet abonné ont déjà été enregistrées. Une nouvelle saisie n\'est pas autorisée.',
                'already_submitted' => true,
            ], 422);
        }

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
            $nomop = 'PORTAIL_WEB';
        }
        $userId = $user ? $user->id : null;

        // 2. INSERTION DANS LA TABLE DE STAGING POLPFZ D'IBM Db2
        if (env('DB2_HOST') && $this->db2->isExtensionLoaded()) {
            try {
                $this->db2->insertCoordonnees(
                    reference: $ref,
                    cip: $validated['cip'],
                    telephone: $validated['telephone'],
                    email: $validated['email'] ?? null,
                    nomop: $nomop,
                    cipDocPath: $cipDocPath
                );
                Log::info("Coordonnées insérées avec succès dans le staging POLPFZ d'IBM Db2 pour {$ref}");
            } catch (\Throwable $e) {
                Log::error("Erreur insertion dans staging POLPFZ Db2 pour {$ref} : " . $e->getMessage());
            }
        }

        // 3. Créer également l'enregistrement dans la table locale SaisieStaging (pour le dashboard admin web)
        $staging = SaisieStaging::create([
            'reference'         => $ref,
            'cip'               => $validated['cip'],
            'telephone'         => $validated['telephone'],
            'email'             => $validated['email'] ?? null,
            'cip_document_path' => $cipDocPath,
            'datesais'          => now()->toDateString(),
            'nomop'             => mb_substr($nomop, 0, 250),
            'user_id'           => $userId,
            'statut'            => 'en_attente',
        ]);

        // Envoi de la notification par e-mail à l'adresse inscrite parmi les coordonnées saisies
        $emailNotified = false;
        $destinataireEmail = !empty($validated['email']) ? trim($validated['email']) : null;

        if ($destinataireEmail) {
            try {
                $titulaire = htmlspecialchars($abonne?->nom_abonne ?? $abonneDb2['nom_abonne'] ?? 'Cher(e) Client(e)');
                $reference = htmlspecialchars($abonne?->reference ?? $ref);
                $police = htmlspecialchars($abonne?->police ?? $abonneDb2['police'] ?? '—');
                $ville = htmlspecialchars($abonne?->ville ?? $abonneDb2['ville'] ?? '—');
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
