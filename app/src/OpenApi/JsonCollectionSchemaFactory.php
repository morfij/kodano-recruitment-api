<?php

declare(strict_types=1);

namespace App\OpenApi;

use ApiPlatform\JsonSchema\Schema;
use ApiPlatform\JsonSchema\SchemaFactoryAwareInterface;
use ApiPlatform\JsonSchema\SchemaFactoryInterface;
use ApiPlatform\Metadata\Operation;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Documents JSON collections the way App\Serializer\JsonCollectionNormalizer renders
 * them: {items, totalItems, page, itemsPerPage} instead of a bare array.
 */
#[AsDecorator('api_platform.json_schema.schema_factory')]
final class JsonCollectionSchemaFactory implements SchemaFactoryInterface, SchemaFactoryAwareInterface
{
    public function __construct(
        #[AutowireDecorated]
        private readonly SchemaFactoryInterface $decorated,
    ) {
    }

    public function buildSchema(string $className, string $format = 'json', string $type = Schema::TYPE_OUTPUT, ?Operation $operation = null, ?Schema $schema = null, ?array $serializerContext = null, bool $forceCollection = false): Schema
    {
        $schema = $this->decorated->buildSchema($className, $format, $type, $operation, $schema, $serializerContext, $forceCollection);

        if ('json' !== $format || Schema::TYPE_OUTPUT !== $type || 'array' !== ($schema['type'] ?? null)) {
            return $schema;
        }

        $definitions = $schema->getDefinitions();
        $items = $schema['items'];
        $schema->exchangeArray([
            'type' => 'object',
            'required' => ['items', 'totalItems', 'page', 'itemsPerPage'],
            'properties' => [
                'items' => ['type' => 'array', 'items' => $items],
                'totalItems' => ['type' => 'integer', 'minimum' => 0],
                'page' => ['type' => 'integer', 'minimum' => 1],
                'itemsPerPage' => ['type' => 'integer', 'minimum' => 1],
            ],
        ]);
        $schema->setDefinitions($definitions);

        return $schema;
    }

    public function setSchemaFactory(SchemaFactoryInterface $schemaFactory): void
    {
        if ($this->decorated instanceof SchemaFactoryAwareInterface) {
            $this->decorated->setSchemaFactory($schemaFactory);
        }
    }
}
