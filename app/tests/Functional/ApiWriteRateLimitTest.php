<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;

final class ApiWriteRateLimitTest extends KernelTestCase
{
    private const int PROCESSES = 8;
    private const int READY_TIMEOUT = 30;

    public function testLimiterUsesTheLockStore(): void
    {
        $factory = static::getContainer()->get('limiter.api_write');

        self::assertInstanceOf(LockFactory::class, (new \ReflectionProperty($factory, 'lockFactory'))->getValue($factory));
    }

    /**
     * Without a lock, workers that read the same counter can all pass a limit of one.
     * The workers boot first and are released together, so their consume() calls overlap.
     */
    public function testConcurrentWorkersCannotExceedTheLimit(): void
    {
        $storageDir = sys_get_temp_dir().'/api_write_limit_'.bin2hex(random_bytes(4));
        $script = \dirname(__DIR__).'/Support/consume_api_write_limit.php';
        $workers = [];

        try {
            for ($i = 0; $i < self::PROCESSES; ++$i) {
                $process = proc_open([\PHP_BINARY, $script, $storageDir], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                $workers[] = ['process' => $process, 'pipes' => $pipes];
            }

            $this->waitUntilReady($workers);
            foreach ($workers as $worker) {
                fwrite($worker['pipes'][0], "go\n");
            }

            $accepted = 0;
            foreach ($workers as $worker) {
                $accepted += (int) stream_get_contents($worker['pipes'][1]);
            }

            self::assertSame(1, $accepted);
        } finally {
            foreach ($workers as $worker) {
                foreach ($worker['pipes'] as $pipe) {
                    fclose($pipe);
                }
                proc_terminate($worker['process']);
                proc_close($worker['process']);
            }
            (new Filesystem())->remove($storageDir);
        }
    }

    /**
     * @param list<array{process: resource, pipes: array<int, resource>}> $workers
     */
    private function waitUntilReady(array $workers): void
    {
        $waiting = array_map(static fn (array $worker) => $worker['pipes'][1], $workers);
        $deadline = time() + self::READY_TIMEOUT;

        while ([] !== $waiting) {
            $timeLeft = $deadline - time();
            if ($timeLeft <= 0) {
                self::fail(\sprintf('%d worker(s) not ready after %d s: %s', \count($waiting), self::READY_TIMEOUT, $this->workerErrors($workers)));
            }

            $read = array_values($waiting);
            $write = $except = null;
            if (!stream_select($read, $write, $except, $timeLeft)) {
                continue;
            }

            foreach ($read as $stream) {
                self::assertSame("ready\n", fgets($stream), 'Unexpected worker output: '.$this->workerErrors($workers));
                unset($waiting[array_search($stream, $waiting, true)]);
            }
        }
    }

    /**
     * @param list<array{process: resource, pipes: array<int, resource>}> $workers
     */
    private function workerErrors(array $workers): string
    {
        $errors = '';
        foreach ($workers as $worker) {
            stream_set_blocking($worker['pipes'][2], false);
            $errors .= stream_get_contents($worker['pipes'][2]);
        }

        return $errors;
    }
}
