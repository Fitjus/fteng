<?php
try {
    $pdo = new PDO(
        'odbc:Driver={iSeries Access ODBC Driver};System=192.168.50.150;',
        'SOKAPPLALP',
        'SOKAPPLAL'
    );
    echo "CONNEXION OK\n";
} catch (Throwable $e) {
    echo "ERREUR : " . $e->getMessage() . "\n";
}