<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class Db2Service
{
    /**
     * Vérifie si l'extension PHP ibm_db2 est chargée
     */
    public function hasIbmDb2(): bool
    {
        return function_exists('db2_connect');
    }

    /**
     * Vérifie si l'extension PHP pdo_odbc est chargée (utile sous Windows XAMPP)
     */
    public function hasPdoOdbc(): bool
    {
        return extension_loaded('pdo_odbc')
            || (class_exists(\PDO::class) && in_array('odbc', \PDO::getAvailableDrivers(), true));
    }

    /**
     * Vérifie si au moins un pilote Db2/ODBC compatible est disponible
     */
    public function isExtensionLoaded(): bool
    {
        return $this->hasIbmDb2() || $this->hasPdoOdbc();
    }

    /**
     * Vérifie si l'hôte Db2 est configuré et qu'un pilote PHP est chargé
     */
    public function isConfigured(): bool
    {
        return !empty($this->db2Config('host')) && $this->isExtensionLoaded();
    }

    private function db2Config(string $key, mixed $default = null): mixed
    {
        $envMap = [
            'host'        => 'DB2_HOST',
            'port'        => 'DB2_PORT',
            'database'    => 'DB2_DATABASE',
            'library'     => 'DB2_LIBRARY',
            'username'    => 'DB2_USERNAME',
            'password'    => 'DB2_PASSWORD',
            'dsn'         => 'DB2_DSN',
            'odbc_driver' => 'DB2_ODBC_DRIVER',
        ];

        $envVal = isset($envMap[$key]) ? env($envMap[$key], $default) : $default;
        $val = config("database.connections.db2.{$key}", $envVal);

        return ($val === null || $val === '') ? $envVal : $val;
    }

    /**
     * Établit et renvoie la connexion IBM i / Db2 (ressource ibm_db2 ou instance PDO ODBC)
     */
    public function getConnection()
    {
        if (!$this->isExtensionLoaded()) {
            throw new \RuntimeException(
                "Aucune extension Db2 PHP n'est activée ('ibm_db2' ou 'pdo_odbc'). " .
                "Sous Windows XAMPP, activez 'extension=pdo_odbc' dans php.ini avec un pilote ODBC IBM, " .
                "ou utilisez Docker avec l'extension 'ibm_db2'."
            );
        }

        $host = $this->db2Config('host');
        $port = $this->db2Config('port', 8082);
        $db   = $this->db2Config('database', 'SAMPLE');
        $lib  = $this->db2Config('library', '');
        $user = $this->db2Config('username', '');
        $pass = $this->db2Config('password', '');

        if (empty($host) || empty($user) || empty($pass)) {
            throw new \InvalidArgumentException(
                "Paramètres de connexion Db2 manquants dans le fichier .env (DB2_HOST, DB2_USERNAME, DB2_PASSWORD)."
            );
        }

        if ($this->hasIbmDb2()) {
            $connString = "DATABASE={$db};HOSTNAME={$host};PORT={$port};PROTOCOL=TCPIP;UID={$user};PWD={$pass};";
            $options = [
                'autocommit'    => defined('DB2_AUTOCOMMIT_ON') ? DB2_AUTOCOMMIT_ON : 1,
                'DB2_ATTR_CASE' => defined('DB2_CASE_LOWER') ? DB2_CASE_LOWER : 0,
                'i5_naming'     => defined('DB2_I5_NAMING_OFF') ? DB2_I5_NAMING_OFF : 0,
                'i5_commit'     => defined('DB2_I5_TXN_NO_COMMIT') ? DB2_I5_TXN_NO_COMMIT : 1,
            ];
            if (!empty($lib)) {
                $options['i5_lib'] = $lib;
            }

            $conn = @db2_connect($connString, '', '', $options);
            if (!$conn) {
                $code = function_exists('db2_conn_error') ? db2_conn_error() : 'UNKNOWN';
                $msg  = function_exists('db2_conn_errormsg') ? db2_conn_errormsg() : 'Erreur inconnue';
                Log::error("Échec connexion IBM Db2 [{$code}] : {$msg}");
                throw new \Exception("Échec de connexion IBM Db2 [{$code}] : {$msg}");
            }

            return $conn;
        }

        // Fallback natif Windows XAMPP via PDO ODBC
        $dsnName = $this->db2Config('dsn', '');
        $driver  = $this->db2Config('odbc_driver', 'IBM DB2 ODBC DRIVER');

        if (!empty($dsnName)) {
            $pdoDsn = "odbc:{$dsnName}";
        } elseif (stripos((string) $driver, 'IBM i Access') !== false) {
            $libAttr = !empty($lib) ? "DBQ={$lib};" : '';
            $pdoDsn = "odbc:DRIVER={{$driver}};SYSTEM={$host};{$libAttr}NAM=0;CMT=0;";
        } else {
            $pdoDsn = "odbc:DRIVER={{$driver}};DATABASE={$db};HOSTNAME={$host};PORT={$port};PROTOCOL=TCPIP;";
        }

        try {
            $pdo = new \PDO($pdoDsn, (string) $user, (string) $pass, [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_CASE               => \PDO::CASE_LOWER,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
            return $pdo;
        } catch (\PDOException $e) {
            Log::error("Échec connexion IBM Db2 (PDO ODBC) : " . $e->getMessage());
            throw new \Exception("Échec de connexion IBM Db2 (ODBC) : " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Exécute un SELECT et retourne la première ligne associative, ou null
     */
    private function fetchRow($conn, string $sql, array $params = [], bool $throwOnPrepareError = true): ?array
    {
        if ($conn instanceof \PDO) {
            try {
                $stmt = $conn->prepare($sql);
                $stmt->execute($params);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                return $row ?: null;
            } catch (\PDOException $e) {
                if ($throwOnPrepareError) {
                    throw new \Exception("Erreur SQL (ODBC) : " . $e->getMessage(), 0, $e);
                }
                return null;
            }
        }

        $stmt = @db2_prepare($conn, $sql);
        if (!$stmt) {
            if ($throwOnPrepareError) {
                throw new \Exception("Erreur de préparation SQL : " . db2_stmt_errormsg());
            }
            return null;
        }

        @db2_execute($stmt, $params);
        $row = @db2_fetch_assoc($stmt);
        return $row ?: null;
    }

    /**
     * Exécute une requête INSERT/UPDATE avec paramètres
     */
    private function executeNonQuery($conn, string $sql, array $params = [], bool $throwOnError = true, string $context = 'SQL'): bool
    {
        if ($conn instanceof \PDO) {
            try {
                $stmt = $conn->prepare($sql);
                return $stmt->execute($params);
            } catch (\PDOException $e) {
                if ($throwOnError) {
                    throw new \Exception("Erreur {$context} (ODBC) : " . $e->getMessage(), 0, $e);
                }
                Log::warning("Erreur {$context} (ODBC) : " . $e->getMessage());
                return false;
            }
        }

        $stmt = @db2_prepare($conn, $sql);
        if (!$stmt) {
            $err = db2_stmt_errormsg();
            if ($throwOnError) {
                throw new \Exception("Erreur de préparation SQL ({$context}) : {$err}");
            }
            Log::warning("Impossible de préparer {$context} : {$err}");
            return false;
        }

        $result = @db2_execute($stmt, $params);
        if (!$result) {
            $err = db2_stmt_errormsg();
            if ($throwOnError) {
                throw new \Exception("Erreur lors de l'exécution ({$context}) : {$err}");
            }
            Log::warning("Échec exécution ({$context}) : {$err}");
            return false;
        }

        return true;
    }

    private function closeConnection($conn): void
    {
        if ($conn instanceof \PDO) {
            return;
        }
        if ($conn) {
            @db2_close($conn);
        }
    }

    /**
     * Teste la connectivité avec le serveur IBM Db2
     */
    public function testConnection(): array
    {
        try {
            $conn = $this->getConnection();
            $driverUsed = $conn instanceof \PDO ? 'pdo_odbc' : 'ibm_db2';

            $row = $this->fetchRow($conn, "SELECT CURRENT DATE AS date_serveur FROM SYSIBM.SYSDUMMY1", [], false);
            $dateServeur = $row['date_serveur'] ?? $row['DATE_SERVEUR'] ?? date('Y-m-d');

            $jobInfo = null;
            try {
                $sqlJob = "SELECT JOB_NAME FROM TABLE(QSYS2.ACTIVE_JOB_INFO()) WHERE AUTHORIZATION_NAME = CURRENT USER";
                $rowJob = $this->fetchRow($conn, $sqlJob, [], false);
                $jobInfo = $rowJob['job_name'] ?? $rowJob['JOB_NAME'] ?? null;
            } catch (\Throwable $e) {
                // Optionnel
            }

            $this->closeConnection($conn);

            return [
                'success'      => true,
                'message'      => "Connexion IBM Db2 réussie ({$driverUsed}) !",
                'driver'       => $driverUsed,
                'date_serveur' => $dateServeur,
                'job_ibmi'     => $jobInfo,
                'database'     => $this->db2Config('database'),
                'library'      => $this->db2Config('library'),
                'host'         => $this->db2Config('host'),
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
        $lib  = $this->db2Config('library', '');
        $table = !empty($lib) ? "{$lib}.POLPF" : "POLPF";

        $cleanRef = trim($reference);

        try {
            $row = $this->fetchRow($conn, "SELECT * FROM {$table} WHERE TRIM(CODPPO) = ?", [$cleanRef], true);

            if (!$row) {
                $row = $this->fetchRow($conn, "SELECT * FROM {$table} WHERE TRIM(REFPREC) = ?", [$cleanRef], false);
            }
        } finally {
            $this->closeConnection($conn);
        }

        if (!$row) {
            return null;
        }

        return $this->formatAbonneRow($row, $cleanRef);
    }

    /**
     * Vérifie si des coordonnées existent déjà dans POLPFZ pour cet abonné
     */
    public function findExistingSaisie(string $reference): ?array
    {
        $conn = $this->getConnection();
        $lib  = $this->db2Config('library', '');
        $table = !empty($lib) ? "{$lib}.POLPFZ" : "POLPFZ";

        $cleanRef = trim($reference);

        try {
            $row = $this->fetchRow($conn, "SELECT * FROM {$table} WHERE TRIM(CODPPO) = ? FETCH FIRST 1 ROWS ONLY", [$cleanRef], false);
        } finally {
            $this->closeConnection($conn);
        }

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
        $lib  = $this->db2Config('library', '');
        $table = !empty($lib) ? "{$lib}.POLPFZ" : "POLPFZ";

        $sql = "INSERT INTO {$table} (CODPPO, CIP, TELEPHONE, EMAIL, DATESAIS, NOMOP) VALUES (?, ?, ?, ?, CURRENT DATE, ?)";
        $params = [
            trim($reference),
            trim($cip),
            trim($telephone),
            !empty($email) ? trim($email) : '',
            $nomop,
        ];

        try {
            return $this->executeNonQuery($conn, $sql, $params, true, 'POLPFZ');
        } finally {
            $this->closeConnection($conn);
        }
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
        $lib  = $this->db2Config('library', '');
        $table = !empty($lib) ? "{$lib}.POLPF" : "POLPF";

        $sql = "UPDATE {$table} SET CIP = ?, TELEPHONE = ?, EMAIL = ?, DATESAIS = CURRENT DATE, NOMOP = ? WHERE TRIM(CODPPO) = ?";
        $params = [
            trim($cip),
            trim($telephone),
            !empty($email) ? trim($email) : '',
            $nomop,
            trim($reference),
        ];

        try {
            return $this->executeNonQuery($conn, $sql, $params, false, 'UPDATE POLPF');
        } finally {
            $this->closeConnection($conn);
        }
    }

    /**
     * Formate la ligne brute Db2 vers le format JSON unifié du frontend SONEB
     */
    private function formatAbonneRow(array $row, string $reference): array
    {
        $get = function (...$keys) use ($row) {
            foreach ($keys as $k) {
                if (isset($row[$k])) return trim((string) $row[$k]);
                $lower = strtolower($k);
                if (isset($row[$lower])) return trim((string) $row[$lower]);
                $upper = strtoupper($k);
                if (isset($row[$upper])) return trim((string) $row[$upper]);
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
            'departement'       => (int) ($get('departement', 'dept', 'd') ?: 0),
            'agence'            => (int) ($get('agence', 'a') ?: 0),
            'centre'            => (int) ($get('centre', 'c') ?: 0),
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
