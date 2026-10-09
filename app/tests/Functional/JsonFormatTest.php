<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class JsonFormatTest extends ApiTestCase
{
    public function testPlainJsonIsTheDefault(): void
    {
        $id = $this->createProduct('Kubek', '19.99', [$this->createCategory('MUGS')]);

        $response = $this->client->request('GET', '/api/products/'.$id, ['headers' => ['Accept' => '*/*']]);

        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertSame(['id', 'name', 'price', 'categories', 'createdAt', 'updatedAt'], array_keys($response->toArray()));
    }

    public function testCollectionIsPaginated(): void
    {
        foreach (range(1, 31) as $i) {
            $this->createCategory('C'.$i);
        }

        $first = $this->client->request('GET', '/api/categories')->toArray();
        self::assertSame(['items', 'totalItems', 'page', 'itemsPerPage'], array_keys($first));
        self::assertCount(30, $first['items']);
        self::assertSame([31, 1, 30], [$first['totalItems'], $first['page'], $first['itemsPerPage']]);

        $second = $this->client->request('GET', '/api/categories?page=2')->toArray();
        self::assertSame(['C31'], array_column($second['items'], 'code'));
        self::assertSame(2, $second['page']);
    }

    public function testEmptyCollectionHasAnEmptyList(): void
    {
        $response = $this->client->request('GET', '/api/products');

        self::assertSame('{"items":[],"totalItems":0,"page":1,"itemsPerPage":30}', $response->getContent());
    }

    public function testJsonLdIsNotAvailable(): void
    {
        $this->client->request('GET', '/api/products', ['headers' => ['Accept' => 'application/ld+json']]);
        self::assertResponseStatusCodeSame(406);

        $this->client->request('GET', '/api/docs.jsonld');
        self::assertResponseStatusCodeSame(404);
    }

    public function testOpenApiDescribesTheCollectionWrapper(): void
    {
        $openApi = $this->client->request('GET', '/api/docs.jsonopenapi', ['headers' => ['Accept' => 'application/vnd.openapi+json']])->toArray();

        $schema = $openApi['paths']['/api/products']['get']['responses']['200']['content']['application/json']['schema'];
        self::assertSame(['items', 'totalItems', 'page', 'itemsPerPage'], array_keys($schema['properties']));
        self::assertSame('#/components/schemas/Product-product.read', $schema['properties']['items']['items']['$ref']);
        self::assertSame(['type' => 'array', 'items' => ['type' => 'integer'], 'example' => [1, 2]], $openApi['components']['schemas']['Product-product.read']['properties']['categories']);
    }
}
