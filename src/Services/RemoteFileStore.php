<?php

declare(strict_types=1);

namespace Ets2AtsModManager\Services;

use InvalidArgumentException;
use Pterodactyl\Contracts\Files\CreatesDirectories;
use Pterodactyl\Contracts\Files\DeletesFiles;
use Pterodactyl\Contracts\Files\ListsDirectories;
use Pterodactyl\Contracts\Files\RenamesFiles;
use Pterodactyl\Contracts\Files\WritesFileContents;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Models\Server;

final class RemoteFileStore implements FileStore
{
    public function __construct(
        private Server $server,
        private ListsDirectories $lister,
        private WritesFileContents $writer,
        private CreatesDirectories $creator,
        private RenamesFiles $renamer,
        private DeletesFiles $deleter,
        private PackageTransport $transport
    ) {
    }

    private function assertSafePath(string $path): void
    {
        if (! str_starts_with($path, '/') || preg_match('/[\x00-\x1f\\\\]/', $path)
            || array_intersect(explode('/', $path), ['.', '..'])) {
            throw new InvalidArgumentException('Unsafe server path.');
        }
    }

    /** Listings are shared only within this call, never across reads or mutations. */
    private function inspect(string $path): ?array
    {
        return $this->inspectMany([$path])[$path];
    }

    private function inspectMany(array $paths): array
    {
        $listings = [];
        $result = [];
        foreach ($paths as $path) {
            $result[$path] = $this->inspectWithListings($path, $listings);
        }

        return $result;
    }

    private function inspectWithListings(string $path, array &$listings): ?array
    {
        $this->assertSafePath($path);
        $directory = '/';
        $entry = null;
        $segments = array_values(array_filter(explode('/', $path), fn ($s) => $s !== ''));
        foreach ($segments as $n => $name) {
            try {
                if (! array_key_exists($directory, $listings)) {
                    $listings[$directory] = [];
                    foreach ($this->lister->list($this->server, $directory) as $candidate) {
                        $listings[$directory][$candidate['name']] = $candidate;
                    }
                }
            } catch (DaemonConnectionException $e) {
                if ($e->getStatusCode() === 404) {
                    return null;
                }
                throw $e;
            }
            $entry = $listings[$directory][$name] ?? null;
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

    public function presence(array $paths): array
    {
        return array_map(fn ($entry) => $entry !== null, $this->inspectMany($paths));
    }

    public function readPair(string $directory, int $siiLimit, int $datLimit): array
    {
        [$sii, $datPath] = $this->pairSource($directory, $siiLimit, $datLimit);

        return ['server_packages.sii' => $sii,
            'server_packages.dat' => $this->transport->download($this->server, $datPath, $datLimit)];
    }

    public function snapshotPair(string $directory, int $siiLimit, int $datLimit): array
    {
        [$sii, $datPath] = $this->pairSource($directory, $siiLimit, $datLimit);

        return ['sii' => $sii, 'hashes' => ['server_packages.sii' => hash('sha256', $sii),
            'server_packages.dat' => $this->transport->checksum($this->server, $datPath, $datLimit)]];
    }

    private function pairSource(string $directory, int $siiLimit, int $datLimit): array
    {
        $root = rtrim($directory, '/').'/';
        $paths = [$root.'server_packages.sii', $root.'server_packages.dat'];
        $entries = $this->inspectMany($paths);
        $this->checkFile($entries[$paths[0]], $paths[0], $siiLimit);
        $this->checkFile($entries[$paths[1]], $paths[1], $datLimit);
        $sii = $this->transport->download($this->server, $paths[0], $siiLimit)->bytes();

        return [$sii, $paths[1]];
    }

    public function read(string $path, int $limit): string
    {
        $this->checkFile($this->inspect($path), $path, $limit);
        return $this->transport->download($this->server, $path, $limit)->bytes();
    }

    public function readBlob(string $path, int $limit): PackageFile
    {
        $this->checkFile($this->inspect($path), $path, $limit);

        return $this->transport->download($this->server, $path, $limit);
    }

    public function digest(string $path, int $limit): ?string
    {
        return $this->digests([$path => $limit])[$path];
    }

    public function digests(array $limits): array
    {
        $entries = $this->inspectMany(array_keys($limits));
        $result = [];
        foreach ($limits as $path => $limit) {
            if ($entries[$path] === null) {
                $result[$path] = null;
                continue;
            }
            $this->checkFile($entries[$path], $path, $limit);
            $result[$path] = $this->transport->checksum($this->server, $path, $limit);
        }

        return $result;
    }

    private function checkFile(?array $entry, string $path, int $limit): void
    {
        if (! $entry || ! ($entry['file'] ?? false)) {
            throw new InvalidArgumentException('Missing package file: '.basename($path));
        }
        if (($entry['size'] ?? 0) > $limit) {
            throw new InvalidArgumentException('The package file exceeds the size limit.');
        }
    }

    public function write(string $path, string|PackageFile $bytes): void
    {
        $entry = $this->inspect($path);
        if ($entry !== null && ! ($entry['file'] ?? false)) {
            throw new InvalidArgumentException('The target is not a regular file.');
        }
        if ($bytes instanceof PackageFile) {
            $this->transport->upload($this->server, $path, $bytes);
        } else {
            $this->writer->write($this->server, $path, $bytes);
        }
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
        $entries = $this->inspectMany([$from, $to]);
        $entry = $entries[$to];
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
