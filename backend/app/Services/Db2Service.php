<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class Db2Service
{
    /**
     * Cache en mémoire pour éviter de tester plusieurs fois le socket dans la même requête
     */
    private ?bool $serverReachable = null;

    /**
     * Vérifie si l'extension PHP ibm_db2 est chargée
     */
    public function isExtensionLoaded(): bool
    {
        return function_exists('db2_connect');
    }

    /**
     * Teste la joignabilité TCP du serveur IBM Db2 avec un délai strict (5.0s par défaut).
     * Si le serveur ne répond pas en 5 secondes, retourne false pour basculer immédiatement vers Supabase.
     */
    public function isServerReachable(float $timeout = 5.0): bool
    {
        if ($this->serverReachable !== null) {
            return $this->serverReachable;
        }

        $host = config('database.connections.db2.host', env('DB2_HOST'));
        $port = config('database.connections.db2.port', env('DB2_PORT', 446));

        if (empty($host)) {
            $this->serverReachable = false;
            return false;
        }

        $errno = 0;
        $errstr = '';

        // Test de socket TCP avec timeout strict de 5 secondes
        $socket = @fsockopen($host, (int) $port, $errno, $errstr, $timeout);
        if (!$socket) {
            Log::warning("Serveur IBM Db2 ({$host}:{$port}) injoignable après {$timeout}s : [{$errno}] {$errstr}. Bascule immédiate vers Supabase.");
            $this->serverReachable = false;
            return false;
        }

        fclose($socket);
        $this->serverReachable = true;
        return true;
    }

    /**
     * Établit et renvoie la ressource de connexion IBM i (Db2)
     */
    public function getConnection()
    {
        if (!$this->isExtensionLoaded()) {
            throw new \RuntimeException(
                "L'extension PHP 'ibm_db2' n'est pas installée ou activée sur ce serveur. " .
                "Assurez-vous que le pilote IBM clidriver et l'extension pecl ibm_db2 sont installés."
            );
        }

        $host = config('database.connections.db2.host', env('DB2_HOST'));
        $port = config('database.connections.db2.port', env('DB2_PORT', 446));
        $db   = config('database.connections.db2.database', env('DB2_DATABASE', 'SAMPLE'));
        $lib  = config('database.connections.db2.library', env('DB2_LIBRARY', ''));
        $user = config('database.connections.db2.username', env('DB2_USERNAME', ''));
        $pass = config('database.connections.db2.password', env('DB2_PASSWORD', ''));

        if (empty($host) || empty($user) || empty($pass)) {
            throw new \InvalidArgumentException(
                "Paramètres de connexion Db2 manquants dans le fichier .env (DB2_HOST, DB2_USERNAME, DB2_PASSWORD)."
            );
        }

        // Vérification de la joignabilité avec délai strict de 5 secondes
        if (!$this->isServerReachable(5.0)) {
            throw new \Exception("Délai d'attente de 5 secondes dépassé : le serveur IBM Db2 ({$host}:{$port}) ne répond pas.");
        }

        // Chaîne TCP/IP directe uncataloged pour IBM i / Db2 avec CONNECTTIMEOUT=5
        $connString = "DATABASE={$db};HOSTNAME={$host};PORT={$port};PROTOCOL=TCPIP;UID={$user};PWD={$pass};CONNECTTIMEOUT=5;";

        $options = [
            'autocommit'    => defined('DB2_AUTOCOMMIT_ON') ? DB2_AUTOCOMMIT_ON : 1,
            'DB2_ATTR_CASE' => defined('DB2_CASE_LOWER') ? DB2_CASE_LOWER : 0, // Clés renvoyées en minuscules
            'i5_naming'     => defined('DB2_I5_NAMING_OFF') ? DB2_I5_NAMING_OFF : 0, // Syntaxe SQL (BIB.TABLE)
            'i5_commit'     => defined('DB2_I5_TXN_NO_COMMIT') ? DB2_I5_TXN_NO_COMMIT : 1, // Évite erreur si non journalisé
        ];

        if (!empty($lib)) {
            $options['i5_lib'] = $lib;
        }

        // Connexion TCP/IP non cataloguée (arguments UID/PWD vides dans l'appel fonction car inclus dans $connString)
        $conn = @db2_connect($connString, '', '', $options);

        if (!$conn) {
            $code = function_exists('db2_conn_error') ? db2_conn_error() : 'UNKNOWN';
            $msg  = function_exists('db2_conn_errormsg') ? db2_conn_errormsg() : 'Erreur inconnue';
            Log::error("Échec connexion IBM Db2 [{$code}] : {$msg}");
            throw new \Exception("Échec de connexion IBM Db2 [{$code}] : {$msg}");
        }

        return $conn;
    }

    /**
     * Teste la connectivité avec le serveur IBM Db2
     */
    public function testConnection(): array
    {
        try {
            $conn = $this->getConnection();

            // Test simple d'exécution de date système
            $sql = "SELECT CURRENT DATE AS date_serveur FROM SYSIBM.SYSDUMMY1";
            $stmt = @db2_exec($conn, $sql);

            $dateServeur = null;
            if ($stmt) {
                $row = db2_fetch_assoc($stmt);
                $dateServeur = $row['date_serveur'] ?? $row['DATE_SERVEUR'] ?? date('Y-m-d');
            }

            // Récupération informations job IBM i si disponible
            $jobInfo = null;
            try {
                $sqlJob = "SELECT JOB_NAME FROM TABLE(QSYS2.ACTIVE_JOB_INFO()) WHERE AUTHORIZATION_NAME = CURRENT USER";
                $stmtJob = @db2_exec($conn, $sqlJob);
                if ($stmtJob && ($rowJob = db2_fetch_assoc($stmtJob))) {
                    $jobInfo = $rowJob['job_name'] ?? $rowJob['JOB_NAME'] ?? null;
                }
            } catch (\Throwable $e) {
                // Optionnel
            }

            db2_close($conn);

            return [
                'success'      => true,
                'message'      => 'Connexion IBM Db2 réussie !',
                'date_serveur' => $dateServeur,
                'job_ibmi'     => $jobInfo,
                'database'     => env('DB2_DATABASE'),
                'library'      => env('DB2_LIBRARY'),
                'host'         => env('DB2_HOST'),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Recherche d'un abonné dans la table POLPF par sa référence (CODPPO)
     */
    public function findAbonne(string $reference): ?array
    {
        $conn = $this->getConnection();
        $lib  = config('database.connections.db2.library', env('DB2_LIBRARY', ''));
        $table = !empty($lib) ? "{$lib}.POLPF" : "POLPF";

        $cleanRef = trim($reference);

        // Recherche par la référence principale (CODPPO)
        $sql = "SELECT * FROM {$table} WHERE TRIM(CODPPO) = ?";
        $stmt = db2_prepare($conn, $sql);

        if (!$stmt) {
            $err = db2_stmt_errormsg();
            db2_close($conn);
            throw new \Exception("Erreur de préparation SQL (POLPF) : {$err}");
        }

        db2_execute($stmt, [$cleanRef]);
        $row = db2_fetch_assoc($stmt);

        // Si non trouvé et si la table supporte une référence précédente (REFPREC)
        if (!$row) {
            $sqlFallback = "SELECT * FROM {$table} WHERE TRIM(REFPREC) = ?";
            $stmtFallback = @db2_prepare($conn, $sqlFallback);
            if ($stmtFallback) {
                @db2_execute($stmtFallback, [$cleanRef]);
                $row = @db2_fetch_assoc($stmtFallback);
            }
        }

        db2_close($conn);

        if (!$row) {
            return null;
        }

        // Mappage standardisé vers les champs attendus par l'application Laravel
        return $this->formatAbonneRow($row, $cleanRef);
    }

    /**
     * Vérifie si des coordonnées existent déjà dans POLPFZ pour cet abonné
     */
    public function findExistingSaisie(string $reference): ?array
    {
        $conn = $this->getConnection();
        $lib  = config('database.connections.db2.library', env('DB2_LIBRARY', ''));
        $table = !empty($lib) ? "{$lib}.POLPFZ" : "POLPFZ";

        $cleanRef = trim($reference);
        $sql = "SELECT * FROM {$table} WHERE TRIM(CODPPO) = ? FETCH FIRST 1 ROWS ONLY";
        $stmt = @db2_prepare($conn, $sql);

        if (!$stmt) {
            db2_close($conn);
            return null;
        }

        @db2_execute($stmt, [$cleanRef]);
        $row = @db2_fetch_assoc($stmt);
        db2_close($conn);

        if (!$row) {
            return null;
        }

        return [
            'reference'   => trim($row['codppo'] ?? $row['CODPPO'] ?? $cleanRef),
            'cip'         => trim($row['cip'] ?? $row['CIP'] ?? ''),
            'telephone'   => trim($row['telephone'] ?? $row['tel'] ?? $row['TELEPHONE'] ?? $row['TEL'] ?? ''),
            'email'       => trim($row['email'] ?? $row['mail'] ?? $row['EMAIL'] ?? $row['MAIL'] ?? ''),
            'datesais'    => trim($row['datesais'] ?? $row['DATESAIS'] ?? ''),
        ];
    }

    /**
     * Insère les coordonnées de l'abonné dans le fichier récepteur POLPFZ
     */
    public function insertCoordonnees(
        string $reference,
        string $cip,
        string $telephone,
        ?string $email = null,
        ?string $nomop = 'PORTAIL_WEB',
        ?string $cipDocPath = null
    ): bool {
        $conn = $this->getConnection();
        $lib  = config('database.connections.db2.library', env('DB2_LIBRARY', ''));
        $table = !empty($lib) ? "{$lib}.POLPFZ" : "POLPFZ";

        $cleanRef = trim($reference);
        $cleanCip = trim($cip);
        $cleanTel = trim($telephone);
        $cleanEmail = !empty($email) ? trim($email) : '';

        // Insertion dans POLPFZ
        $sql = "INSERT INTO {$table} (CODPPO, CIP, TELEPHONE, EMAIL, DATESAIS, NOMOP) 
                VALUES (?, ?, ?, ?, CURRENT DATE, ?)";

        $stmt = db2_prepare($conn, $sql);

        if (!$stmt) {
            $err = db2_stmt_errormsg();
            db2_close($conn);
            throw new \Exception("Erreur de préparation SQL pour insertion dans POLPFZ : {$err}");
        }

        $params = [$cleanRef, $cleanCip, $cleanTel, $cleanEmail, $nomop];
        $result = db2_execute($stmt, $params);

        if (!$result) {
            $err = db2_stmt_errormsg();
            db2_close($conn);
            throw new \Exception("Erreur lors de l'enregistrement dans POLPFZ : {$err}");
        }

        db2_close($conn);
        return true;
    }

    /**
     * Mise à jour du fichier officiel POLPF après validation de la saisie staging
     */
    public function updateAbonneOfficial(
        string $reference,
        string $cip,
        string $telephone,
        ?string $email = null,
        ?string $nomop = 'ADMIN_SONEB'
    ): bool {
        $conn = $this->getConnection();
        $lib  = config('database.connections.db2.library', env('DB2_LIBRARY', ''));
        $table = !empty($lib) ? "{$lib}.POLPF" : "POLPF";

        $cleanRef = trim($reference);
        $cleanCip = trim($cip);
        $cleanTel = trim($telephone);
        $cleanEmail = !empty($email) ? trim($email) : '';

        $sql = "UPDATE {$table} SET CIP = ?, TELEPHONE = ?, EMAIL = ?, DATESAIS = CURRENT DATE, NOMOP = ? WHERE TRIM(CODPPO) = ?";
        $stmt = @db2_prepare($conn, $sql);

        if (!$stmt) {
            $err = db2_stmt_errormsg();
            db2_close($conn);
            Log::warning("Impossible de préparer l'UPDATE sur POLPF : {$err}");
            return false;
        }

        $res = @db2_execute($stmt, [$cleanCip, $cleanTel, $cleanEmail, $nomop, $cleanRef]);
        db2_close($conn);

        return (bool)$res;
    }

    /**
     * Formate la ligne brute Db2 vers le format JSON unifié du frontend SONEB
     */
    private function formatAbonneRow(array $row, string $reference): array
    {
        // Recherche des champs avec tolérance de casse (minuscule / majuscule)
        $get = function (...$keys) use ($row) {
            foreach ($keys as $k) {
                if (isset($row[$k])) return trim((string)$row[$k]);
                $lower = strtolower($k);
                if (isset($row[$lower])) return trim((string)$row[$lower]);
                $upper = strtoupper($k);
                if (isset($row[$upper])) return trim((string)$row[$upper]);
            }
            return null;
        };

        $existingSaisie = $this->findExistingSaisie($reference);

        $cip       = $existingSaisie['cip'] ?? $get('cip');
        $telephone = $existingSaisie['telephone'] ?? $get('telephone', 'tel');
        $email     = $existingSaisie['email'] ?? $get('email', 'mail');
        $datesais  = $existingSaisie['datesais'] ?? $get('datesais');

        $alreadySubmitted = !empty($cip) || !empty($telephone) || !empty($existingSaisie);

        return [
            'reference'         => $get('codppo', 'reference') ?: $reference,
            'ref_prec'          => $get('refprec', 'ref_prec'),
            'nom_abonne'        => $get('nom_abonne', 'nom', 'rsoc', 'nomrsoc') ?: 'Client SONEB',
            'ville'             => $get('ville', 'loc') ?: '—',
            'departement'       => (int)($get('departement', 'dept', 'd') ?: 0),
            'agence'            => (int)($get('agence', 'a') ?: 0),
            'centre'            => (int)($get('centre', 'c') ?: 0),
            'police'            => $get('police', 'nopan', 'panneau') ?: '—',
            'etat_abo'          => $get('etat_abo', 'etabt') ?: '—',
            'etat_comp'         => $get('etat_comp', 'etcpt') ?: '—',
            'already_submitted' => $alreadySubmitted,
            'cip'               => $cip,
            'telephone'         => $telephone,
            'email'             => $email,
            'datesais'          => $datesais,
        ];
    }
}
