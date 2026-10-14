<?php

declare(strict_types=1);

namespace Ets2AtsModManager\Services;

use Closure;
use RuntimeException;
use Throwable;

/** Durable journal is persisted before the first destination rename. */
final class PairTransaction
{
    public function __construct(private FileStore $files, private PackageUpload $limits) {}

    public function replace(string $id, string $target, array $pair, Closure $persist): array
    {
        $this->limits->validate($pair);
        $backup = '/.ets2-ats-mod-manager/operations/'.$id;
        $journal = ['target' => $target, 'backup' => $backup, 'backup_mode' => 'move', 'originals' => [], 'expected' => self::hashes($pair)];
        $persist($journal, 'preparing');
        $swapping = false;
        $phase = 'preparing the package';
        try {
            $this->files->directory($target);
            $this->files->directory($backup);
            $this->files->directory($backup.'/staged');
            foreach (PackageUpload::FILES as $file) {
                $path = rtrim($target, '/').'/'.$file;
                $original = null;
                if ($this->files->exists($path)) {
                    $bytes = $this->files->read($path, $this->limit($file));
                    $original = hash('sha256', $bytes);
                    unset($bytes);
                }
                $journal['originals'][$file] = $original;
                $staged = $this->workingFile($id, 'staged', $file);
                $this->files->write($staged, $pair[$file]);
                $this->verify($staged, $journal['expected'][$file], $file);
            }
            $persist($journal, 'prepared');
            $persist($journal, 'swapping');
            $swapping = true;
            foreach (PackageUpload::FILES as $file) {
                $phase = 'replacing '.$file;
                // Detect edits made through SFTP/file manager since the snapshot.
                $this->verifyOriginal($journal, $file);
                if ($journal['originals'][$file] !== null) {
                    // Wings refuses rename-over-existing. Moving the original also avoids
                    // downloading and re-uploading its bytes just to create a backup.
                    $this->files->rename(rtrim($target, '/').'/'.$file, $backup.'/'.$file);
                    $this->verify($backup.'/'.$file, $journal['originals'][$file], $file);
                }
                $this->files->rename($this->workingFile($id, 'staged', $file), rtrim($target, '/').'/'.$file);
            }
            foreach (PackageUpload::FILES as $file) {
                $phase = 'verifying '.$file;
                $this->verify(rtrim($target, '/').'/'.$file, $journal['expected'][$file], $file);
            }

            return $journal;
        } catch (Throwable $e) {
            // Preparing never changes destination files. Do not restore a partial snapshot.
            try {
                if ($swapping) {
                    $this->rollback($id, $journal);
                }
                $persist($journal, 'rolled_back');
            } catch (Throwable $rollbackError) {
                $persist($journal, 'recovery_required');
                throw new PackageOperationException('Package update failed while '.$phase.'. Recovery is required. Operation: '.$id, 0, $rollbackError);
            }
            throw new PackageOperationException($swapping
                ? 'Package update failed while '.$phase.'. The previous package was restored. Operation: '.$id
                : 'Package preparation failed. The target files were not changed. Operation: '.$id, 0, $e);
        }
    }

    public function rollback(string $id, array $journal): void
    {
        foreach (PackageUpload::FILES as $file) {
            $path = rtrim($journal['target'], '/').'/'.$file;
            $displaced = $this->workingFile($id, 'displaced', $file);
            $original = $journal['originals'][$file] ?? null;
            $current = $this->files->exists($path) ? hash('sha256', $this->files->read($path, $this->limit($file))) : null;
            if ($current === $original) {
                continue;
            }
            $missingAfterMove = $current === null && ($journal['backup_mode'] ?? null) === 'move';
            // A previous recovery of a legacy journal may itself have stopped after
            // moving the new file aside. Its verified digest proves this missing target is ours.
            $missingDuringRecovery = $current === null && $this->hasDisplacedFile($id, $journal, $file);
            if ($current !== ($journal['expected'][$file] ?? null) && ! $missingAfterMove && ! $missingDuringRecovery) {
                throw new RuntimeException('A target file was changed externally. Automatic recovery was cancelled.');
            }
            if ($original !== null) {
                $bytes = $this->files->read($journal['backup'].'/'.$file, $this->limit($file));
                if (hash('sha256', $bytes) !== $original) {
                    throw new RuntimeException('The backup is corrupted.');
                }
                $staged = $this->workingFile($id, 'restore', $file);
                $this->files->directory(dirname($staged));
                $this->files->write($staged, $bytes);
                $this->verify($staged, $original, $file);
                if ($current !== null) {
                    // Keep the displaced new file rather than deleting it. This works
                    // for legacy copy journals too and never renames over an existing file.
                    $this->files->directory(dirname($displaced));
                    $this->files->rename($path, $displaced);
                    try {
                        $this->verify($displaced, $current, $file);
                    } catch (Throwable $e) {
                        // An external edit raced with the move: return that file if possible.
                        if (! $this->files->exists($path)) {
                            $this->files->rename($displaced, $path);
                        }
                        throw $e;
                    }
                }
                $this->files->rename($staged, $path);
                $this->verify($path, $original, $file);
            } else {
                $this->files->delete($path);
            }
        }
    }

    private function verifyOriginal(array $journal, string $file): void
    {
        $path = rtrim($journal['target'], '/').'/'.$file;
        $original = $journal['originals'][$file];
        if ($original === null) {
            if ($this->files->exists($path)) {
                throw new RuntimeException('Target files were changed concurrently.');
            }
        } else {
            $this->verify($path, $original, $file);
        }
    }

    private function verify(string $path, string $hash, string $file): void
    {
        if (hash('sha256', $this->files->read($path, $this->limit($file))) !== $hash) {
            throw new RuntimeException('File verification failed: '.$file);
        }
    }

    private function workingFile(string $id, string $area, string $file): string
    {
        return '/.ets2-ats-mod-manager/operations/'.$id.'/'.$area.'/'.$file;
    }

    /** Earlier recoveries could have stopped after moving a file beside the active pair. */
    private function hasDisplacedFile(string $id, array $journal, string $file): bool
    {
        $candidates = [
            $this->workingFile($id, 'displaced', $file),
            rtrim($journal['target'], '/').'/.ets2-ats-mm-'.$id.'-rollback-'.$file,
            rtrim($journal['target'], '/').'/.'.'scs'.'-mm-'.$id.'-rollback-'.$file,
        ];
        foreach ($candidates as $path) {
            if ($this->files->exists($path)
                && hash('sha256', $this->files->read($path, $this->limit($file))) === ($journal['expected'][$file] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function limit(string $file): int
    {
        return str_ends_with($file, '.sii') ? $this->limits->siiLimit : $this->limits->datLimit;
    }

    public static function hashes(array $pair): array
    {
        $hashes = [];
        foreach (PackageUpload::FILES as $name) {
            $hashes[$name] = hash('sha256', $pair[$name]);
        }

        return $hashes;
    }

    /** JSON object key order may change in MySQL; compare the named digests strictly. */
    public static function hashesMatch(array $a, array $b): bool
    {
        if (count($a) !== 2 || count($b) !== 2) {
            return false;
        }
        foreach (PackageUpload::FILES as $file) {
            if (! isset($a[$file], $b[$file]) || ! is_string($a[$file]) || ! is_string($b[$file])
                || ! hash_equals($a[$file], $b[$file])) {
                return false;
            }
        }

        return true;
    }
}
