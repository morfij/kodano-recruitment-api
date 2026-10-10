<?php

declare(strict_types=1);

/*
 * Child process for ApiWriteRateLimitTest: consumes one write from a limiter that
 * uses the configured lock store and a storage shared through the given directory.
 * Prints "ready" once booted, waits for a line on stdin, then prints 1 when the
 * write was accepted.
 */

use App\Kernel;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require dirname(__DIR__).'/bootstrap.php';

$storageDir = $argv[1];

$kernel = new Kernel('test', false);
$kernel->boot();
$lockFactory = $kernel->getContainer()->get('test.service_container')->get('lock.factory');

$limiter = (new RateLimiterFactory(
    ['id' => 'api_write', 'policy' => 'sliding_window', 'limit' => 1, 'interval' => '1 minute'],
    new CacheStorage(new FilesystemAdapter(directory: $storageDir)),
    $lockFactory,
))->create('203.0.113.1');

echo "ready\n";
fgets(\STDIN);

echo $limiter->consume()->isAccepted() ? '1' : '0';
