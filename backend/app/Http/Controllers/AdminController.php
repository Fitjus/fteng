<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Police;
use App\Models\SaisieStaging;
use App\Services\Db2Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class AdminController extends Controller
{
    protected Db2Service $db2;

    public function __construct(Db2Service $db2)
    {
        $this->db2 = $db2;
    }

    /**
     * Vérifier que l'utilisateur est bien admin
     */
    protected function checkAdmin(Request $request)
    {
        if (!$request->user() || !$request->user()->is_admin) {
            abort(403, 'Accès réservé aux administrateurs SONEB.');
        }
    }

    /**
     * Liste des saisies dans le staging avec filtres
     */
    public function getStaging(Request $request)
    {
        $this->checkAdmin($request);

        $query = SaisieStaging::with(['user', 'admin']);

        // Filtre par statut (en_attente, valide, rejete)
        if ($request->has('statut') && in_array($request->statut, ['en_attente', 'valide', 'rejete'])) {
            $query->where('statut', $request->statut);
        }

        // Filtre par référence
        if ($request->filled('reference')) {
            $query->where('reference', 'LIKE', '%' . trim($request->reference) . '%');
        }

        $saisies = $query->latest()->paginate(15);

        return response()->json($saisies);
    }

    /**
     * Valider une saisie en staging : met à jour la table officielle `polices`
     */
    public function valider(Request $request, $id)
    {
        $this->checkAdmin($request);

        return DB::transaction(function () use ($id, $request) {
            $staging = SaisieStaging::where('id', $id)
                ->where('statut', 'en_attente')
                ->lockForUpdate()
                ->firstOrFail();

            // 1. Mettre à jour la table officielle SONEB (Local / Supabase)
            // 1. Tenter la mise à jour de la table officielle POLPF sur IBM Db2 si configuré et joignable
            if (env('DB2_HOST') && $this->db2->isExtensionLoaded()) {
                try {
                    $this->db2->updateAbonneOfficial(
                        reference: $staging->reference,
                        cip: $staging->cip,
                        telephone: $staging->telephone,
                        email: $staging->email,
                        nomop: $request->user()->name ?? 'ADMIN_SONEB'
                    );
                    Log::info("Coordonnées consolidées dans POLPF (IBM Db2) pour {$staging->reference}");
                } catch (\Throwable $e) {
                    Log::warning("Serveur Db2 injoignable lors de la validation ({$staging->reference}), mise à jour locale Supabase appliquée : " . $e->getMessage());
                }
            }

            // 2. Mettre à jour la table officielle SONEB dans Supabase / local
            $police = Police::where('reference', $staging->reference)->first();
            if ($police) {
                $police->update([
                    'cip'               => $staging->cip,
                    'telephone'         => $staging->telephone,
                    'email'             => $staging->email,
                    'cip_document_path' => $staging->cip_document_path,
                    'datesais'          => $staging->datesais,
                    'nomop'             => $staging->nomop,
                ]);
            }

            // 2. Marquer la saisie comme validée dans le staging
            $staging->update([
                'statut'       => 'valide',
                'admin_id'     => $request->user()->id,
                'validated_at' => now(),
            ]);

            return response()->json([
                'message' => "La saisie pour la référence {$staging->reference} a été validée et intégrée dans la base officielle.",
                'staging' => $staging,
            ]);
        });
    }

    /**
     * Rejeter une saisie en staging avec un motif (la table officielle polices reste intacte)
     */
    public function rejeter(Request $request, $id)
    {
        $this->checkAdmin($request);

        $request->validate([
            'motif' => 'nullable|string|max:255',
        ]);

        $staging = SaisieStaging::where('id', $id)
            ->where('statut', 'en_attente')
            ->firstOrFail();

        $staging->update([
            'statut'       => 'rejete',
            'admin_id'     => $request->user()->id,
            'motif_rejet'  => $request->input('motif', null),
            'validated_at' => now(),
        ]);

        return response()->json([
            'message' => "La saisie pour la référence {$staging->reference} a été rejetée.",
            'staging' => $staging,
        ]);
    }

    /**
     * Export CSV des données validées pour réinjection dans le système SONEB
     */
    public function export(Request $request)
    {
        if (!$request->user() && $request->filled('token')) {
            $tokenModel = \Laravel\Sanctum\PersonalAccessToken::findToken($request->query('token'));
            if ($tokenModel && $tokenModel->tokenable) {
                auth()->setUser($tokenModel->tokenable);
            }
        }

        $this->checkAdmin($request);

        $polices = Police::whereNotNull('cip')
            ->orWhereNotNull('telephone')
            ->get();

        $csvHeader = "Departement,Agence,Centre,Reference,Police,CIP,Telephone,Email,DateSaisie,NomOperateur\n";
        $csvBody = '';

        foreach ($polices as $p) {
            $csvBody .= sprintf(
                "%s,%s,%s,%s,%s,%s,%s,%s,%s,%s\n",
                $p->departement,
                $p->agence,
                $p->centre,
                $p->reference,
                $p->police,
                $p->cip,
                $p->telephone,
                $p->email,
                $p->datesais?->format('Y-m-d'),
                '"' . str_replace('"', '""', $p->nomop) . '"'
            );
        }

        return response($csvHeader . $csvBody, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="soneb_mises_a_jour_' . date('Ymd_His') . '.csv"',
        ]);
    }

    /**
     * Visualiser / Télécharger le document scanné de la carte CIP
     */
    public function document(Request $request, $id)
    {
        // Support de l'authentification par query token pour ouverture directe dans un nouvel onglet
        if (!$request->user() && $request->filled('token')) {
            $tokenModel = \Laravel\Sanctum\PersonalAccessToken::findToken($request->query('token'));
            if ($tokenModel && $tokenModel->tokenable) {
                auth()->setUser($tokenModel->tokenable);
            }
        }

        $this->checkAdmin($request);

        $staging = SaisieStaging::findOrFail($id);

        if (!$staging->cip_document_path || !Storage::disk('public')->exists($staging->cip_document_path)) {
            abort(404, 'Aucun document scanné associé à cette fiche.');
        }

        return Storage::disk('public')->response($staging->cip_document_path);
    }
}
