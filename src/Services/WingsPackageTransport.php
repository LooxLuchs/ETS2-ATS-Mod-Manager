<?php

declare(strict_types=1);

namespace Ets2AtsModManager\Services;

use GuzzleHttp\Psr7\Utils;
use InvalidArgumentException;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Daemon\DaemonConnection;
use RuntimeException;

/** Uses the Panel's authenticated connection, TLS policy and timeouts. */
final class WingsPackageTransport implements PackageTransport
{
    private function consume(Server $server, string $path, int $limit, \Closure $consume): mixed
    {
        $response = DaemonConnection::forNode($server->node)->request('GET', "/api/servers/{$server->uuid}/files/contents", [
            'query' => ['file' => $path], 'stream' => true,
        ]);
        $body = $response->toPsrResponse()->getBody();
        try {
            $length = $response->header('Content-Length');
            if ($length !== null && $length !== '' && (! ctype_digit($length) || (float) $length > $limit)) {
                throw new InvalidArgumentException('The package file exceeds the size limit.');
            }
            $stream = $body->detach();
            if (! is_resource($stream)) {
                throw new RuntimeException('Wings did not return a readable file stream.');
            }
            try {
                $result = $consume($stream);
                $size = $result instanceof PackageFile ? $result->size : $result['size'];
                if ($length !== null && $length !== '' && $size !== (int) $length) {
                    throw new RuntimeException('Wings returned a truncated package file.');
                }

                return $result;
            } finally {
                fclose($stream);
            }
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new DaemonConnectionException($e, response: $response);
        } finally {
            $body->close();
        }
    }

    public function download(Server $server, string $path, int $limit): PackageFile
    {
        return $this->consume($server, $path, $limit, fn ($stream) => PackageFile::fromStream($stream, $limit));
    }

    public function checksum(Server $server, string $path, int $limit): string
    {
        $result = $this->consume($server, $path, $limit, function ($stream) use ($limit) {
            $hash = hash_init('sha256');
            $size = 0;
            while (! feof($stream)) {
                $chunk = fread($stream, min(65536, $limit - $size + 1));
                if ($chunk === false || ($chunk === '' && ! feof($stream))) {
                    throw new RuntimeException('Could not read the package file.');
                }
                $size += strlen($chunk);
                if ($size > $limit) {
                    throw new InvalidArgumentException('The package file exceeds the size limit.');
                }
                hash_update($hash, $chunk);
            }

            return ['size' => $size, 'hash' => hash_final($hash)];
        });

        return $result['hash'];
    }

    public function upload(Server $server, string $path, PackageFile $file): void
    {
        $body = Utils::streamFor($file->open());
        try {
            DaemonConnection::forNode($server->node)->request('POST', "/api/servers/{$server->uuid}/files/write", [
                'query' => ['file' => $path], 'body' => $body,
                'headers' => ['Content-Length' => (string) $file->size],
            ]);
            Activity::event('server:file.write')->subject($server)->property('file', $path)->log();
        } finally {
            $body->close();
        }
    }
}
