<?php

declare(strict_types=1);

use Ets2AtsModManager\Models\Activation;
use Ets2AtsModManager\Models\Operation;
use Ets2AtsModManager\Models\Preset;
use Ets2AtsModManager\Services\Manager;
use Ets2AtsModManager\Services\PackageFile;
use Ets2AtsModManager\Services\PackageTransport;
use Ets2AtsModManager\Services\WingsPackageTransport;
use Ets2AtsModManager\Services\PackageUpload;
use Ets2AtsModManager\Services\PairTransaction;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Pterodactyl\Contracts\Files\CreatesDirectories;
use Pterodactyl\Contracts\Files\DeletesFiles;
use Pterodactyl\Contracts\Files\ListsDirectories;
use Pterodactyl\Contracts\Files\ReadsFileContents;
use Pterodactyl\Contracts\Files\RenamesFiles;
use Pterodactyl\Contracts\Files\WritesFileContents;
use Pterodactyl\Events\ActivityLogged;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Subuser;
use Pterodactyl\Models\User;
use Symfony\Component\Process\Process;

require_once __DIR__.'/fixtures.php';

/** A Wings contract fake. HTTP requests still traverse the real panel middleware. */
final class FakeWings implements CreatesDirectories, DeletesFiles, ListsDirectories, ReadsFileContents, RenamesFiles, WritesFileContents, PackageTransport
{
    public array $files = [];

    public array $directories = ['/'];

    public bool $symlink = false;

    public int $renames = 0;

    public ?int $failRename = null;

    public array $reads = [];

    public ?Closure $beforeRead = null;

    public array $writes = [];

    public int $lists = 0;

    public function list(Server $server, string $directory = '/'): array
    {
        $this->lists++;
        if ($this->symlink && $directory === '/') {
            return [['name' => '.local', 'file' => false, 'symlink' => true]];
        }
        $root = rtrim($directory, '/').'/';
        $out = [];
        foreach ($this->directories as $path) {
            if ($path !== $directory && dirname($path) === ($directory === '/' ? '/' : rtrim($directory, '/'))) {
                $out[basename($path)] = ['name' => basename($path), 'file' => false, 'symlink' => false, 'size' => 0];
            }
        }
        foreach ($this->files as $path => $bytes) {
            if (dirname($path) === ($directory === '/' ? '/' : rtrim($directory, '/'))) {
                $out[basename($path)] = ['name' => basename($path), 'file' => true, 'symlink' => false, 'size' => strlen($bytes)];
            }
        }

        return array_values($out);
    }

    public function read(Server $server, string $path, ?int $maxBytes = null): string
    {
        if ($this->beforeRead) {
            ($this->beforeRead)($this, $path);
        }
        $this->reads[] = $path;
        $bytes = $this->files[$path] ?? throw new RuntimeException('missing fake file');
        if ($maxBytes !== null && strlen($bytes) > $maxBytes) {
            throw new InvalidArgumentException('oversized fake file');
        }

        return $bytes;
    }

    public function write(Server $server, string $path, string $contents): void
    {
        $this->writes[] = ['path' => $path, 'bytes' => strlen($contents)];
        $this->files[$path] = $contents;
    }

    public function download(Server $server, string $path, int $limit): PackageFile
    {
        return PackageFile::fromString($this->read($server, $path, $limit));
    }

    public function checksum(Server $server, string $path, int $limit): string
    {
        return hash('sha256', $this->read($server, $path, $limit));
    }

    public function upload(Server $server, string $path, PackageFile $file): void
    {
        $this->write($server, $path, $file->bytes());
    }

    public function create(Server $server, string $name, string $root): void
    {
        $this->directories[] = rtrim($root, '/').'/'.$name;
    }

    public function rename(Server $server, ?string $root, array $files): void
    {
        $this->renames++;
        if ($this->renames === $this->failRename) {
            throw new RuntimeException('Wings failure');
        }
        foreach ($files as $file) {
            $from = '/'.ltrim($file['from'], '/');
            $to = '/'.ltrim($file['to'], '/');
            if (isset($this->files[$to])) {
                throw new RuntimeException('Cannot move or rename file, destination already exists.');
            }
            if (in_array($from, $this->directories, true)) {
                if (in_array($to, $this->directories, true)) {
                    throw new RuntimeException('Cannot move or rename directory, destination already exists.');
                }
                $this->directories = array_map(
                    fn (string $path): string => $path === $from || str_starts_with($path, $from.'/')
                        ? $to.substr($path, strlen($from))
                        : $path,
                    $this->directories,
                );
                foreach (array_keys($this->files) as $path) {
                    if (str_starts_with($path, $from.'/')) {
                        $this->files[$to.substr($path, strlen($from))] = $this->files[$path];
                        unset($this->files[$path]);
                    }
                }

                continue;
            }
            $this->files[$to] = $this->files[$from];
            unset($this->files[$from]);
        }
    }

    public function delete(Server $server, ?string $root, array $files): void
    {
        foreach ($files as $file) {
            $path = '/'.ltrim($file, '/');
            foreach (array_keys($this->files) as $candidate) {
                if ($candidate === $path || str_starts_with($candidate, $path.'/')) {
                    unset($this->files[$candidate]);
                }
            }
        }
    }
}

final class ManagerIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    private Server $server;

    private FakeWings $wings;

    private string $url;

    private string $sii;

    public function test_stream_transport_preserves_binary_bytes_and_uses_authenticated_bounded_requests(): void
    {
        $bytes = "\0\xff\x80map-data\r\n";
        Http::fake(function ($request, $options) use ($bytes) {
            self::assertTrue($request->hasHeader('Authorization'));
            self::assertStringContainsString('/api/servers/'.$this->server->uuid.'/files/', $request->url());
            if ($request->method() === 'GET') {
                self::assertTrue($options['stream']);

                return Http::response($bytes, 200, ['Content-Length' => (string) strlen($bytes)]);
            }
            self::assertSame($bytes, $request->body());
            self::assertTrue($request->hasHeader('Content-Length', (string) strlen($bytes)));

            return Http::response('', 204);
        });
        $transport = new WingsPackageTransport();
        $file = $transport->download($this->server, '/packages/server_packages.dat', strlen($bytes));
        self::assertSame($bytes, $file->bytes());
        self::assertSame(hash('sha256', $bytes), $transport->checksum($this->server, '/packages/server_packages.dat', strlen($bytes)));
        $transport->upload($this->server, '/staged/server_packages.dat', $file);
        self::assertSame($bytes, $file->bytes());
    }

    public function test_stream_transport_rejects_oversized_unannounced_and_truncated_responses(): void
    {
        $transport = new WingsPackageTransport();
        $bytes = '';
        $headers = [];
        Http::fake(function () use (&$bytes, &$headers) {
            return Http::response($bytes, 200, $headers);
        });
        foreach (['download', 'checksum'] as $method) {
            $bytes = '12345';
            $headers = [];
            try {
                $transport->$method($this->server, '/package.dat', 4);
                self::fail('Unannounced oversized response accepted');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
            $bytes = '123';
            $headers = ['Content-Length' => '5'];
            try {
                $transport->$method($this->server, '/package.dat', 5);
                self::fail('Truncated response accepted');
            } catch (\Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException $e) {
                self::assertSame(502, $e->getStatusCode());
            }
        }
    }

    public function test_library_refresh_has_no_wings_io_and_keeps_matching_after_preset_changes(): void
    {
        $id = $this->uploadPreset();
        $this->postJson($this->url.'/presets/'.$id.'/activate', ['revision' => 1])->assertOk();
        $hashes = \Ets2AtsModManager\Models\Preset::findOrFail($id)->hashes;
        $order = array_reverse(array_column(\Ets2AtsModManager\Models\Preset::findOrFail($id)->metadata['mods'], 'id'));
        $this->putJson($this->url.'/presets/'.$id.'/order', ['revision' => 1, 'order' => $order, 'confirm_experimental_removal' => false])->assertOk();
        $this->wings->reads = [];
        $this->wings->lists = 0;
        $this->getJson($this->url.'/library?'.http_build_query(['hashes' => $hashes]))->assertOk()
            ->assertJsonPath('active_match.preset_id', null)->assertJsonPath('active_match.source_preset_id', $id)
            ->assertJsonPath('presets.0.revision', 2)->assertJsonMissingPath('presets.0.metadata.mods')
            ->assertJsonPath('limits.mods', 120)->assertJsonPath('limits.warn_mods', 100);
        self::assertSame([], $this->wings->reads);
        self::assertSame(0, $this->wings->lists);
        $operation = Operation::where('server_id', $this->server->id)->where('kind', 'activate')->firstOrFail();
        $journal = $operation->journal;
        $changed = $journal;
        $changed['expected']['server_packages.dat'] = strtoupper($changed['expected']['server_packages.dat']);
        $operation->update(['journal' => $changed]);
        $this->getJson($this->url.'/library?'.http_build_query(['hashes' => $hashes]))->assertOk()->assertJsonPath('active_match.source_preset_id', null);
        $changed = $journal;
        $changed['expected']['extra'] = 'foreign';
        $operation->update(['journal' => $changed]);
        $this->getJson($this->url.'/library?'.http_build_query(['hashes' => $hashes]))->assertOk()->assertJsonPath('active_match.source_preset_id', null);
        $journal['expected'] = array_reverse($journal['expected'], true);
        $operation->update(['journal' => $journal]);
        $this->getJson($this->url.'/library?'.http_build_query(['hashes' => $hashes]))->assertOk()->assertJsonPath('active_match.source_preset_id', $id);
        $numericHashes = ['server_packages.sii' => str_repeat('1', 64), 'server_packages.dat' => str_repeat('2', 64)];
        $numericJournal = '{"expected":{"server_packages.sii":"'.$numericHashes['server_packages.sii'].'","server_packages.dat":'.$numericHashes['server_packages.dat'].'}}';
        DB::table($operation->getTable())->where('id', $operation->id)->update(['journal' => $numericJournal]);
        $this->getJson($this->url.'/library?'.http_build_query(['hashes' => $numericHashes]))->assertOk()->assertJsonPath('active_match.source_preset_id', null);
        $operation->refresh()->update(['journal' => $journal]);
        $this->getJson($this->url.'/library?'.http_build_query(['hashes' => ['bad' => 'bad']]))->assertUnprocessable();
        $this->subuser(['file.read']);
        $this->getJson($this->url.'/library')->assertForbidden();
    }

    public function test_overview_summaries_omit_mod_arrays_but_details_still_return_all_mods(): void
    {
        $id = $this->uploadPreset();
        $this->getJson($this->url)->assertOk()->assertJsonMissingPath('presets.0.metadata.mods')
            ->assertJsonPath('presets.0.metadata.mod_count', 70)->assertJsonPath('presets.0.metadata.workshop_count', 55);
        $this->getJson($this->url.'/presets/'.$id)->assertOk()->assertJsonCount(70, 'metadata.mods');
    }

    public function test_mod_count_limit_is_enforced_for_both_games_and_before_file_changes(): void
    {
        foreach (['ets2', 'ats'] as $game) {
            Activation::where('egg_id', $this->server->egg_id)->update(['game' => $game]);
            foreach ([99, 100, 120] as $count) {
                $this->postActivePair(packageSii($count))->assertOk()->assertJsonPath('metadata.mod_count', $count);
            }
            $before = $this->wings->files;
            $this->wings->writes = [];
            $this->postActivePair(packageSii(121))->assertUnprocessable();
            $this->wings->files['/.local/share/Euro Truck Simulator 2/server_packages.sii'] = packageSii(121);
            $this->postJson($this->url.'/import-current', ['name' => 'Too many'])->assertUnprocessable();
            self::assertSame([], $this->wings->writes);
            $this->wings->files = $before;
            $hashes = $this->getJson($this->url)->assertOk()->json('active.hashes');
            $this->wings->reads = [];
            $this->putJson($this->url.'/active/order', ['hashes' => $hashes, 'order' => array_map(fn ($n) => 'mod.'.$n, range(0, 120)),
                'confirm_experimental_removal' => false])->assertUnprocessable();
            self::assertSame([], $this->wings->reads);
            self::assertSame($before, $this->wings->files);
        }
    }

    public function test_pair_inspections_are_shared_within_a_call_and_rechecked_on_the_next_call(): void
    {
        $this->external_active();
        $files = app(Manager::class)->files($this->server);
        $this->wings->lists = 0;
        $files->readPair('/.local/share/Euro Truck Simulator 2', 4194304, 67108864);
        self::assertSame(4, $this->wings->lists);
        $this->wings->symlink = true;
        $this->expectException(InvalidArgumentException::class);
        $files->readPair('/.local/share/Euro Truck Simulator 2', 4194304, 67108864);
    }

    public function test_corrupt_staging_is_detected_without_replacing_the_installed_pair(): void
    {
        $this->external_active();
        $before = $this->wings->files;
        $this->wings->beforeRead = function ($files, $path) {
            if (str_contains($path, '/staged/') && str_ends_with($path, '.dat')) {
                $files->files[$path] = 'corrupted';
            }
        };
        $this->postActivePair()->assertConflict();
        foreach ($before as $path => $bytes) {
            self::assertSame($bytes, $this->wings->files[$path]);
        }
        self::assertSame('rolled_back', Operation::where('server_id', $this->server->id)->latest()->firstOrFail()->status);
    }

    public function test_transactions_use_fewer_directory_queries_without_removing_integrity_reads(): void
    {
        $this->external_active();
        $files = app(Manager::class)->files($this->server);
        $this->wings->lists = 0;
        $this->wings->reads = [];
        (new PairTransaction($files, new PackageUpload()))->replace(
            'io-probe',
            '/.local/share/Euro Truck Simulator 2',
            ['server_packages.sii' => $this->sii, 'server_packages.dat' => PackageFile::fromString('new dat')],
            fn () => null
        );
        self::assertLessThan(104, $this->wings->lists);
        self::assertCount(10, $this->wings->reads);
        self::assertCount(2, $this->wings->writes);
    }

    public function createApplication()
    {
        $app = require getenv('PANEL_PATH').'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false, 'pterodactyl.auth.2fa_required' => 0]);
        Event::fake([ActivityLogged::class]);
        Http::preventStrayRequests();
        $this->server = Server::factory()->withRelationships()->create();
        $this->actingAs($this->server->user);
        $this->url = '/api/client/servers/'.$this->server->uuidShort.'/extensions/ets2-ats-mod-manager';
        $this->sii = file_get_contents(__DIR__.'/fixtures/seventy-mods.sii');
        $this->wings = new FakeWings();
        foreach ([ListsDirectories::class, ReadsFileContents::class, WritesFileContents::class, CreatesDirectories::class, RenamesFiles::class, DeletesFiles::class, PackageTransport::class] as $contract) {
            $this->app->instance($contract, $this->wings);
        }
        Activation::create(['egg_id' => $this->server->egg_id, 'game' => 'ets2', 'path_template' => '/.local/share/Euro Truck Simulator 2', 'enabled' => true]);
    }

    private function subuser(array $permissions): User
    {
        $user = User::factory()->create();
        Subuser::create(['server_id' => $this->server->id, 'user_id' => $user->id, 'permissions' => $permissions]);
        $this->actingAs($user);

        return $user;
    }

    private function uploaded(string $name, string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'scs-upload-');
        file_put_contents($path, $bytes);
        $this->beforeApplicationDestroyed(fn () => is_file($path) ? unlink($path) : null);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function uploadPreset(): string
    {
        $r = $this->post($this->url.'/presets', ['name' => 'Convoy', 'files' => [$this->uploaded('server_packages.sii', $this->sii), $this->uploaded('server_packages.dat', "\0\xffmap")]], ['Accept' => 'application/json']);
        $r->assertOk()->assertJsonPath('preset.metadata.mod_count', 70);

        return $r->json('preset.id');
    }

    public function test_owner_can_upload_activate_edit_and_restore(): void
    {
        $id = $this->uploadPreset();
        $this->postJson($this->url.'/presets/'.$id.'/activate', ['revision' => 1])->assertOk()->assertJsonPath('restart_required', true);
        $this->getJson($this->url)->assertOk()->assertJsonPath('active.preset_id', $id);
        $initial = Preset::findOrFail($id);
        $order = array_reverse(array_column($initial->metadata['mods'], 'id'));
        $this->putJson($this->url.'/presets/'.$id.'/order', ['revision' => 1, 'order' => $order, 'confirm_experimental_removal' => false])->assertOk()->assertJsonPath('preset.revision', 2);
        $this->getJson($this->url)->assertOk()->assertJsonPath('active.state', 'external')->assertJsonPath('active.source_preset_id', $id);
        $snapshot = Operation::where('preset_id', $id)->where('kind', 'edit')->firstOrFail();
        $this->postJson($this->url.'/presets/'.$id.'/restore/'.$snapshot->id, ['revision' => 2])->assertOk()->assertJsonPath('preset.revision', 3);
        $this->getJson($this->url)->assertOk()->assertJsonPath('active.preset_id', $id);
    }

    public function test_missing_file_and_invalid_sii_are_rejected_before_any_writes(): void
    {
        $this->post($this->url.'/presets', ['name' => 'Missing', 'files' => [$this->uploaded('server_packages.sii', $this->sii)]], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post($this->url.'/presets', ['name' => 'Bad', 'files' => [$this->uploaded('server_packages.sii', 'bad'), $this->uploaded('server_packages.dat', 'dat')]], ['Accept' => 'application/json'])->assertUnprocessable();
        self::assertSame([], $this->wings->files);
    }

    public function test_read_only_user_cannot_upload_edit_or_activate(): void
    {
        $id = $this->uploadPreset();
        $this->subuser(['file.read', 'file.read-content']);
        $this->getJson($this->url.'/presets/'.$id)->assertOk();
        $this->postJson($this->url.'/presets')->assertForbidden();
        $this->putJson($this->url.'/presets/'.$id.'/order')->assertForbidden();
        $this->postJson($this->url.'/presets/'.$id.'/activate', ['revision' => 1])->assertForbidden();
        $this->deleteJson($this->url.'/presets/'.$id, ['revision' => 1])->assertForbidden();
    }

    public function test_delete_and_zip_require_their_additional_permissions(): void
    {
        $id = $this->uploadPreset();
        $this->subuser(['file.read', 'file.read-content', 'file.create', 'file.update']);
        $preset = Preset::findOrFail($id);
        $order = array_column($preset->metadata['mods'], 'id');
        array_pop($order);
        $this->putJson($this->url.'/presets/'.$id.'/order', ['revision' => 1, 'order' => $order, 'confirm_experimental_removal' => true])->assertForbidden();
        $this->post($this->url.'/presets', ['name' => 'ZIP', 'files' => [$this->uploaded('pair.zip', 'not-a-zip')]], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_foreign_preset_ids_and_nonmembers_return404(): void
    {
        $id = $this->uploadPreset();
        $other = Server::factory()->withRelationships()->create(['owner_id' => $this->server->owner_id]);
        Activation::create(['egg_id' => $other->egg_id, 'game' => 'ets2', 'path_template' => '/', 'enabled' => true]);
        $url = '/api/client/servers/'.$other->uuidShort.'/extensions/ets2-ats-mod-manager/presets/'.$id;
        $this->getJson($url)->assertNotFound();
        $this->actingAs(User::factory()->create());
        $this->getJson($this->url)->assertNotFound();
    }

    public function test_activation_disabled_blocks_direct_endpoints(): void
    {
        Activation::where('egg_id', $this->server->egg_id)->update(['enabled' => false]);
        $this->getJson($this->url.'/presence')->assertOk()->assertJsonPath('enabled', false);
        $this->getJson($this->url)->assertNotFound();
        $this->getJson($this->url.'/quick')->assertNotFound();
        $this->postActivePair()->assertNotFound();
        $this->putJson($this->url.'/active/order', ['hashes' => ['server_packages.sii' => str_repeat('a', 64), 'server_packages.dat' => str_repeat('b', 64)], 'order' => [], 'confirm_experimental_removal' => false])->assertNotFound();
        $this->postJson($this->url.'/import', ['name' => 'Import', 'hashes' => ['server_packages.sii' => str_repeat('a', 64), 'server_packages.dat' => str_repeat('b', 64)]])->assertNotFound();
    }

    private function postActivePair(?string $sii = null, string $dat = "quick\0dat")
    {
        return $this->post($this->url.'/active/packages', ['files' => [
            $this->uploaded('server_packages.dat', $dat),
            $this->uploaded('server_packages.sii', $sii ?? $this->sii),
        ]], ['Accept' => 'application/json']);
    }

    public function test_quick_drop_replaces_only_active_files_and_backs_up_the_previous_pair(): void
    {
        $id = $this->uploadPreset();
        $this->postJson($this->url.'/presets/'.$id.'/activate', ['revision' => 1])->assertOk();
        $preset = Preset::findOrFail($id)->toArray();
        $library = array_filter($this->wings->files, fn ($key) => str_starts_with($key, '/.ets2-ats-mod-manager/presets/'), ARRAY_FILTER_USE_KEY);
        $sii = str_replace('time_compression: 15.560001', 'time_compression: 20', $this->sii);
        $this->postActivePair($sii)->assertOk()->assertJsonPath('restart_required', true)->assertJsonPath('metadata.mod_count', 70);
        self::assertSame($preset, Preset::findOrFail($id)->toArray());
        self::assertSame(1, Preset::where('server_id', $this->server->id)->count());
        self::assertSame($library, array_intersect_key($this->wings->files, $library));
        self::assertSame($sii, $this->wings->files['/.local/share/Euro Truck Simulator 2/server_packages.sii']);
        self::assertSame("quick\0dat", $this->wings->files['/.local/share/Euro Truck Simulator 2/server_packages.dat']);
        $operation = Operation::where('server_id', $this->server->id)->where('kind', 'apply_active')->firstOrFail();
        self::assertNull($operation->preset_id);
        self::assertSame('completed', $operation->status);
        self::assertSame($this->sii, $this->wings->files[$operation->journal['backup'].'/server_packages.sii']);
        self::assertSame("\0\xffmap", $this->wings->files[$operation->journal['backup'].'/server_packages.dat']);
    }

    public function test_quick_drop_also_installs_into_an_empty_target_without_creating_presets(): void
    {
        $this->postActivePair()->assertOk();
        $this->getJson($this->url)->assertOk()->assertJsonPath('active.state', 'external');
        self::assertSame(0, Preset::where('server_id', $this->server->id)->count());
    }

    public function test_apply_twice_uses_wings_non_overwriting_renames_without_uploading_backups(): void
    {
        $this->external_active();
        foreach (["first\0dat", "second\0dat"] as $dat) {
            $previous = $this->wings->files['/.local/share/Euro Truck Simulator 2/server_packages.dat'];
            $this->wings->writes = [];
            $this->postActivePair(dat: $dat)->assertOk();
            self::assertCount(2, $this->wings->writes);
            foreach ($this->wings->writes as $write) {
                self::assertStringStartsWith('/.ets2-ats-mod-manager/operations/', $write['path']);
                self::assertStringContainsString('/staged/', $write['path']);
            }
            self::assertSame(strlen($this->sii) + strlen($dat), array_sum(array_column($this->wings->writes, 'bytes')));
            self::assertSame($dat, $this->wings->files['/.local/share/Euro Truck Simulator 2/server_packages.dat']);
            // Dotted filename JSON keys are selected in PHP for DB portability.
            $operation = Operation::where('server_id', $this->server->id)->where('kind', 'apply_active')->get()
                ->first(fn ($op) => $op->journal['expected']['server_packages.dat'] === hash('sha256', $dat));
            self::assertSame('move', $operation->journal['backup_mode']);
            self::assertSame($previous, $this->wings->files[$operation->journal['backup'].'/server_packages.dat']);
        }
        $gameFiles = array_filter(
            array_keys($this->wings->files),
            fn ($path) => str_starts_with($path, '/.local/share/Euro Truck Simulator 2/')
        );
        self::assertEqualsCanonicalizing([
            '/.local/share/Euro Truck Simulator 2/server_packages.sii',
            '/.local/share/Euro Truck Simulator 2/server_packages.dat',
        ], array_values($gameFiles));
    }

    public function test_preset_details_use_stored_metadata_without_wings_reads_but_activation_still_checks_hashes(): void
    {
        $id = $this->uploadPreset();
        $this->wings->files['/.ets2-ats-mod-manager/presets/'.$id.'/server_packages.dat'] = 'external edit';
        $this->wings->reads = [];
        $this->wings->lists = 0;
        $this->getJson($this->url.'/presets/'.$id)->assertOk()->assertJsonPath('metadata.mod_count', 70);
        self::assertSame([], $this->wings->reads);
        self::assertSame(0, $this->wings->lists);
        $this->postJson($this->url.'/presets/'.$id.'/activate', ['revision' => 1])->assertConflict();
    }

    public function test_json_object_key_reordering_does_not_break_activation_matching_or_restore(): void
    {
        $id = $this->uploadPreset();
        $preset = Preset::findOrFail($id);
        $preset->update(['hashes' => array_reverse($preset->hashes, true)]);
        $this->postJson($this->url.'/presets/'.$id.'/activate', ['revision' => 1])->assertOk();
        $this->getJson($this->url)->assertOk()->assertJsonPath('active.preset_id', $id);
        $activate = Operation::where('preset_id', $id)->where('kind', 'activate')->firstOrFail();
        $journal = $activate->journal;
        $journal['expected'] = array_reverse($journal['expected'], true);
        $activate->update(['journal' => $journal]);
        $order = array_reverse(array_column($preset->metadata['mods'], 'id'));
        $this->putJson($this->url.'/presets/'.$id.'/order', ['revision' => 1, 'order' => $order, 'confirm_experimental_removal' => false])->assertOk();
        $this->getJson($this->url)->assertOk()->assertJsonPath('active.source_preset_id', $id);
        $edit = Operation::where('preset_id', $id)->where('kind', 'edit')->firstOrFail();
        $journal = $edit->journal;
        $journal['originals'] = array_reverse($journal['originals'], true);
        $edit->update(['journal' => $journal]);
        $this->postJson($this->url.'/presets/'.$id.'/restore/'.$edit->id, ['revision' => 2])->assertOk();
        $this->getJson($this->url)->assertOk()->assertJsonPath('active.preset_id', $id);
    }

    public function test_quick_save_captures_current_files_with_one_source_read_and_checks_permissions(): void
    {
        $this->external_active();
        $path = '/.local/share/Euro Truck Simulator 2/server_packages.dat';
        $this->wings->files[$path] = 'latest export';
        $this->wings->reads = [];
        $r = $this->postJson($this->url.'/import-current', ['name' => 'Current export'])->assertOk();
        self::assertSame(1, count(array_filter($this->wings->reads, fn ($read) => $read === $path)));
        self::assertSame('latest export', $this->wings->files['/.ets2-ats-mod-manager/presets/'.$r->json('preset.id').'/server_packages.dat']);
        self::assertSame('latest export', $this->wings->files[$path]);
        $this->subuser(['file.read', 'file.read-content']);
        $this->postJson($this->url.'/import-current', ['name' => 'Forbidden'])->assertForbidden();
        $this->actingAs(User::factory()->create());
        $this->postJson($this->url.'/import-current', ['name' => 'Foreign'])->assertNotFound();
        $this->actingAs($this->server->user);
        Activation::where('egg_id', $this->server->egg_id)->update(['enabled' => false]);
        $this->postJson($this->url.'/import-current', ['name' => 'Disabled'])->assertNotFound();
    }

    public function test_directory_creation_walks_each_ancestor_once_and_rejects_unsafe_paths(): void
    {
        $files = app(Manager::class)->files($this->server);
        $files->directory('/first/second/third/fourth');
        self::assertSame(4, $this->wings->lists);
        foreach (['../escape', '/safe/../escape', '/bad\\path'] as $path) {
            try {
                $files->directory($path);
                self::fail('Unsafe path accepted');
            } catch (InvalidArgumentException) {
                self::assertSame(4, $this->wings->lists);
            }
        }
        $this->wings->symlink = true;
        $this->expectException(InvalidArgumentException::class);
        $files->directory('/.local/export');
    }

    public function test_quick_save_rejects_missing_or_invalid_current_files_without_creating_presets(): void
    {
        $this->postJson($this->url.'/import-current', ['name' => 'Missing'])->assertUnprocessable();
        $this->external_active();
        $this->wings->files['/.local/share/Euro Truck Simulator 2/server_packages.sii'] = 'invalid';
        $before = $this->wings->files;
        $this->postJson($this->url.'/import-current', ['name' => 'Invalid'])->assertUnprocessable();
        self::assertSame($before, $this->wings->files);
        self::assertSame(0, Preset::where('server_id', $this->server->id)->count());
    }

    public function test_quick_drop_invalid_pairs_are_rejected_without_touching_server_files(): void
    {
        $this->external_active();
        $before = $this->wings->files;
        $this->post($this->url.'/active/packages', ['files' => [$this->uploaded('server_packages.sii', $this->sii)]], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->postActivePair('invalid')->assertUnprocessable();
        $this->postActivePair(dat: '')->assertUnprocessable();
        $this->post($this->url.'/active/packages', ['files' => [$this->uploaded('server_packages.sii', $this->sii), $this->uploaded('server_packages.sii', $this->sii)]], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post($this->url.'/active/packages', ['files' => [$this->uploaded('../server_packages.sii', $this->sii), $this->uploaded('other.dat', 'dat')]], ['Accept' => 'application/json'])->assertUnprocessable();
        self::assertSame($before, $this->wings->files);
        self::assertSame(0, Operation::where('server_id', $this->server->id)->count());
    }

    public function test_quick_drop_requires_write_rights_and_membership(): void
    {
        $this->subuser(['file.read', 'file.read-content']);
        $this->getJson($this->url.'/quick')->assertOk();
        $this->postActivePair()->assertForbidden();
        $this->subuser(['file.read', 'file.read-content', 'file.create', 'file.update']);
        $this->postActivePair()->assertOk(); // No archive/delete right needed to replace a pair.
        $this->actingAs(User::factory()->create());
        $this->getJson($this->url.'/quick')->assertNotFound();
        $this->postActivePair()->assertNotFound();
    }

    public function test_failed_quick_drop_restores_both_original_files(): void
    {
        $this->external_active();
        $this->wings->failRename = 2;
        $this->postActivePair()->assertConflict();
        self::assertSame($this->sii, $this->wings->files['/.local/share/Euro Truck Simulator 2/server_packages.sii']);
        self::assertSame("external\0dat", $this->wings->files['/.local/share/Euro Truck Simulator 2/server_packages.dat']);
        self::assertSame('rolled_back', Operation::where('server_id', $this->server->id)->where('kind', 'apply_active')->firstOrFail()->status);
    }

    public function test_quick_list_is_server_scoped_and_does_not_read_wings_files(): void
    {
        $id = $this->uploadPreset();
        $other = Server::factory()->withRelationships()->create(['owner_id' => $this->server->owner_id]);
        $foreign = Preset::findOrFail($id)->replicate();
        $foreign->id = (string) Str::uuid();
        $foreign->server_id = $other->id;
        $foreign->save();
        // A Wings error would reject overview; quick list must need no Wings call.
        $this->wings->symlink = true;
        $this->getJson($this->url.'/quick')->assertOk()->assertJsonCount(1, 'presets')
            ->assertJsonPath('presets.0.id', $id)->assertJsonPath('presets.0.revision', 1)
            ->assertJsonPath('presets.0.mod_count', 70)->assertJsonPath('pending', false)
            ->assertJsonMissingPath('presets.0.metadata');
    }

    public function test_quick_drop_waits_for_background_work_and_blocks_interrupted_operations(): void
    {
        $this->with_background_lock(fn () => $this->postActivePair()->assertOk());
        $operation = Operation::create(['id' => (string) Str::uuid(), 'server_id' => $this->server->id, 'kind' => 'apply_active', 'status' => 'preparing', 'journal' => []]);
        $before = $this->wings->files;
        $this->getJson($this->url.'/quick')->assertOk()->assertJsonPath('pending', true);
        $this->postActivePair(dat: 'changed')->assertConflict();
        self::assertSame($before, $this->wings->files);
        $this->postJson($this->url.'/operations/'.$operation->id.'/recover')->assertOk();
        $this->getJson($this->url.'/quick')->assertOk()->assertJsonPath('pending', false);
    }

    public function test_stale_revision_and_externally_modified_preset_are_rejected(): void
    {
        $id = $this->uploadPreset();
        $this->postJson($this->url.'/presets/'.$id.'/activate', ['revision' => 2])->assertConflict();
        $this->wings->files['/.ets2-ats-mod-manager/presets/'.$id.'/server_packages.dat'] = 'changed';
        $this->postJson($this->url.'/presets/'.$id.'/activate', ['revision' => 1])->assertConflict();
    }

    public function test_symlink_ancestor_is_rejected(): void
    {
        $this->wings->symlink = true;
        $this->getJson($this->url)->assertUnprocessable();
    }

    public function test_admin_endpoints_require_root_admin_and_validate_unique_egg_and_path(): void
    {
        $url = '/api/admin/extensions/ets2-ats-mod-manager';
        $this->getJson($url)->assertForbidden();
        $this->actingAs(User::factory()->create(['root_admin' => true]));
        $this->getJson($url)->assertOk()->assertJsonCount(8, 'placeholders');
        $this->postJson($url.'/activations', ['egg_id' => $this->server->egg_id, 'game' => 'ets2', 'path_template' => '/', 'enabled' => true])->assertUnprocessable();
        $a = Activation::where('egg_id', $this->server->egg_id)->firstOrFail();
        $this->putJson($url.'/activations/'.$a->id, ['egg_id' => $this->server->egg_id, 'game' => 'ats', 'path_template' => '/../etc', 'enabled' => true])->assertUnprocessable();
    }

    public function test_pending_operation_blocks_writes_and_can_recover_without_changing_files(): void
    {
        $id = $this->uploadPreset();
        $op = Operation::create(['id' => (string) Str::uuid(), 'server_id' => $this->server->id, 'preset_id' => $id, 'kind' => 'activate', 'status' => 'preparing', 'journal' => []]);
        $this->postJson($this->url.'/presets/'.$id.'/activate', ['revision' => 1])->assertConflict();
        $this->postJson($this->url.'/operations/'.$op->id.'/recover')->assertOk();
        self::assertSame('rolled_back', $op->refresh()->status);
        $this->postJson($this->url.'/presets/'.$id.'/activate', ['revision' => 1])->assertOk();
    }

    public function test_second_rename_failure_restores_active_pair_and_leaves_preset_inactive(): void
    {
        $id = $this->uploadPreset();
        $this->postJson($this->url.'/presets/'.$id.'/activate', ['revision' => 1])->assertOk();
        $oldSii = $this->wings->files['/.local/share/Euro Truck Simulator 2/server_packages.sii'];
        $this->wings->renames = 0;
        $this->wings->failRename = 2;
        // Change the library first, then fail during its activation.
        $preset = Preset::findOrFail($id);
        $order = array_reverse(array_column($preset->metadata['mods'], 'id'));
        $this->wings->failRename = null;
        $this->putJson($this->url.'/presets/'.$id.'/order', ['revision' => 1, 'order' => $order, 'confirm_experimental_removal' => false])->assertOk();
        $this->wings->renames = 0;
        $this->wings->failRename = 2;
        $this->postJson($this->url.'/presets/'.$id.'/activate', ['revision' => 2])->assertConflict();
        self::assertSame($oldSii, $this->wings->files['/.local/share/Euro Truck Simulator 2/server_packages.sii']);
        self::assertSame(0, Operation::where('server_id', $this->server->id)->whereIn('status', Operation::PENDING)->count());
        $this->getJson($this->url)->assertOk()->assertJsonPath('active.preset_id', null);
    }

    public function test_dat_first_upload_is_recognized_and_external_pair_can_be_imported(): void
    {
        $r = $this->post($this->url.'/presets', ['name' => 'DAT first', 'files' => [$this->uploaded('server_packages.dat', 'dat'), $this->uploaded('server_packages.sii', $this->sii)]], ['Accept' => 'application/json']);
        $r->assertOk();
        $id = $r->json('preset.id');
        $this->getJson($this->url.'/presets/'.$id)->assertOk();
        $this->postJson($this->url.'/presets/'.$id.'/activate', ['revision' => 1])->assertOk();
        $active = $this->getJson($this->url)->assertOk()->json('active');
        self::assertSame($id, $active['preset_id']);
        $this->deleteJson($this->url.'/presets/'.$id, ['revision' => 1])->assertOk();
        $this->getJson($this->url)->assertOk()->assertJsonPath('active.state', 'external');
        $this->postJson($this->url.'/import', ['name' => 'Reimport', 'hashes' => array_reverse($active['hashes'], true)])->assertOk();
    }

    public function test_other_worker_holding_the_server_lock_blocks_mutation(): void
    {
        $id = $this->uploadPreset();
        config(['database.connections.scs_lock_test' => config('database.connections.mysql')]);
        $other = DB::connection('scs_lock_test');
        $key = 'ets2-ats-mod-manager:'.$this->server->id;
        $other->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$key]);
        try {
            $this->postJson($this->url.'/presets/'.$id.'/activate', ['revision' => 1])->assertConflict();
        } finally {
            $other->selectOne('SELECT RELEASE_LOCK(?) AS released', [$key]);
            $other->disconnect();
        }
    }

    public function test_active_edits_update_only_server_files_and_keep_a_verified_backup(): void
    {
        $id = $this->uploadPreset();
        $this->postJson($this->url.'/presets/'.$id.'/activate', ['revision' => 1])->assertOk();
        $active = $this->getJson($this->url)->assertOk()->json('active');
        $order = array_reverse(array_column($active['metadata']['mods'], 'id'));
        $this->putJson($this->url.'/active/order', ['hashes' => $active['hashes'], 'order' => $order, 'confirm_experimental_removal' => false])
            ->assertOk()->assertJsonPath('restart_required', true)->assertJsonPath('metadata.mods.0.id', $order[0]);
        self::assertSame($this->sii, $this->wings->files['/.ets2-ats-mod-manager/presets/'.$id.'/server_packages.sii']);
        self::assertSame(1, Preset::findOrFail($id)->revision);
        self::assertSame("\0\xffmap", $this->wings->files['/.local/share/Euro Truck Simulator 2/server_packages.dat']);
        $snapshot = Operation::where('server_id', $this->server->id)->where('kind', 'edit_active')->firstOrFail();
        self::assertSame('completed', $snapshot->status);
        self::assertNull($snapshot->preset_id);
        self::assertSame($this->sii, $this->wings->files[$snapshot->journal['backup'].'/server_packages.sii']);
        $this->getJson($this->url)->assertOk()->assertJsonPath('active.preset_id', null)->assertJsonPath('active.metadata.mods.0.id', $order[0]);
    }

    private function external_active(): array
    {
        $this->wings->directories = ['/', '/.local', '/.local/share', '/.local/share/Euro Truck Simulator 2'];
        $this->wings->files['/.local/share/Euro Truck Simulator 2/server_packages.sii'] = $this->sii;
        $this->wings->files['/.local/share/Euro Truck Simulator 2/server_packages.dat'] = "external\0dat";

        return $this->getJson($this->url)->assertOk()->json('active');
    }

    public function test_external_active_package_can_be_edited_without_creating_a_template(): void
    {
        $active = $this->external_active();
        $order = array_reverse(array_column($active['metadata']['mods'], 'id'));
        $this->putJson($this->url.'/active/order', ['hashes' => $active['hashes'], 'order' => $order, 'confirm_experimental_removal' => false])->assertOk();
        self::assertSame(0, Preset::where('server_id', $this->server->id)->count());
        self::assertSame("external\0dat", $this->wings->files['/.local/share/Euro Truck Simulator 2/server_packages.dat']);
        $this->getJson($this->url)->assertOk()->assertJsonPath('active.metadata.mods.0.id', $order[0]);
    }

    public function test_active_edit_checks_hashes_and_does_not_overwrite_external_changes(): void
    {
        $active = $this->external_active();
        $changed = str_replace('time_compression: 15.560001', 'time_compression: 20', $this->sii);
        $this->wings->files['/.local/share/Euro Truck Simulator 2/server_packages.sii'] = $changed;
        $this->putJson($this->url.'/active/order', ['hashes' => $active['hashes'], 'order' => array_reverse(array_column($active['metadata']['mods'], 'id')), 'confirm_experimental_removal' => false])->assertConflict();
        self::assertSame($changed, $this->wings->files['/.local/share/Euro Truck Simulator 2/server_packages.sii']);
        self::assertSame(0, Operation::where('server_id', $this->server->id)->count());
    }

    public function test_active_removal_requires_confirmation_and_delete_permission(): void
    {
        $active = $this->external_active();
        $order = array_column($active['metadata']['mods'], 'id');
        array_pop($order);
        $this->subuser(['file.read', 'file.read-content', 'file.create', 'file.update']);
        $body = ['hashes' => $active['hashes'], 'order' => $order, 'confirm_experimental_removal' => true];
        $this->putJson($this->url.'/active/order', $body)->assertForbidden();
        $this->putJson($this->url.'/active/order', [...$body, 'confirm_experimental_removal' => false])->assertUnprocessable();
        self::assertSame($this->sii, $this->wings->files['/.local/share/Euro Truck Simulator 2/server_packages.sii']);
        $this->actingAs($this->server->user);
        $this->putJson($this->url.'/active/order', $body)->assertOk()->assertJsonPath('metadata.mod_count', 69);
        self::assertSame("external\0dat", $this->wings->files['/.local/share/Euro Truck Simulator 2/server_packages.dat']);
    }

    public function test_read_only_users_and_nonmembers_cannot_edit_active_files(): void
    {
        $this->external_active();
        $this->subuser(['file.read', 'file.read-content']);
        $this->putJson($this->url.'/active/order')->assertForbidden();
        $this->actingAs(User::factory()->create());
        $this->putJson($this->url.'/active/order')->assertNotFound();
        self::assertSame($this->sii, $this->wings->files['/.local/share/Euro Truck Simulator 2/server_packages.sii']);
    }

    public function test_failed_active_file_switch_restores_the_original_pair(): void
    {
        $active = $this->external_active();
        $this->wings->renames = 0;
        $this->wings->failRename = 2;
        $this->putJson($this->url.'/active/order', ['hashes' => $active['hashes'], 'order' => array_reverse(array_column($active['metadata']['mods'], 'id')), 'confirm_experimental_removal' => false])->assertConflict();
        self::assertSame($this->sii, $this->wings->files['/.local/share/Euro Truck Simulator 2/server_packages.sii']);
        self::assertSame("external\0dat", $this->wings->files['/.local/share/Euro Truck Simulator 2/server_packages.dat']);
        self::assertSame('rolled_back', Operation::where('server_id', $this->server->id)->where('kind', 'edit_active')->firstOrFail()->status);
    }

    private function with_background_lock(Closure $action): mixed
    {
        $worker = new Process([
            PHP_BINARY, __DIR__.'/lock-worker.php',
            json_encode(config('database.connections.mysql'), JSON_THROW_ON_ERROR),
            'ets2-ats-mod-manager:'.$this->server->id,
        ], timeout: 5);
        $worker->start();
        try {
            self::assertTrue($worker->waitUntil(fn ($type, $output) => str_contains($output, 'LOCKED')), $worker->getErrorOutput());
            $result = $action();
            self::assertSame(0, $worker->wait(), $worker->getErrorOutput());

            return $result;
        } finally {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
    }

    public function test_first_locked_operation_moves_the_existing_hidden_library_to_the_new_directory(): void
    {
        $legacy = '/.'.'scs'.'-mod-manager';
        $current = '/.ets2-ats-mod-manager';
        $this->wings->directories = ['/', $legacy, $legacy.'/presets', $legacy.'/presets/example'];
        $this->wings->files[$legacy.'/presets/example/server_packages.sii'] = $this->sii;
        $this->wings->files[$legacy.'/presets/example/server_packages.dat'] = "\0legacy";

        $result = app(Manager::class)->locked($this->server, fn () => 'migrated');

        self::assertSame('migrated', $result);
        self::assertNotContains($legacy, $this->wings->directories);
        self::assertContains($current.'/presets/example', $this->wings->directories);
        self::assertArrayNotHasKey($legacy.'/presets/example/server_packages.dat', $this->wings->files);
        self::assertSame("\0legacy", $this->wings->files[$current.'/presets/example/server_packages.dat']);
    }

    public function test_background_read_lock_does_not_fail_overview_or_detail(): void
    {
        $id = $this->uploadPreset();
        $this->with_background_lock(fn () => $this->getJson($this->url)->assertOk());
        $this->with_background_lock(fn () => $this->getJson($this->url.'/presets/'.$id)->assertOk());
    }

    public function test_import_waits_for_background_read_and_releases_its_lock(): void
    {
        $id = $this->uploadPreset();
        $this->postJson($this->url.'/presets/'.$id.'/activate', ['revision' => 1])->assertOk();
        $hashes = $this->getJson($this->url)->assertOk()->json('active.hashes');
        $this->with_background_lock(fn () => $this->postJson($this->url.'/import', ['name' => 'Queued import', 'hashes' => $hashes])->assertOk());
        self::assertSame(1, (int) DB::selectOne('SELECT IS_FREE_LOCK(?) AS available', ['ets2-ats-mod-manager:'.$this->server->id])->available);
    }

    public function test_saving_the_active_package_creates_a_new_preset_without_changing_existing_files(): void
    {
        $id = $this->uploadPreset();
        $this->postJson($this->url.'/presets/'.$id.'/activate', ['revision' => 1])->assertOk();
        $active = $this->getJson($this->url)->assertOk()->json('active');
        $existing = Preset::findOrFail($id)->toArray();
        $files = $this->wings->files;
        $response = $this->postJson($this->url.'/import', ['name' => 'Console copy', 'hashes' => $active['hashes']]);
        $response->assertOk()->assertJsonPath('preset.name', 'Console copy')
            ->assertJsonPath('preset.revision', 1)->assertJsonPath('preset.hashes', $active['hashes'])
            ->assertJsonMissingPath('restart_required');
        self::assertNotSame($id, $response->json('preset.id'));
        self::assertSame(2, Preset::where('server_id', $this->server->id)->count());
        self::assertSame($existing, Preset::findOrFail($id)->toArray());
        self::assertSame($files, array_intersect_key($this->wings->files, $files));
        $this->getJson($this->url.'/quick')->assertOk()->assertJsonCount(2, 'presets');
    }

    public function test_saving_a_changed_active_snapshot_is_rejected_and_requires_write_access(): void
    {
        $active = $this->external_active();
        $this->wings->files['/.local/share/Euro Truck Simulator 2/server_packages.dat'] = 'changed after opening dialog';
        $files = $this->wings->files;
        $body = ['name' => 'Console copy', 'hashes' => $active['hashes']];
        $this->postJson($this->url.'/import', $body)->assertConflict();
        self::assertSame(0, Preset::where('server_id', $this->server->id)->count());
        self::assertSame($files, $this->wings->files);
        $this->subuser(['file.read', 'file.read-content']);
        $this->postJson($this->url.'/import', $body)->assertForbidden();
        $this->actingAs(User::factory()->create());
        $this->postJson($this->url.'/import', $body)->assertNotFound();
    }

    public function test_download_waits_for_background_read_and_preserves_the_pair(): void
    {
        $id = $this->uploadPreset();
        $response = $this->with_background_lock(fn () => $this->get($this->url.'/presets/'.$id.'/download')->assertOk());
        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path));
        self::assertSame("\0\xffmap", $zip->getFromName('server_packages.dat'));
        self::assertSame($this->sii, $zip->getFromName('server_packages.sii'));
        $zip->close();
        unlink($path);
    }

    public function test_download_returns_zip_with_the_exact_binary_pair(): void
    {
        $id = $this->uploadPreset();
        $response = $this->get($this->url.'/presets/'.$id.'/download');
        $response->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path));
        self::assertSame("\0\xffmap", $zip->getFromName('server_packages.dat'));
        self::assertSame($this->sii, $zip->getFromName('server_packages.sii'));
        $zip->close();
        unlink($path);
    }
}
