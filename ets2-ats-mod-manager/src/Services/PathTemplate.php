<?php

declare(strict_types=1);

namespace Ets2AtsModManager\Services;

use InvalidArgumentException;
use Pterodactyl\Models\Server;

final class PathTemplate
{
    public const PLACEHOLDERS = ['P_SERVER_UUID', 'P_SERVER_UUID_SHORT', 'P_SERVER_NAME', 'P_SERVER_ID',
        'P_SERVER_ALLOCATION_IP', 'P_SERVER_ALLOCATION_PORT', 'P_SERVER_NODE', 'P_SERVER_OWNER'];

    public static function resolve(string $template, Server $server): string
    {
        $server->loadMissing(['allocation', 'node', 'user']);

        return self::expand($template, [
            'P_SERVER_UUID' => $server->uuid, 'P_SERVER_UUID_SHORT' => $server->uuidShort,
            'P_SERVER_NAME' => $server->name, 'P_SERVER_ID' => (string) $server->id,
            'P_SERVER_ALLOCATION_IP' => $server->allocation?->ip ?? '',
            'P_SERVER_ALLOCATION_PORT' => (string) ($server->allocation?->port ?? ''),
            'P_SERVER_NODE' => $server->node?->name ?? '', 'P_SERVER_OWNER' => $server->user?->username ?? '',
        ]);
    }

    public static function validate(string $template): void
    {
        self::expand($template, array_fill_keys(self::PLACEHOLDERS, 'example'));
    }

    public static function expand(string $template, array $values): string
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $template)) {
            throw new InvalidArgumentException('Control characters are not allowed in paths.');
        }
        $path = preg_replace_callback('/\{\{env\.([A-Z_]+)\}\}/', function ($m) use ($values) {
            if (! in_array($m[1], self::PLACEHOLDERS, true) || ! isset($values[$m[1]]) || $values[$m[1]] === '') {
                throw new InvalidArgumentException('Unknown or empty placeholder: '.$m[1]);
            }

            // One server value can never inject another directory or a dot segment.
            return rawurlencode((string) $values[$m[1]]);
        }, trim($template));
        if ($path === '/home/container' || str_starts_with($path, '/home/container/')) {
            $path = substr($path, strlen('/home/container')) ?: '/';
        }
        if ($path === '' || strlen($path) > 2048 || str_contains($path, '{{') || str_contains($path, '}}')
            || preg_match('/[\x00-\x1f\x7f\\\\]/', $path) || str_starts_with($path, '//')) {
            throw new InvalidArgumentException('Invalid directory path.');
        }
        $segments = explode('/', trim($path, '/'));
        foreach ($segments as $segment) {
            if (in_array($segment, ['.', '..'], true) || preg_match('/%(?:2f|5c|00|2e)/i', $segment)) {
                throw new InvalidArgumentException('Unsafe path segment.');
            }
        }
        $path = '/'.implode('/', array_filter($segments, fn ($s) => $s !== ''));
        $legacyStorage = '/.'.'scs'.'-mod-manager';
        if ($path === '/.ets2-ats-mod-manager' || str_starts_with($path, '/.ets2-ats-mod-manager/')
            || $path === $legacyStorage || str_starts_with($path, $legacyStorage.'/')) {
            throw new InvalidArgumentException('The internal preset storage cannot be used as a package directory.');
        }

        return $path;
    }
}
