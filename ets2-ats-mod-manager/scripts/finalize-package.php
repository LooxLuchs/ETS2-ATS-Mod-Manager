<?php

declare(strict_types=1);

// The pinned Panel packer includes runtime directories but omits root documentation.
// Include the license with distributed copies and verify the artifact before hashing it.
$extension = dirname(__DIR__);
$package = $argv[1] ?? throw new InvalidArgumentException('Usage: php scripts/finalize-package.php /path/package.pteroext');
$manifest = json_decode(file_get_contents($extension.'/extension.json'), true, flags: JSON_THROW_ON_ERROR);
$expectedName = $manifest['id'].'-'.$manifest['version'].'.pteroext';
if (basename($package) !== $expectedName || ! is_file($package)) {
    throw new RuntimeException('Expected an existing package named '.$expectedName);
}

$zip = new ZipArchive;
if ($zip->open($package) !== true) {
    throw new RuntimeException('Unable to open extension package.');
}
try {
    $packed = json_decode($zip->getFromName('extension.json'), true, flags: JSON_THROW_ON_ERROR);
    if ($packed !== $manifest) {
        throw new RuntimeException('Package manifest differs from the source manifest.');
    }
    $documents = [
        'LICENSE' => dirname($extension).'/LICENSE',
        'README.md' => $extension.'/README.md',
        'CHANGELOG.md' => $extension.'/CHANGELOG.md',
    ];
    foreach ($documents as $name => $source) {
        if (! is_file($source) || ! $zip->addFile($source, $name)) {
            throw new RuntimeException('Unable to include '.$name);
        }
    }
} finally {
    if (! $zip->close()) {
        throw new RuntimeException('Unable to finish extension package.');
    }
}

$checksum = hash_file('sha256', $package).'  '.basename($package).PHP_EOL;
if (file_put_contents(substr($package, 0, -strlen('.pteroext')).'.sha256', $checksum) === false) {
    throw new RuntimeException('Unable to write the checksum.');
}
echo $checksum;
