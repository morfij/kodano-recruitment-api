<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Message\ProductNotification;
use App\Notification\NotificationChannelInterface;

/**
 * Extra channel that is ignored until a test enables it, then always fails.
 */
final class FailingNotificationChannel implements NotificationChannelInterface
{
    private bool $enabled = false;

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function supports(ProductNotification $notification): bool
    {
        return $this->enabled;
    }

    public function send(ProductNotification $notification): void
    {
        throw new \RuntimeException('Channel is down.');
    }
}
