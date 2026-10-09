<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;

final class ApiAuthenticationTest extends ApiTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function protectedRequests(): iterable
    {
        yield 'entrypoint' => ['GET', '/api'];
        yield 'product list' => ['GET', '/api/products'];
        yield 'product create' => ['POST', '/api/products'];
        yield 'category list' => ['GET', '/api/categories'];
        yield 'category create' => ['POST', '/api/categories'];
        yield 'operation log' => ['GET', '/api/operation_logs'];
    }

    #[DataProvider('protectedRequests')]
    public function testRequestWithoutTokenIsRejected(string $method, string $uri): void
    {
        static::createApiClient()->request($method, $uri, ['json' => []]);

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('www-authenticate', 'Bearer');
    }

    public function testWrongTokenIsRejected(): void
    {
        $response = static::createApiClient(['auth_bearer' => 'wrong'])
            ->request('POST', '/api/categories', ['json' => ['code' => 'MUGS']]);

        self::assertResponseStatusCodeSame(401);
        self::assertStringStartsWith('Bearer ', $response->getHeaders(false)['www-authenticate'][0]);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM category'));
    }

    public function testValidTokenIsAccepted(): void
    {
        static::createApiClient(['auth_bearer' => $_SERVER['API_TOKEN']])->request('GET', '/api/products');

        self::assertResponseIsSuccessful();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documentation(): iterable
    {
        yield 'Swagger UI' => ['/api/docs', 'text/html'];
        yield 'OpenAPI' => ['/api/docs.jsonopenapi', 'application/vnd.openapi+json'];
    }

    #[DataProvider('documentation')]
    public function testDocumentationIsPublic(string $uri, string $accept): void
    {
        static::createApiClient(['headers' => ['Accept' => $accept]])->request('GET', $uri);

        self::assertResponseIsSuccessful();
    }
}
