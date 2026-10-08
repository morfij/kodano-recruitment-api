<?php

declare(strict_types=1);

namespace App\Notification;

use App\Message\ProductNotification;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A way to tell someone about a product change. Implementations are picked up
 * automatically; adding a channel (Slack, SMS) means adding a class.
 */
#[AutoconfigureTag]
interface NotificationChannelInterface
{
    public function supports(ProductNotification $notification): bool;

    public function send(ProductNotification $notification): void;
}
