<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Enum\ProductOperation;
use App\Message\ProductNotification;
use App\MessageHandler\SendProductNotificationHandler;
use App\Notification\NotificationChannelInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SendProductNotificationHandlerTest extends TestCase
{
    public function testSendsToEverySupportingChannel(): void
    {
        $sent = [];
        $first = $this->recordingChannel('first', $sent);
        $second = $this->recordingChannel('second', $sent);
        $notification = $this->notification();

        $this->handler([$first, $second])($notification);

        self::assertSame([['first', $notification], ['second', $notification]], $sent);
    }

    public function testSkipsChannelThatDoesNotSupportNotification(): void
    {
        $sent = [];
        $unsupported = $this->recordingChannel('unsupported', $sent, supports: false);
        $supported = $this->recordingChannel('supported', $sent);

        $this->handler([$unsupported, $supported])($this->notification());

        self::assertSame(['supported'], array_column($sent, 0));
    }

    public function testFailingChannelDoesNotStopOthers(): void
    {
        $sent = [];
        $failing = $this->failingChannel(new \RuntimeException('SMTP is down'));
        $working = $this->recordingChannel('working', $sent);
        $logger = $this->createStub(LoggerInterface::class);

        (new SendProductNotificationHandler([$failing, $working], $logger))($this->notification());

        self::assertSame(['working'], array_column($sent, 0));
    }

    public function testLogsChannelFailure(): void
    {
        $exception = new \RuntimeException('SMTP is down');
        $failing = $this->failingChannel($exception);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(self::isString(), [
                'channel' => $failing::class,
                'product_id' => 12,
                'operation' => 'updated',
                'exception' => $exception,
            ]);

        (new SendProductNotificationHandler([$failing], $logger))($this->notification(ProductOperation::Updated));
    }

    public function testWithoutChannelsDoesNothing(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method(self::anything());

        (new SendProductNotificationHandler([], $logger))($this->notification());
    }

    /**
     * @param iterable<NotificationChannelInterface> $channels
     */
    private function handler(iterable $channels): SendProductNotificationHandler
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        return new SendProductNotificationHandler($channels, $logger);
    }

    /**
     * @param list<array{string, ProductNotification}> $sent
     */
    private function recordingChannel(string $name, array &$sent, bool $supports = true): NotificationChannelInterface
    {
        return new class($name, $sent, $supports) implements NotificationChannelInterface {
            public function __construct(private string $name, private array &$sent, private bool $supports)
            {
            }

            public function supports(ProductNotification $notification): bool
            {
                return $this->supports;
            }

            public function send(ProductNotification $notification): void
            {
                $this->sent[] = [$this->name, $notification];
            }
        };
    }

    private function failingChannel(\Throwable $exception): NotificationChannelInterface
    {
        return new class($exception) implements NotificationChannelInterface {
            public function __construct(private \Throwable $exception)
            {
            }

            public function supports(ProductNotification $notification): bool
            {
                return true;
            }

            public function send(ProductNotification $notification): void
            {
                throw $this->exception;
            }
        };
    }

    private function notification(ProductOperation $operation = ProductOperation::Created): ProductNotification
    {
        return new ProductNotification($operation, 12, 'Kubek', '19.99', ['MUGS'], new \DateTimeImmutable('2026-10-08 12:00:00'));
    }
}
