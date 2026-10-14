<?php

use Ets2AtsModManager\Services\FileStore;
use Ets2AtsModManager\Services\PackageFile;

final class MemoryFiles implements FileStore
{
    public array $files = [];

    public int $renames = 0;

    public ?int $failRename = null;

    public bool $failAlways = false;

    public ?Closure $beforeRename = null;

    public array $writes = [];

    public function exists(string $path): bool
    {
        return array_key_exists($path, $this->files);
    }

    public function presence(array $paths): array
    {
        return array_combine($paths, array_map($this->exists(...), $paths));
    }

    public function readPair(string $directory, int $siiLimit, int $datLimit): array
    {
        $root = rtrim($directory, '/').'/';

        return ['server_packages.sii' => $this->read($root.'server_packages.sii', $siiLimit),
            'server_packages.dat' => $this->readBlob($root.'server_packages.dat', $datLimit)];
    }

    public function snapshotPair(string $directory, int $siiLimit, int $datLimit): array
    {
        $root = rtrim($directory, '/').'/';
        $sii = $this->read($root.'server_packages.sii', $siiLimit);

        return ['sii' => $sii, 'hashes' => ['server_packages.sii' => hash('sha256', $sii),
            'server_packages.dat' => $this->digest($root.'server_packages.dat', $datLimit)]];
    }

    public function digests(array $limits): array
    {
        $result = [];
        foreach ($limits as $path => $limit) {
            $result[$path] = $this->digest($path, $limit);
        }

        return $result;
    }

    public function read(string $path, int $limit): string
    {
        if (! $this->exists($path)) {
            throw new RuntimeException('File missing: '.$path);
        }
        if (strlen($this->files[$path]) > $limit) {
            throw new RuntimeException('File too large');
        }

        return $this->files[$path];
    }

    public function readBlob(string $path, int $limit): PackageFile
    {
        return PackageFile::fromString($this->read($path, $limit));
    }

    public function digest(string $path, int $limit): ?string
    {
        return $this->exists($path) ? hash('sha256', $this->read($path, $limit)) : null;
    }

    public function write(string $path, string|PackageFile $bytes): void
    {
        $bytes = $bytes instanceof PackageFile ? $bytes->bytes() : $bytes;
        $this->writes[] = ['path' => $path, 'bytes' => strlen($bytes)];
        $this->files[$path] = $bytes;
    }

    public function directory(string $path): void
    {
    }

    public function rename(string $from, string $to): void
    {
        $this->renames++;
        if ($this->beforeRename) {
            ($this->beforeRename)($this, $from, $to);
        }
        if ($this->failAlways || $this->renames === $this->failRename) {
            throw new RuntimeException('Simulated Wings failure');
        }
        if ($this->exists($to)) {
            throw new RuntimeException('Wings refuses rename: destination already exists');
        }
        $this->files[$to] = $this->read($from, PHP_INT_MAX);
        unset($this->files[$from]);
    }

    public function delete(string $path): void
    {
        unset($this->files[$path]);
    }
}
