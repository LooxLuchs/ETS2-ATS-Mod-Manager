<?php

declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use Ets2AtsModManager\Services\PackageFile;
use Ets2AtsModManager\Services\PackageUpload;
use Ets2AtsModManager\Services\PairTransaction;

// Run with: php -d memory_limit=32M tests/stream-memory.php
// Generates 256 MiB on disk without allocating a 256 MiB PHP string.
$size = 256 * 1048576;
$input = tmpfile();
if ($input === false) {
    throw new RuntimeException('Could not create the input file.');
}
try {
    $chunk = str_repeat("\0\xff\x80data", 8192);
    $hash = hash_init('sha256');
    $written = 0;
    while ($written < $size) {
        $part = substr($chunk, 0, min(strlen($chunk), $size - $written));
        if (fwrite($input, $part) !== strlen($part)) {
            throw new RuntimeException('Could not write input.');
        }
        hash_update($hash, $part);
        $written += strlen($part);
    }
    rewind($input);
    $blob = PackageFile::fromStream($input, $size);
    $path = $blob->path();
    $pair = ['server_packages.sii' => file_get_contents(__DIR__.'/fixtures/seventy-mods.sii'), 'server_packages.dat' => $blob];
    (new PackageUpload(datLimit: $size))->validate($pair);
    if ($blob->size !== $size || PairTransaction::hashes($pair)['server_packages.dat'] !== hash_final($hash)) {
        throw new RuntimeException('Streamed size or digest differs.');
    }
    unset($pair, $blob);
    if (is_file($path)) {
        throw new RuntimeException('Temporary file was retained.');
    }
    echo json_encode(['dat_mib' => 256, 'peak_allocated_mib' => memory_get_peak_usage(true) / 1048576,
        'memory_limit' => ini_get('memory_limit'), 'hash_verified' => true, 'temporary_file_removed' => true]).PHP_EOL;
} finally {
    fclose($input);
}
