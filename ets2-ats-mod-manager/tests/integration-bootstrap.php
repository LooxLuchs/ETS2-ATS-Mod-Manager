<?php

$panel = getenv('PANEL_PATH');
if (! $panel || ! is_file($panel.'/vendor/autoload.php')) {
    throw new RuntimeException('Set PANEL_PATH to the prepared v2 panel checkout.');
}
if (! str_contains(getenv('DB_DATABASE') ?: '', 'test')) {
    throw new RuntimeException('Integration tests require a database whose name contains test.');
}
require $panel.'/vendor/autoload.php';
require __DIR__.'/bootstrap.php';
