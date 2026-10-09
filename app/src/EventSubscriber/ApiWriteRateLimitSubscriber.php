<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Limits all API writes per client IP. Product changes are the expensive ones (log
 * entry and e-mail), but category writes and attempts with a wrong token count too.
 * Runs after routing, so the error is rendered by API Platform, and before the firewall.
 */
final class ApiWriteRateLimitSubscriber implements EventSubscriberInterface
{
    private const array WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        #[Autowire(service: 'limiter.api_write')]
        private readonly RateLimiterFactoryInterface $apiWriteLimiter,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 16]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest()
            || !\in_array($request->getMethod(), self::WRITE_METHODS, true)
            || !str_starts_with($request->getPathInfo(), '/api/')
        ) {
            return;
        }

        $limit = $this->apiWriteLimiter->create($request->getClientIp())->consume();

        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - time()), 'Too many write requests. Try again later.');
        }
    }
}
