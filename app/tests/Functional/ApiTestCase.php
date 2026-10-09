<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Test\ApiTestCase as BaseApiTestCase;
use ApiPlatform\Test\Client;
use Doctrine\DBAL\Connection;

/**
 * Client and helpers shared by the API tests. Data is created through the API.
 */
abstract class ApiTestCase extends BaseApiTestCase
{
    protected static ?bool $alwaysBootKernel = false;

    protected Client $client;

    protected function setUp(): void
    {
        $this->client = static::createApiClient(['auth_bearer' => $_SERVER['API_TOKEN']]);
    }

    /**
     * The test client asks for JSON-LD unless told otherwise.
     *
     * @param array<string, mixed> $options
     */
    protected static function createApiClient(array $options = []): Client
    {
        return static::createClient([], $options + ['headers' => ['Accept' => 'application/json']]);
    }

    protected function createCategory(string $code): int
    {
        return $this->client->request('POST', '/api/categories', ['json' => ['code' => $code]])->toArray()['id'];
    }

    /**
     * @param list<int> $categories
     */
    protected function createProduct(string $name, string $price, array $categories): int
    {
        return $this->client->request('POST', '/api/products', ['json' => [
            'name' => $name,
            'price' => $price,
            'categories' => $categories,
        ]])->toArray()['id'];
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function patch(string $uri, array $data): void
    {
        $this->client->request('PATCH', $uri, [
            'json' => $data,
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
    }

    protected function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }
}
