<?php

declare(strict_types=1);

namespace App\Serializer;

use ApiPlatform\Metadata\ResourceClassResolverInterface;
use ApiPlatform\Serializer\AbstractCollectionNormalizer;
use ApiPlatform\State\Pagination\PaginatorInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Plain JSON would render a paginated collection as a bare array without any
 * pagination data. This wraps it as {items, totalItems, page, itemsPerPage}.
 */
final class JsonCollectionNormalizer extends AbstractCollectionNormalizer
{
    public const FORMAT = 'json';

    public function __construct(
        ResourceClassResolverInterface $resourceClassResolver,
        #[Autowire(param: 'api_platform.collection.pagination.page_parameter_name')]
        string $pageParameterName,
    ) {
        parent::__construct($resourceClassResolver, $pageParameterName);
    }

    /**
     * Only top-level API collections are paginators; nested arrays and violation lists are not.
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return self::FORMAT === $format && $data instanceof PaginatorInterface && !isset($context['api_sub_level']);
    }

    public function getSupportedTypes(?string $format): array
    {
        return self::FORMAT === $format ? [PaginatorInterface::class => false] : [];
    }

    /**
     * @param iterable<mixed>      $data
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        $normalized = parent::normalize($data, $format, $context);
        \assert(\is_array($normalized));

        return ['items' => $normalized['items']] + $normalized;
    }

    /**
     * @param iterable<mixed>      $object
     * @param array<string, mixed> $context
     *
     * @return array{totalItems: int, page: int, itemsPerPage: int}
     */
    protected function getPaginationData(iterable $object, array $context = []): array
    {
        \assert($object instanceof PaginatorInterface);

        return [
            'totalItems' => (int) $object->getTotalItems(),
            'page' => (int) $object->getCurrentPage(),
            'itemsPerPage' => (int) $object->getItemsPerPage(),
        ];
    }

    /**
     * @param iterable<mixed>      $object
     * @param array<string, mixed> $context
     *
     * @return array{items: list<mixed>}
     */
    protected function getItemsData(iterable $object, ?string $format = null, array $context = []): array
    {
        $items = [];
        foreach ($object as $item) {
            $items[] = $this->normalizer->normalize($item, $format, $context);
        }

        return ['items' => $items];
    }
}
