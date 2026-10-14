<?php

declare(strict_types=1);

namespace Ets2AtsModManager\Http\Controllers;

use Closure;
use Ets2AtsModManager\Models\Activation;
use Ets2AtsModManager\Models\Operation;
use Ets2AtsModManager\Services\Access;
use Ets2AtsModManager\Services\Manager;
use Ets2AtsModManager\Services\PackageOperationException;
use Ets2AtsModManager\Services\PackageUpload;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

final class ServerController
{
    public function __construct(private Manager $manager, private PackageUpload $upload) {}

    public function presence(Request $request, Server $server): array
    {
        $allowed = true;
        foreach (Access::READ as $permission) {
            $allowed = $allowed && $request->user()->can($permission, $server);
        }

        return ['enabled' => $allowed && Activation::where('egg_id', $server->egg_id)->where('enabled', true)->exists()];
    }

    public function index(Request $request, Server $server): array
    {
        Access::require($request, $server, Access::READ);

        return $this->valid(fn () => $this->manager->locked($server, fn () => $this->manager->overview($server), recovery: true));
    }

    public function quick(Request $request, Server $server): array
    {
        Access::require($request, $server, Access::READ);

        return $this->manager->quick($server);
    }

    public function applyActive(Request $request, Server $server): array
    {
        Access::require($request, $server, Access::WRITE);
        $request->validate(['files' => ['required', 'array', 'size:2'], 'files.*' => ['required', 'file']]);

        return $this->valid(function () use ($request, $server) {
            $pair = $this->uploadedPair($request, $server, false);

            return $this->manager->locked($server, fn () => $this->manager->applyActive($server, $pair));
        });
    }

    public function show(Request $request, Server $server, string $preset): array
    {
        Access::require($request, $server, Access::READ);

        // Library metadata was validated when saved. File hashes are still checked on
        // activation, edits and downloads; simply selecting a preset needs no DAT transfer.
        return $this->valid(function () use ($server, $preset) {
            $model = $this->manager->preset($server, $preset);
            $snapshots = Operation::where('server_id', $server->id)->where('preset_id', $model->id)
                ->where('status', 'completed')->whereIn('kind', ['edit', 'restore'])->orderByDesc('created_at')->get();

            return ['preset' => $model, 'metadata' => $model->metadata,
                'snapshots' => $snapshots->map(fn ($s) => ['id' => $s->id, 'created_at' => $s->created_at,
                    'revision' => $s->journal['before']['revision'] ?? null, 'kind' => $s->kind])];
        });
    }

    public function store(Request $request, Server $server): array
    {
        Access::require($request, $server, Access::WRITE);
        $data = $request->validate(['name' => ['required', 'string', 'max:100'],
            'files' => ['required', 'array', 'min:1', 'max:2'], 'files.*' => ['required', 'file']]);

        return $this->valid(function () use ($request, $server, $data) {
            $pair = $this->uploadedPair($request, $server);

            return $this->manager->locked($server, fn () => ['preset' => $this->manager->create($server, $data['name'], $pair)]);
        });
    }

    private function uploadedPair(Request $request, Server $server, bool $allowZip = true): array
    {
        $files = array_values($request->file('files'));
        $pair = [];
        if ($allowZip && count($files) === 1 && strtolower(pathinfo($files[0]->getClientOriginalName(), PATHINFO_EXTENSION)) === 'zip') {
            Access::require($request, $server, ['file.archive']);
            $pair = $this->upload->zip($files[0]->getPathname());
        } else {
            foreach ($files as $file) {
                $name = $file->getClientOriginalName();
                if (! in_array($name, PackageUpload::FILES, true) || isset($pair[$name])) {
                    throw new InvalidArgumentException('Select exactly server_packages.sii and server_packages.dat.');
                }
                $limit = str_ends_with($name, '.sii') ? $this->upload->siiLimit : $this->upload->datLimit;
                if ($file->getSize() > $limit) {
                    throw new InvalidArgumentException('The file exceeds the upload limit.');
                }
                $pair[$name] = file_get_contents($file->getPathname());
            }
        }
        $this->upload->validate($pair);

        return $pair;
    }

    public function import(Request $request, Server $server): array
    {
        Access::require($request, $server, Access::WRITE);
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'hashes' => ['required', 'array'],
        ]);
        $expected = $this->expectedHashes($request);

        return $this->valid(fn () => $this->manager->locked($server,
            fn () => ['preset' => $this->manager->import($server, $data['name'], $expected)]));
    }

    public function importCurrent(Request $request, Server $server): array
    {
        Access::require($request, $server, Access::WRITE);
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);

        return $this->valid(fn () => $this->manager->locked($server,
            fn () => ['preset' => $this->manager->importCurrent($server, $data['name'])]));
    }

    public function activate(Request $request, Server $server, string $preset): array
    {
        Access::require($request, $server, Access::WRITE);
        $revision = $this->revision($request);

        return $this->valid(fn () => $this->manager->locked($server, function () use ($server, $preset, $revision) {
            $this->manager->activate($server, $this->manager->preset($server, $preset), $revision);

            return ['ok' => true, 'restart_required' => true];
        }));
    }

    public function edit(Request $request, Server $server, string $preset): array
    {
        Access::require($request, $server, Access::WRITE);
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'],
            'order' => ['present', 'array', 'max:10000'], 'order.*' => ['required', 'string', 'max:100', 'distinct:strict'],
            'confirm_experimental_removal' => ['required', 'boolean']]);

        return $this->valid(fn () => $this->manager->locked($server, function () use ($request, $server, $preset, $data) {
            $model = $this->manager->preset($server, $preset);
            if (count($data['order']) < $model->metadata['mod_count']) {
                Access::require($request, $server, ['file.delete']);
            }

            return ['preset' => $this->manager->edit($server, $model, (int) $data['revision'], $data['order'], $data['confirm_experimental_removal'])];
        }));
    }

    public function editActive(Request $request, Server $server): array
    {
        Access::require($request, $server, Access::WRITE);
        $data = $request->validate(['hashes' => ['required', 'array'],
            'order' => ['present', 'array', 'max:10000'], 'order.*' => ['required', 'string', 'max:100', 'distinct:strict'],
            'confirm_experimental_removal' => ['required', 'boolean']]);
        if ($data['confirm_experimental_removal']) {
            Access::require($request, $server, ['file.delete']);
        }
        $expected = $this->expectedHashes($request);

        return $this->valid(fn () => $this->manager->locked($server,
            fn () => $this->manager->editActive($server, $expected, $data['order'], $data['confirm_experimental_removal'])));
    }

    public function rename(Request $request, Server $server, string $preset): array
    {
        Access::require($request, $server, Access::WRITE);
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:100']]);

        return $this->manager->locked($server, function () use ($server, $preset, $data) {
            $model = $this->manager->preset($server, $preset);
            abort_unless($model->revision === (int) $data['revision'], 409, 'The preset has changed. Refresh and try again.');
            $model->update(['name' => $data['name'], 'revision' => $model->revision + 1]);
            Activity::event('ets2-ats-mod-manager:preset.rename')->subject($server)->property('preset', $model->id)->log();

            return ['preset' => $model];
        });
    }

    public function destroy(Request $request, Server $server, string $preset): array
    {
        Access::require($request, $server, [...Access::WRITE, 'file.delete']);
        $revision = $this->revision($request);

        return $this->manager->locked($server, function () use ($server, $preset, $revision) {
            $this->manager->delete($server, $this->manager->preset($server, $preset), $revision);

            return ['ok' => true];
        });
    }

    public function restore(Request $request, Server $server, string $preset, string $operation): array
    {
        Access::require($request, $server, Access::WRITE);
        $revision = $this->revision($request);

        return $this->valid(fn () => $this->manager->locked($server, fn () => ['preset' => $this->manager->restore($server, $this->manager->preset($server, $preset), $operation, $revision)]));
    }

    public function recover(Request $request, Server $server, string $operation): array
    {
        Access::require($request, $server, Access::WRITE);

        return $this->valid(fn () => $this->manager->locked($server, function () use ($server, $operation) {
            $this->manager->recover($server, $operation);

            return ['ok' => true];
        }, recovery: true));
    }

    public function download(Request $request, Server $server, string $preset): BinaryFileResponse
    {
        Access::require($request, $server, Access::READ);

        return $this->valid(function () use ($server, $preset) {
            [$model, $pair] = $this->manager->locked($server, function () use ($server, $preset) {
                $model = $this->manager->preset($server, $preset);

                return [$model, $this->manager->presetPair($server, $model)];
            }, recovery: true);
            $path = tempnam(sys_get_temp_dir(), 'scs-download-');
            $zip = new ZipArchive;
            try {
                if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                    throw new InvalidArgumentException('Could not create the download.');
                }
                foreach ($pair as $name => $bytes) {
                    if (! $zip->addFromString($name, $bytes)) {
                        throw new InvalidArgumentException('Could not create the download.');
                    }
                }
                if (! $zip->close()) {
                    throw new InvalidArgumentException('Could not create the download.');
                }
                Activity::event('ets2-ats-mod-manager:preset.download')->subject($server)->property('preset', $model->id)->log();

                return response()->download($path, 'server-packages-'.$model->id.'.zip')->deleteFileAfterSend();
            } catch (\Throwable $e) {
                if (is_file($path)) {
                    unlink($path);
                }
                throw $e;
            }
        });
    }

    private function revision(Request $request): int
    {
        return (int) $request->validate(['revision' => ['required', 'integer', 'min:1']])['revision'];
    }

    private function expectedHashes(Request $request): array
    {
        $expected = $request->input('hashes');
        if (count($expected) !== 2 || array_diff(PackageUpload::FILES, array_keys($expected))
            || count(array_filter($expected, fn ($h) => is_string($h) && preg_match('/^[a-f0-9]{64}$/D', $h))) !== 2) {
            throw ValidationException::withMessages(['hashes' => 'Invalid file hashes.']);
        }

        return ['server_packages.sii' => $expected['server_packages.sii'], 'server_packages.dat' => $expected['server_packages.dat']];
    }

    private function valid(Closure $action): mixed
    {
        try {
            return $action();
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['package' => $e->getMessage()]);
        } catch (PackageOperationException $e) {
            report($e);
            abort(409, $e->getMessage());
        }
    }
}
