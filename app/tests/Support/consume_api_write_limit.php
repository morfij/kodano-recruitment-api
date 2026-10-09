<?php

declare(strict_types=1);

/*
 * Child process for ApiWriteRateLimitTest: consumes one write from a limiter that
 * uses the configured lock store and a storage shared through the given directory.
 * Prints 1 when the write was accepted.
 */

use App\Kernel;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require dirname(__DIR__).'/bootstrap.php';

[, $storageDir, $startAt] = $argv;

$kernel = new Kernel('test', false);
$kernel->boot();
$lockFactory = $kernel->getContainer()->get('test.service_container')->get('lock.factory');

$limiter = (new RateLimiterFactory(
    ['id' => 'api_write', 'policy' => 'sliding_window', 'limit' => 1, 'interval' => '1 minute'],
    new CacheStorage(new FilesystemAdapter(directory: $storageDir)),
    $lockFactory,
))->create('203.0.113.1');

// Start all processes at the same moment so their read-check-write steps overlap.
time_sleep_until((float) $startAt);

echo $limiter->consume()->isAccepted() ? '1' : '0';
