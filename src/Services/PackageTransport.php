<?php

declare(strict_types=1);

namespace Ets2AtsModManager\Services;

use Pterodactyl\Models\Server;

interface PackageTransport
{
    public function download(Server $server, string $path, int $limit): PackageFile;

    public function checksum(Server $server, string $path, int $limit): string;

    public function upload(Server $server, string $path, PackageFile $file): void;
}
