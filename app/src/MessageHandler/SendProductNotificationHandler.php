<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ProductNotification;
use App\Notification\NotificationChannelInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SendProductNotificationHandler
{
    /**
     * @param iterable<NotificationChannelInterface> $channels
     */
    public function __construct(
        #[AutowireIterator(NotificationChannelInterface::class)]
        private readonly iterable $channels,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProductNotification $notification): void
    {
        foreach ($this->channels as $channel) {
            // The change is already committed; one failing channel must not stop the others.
            try {
                if ($channel->supports($notification)) {
                    $channel->send($notification);
                }
            } catch (\Throwable $e) {
                $this->logger->error('Product notification channel failed.', [
                    'channel' => $channel::class,
                    'product_id' => $notification->productId,
                    'operation' => $notification->operation->value,
                    'exception' => $e,
                ]);
            }
        }
    }
}
