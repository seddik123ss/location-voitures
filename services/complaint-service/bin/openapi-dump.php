<?php

/*
 * Régénère docs/openapi/complaint-service.yaml (composer openapi:dump).
 * Écrit depuis PHP pour éviter la redirection ">" de PowerShell 5.1 (UTF-16) :
 * UTF-8 sans BOM, fins de ligne LF.
 */

$root = dirname(__DIR__);
$target = $root . '/../../docs/openapi/complaint-service.yaml';

$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/console') . ' nelmio:apidoc:dump --format=yaml --no-ansi';
exec($cmd, $lines, $code);
if ($code !== 0) {
    fwrite(STDERR, "nelmio:apidoc:dump a échoué (code $code)\n" . implode("\n", $lines) . "\n");
    exit($code);
}

$yaml = rtrim(str_replace("\r\n", "\n", implode("\n", $lines))) . "\n";
if (str_starts_with($yaml, "\xEF\xBB\xBF")) {
    $yaml = substr($yaml, 3);
}
if (!mb_check_encoding($yaml, 'UTF-8')) {
    fwrite(STDERR, "Sortie qui n'est pas en UTF-8 valide\n");
    exit(1);
}

file_put_contents($target, $yaml);
printf("%s écrit (%d octets, UTF-8 sans BOM, LF)\n", realpath($target), strlen($yaml));
