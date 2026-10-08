<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use PHPUnit\Framework\Attributes\DataProvider;

final class CategoryApiTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = false;

    private Client $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testCreate(): void
    {
        $response = $this->client->request('POST', '/api/categories', ['json' => ['code' => 'MUGS']]);

        self::assertResponseStatusCodeSame(201);
        self::assertJsonContains(['@type' => 'Category', 'code' => 'MUGS']);
        $data = $response->toArray();
        self::assertNotEmpty($data['createdAt']);
        self::assertSame($data['createdAt'], $data['updatedAt']);
    }

    public function testGetItemAndCollection(): void
    {
        $iri = $this->createCategory('MUGS');
        $this->createCategory('CUPS');

        $this->client->request('GET', $iri);
        self::assertResponseIsSuccessful();
        self::assertJsonContains(['@id' => $iri, 'code' => 'MUGS']);

        $collection = $this->client->request('GET', '/api/categories')->toArray();
        self::assertSame(2, $collection['totalItems']);
        self::assertSame(['MUGS', 'CUPS'], array_column($collection['member'], 'code'));
    }

    public static function invalidCodes(): iterable
    {
        yield 'too long' => ['ABCDEFGHIJK'];
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
    }

    #[DataProvider('invalidCodes')]
    public function testInvalidCodeIsRejected(string $code): void
    {
        $this->client->request('POST', '/api/categories', ['json' => ['code' => $code]]);

        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['violations' => [['propertyPath' => 'code']]]);
    }

    public function testDuplicateCodeIsRejected(): void
    {
        $this->createCategory('MUGS');

        $this->client->request('POST', '/api/categories', ['json' => ['code' => 'MUGS']]);

        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['violations' => [['propertyPath' => 'code']]]);
    }

    public static function closedMethods(): iterable
    {
        yield ['PATCH'];
        yield ['PUT'];
        yield ['DELETE'];
    }

    #[DataProvider('closedMethods')]
    public function testCategoryCannotBeChangedOrDeleted(string $method): void
    {
        $iri = $this->createCategory('MUGS');

        $this->client->request($method, $iri, [
            'json' => ['code' => 'CUPS'],
            'headers' => ['Content-Type' => 'PATCH' === $method ? 'application/merge-patch+json' : 'application/ld+json'],
        ]);

        self::assertResponseStatusCodeSame(405);
    }

    private function createCategory(string $code): string
    {
        return $this->client->request('POST', '/api/categories', ['json' => ['code' => $code]])->toArray()['@id'];
    }
}
