<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;

final class OperationLogApiTest extends ApiTestCase
{
    public function testListsEntriesNewestFirst(): void
    {
        $id = $this->createProductInCategory('Kubek');
        $this->patch('/api/products/'.$id, ['name' => 'Kubek XL']);
        $this->client->request('DELETE', '/api/products/'.$id);

        $entries = $this->list('/api/operation_logs');

        self::assertSame([['deleted', $id], ['updated', $id], ['created', $id]], $this->summary($entries));
        self::assertSame(['name' => 'Kubek XL', 'price' => '19.99', 'categories' => ['MUGS']], $entries[0]['payload']);
        self::assertNotEmpty($entries[0]['createdAt']);
    }

    public function testGetItem(): void
    {
        $id = $this->createProductInCategory('Kubek');
        $logId = $this->list('/api/operation_logs')[0]['id'];

        $this->client->request('GET', '/api/operation_logs/'.$logId);

        self::assertResponseIsSuccessful();
        self::assertJsonContains([
            'id' => $logId,
            'operation' => 'created',
            'productId' => $id,
            'payload' => ['name' => 'Kubek', 'price' => '19.99', 'categories' => ['MUGS']],
        ]);
    }

    public function testFilterByProductId(): void
    {
        $kubek = $this->createProductInCategory('Kubek');
        $talerz = $this->createProductInCategory('Talerz', 'PLATES');
        $this->patch('/api/products/'.$kubek, ['name' => 'Kubek XL']);

        self::assertSame([['created', $talerz]], $this->summary($this->list('/api/operation_logs?productId='.$talerz)));
        self::assertSame([['updated', $kubek], ['created', $kubek]], $this->summary($this->list('/api/operation_logs?productId='.$kubek)));
    }

    public function testFilterByOperation(): void
    {
        $kubek = $this->createProductInCategory('Kubek');
        $this->createProductInCategory('Talerz', 'PLATES');
        $this->patch('/api/products/'.$kubek, ['name' => 'Kubek XL']);

        self::assertSame([['updated', $kubek]], $this->summary($this->list('/api/operation_logs?operation=updated')));
    }

    public function testCombinedFilters(): void
    {
        $kubek = $this->createProductInCategory('Kubek');
        $talerz = $this->createProductInCategory('Talerz', 'PLATES');
        $this->patch('/api/products/'.$kubek, ['name' => 'Kubek XL']);
        $this->patch('/api/products/'.$talerz, ['name' => 'Talerz XL']);

        self::assertSame(
            [['updated', $talerz]],
            $this->summary($this->list(sprintf('/api/operation_logs?productId=%d&operation=updated', $talerz))),
        );
    }

    public function testFilterByDate(): void
    {
        $old = $this->createProductInCategory('Kubek');
        $new = $this->createProductInCategory('Talerz', 'PLATES');
        $this->db()->executeStatement(
            "UPDATE operation_log SET created_at = '2026-01-15 12:00:00' WHERE product_id = ?",
            [$old],
        );

        self::assertSame([['created', $new]], $this->summary($this->list('/api/operation_logs?createdAt[after]=2026-02-01')));
        self::assertSame([['created', $old]], $this->summary($this->list('/api/operation_logs?createdAt[before]=2026-02-01')));
        self::assertSame(
            [['created', $old]],
            $this->summary($this->list('/api/operation_logs?createdAt[after]=2026-01-01&createdAt[before]=2026-01-31')),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidFilters(): iterable
    {
        yield 'unknown operation' => ['operation=renamed', 'operation'];
        yield 'non-numeric product id' => ['productId=abc', 'productId'];
        yield 'decimal product id' => ['productId=1.5', 'productId'];
    }

    #[DataProvider('invalidFilters')]
    public function testInvalidFilterValueIsRejected(string $query, string $parameter): void
    {
        $this->client->request('GET', '/api/operation_logs?'.$query);

        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['violations' => [['propertyPath' => $parameter]]]);
    }

    /**
     * @return iterable<array{string}>
     */
    public static function writeMethods(): iterable
    {
        yield ['POST'];
        yield ['PATCH'];
        yield ['PUT'];
        yield ['DELETE'];
    }

    #[DataProvider('writeMethods')]
    public function testLogIsReadOnly(string $method): void
    {
        $this->createProductInCategory('Kubek');
        $uri = '/api/operation_logs/'.$this->list('/api/operation_logs')[0]['id'];

        $this->client->request($method, 'POST' === $method ? '/api/operation_logs' : $uri, [
            'json' => ['operation' => 'deleted'],
            'headers' => ['Content-Type' => 'PATCH' === $method ? 'application/merge-patch+json' : 'application/json'],
        ]);

        self::assertResponseStatusCodeSame(405);
    }

    private function createProductInCategory(string $name, string $categoryCode = 'MUGS'): int
    {
        return $this->createProduct($name, '19.99', [$this->createCategory($categoryCode)]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function list(string $url): array
    {
        $response = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $response->toArray()['items'];
    }

    /**
     * @param list<array<string, mixed>> $entries
     *
     * @return list<array{mixed, mixed}>
     */
    private function summary(array $entries): array
    {
        return array_map(static fn (array $entry): array => [$entry['operation'], $entry['productId']], $entries);
    }
}
