<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\OperationLog;

/**
 * Lets a test make the next operation log insert fail inside the processor's transaction.
 */
final class FailingOperationLogListener
{
    private bool $failNext = false;

    public function failNextPersist(): void
    {
        $this->failNext = true;
    }

    public function prePersist(OperationLog $log): void
    {
        if ($this->failNext) {
            $this->failNext = false;

            throw new \RuntimeException('Operation log is not available.');
        }
    }
}
