<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use App\Enum\ProductOperation;
use App\Message\ProductNotification;
use App\Notification\EmailNotificationChannel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

final class EmailNotificationChannelTest extends TestCase
{
    private const FROM = 'Kodano API <noreply@example.com>';
    private const TO = 'catalog@example.com';

    public function testSupportsEveryNotification(): void
    {
        $channel = new EmailNotificationChannel($this->createStub(MailerInterface::class), self::FROM, self::TO);

        self::assertTrue($channel->supports($this->notification(ProductOperation::Deleted)));
    }

    /**
     * @return iterable<string, array{ProductOperation, string}>
     */
    public static function operations(): iterable
    {
        yield 'created' => [ProductOperation::Created, 'Product created: Kubek (#12)'];
        yield 'updated' => [ProductOperation::Updated, 'Product updated: Kubek (#12)'];
        yield 'deleted' => [ProductOperation::Deleted, 'Product deleted: Kubek (#12)'];
    }

    #[DataProvider('operations')]
    public function testSendsTemplatedEmail(ProductOperation $operation, string $subject): void
    {
        $notification = $this->notification($operation);
        $sent = null;

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())
            ->method('send')
            ->with(self::callback(static function (TemplatedEmail $email) use (&$sent): bool {
                $sent = $email;

                return true;
            }));

        (new EmailNotificationChannel($mailer, self::FROM, self::TO))->send($notification);

        self::assertInstanceOf(TemplatedEmail::class, $sent);
        self::assertEquals([Address::create(self::FROM)], $sent->getFrom());
        self::assertEquals([new Address(self::TO)], $sent->getTo());
        self::assertSame($subject, $sent->getSubject());
        self::assertSame('email/product_notification.html.twig', $sent->getHtmlTemplate());
        self::assertSame('email/product_notification.txt.twig', $sent->getTextTemplate());
        self::assertSame(['notification' => $notification], $sent->getContext());
    }

    public function testMailerFailureIsNotSwallowed(): void
    {
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willThrowException(new TransportException('Connection refused'));

        $this->expectException(TransportException::class);

        (new EmailNotificationChannel($mailer, self::FROM, self::TO))->send($this->notification(ProductOperation::Created));
    }

    private function notification(ProductOperation $operation): ProductNotification
    {
        return new ProductNotification($operation, 12, 'Kubek', '19.99', ['MUGS', 'CUPS'], new \DateTimeImmutable('2026-10-08 12:00:00'));
    }
}
