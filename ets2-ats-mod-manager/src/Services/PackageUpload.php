<?php

declare(strict_types=1);

namespace Ets2AtsModManager\Services;

use InvalidArgumentException;
use ZipArchive;

final class PackageUpload
{
    public const FILES = ['server_packages.sii', 'server_packages.dat'];

    public function __construct(public readonly int $siiLimit = 4194304, public readonly int $datLimit = 67108864) {}

    public function validate(array $pair): array
    {
        if (count($pair) !== 2 || array_diff(self::FILES, array_keys($pair))) {
            throw new InvalidArgumentException('Both server_packages.sii and server_packages.dat are required.');
        }
        foreach (self::FILES as $file) {
            $limit = str_ends_with($file, '.sii') ? $this->siiLimit : $this->datLimit;
            if (! is_string($pair[$file]) || strlen($pair[$file]) === 0 || strlen($pair[$file]) > $limit) {
                throw new InvalidArgumentException('Empty or oversized file: '.$file);
            }
        }

        return (new SiiDocument($pair[self::FILES[0]]))->metadata();
    }

    /** Read entries directly; never extract archive paths onto the panel or server. */
    public function zip(string $path): array
    {
        if (filesize($path) > $this->siiLimit + $this->datLimit) {
            throw new InvalidArgumentException('The ZIP exceeds the upload limit.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new InvalidArgumentException('Invalid ZIP archive.');
        }
        try {
            if ($zip->numFiles > 128) {
                throw new InvalidArgumentException('The ZIP contains too many entries.');
            }
            $pair = [];
            $parent = null;
            $total = 0;
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (! $stat) {
                    throw new InvalidArgumentException('Could not read the ZIP entry.');
                }
                $name = $stat['name'];
                $total += $stat['size'];
                $zip->getExternalAttributesIndex($i, $os, $attributes);
                $kind = ($attributes >> 16) & 0170000;
                if ($total > $this->siiLimit + $this->datLimit || str_starts_with($name, '/')
                    || preg_match('/[\x00-\x1f\\\\:]/', $name) || in_array('..', explode('/', $name), true)
                    || in_array('.', explode('/', $name), true) || isset($names[$name])
                    || ($kind !== 0 && ! in_array($kind, [0100000, 0040000], true))
                    || ($stat['encryption_method'] ?? 0) !== 0) {
                    throw new InvalidArgumentException('Unsafe or oversized ZIP entry.');
                }
                $names[$name] = true;
                $base = basename($name);
                if (str_ends_with($name, '/') || ! in_array($base, self::FILES, true)) {
                    continue;
                }
                if (isset($pair[$base]) || ($parent !== null && $parent !== dirname($name))) {
                    throw new InvalidArgumentException('The ZIP must contain exactly one package pair in the same directory.');
                }
                $parent = dirname($name);
                $limit = str_ends_with($base, '.sii') ? $this->siiLimit : $this->datLimit;
                if ($stat['size'] > $limit) {
                    throw new InvalidArgumentException('Oversized package file.');
                }
                $stream = $zip->getStream($name);
                if ($stream === false) {
                    throw new InvalidArgumentException('Could not read the ZIP file.');
                }
                try {
                    $bytes = stream_get_contents($stream, $limit + 1);
                } finally {
                    fclose($stream);
                }
                if ($bytes === false || strlen($bytes) !== $stat['size'] || strlen($bytes) > $limit
                    || sprintf('%u', crc32($bytes)) !== sprintf('%u', $stat['crc'])) {
                    throw new InvalidArgumentException('Corrupted or oversized ZIP file.');
                }
                $pair[$base] = $bytes;
            }
            $this->validate($pair);

            return $pair;
        } finally {
            $zip->close();
        }
    }
}
