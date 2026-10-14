<?php

// A separate DB session represents another panel worker reading the same server.
[$script, $configuration, $key] = $argv;
$db = json_decode($configuration, true, flags: JSON_THROW_ON_ERROR);
if (! str_contains($db['database'], 'test')) {
    throw new RuntimeException('Lock worker must use a test database.');
}
$dsn = ! empty($db['unix_socket'])
    ? 'mysql:unix_socket='.$db['unix_socket'].';dbname='.$db['database']
    : 'mysql:host='.$db['host'].';port='.$db['port'].';dbname='.$db['database'];
$pdo = new PDO($dsn, $db['username'], $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$lock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
$lock->execute([$key]);
if ((int) $lock->fetchColumn() !== 1) {
    throw new RuntimeException('Test worker could not acquire the server lock.');
}
echo "LOCKED\n";
flush();
usleep(500000);
$release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
$release->execute([$key]);
