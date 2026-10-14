<?php

declare(strict_types=1);

namespace Ets2AtsModManager\Services;

use Illuminate\Http\Request;
use Pterodactyl\Models\Server;

final class Access
{
    public const READ = ['file.read', 'file.read-content'];

    public const WRITE = ['file.read', 'file.read-content', 'file.create', 'file.update'];

    public static function require(Request $request, Server $server, array $permissions): void
    {
        foreach ($permissions as $permission) {
            abort_unless($request->user()?->can($permission, $server), 403, 'Missing permission: '.$permission);
        }
    }
}
