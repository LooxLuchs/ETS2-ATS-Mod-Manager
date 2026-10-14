<?php

declare(strict_types=1);

namespace Ets2AtsModManager\Services;

interface FileStore
{
    public function exists(string $path): bool;

    public function presence(array $paths): array;

    public function readPair(string $directory, int $siiLimit, int $datLimit): array;

    public function snapshotPair(string $directory, int $siiLimit, int $datLimit): array;

    public function read(string $path, int $limit): string;

    public function readBlob(string $path, int $limit): PackageFile;

    public function digest(string $path, int $limit): ?string;

    public function digests(array $limits): array;

    public function write(string $path, string|PackageFile $bytes): void;

    public function directory(string $path): void;

    public function rename(string $from, string $to): void;

    public function delete(string $path): void;
}
