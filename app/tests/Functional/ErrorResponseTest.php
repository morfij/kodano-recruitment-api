<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Runs without debug, as a production instance does, and checks that error
 * responses carry no stack trace or database details.
 */
final class ErrorResponseTest extends ApiTestCase
{
    protected function setUp(): void
    {
        // createClient() ignores kernel options unless $alwaysBootKernel is set.
        static::bootKernel(['debug' => false]);
        parent::setUp();
    }

    public function testNotFound(): void
    {
        $this->assertCleanError($this->client->request('GET', '/api/products/999999'), 404);
    }

    public function testMalformedJson(): void
    {
        $response = $this->client->request('POST', '/api/products', [
            'body' => '{"name":',
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $this->assertCleanError($response, 400);
    }

    public function testUnknownCategory(): void
    {
        $response = $this->client->request('POST', '/api/products', ['json' => [
            'name' => 'Kubek',
            'price' => '19.99',
            'categories' => [999999],
        ]]);

        $this->assertCleanError($response, 422);
    }

    public function testCategoryInUse(): void
    {
        $category = $this->createCategory('MUGS');
        $this->createProduct('Kubek', '19.99', [$category]);

        $this->assertCleanError($this->client->request('DELETE', '/api/categories/'.$category), 409);
    }

    public function testDuplicateCategoryCodeKeepsTheViolation(): void
    {
        $this->client->request('POST', '/api/categories', ['json' => ['code' => 'MUGS']]);

        $response = $this->client->request('POST', '/api/categories', ['json' => ['code' => 'MUGS']]);

        $this->assertCleanError($response, 422);
        self::assertSame('code', $response->toArray(false)['violations'][0]['propertyPath']);
    }

    private function assertCleanError(ResponseInterface $response, int $status): void
    {
        self::assertSame($status, $response->getStatusCode());

        $body = $response->getContent(false);
        self::assertArrayNotHasKey('trace', $response->toArray(false));
        foreach (['SQLSTATE', '/var/www', '#0 '] as $leak) {
            self::assertStringNotContainsString($leak, $body);
        }
    }
}
