<?php

declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/MemoryFiles.php';
require __DIR__.'/fixtures.php';
use Ets2AtsModManager\Services\PackageUpload;
use Ets2AtsModManager\Services\PairTransaction;
use Ets2AtsModManager\Services\PathTemplate;
use Ets2AtsModManager\Services\SiiDocument;
use Ets2AtsModManager\Services\PackageFile;

$passed = 0;
function check(bool $value, string $message = 'Assertion failed'): void
{
    if (! $value) {
        throw new RuntimeException($message);
    }
}
function rejects(Closure $action): void
{
    try {
        $action();
    } catch (InvalidArgumentException|RuntimeException $e) {
        return;
    }
    throw new LogicException('Expected rejection');
}
function test(string $name, Closure $action): void
{
    global $passed;
    try {
        $action();
        $passed++;
        echo 'PASS '.$name.PHP_EOL;
    } catch (Throwable $e) {
        fwrite(STDERR, 'FAIL '.$name.': '.$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL);
        exit(1);
    }
}
$sii = file_get_contents(__DIR__.'/fixtures/seventy-mods.sii');
$pair = ['server_packages.sii' => $sii, 'server_packages.dat' => "\x00\xFF\x80map-data\r\n"];
$limits = new PackageUpload();
test('70 mods, Workshop count, priority, escapes, uint64 and links', function () use ($sii) {
    $m = (new SiiDocument($sii))->metadata();
    check($m['mod_count'] === 70 && $m['workshop_count'] === 55);
    check($m['mods'][0]['mod_name'] === 'Realistic Rain v5.2.1');
    check($m['mods'][69]['mod_id'] === '5067522809175364042');
    check($m['mods'][0]['checksum'] === '18446744073709551615');
    check($m['mods'][0]['workshop_url'] === 'https://steamcommunity.com/sharedfiles/filedetails/?id=1787041596');
    check($m['mods'][52]['mod_name'] === 'Top Einzelhändler und U');
    check($m['mods'][15]['mod_name'] === 'Geraldine – German Nav');
    check($m['mods'][38]['mod_name'] === 'Strands Ambassador 9"');
});
test('edit reverses UI order and preserves unknown fields and numeric bytes', function () use ($sii) {
    $doc = new SiiDocument($sii);
    $ids = array_column($doc->metadata()['mods'], 'id');
    $ids = array_reverse($ids);
    $out = $doc->edit($ids, false);
    check(array_column((new SiiDocument($out))->metadata()['mods'], 'id') === $ids);
    check(str_contains($out, 'time_compression: 15.560001'));
    check(str_contains($out, 'future_field: "keep { this }: unchanged" // preserved'));
    check(str_contains($out, 'roads_data_file_name: "/home/server_packages.dat"'));
    check(str_contains($out, 'checksum: 18446744073709551615'));
});
test('removal requires confirmation, removes unit and renumbers references', function () use ($sii) {
    $doc = new SiiDocument($sii);
    $ids = array_column($doc->metadata()['mods'], 'id');
    $gone = array_shift($ids);
    rejects(fn () => $doc->edit($ids, false));
    $out = $doc->edit($ids, true);
    check(! str_contains($out, $gone));
    check((new SiiDocument($out))->metadata()['mod_count'] === 69);
    check((new SiiDocument($doc->edit([], true)))->metadata()['mod_count'] === 0);
});
test('malformed counts, duplicate units, missing references and unknown mods rejected', function () use ($sii) {
    rejects(fn () => new SiiDocument(str_replace('mod_list: 70', 'mod_list: 71', $sii)));
    rejects(fn () => new SiiDocument(str_replace('mod_list[1]: _nameless.test.001', 'mod_list[1]: _nameless.test.000', $sii)));
    rejects(fn () => new SiiDocument(str_replace('mod_list[1]: _nameless.test.001', 'mod_list[1]: missing', $sii)));
    rejects(fn () => new SiiDocument(str_replace('version: 1', 'version: 2', $sii)));
    rejects(fn () => (new SiiDocument($sii))->edit(['not-a-mod'], true));
    rejects(fn () => new SiiDocument($sii.'unexpected'));
    rejects(fn () => new SiiDocument(str_replace('checksum: 18446744073709551615', 'checksum: 18446744073709551616', $sii)));
});
test('CRLF, BOM, comments and no-mod packages', function () use ($sii) {
    $doc = new SiiDocument("\xEF\xBB\xBF".str_replace("\n", "\r\n", $sii));
    $out = $doc->edit([], true);
    check(str_contains($out, "\r\n"));
    check(str_starts_with($out, "\xEF\xBB\xBF"));
    check((new SiiDocument($out))->metadata()['mod_count'] === 0);
});
test('both files required, binary DAT preserved, limits enforced', function () use ($pair, $limits) {
    check($limits->validate($pair)['mod_count'] === 70);
    rejects(fn () => $limits->validate(['server_packages.sii' => $pair['server_packages.sii']]));
    rejects(fn () => $limits->validate(['server_packages.sii' => $pair['server_packages.sii'], 'server_packages.dat' => '']));
    rejects(fn () => (new PackageUpload(10, 10))->validate($pair));
});
function archive(array $entries, ?Closure $modify = null): string
{
    $path = tempnam(sys_get_temp_dir(), 'scs-test-');
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::OVERWRITE);
    foreach ($entries as $name => $bytes) {
        $zip->addFromString($name, $bytes);
    }
    if ($modify) {
        $modify($zip);
    }
    $zip->close();

    return $path;
}
test('ZIP root and subdirectory pairs, extras never extracted', function () use ($pair, $limits) {
    foreach ([$pair, ['folder/server_packages.sii' => $pair['server_packages.sii'], 'folder/server_packages.dat' => $pair['server_packages.dat'], 'README.txt' => 'ignored']] as $entries) {
        $path = archive($entries);
        try {
            $loaded = $limits->zip($path);
            check($loaded['server_packages.sii'] === $pair['server_packages.sii']);
            check($loaded['server_packages.dat']->bytes() === $pair['server_packages.dat']);
        } finally {
            unlink($path);
        }
    }
});
test('ZIP traversal, missing files, ambiguity, symlinks and limits rejected', function () use ($pair, $limits) {
    foreach ([['../evil' => 'bad'] + $pair, ['server_packages.sii' => $pair['server_packages.sii']],
        ['other/server_packages.sii' => $pair['server_packages.sii']] + $pair,
        ['folder/server_packages.sii' => $pair['server_packages.sii'], 'server_packages.dat' => $pair['server_packages.dat']]] as $entries) {
        $path = archive($entries);
        try {
            rejects(fn () => $limits->zip($path));
        } finally {
            unlink($path);
        }
    }
    $path = archive($pair, fn ($zip) => $zip->setExternalAttributesName('server_packages.dat', ZipArchive::OPSYS_UNIX, 0120777 << 16));
    try {
        rejects(fn () => $limits->zip($path));
    } finally {
        unlink($path);
    }
    $path = archive($pair);
    try {
        rejects(fn () => (new PackageUpload(16, 16))->zip($path));
    } finally {
        unlink($path);
    }
});
test('all Custom Buttons tokens, container prefix and unsafe paths', function () {
    $values = array_fill_keys(PathTemplate::PLACEHOLDERS, 'safe');
    check(PathTemplate::expand('/home/container/.local/share/Euro Truck Simulator 2', $values) === '/.local/share/Euro Truck Simulator 2');
    foreach (PathTemplate::PLACEHOLDERS as $token) {
        check(PathTemplate::expand('/packages/{{env.'.$token.'}}', $values) === '/packages/safe');
    }
    foreach (['../etc', '/a/../b', '/a/./b', '//host/path', '/.ets2-ats-mod-manager/presets', '/%2e%2e/etc', '/{{env.UNKNOWN}}', "/x\0"] as $path) {
        rejects(fn () => PathTemplate::expand($path, $values));
    }
    rejects(fn () => PathTemplate::expand('/{{env.P_SERVER_NAME}}', ['P_SERVER_NAME' => '../../escape']));
});
test('pair switch verifies backups and destination, keeps binary bytes', function () use ($pair, $limits) {
    $files = new MemoryFiles();
    $files->files = ['/target/server_packages.sii' => 'old-sii', '/target/server_packages.dat' => "old\x00dat"];
    $states = [];
    $tx = new PairTransaction($files, $limits);
    $journal = $tx->replace('test', '/target', $pair, function ($j, $s) use (&$states) {
        $states[] = $s;
    });
    check($states === ['preparing', 'prepared', 'swapping']);
    check($files->files['/target/server_packages.dat'] === $pair['server_packages.dat']);
    check($files->files[$journal['backup'].'/server_packages.sii'] === 'old-sii');
    $tx->rollback('test', $journal);
    check($files->files['/target/server_packages.dat'] === "old\x00dat");
});
test('failure on second rename rolls back both originals', function () use ($pair, $limits) {
    $files = new MemoryFiles();
    $files->files = ['/target/server_packages.sii' => 'old-sii', '/target/server_packages.dat' => 'old-dat'];
    $files->failRename = 2;
    $last = '';
    $persist = function ($j, $s) use (&$last) {
        $last = $s;
    };
    rejects(fn () => (new PairTransaction($files, $limits))->replace('failure', '/target', $pair, $persist));
    check($last === 'rolled_back');
    check($files->files['/target/server_packages.sii'] === 'old-sii');
    check($files->files['/target/server_packages.dat'] === 'old-dat');
});
test('failed rollback retains recoverable journal', function () use ($pair, $limits) {
    $files = new MemoryFiles();
    $files->files = ['/target/server_packages.sii' => 'old-sii', '/target/server_packages.dat' => 'old-dat'];
    $files->beforeRename = function ($f) {
        if ($f->renames >= 2) {
            $f->failAlways = true;
        }
    };
    $last = '';
    $journal = [];
    $persist = function ($j, $s) use (&$last, &$journal) {
        $last = $s;
        $journal = $j;
    };
    rejects(fn () => (new PairTransaction($files, $limits))->replace('recover', '/target', $pair, $persist));
    check($last === 'recovery_required');
    $files->failAlways = false;
    $files->beforeRename = null;
    (new PairTransaction($files, $limits))->rollback('recover', $journal);
    check($files->files['/target/server_packages.sii'] === 'old-sii');
});
test('missing original files are removed by rollback', function () use ($pair, $limits) {
    $files = new MemoryFiles();
    $tx = new PairTransaction($files, $limits);
    $journal = $tx->replace('new', '/target', $pair, fn ($j, $s) => null);
    $tx->rollback('new', $journal);
    check(! $files->exists('/target/server_packages.sii') && ! $files->exists('/target/server_packages.dat'));
});

test('unterminated block comments are rejected', function () use ($sii) {
    rejects(fn () => new SiiDocument($sii.'/*unfinished'));
});
test('rollback does not overwrite third-party changes', function () use ($pair, $limits) {
    $files = new MemoryFiles();
    $files->files = ['/target/server_packages.sii' => 'old-sii', '/target/server_packages.dat' => 'old-dat'];
    $tx = new PairTransaction($files, $limits);
    $journal = $tx->replace('external', '/target', $pair, fn ($j, $s) => null);
    $files->files['/target/server_packages.dat'] = 'external-edit';
    rejects(fn () => $tx->rollback('external', $journal));
    check($files->files['/target/server_packages.dat'] === 'external-edit');
});
test('hash identity does not depend on file selection order', function () use ($pair) {
    check(PairTransaction::hashes($pair) === PairTransaction::hashes(array_reverse($pair, true)));
});

test('existing files are moved into backups without uploading their bytes', function () use ($pair, $limits) {
    $files = new MemoryFiles();
    $files->files = ['/target/server_packages.sii' => 'old-sii', '/target/server_packages.dat' => 'old-dat'];
    $journal = (new PairTransaction($files, $limits))->replace('move', '/target', $pair, fn ($j, $s) => null);
    check($journal['backup_mode'] === 'move');
    check(count($files->writes) === 2);
    check($files->files[$journal['backup'].'/server_packages.dat'] === 'old-dat');
    check($files->renames === 4);
});
test('recovery restores originals when interrupted after moving a backup', function () use ($pair, $limits) {
    foreach ([false, true] as $secondGap) {
        $files = new MemoryFiles();
        $originals = ['server_packages.sii' => 'old-sii', 'server_packages.dat' => 'old-dat'];
        $backup = '/.ets2-ats-mod-manager/operations/gap';
        $journal = ['target' => '/target', 'backup' => $backup, 'backup_mode' => 'move',
            'originals' => PairTransaction::hashes($originals), 'expected' => PairTransaction::hashes($pair)];
        $files->files[$backup.'/server_packages.sii'] = 'old-sii';
        if ($secondGap) {
            $files->files['/target/server_packages.sii'] = $pair['server_packages.sii'];
            $files->files[$backup.'/server_packages.dat'] = 'old-dat';
        } else {
            $files->files['/target/server_packages.dat'] = 'old-dat';
        }
        (new PairTransaction($files, $limits))->rollback('gap', $journal);
        check($files->files['/target/server_packages.sii'] === 'old-sii');
        check($files->files['/target/server_packages.dat'] === 'old-dat');
        check($files->files[$backup.'/server_packages.sii'] === 'old-sii');
    }
});
test('legacy copy journals recover including an interrupted non-overwriting recovery', function () use ($pair, $limits) {
    $files = new MemoryFiles();
    $backup = '/.ets2-ats-mod-manager/operations/legacy';
    $originals = ['server_packages.sii' => 'old-sii', 'server_packages.dat' => 'old-dat'];
    $journal = ['target' => '/target', 'backup' => $backup,
        'originals' => PairTransaction::hashes($originals), 'expected' => array_reverse(PairTransaction::hashes($pair), true)];
    foreach ($pair as $name => $bytes) {
        $files->files['/target/'.$name] = $bytes;
        $files->files[$backup.'/'.$name] = $originals[$name];
    }
    $tx = new PairTransaction($files, $limits);
    $files->failRename = 2;
    rejects(fn () => $tx->rollback('legacy', $journal));
    $files->failRename = null;
    $tx->rollback('legacy', $journal);
    check($files->files['/target/server_packages.sii'] === 'old-sii');
    check($files->files['/target/server_packages.dat'] === 'old-dat');
});
test('a foreign file created during the move gap is preserved', function () use ($pair, $limits) {
    $files = new MemoryFiles();
    $files->files = ['/target/server_packages.sii' => 'old-sii', '/target/server_packages.dat' => 'old-dat'];
    $files->beforeRename = function ($f) {
        if ($f->renames === 2) {
            $f->files['/target/server_packages.sii'] = 'foreign-edit';
        }
    };
    $last = '';
    $persist = function ($j, $s) use (&$last) {
        $last = $s;
    };
    rejects(fn () => (new PairTransaction($files, $limits))->replace('foreign', '/target', $pair, $persist));
    check($last === 'recovery_required');
    check($files->files['/target/server_packages.sii'] === 'foreign-edit');
    check($files->files['/.ets2-ats-mod-manager/operations/foreign/server_packages.sii'] === 'old-sii');
});
test('all working file writes stay in the extension directory on success and failed swaps', function () use ($pair, $limits) {
    foreach ([null, 1, 2, 3, 4] as $failedRename) {
        $files = new MemoryFiles();
        $originals = ['/target/server_packages.sii' => 'old-sii', '/target/server_packages.dat' => 'old-dat'];
        $files->files = $originals;
        $files->failRename = $failedRename;
        $tx = new PairTransaction($files, $limits);
        if ($failedRename === null) {
            $journal = $tx->replace('workspace', '/target', $pair, fn ($j, $s) => null);
            check($files->files['/target/server_packages.dat'] === $pair['server_packages.dat']);
            $tx->rollback('workspace', $journal);
        } else {
            rejects(fn () => $tx->replace('workspace', '/target', $pair, fn ($j, $s) => null));
        }
        foreach ($originals as $path => $bytes) {
            check($files->files[$path] === $bytes);
        }
        foreach ($files->writes as $write) {
            check(str_starts_with($write['path'], '/.ets2-ats-mod-manager/operations/workspace/'));
        }
        foreach (array_keys($files->files) as $path) {
            check(! str_starts_with($path, '/target/') || array_key_exists($path, $originals));
        }
    }
});
test('interrupted older recoveries reuse their displaced files without creating working files in the game directory', function () use ($pair, $limits) {
    foreach (['ets2-ats-mm', 'scs'.'-mm'] as $prefix) {
        $files = new MemoryFiles();
        $backup = '/.ets2-ats-mod-manager/operations/older';
        $originals = ['server_packages.sii' => 'old-sii', 'server_packages.dat' => 'old-dat'];
        $journal = ['target' => '/target', 'backup' => $backup,
            'originals' => PairTransaction::hashes($originals), 'expected' => PairTransaction::hashes($pair)];
        $displaced = '/target/.'.$prefix.'-older-rollback-server_packages.sii';
        $files->files = [
            $displaced => $pair['server_packages.sii'],
            '/target/server_packages.dat' => $pair['server_packages.dat'],
            $backup.'/server_packages.sii' => 'old-sii',
            $backup.'/server_packages.dat' => 'old-dat',
        ];
        (new PairTransaction($files, $limits))->rollback('older', $journal);
        check($files->files['/target/server_packages.sii'] === 'old-sii');
        check($files->files['/target/server_packages.dat'] === 'old-dat');
        check($files->files[$displaced] === $pair['server_packages.sii']);
        foreach ($files->writes as $write) {
            check(str_starts_with($write['path'], $backup.'/'));
        }
    }
});
test('JSON digest comparisons ignore order and strictly reject changed values', function () use ($pair) {
    $hashes = PairTransaction::hashes($pair);
    check(PairTransaction::hashesMatch($hashes, array_reverse($hashes, true)));
    check(! PairTransaction::hashesMatch($hashes, ['server_packages.sii' => 0, 'server_packages.dat' => $hashes['server_packages.dat']]));
    check(! PairTransaction::hashesMatch($hashes, $hashes + ['extra' => 'bad']));
    $changed = $hashes;
    $changed['server_packages.dat'] = hash('sha256', 'changed');
    check(! PairTransaction::hashesMatch($hashes, $changed));
});

test('mod boundaries accept 99, 100 and 120 and reject 121 or more', function () use ($limits) {
    foreach ([0, 99, 100, 120] as $count) {
        check($limits->validate(['server_packages.sii' => packageSii($count), 'server_packages.dat' => 'dat'])['mod_count'] === $count);
    }
    foreach ([121, 10000] as $count) {
        rejects(fn () => $limits->validate(['server_packages.sii' => packageSii($count), 'server_packages.dat' => 'dat']));
    }
});
test('120-mod edits preserve unknown spans, BOM and line endings', function () {
    $sii = "\xEF\xBB\xBF".str_replace("\n", "\r\n", str_replace('map_name: europe', 'map_name: europe /* retain */'."\n".'future_field: "retain { text }: exactly"', packageSii(120)));
    $doc = new SiiDocument($sii);
    $ids = array_column($doc->metadata()['mods'], 'id');
    $out = $doc->edit(array_reverse($ids), false);
    check(str_contains($out, 'future_field: "retain { text }: exactly"'));
    check(str_contains($out, '/* retain */') && str_starts_with($out, "\xEF\xBB\xBF") && str_contains($out, "\r\n"));
    check(array_column((new SiiDocument($out))->metadata()['mods'], 'id') === array_reverse($ids));
});
test('streamed DAT files are binary-safe, bounded and temporary', function () use ($pair, $limits) {
    $blob = PackageFile::fromString($pair['server_packages.dat']);
    $path = $blob->path();
    check($blob->bytes() === $pair['server_packages.dat']);
    check($blob->hash === hash('sha256', $pair['server_packages.dat']));
    check($blob->size === strlen($pair['server_packages.dat']));
    check($limits->validate(['server_packages.sii' => $pair['server_packages.sii'], 'server_packages.dat' => $blob])['mod_count'] === 70);
    rejects(fn () => PackageFile::fromPath($path, $blob->size - 1));
    check(PackageFile::fromPath($path, $blob->size)->hash === $blob->hash);
    unset($blob);
    check(! is_file($path));
});
test('streamed pair swaps and rollbacks retain exactly the original binary files', function () use ($pair, $limits) {
    $files = new MemoryFiles();
    $files->files = ['/target/server_packages.sii' => 'old-sii', '/target/server_packages.dat' => "old\0\xffdat"];
    $streamed = $pair;
    $streamed['server_packages.dat'] = PackageFile::fromString($pair['server_packages.dat']);
    $tx = new PairTransaction($files, $limits);
    $journal = $tx->replace('stream', '/target', $streamed, fn () => null);
    check($files->files['/target/server_packages.dat'] === $pair['server_packages.dat']);
    $tx->rollback('stream', $journal);
    check($files->files['/target/server_packages.dat'] === "old\0\xffdat");
});
test('reused original hashes still detect edits made before a swap', function () use ($pair, $limits) {
    $files = new MemoryFiles();
    foreach ($pair as $name => $bytes) {
        $files->files['/target/'.$name] = $bytes;
    }
    $expected = PairTransaction::hashes($pair);
    $files->files['/target/server_packages.dat'] = 'foreign-change';
    rejects(fn () => (new PairTransaction($files, $limits))->replace('snapshot', '/target', $pair, fn () => null, $expected));
    check($files->files['/target/server_packages.dat'] === 'foreign-change');
});

echo $passed.' PHP tests passed'.PHP_EOL;
