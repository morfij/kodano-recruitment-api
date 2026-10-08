<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;

final class ProductApiTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = false;

    private Client $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

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
            '@type' => 'Product',
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
        $iri = $this->createProduct('Kubek', '19.99', [$category]);
        $this->createProduct('Talerz', '7.50', [$category]);

        $this->client->request('GET', $iri);
        self::assertResponseIsSuccessful();
        self::assertJsonContains(['@id' => $iri, 'name' => 'Kubek', 'categories' => [$category]]);

        $collection = $this->client->request('GET', '/api/products')->toArray();
        self::assertSame(2, $collection['totalItems']);
        self::assertSame(['Kubek', 'Talerz'], array_column($collection['member'], 'name'));
    }

    public function testPlainJsonFormat(): void
    {
        $iri = $this->createProduct('Kubek', '19.99', [$this->createCategory('MUGS')]);

        $response = $this->client->request('GET', $iri, ['headers' => ['Accept' => 'application/json']]);

        self::assertResponseHeaderSame('content-type', 'application/json');
        $data = $response->toArray();
        self::assertArrayNotHasKey('@context', $data);
        self::assertSame('Kubek', $data['name']);
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
        $iri = $this->createProduct('Kubek', '19.99', [$category]);

        $this->patch($iri, ['name' => 'Kubek XL']);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['name' => 'Kubek XL', 'price' => '19.99', 'categories' => [$category]]);
    }

    public function testPatchReplacesCategories(): void
    {
        $mugs = $this->createCategory('MUGS');
        $cups = $this->createCategory('CUPS');
        $iri = $this->createProduct('Kubek', '19.99', [$mugs]);

        $this->patch($iri, ['categories' => [$cups]]);

        self::assertResponseIsSuccessful();
        self::assertSame([$cups], $this->client->request('GET', $iri)->toArray()['categories']);
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
        $iri = $this->createProduct('Kubek', '19.99', [$this->createCategory('MUGS')]);

        $this->patch($iri, $patch);

        self::assertResponseStatusCodeSame(422);
        self::assertJsonContains(['violations' => [['propertyPath' => $field]]]);
        $this->client->request('GET', $iri);
        self::assertJsonContains(['name' => 'Kubek', 'price' => '19.99']);
    }

    public function testPatchOfPriceOnlyUpdatesUpdatedAt(): void
    {
        $iri = $this->createProduct('Kubek', '19.99', [$this->createCategory('MUGS')]);
        $before = $this->backdateTimestamps($iri);

        $this->patch($iri, ['price' => '24.99']);
        self::assertResponseIsSuccessful();

        $this->assertTimestampsAfterUpdate($iri, $before);
    }

    public function testPatchOfCategoriesOnlyUpdatesUpdatedAt(): void
    {
        $iri = $this->createProduct('Kubek', '19.99', [$this->createCategory('MUGS')]);
        $cups = $this->createCategory('CUPS');
        $before = $this->backdateTimestamps($iri);

        $this->patch($iri, ['categories' => [$cups]]);
        self::assertResponseIsSuccessful();

        $this->assertTimestampsAfterUpdate($iri, $before);
    }

    public function testPatchWithoutChangesStillUpdatesUpdatedAt(): void
    {
        $iri = $this->createProduct('Kubek', '19.99', [$this->createCategory('MUGS')]);
        $before = $this->backdateTimestamps($iri);

        $this->patch($iri, ['name' => 'Kubek']);
        self::assertResponseIsSuccessful();

        $this->assertTimestampsAfterUpdate($iri, $before);
    }

    public function testPatchRequiresMergePatchContentType(): void
    {
        $iri = $this->createProduct('Kubek', '19.99', [$this->createCategory('MUGS')]);

        $this->client->request('PATCH', $iri, ['json' => ['name' => 'Kubek XL'], 'headers' => ['Content-Type' => 'application/json']]);

        self::assertResponseStatusCodeSame(415);
    }

    public function testPutIsNotAvailable(): void
    {
        $iri = $this->createProduct('Kubek', '19.99', [$this->createCategory('MUGS')]);

        $this->client->request('PUT', $iri, ['json' => ['name' => 'Kubek XL']]);

        self::assertResponseStatusCodeSame(405);
    }

    public function testDelete(): void
    {
        $iri = $this->createProduct('Kubek', '19.99', [$this->createCategory('MUGS')]);

        $this->client->request('DELETE', $iri);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', $iri);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * DATETIME keeps whole seconds, so move the stored dates back instead of sleeping.
     *
     * @return array{created_at: string, updated_at: string}
     */
    private function backdateTimestamps(string $iri): array
    {
        static::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE product SET created_at = created_at - INTERVAL 1 HOUR, updated_at = updated_at - INTERVAL 1 HOUR WHERE id = ?',
            [(int) basename($iri)],
        );

        return $this->storedTimestamps($iri);
    }

    /**
     * @param array{created_at: string, updated_at: string} $before
     */
    private function assertTimestampsAfterUpdate(string $iri, array $before): void
    {
        $stored = $this->storedTimestamps($iri);
        self::assertSame($before['created_at'], $stored['created_at']);
        self::assertGreaterThan($before['updated_at'], $stored['updated_at']);

        $read = $this->client->request('GET', $iri)->toArray();
        self::assertEquals(new \DateTimeImmutable($before['created_at']), new \DateTimeImmutable($read['createdAt']));
        self::assertGreaterThan(new \DateTimeImmutable($before['updated_at']), new \DateTimeImmutable($read['updatedAt']));
    }

    /**
     * @return array{created_at: string, updated_at: string}
     */
    private function storedTimestamps(string $iri): array
    {
        $row = static::getContainer()->get(Connection::class)
            ->fetchAssociative('SELECT created_at, updated_at FROM product WHERE id = ?', [(int) basename($iri)]);
        self::assertIsArray($row);

        return ['created_at' => (string) $row['created_at'], 'updated_at' => (string) $row['updated_at']];
    }

    private function createCategory(string $code): string
    {
        return $this->client->request('POST', '/api/categories', ['json' => ['code' => $code]])->toArray()['@id'];
    }

    /**
     * @param list<string> $categories
     */
    private function createProduct(string $name, string $price, array $categories): string
    {
        return $this->client->request('POST', '/api/products', ['json' => [
            'name' => $name,
            'price' => $price,
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
}
