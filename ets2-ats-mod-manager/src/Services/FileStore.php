<?php

declare(strict_types=1);

namespace Ets2AtsModManager\Services;

interface FileStore
{
    public function exists(string $path): bool;

    public function read(string $path, int $limit): string;

    public function write(string $path, string $bytes): void;

    public function directory(string $path): void;

    public function rename(string $from, string $to): void;

    public function delete(string $path): void;
}
