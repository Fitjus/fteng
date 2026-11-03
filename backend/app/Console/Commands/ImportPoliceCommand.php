<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Police;
use ZipArchive;
use SimpleXMLElement;

class ImportPoliceCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'soneb:import-police {file : Chemin du fichier .xlsx ou .csv}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Importe le fichier SONEB (138 colonnes) et reconstruit la référence active';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $filePath = $this->argument('file');

        if (!file_exists($filePath)) {
            $this->error("Fichier introuvable : {$filePath}");
            return 1;
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if ($extension === 'xlsx') {
            return $this->importXlsx($filePath);
        } elseif (in_array($extension, ['csv', 'txt'])) {
            return $this->importCsv($filePath);
        } else {
            $this->error("Format non supporté. Veuillez fournir un fichier .xlsx ou .csv");
            return 1;
        }
    }

    /**
     * Import direct depuis un fichier .xlsx OpenXML streaming
     */
    protected function importXlsx(string $filePath): int
    {
        $this->info("Analyse du fichier Excel : {$filePath}");

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            $this->error("Impossible d'ouvrir le fichier .xlsx");
            return 1;
        }

        // 1. Charger les sharedStrings
        $sharedStrings = [];
        $sharedIndex = $zip->locateName('xl/sharedStrings.xml');
        if ($sharedIndex !== false) {
            $xmlString = $zip->getFromIndex($sharedIndex);
            $xml = simplexml_load_string($xmlString);
            foreach ($xml->si as $si) {
                if (isset($si->t)) {
                    $sharedStrings[] = (string)$si->t;
                } elseif (isset($si->r)) {
                    $text = '';
                    foreach ($si->r as $r) {
                        $text .= (string)$r->t;
                    }
                    $sharedStrings[] = $text;
                } else {
                    $sharedStrings[] = '';
                }
            }
        }

        // 2. Lire sheet1.xml
        $sheetIndex = $zip->locateName('xl/worksheets/sheet1.xml');
        if ($sheetIndex === false) {
            $this->error("Feuille sheet1.xml introuvable dans le fichier Excel");
            $zip->close();
            return 1;
        }

        $sheetXmlStr = $zip->getFromIndex($sheetIndex);
        $zip->close();

        // Enlever les namespaces pour faciliter la lecture SimpleXML
        $cleanXmlStr = preg_replace('/xmlns[^=]*="[^"]*"/i', '', $sheetXmlStr);
        $cleanXmlStr = preg_replace('/[a-zA-Z0-9]+:([a-zA-Z0-9]+)/', '$1', $cleanXmlStr);

        $sheetXml = simplexml_load_string($cleanXmlStr);
        $rows = $sheetXml->xpath('//row');

        $totalRows = count($rows);
        $this->info("Nombre total de lignes trouvées : {$totalRows}");

        if ($totalRows < 2) {
            $this->warn("Le fichier ne contient aucune donnée (seulement l'en-tête ou vide).");
            return 0;
        }

        $bar = $this->output->createProgressBar($totalRows - 1);
        $bar->start();

        $batch = [];
        $imported = 0;

        // Parcourir à partir de la ligne 2 (index 1)
        for ($i = 1; $i < $totalRows; $i++) {
            $row = $rows[$i];
            $cells = [];

            foreach ($row->c as $c) {
                $ref = (string)$c['r'];
                $colLetter = preg_replace('/\d+/', '', $ref);
                $type = (string)$c['t'];
                $val = (string)$c->v;

                if ($type === 's' && isset($sharedStrings[(int)$val])) {
                    $cells[$colLetter] = trim($sharedStrings[(int)$val]);
                } elseif ($type === 'inlineStr' || isset($c->is)) {
                    $cells[$colLetter] = trim((string)($c->is->t ?? ''));
                } else {
                    $cells[$colLetter] = trim($val);
                }
            }

            // Reconstruction de la référence active 14 car : L(A) + B(B) + N(C) + R(D) + K(E) + S(F)
            $colL = $cells['A'] ?? '';
            $colB = $cells['B'] ?? '';
            $colN = $cells['C'] ?? '';
            $colR = $cells['D'] ?? '';
            $colK = $cells['E'] ?? '';
            $colS = $cells['F'] ?? '';

            $reference = $colL . $colB . $colN . $colR . $colK . $colS;

            // Secours : si la colonne M (Payeur / Référence) est déjà assemblée à 14 caractères
            if (strlen($reference) !== 14 && isset($cells['M']) && strlen($cells['M']) === 14) {
                $reference = $cells['M'];
            }

            if (strlen($reference) !== 14) {
                $bar->advance();
                continue;
            }

            $batch[] = [
                'reference'   => $reference,
                'ref_prec'    => !empty($cells['EA'] ?? '') ? substr($cells['EA'], 0, 14) : null, // Col 131 (Réf.préc.)
                'departement' => (int)round((float)($cells['G'] ?? 0)),  // Col 7 (D)
                'agence'      => (int)round((float)($cells['H'] ?? 0)),  // Col 8 (A)
                'centre'      => (int)round((float)($cells['I'] ?? 0)),  // Col 9 (C)
                'police'      => substr($cells['BU'] ?? '', 0, 8), // Col 73 (N°Panneau)
                'etat_abo'    => substr($cells['BJ'] ?? '', 0, 1), // Col 62 (Etat abt)
                'etat_comp'   => substr($cells['BK'] ?? '', 0, 1), // Col 63 (Etat cpt)
                'nom_abonne'  => substr($cells['V'] ?? '', 0, 150), // Col 22 (Nom raison sociale)
                'ville'       => substr($cells['Z'] ?? '', 0, 100), // Col 26 (Ville)
                'created_at'  => now(),
                'updated_at'  => now(),
            ];

            if (count($batch) >= 1000) {
                Police::upsert(
                    $batch,
                    ['reference'],
                    ['ref_prec', 'departement', 'agence', 'centre', 'police', 'etat_abo', 'etat_comp', 'nom_abonne', 'ville', 'updated_at']
                );
                $imported += count($batch);
                $batch = [];
            }

            $bar->advance();
        }

        if (!empty($batch)) {
            Police::upsert(
                $batch,
                ['reference'],
                ['ref_prec', 'departement', 'agence', 'centre', 'police', 'etat_abo', 'etat_comp', 'nom_abonne', 'ville', 'updated_at']
            );
            $imported += count($batch);
        }

        $bar->finish();
        $this->newLine();
        $this->info("Importation terminée avec succès ! {$imported} abonné(s) enregistré(s) ou mis à jour.");
        return 0;
    }

    /**
     * Import CSV / TXT délimité par virgule ou tabulation
     */
    protected function importCsv(string $filePath): int
    {
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            $this->error("Impossible d'ouvrir le fichier CSV");
            return 1;
        }

        // Détection séparateur
        $firstLine = fgets($handle);
        $sep = (strpos($firstLine, "\t") !== false) ? "\t" : ",";
        rewind($handle);

        // Ignorer l'en-tête
        fgetcsv($handle, 0, $sep);

        $batch = [];
        $imported = 0;

        while (($row = fgetcsv($handle, 0, $sep)) !== false) {
            $ref = trim($row[0] ?? '') . trim($row[1] ?? '') . trim($row[2] ?? '') . trim($row[3] ?? '') . trim($row[4] ?? '') . trim($row[5] ?? '');
            if (strlen($ref) !== 14) continue;

            $batch[] = [
                'reference'   => $ref,
                'ref_prec'    => !empty($row[130] ?? '') ? substr(trim($row[130]), 0, 14) : null,
                'departement' => (int)($row[6] ?? 0),
                'agence'      => (int)($row[7] ?? 0),
                'centre'      => (int)($row[8] ?? 0),
                'police'      => substr(trim($row[72] ?? ''), 0, 8),
                'etat_abo'    => substr(trim($row[61] ?? ''), 0, 1),
                'etat_comp'   => substr(trim($row[62] ?? ''), 0, 1),
                'nom_abonne'  => substr(trim($row[21] ?? ''), 0, 150),
                'ville'       => substr(trim($row[25] ?? ''), 0, 100),
                'created_at'  => now(),
                'updated_at'  => now(),
            ];

            if (count($batch) >= 1000) {
                Police::upsert(
                    $batch,
                    ['reference'],
                    ['ref_prec', 'departement', 'agence', 'centre', 'police', 'etat_abo', 'etat_comp', 'nom_abonne', 'ville', 'updated_at']
                );
                $imported += count($batch);
                $batch = [];
            }
        }

        if (!empty($batch)) {
            Police::upsert(
                $batch,
                ['reference'],
                ['ref_prec', 'departement', 'agence', 'centre', 'police', 'etat_abo', 'etat_comp', 'nom_abonne', 'ville', 'updated_at']
            );
            $imported += count($batch);
        }

        fclose($handle);
        $this->info("Importation CSV terminée avec succès ! {$imported} abonné(s) traité(s).");
        return 0;
    }
}
