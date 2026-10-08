<?php

declare(strict_types=1);

namespace App\Notification;

use App\Message\ProductNotification;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;

final class EmailNotificationChannel implements NotificationChannelInterface
{
    public function __construct(
        private readonly MailerInterface $mailer,
        #[Autowire(env: 'MAILER_FROM')]
        private readonly string $from,
        #[Autowire(env: 'MAILER_TO')]
        private readonly string $to,
    ) {
    }

    public function supports(ProductNotification $notification): bool
    {
        return true;
    }

    public function send(ProductNotification $notification): void
    {
        $email = (new TemplatedEmail())
            ->from($this->from)
            ->to($this->to)
            ->subject(sprintf('Product %s: %s (#%d)', $notification->operation->value, $notification->name, $notification->productId))
            ->textTemplate('email/product_notification.txt.twig')
            ->htmlTemplate('email/product_notification.html.twig')
            ->context(['notification' => $notification]);

        $this->mailer->send($email);
    }
}
