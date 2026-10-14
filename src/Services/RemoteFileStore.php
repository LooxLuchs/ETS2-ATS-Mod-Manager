<?php

declare(strict_types=1);

namespace Ets2AtsModManager\Services;

use InvalidArgumentException;
use Pterodactyl\Contracts\Files\CreatesDirectories;
use Pterodactyl\Contracts\Files\DeletesFiles;
use Pterodactyl\Contracts\Files\ListsDirectories;
use Pterodactyl\Contracts\Files\ReadsFileContents;
use Pterodactyl\Contracts\Files\RenamesFiles;
use Pterodactyl\Contracts\Files\WritesFileContents;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Server;

final class RemoteFileStore implements FileStore
{
    public function __construct(private Server $server, private ListsDirectories $lister,
        private ReadsFileContents $reader, private WritesFileContents $writer,
        private CreatesDirectories $creator, private RenamesFiles $renamer, private DeletesFiles $deleter) {}

    private function assertSafePath(string $path): void
    {
        if (! str_starts_with($path, '/') || preg_match('/[\x00-\x1f\\\\]/', $path)
            || array_intersect(explode('/', $path), ['.', '..'])) {
            throw new InvalidArgumentException('Unsafe server path.');
        }
    }

    /** Inspect every ancestor: no operation follows a symlink, including internal storage. */
    private function inspect(string $path): ?array
    {
        $this->assertSafePath($path);
        $directory = '/';
        $entry = null;
        $segments = array_values(array_filter(explode('/', $path), fn ($s) => $s !== ''));
        foreach ($segments as $n => $name) {
            try {
                $entries = $this->lister->list($this->server, $directory);
            } catch (DaemonConnectionException $e) {
                if ($e->getStatusCode() === 404) {
                    return null;
                }
                throw $e;
            }
            $entry = null;
            foreach ($entries as $candidate) {
                if (($candidate['name'] ?? null) === $name) {
                    $entry = $candidate;
                    break;
                }
            }
            if ($entry === null) {
                return null;
            }
            if ($entry['symlink'] ?? false) {
                throw new InvalidArgumentException('Symlinks are not allowed for package files.');
            }
            if ($n < count($segments) - 1 && ($entry['file'] ?? false)) {
                throw new InvalidArgumentException('A file is blocking the package directory.');
            }
            $directory = rtrim($directory, '/').'/'.$name;
        }

        return $entry;
    }

    public function exists(string $path): bool
    {
        return $this->inspect($path) !== null;
    }

    public function read(string $path, int $limit): string
    {
        $entry = $this->inspect($path);
        if (! $entry || ! ($entry['file'] ?? false)) {
            throw new InvalidArgumentException('Missing package file: '.basename($path));
        }
        if (($entry['size'] ?? 0) > $limit) {
            throw new InvalidArgumentException('The package file exceeds the size limit.');
        }
        $bytes = $this->reader->read($this->server, $path, $limit);
        if (strlen($bytes) > $limit) {
            throw new InvalidArgumentException('The package file exceeds the size limit.');
        }

        return $bytes;
    }

    public function write(string $path, string $bytes): void
    {
        $entry = $this->inspect($path);
        if ($entry !== null && ! ($entry['file'] ?? false)) {
            throw new InvalidArgumentException('The target is not a regular file.');
        }
        $this->writer->write($this->server, $path, $bytes);
    }

    public function directory(string $path): void
    {
        $this->assertSafePath($path);
        $parent = '/';
        foreach (array_filter(explode('/', $path), fn ($s) => $s !== '') as $name) {
            $current = rtrim($parent, '/').'/'.$name;
            // Walk once rather than re-listing every previously checked ancestor.
            $entry = null;
            foreach ($this->lister->list($this->server, $parent) as $candidate) {
                if (($candidate['name'] ?? null) === $name) {
                    $entry = $candidate;
                    break;
                }
            }
            if ($entry === null) {
                $this->creator->create($this->server, $name, $parent);
            } elseif ($entry['symlink'] ?? false) {
                throw new InvalidArgumentException('Symlinks are not allowed for package files.');
            } elseif ($entry['file'] ?? false) {
                throw new InvalidArgumentException('The target directory is a file.');
            }
            $parent = $current;
        }
    }

    public function rename(string $from, string $to): void
    {
        $this->inspect($from);
        $entry = $this->inspect($to);
        if ($entry !== null && ! ($entry['file'] ?? false)) {
            throw new InvalidArgumentException('The target is not a regular file.');
        }
        $this->renamer->rename($this->server, '/', [['from' => ltrim($from, '/'), 'to' => ltrim($to, '/')]]);
    }

    public function delete(string $path): void
    {
        if ($this->exists($path)) {
            $this->deleter->delete($this->server, '/', [ltrim($path, '/')]);
        }
    }
}
