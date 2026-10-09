<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\ApiWriteRateLimitSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class ApiWriteRateLimitSubscriberTest extends TestCase
{
    private ApiWriteRateLimitSubscriber $subscriber;

    protected function setUp(): void
    {
        $factory = new RateLimiterFactory(['id' => 'api_write', 'policy' => 'sliding_window', 'limit' => 2, 'interval' => '1 minute'], new InMemoryStorage());
        $this->subscriber = new ApiWriteRateLimitSubscriber($factory);
    }

    public function testRejectsWritesOverTheLimit(): void
    {
        $this->write('POST', '/api/products');
        $this->write('PATCH', '/api/products/1');

        try {
            $this->write('DELETE', '/api/products/1');
            self::fail('The third write must be rejected.');
        } catch (TooManyRequestsHttpException $e) {
            self::assertSame(429, $e->getStatusCode());
            self::assertGreaterThan(0, (int) $e->getHeaders()['Retry-After']);
        }
    }

    public function testReadsAreNotCounted(): void
    {
        foreach (range(1, 5) as $i) {
            $this->write('GET', '/api/products');
        }
        $this->write('POST', '/api/products');
        $this->write('POST', '/api/products');

        $this->expectException(TooManyRequestsHttpException::class);
        $this->write('POST', '/api/products');
    }

    public function testPathsOutsideTheApiAreNotCounted(): void
    {
        foreach (range(1, 5) as $i) {
            $this->write('POST', '/login');
        }

        $this->expectNotToPerformAssertions();
    }

    public function testClientsAreCountedSeparately(): void
    {
        $this->write('POST', '/api/products', '10.0.0.1');
        $this->write('POST', '/api/products', '10.0.0.1');
        $this->write('POST', '/api/products', '10.0.0.2');

        $this->expectException(TooManyRequestsHttpException::class);
        $this->write('POST', '/api/products', '10.0.0.1');
    }

    private function write(string $method, string $path, string $ip = '127.0.0.1'): void
    {
        $request = Request::create($path, $method, server: ['REMOTE_ADDR' => $ip]);
        $kernel = $this->createStub(HttpKernelInterface::class);

        $this->subscriber->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
    }
}
