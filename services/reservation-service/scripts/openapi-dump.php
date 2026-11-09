<?php

/*
 * composer openapi:dump : copie le contrat généré par l5-swagger (storage/api-docs/api-docs.yaml)
 * dans docs/openapi/reservation-service.yaml, en UTF-8 sans BOM et avec des fins de ligne LF.
 * (Ne pas utiliser une redirection « > fichier » sous PowerShell 5.1 : elle écrit de l'UTF-16.)
 */

$source = __DIR__.'/../storage/api-docs/api-docs.yaml';
$target = __DIR__.'/../../../docs/openapi/reservation-service.yaml';

if (! is_file($source)) {
    fwrite(STDERR, "Contrat introuvable : lancer d'abord php artisan l5-swagger:generate\n");
    exit(1);
}

$yaml = file_get_contents($source);
$yaml = preg_replace('/^\xEF\xBB\xBF/', '', $yaml);   // BOM
$yaml = str_replace("\r\n", "\n", $yaml);             // CRLF -> LF
$yaml = rtrim($yaml)."\n";

file_put_contents($target, $yaml);
echo 'Contrat exporté : docs/openapi/reservation-service.yaml ('.substr_count($yaml, "\n")." lignes)\n";
