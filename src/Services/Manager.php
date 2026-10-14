<?php

declare(strict_types=1);

namespace Ets2AtsModManager\Services;

use Closure;
use Ets2AtsModManager\Models\Activation;
use Ets2AtsModManager\Models\Operation;
use Ets2AtsModManager\Models\Preset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\Server;
use Throwable;

final class Manager
{
    public function __construct(private PackageUpload $upload)
    {
    }

    public function activation(Server $server): Activation
    {
        return Activation::where('egg_id', $server->egg_id)->where('enabled', true)->firstOrFail();
    }

    public function files(Server $server): RemoteFileStore
    {
        return app()->makeWith(RemoteFileStore::class, ['server' => $server]);
    }

    private function directory(Preset $preset): string
    {
        return '/.ets2-ats-mod-manager/presets/'.$preset->id;
    }

    public function preset(Server $server, string $id): Preset
    {
        $preset = Preset::where('server_id', $server->id)->findOrFail($id);
        abort_unless($preset->game === $this->activation($server)->game, 409, 'This preset belongs to a different game.');

        return $preset;
    }

    public function pair(FileStore $files, string $directory): array
    {
        return $files->readPair($directory, $this->upload->siiLimit, $this->upload->datLimit);
    }

    public function presetPair(Server $server, Preset $preset): array
    {
        $pair = $this->pair($this->files($server), $this->directory($preset));
        abort_unless(
            PairTransaction::hashesMatch(PairTransaction::hashes($pair), $preset->hashes),
            409,
            'Preset files were changed outside Mod Manager. Import the package again.'
        );
        $this->upload->validate($pair);

        return $pair;
    }

    public function overview(Server $server): array
    {
        $activation = $this->activation($server);
        $target = PathTemplate::resolve($activation->path_template, $server);
        $presets = $this->library($server, $activation->game);
        $active = ['state' => 'missing', 'preset_id' => null, 'metadata' => null, 'hashes' => null];
        $files = $this->files($server);
        $present = $files->presence(array_map(fn ($name) => rtrim($target, '/').'/'.$name, PackageUpload::FILES));
        if (in_array(true, $present, true)) {
            if (in_array(false, $present, true)) {
                $active['state'] = 'incomplete';
                $active['message'] = 'One of the two package files is missing from the package directory.';
            } else {
                try {
                    $snapshot = $files->snapshotPair($target, $this->upload->siiLimit, $this->upload->datLimit);
                    if ($snapshot['sii'] === '' || $snapshot['hashes']['server_packages.dat'] === hash('sha256', '')) {
                        throw new InvalidArgumentException('Empty or oversized file: '.($snapshot['sii'] === '' ? 'server_packages.sii' : 'server_packages.dat'));
                    }
                    $active['metadata'] = $this->upload->document($snapshot['sii'])->metadata();
                    $active['hashes'] = $snapshot['hashes'];
                    $match = $presets->first(fn ($p) => PairTransaction::hashesMatch($p->hashes, $active['hashes']));
                    $active['preset_id'] = $match?->id;
                    $active['state'] = $match ? 'preset' : 'external';
                } catch (InvalidArgumentException $e) {
                    $active['state'] = 'invalid';
                    $active['message'] = $e->getMessage();
                }
            }
        }
        $active['source_preset_id'] = $active['preset_id'];
        if ($active['source_preset_id'] === null && $active['hashes'] !== null) {
            $active['source_preset_id'] = $this->sourcePreset($server, $active['hashes']);
        }

        return ['activation' => ['game' => $activation->game, 'target' => $target],
            'presets' => $presets, 'active' => $active,
            'pending' => $this->pending($server), 'limits' => $this->upload->limits()];
    }

    private function pending(Server $server): Collection
    {
        return Operation::where('server_id', $server->id)->whereIn('status', Operation::PENDING)
            ->get(['id', 'kind', 'status', 'created_at']);
    }

    private function sourcePreset(Server $server, array $hashes): ?string
    {
        $source = Operation::where('server_id', $server->id)->where('kind', 'activate')->where('status', 'completed')
            ->whereRaw("JSON_LENGTH(JSON_EXTRACT(journal, '$.expected')) = 2");
        foreach ($hashes as $file => $hash) {
            $path = '$.expected."'.$file.'"';
            $source->whereRaw("JSON_TYPE(JSON_EXTRACT(journal, ?)) = 'STRING' AND BINARY JSON_UNQUOTE(JSON_EXTRACT(journal, ?)) = ?", [$path, $path, $hash]);
        }

        return $source->orderByDesc('created_at')->value('preset_id');
    }

    /** Refresh library changes against the browser's last verified active snapshot. */
    public function libraryOverview(Server $server, ?array $hashes): array
    {
        $presets = $this->library($server, $this->activation($server)->game);
        $match = $hashes === null ? null : $presets->first(fn ($p) => PairTransaction::hashesMatch($p->hashes, $hashes));

        return ['presets' => $presets, 'pending' => $this->pending($server),
            'active_match' => ['preset_id' => $match?->id,
                'source_preset_id' => $match?->id ?? ($hashes !== null ? $this->sourcePreset($server, $hashes) : null)],
            'limits' => $this->upload->limits()];
    }

    /** List views never hydrate the full mod arrays. Detail keeps the complete metadata. */
    public function library(Server $server, string $game): Collection
    {
        return Preset::where('server_id', $server->id)->where('game', $game)
            ->select(['id', 'name', 'game', 'revision', 'hashes', 'imported_at', 'updated_at'])
            ->selectRaw("JSON_OBJECT('mod_count', JSON_EXTRACT(metadata, '$.mod_count'),
                'workshop_count', JSON_EXTRACT(metadata, '$.workshop_count'),
                'map_name', JSON_EXTRACT(metadata, '$.map_name'),
                'exported_at', JSON_EXTRACT(metadata, '$.exported_at')) AS metadata")
            ->orderByDesc('imported_at')->get();
    }

    /** Console controls only need library summaries, not the large active DAT file. */
    public function quick(Server $server): array
    {
        $activation = $this->activation($server);

        return ['presets' => Preset::where('server_id', $server->id)->where('game', $activation->game)
            ->select(['id', 'name', 'revision'])->selectRaw("JSON_EXTRACT(metadata, '$.mod_count') AS mod_count")
            ->orderByDesc('imported_at')->get()->map(fn ($preset) => [
                'id' => $preset->id, 'name' => $preset->name, 'revision' => $preset->revision,
                'mod_count' => (int) $preset->mod_count,
            ]),
            'pending' => Operation::where('server_id', $server->id)->whereIn('status', Operation::PENDING)->exists(),
            'limits' => $this->upload->limits()];
    }

    public function applyActive(Server $server, array $pair): array
    {
        $metadata = $this->upload->validate($pair);
        $activation = $this->activation($server);
        $target = PathTemplate::resolve($activation->path_template, $server);
        $this->change($server, null, 'apply_active', $target, $pair, fn () => null);

        return ['metadata' => $metadata, 'restart_required' => true];
    }

    /** MySQL/MariaDB session lock: shared across panel workers, no expiring lease. */
    public function locked(Server $server, Closure $action, bool $recovery = false): mixed
    {
        $this->activation($server);
        $key = 'ets2-ats-mod-manager:'.$server->id;
        $connection = DB::connection();
        // Background reads and user actions may overlap. Queue them instead of failing immediately.
        $result = $connection->selectOne('SELECT GET_LOCK(?, 30) AS acquired', [$key], false);
        abort_unless((int) $result->acquired === 1, 409, 'Server packages are still being processed. Wait a moment and try again.');
        try {
            if (! $recovery) {
                abort_if(
                    Operation::where('server_id', $server->id)->whereIn('status', Operation::PENDING)->exists(),
                    409,
                    'Recover the interrupted operation before making further changes.'
                );
                $this->migrateLegacyStorage($server);
            }

            return $action();
        } finally {
            $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$key], false);
        }
    }

    public function create(Server $server, string $name, array $pair): Preset
    {
        $metadata = $this->upload->validate($pair);
        $preset = new Preset(['id' => (string) Str::uuid(), 'server_id' => $server->id, 'name' => $name,
            'game' => $this->activation($server)->game, 'revision' => 1, 'metadata' => $metadata,
            'hashes' => PairTransaction::hashes($pair), 'imported_at' => now()]);
        $this->change($server, $preset, 'create', $this->directory($preset), $pair, fn () => $preset->save());

        return $preset;
    }

    public function import(Server $server, string $name, array $expected): Preset
    {
        $a = $this->activation($server);
        $pair = $this->pair($this->files($server), PathTemplate::resolve($a->path_template, $server));
        abort_unless(PairTransaction::hashesMatch(PairTransaction::hashes($pair), $expected), 409, 'The active package has changed. Refresh and try again.');

        return $this->create($server, $name, $pair);
    }

    /** Create-only quick save captures the current pair at confirmation, not while naming it. */
    public function importCurrent(Server $server, string $name): Preset
    {
        $a = $this->activation($server);

        return $this->create(
            $server,
            $name,
            $this->pair($this->files($server), PathTemplate::resolve($a->path_template, $server))
        );
    }

    public function activate(Server $server, Preset $preset, int $revision): void
    {
        $this->revision($preset, $revision);
        $pair = $this->presetPair($server, $preset);
        $a = $this->activation($server);
        $this->change($server, $preset, 'activate', PathTemplate::resolve($a->path_template, $server), $pair, fn () => null);
    }

    public function edit(Server $server, Preset $preset, int $revision, array $order, bool $confirmed): Preset
    {
        $this->revision($preset, $revision);
        $pair = $this->presetPair($server, $preset);
        $originalHashes = PairTransaction::hashes($pair);
        $edited = $this->upload->document($pair['server_packages.sii'])->editDocument($order, $confirmed);
        $this->upload->remember($edited);
        $pair['server_packages.sii'] = $edited->source();

        return $this->replacePreset($server, $preset, $pair, 'edit', $originalHashes);
    }

    public function editActive(Server $server, array $expected, array $order, bool $confirmed): array
    {
        $activation = $this->activation($server);
        $target = PathTemplate::resolve($activation->path_template, $server);
        $pair = $this->pair($this->files($server), $target);
        abort_unless(
            PairTransaction::hashesMatch(PairTransaction::hashes($pair), $expected),
            409,
            'The active package has changed. Refresh and try again.'
        );
        $edited = $this->upload->document($pair['server_packages.sii'])->editDocument($order, $confirmed);
        $this->upload->remember($edited);
        $pair['server_packages.sii'] = $edited->source();
        $metadata = $this->upload->validate($pair);
        $this->change($server, null, 'edit_active', $target, $pair, fn () => null, $expected);

        return ['metadata' => $metadata, 'hashes' => PairTransaction::hashes($pair), 'restart_required' => true];
    }

    private function replacePreset(Server $server, Preset $preset, array $pair, string $kind, ?array $originalHashes = null): Preset
    {
        $metadata = $this->upload->validate($pair);
        $this->change($server, $preset, $kind, $this->directory($preset), $pair, function () use ($preset, $pair, $metadata) {
            $preset->fill(['revision' => $preset->revision + 1, 'hashes' => PairTransaction::hashes($pair), 'metadata' => $metadata])->save();
        }, $originalHashes);

        return $preset->refresh();
    }

    public function restore(Server $server, Preset $preset, string $operation, int $revision): Preset
    {
        $this->revision($preset, $revision);
        $snapshot = Operation::where('server_id', $server->id)->where('preset_id', $preset->id)
            ->where('status', 'completed')->whereIn('kind', ['edit', 'restore'])->findOrFail($operation);
        $pair = $this->pair($this->files($server), $snapshot->journal['backup']);
        abort_unless(PairTransaction::hashesMatch(PairTransaction::hashes($pair), $snapshot->journal['originals']), 409, 'The backup is corrupted.');
        // Read current pair as well so an external edit is never silently overwritten.
        $current = $this->presetPair($server, $preset);

        return $this->replacePreset($server, $preset, $pair, 'restore', PairTransaction::hashes($current));
    }

    public function delete(Server $server, Preset $preset, int $revision): void
    {
        $this->revision($preset, $revision);
        // Delete only the library directory. The active files and recovery snapshots remain.
        $this->files($server)->delete($this->directory($preset));
        $preset->delete();
        Activity::event('ets2-ats-mod-manager:preset.delete')->subject($server)->property('preset', $preset->id)->log();
    }

    public function recover(Server $server, string $id): void
    {
        $operation = Operation::where('server_id', $server->id)->whereIn('status', Operation::PENDING)->findOrFail($id);
        $journal = $operation->journal;
        try {
            if (in_array($operation->status, ['swapping', 'recovery_required'], true)) {
                (new PairTransaction($this->files($server), $this->upload))->rollback($id, $journal);
            }
            $operation->update(['status' => 'rolled_back']);
            Activity::event('ets2-ats-mod-manager:operation.recover')->subject($server)->property('operation', $id)->log();
        } catch (Throwable $e) {
            $operation->update(['status' => 'recovery_required']);
            report($e);
            throw new PackageOperationException('Recovery failed. Backups have been retained.', 0, $e);
        }
    }

    private function change(Server $server, ?Preset $preset, string $kind, string $target, array $pair, Closure $finalize, ?array $originalHashes = null): void
    {
        $id = (string) Str::uuid();
        $before = $preset?->exists ? ['revision' => $preset->revision, 'name' => $preset->name] : null;
        $operation = Operation::create(['id' => $id, 'server_id' => $server->id, 'preset_id' => $preset?->id,
            'kind' => $kind, 'status' => 'preparing', 'journal' => []]);
        $transaction = new PairTransaction($this->files($server), $this->upload);
        $persist = function (array $journal, string $status) use ($operation, $before) {
            $operation->update(['journal' => $journal + ['before' => $before], 'status' => $status]);
        };
        try {
            $journal = $transaction->replace($id, $target, $pair, $persist, $originalHashes);
            DB::transaction(function () use ($finalize, $operation, $journal, $before) {
                $finalize();
                $operation->update(['status' => 'completed', 'journal' => $journal + ['before' => $before]]);
            });
        } catch (Throwable $e) {
            // Includes DB failures after both files were switched successfully.
            $operation->refresh();
            if ($operation->status === 'swapping') {
                try {
                    $transaction->rollback($id, $operation->journal);
                    $operation->update(['status' => 'rolled_back']);
                } catch (Throwable $rollbackError) {
                    $operation->update(['status' => 'recovery_required']);
                    report($rollbackError);
                }
            }
            throw $e;
        }
        Activity::event('ets2-ats-mod-manager:'.$kind)->subject($server)
            ->property(['preset' => $preset?->id, 'operation' => $id])->log();
    }

    private function revision(Preset $preset, int $revision): void
    {
        abort_unless($preset->revision === $revision, 409, 'The preset has changed. Refresh and try again.');
    }

    /** Move the former hidden library once, after pending legacy operations are resolved. */
    private function migrateLegacyStorage(Server $server): void
    {
        $files = $this->files($server);
        $legacy = '/.'.'scs'.'-mod-manager';
        $current = '/.ets2-ats-mod-manager';
        if (! $files->exists($current) && $files->exists($legacy)) {
            $files->rename($legacy, $current);
        }
    }
}
