<?php

class Notifications
{
    public static $command = 'notifications:run';
    public static $description = 'Check severe weather and send queued email notifications';

    public function handle($input = null, array $flags = [])
    {
        $worker = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'notifications.php';
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($worker);
        passthru($command, $exitCode);
        exit($exitCode);
    }
}
