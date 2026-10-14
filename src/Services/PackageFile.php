<?php

declare(strict_types=1);

namespace Ets2AtsModManager\Services;

use InvalidArgumentException;
use RuntimeException;

/** An owned temporary file: DAT bytes never need to live in a PHP string. */
final class PackageFile
{
    private function __construct(
        private mixed $handle,
        public readonly int $size,
        public readonly string $hash,
        public readonly ?string $crc32
    ) {
    }

    public static function fromStream(mixed $stream, int $limit, bool $withCrc = false): self
    {
        $handle = tmpfile();
        if ($handle === false) {
            throw new RuntimeException('Could not create a temporary package file.');
        }
        $hash = hash_init('sha256');
        $crc = $withCrc ? hash_init('crc32b') : null;
        $size = 0;
        try {
            while (! feof($stream)) {
                $chunk = fread($stream, min(65536, $limit - $size + 1));
                if ($chunk === false || ($chunk === '' && ! feof($stream))) {
                    throw new RuntimeException('Could not read the package file.');
                }
                $size += strlen($chunk);
                if ($size > $limit) {
                    throw new InvalidArgumentException('The package file exceeds the size limit.');
                }
                if (fwrite($handle, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('Could not write the temporary package file.');
                }
                hash_update($hash, $chunk);
                if ($crc !== null) {
                    hash_update($crc, $chunk);
                }
            }
            if (! fflush($handle)) {
                throw new RuntimeException('Could not flush the temporary package file.');
            }

            return new self($handle, $size, hash_final($hash), $crc !== null ? hash_final($crc) : null);
        } catch (\Throwable $e) {
            fclose($handle);
            throw $e;
        }
    }

    public static function fromPath(string $path, int $limit): self
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Could not open the package file.');
        }
        try {
            return self::fromStream($stream, $limit);
        } finally {
            fclose($stream);
        }
    }

    public static function fromString(string $bytes): self
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Could not create a temporary stream.');
        }
        try {
            if (fwrite($stream, $bytes) !== strlen($bytes)) {
                throw new RuntimeException('Could not write the temporary stream.');
            }
            rewind($stream);

            return self::fromStream($stream, strlen($bytes));
        } finally {
            fclose($stream);
        }
    }

    public function path(): string
    {
        return stream_get_meta_data($this->handle)['uri'];
    }

    /** Open a separate read cursor; closing an HTTP body must not close our file. */
    public function open(): mixed
    {
        $stream = fopen($this->path(), 'rb');
        if ($stream === false) {
            throw new RuntimeException('Could not open the temporary package file.');
        }

        return $stream;
    }

    public function bytes(): string
    {
        rewind($this->handle);
        $bytes = stream_get_contents($this->handle);
        if ($bytes === false) {
            throw new RuntimeException('Could not read the temporary package file.');
        }

        return $bytes;
    }

    public function __destruct()
    {
        fclose($this->handle);
    }
}
