<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;

/**
 * @phpstan-type Worker array{process: resource, pipes: array<int, resource>}
 */
final class ApiWriteRateLimitTest extends KernelTestCase
{
    private const int PROCESSES = 8;
    private const int TIMEOUT = 30;

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
        $closed = [];

        try {
            for ($i = 0; $i < self::PROCESSES; ++$i) {
                $process = proc_open([\PHP_BINARY, $script, $storageDir], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                $workers[] = ['process' => $process, 'pipes' => $pipes];
            }

            $ready = $this->readOutputs($workers, 'start', static fn (string $output, bool $ended): bool => $ended || str_contains($output, "\n"));
            foreach ($ready as $i => $output) {
                self::assertSame("ready\n", $output, \sprintf('Worker %d did not start: %s', $i, $this->errors($workers)));
            }

            foreach ($workers as $worker) {
                fwrite($worker['pipes'][0], "go\n");
            }
            $results = $this->readOutputs($workers, 'consume', static fn (string $output, bool $ended): bool => $ended);

            foreach ($workers as $i => $worker) {
                $errors = $this->errors([$worker]);
                $exitCode = $this->close($worker);
                $closed[$i] = true;
                self::assertSame(0, $exitCode, \sprintf('Worker %d failed: %s', $i, $errors));
                self::assertContains($results[$i], ['0', '1'], \sprintf('Worker %d printed an unexpected result.', $i));
            }

            self::assertSame(1, array_sum(array_map(intval(...), $results)));
        } finally {
            foreach ($workers as $i => $worker) {
                if (!isset($closed[$i])) {
                    proc_terminate($worker['process']);
                    $this->close($worker);
                }
            }
            (new Filesystem())->remove($storageDir);
        }
    }

    /**
     * Reads every worker's stdout until $done accepts it, failing after TIMEOUT seconds.
     *
     * @param list<Worker>                 $workers
     * @param callable(string, bool): bool $done    gets the output so far and whether stdout has ended
     *
     * @return array<int, string>
     */
    private function readOutputs(array $workers, string $stage, callable $done): array
    {
        $outputs = array_fill(0, \count($workers), '');
        $pending = [];
        foreach ($workers as $i => $worker) {
            stream_set_blocking($worker['pipes'][1], false);
            $pending[$i] = $worker['pipes'][1];
        }
        $deadline = microtime(true) + self::TIMEOUT;

        while ([] !== $pending) {
            $timeLeft = $deadline - microtime(true);
            if ($timeLeft <= 0) {
                self::fail(\sprintf('%d worker(s) stuck in the %s step after %d s: %s', \count($pending), $stage, self::TIMEOUT, $this->errors($workers)));
            }

            $read = array_values($pending);
            $write = $except = null;
            if (!stream_select($read, $write, $except, (int) $timeLeft, (int) (fmod($timeLeft, 1) * 1_000_000))) {
                continue;
            }

            foreach ($read as $stream) {
                $i = array_search($stream, $pending, true);
                \assert(\is_int($i));
                $outputs[$i] .= (string) fread($stream, 8192);
                if ($done($outputs[$i], feof($stream))) {
                    unset($pending[$i]);
                }
            }
        }

        return $outputs;
    }

    /**
     * @param list<Worker> $workers
     */
    private function errors(array $workers): string
    {
        $errors = '';
        foreach ($workers as $worker) {
            stream_set_blocking($worker['pipes'][2], false);
            $errors .= stream_get_contents($worker['pipes'][2]);
        }

        return $errors;
    }

    /**
     * @param Worker $worker
     */
    private function close(array $worker): int
    {
        foreach ($worker['pipes'] as $pipe) {
            fclose($pipe);
        }

        return proc_close($worker['process']);
    }
}
