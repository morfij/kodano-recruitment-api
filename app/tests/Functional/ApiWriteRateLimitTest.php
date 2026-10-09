<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;

final class ApiWriteRateLimitTest extends KernelTestCase
{
    private const int PROCESSES = 8;

    public function testLimiterUsesTheLockStore(): void
    {
        $factory = static::getContainer()->get('limiter.api_write');

        self::assertInstanceOf(LockFactory::class, (new \ReflectionProperty($factory, 'lockFactory'))->getValue($factory));
    }

    /**
     * Without a lock, workers that read the same counter can all pass a limit of one.
     */
    public function testConcurrentWorkersCannotExceedTheLimit(): void
    {
        $storageDir = sys_get_temp_dir().'/api_write_limit_'.bin2hex(random_bytes(4));
        $script = \dirname(__DIR__).'/Support/consume_api_write_limit.php';
        $startAt = (string) (microtime(true) + 3);

        $processes = [];
        for ($i = 0; $i < self::PROCESSES; ++$i) {
            $process = proc_open([\PHP_BINARY, $script, $storageDir, $startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $processes[] = [$process, $pipes];
        }

        $accepted = 0;
        foreach ($processes as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), (string) $errors);
            $accepted += (int) $output;
        }
        (new Filesystem())->remove($storageDir);

        self::assertSame(1, $accepted);
    }
}
