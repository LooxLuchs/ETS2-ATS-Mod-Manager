<?php

use Ets2AtsModManager\Services\FileStore;

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

    public function write(string $path, string $bytes): void
    {
        $this->writes[] = ['path' => $path, 'bytes' => strlen($bytes)];
        $this->files[$path] = $bytes;
    }

    public function directory(string $path): void {}

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
