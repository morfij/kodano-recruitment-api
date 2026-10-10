<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;

final class ProductApiTest extends ApiTestCase
{
    public function testCreate(): void
    {
        $category = $this->createCategory('MUGS');

        $response = $this->client->request('POST', '/api/products', ['json' => [
            'name' => 'Kubek',
            'price' => '19.99',
            'categories' => [$category],
        ]]);

        self::assertResponseStatusCodeSame(201);
        self::assertJsonContains([
            'name' => 'Kubek',
            'price' => '19.99',
            'categories' => [$category],
        ]);
        $data = $response->toArray();
        self::assertNotEmpty($data['createdAt']);
        self::assertSame($data['createdAt'], $data['updatedAt']);
    }

    public function testGetItemAndCollection(): void
    {
        $category = $this->createCategory('MUGS');
        $uri = '/api/products/'.$this->createProduct('Kubek', '19.99', [$category]);
        $this->createProduct('Talerz', '7.50', [$category]);

        $this->client->request('GET', $uri);
        self::assertResponseIsSuccessful();
        self::assertJsonContains(['id' => (int) basename($uri), 'name' => 'Kubek', 'categories' => [$category]]);

        $collection = $this->client->request('GET', '/api/products')->toArray();
        self::assertSame(2, $collection['totalItems']);
        self::assertSame(['Kubek', 'Talerz'], array_column($collection['items'], 'name'));
    }

    public function testUnknownProductReturns404(): void
    {
        $this->client->request('GET', '/api/products/999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testProductNeedsAtLeastOneCategory(): void
    {
        $this->client->request('POST', '/api/products', ['json' => ['name' => 'Kubek', 'price' => '19.99']]);
        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['violations' => [['propertyPath' => 'categories']]]);

        $this->client->request('POST', '/api/products', ['json' => ['name' => 'Kubek', 'price' => '19.99', 'categories' => []]]);
        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['violations' => [['propertyPath' => 'categories']]]);
    }

    /**
     * The response is read back from DECIMAL(10,2), so the price comes out with two decimals.
     *
     * @return iterable<array{string, string}>
     */
    public static function validPrices(): iterable
    {
        yield ['19.99', '19.99'];
        yield ['5', '5.00'];
        yield ['0.5', '0.50'];
        yield ['99999999.99', '99999999.99'];
    }

    #[DataProvider('validPrices')]
    public function testValidPriceIsAccepted(string $price, string $stored): void
    {
        $this->createProduct('Kubek', $price, [$this->createCategory('MUGS')]);

        self::assertResponseStatusCodeSame(201);
        self::assertJsonContains(['price' => $stored]);
    }

    /**
     * @return iterable<string, array{string|float}>
     */
    public static function invalidPrices(): iterable
    {
        yield 'three decimals' => ['19.999'];
        yield 'negative' => ['-1'];
        yield 'too large' => ['100000000'];
        yield 'comma' => ['19,99'];
        yield 'number instead of string' => [19.99];
    }

    #[DataProvider('invalidPrices')]
    public function testInvalidPriceIsRejected(string|float $price): void
    {
        $category = $this->createCategory('MUGS');

        $this->client->request('POST', '/api/products', ['json' => ['name' => 'Kubek', 'price' => $price, 'categories' => [$category]]]);

        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['violations' => [['propertyPath' => 'price']]]);
        self::assertSame(0, $this->client->request('GET', '/api/products')->toArray()['totalItems']);
    }

    public function testPatchChangesOnlyGivenFields(): void
    {
        $category = $this->createCategory('MUGS');
        $uri = '/api/products/'.$this->createProduct('Kubek', '19.99', [$category]);

        $this->patch($uri, ['name' => 'Kubek XL']);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['name' => 'Kubek XL', 'price' => '19.99', 'categories' => [$category]]);
    }

    public function testPatchReplacesCategories(): void
    {
        $mugs = $this->createCategory('MUGS');
        $cups = $this->createCategory('CUPS');
        $uri = '/api/products/'.$this->createProduct('Kubek', '19.99', [$mugs]);

        $this->patch($uri, ['categories' => [$cups]]);

        self::assertResponseIsSuccessful();
        self::assertSame([$cups], $this->client->request('GET', $uri)->toArray()['categories']);
    }

    public function testDuplicateCategoryIdsAreStoredOnce(): void
    {
        $mugs = $this->createCategory('MUGS');
        $cups = $this->createCategory('CUPS');

        $id = $this->createProduct('Kubek', '19.99', [$mugs, $cups, $mugs]);

        self::assertResponseStatusCodeSame(201);
        self::assertJsonContains(['categories' => [$mugs, $cups]]);
        self::assertSame(2, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM product_category WHERE product_id = ?', [$id]));
    }

    public function testPatchWithoutCategoriesKeepsThem(): void
    {
        $mugs = $this->createCategory('MUGS');
        $uri = '/api/products/'.$this->createProduct('Kubek', '19.99', [$mugs]);

        $this->patch($uri, ['price' => '24.99']);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['price' => '24.99', 'categories' => [$mugs]]);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidCategoryLists(): iterable
    {
        yield 'unknown ID' => [[999999], 'Category 999999 does not exist.'];
        yield 'IRI' => [['/api/categories/1'], 'This value should be a list of category IDs.'];
        yield 'numeric string' => [['1'], 'This value should be a list of category IDs.'];
        yield 'nested list' => [[[1]], 'This value should be a list of category IDs.'];
        yield 'object' => [['a' => 1], 'This value should be a list of category IDs.'];
        yield 'single ID' => [1, 'This value should be a list of category IDs.'];
        yield 'null' => [null, 'This collection should contain 1 element or more.'];
    }

    #[DataProvider('invalidCategoryLists')]
    public function testInvalidCategoryListIsRejected(mixed $categories, string $message): void
    {
        $mugs = $this->createCategory('MUGS');
        $uri = '/api/products/'.$this->createProduct('Kubek', '19.99', [$mugs]);

        $this->patch($uri, ['categories' => $categories]);

        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['violations' => [['propertyPath' => 'categories', 'message' => $message]]]);
        self::assertSame([$mugs], $this->client->request('GET', $uri)->toArray()['categories']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidPatches(): iterable
    {
        yield 'null name' => [['name' => null], 'name'];
        yield 'null price' => [['price' => null], 'price'];
        yield 'empty categories' => [['categories' => []], 'categories'];
    }

    /**
     * @param array<string, mixed> $patch
     */
    #[DataProvider('invalidPatches')]
    public function testInvalidPatchIsRejected(array $patch, string $field): void
    {
        $uri = '/api/products/'.$this->createProduct('Kubek', '19.99', [$this->createCategory('MUGS')]);

        $this->patch($uri, $patch);

        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['violations' => [['propertyPath' => $field]]]);
        $this->client->request('GET', $uri);
        self::assertJsonContains(['name' => 'Kubek', 'price' => '19.99']);
    }

    public function testPatchOfPriceOnlyUpdatesUpdatedAt(): void
    {
        $uri = '/api/products/'.$this->createProduct('Kubek', '19.99', [$this->createCategory('MUGS')]);
        $before = $this->backdateTimestamps($uri);

        $this->patch($uri, ['price' => '24.99']);
        self::assertResponseIsSuccessful();

        $this->assertTimestampsAfterUpdate($uri, $before);
    }

    public function testPatchOfCategoriesOnlyUpdatesUpdatedAt(): void
    {
        $uri = '/api/products/'.$this->createProduct('Kubek', '19.99', [$this->createCategory('MUGS')]);
        $cups = $this->createCategory('CUPS');
        $before = $this->backdateTimestamps($uri);

        $this->patch($uri, ['categories' => [$cups]]);
        self::assertResponseIsSuccessful();

        $this->assertTimestampsAfterUpdate($uri, $before);
    }

    public function testPatchWithoutChangesStillUpdatesUpdatedAt(): void
    {
        $uri = '/api/products/'.$this->createProduct('Kubek', '19.99', [$this->createCategory('MUGS')]);
        $before = $this->backdateTimestamps($uri);

        $this->patch($uri, ['name' => 'Kubek']);
        self::assertResponseIsSuccessful();

        $this->assertTimestampsAfterUpdate($uri, $before);
    }

    public function testPatchRejectsUnsupportedContentType(): void
    {
        $uri = '/api/products/'.$this->createProduct('Kubek', '19.99', [$this->createCategory('MUGS')]);

        $this->client->request('PATCH', $uri, ['body' => 'name=Kubek XL', 'headers' => ['Content-Type' => 'text/plain']]);

        self::assertResponseStatusCodeSame(415);
    }

    public function testPutIsNotAvailable(): void
    {
        $uri = '/api/products/'.$this->createProduct('Kubek', '19.99', [$this->createCategory('MUGS')]);

        $this->client->request('PUT', $uri, ['json' => ['name' => 'Kubek XL']]);

        self::assertResponseStatusCodeSame(405);
    }

    public function testMethodOverrideHeaderIsIgnored(): void
    {
        $uri = '/api/products/'.$this->createProduct('Kubek', '19.99', [$this->createCategory('MUGS')]);

        $this->client->request('POST', $uri, ['json' => [], 'headers' => ['X-HTTP-Method-Override' => 'DELETE']]);
        self::assertResponseStatusCodeSame(405);

        $this->client->request('GET', $uri);
        self::assertResponseIsSuccessful();
    }

    public function testDelete(): void
    {
        $uri = '/api/products/'.$this->createProduct('Kubek', '19.99', [$this->createCategory('MUGS')]);

        $this->client->request('DELETE', $uri);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', $uri);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * DATETIME keeps whole seconds, so move the stored dates back instead of sleeping.
     *
     * @return array{created_at: string, updated_at: string}
     */
    private function backdateTimestamps(string $uri): array
    {
        $this->db()->executeStatement(
            'UPDATE product SET created_at = created_at - INTERVAL 1 HOUR, updated_at = updated_at - INTERVAL 1 HOUR WHERE id = ?',
            [(int) basename($uri)],
        );

        return $this->storedTimestamps($uri);
    }

    /**
     * @param array{created_at: string, updated_at: string} $before
     */
    private function assertTimestampsAfterUpdate(string $uri, array $before): void
    {
        $stored = $this->storedTimestamps($uri);
        self::assertSame($before['created_at'], $stored['created_at']);
        self::assertGreaterThan($before['updated_at'], $stored['updated_at']);

        $read = $this->client->request('GET', $uri)->toArray();
        self::assertEquals(new \DateTimeImmutable($before['created_at']), new \DateTimeImmutable($read['createdAt']));
        self::assertGreaterThan(new \DateTimeImmutable($before['updated_at']), new \DateTimeImmutable($read['updatedAt']));
    }

    /**
     * @return array{created_at: string, updated_at: string}
     */
    private function storedTimestamps(string $uri): array
    {
        $row = $this->db()
            ->fetchAssociative('SELECT created_at, updated_at FROM product WHERE id = ?', [(int) basename($uri)]);
        self::assertIsArray($row);

        return ['created_at' => (string) $row['created_at'], 'updated_at' => (string) $row['updated_at']];
    }
}
