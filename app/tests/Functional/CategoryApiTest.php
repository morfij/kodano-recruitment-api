<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;

final class CategoryApiTest extends ApiTestCase
{
    public function testCreate(): void
    {
        $response = $this->client->request('POST', '/api/categories', ['json' => ['code' => 'MUGS']]);

        self::assertResponseStatusCodeSame(201);
        self::assertJsonContains(['code' => 'MUGS']);
        $data = $response->toArray();
        self::assertNotEmpty($data['createdAt']);
        self::assertSame($data['createdAt'], $data['updatedAt']);
    }

    public function testGetItemAndCollection(): void
    {
        $uri = '/api/categories/'.$this->createCategory('MUGS');
        $this->createCategory('CUPS');

        $this->client->request('GET', $uri);
        self::assertResponseIsSuccessful();
        self::assertJsonContains(['id' => (int) basename($uri), 'code' => 'MUGS']);

        $collection = $this->client->request('GET', '/api/categories')->toArray();
        self::assertSame(2, $collection['totalItems']);
        self::assertSame(['MUGS', 'CUPS'], array_column($collection['items'], 'code'));
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
        $uri = '/api/categories/'.$this->createCategory('MUGS');
        $createdAt = $this->client->request('GET', $uri)->toArray()['createdAt'];

        $this->patch($uri, ['code' => 'CUPS']);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['id' => (int) basename($uri), 'code' => 'CUPS', 'createdAt' => $createdAt]);
    }

    public function testUpdateKeepsOwnCode(): void
    {
        $uri = '/api/categories/'.$this->createCategory('MUGS');

        $this->patch($uri, ['code' => 'MUGS']);

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
        $uri = '/api/categories/'.$this->createCategory('MUGS');
        $this->createCategory('CUPS');

        $this->patch($uri, ['code' => $code]);

        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['violations' => [['propertyPath' => 'code']]]);
        self::assertSame('MUGS', $this->client->request('GET', $uri)->toArray()['code']);
    }

    public function testDeleteUnusedCategory(): void
    {
        $uri = '/api/categories/'.$this->createCategory('MUGS');

        $this->client->request('DELETE', $uri);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', $uri);
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteUsedCategoryIsRejected(): void
    {
        $mugs = $this->createCategory('MUGS');
        $uri = '/api/categories/'.$mugs;
        $product = '/api/products/'.$this->createProduct('Kubek', '19.99', [$mugs]);
        $logEntries = $this->logEntryCount();

        $this->client->request('DELETE', $uri);

        self::assertResponseStatusCodeSame(409);
        self::assertJsonContains(['detail' => 'Category MUGS is used by 1 product(s) and cannot be deleted.']);
        self::assertEmailCount(0);
        $this->client->request('GET', $uri);
        self::assertResponseIsSuccessful();
        self::assertSame([$mugs], $this->client->request('GET', $product)->toArray()['categories']);
        self::assertSame($logEntries, $this->logEntryCount());
    }

    public function testCategoryCanBeDeletedOnceNoProductUsesIt(): void
    {
        $mugs = $this->createCategory('MUGS');
        $cups = $this->createCategory('CUPS');
        $product = $this->createProduct('Kubek', '19.99', [$mugs]);

        $this->patch('/api/products/'.$product, ['categories' => [$cups]]);
        $this->client->request('DELETE', '/api/categories/'.$mugs);

        self::assertResponseStatusCodeSame(204);
    }

    public function testCategoryChangesSendNoNotifications(): void
    {
        $uri = '/api/categories/'.$this->createCategory('MUGS');

        $this->patch($uri, ['code' => 'CUPS']);
        self::assertEmailCount(0);
        $this->client->request('DELETE', $uri);
        self::assertEmailCount(0);

        self::assertSame(0, $this->logEntryCount());
    }

    public function testPutIsNotAvailable(): void
    {
        $uri = '/api/categories/'.$this->createCategory('MUGS');

        $this->client->request('PUT', $uri, ['json' => ['code' => 'CUPS']]);

        self::assertResponseStatusCodeSame(405);
    }

    private function logEntryCount(): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM operation_log');
    }
}
