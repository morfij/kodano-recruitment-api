<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;

final class CategoryApiTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = false;

    private Client $client;

    protected function setUp(): void
    {
        $this->client = static::createClient([], ['auth_bearer' => $_SERVER['API_TOKEN']]);
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

    /**
     * @return iterable<string, array{string}>
     */
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

    public function testUpdateCode(): void
    {
        $iri = $this->createCategory('MUGS');
        $createdAt = $this->client->request('GET', $iri)->toArray()['createdAt'];

        $this->patch($iri, ['code' => 'CUPS']);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['@id' => $iri, 'code' => 'CUPS', 'createdAt' => $createdAt]);
    }

    public function testUpdateKeepsOwnCode(): void
    {
        $iri = $this->createCategory('MUGS');

        $this->patch($iri, ['code' => 'MUGS']);

        self::assertResponseIsSuccessful();
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function invalidUpdates(): iterable
    {
        yield 'too long' => ['ABCDEFGHIJK'];
        yield 'empty' => [''];
        yield 'null' => [null];
        yield 'code of another category' => ['CUPS'];
    }

    #[DataProvider('invalidUpdates')]
    public function testInvalidUpdateIsRejected(?string $code): void
    {
        $iri = $this->createCategory('MUGS');
        $this->createCategory('CUPS');

        $this->patch($iri, ['code' => $code]);

        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['violations' => [['propertyPath' => 'code']]]);
        self::assertSame('MUGS', $this->client->request('GET', $iri)->toArray()['code']);
    }

    public function testDeleteUnusedCategory(): void
    {
        $iri = $this->createCategory('MUGS');

        $this->client->request('DELETE', $iri);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', $iri);
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteUsedCategoryIsRejected(): void
    {
        $iri = $this->createCategory('MUGS');
        $product = $this->createProduct([$iri]);
        $logEntries = $this->logEntryCount();

        $this->client->request('DELETE', $iri);

        self::assertResponseStatusCodeSame(409);
        self::assertJsonContains(['detail' => 'Category MUGS is used by 1 product(s) and cannot be deleted.']);
        self::assertEmailCount(0);
        $this->client->request('GET', $iri);
        self::assertResponseIsSuccessful();
        self::assertSame([$iri], $this->client->request('GET', $product)->toArray()['categories']);
        self::assertSame($logEntries, $this->logEntryCount());
    }

    public function testCategoryCanBeDeletedOnceNoProductUsesIt(): void
    {
        $mugs = $this->createCategory('MUGS');
        $cups = $this->createCategory('CUPS');
        $product = $this->createProduct([$mugs]);

        $this->client->request('PATCH', $product, [
            'json' => ['categories' => [$cups]],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        $this->client->request('DELETE', $mugs);

        self::assertResponseStatusCodeSame(204);
    }

    public function testCategoryChangesSendNoNotifications(): void
    {
        $iri = $this->createCategory('MUGS');

        $this->patch($iri, ['code' => 'CUPS']);
        self::assertEmailCount(0);
        $this->client->request('DELETE', $iri);
        self::assertEmailCount(0);

        self::assertSame(0, $this->logEntryCount());
    }

    public function testPutIsNotAvailable(): void
    {
        $iri = $this->createCategory('MUGS');

        $this->client->request('PUT', $iri, ['json' => ['code' => 'CUPS']]);

        self::assertResponseStatusCodeSame(405);
    }

    /**
     * @param list<string> $categories
     */
    private function createProduct(array $categories): string
    {
        return $this->client->request('POST', '/api/products', ['json' => [
            'name' => 'Kubek',
            'price' => '19.99',
            'categories' => $categories,
        ]])->toArray()['@id'];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function patch(string $iri, array $data): void
    {
        $this->client->request('PATCH', $iri, [
            'json' => $data,
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
    }

    private function logEntryCount(): int
    {
        return (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM operation_log');
    }

    private function createCategory(string $code): string
    {
        return $this->client->request('POST', '/api/categories', ['json' => ['code' => $code]])->toArray()['@id'];
    }
}
