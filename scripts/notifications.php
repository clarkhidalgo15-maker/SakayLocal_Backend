<?php

$publicEntry = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php';
$_SERVER['REQUEST_URI'] = '/api/notifications/run';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $publicEntry;
$argv[1] = 'api/notifications/run';
require $publicEntry;
